<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCP\IRequest;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Controller;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCP\ILogger;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Controller for live transcoding of video files
 */
class TranscodeController extends Controller {
	
	private IRootFolder $rootFolder;
	private IUserSession $userSession;
	private ILogger $logger;
	private static array $activeProcesses = [];
	private const MAX_PROCESSES_PER_USER = 1;
	private const PROCESS_TIMEOUT = 60; // 60 seconds
	
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
	 * Live transcode a video file to MP4 stream
	 * 
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function stream(): Response {
		$path = $this->request->getParam('path');
		$resolution = $this->request->getParam('resolution', '240p');
		
		if (!$path) {
			return new JSONResponse(['error' => 'Missing path parameter'], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();
		
		// Check if user already has active processes
		$this->cleanupStaleProcesses();
		if ($this->getUserActiveProcessCount($userId) >= self::MAX_PROCESSES_PER_USER) {
			$this->logger->warning("🚫 User {$userId} exceeded max transcode processes", ['app' => 'hyper_viewer']);
			return new JSONResponse(['error' => 'Too many active transcoding processes'], Http::STATUS_TOO_MANY_REQUESTS);
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$file = $userFolder->get($path);
			
			if (!$file->isReadable()) {
				return new JSONResponse(['error' => 'File not accessible'], Http::STATUS_FORBIDDEN);
			}

			$realPath = $file->getStorage()->getLocalFile($file->getInternalPath());
			if (!$realPath || !file_exists($realPath)) {
				return new JSONResponse(['error' => 'File not found on disk'], Http::STATUS_NOT_FOUND);
			}

			$this->logger->info("🚀 Starting live transcode for file {$path} ({$resolution})", ['app' => 'hyper_viewer']);

			return $this->streamTranscode($realPath, $resolution, $userId, $path);

		} catch (\Exception $e) {
			$this->logger->error("❌ Transcode error: " . $e->getMessage(), ['app' => 'hyper_viewer']);
			return new JSONResponse(['error' => 'Internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Stream transcoded video using FFmpeg
	 */
	private function streamTranscode(string $filePath, string $resolution, string $userId, string $originalPath): Response {
		$processId = uniqid('transcode_', true);
		
		// Build FFmpeg command
		$ffmpegCmd = $this->buildFFmpegCommand($filePath, $resolution);
		
		$this->logger->info("🎬 FFmpeg command: {$ffmpegCmd}", ['app' => 'hyper_viewer']);
		$this->logger->info("🎬 Input file exists: " . (file_exists($filePath) ? 'YES' : 'NO'), ['app' => 'hyper_viewer']);
		$this->logger->info("🎬 Input file size: " . (file_exists($filePath) ? filesize($filePath) : 'N/A') . ' bytes', ['app' => 'hyper_viewer']);

		// Create custom response that handles the streaming
		return new class($ffmpegCmd, $processId, $userId, $originalPath, $this->logger) extends Response {
			private string $ffmpegCmd;
			private string $processId;
			private string $userId;
			private string $originalPath;
			private ILogger $logger;
			private $process;

			public function __construct(string $ffmpegCmd, string $processId, string $userId, string $originalPath, ILogger $logger) {
				parent::__construct();
				$this->ffmpegCmd = $ffmpegCmd;
				$this->processId = $processId;
				$this->userId = $userId;
				$this->originalPath = $originalPath;
				$this->logger = $logger;
				
				// Set appropriate headers for MP4 streaming
				$this->addHeader('Content-Type', 'video/mp4');
				$this->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
				$this->addHeader('Pragma', 'no-cache');
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
		
		// Simplified command for debugging
		$cmd = [
			'ffmpeg',
			'-i', escapeshellarg($inputPath),
			'-vf', "scale=-2:{$height}",
			'-c:v', 'libx264',
			'-preset', 'ultrafast',
			'-c:a', 'aac',
			'-f', 'mp4',
			'-movflags', 'frag_keyframe+empty_moov',
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
