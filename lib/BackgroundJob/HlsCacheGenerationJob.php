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

		// Check if cache already exists and skip if not overwriting
		if (!$overwriteExisting && $this->cacheAlreadyExists($userFolder, $cacheOutputPath)) {
			$this->logger->info('HLS cache already exists, skipping generation', [
				'jobId' => $jobId,
				'filename' => $filename,
				'cachePath' => $cacheOutputPath
			]);

			// Send notification if requested
			if ($notifyCompletion) {
				$this->sendCompletionNotification($user, $filename, true);
			}
			return;
		}

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
				if (substr($customPath, -11) !== '.cached_hls') {
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

		// Acquire FFmpeg concurrency lock with retry mechanism
		$ffmpegLockId = $this->acquireFFmpegLock();
		if ($ffmpegLockId === false) {
			throw new \Exception("Failed to acquire FFmpeg concurrency lock after maximum retries");
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
		} finally {
			// Always release the FFmpeg lock
			$this->releaseFFmpegLock($ffmpegLockId);
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

		// Define optimized bitrate variants with resolution-specific presets
		$allVariants = [
			'1080p' => ['resolution' => '1920x1080', 'bitrate' => '8000k', 'maxrate' => '12000k', 'bufsize' => '16000k', 'crf' => '18', 'preset' => 'medium', 'profile' => 'high', 'level' => '4.1', 'tune' => 'film'],
			'720p' => ['resolution' => '1280x720', 'bitrate' => '3000k', 'maxrate' => '3600k', 'bufsize' => '6000k', 'crf' => '23', 'preset' => 'superfast'],
			'480p' => ['resolution' => '854x480', 'bitrate' => '800k', 'maxrate' => '1000k', 'bufsize' => '1600k', 'crf' => '26', 'preset' => 'superfast'],
			'360p' => ['resolution' => '640x360', 'bitrate' => '500k', 'maxrate' => '600k', 'bufsize' => '1000k', 'crf' => '28', 'preset' => 'superfast'],
			'240p' => ['resolution' => '426x240', 'bitrate' => '300k', 'maxrate' => '400k', 'bufsize' => '600k', 'crf' => '30', 'preset' => 'superfast']
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

		// Build FFmpeg command for adaptive streaming (FFmpeg 4.4.x compatible)
		// Add flags to reduce file locking issues with WebDAV
		$ffmpegCmd = '/usr/local/bin/ffmpeg -y -fflags +genpts -avoid_negative_ts make_zero -i ' . escapeshellarg($inputPath);
		
		// Detect input format and add format-specific optimization flags
		$fileExtension = strtolower(pathinfo($inputPath, PATHINFO_EXTENSION));
		
		if ($fileExtension === 'mp4') {
			// MP4-specific optimizations: corruption handling, frame rate stabilization, GOP structure
			$ffmpegCmd .= ' -threads 2 -fflags +discardcorrupt -err_detect ignore_err -use_wallclock_as_timestamps 1 -vf fps=30 -g 180 -keyint_min 60';
			$this->logger->info('Applied MP4-specific FFmpeg optimizations', [
				'threads' => 2,
				'corruption_handling' => true,
				'fps_stabilization' => '30fps',
				'gop_structure' => '180/60'
			]);
		} elseif ($fileExtension === 'mov') {
			// MOV-specific optimizations: enhanced probing for complex QuickTime structures
			$ffmpegCmd .= ' -threads 4 -probesize 50M -analyzeduration 100M';
			$this->logger->info('Applied MOV-specific FFmpeg optimizations', [
				'threads' => 4,
				'probesize' => '50MB',
				'analyzeduration' => '100MB'
			]);
		} else {
			// Default optimizations for other formats
			$ffmpegCmd .= ' -threads 2';
			$this->logger->info('Applied default FFmpeg optimizations', [
				'format' => $fileExtension,
				'threads' => 2
			]);
		}
		
		// Map video and audio streams for each variant (separate audio per variant for FFmpeg 4.4.x)
		$streamIndex = 0;
		foreach ($variants as $name => $variant) {
			// Map video stream for this variant
			$videoCmd = sprintf(
				' -map 0:v:0 -c:v:%d libx264 -preset %s -crf %s -maxrate %s -bufsize %s -s:v:%d %s',
				$streamIndex, $variant['preset'], $variant['crf'], $variant['maxrate'], $variant['bufsize'], $streamIndex, $variant['resolution']
			);
			
			// Add profile (use 'high' for 1080p, 'main' for others)
			$profile = isset($variant['profile']) ? $variant['profile'] : 'main';
			$videoCmd .= sprintf(' -profile:v:%d %s', $streamIndex, $profile);
			
			// Add level for 1080p (better compatibility and quality)
			if (isset($variant['level'])) {
				$videoCmd .= sprintf(' -level:v:%d %s', $streamIndex, $variant['level']);
			}
			
			// Add tune for 1080p (optimizes for film content)
			if (isset($variant['tune'])) {
				$videoCmd .= sprintf(' -tune:%d %s', $streamIndex, $variant['tune']);
			}
			
			// Add additional quality flags for 1080p
			if ($name === '1080p') {
				$videoCmd .= sprintf(' -refs:%d 6 -me_method:%d hex -subq:%d 8 -trellis:%d 2 -bf:%d 3 -b_strategy:%d 2 -coder:%d 1', 
					$streamIndex, $streamIndex, $streamIndex, $streamIndex, $streamIndex, $streamIndex, $streamIndex);
			}
			
			$ffmpegCmd .= $videoCmd;
			
			// Map audio stream for this variant (each variant needs its own audio for FFmpeg 4.4.x)
			$ffmpegCmd .= sprintf(' -map 0:a:0 -c:a:%d aac -b:a:%d 128k', $streamIndex, $streamIndex);
			$streamIndex++;
		}

		// HLS options optimized for adaptive streaming
		$ffmpegCmd .= ' -f hls -hls_time 6 -hls_playlist_type vod -hls_flags independent_segments';
		$ffmpegCmd .= ' -master_pl_name master.m3u8';
		
		// Build var_stream_map - each variant has its own video and audio stream
		$streamMaps = [];
		$streamIndex = 0;
		foreach ($variants as $name => $variant) {
			$streamMaps[] = "v:$streamIndex,a:$streamIndex,name:$name";
			$streamIndex++;
		}
		$varStreamMap = implode(' ', $streamMaps);
		// Don't use escapeshellarg here - it adds extra quotes that break the command
		$ffmpegCmd .= ' -var_stream_map "' . $varStreamMap . '"';
		
		// Output pattern for variant playlists
		$ffmpegCmd .= ' ' . escapeshellarg($outputPath . '/playlist_%v.m3u8');

		// Add progress output to log file for real-time tracking
		$logFile = $outputPath . '/generation.log';
		$progressFile = $outputPath . '/progress.json';
		
		// Initialize progress file BEFORE starting FFmpeg
		$this->initializeProgressFile($progressFile, $filename, $resolutions);
		
		// Add progress piping to FFmpeg command
		$ffmpegCmd .= ' -progress ' . escapeshellarg($progressFile . '.raw') . ' 2>&1 | tee ' . escapeshellarg($logFile);

		$this->logger->info('Executing optimized FFmpeg command', ['cmd' => $ffmpegCmd]);

		// Execute FFmpeg with extended timeout for multi-bitrate encoding
		$output = [];
		$returnCode = 0;
		set_time_limit(3600 * 3); // 3 hour timeout
		
		// Log the exact command being executed
		$this->logger->info('Executing FFmpeg command', [
			'command' => $ffmpegCmd,
			'inputPath' => $inputPath,
			'outputPath' => $outputPath,
			'variants' => array_keys($variants)
		]);
		
		// Execute FFmpeg with real-time progress monitoring
		$this->executeFFmpegWithProgress($ffmpegCmd, $progressFile, $output, $returnCode);

		// Check if FFmpeg actually succeeded by analyzing output
		$outputText = implode("\n", $output);
		$isActuallySuccessful = $this->isFFmpegOutputSuccessful($outputText, $outputPath);
		
		if ($returnCode !== 0 && !$isActuallySuccessful) {
			$this->logger->error('FFmpeg adaptive HLS generation failed', [
				'returnCode' => $returnCode,
				'output' => $outputText,
				'outputLines' => count($output),
				'command' => $ffmpegCmd,
				'commandLength' => strlen($ffmpegCmd)
			]);
			
			// Update progress file to indicate failure
			$this->updateProgressFileCompletion($progressFile, false, $outputText);
			
			throw new \Exception("FFmpeg failed with return code $returnCode: $outputText");
		} elseif ($returnCode !== 0 && $isActuallySuccessful) {
			// FFmpeg succeeded but returned non-zero code (common with progress piping)
			$this->logger->debug('FFmpeg completed successfully despite non-zero return code', [
				'returnCode' => $returnCode,
				'outputPath' => $outputPath,
				'outputLines' => count($output)
			]);
		}

		$this->logger->info('Adaptive HLS generation completed successfully', [
			'output' => implode("\n", array_slice($output, -5))
		]);

		// Update progress file to indicate completion (clear any error field)
		$this->updateProgressFileCompletion($progressFile, true);
	}

	/**
	 * Check if FFmpeg output indicates successful completion
	 */
	private function isFFmpegOutputSuccessful(string $output, string $outputPath): bool {
		// Check for successful completion indicators in FFmpeg output
		$successIndicators = [
			'muxing overhead:',           // Final statistics line
			'kb/s:',                     // Bitrate statistics (final output)
			'Opening \'' . $outputPath . '/master.m3u8\' for writing',  // Master playlist creation
			'video:.*audio:.*subtitle:.*other streams:.*global headers:.*muxing overhead'  // Final summary
		];
		
		foreach ($successIndicators as $indicator) {
			if (preg_match('/' . preg_quote($indicator, '/') . '/i', $output)) {
				// Also verify that actual files were created
				if (file_exists($outputPath . '/master.m3u8') || 
					file_exists($outputPath . '/playlist.m3u8') ||
					glob($outputPath . '/playlist_*.m3u8')) {
					return true;
				}
			}
		}
		
		// Check for error indicators that would suggest actual failure
		$errorIndicators = [
			'No such file or directory',
			'Permission denied',
			'Invalid data found',
			'Conversion failed',
			'Error opening',
			'Could not open'
		];
		
		foreach ($errorIndicators as $errorIndicator) {
			if (preg_match('/' . preg_quote($errorIndicator, '/') . '/i', $output)) {
				return false;
			}
		}
		
		// If we have HLS files created and no clear errors, consider it successful
		return file_exists($outputPath . '/master.m3u8') || 
			   file_exists($outputPath . '/playlist.m3u8') ||
			   !empty(glob($outputPath . '/playlist_*.m3u8'));
	}

	/**
	 * Initialize progress tracking file
	 */
	private function initializeProgressFile(string $progressFile, string $filename, array $resolutions): void {
		$progressData = [
			'status' => 'processing',
			'filename' => $filename,
			'resolutions' => $resolutions,
			'progress' => 0,
			'frame' => 0,
			'fps' => 0,
			'speed' => '0x',
			'time' => '00:00:00',
			'bitrate' => '0kbits/s',
			'size' => '0kB',
			'startTime' => time(),
			'lastUpdate' => time(),
			'completed' => false,
			'error' => null
		];

		// Ensure directory exists
		$dir = dirname($progressFile);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}
		
		file_put_contents($progressFile, json_encode($progressData, JSON_PRETTY_PRINT));
		$this->logger->info('Progress file initialized', ['file' => $progressFile]);
	}

	/**
	 * Update progress file when generation completes
	 */
	private function updateProgressFileCompletion(string $progressFile, bool $success, string $error = ''): void {
		if (file_exists($progressFile)) {
			$progressData = json_decode(file_get_contents($progressFile), true) ?: [];
			$progressData['status'] = $success ? 'completed' : 'failed';
			$progressData['completed'] = $success;
			$progressData['progress'] = $success ? 100 : $progressData['progress'];
			$progressData['lastUpdate'] = time();
			
			if ($success) {
				// Clear error on success
				$progressData['error'] = null;
			} else if ($error) {
				// Sanitize error message - only include actual errors, not FFmpeg version info
				$sanitizedError = $this->sanitizeErrorMessage($error);
				$progressData['error'] = $sanitizedError;
			}
			
			file_put_contents($progressFile, json_encode($progressData, JSON_PRETTY_PRINT));
		}
	}
	
	/**
	 * Sanitize error message to remove FFmpeg version info and other noise
	 */
	private function sanitizeErrorMessage(string $error): string {
		$lines = explode("\n", $error);
		$errorLines = [];
		
		foreach ($lines as $line) {
			$line = trim($line);
			
			// Skip FFmpeg version/build info
			if (strpos($line, 'ffmpeg version') === 0 ||
				strpos($line, 'built with') !== false ||
				strpos($line, 'configuration:') !== false ||
				strpos($line, 'lib') === 0 ||
				empty($line)) {
				continue;
			}
			
			// Include actual error messages
			if (strpos($line, 'Error') !== false ||
				strpos($line, 'error') !== false ||
				strpos($line, 'failed') !== false ||
				strpos($line, 'Invalid') !== false ||
				strpos($line, 'No such file') !== false) {
				$errorLines[] = $line;
			}
		}
		
		return empty($errorLines) ? 'Unknown error occurred' : implode("\n", array_slice($errorLines, 0, 5));
	}

	/**
	 * Generate single bitrate HLS as fallback (720p)
	 */
	private function generateSingleHls(string $inputPath, string $outputPath, string $filename): void {
		$this->logger->info('Starting single bitrate HLS generation (fallback)', [
			'input' => $inputPath,
			'output' => $outputPath
		]);

		// Simple single-bitrate HLS command (720p with higher bitrate)
		// Add flags to reduce file locking issues with WebDAV
		$ffmpegCmd = '/usr/local/bin/ffmpeg -y -fflags +genpts -avoid_negative_ts make_zero -i ' . escapeshellarg($inputPath) .
			' -c:v libx264 -preset superfast -crf 23 -maxrate 3600k -bufsize 6000k -s 1280x720' .
			' -c:a aac -b:a 128k' .
			' -f hls -hls_time 6 -hls_playlist_type vod -hls_flags independent_segments' .
			' ' . escapeshellarg($outputPath . '/playlist.m3u8');

		$this->logger->info('Executing single HLS FFmpeg command', ['cmd' => $ffmpegCmd]);

		$output = [];
		$returnCode = 0;
		exec($ffmpegCmd . ' 2>&1', $output, $returnCode);

		// Check if FFmpeg actually succeeded by analyzing output
		$outputText = implode("\n", $output);
		$isActuallySuccessful = $this->isFFmpegOutputSuccessful($outputText, $outputPath);
		
		if ($returnCode !== 0 && !$isActuallySuccessful) {
			$this->logger->error('Single HLS generation also failed', [
				'returnCode' => $returnCode,
				'output' => $outputText,
				'command' => $ffmpegCmd
			]);
			throw new \Exception("Single HLS generation failed with return code $returnCode: $outputText");
		} elseif ($returnCode !== 0 && $isActuallySuccessful) {
			// FFmpeg succeeded but returned non-zero code (common with progress piping)
			$this->logger->debug('Single HLS generation completed successfully despite non-zero return code', [
				'returnCode' => $returnCode,
				'outputPath' => $outputPath
			]);
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

	/**
	 * Execute FFmpeg with real-time progress monitoring
	 */
	private function executeFFmpegWithProgress(string $ffmpegCmd, string $progressFile, array &$output, int &$returnCode): void {
		$progressRawFile = $progressFile . '.raw';
		
		// Start FFmpeg process
		$descriptorspec = [
			0 => ['pipe', 'r'],  // stdin
			1 => ['pipe', 'w'],  // stdout
			2 => ['pipe', 'w']   // stderr
		];
		
		$process = proc_open($ffmpegCmd, $descriptorspec, $pipes);
		$output = [];
		
		if (is_resource($process)) {
			// Close stdin
			fclose($pipes[0]);
			
			// Read output in real-time
			stream_set_blocking($pipes[1], false);
			stream_set_blocking($pipes[2], false);
			
			while (true) {
				$status = proc_get_status($process);
				
				// Read stdout and stderr
				$stdout = fread($pipes[1], 8192);
				$stderr = fread($pipes[2], 8192);
				
				if ($stdout !== false && $stdout !== '') {
					$output[] = $stdout;
				}
				if ($stderr !== false && $stderr !== '') {
					$output[] = $stderr;
				}
				
				// Parse progress from the .raw file that FFmpeg writes to
				$this->parseProgressFromRawFile($progressRawFile, $progressFile);
				
				if (!$status['running']) {
					break;
				}
				
				usleep(100000); // 0.1 second
			}
			
			// Close pipes
			fclose($pipes[1]);
			fclose($pipes[2]);
			
			// Get return code
			$returnCode = proc_close($process);
		} else {
			$returnCode = -1;
			$output[] = 'Failed to start FFmpeg process';
		}
	}

	/**
	 * Parse progress from FFmpeg's -progress output file
	 */
	private function parseProgressFromRawFile(string $progressRawFile, string $progressFile): void {
		if (!file_exists($progressRawFile) || !file_exists($progressFile)) {
			return;
		}
		
		// Read the raw progress file
		$rawContent = file_get_contents($progressRawFile);
		if (empty($rawContent)) {
			return;
		}
		
		$progressData = json_decode(file_get_contents($progressFile), true) ?: [];
		$updated = false;
		
		// Parse the key=value format from FFmpeg progress output
		// Get the last complete progress block (between progress=continue markers)
		$lines = explode("\n", $rawContent);
		$currentFrame = null;
		$latestProgressBlock = [];
		
		// Find the most recent progress block
		$currentBlock = [];
		foreach ($lines as $line) {
			$line = trim($line);
			if (empty($line)) continue;
			
			if (strpos($line, 'progress=continue') !== false || strpos($line, 'progress=end') !== false) {
				if (!empty($currentBlock)) {
					$latestProgressBlock = $currentBlock;
				}
				$currentBlock = [];
				$currentBlock[] = $line; // Include the progress line
			} else {
				$currentBlock[] = $line;
			}
		}
		
		// Use the latest block if we have one
		if (!empty($latestProgressBlock)) {
			foreach ($latestProgressBlock as $line) {
				if (strpos($line, '=') !== false) {
					list($key, $value) = explode('=', $line, 2);
					$key = trim($key);
					$value = trim($value);
					
					switch ($key) {
						case 'frame':
							$progressData['frame'] = (int)$value;
							$currentFrame = (int)$value;
							$updated = true;
							break;
						case 'fps':
							$progressData['fps'] = (float)$value;
							$updated = true;
							break;
						case 'speed':
							$progressData['speed'] = $value;
							$updated = true;
							break;
						case 'out_time':
							$progressData['time'] = substr($value, 0, 8); // Trim to HH:MM:SS
							$updated = true;
							break;
						case 'bitrate':
							if ($value !== 'N/A') {
								$progressData['bitrate'] = $value;
								$updated = true;
							}
							break;
						case 'total_size':
							if ($value !== 'N/A') {
								$sizeKB = round((int)$value / 1024);
								$progressData['size'] = $sizeKB . 'kB';
								$updated = true;
							}
							break;
						case 'progress':
							if ($value === 'end') {
								$progressData['status'] = 'completed';
								$progressData['completed'] = true;
								$progressData['progress'] = 100;
								$updated = true;
								$this->logger->info('FFmpeg progress detected completion');
							}
							break;
					}
				}
			}
		}
		
		// Calculate progress percentage based on time and frame count
		if ($currentFrame && $progressData['time'] !== '00:00:00') {
			// Convert time to seconds for calculation
			$timeParts = explode(':', $progressData['time']);
			$currentSeconds = ($timeParts[0] * 3600) + ($timeParts[1] * 60) + $timeParts[2];
			
			// Estimate total duration based on typical video lengths
			// For better accuracy, we could store video duration, but this is a reasonable estimate
			$estimatedTotalSeconds = max($currentSeconds * 2, 300); // At least 5 minutes, or double current time
			
			// Calculate percentage based on time progress
			$timeBasedProgress = min(($currentSeconds / $estimatedTotalSeconds) * 100, 99);
			
			// Also calculate frame-based progress (backup method)
			$frameBasedProgress = min(($currentFrame / 5000) * 100, 99); // Assume ~5000 frames for typical video
			
			// Use the higher of the two estimates (more conservative)
			$estimatedProgress = max($timeBasedProgress, $frameBasedProgress);
			
			$progressData['progress'] = (int)$estimatedProgress;
			$updated = true;
		} elseif ($currentFrame > 0) {
			// Fallback: frame-only based progress
			$frameBasedProgress = min(($currentFrame / 3000) * 100, 95); // Conservative estimate
			$progressData['progress'] = (int)$frameBasedProgress;
			$updated = true;
		}
		
		if ($updated) {
			$progressData['lastUpdate'] = time();
			if (!isset($progressData['status']) || $progressData['status'] !== 'completed') {
				$progressData['status'] = 'processing';
			}
			file_put_contents($progressFile, json_encode($progressData, JSON_PRETTY_PRINT));
		}
	}

	/**
	 * Parse FFmpeg progress output and update progress file (legacy method)
	 */
	private function parseAndUpdateProgress(string $output, string $progressFile): void {
		if (!file_exists($progressFile)) {
			return;
		}
		
		$progressData = json_decode(file_get_contents($progressFile), true) ?: [];
		$updated = false;
		
		// Split output into lines for better parsing
		$lines = explode("\n", $output);
		
		foreach ($lines as $line) {
			$line = trim($line);
			
			// Parse frame count
			if (preg_match('/^frame=(\d+)$/', $line, $matches)) {
				$progressData['frame'] = (int)$matches[1];
				$updated = true;
			}
			
			// Parse FPS
			if (preg_match('/^fps=([\d.]+)$/', $line, $matches)) {
				$progressData['fps'] = (float)$matches[1];
				$updated = true;
			}
			
			// Parse speed
			if (preg_match('/^speed=([\d.]+x)$/', $line, $matches)) {
				$progressData['speed'] = $matches[1];
				$updated = true;
			}
			
			// Parse time (out_time format)
			if (preg_match('/^out_time=(\d{2}:\d{2}:\d{2}\.\d+)$/', $line, $matches)) {
				$progressData['time'] = substr($matches[1], 0, 8); // Trim to HH:MM:SS
				$updated = true;
			}
			
			// Parse bitrate (if available)
			if (preg_match('/^bitrate=([\d.]+kbits\/s)$/', $line, $matches)) {
				$progressData['bitrate'] = $matches[1];
				$updated = true;
			}
			
			// Parse total size (if available)
			if (preg_match('/^total_size=(\d+)$/', $line, $matches)) {
				$sizeKB = round((int)$matches[1] / 1024);
				$progressData['size'] = $sizeKB . 'kB';
				$updated = true;
			}
			
			// Check for completion
			if (preg_match('/^progress=end$/', $line)) {
				$progressData['status'] = 'completed';
				$progressData['completed'] = true;
				$progressData['progress'] = 100;
				$updated = true;
				$this->logger->info('FFmpeg progress detected completion');
			}
		}
		
		if ($updated) {
			$progressData['lastUpdate'] = time();
			if (!isset($progressData['status']) || $progressData['status'] !== 'completed') {
				$progressData['status'] = 'processing';
			}
			file_put_contents($progressFile, json_encode($progressData, JSON_PRETTY_PRINT));
		}
	}

	/**
	 * Check if HLS cache already exists for the given path
	 */
	private function cacheAlreadyExists($userFolder, string $cacheOutputPath): bool {
		try {
			if ($userFolder->nodeExists($cacheOutputPath)) {
				$cacheFolder = $userFolder->get($cacheOutputPath);
				if ($cacheFolder instanceof \OCP\Files\Folder) {
					// Check for master.m3u8 (adaptive streaming) or playlist.m3u8 (single bitrate)
					return $cacheFolder->nodeExists('master.m3u8') || $cacheFolder->nodeExists('playlist.m3u8');
				}
			}
		} catch (\Exception $e) {
			$this->logger->debug('Error checking cache existence', ['error' => $e->getMessage()]);
		}
		return false;
	}

	/**
	 * Acquire FFmpeg concurrency lock (max 4 simultaneous processes)
	 * @return string|false Lock ID on success, false on failure
	 */
	private function acquireFFmpegLock() {
		$lockDir = '/tmp/hyper_ffmpeg_locks';
		$maxConcurrency = 4;
		$maxRetries = 18; // 18 * 10 seconds = 3 minutes max wait
		$retryDelay = 10; // seconds

		// Create lock directory if it doesn't exist
		if (!is_dir($lockDir)) {
			mkdir($lockDir, 0755, true);
		}

		for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
			// Clean up stale locks (older than 4 hours)
			$this->cleanupStaleLocks($lockDir);

			// Count current active locks
			$activeLocks = glob($lockDir . '/ffmpeg_*.lock');
			$currentCount = count($activeLocks);

			if ($currentCount < $maxConcurrency) {
				// Create new lock
				$lockId = uniqid('ffmpeg_' . getmypid() . '_', true);
				$lockFile = $lockDir . '/' . $lockId . '.lock';
				
				$lockData = [
					'pid' => getmypid(),
					'startTime' => time(),
					'hostname' => gethostname(),
					'lockId' => $lockId
				];

				if (file_put_contents($lockFile, json_encode($lockData)) !== false) {
					$this->logger->info('Acquired FFmpeg concurrency lock', [
						'lockId' => $lockId,
						'currentConcurrency' => $currentCount + 1,
						'maxConcurrency' => $maxConcurrency
					]);
					return $lockId;
				}
			}

			// Wait before retrying if we haven't reached max concurrency
			if ($attempt < $maxRetries - 1) {
				$this->logger->info('FFmpeg concurrency limit reached, waiting for slot', [
					'currentConcurrency' => $currentCount,
					'maxConcurrency' => $maxConcurrency,
					'attempt' => $attempt + 1,
					'waitTime' => $retryDelay
				]);
				sleep($retryDelay);
			}
		}

		$this->logger->error('Failed to acquire FFmpeg lock after maximum retries', [
			'maxRetries' => $maxRetries,
			'totalWaitTime' => $maxRetries * $retryDelay
		]);
		return false;
	}

	/**
	 * Release FFmpeg concurrency lock
	 */
	private function releaseFFmpegLock(string $lockId): void {
		$lockDir = '/tmp/hyper_ffmpeg_locks';
		$lockFile = $lockDir . '/' . $lockId . '.lock';

		if (file_exists($lockFile)) {
			unlink($lockFile);
			$this->logger->info('Released FFmpeg concurrency lock', ['lockId' => $lockId]);
		}
	}

	/**
	 * Clean up stale FFmpeg locks (older than 4 hours)
	 */
	private function cleanupStaleLocks(string $lockDir): void {
		$staleThreshold = time() - (4 * 3600); // 4 hours
		$lockFiles = glob($lockDir . '/ffmpeg_*.lock');

		foreach ($lockFiles as $lockFile) {
			$mtime = filemtime($lockFile);
			if ($mtime && $mtime < $staleThreshold) {
				$this->logger->info('Removing stale FFmpeg lock', [
					'lockFile' => basename($lockFile),
					'age' => time() - $mtime
				]);
				unlink($lockFile);
			}
		}
	}
}
