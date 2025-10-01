<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCP\BackgroundJob\IJobList;
use OCA\HyperViewer\BackgroundJob\HlsCacheGenerationJob;
use Psr\Log\LoggerInterface;

class CacheController extends Controller {
	
	private IRootFolder $rootFolder;
	private IUserSession $userSession;
	private IJobList $jobList;
	private LoggerInterface $logger;

	public function __construct(
		string $appName,
		IRequest $request,
		IRootFolder $rootFolder,
		IUserSession $userSession,
		IJobList $jobList,
		LoggerInterface $logger
	) {
		parent::__construct($appName, $request);
		$this->rootFolder = $rootFolder;
		$this->userSession = $userSession;
		$this->jobList = $jobList;
		$this->logger = $logger;
	}

	/**
	 * Generate HLS cache for video files
	 * 
	 * @NoAdminRequired
	 */
	public function generateCache(): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'User not authenticated'], 401);
		}

		$files = $this->request->getParam('files', []);
		$cacheLocation = $this->request->getParam('cacheLocation', 'relative');
		$customPath = $this->request->getParam('customPath', '');
		$overwriteExisting = $this->request->getParam('overwriteExisting', false);
		$resolutions = $this->request->getParam('resolutions', ['720p', '480p', '240p']);

		$this->logger->info('HLS cache generation requested', [
			'user' => $user->getUID(),
			'files' => count($files),
			'cacheLocation' => $cacheLocation,
			'resolutions' => $resolutions
		]);

		$jobId = uniqid('hls_cache_', true);
		
		// Add background job for each file
		foreach ($files as $fileData) {
			$jobData = [
				'jobId' => $jobId,
				'userId' => $user->getUID(),
				'filename' => $fileData['filename'],
				'directory' => $fileData['directory'] ?? '/',
				'cacheLocation' => $cacheLocation,
				'customPath' => $customPath,
				'overwriteExisting' => $overwriteExisting,
				'resolutions' => $resolutions
			];
			
			$this->logger->info('Adding HLS cache generation job to queue', [
				'jobId' => $jobId,
				'jobData' => $jobData
			]);
			
			$this->jobList->add(HlsCacheGenerationJob::class, $jobData);
		}
		return new JSONResponse([
			'success' => true,
			'jobId' => $jobId,
			'message' => 'HLS cache generation started',
			'filesCount' => count($files)
		]);
	}


	/**
	 * Get real-time progress for HLS generation
	 * 
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getProgress(string $cachePath): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'User not authenticated'], 401);
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			$decodedCachePath = urldecode($cachePath);
			
			// Check if cache directory exists
			if (!$userFolder->nodeExists($decodedCachePath)) {
				return new JSONResponse(['error' => 'Cache path not found'], 404);
			}

			$cacheFolder = $userFolder->get($decodedCachePath);
			if (!($cacheFolder instanceof \OCP\Files\Folder)) {
				return new JSONResponse(['error' => 'Invalid cache path'], 400);
			}

			// Look for progress.json file
			$progressFile = $decodedCachePath . '/progress.json';
			$logFile = $decodedCachePath . '/generation.log';

			$progressData = ['status' => 'not_found', 'progress' => 0];

			if ($userFolder->nodeExists($progressFile)) {
				$progressNode = $userFolder->get($progressFile);
				$progressContent = $progressNode->getContent();
				$progressData = json_decode($progressContent, true) ?: $progressData;
			}

			// Parse latest log entries for real-time progress if log exists
			if ($userFolder->nodeExists($logFile)) {
				$logNode = $userFolder->get($logFile);
				$logContent = $logNode->getContent();
				$parsedProgress = $this->parseFFmpegProgress($logContent);
				
				// Merge parsed progress with existing data
				$progressData = array_merge($progressData, $parsedProgress);
				$progressData['status'] = $progressData['completed'] ? 'completed' : 'processing';
			}

			return new JSONResponse([
				'success' => true,
				'progress' => $progressData
			]);

		} catch (\Exception $e) {
			$this->logger->error('Failed to get progress', [
				'cachePath' => $cachePath,
				'error' => $e->getMessage()
			]);
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * Parse FFmpeg progress output from generation.log
	 */
	private function parseFFmpegProgress(string $logContent): array {
		$lines = explode("\n", $logContent);
		$progress = [
			'frame' => 0,
			'fps' => 0,
			'speed' => '0x',
			'time' => '00:00:00',
			'bitrate' => 'N/A',
			'size' => '0kB',
			'completed' => false,
			'lastUpdate' => time()
		];

		// Look for the latest progress line with frame info
		for ($i = count($lines) - 1; $i >= 0; $i--) {
			$line = trim($lines[$i]);
			
			// Check for completion first
			if (strpos($line, 'muxing overhead') !== false || 
				strpos($line, 'kb/s:') !== false) {
				$progress['completed'] = true;
				$progress['progress'] = 100;
				break;
			}
			
			// Parse progress lines like: frame=  847 fps= 24 q=-1.0 Lq=-1.0 q=-1.0 q=-1.0 q=-1.0 size=N/A time=00:00:35.30 bitrate=N/A speed=0.987x
			if (preg_match('/frame=\s*(\d+)/', $line, $frameMatch) && 
				preg_match('/fps=\s*([\d.]+)/', $line, $fpsMatch) &&
				preg_match('/time=(\d{2}:\d{2}:\d{2}\.\d+)/', $line, $timeMatch) &&
				preg_match('/speed=\s*([\d.]+x)/', $line, $speedMatch)) {
				
				$progress['frame'] = (int)$frameMatch[1];
				$progress['fps'] = (float)$fpsMatch[1];
				$progress['speed'] = $speedMatch[1];
				$progress['time'] = substr($timeMatch[1], 0, 8); // Trim to HH:MM:SS
				
				// Parse bitrate if available (not always N/A)
				if (preg_match('/bitrate=\s*([\d.]+kbits\/s)/', $line, $bitrateMatch)) {
					$progress['bitrate'] = $bitrateMatch[1];
				}
				
				// Parse size if available (not always N/A)
				if (preg_match('/size=\s*(\d+kB)/', $line, $sizeMatch)) {
					$progress['size'] = $sizeMatch[1];
				}
				
				break;
			}
		}

		return $progress;
	}

	/**
	 * Check if HLS cache exists for a video file
	 * 
	 * @NoAdminRequired
	 */
	public function checkCache(): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'User not authenticated'], 401);
		}

		$filename = $this->request->getParam('filename');
		$directory = $this->request->getParam('directory', '/');

		if (!$filename) {
			return new JSONResponse(['error' => 'Filename required'], 400);
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$cacheExists = $this->findHlsCache($userFolder, $filename, $directory);

		return new JSONResponse([
			'exists' => $cacheExists !== null,
			'cachePath' => $cacheExists,
			'filename' => $filename
		]);
	}

	/**
	 * Find HLS cache for a video file
	 */
	private function findHlsCache($userFolder, string $filename, string $directory): ?string {
		$baseFilename = pathinfo($filename, PATHINFO_FILENAME);
		
		// Check cache locations in order of preference
		$cacheLocations = [
			// Relative to video file
			$directory . '/.cached_hls/' . $baseFilename,
			// User home directory
			'/.cached_hls/' . $baseFilename,
			// TODO: Add custom mount points from user settings
		];

		foreach ($cacheLocations as $cachePath) {
			try {
				// Check for adaptive streaming master playlist first, fallback to single playlist
				if ($userFolder->nodeExists($cachePath . '/master.m3u8')) {
					$this->logger->debug('Found adaptive HLS cache', ['path' => $cachePath]);
					return $cachePath;
				} elseif ($userFolder->nodeExists($cachePath . '/playlist.m3u8')) {
					$this->logger->debug('Found legacy HLS cache', ['path' => $cachePath]);
					return $cachePath;
				}
			} catch (\Exception $e) {
				// Continue checking other locations
				continue;
			}
		}

		return null;
	}

	/**
	 * Discover video files recursively in a directory
	 * 
	 * @NoAdminRequired
	 */
	public function discoverVideos(): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'User not authenticated'], 401);
		}

		$directory = $this->request->getParam('directory');
		if (!$directory) {
			return new JSONResponse(['error' => 'Directory path required'], 400);
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			$videoFiles = $this->scanDirectoryForVideos($userFolder, $directory);

			$this->logger->info('Video discovery completed', [
				'directory' => $directory,
				'filesFound' => count($videoFiles)
			]);

			return new JSONResponse([
				'success' => true,
				'files' => $videoFiles,
				'directory' => $directory
			]);

		} catch (\Exception $e) {
			$this->logger->error('Video discovery failed', [
				'directory' => $directory,
				'error' => $e->getMessage()
			]);
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * Register directory for automatic HLS generation
	 * 
	 * @NoAdminRequired
	 */
	public function registerAutoGeneration(): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'User not authenticated'], 401);
		}

		$directory = $this->request->getParam('directory');
		$options = $this->request->getParam('options', []);

		if (!$directory) {
			return new JSONResponse(['error' => 'Directory path required'], 400);
		}

		try {
			// Store auto-generation settings in app config
			$autoGenSettings = [
				'userId' => $user->getUID(),
				'directory' => $directory,
				'cacheLocation' => $options['cacheLocation'] ?? 'relative',
				'customPath' => $options['customPath'] ?? '',
				'overwriteExisting' => $options['overwriteExisting'] ?? false,
				'resolutions' => $options['resolutions'] ?? ['720p', '480p', '240p'],
				'enabled' => true,
				'createdAt' => time()
			];

			// Use a simple key-value storage for now (could be moved to database later)
			$configKey = 'auto_gen_' . md5($user->getUID() . '_' . $directory);
			\OC::$server->getConfig()->setAppValue('hyper_viewer', $configKey, json_encode($autoGenSettings));

			$this->logger->info('Directory registered for auto-generation', [
				'userId' => $user->getUID(),
				'directory' => $directory,
				'options' => $options
			]);

			return new JSONResponse([
				'success' => true,
				'message' => 'Directory registered for auto-generation',
				'directory' => $directory
			]);

		} catch (\Exception $e) {
			$this->logger->error('Auto-generation registration failed', [
				'directory' => $directory,
				'error' => $e->getMessage()
			]);
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * Recursively scan directory for video files
	 */
	private function scanDirectoryForVideos($userFolder, string $directoryPath): array {
		$videoFiles = [];
		$supportedMimes = ['video/quicktime', 'video/mp4'];

		try {
			if (!$userFolder->nodeExists($directoryPath)) {
				throw new \Exception("Directory not found: $directoryPath");
			}

			$directory = $userFolder->get($directoryPath);
			if (!($directory instanceof \OCP\Files\Folder)) {
				throw new \Exception("Path is not a directory: $directoryPath");
			}

			$this->scanFolderRecursively($directory, $directoryPath, $supportedMimes, $videoFiles);

		} catch (\Exception $e) {
			$this->logger->error('Directory scanning failed', [
				'directory' => $directoryPath,
				'error' => $e->getMessage()
			]);
			throw $e;
		}

		return $videoFiles;
	}

	/**
	 * Recursively scan folder for video files
	 */
	private function scanFolderRecursively($folder, string $basePath, array $supportedMimes, array &$videoFiles): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof \OCP\Files\File) {
				$mimeType = $node->getMimeType();
				if (in_array($mimeType, $supportedMimes)) {
					$relativePath = $basePath === '/' ? '/' : $basePath;
					$videoFiles[] = [
						'filename' => $node->getName(),
						'directory' => $relativePath,
						'size' => $node->getSize(),
						'mimeType' => $mimeType,
						'fullPath' => $relativePath . '/' . $node->getName()
					];
				}
			} elseif ($node instanceof \OCP\Files\Folder) {
				// Skip hidden directories and cache directories
				$folderName = $node->getName();
				if (!str_starts_with($folderName, '.')) {
					$subPath = $basePath === '/' ? '/' . $folderName : $basePath . '/' . $folderName;
					$this->scanFolderRecursively($node, $subPath, $supportedMimes, $videoFiles);
				}
			}
		}
	}

	/**
	 * Serve HLS files (playlist.m3u8 and segments)
	 * 
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function serveHlsFile(string $cachePath, string $filename): Response {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new Response('Unauthorized', 401);
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			
			// Decode the cache path (it might be URL encoded)
			$decodedCachePath = urldecode($cachePath);
			$decodedFilename = urldecode($filename);
			
			// Construct the full path to the HLS file
			$fullPath = $decodedCachePath . '/' . $decodedFilename;
			
			$this->logger->debug('Serving HLS file', [
				'cachePath' => $decodedCachePath,
				'filename' => $decodedFilename,
				'fullPath' => $fullPath
			]);

			// Check if the file exists
			if (!$userFolder->nodeExists($fullPath)) {
				$this->logger->warning('HLS file not found', ['path' => $fullPath]);
				return new Response('File not found', 404);
			}

			$file = $userFolder->get($fullPath);
			
			if (!$file instanceof \OCP\Files\File) {
				return new Response('Not a file', 400);
			}

			// Determine content type based on file extension
			$contentType = 'application/octet-stream';
			$extension = pathinfo($decodedFilename, PATHINFO_EXTENSION);
			
			switch (strtolower($extension)) {
				case 'm3u8':
					$contentType = 'application/vnd.apple.mpegurl';
					break;
				case 'ts':
					$contentType = 'video/mp2t';
					break;
				case 'mp4':
					$contentType = 'video/mp4';
					break;
			}

			// Create stream response
			$response = new StreamResponse($file->fopen('r'));
			$response->addHeader('Content-Type', $contentType);
			$response->addHeader('Content-Length', (string)$file->getSize());
			
			// Add CORS headers for HLS playback
			$response->addHeader('Access-Control-Allow-Origin', '*');
			$response->addHeader('Access-Control-Allow-Methods', 'GET');
			$response->addHeader('Access-Control-Allow-Headers', 'Range');
			
			// Add caching headers for segments
			if ($extension === 'ts' || $extension === 'mp4') {
				$response->addHeader('Cache-Control', 'public, max-age=31536000'); // 1 year
			} else {
				$response->addHeader('Cache-Control', 'public, max-age=300'); // 5 minutes for playlists
			}

			return $response;

		} catch (\Exception $e) {
			$this->logger->error('Error serving HLS file', [
				'error' => $e->getMessage(),
				'cachePath' => $cachePath,
				'filename' => $filename
			]);
			return new Response('Internal server error', 500);
		}
	}
}
