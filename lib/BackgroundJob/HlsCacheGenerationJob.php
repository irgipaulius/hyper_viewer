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

			// Generate HLS cache
			$this->generateHlsCache($videoLocalPath, $cacheOutputPath, $filename, $overwriteExisting, $userId);

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
	private function generateHlsCache(string $videoLocalPath, string $cacheOutputPath, string $filename, bool $overwriteExisting, string $userId): void {
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

		// Generate simple HLS stream (single bitrate for now)
		$this->generateSimpleHls($videoLocalPath, $cacheLocalPath, $filename);
	}

	/**
	 * Generate simple single-bitrate HLS using FFmpeg
	 */
	private function generateSimpleHls(string $inputPath, string $outputPath, string $filename): void {
		$this->logger->info('Starting simple HLS generation', [
			'input' => $inputPath,
			'output' => $outputPath
		]);

		// Simple HLS command similar to Video Converter approach
		$ffmpegCmd = '/usr/local/bin/ffmpeg -y -i ' . escapeshellarg($inputPath) . 
			' -c:v libx264 -preset fast -c:a aac -b:a 128k' .
			' -f hls -hls_time 6 -hls_playlist_type vod' .
			' -hls_segment_filename ' . escapeshellarg($outputPath . '/segment_%03d.ts') .
			' ' . escapeshellarg($outputPath . '/playlist.m3u8');

		$this->logger->info('Executing FFmpeg command', ['cmd' => $ffmpegCmd]);

		// Execute FFmpeg
		$output = [];
		$returnCode = 0;
		exec($ffmpegCmd . ' 2>&1', $output, $returnCode);

		if ($returnCode !== 0) {
			$errorOutput = implode("\n", $output);
			$this->logger->error('FFmpeg failed', [
				'returnCode' => $returnCode,
				'output' => $errorOutput,
				'command' => $ffmpegCmd
			]);
			throw new \Exception("FFmpeg failed with return code $returnCode: $errorOutput");
		}

		$this->logger->info('Simple HLS generation completed successfully', [
			'output' => implode("\n", array_slice($output, -3)) // Last 3 lines
		]);
	}

	/**
	 * Generate multi-bitrate HLS using FFmpeg (for future use)
	 */
	private function generateMultiBitrateHls(string $inputPath, string $outputPath, string $filename): void {
		// Define bitrate variants
		$variants = [
			['resolution' => '1920x1080', 'bitrate' => '5000k', 'name' => '1080p'],
			['resolution' => '1280x720', 'bitrate' => '2500k', 'name' => '720p'],
			['resolution' => '854x480', 'bitrate' => '1000k', 'name' => '480p'],
			['resolution' => '640x360', 'bitrate' => '500k', 'name' => '360p']
		];

		$this->logger->info('Starting FFmpeg HLS generation', [
			'input' => $inputPath,
			'output' => $outputPath,
			'variants' => count($variants)
		]);

		// Build FFmpeg command for multi-bitrate HLS
		$ffmpegCmd = '/usr/local/bin/ffmpeg -i ' . escapeshellarg($inputPath);
		
		// Add video streams for each variant
		foreach ($variants as $i => $variant) {
			$ffmpegCmd .= sprintf(
				' -map 0:v:0 -c:v:%d libx264 -b:v:%d %s -s:%d %s -profile:v:%d main -level:%d 3.1',
				$i, $i, $variant['bitrate'], $i, $variant['resolution'], $i, $i
			);
		}

		// Add audio stream (copy original)
		$ffmpegCmd .= ' -map 0:a:0 -c:a aac -b:a 128k';

		// HLS options
		$ffmpegCmd .= ' -f hls -hls_time 6 -hls_playlist_type vod';
		$ffmpegCmd .= ' -hls_segment_filename ' . escapeshellarg($outputPath . '/segment_%v_%03d.ts');
		$ffmpegCmd .= ' -master_pl_name master.m3u8';
		
		// Variant playlist names
		foreach ($variants as $i => $variant) {
			$ffmpegCmd .= ' -hls_segment_filename ' . escapeshellarg($outputPath . '/segment_' . $variant['name'] . '_%03d.ts');
		}
		
		$ffmpegCmd .= ' -var_stream_map "';
		foreach ($variants as $i => $variant) {
			if ($i > 0) $ffmpegCmd .= ' ';
			$ffmpegCmd .= "v:$i,a:0,name:{$variant['name']}";
		}
		$ffmpegCmd .= '"';
		
		$ffmpegCmd .= ' ' . escapeshellarg($outputPath . '/playlist_%v.m3u8');

		$this->logger->debug('FFmpeg command', ['cmd' => $ffmpegCmd]);

		// Execute FFmpeg
		$output = [];
		$returnCode = 0;
		exec($ffmpegCmd . ' 2>&1', $output, $returnCode);

		if ($returnCode !== 0) {
			$errorOutput = implode("\n", $output);
			$this->logger->error('FFmpeg failed', [
				'returnCode' => $returnCode,
				'output' => $errorOutput
			]);
			throw new \Exception("FFmpeg failed with return code $returnCode: $errorOutput");
		}

		$this->logger->info('FFmpeg HLS generation completed successfully', [
			'output' => implode("\n", array_slice($output, -5)) // Last 5 lines
		]);
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
