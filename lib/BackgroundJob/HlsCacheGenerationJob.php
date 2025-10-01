<?php

declare(strict_types=1);

namespace OCA\HyperViewer\BackgroundJob;

use OCP\BackgroundJob\QueuedJob;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use OCP\Notification\IManager as INotificationManager;
use OCP\AppFramework\Utility\ITimeFactory;

class HlsCacheGenerationJob extends QueuedJob {

	private IRootFolder $rootFolder;
	private IUserManager $userManager;
	private LoggerInterface $logger;
	private INotificationManager $notificationManager;

	public function __construct(
		ITimeFactory $timeFactory,
		IRootFolder $rootFolder,
		IUserManager $userManager,
		LoggerInterface $logger,
		INotificationManager $notificationManager
	) {
		parent::__construct($timeFactory);
		$this->rootFolder = $rootFolder;
		$this->userManager = $userManager;
		$this->logger = $logger;
		$this->notificationManager = $notificationManager;
	}

	protected function run($argument): void {
		$this->logger->info('🚀 HLS cache generation job STARTED', ['argument' => $argument]);
		
		$jobId = $argument['jobId'] ?? 'unknown';
		$userId = $argument['userId'] ?? null;
		$filename = $argument['filename'] ?? null;
		$directory = $argument['directory'] ?? '/';
		$cacheLocation = $argument['cacheLocation'] ?? 'relative';
		$customPath = $argument['customPath'] ?? '';
		$overwriteExisting = $argument['overwriteExisting'] ?? false;
		$notifyCompletion = $argument['notifyCompletion'] ?? true;

		if (!$userId || !$filename) {
			$this->logger->error('HLS cache generation job missing required parameters', $argument);
			return;
		}

		$user = $this->userManager->get($userId);
		if (!$user) {
			$this->logger->error('User not found for HLS cache generation', ['userId' => $userId]);
			return;
		}

		$this->logger->info('Starting HLS cache generation', [
			'jobId' => $jobId,
			'user' => $userId,
			'filename' => $filename,
			'directory' => $directory
		]);

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$videoPath = $directory . '/' . $filename;
			
			// Check if video file exists
			if (!$userFolder->nodeExists($videoPath)) {
				throw new \Exception("Video file not found: $videoPath");
			}

			$videoFile = $userFolder->get($videoPath);
			$videoLocalPath = $videoFile->getStorage()->getLocalFile($videoFile->getInternalPath());

			if (!$videoLocalPath || !file_exists($videoLocalPath)) {
				throw new \Exception("Cannot access video file locally: $filename");
			}

			// Determine cache output path
			$cacheOutputPath = $this->determineCacheOutputPath(
				$userFolder, 
				$filename, 
				$directory, 
				$cacheLocation, 
				$customPath
			);

			// Generate HLS cache with adaptive bitrate ladder
			$resolutions = $argument['resolutions'] ?? ['720p', '480p', '240p'];
			$this->generateHlsCache($videoLocalPath, $cacheOutputPath, $filename, $overwriteExisting, $userId, $resolutions);

			$this->logger->info('HLS cache generation completed', [
				'jobId' => $jobId,
				'filename' => $filename,
				'cachePath' => $cacheOutputPath
			]);

			// Send notification if requested
			if ($notifyCompletion) {
				$this->sendCompletionNotification($user, $filename, true);
			}

		} catch (\Exception $e) {
			$this->logger->error('HLS cache generation failed', [
				'jobId' => $jobId,
				'filename' => $filename,
				'error' => $e->getMessage()
			]);

			// Send failure notification
			if ($notifyCompletion) {
				$this->sendCompletionNotification($user, $filename, false, $e->getMessage());
			}
		}
	}

	/**
	 * Determine where to output the HLS cache
	 */
	private function determineCacheOutputPath($userFolder, string $filename, string $directory, string $cacheLocation, string $customPath): string {
		$baseFilename = pathinfo($filename, PATHINFO_FILENAME);

		switch ($cacheLocation) {
			case 'relative':
				return $directory . '/.cached_hls/' . $baseFilename;
			
			case 'home':
				return '/.cached_hls/' . $baseFilename;
			
			case 'custom':
				if (empty($customPath)) {
					throw new \Exception('Custom cache path is required but not provided');
				}
				// Ensure custom path ends with .cached_hls
				$customPath = rtrim($customPath, '/');
				if (!str_ends_with($customPath, '.cached_hls')) {
					$customPath .= '/.cached_hls';
				}
				return $customPath . '/' . $baseFilename;
			
			default:
				throw new \Exception("Unknown cache location: $cacheLocation");
		}
	}

	/**
	 * Generate HLS cache using FFmpeg
	 */
	private function generateHlsCache(string $videoLocalPath, string $cacheOutputPath, string $filename, bool $overwriteExisting, string $userId, array $resolutions = ['720p', '480p', '240p']): void {
		$this->logger->info('Generating HLS cache', [
			'input' => $videoLocalPath,
			'output' => $cacheOutputPath,
			'overwrite' => $overwriteExisting
		]);

		// Create output directory in Nextcloud
		$userFolder = $this->rootFolder->getUserFolder($userId);
		
		try {
			// Check if cache directory exists
			if ($userFolder->nodeExists($cacheOutputPath)) {
				$this->logger->info('Cache directory already exists, using existing', ['path' => $cacheOutputPath]);
				$cacheFolder = $userFolder->get($cacheOutputPath);
				if (!($cacheFolder instanceof \OCP\Files\Folder)) {
					throw new \Exception("Cache path exists but is not a folder");
				}
			} else {
				// Create new cache directory
				$this->logger->info('Creating new cache directory', ['path' => $cacheOutputPath]);
				$cacheFolder = $userFolder->newFolder($cacheOutputPath);
			}
		} catch (\Exception $e) {
			throw new \Exception("Failed to create cache directory: " . $e->getMessage());
		}

		// Get local path for output
		$cacheLocalPath = $cacheFolder->getStorage()->getLocalFile($cacheFolder->getInternalPath());
		
		if (!$cacheLocalPath) {
			throw new \Exception("Cannot access cache directory locally");
		}

		// Generate adaptive bitrate HLS ladder with fallback
		try {
			$this->generateAdaptiveHls($videoLocalPath, $cacheLocalPath, $filename, $resolutions);
		} catch (\Exception $e) {
			$this->logger->warning('Adaptive HLS generation failed, falling back to single bitrate', [
				'error' => $e->getMessage(),
				'filename' => $filename
			]);
			
			// Fallback to single bitrate (720p)
			$this->generateSingleHls($videoLocalPath, $cacheLocalPath, $filename);
		}
	}

	/**
	 * Generate adaptive bitrate HLS ladder optimized for speed and storage
	 */
	private function generateAdaptiveHls(string $inputPath, string $outputPath, string $filename, array $resolutions): void {
		$this->logger->info('Starting adaptive HLS generation', [
			'input' => $inputPath,
			'output' => $outputPath,
			'resolutions' => $resolutions
		]);

		// Define optimized bitrate variants for speed and storage efficiency
		$allVariants = [
			'1080p' => ['resolution' => '1920x1080', 'bitrate' => '4000k', 'maxrate' => '4800k', 'bufsize' => '8000k', 'crf' => '23'],
			'720p' => ['resolution' => '1280x720', 'bitrate' => '2000k', 'maxrate' => '2400k', 'bufsize' => '4000k', 'crf' => '24'],
			'480p' => ['resolution' => '854x480', 'bitrate' => '800k', 'maxrate' => '1000k', 'bufsize' => '1600k', 'crf' => '26'],
			'360p' => ['resolution' => '640x360', 'bitrate' => '500k', 'maxrate' => '600k', 'bufsize' => '1000k', 'crf' => '28'],
			'240p' => ['resolution' => '426x240', 'bitrate' => '300k', 'maxrate' => '400k', 'bufsize' => '600k', 'crf' => '30']
		];

		// Filter variants based on user selection
		$variants = [];
		foreach ($resolutions as $res) {
			if (isset($allVariants[$res])) {
				$variants[$res] = $allVariants[$res];
			}
		}

		if (empty($variants)) {
			throw new \Exception('No valid resolutions selected');
		}

		// Build optimized FFmpeg command for adaptive streaming (fixed version)
		$ffmpegCmd = '/usr/local/bin/ffmpeg -y -i ' . escapeshellarg($inputPath);
		
		// Map video streams for each variant (using proper -s:v:N syntax)
		$streamIndex = 0;
		foreach ($variants as $name => $variant) {
			$ffmpegCmd .= sprintf(
				' -map 0:v:0 -c:v:%d libx264 -preset superfast -crf %s -maxrate %s -bufsize %s -s:v:%d %s -profile:v:%d main',
				$streamIndex, $variant['crf'], $variant['maxrate'], $variant['bufsize'], $streamIndex, $variant['resolution'], $streamIndex
			);
			$streamIndex++;
		}

		// Map single audio stream (shared across all variants - more efficient)
		$ffmpegCmd .= ' -map 0:a:0 -c:a aac -b:a 128k';

		// HLS options optimized for adaptive streaming
		$ffmpegCmd .= ' -f hls -hls_time 6 -hls_playlist_type vod -hls_flags independent_segments';
		$ffmpegCmd .= ' -master_pl_name master.m3u8';
		
		// Build var_stream_map - all variants share the single audio stream (a:0)
		$streamMaps = [];
		$streamIndex = 0;
		foreach ($variants as $name => $variant) {
			$streamMaps[] = "v:$streamIndex,a:0,name:$name";
			$streamIndex++;
		}
		$varStreamMap = implode(' ', $streamMaps);
		// Don't use escapeshellarg here - it adds extra quotes that break the command
		$ffmpegCmd .= ' -var_stream_map "' . $varStreamMap . '"';
		
		// Output pattern for variant playlists
		$ffmpegCmd .= ' ' . escapeshellarg($outputPath . '/playlist_%v.m3u8');

		$this->logger->info('Executing optimized FFmpeg command', ['cmd' => $ffmpegCmd]);

		// Execute FFmpeg with extended timeout for multi-bitrate encoding
		$output = [];
		$returnCode = 0;
		set_time_limit(1800); // 30 minutes timeout
		
		// Log the exact command being executed
		$this->logger->info('Executing FFmpeg command', [
			'command' => $ffmpegCmd,
			'inputPath' => $inputPath,
			'outputPath' => $outputPath,
			'variants' => array_keys($variants)
		]);
		
		exec($ffmpegCmd . ' 2>&1', $output, $returnCode);

		if ($returnCode !== 0) {
			$errorOutput = implode("\n", $output);
			$this->logger->error('FFmpeg adaptive HLS generation failed', [
				'returnCode' => $returnCode,
				'output' => $errorOutput,
				'outputLines' => count($output),
				'command' => $ffmpegCmd,
				'commandLength' => strlen($ffmpegCmd)
			]);
			throw new \Exception("FFmpeg failed with return code $returnCode: $errorOutput");
		}

		$this->logger->info('Adaptive HLS generation completed successfully', [
			'output' => implode("\n", array_slice($output, -5))
		]);
	}

	/**
	 * Generate single bitrate HLS as fallback (720p)
	 */
	private function generateSingleHls(string $inputPath, string $outputPath, string $filename): void {
		$this->logger->info('Starting single bitrate HLS generation (fallback)', [
			'input' => $inputPath,
			'output' => $outputPath
		]);

		// Simple single-bitrate HLS command (720p)
		$ffmpegCmd = '/usr/local/bin/ffmpeg -y -i ' . escapeshellarg($inputPath) .
			' -c:v libx264 -preset superfast -crf 24 -maxrate 2400k -bufsize 4000k -s 1280x720' .
			' -c:a aac -b:a 128k' .
			' -f hls -hls_time 6 -hls_playlist_type vod -hls_flags independent_segments' .
			' ' . escapeshellarg($outputPath . '/playlist.m3u8');

		$this->logger->info('Executing single HLS FFmpeg command', ['cmd' => $ffmpegCmd]);

		$output = [];
		$returnCode = 0;
		exec($ffmpegCmd . ' 2>&1', $output, $returnCode);

		if ($returnCode !== 0) {
			$errorOutput = implode("\n", $output);
			$this->logger->error('Single HLS generation also failed', [
				'returnCode' => $returnCode,
				'output' => $errorOutput,
				'command' => $ffmpegCmd
			]);
			throw new \Exception("Single HLS generation failed with return code $returnCode: $errorOutput");
		}

		$this->logger->info('Single HLS generation completed successfully');
	}

	/**
	 * Send completion notification to user
	 */
	private function sendCompletionNotification($user, string $filename, bool $success, string $error = ''): void {
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp('hyper_viewer')
				->setUser($user->getUID())
				->setDateTime(new \DateTime())
				->setObject('hls_cache', $filename)
				->setSubject($success ? 'cache_generated' : 'cache_failed', [
					'filename' => $filename,
					'error' => $error
				]);

			$this->notificationManager->notify($notification);
		} catch (\Exception $e) {
			$this->logger->error('Failed to send notification', [
				'error' => $e->getMessage(),
				'filename' => $filename
			]);
		}
	}

	private function getCurrentUserId(): string {
		// This is a simplified approach - in a real implementation,
		// you'd need to properly handle the user context in background jobs
		return $this->argument['userId'] ?? '';
	}
}
