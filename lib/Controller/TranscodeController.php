<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\ILogger;
use OCP\AppFramework\Http;

class TranscodeController extends Controller {
	private IRootFolder $rootFolder;
	private IUserSession $userSession;
	private ILogger $logger;
	
	public function __construct(
		string $appName,
		IRequest $request,
		IRootFolder $rootFolder,
		IUserSession $userSession,
		ILogger $logger
	) {
		parent::__construct($appName, $request);
		$this->rootFolder = $rootFolder;
		$this->userSession = $userSession;
		$this->logger = $logger;
	}

	/**
	 * Start on-demand transcoding and return proxy URL
	 * 
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function proxyTranscode(): JSONResponse {
		$path = $this->request->getParam('path');
		$resolution = $this->request->getParam('resolution', '720p');
		$preset = $this->request->getParam('preset', 'ultrafast');
		
		if (!$path) {
			return new JSONResponse(['error' => 'Missing path parameter'], Http::STATUS_BAD_REQUEST);
		}
		
		// Decode the URL-encoded path
		$path = urldecode($path);

		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();

		try {
			// Validate file access
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$file = $userFolder->get($path);
			
			if (!$file instanceof File) {
				return new JSONResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
			}

			$realPath = $file->getStorage()->getLocalFile($file->getInternalPath());
			if (!$realPath || !file_exists($realPath)) {
				return new JSONResponse(['error' => 'File not accessible'], Http::STATUS_NOT_FOUND);
			}

			// Generate unique ID for this transcode
			$uuid = uniqid('transcode_', true);
			$tempDir = '/tmp/hypertranscode';
			$tempFile = $tempDir . '/' . $uuid . '.mp4';
			
			// Ensure temp directory exists
			if (!is_dir($tempDir)) {
				mkdir($tempDir, 0755, true);
			}
			
			// Check if we already have a fresh version
			if (file_exists($tempFile) && (time() - filemtime($tempFile)) < 7200) { // 2 hours
				$this->logger->info("🔄 Reusing existing transcode: {$uuid}", ['app' => 'hyper_viewer']);
				return new JSONResponse(['url' => "/apps/hyper_viewer/api/proxy-stream?id={$uuid}"]);
			}
			
			// Start background transcoding
			$this->startBackgroundTranscode($realPath, $tempFile, $resolution, $preset, $uuid);
			
			$this->logger->info("🎬 Started transcode: {$uuid} for {$path}", ['app' => 'hyper_viewer']);
			
			return new JSONResponse(['url' => "/apps/hyper_viewer/api/proxy-stream?id={$uuid}"]);

		} catch (\Exception $e) {
			$this->logger->error("❌ Proxy transcode error: " . $e->getMessage(), ['app' => 'hyper_viewer']);
			return new JSONResponse(['error' => 'Internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Stream progressive MP4 file
	 * 
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function proxyStream(): Response {
		$id = $this->request->getParam('id');
		
		if (!$id) {
			return new JSONResponse(['error' => 'Missing id parameter'], Http::STATUS_BAD_REQUEST);
		}
		
		$tempFile = '/tmp/hypertranscode/' . $id . '.mp4';
		
		if (!file_exists($tempFile)) {
			return new JSONResponse(['error' => 'Transcode not found or not ready'], Http::STATUS_NOT_FOUND);
		}
		
		return $this->streamProgressiveMP4($tempFile);
	}

	/**
	 * Start background transcoding process
	 */
	private function startBackgroundTranscode(string $inputPath, string $outputPath, string $resolution, string $preset, string $uuid): void {
		$height = $this->getHeightFromResolution($resolution);
		
		// Build FFmpeg command for progressive MP4
		$cmd = [
			'ffmpeg',
			'-threads', '3',
			'-i', escapeshellarg($inputPath),
			'-vf', "scale=-2:{$height}",
			'-c:v', 'libx264',
			'-preset', $preset,
			'-crf', '28',
			'-c:a', 'aac',
			'-b:a', '128k',
			'-movflags', '+faststart',
			escapeshellarg($outputPath)
		];
		
		$ffmpegCmd = implode(' ', $cmd);
		$this->logger->info("🎬 Starting background transcode: {$uuid}", ['app' => 'hyper_viewer']);
		$this->logger->info("🎬 FFmpeg command: {$ffmpegCmd}", ['app' => 'hyper_viewer']);
		
		// Start FFmpeg in background
		exec($ffmpegCmd . ' > /dev/null 2>&1 &');
	}

