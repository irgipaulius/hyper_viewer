<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\ILogger;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

class TranscodeController extends Controller {
    private IRootFolder $rootFolder;
    private IUserSession $userSession;
    private ILogger $logger;
    private string $tempDir;

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
        $this->tempDir = '/tmp/hypertranscode';
        
        // Ensure temp directory exists
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function proxyTranscode(string $path): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
            }

            // Get the file from Nextcloud
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            $file = $userFolder->get($path);
            
            if (!$file->isReadable()) {
                return new JSONResponse(['error' => 'File not accessible'], Http::STATUS_FORBIDDEN);
            }

            // Generate unique ID for this file
            $fileId = md5($user->getUID() . ':' . $path . ':' . $file->getMTime());
            $tempFile = $this->tempDir . '/' . $fileId . '.mp4';

            // Check if already transcoded
            if (file_exists($tempFile)) {
                $this->logger->debug('Reusing cached transcode for: ' . $path, ['app' => 'hyper_viewer']);
                return new JSONResponse([
                    'url' => '/apps/hyper_viewer/api/proxy-stream?id=' . $fileId
                ]);
            }

            // Clean up old files (older than 2 hours)
            $this->cleanupOldFiles();

            // Get input file path
            $inputPath = $file->getStorage()->getLocalFile($file->getInternalPath());
            if (!$inputPath) {
                return new JSONResponse(['error' => 'Cannot access file locally'], Http::STATUS_INTERNAL_SERVER_ERROR);
            }

            // Start transcoding
            $this->logger->info('Starting 480p transcode for: ' . $path, ['app' => 'hyper_viewer']);
            
            $success = $this->startTranscode($inputPath, $tempFile);
            
            if (!$success) {
                return new JSONResponse(['error' => 'Transcoding failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
            }

            $this->logger->info('Completed 480p transcode for: ' . $path, ['app' => 'hyper_viewer']);

            return new JSONResponse([
                'url' => '/apps/hyper_viewer/api/proxy-stream?id=' . $fileId
            ]);

        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
        } catch (NotPermittedException $e) {
            return new JSONResponse(['error' => 'Permission denied'], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            $this->logger->error('Transcode error: ' . $e->getMessage(), ['app' => 'hyper_viewer']);
            return new JSONResponse(['error' => 'Internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function proxyStream(string $id): Response {
        $tempFile = $this->tempDir . '/' . $id . '.mp4';
        
        if (!file_exists($tempFile)) {
            $response = new Response();
            $response->setStatus(Http::STATUS_NOT_FOUND);
            return $response;
        }

        // Clean up old files
        $this->cleanupOldFiles();

        $fileSize = filesize($tempFile);
        $rangeHeader = $this->request->getHeader('Range');

        if ($rangeHeader) {
            // Handle range requests for seeking
            return $this->handleRangeRequest($tempFile, $fileSize, $rangeHeader);
        } else {
            // Serve entire file
            return $this->serveFile($tempFile, $fileSize);
        }
    }

    private function startTranscode(string $inputPath, string $outputPath): bool {
        // Escape shell arguments
        $input = escapeshellarg($inputPath);
        $output = escapeshellarg($outputPath);

        // FFmpeg command for 480p ultrafast transcoding
        $cmd = sprintf(
            'ffmpeg -y -threads 3 -i %s ' .
            '-vf "scale=-2:480:flags=fast_bilinear" ' .
            '-preset ultrafast -tune zerolatency ' .
            '-c:v libx264 -crf 30 ' .
            '-c:a aac -b:a 96k ' .
            '-movflags +faststart ' .
            '%s 2>&1',
            $input,
            $output
        );

        $this->logger->debug('FFmpeg command: ' . $cmd, ['app' => 'hyper_viewer']);

        // Execute synchronously
        $output_lines = [];
        $return_code = 0;
        exec($cmd, $output_lines, $return_code);

        if ($return_code !== 0) {
            $this->logger->error('FFmpeg failed with code ' . $return_code . ': ' . implode('\n', $output_lines), ['app' => 'hyper_viewer']);
            return false;
        }

        return file_exists($outputPath);
    }

    private function handleRangeRequest(string $filePath, int $fileSize, string $rangeHeader): StreamResponse {
        // Parse Range header (e.g., "bytes=0-1023")
        if (!preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $matches)) {
            $response = new StreamResponse($filePath);
            $response->setStatus(Http::STATUS_REQUESTED_RANGE_NOT_SATISFIABLE);
            return $response;
        }

        $start = (int)$matches[1];
        $end = $matches[2] !== '' ? (int)$matches[2] : $fileSize - 1;

        // Validate range
        if ($start >= $fileSize || $end >= $fileSize || $start > $end) {
            $response = new StreamResponse($filePath);
            $response->setStatus(Http::STATUS_REQUESTED_RANGE_NOT_SATISFIABLE);
            $response->addHeader('Content-Range', 'bytes */' . $fileSize);
            return $response;
        }

        $contentLength = $end - $start + 1;

        $response = new StreamResponse($filePath);
        $response->setStatus(Http::STATUS_PARTIAL_CONTENT);
        $response->addHeader('Content-Type', 'video/mp4');
        $response->addHeader('Accept-Ranges', 'bytes');
        $response->addHeader('Content-Length', (string)$contentLength);
        $response->addHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $fileSize);
        $response->addHeader('Cache-Control', 'no-cache');

        // Set callback to stream the range
        $response->setCallback(function() use ($filePath, $start, $contentLength) {
            $handle = fopen($filePath, 'rb');
            if ($handle) {
                fseek($handle, $start);
                $remaining = $contentLength;
                while ($remaining > 0 && !feof($handle)) {
                    $chunkSize = min(8192, $remaining);
                    $chunk = fread($handle, $chunkSize);
                    if ($chunk === false) break;
                    echo $chunk;
                    $remaining -= strlen($chunk);
                    flush();
                }
                fclose($handle);
            }
        });

        return $response;
    }

    private function serveFile(string $filePath, int $fileSize): StreamResponse {
        $response = new StreamResponse($filePath);
        $response->addHeader('Content-Type', 'video/mp4');
        $response->addHeader('Accept-Ranges', 'bytes');
        $response->addHeader('Content-Length', (string)$fileSize);
        $response->addHeader('Cache-Control', 'no-cache');
        return $response;
    }

    private function cleanupOldFiles(): void {
        $cutoff = time() - (2 * 3600); // 2 hours ago
        
        $files = glob($this->tempDir . '/*.mp4');
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $this->logger->debug('Cleaned up old transcode file: ' . basename($file), ['app' => 'hyper_viewer']);
            }
        }
    }
}
