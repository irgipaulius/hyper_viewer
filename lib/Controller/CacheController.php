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

		if (empty($files)) {
			return new JSONResponse(['error' => 'No files provided'], 400);
		}

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
	 * Check cache generation progress
	 * 
	 * @NoAdminRequired
	 */
	public function getProgress(): JSONResponse {
		$jobId = $this->request->getParam('jobId');
		
		if (!$jobId) {
			return new JSONResponse(['error' => 'Job ID required'], 400);
		}

		// For now, simulate completion after some time
		// In a real implementation, you'd check job status from database
		$this->logger->info('Progress check requested', ['jobId' => $jobId]);
		
		// Simulate job completion after 30 seconds
		static $jobStartTimes = [];
		if (!isset($jobStartTimes[$jobId])) {
			$jobStartTimes[$jobId] = time();
		}
		
		$elapsed = time() - $jobStartTimes[$jobId];
		
		if ($elapsed < 10) {
			return new JSONResponse([
				'jobId' => $jobId,
				'progress' => 25,
				'status' => 'processing',
				'message' => 'Starting FFmpeg processing...'
			]);
		} elseif ($elapsed < 30) {
			return new JSONResponse([
				'jobId' => $jobId,
				'progress' => 75,
				'status' => 'processing',
				'message' => 'Generating HLS segments...'
			]);
		} else {
			// Assume completed after 30 seconds
			return new JSONResponse([
				'jobId' => $jobId,
				'progress' => 100,
				'status' => 'completed',
				'message' => 'HLS cache generation completed!'
			]);
		}
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