	/**
	 * Stream progressive MP4 file with range support
	 */
	private function streamProgressiveMP4(string $filePath): Response {
		$fileSize = filesize($filePath);
		$range = $this->request->getHeader('Range');
		
		// Handle range requests for seeking
		if ($range) {
			preg_match('/bytes=(\d+)-(\d*)/', $range, $matches);
			$start = intval($matches[1]);
			$end = $matches[2] ? intval($matches[2]) : $fileSize - 1;
			$length = $end - $start + 1;
			
			$response = new Response();
			$response->setStatus(206); // Partial Content
			$response->addHeader('Content-Type', 'video/mp4');
			$response->addHeader('Accept-Ranges', 'bytes');
			$response->addHeader('Content-Length', (string)$length);
			$response->addHeader('Content-Range', "bytes {$start}-{$end}/{$fileSize}");
			$response->addHeader('Cache-Control', 'no-cache');
			
			// Stream the requested range
			$handle = fopen($filePath, 'rb');
			fseek($handle, $start);
			$buffer = fread($handle, $length);
			fclose($handle);
			
			$response->setOutput($buffer);
			return $response;
		}
		
		// Normal full file response
		$response = new Response();
		$response->addHeader('Content-Type', 'video/mp4');
		$response->addHeader('Content-Length', (string)$fileSize);
		$response->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
		
		// Stream the full file
		$handle = fopen($filePath, 'rb');
		$buffer = fread($handle, $fileSize);
		fclose($handle);
		
		$response->setOutput($buffer);
		return $response;
	}
				$this->addHeader('Expires', '0');
				$this->addHeader('Accept-Ranges', 'none');
			}

			public function output() {
				$startTime = microtime(true);
				
				// Register this process
				TranscodeController::registerProcess($this->processId, $this->userId, getmypid());
				
				// Set up process descriptors
				$descriptors = [
					0 => ['pipe', 'r'],  // stdin
					1 => ['pipe', 'w'],  // stdout
					2 => ['pipe', 'w']   // stderr
				];

				// Start FFmpeg process
				$this->process = proc_open($this->ffmpegCmd, $descriptors, $pipes);
				
				if (!is_resource($this->process)) {
					echo "Error: Could not start FFmpeg process";
					return;
				}
				
				// Test if FFmpeg actually started by checking process status
				$status = proc_get_status($this->process);
				if (!$status['running']) {
					$error = stream_get_contents($pipes[2]);
					echo "FFmpeg failed to start. Error: " . $error;
					return;
				}

				// Close stdin
				fclose($pipes[0]);

				// Set streams to non-blocking
				stream_set_blocking($pipes[1], false);
				stream_set_blocking($pipes[2], false);

				$this->logger->info("⚡ Live transcode started for {$this->originalPath}", ['app' => 'hyper_viewer']);

				// Stream output to client
				$lastActivity = time();
				$bytesStreamed = 0;
				
				while (true) {
					// Check if client disconnected
					if (connection_aborted()) {
						$this->logger->info("🔌 Client disconnected, stopping transcode", ['app' => 'hyper_viewer']);
						break;
					}

					// Read from stdout
					$data = fread($pipes[1], 8192);
					if ($data !== false && strlen($data) > 0) {
						echo $data;
						flush();
						$bytesStreamed += strlen($data);
						$lastActivity = time();
					}

					// Check for errors
					$error = fread($pipes[2], 1024);
					if ($error !== false && strlen($error) > 0) {
						$this->logger->debug("FFmpeg stderr: " . trim($error), ['app' => 'hyper_viewer']);
					}

					// Check if process is still running
					$status = proc_get_status($this->process);
					if (!$status['running']) {
						$this->logger->info("🏁 FFmpeg process finished", ['app' => 'hyper_viewer']);
						break;
					}

					// Timeout check
					if (time() - $lastActivity > TranscodeController::PROCESS_TIMEOUT) {
						$this->logger->warning("⏰ Transcode timeout, terminating process", ['app' => 'hyper_viewer']);
						break;
					}

					// Small delay to prevent CPU spinning
					usleep(10000); // 10ms
				}

				// Cleanup
				$this->cleanup($pipes);
				
				$duration = round(microtime(true) - $startTime, 2);
				$mbStreamed = round($bytesStreamed / 1024 / 1024, 2);
				$this->logger->info("✅ Transcode completed: {$duration}s, {$mbStreamed}MB streamed", ['app' => 'hyper_viewer']);
			}

			private function cleanup($pipes) {
				// Close pipes
				foreach ($pipes as $pipe) {
					if (is_resource($pipe)) {
						fclose($pipe);
					}
				}

				// Terminate process if still running
				if (is_resource($this->process)) {
					$status = proc_get_status($this->process);
					if ($status['running']) {
						proc_terminate($this->process, SIGTERM);
						// Wait a bit, then force kill if necessary
						sleep(2);
						$status = proc_get_status($this->process);
						if ($status['running']) {
							proc_terminate($this->process, SIGKILL);
						}
					}
					proc_close($this->process);
				}

				// Unregister process
				TranscodeController::unregisterProcess($this->processId);
			}

			public function __destruct() {
				if (isset($this->process) && is_resource($this->process)) {
					$this->cleanup([]);
				}
			}
		};
	}

	/**
	 * Build FFmpeg command for live transcoding
	 */
	private function buildFFmpegCommand(string $inputPath, string $resolution): string {
		$height = $this->getHeightFromResolution($resolution);
		
		// WebM with proper codecs
		$cmd = [
			'ffmpeg',
			'-i', escapeshellarg($inputPath),
			'-vf', "scale=-2:{$height}",
			'-c:v', 'libvpx',
			'-b:v', '500k',
			'-c:a', 'libvorbis',
			'-f', 'webm',
			'pipe:1'
		];
		
		return implode(' ', $cmd);
	}

	/**
	 * Get height from resolution string
	 */
	private function getHeightFromResolution(string $resolution): int {
		switch ($resolution) {
			case '1080p':
				return 1080;
			case '720p':
				return 720;
			case '480p':
				return 480;
			case '360p':
				return 360;
			case '240p':
				return 240;
			default:
				return 240; // Default to 240p
		}
	}

	/**
	 * Get bitrate from resolution string
	 */
	private function getBitrateFromResolution(string $resolution): string {
		switch ($resolution) {
			case '1080p':
				return '4000k';
			case '720p':
				return '2500k';
			case '480p':
				return '1200k';
			case '360p':
				return '800k';
			case '240p':
				return '500k';
			default:
				return '500k'; // Default to 240p bitrate
		}
	}

	/**
	 * Get buffer size from bitrate (2x bitrate for good buffering)
	 */
	private function getBufferSize(string $bitrate): string {
		$numeric = (int) str_replace('k', '', $bitrate);
		return ($numeric * 2) . 'k';
	}

	/**
	 * Register an active process
	 */
	public static function registerProcess(string $processId, string $userId, int $pid): void {
		self::$activeProcesses[$processId] = [
			'user_id' => $userId,
			'pid' => $pid,
			'start_time' => time()
		];
	}

	/**
	 * Unregister a process
	 */
	public static function unregisterProcess(string $processId): void {
		unset(self::$activeProcesses[$processId]);
	}

	/**
	 * Get count of active processes for a user
	 */
	private function getUserActiveProcessCount(string $userId): int {
		$count = 0;
		foreach (self::$activeProcesses as $process) {
			if ($process['user_id'] === $userId) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Clean up stale processes
	 */
	private function cleanupStaleProcesses(): void {
		$now = time();
		foreach (self::$activeProcesses as $processId => $process) {
			// Remove processes older than timeout
			if ($now - $process['start_time'] > self::PROCESS_TIMEOUT * 2) {
				$this->logger->info("🧹 Cleaning up stale process {$processId}", ['app' => 'hyper_viewer']);
				unset(self::$activeProcesses[$processId]);
			}
		}
	}

	/**
	 * Get transcode status
	 * 
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function status(): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();
		$this->cleanupStaleProcesses();
		
		return new JSONResponse([
			'active_processes' => $this->getUserActiveProcessCount($userId),
			'max_processes' => self::MAX_PROCESSES_PER_USER,
			'timeout_seconds' => self::PROCESS_TIMEOUT
		]);
	}
}
