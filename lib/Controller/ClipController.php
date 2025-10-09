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
                return new JSONResponse(['error' => 'Original video file not found'], 404);
            }
            
            $originalFile = $userFolder->get($originalPath);
            $originalLocalPath = $originalFile->getStorage()->getLocalFile($originalFile->getInternalPath());
            
            if (!$originalLocalPath || !file_exists($originalLocalPath)) {
                return new JSONResponse(['error' => 'Cannot access original video file'], 500);
            }
            
            // Create export directory if it doesn't exist
            $exportDir = dirname($originalPath) . '/' . $exportPath;
            if (!$userFolder->nodeExists($exportDir)) {
                $userFolder->newFolder($exportDir);
            }
            
            $exportFolder = $userFolder->get($exportDir);
            $exportLocalPath = $exportFolder->getStorage()->getLocalFile($exportFolder->getInternalPath());
            
            if (!$exportLocalPath) {
                return new JSONResponse(['error' => 'Cannot access export directory'], 500);
            }
            
            $outputFile = $exportLocalPath . '/' . $clipFilename;
            
            // Start lossless clip export in background
            $this->startClipExport($originalLocalPath, $outputFile, $startTime, $endTime);
            
            return new JSONResponse([
                'success' => true,
                'message' => 'Clip export started',
                'clipFilename' => $clipFilename,
                'exportPath' => $exportPath,
                'outputFile' => $outputFile
            ]);
            
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function startClipExport(string $inputPath, string $outputPath, float $startTime, float $endTime): void {
        // Create unique log file for this export
        $logId = uniqid();
        $tempDir = sys_get_temp_dir();
        $logFile = $tempDir . '/hyper_viewer_export_' . $logId . '.log';
        
        // FFmpeg command for lossless cutting
        // Using stream copy (-c copy) for lossless operation
        $duration = $endTime - $startTime;
        
        $cmd = sprintf(
            'nohup /usr/local/bin/ffmpeg -y -ss %f -i %s -t %f -c copy -avoid_negative_ts make_zero %s > %s 2>&1 &',
            $startTime,
            escapeshellarg($inputPath),
            $duration,
            escapeshellarg($outputPath),
            escapeshellarg($logFile)
        );

        // Execute the command
        exec($cmd, $output, $returnCode);
    }
}
