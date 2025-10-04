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
			'-maxrate', '2400k',
			'-bufsize', '4800k',
			'-c:a', 'aac',
			'-b:a', '128k',
			'-movflags', '+faststart',
			'-f', 'mp4',
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

	/**
	 * Get height from resolution string
	 */
	private function getHeightFromResolution(string $resolution): int {
		switch ($resolution) {
			case '1080p': return 1080;
			case '720p': return 720;
			case '480p': return 480;
			case '360p': return 360;
			case '240p': return 240;
			default: return 720;
		}
	}

	/**
	 * Get process status
	 * 
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getProcessStatus(): JSONResponse {
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

	/**
	 * Constants for process management
	 */
	private const MAX_PROCESSES_PER_USER = 3;
	private const PROCESS_TIMEOUT = 1800; // 30 minutes

	/**
	 * Clean up stale processes
	 */
	private function cleanupStaleProcesses(): void {
		// Implementation for cleaning up stale processes
	}

	/**
	 * Get active process count for user
	 */
	private function getUserActiveProcessCount(string $userId): int {
		// Implementation for getting active process count
		return 0;
	}
}
