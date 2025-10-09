<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;

class ClipController extends Controller {
    /** @var IRootFolder */
    private $rootFolder;
    /** @var IUserSession */
    private $userSession;

    public function __construct(string $appName, IRequest $request, IRootFolder $rootFolder, IUserSession $userSession) {
        parent::__construct($appName, $request);
        $this->rootFolder = $rootFolder;
        $this->userSession = $userSession;
    }

    /**
     * Export a video clip using lossless cutting
     * @NoAdminRequired
     */
    public function exportClip(): JSONResponse {
        try {
            $input = json_decode($this->request->getParams()['body'] ?? '{}', true);
            
            if (!$input) {
                $input = $this->request->getParams();
            }
            
            $originalPath = $input['originalPath'] ?? '';
            $startTime = floatval($input['startTime'] ?? 0);
            $endTime = floatval($input['endTime'] ?? 0);
            $exportPath = $input['exportPath'] ?? '';
            $clipFilename = $input['clipFilename'] ?? '';
            
            if (!$originalPath || !$exportPath || !$clipFilename) {
                return new JSONResponse(['error' => 'Missing required parameters'], 400);
            }
            
            if ($startTime >= $endTime) {
                return new JSONResponse(['error' => 'Invalid time range'], 400);
            }
            
            // Get user folder and validate original file exists
            $userFolder = $this->rootFolder->getUserFolder($this->userSession->getUser()->getUID());
            
            if (!$userFolder->nodeExists($originalPath)) {
                return new JSONResponse(['error' => 'Original video file not found: ' . $originalPath], 404);
            }
            
            $originalFile = $userFolder->get($originalPath);
            $originalLocalPath = $originalFile->getStorage()->getLocalFile($originalFile->getInternalPath());
            
            if (!$originalLocalPath || !file_exists($originalLocalPath)) {
                return new JSONResponse(['error' => 'Cannot access original video file'], 500);
            }
            
            // Normalize export directory path (resolve .. properly)
            $videoDir = dirname($originalPath);
            $exportDir = $this->normalizePath($videoDir . '/' . $exportPath);
            
            // Create export directory if it doesn't exist
            try {
                if (!$userFolder->nodeExists($exportDir)) {
                    // Create directory recursively
                    $this->createDirectoryRecursive($userFolder, $exportDir);
                }
            } catch (\Exception $e) {
                return new JSONResponse(['error' => 'Failed to create export directory: ' . $e->getMessage()], 500);
            }
            
            $exportFolder = $userFolder->get($exportDir);
            $exportLocalPath = $exportFolder->getStorage()->getLocalFile($exportFolder->getInternalPath());
            
            if (!$exportLocalPath) {
                return new JSONResponse(['error' => 'Cannot access export directory'], 500);
            }
            
            $outputFile = $exportLocalPath . '/' . $clipFilename;
            
            // Start lossless clip export in background
            $logFile = $this->startClipExport($originalLocalPath, $outputFile, $startTime, $endTime, $exportDir);
            
            return new JSONResponse([
                'success' => true,
                'message' => 'Clip export started',
                'clipFilename' => $clipFilename,
                'exportPath' => $exportDir,
                'outputFile' => $outputFile,
                'logFile' => $logFile
            ]);
            
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Export failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Normalize a path by resolving .. and . components
     */
    private function normalizePath(string $path): string {
        // Remove duplicate slashes and split into components
        $path = preg_replace('#/+#', '/', $path);
        $parts = explode('/', $path);
        $normalized = [];
        
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($normalized);
            } else {
                $normalized[] = $part;
            }
        }
        
        return '/' . implode('/', $normalized);
    }

    /**
     * Create a directory recursively
     */
    private function createDirectoryRecursive($userFolder, string $path): void {
        $parts = explode('/', trim($path, '/'));
        $currentPath = '';
        
        foreach ($parts as $part) {
            $currentPath .= '/' . $part;
            if (!$userFolder->nodeExists($currentPath)) {
                $userFolder->newFolder($currentPath);
            }
        }
    }

    private function startClipExport(string $inputPath, string $outputPath, float $startTime, float $endTime, string $exportDir): string {
        // Create hidden log file next to the output file
        $outputDir = dirname($outputPath);
        $outputFilename = basename($outputPath);
        $logFile = $outputDir . '/.' . $outputFilename . '.log';
        
        // FFmpeg command for lossless cutting
        // Using stream copy (-c copy) for lossless operation
        $duration = $endTime - $startTime;
        
        // Get Nextcloud base path for occ command
        // __DIR__ is /lib/Controller, so we need to go up 4 levels to reach Nextcloud root
        $ncBasePath = dirname(dirname(dirname(dirname(__DIR__))));
        $occPath = $ncBasePath . '/occ';
        $userId = $this->userSession->getUser()->getUID();
        // Scan path should be relative to user's files directory (no username prefix)
        $scanPath = $exportDir;
        
        // Build FFmpeg command
        $ffmpegCmd = sprintf(
            '/usr/local/bin/ffmpeg -y -ss %f -i %s -t %f -c copy -avoid_negative_ts make_zero %s',
            $startTime,
            escapeshellarg($inputPath),
            $duration,
            escapeshellarg($outputPath)
        );
        
        // Build scan command (use full PHP path for FreeBSD)
        // occ files:scan expects path in format: username/files/relative/path
        $scanCmd = sprintf(
            '/usr/local/bin/php %s files:scan --path=%s',
            escapeshellarg($occPath),
            escapeshellarg($userId . '/files' . $scanPath)
        );
        
        // Chain commands in background (use sh for FreeBSD compatibility)
        $fullCmd = sprintf(
            'nohup sh -c %s > %s 2>&1 &',
            escapeshellarg($ffmpegCmd . ' && ' . $scanCmd),
            escapeshellarg($logFile)
        );

        // Execute the command
        exec($fullCmd, $output, $returnCode);
        
        return $logFile;
    }
}
