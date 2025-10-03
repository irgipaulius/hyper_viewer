<?php

declare(strict_types=1);

namespace OCA\HyperViewer\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\IConfig;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\Utility\ITimeFactory;

class AutoHlsGenerationJob extends TimedJob {

	private IRootFolder $rootFolder;
	private IUserManager $userManager;
	private IConfig $config;
	private IJobList $jobList;
	private LoggerInterface $logger;

	public function __construct(
		ITimeFactory $timeFactory,
		IRootFolder $rootFolder,
		IUserManager $userManager,
		IConfig $config,
		IJobList $jobList,
		LoggerInterface $logger
	) {
		parent::__construct($timeFactory);
		$this->rootFolder = $rootFolder;
		$this->userManager = $userManager;
		$this->config = $config;
		$this->jobList = $jobList;
		$this->logger = $logger;

		$this->setInterval(60 * 10);
	}

	protected function run($argument): void {
		$this->logger->info('🤖 Auto HLS generation job started');

		try {
			// Get all registered auto-generation directories
			$autoGenDirs = $this->getAutoGenerationDirectories();
			
			if (empty($autoGenDirs)) {
				$this->logger->debug('No auto-generation directories registered');
				return;
			}

			$this->logger->info('Processing auto-generation directories', ['count' => count($autoGenDirs)]);

			foreach ($autoGenDirs as $configKey => $settings) {
				$this->processAutoGenerationDirectory($configKey, $settings);
			}

			$this->logger->info('Auto HLS generation job completed');

		} catch (\Exception $e) {
			$this->logger->error('Auto HLS generation job failed', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
		}
	}

	/**
	 * Get all registered auto-generation directories
	 */
	private function getAutoGenerationDirectories(): array {
		$autoGenDirs = [];
		$allAppValues = $this->config->getAppKeys('hyper_viewer');

		foreach ($allAppValues as $key) {
			if (str_starts_with($key, 'auto_gen_')) {
				$settingsJson = $this->config->getAppValue('hyper_viewer', $key, '');
				if (!empty($settingsJson)) {
					$settings = json_decode($settingsJson, true);
					if ($settings && isset($settings['enabled']) && $settings['enabled']) {
						$autoGenDirs[$key] = $settings;
					}
				}
			}
		}

		return $autoGenDirs;
	}

	/**
	 * Process a single auto-generation directory
	 */
	private function processAutoGenerationDirectory(string $configKey, array $settings): void {
		$userId = $settings['userId'] ?? '';
		$directory = $settings['directory'] ?? '';

		if (empty($userId) || empty($directory)) {
			$this->logger->warning('Invalid auto-generation settings', ['configKey' => $configKey]);
			return;
		}

		$user = $this->userManager->get($userId);
		if (!$user) {
			$this->logger->warning('User not found for auto-generation', ['userId' => $userId]);
			return;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			
			// Check if directory still exists
			if (!$userFolder->nodeExists($directory)) {
				$this->logger->info('Auto-generation directory no longer exists, disabling', [
					'directory' => $directory,
					'userId' => $userId
				]);
				$this->disableAutoGeneration($configKey);
				return;
			}

			// Scan for new video files that don't have HLS cache
			$newVideoFiles = $this->findNewVideoFiles($userFolder, $directory, $settings);

			if (empty($newVideoFiles)) {
				$this->logger->debug('No new video files found', ['directory' => $directory]);
				return;
			}

			$this->logger->info('Found new video files for auto-generation', [
				'directory' => $directory,
				'userId' => $userId,
				'newFiles' => count($newVideoFiles)
			]);

			// Generate HLS cache for new files
			$this->queueHlsGenerationJobs($newVideoFiles, $settings);

		} catch (\Exception $e) {
			$this->logger->error('Failed to process auto-generation directory', [
				'directory' => $directory,
				'userId' => $userId,
				'error' => $e->getMessage()
			]);
		}
	}

	/**
	 * Find video files that don't have HLS cache yet
	 */
	private function findNewVideoFiles($userFolder, string $directory, array $settings): array {
		$newFiles = [];
		$supportedMimes = ['video/quicktime', 'video/mp4'];

		try {
			$dirNode = $userFolder->get($directory);
			if (!($dirNode instanceof \OCP\Files\Folder)) {
				return [];
			}

			$this->scanForNewVideos($dirNode, $directory, $supportedMimes, $userFolder, $newFiles);

		} catch (\Exception $e) {
			$this->logger->error('Failed to scan for new videos', [
				'directory' => $directory,
				'error' => $e->getMessage()
			]);
		}

		return $newFiles;
	}

	/**
	 * Recursively scan for new video files without HLS cache
	 */
	private function scanForNewVideos($folder, string $basePath, array $supportedMimes, $userFolder, array &$newFiles): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof \OCP\Files\File) {
				$mimeType = $node->getMimeType();
				if (in_array($mimeType, $supportedMimes)) {
					// Check if HLS cache already exists
					if (!$this->hasHlsCache($userFolder, $node->getName(), $basePath)) {
						$relativePath = $basePath === '/' ? '/' : $basePath;
						$newFiles[] = [
							'filename' => $node->getName(),
							'directory' => $relativePath,
							'size' => $node->getSize(),
							'mimeType' => $mimeType,
							'modifiedTime' => $node->getMTime()
						];
					}
				}
			} elseif ($node instanceof \OCP\Files\Folder) {
				// Skip hidden directories and cache directories
				$folderName = $node->getName();
				if (!str_starts_with($folderName, '.')) {
					$subPath = $basePath === '/' ? '/' . $folderName : $basePath . '/' . $folderName;
					$this->scanForNewVideos($node, $subPath, $supportedMimes, $userFolder, $newFiles);
				}
			}
		}
	}

	/**
	 * Check if HLS cache exists for a video file
	 */
	private function hasHlsCache($userFolder, string $filename, string $directory): bool {
		$baseFilename = pathinfo($filename, PATHINFO_FILENAME);
		
		// Check cache locations in order of preference
		$cacheLocations = [
			// Relative to video file
			$directory . '/.cached_hls/' . $baseFilename,
			// User home directory
			'/.cached_hls/' . $baseFilename,
		];

		foreach ($cacheLocations as $cachePath) {
			try {
				// Check for adaptive streaming master playlist first, fallback to single playlist
				if ($userFolder->nodeExists($cachePath . '/master.m3u8') || 
					$userFolder->nodeExists($cachePath . '/playlist.m3u8')) {
					return true;
				}
			} catch (\Exception $e) {
				// Continue checking other locations
				continue;
			}
		}

		return false;
	}

	/**
	 * Queue HLS generation jobs for new video files
	 */
	private function queueHlsGenerationJobs(array $videoFiles, array $settings): void {
		$jobId = uniqid('auto_hls_', true);

		foreach ($videoFiles as $fileData) {
			$jobData = [
				'jobId' => $jobId,
				'userId' => $settings['userId'],
				'filename' => $fileData['filename'],
				'directory' => $fileData['directory'],
				'cacheLocation' => $settings['cacheLocation'] ?? 'relative',
				'customPath' => $settings['customPath'] ?? '',
				'overwriteExisting' => $settings['overwriteExisting'] ?? false,
				'resolutions' => $settings['resolutions'] ?? ['720p', '480p', '240p'],
				'autoGenerated' => true
			];

			$this->logger->info('Queuing auto-generation HLS job', [
				'jobId' => $jobId,
				'filename' => $fileData['filename'],
				'directory' => $fileData['directory']
			]);

			$this->jobList->add(HlsCacheGenerationJob::class, $jobData);
		}
	}

	/**
	 * Disable auto-generation for a directory (e.g., if directory no longer exists)
	 */
	private function disableAutoGeneration(string $configKey): void {
		$settingsJson = $this->config->getAppValue('hyper_viewer', $configKey, '');
		if (!empty($settingsJson)) {
			$settings = json_decode($settingsJson, true);
			if ($settings) {
				$settings['enabled'] = false;
				$settings['disabledAt'] = time();
				$this->config->setAppValue('hyper_viewer', $configKey, json_encode($settings));
			}
		}
	}
}
