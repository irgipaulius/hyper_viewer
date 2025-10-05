<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\ILogger;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

class TranscodeController extends Controller {
    /** @var IRootFolder */
    private $rootFolder;
    /** @var IUserSession */
    private $userSession;
    /** @var ILogger */
    private $logger;
    /** @var string */
    private $tempDir;

    public function __construct(
        $appName,
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
     */
    public function proxyTranscode($path, $force = false) {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse([
                    'error' => 'User not authenticated'
                ], Http::STATUS_UNAUTHORIZED);
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

            // Check if already transcoded (unless force is true)
            if (!$force && file_exists($tempFile)) {
                $this->logger->debug('Reusing cached transcode for: ' . $path, ['app' => 'hyper_viewer']);
                return new JSONResponse([
                    'url' => '/apps/hyper_viewer/api/proxy-stream?id=' . $fileId,
                    'debug' => [
                        'fileSize' => filesize($tempFile),
                        'tempFile' => basename($tempFile),
                        'cacheHit' => true
                    ]
                ]);
            }
            
            // If forcing, delete existing file
            if ($force && file_exists($tempFile)) {
                unlink($tempFile);
                $this->logger->debug('Deleted existing transcode due to force flag: ' . $path, ['app' => 'hyper_viewer']);
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
            
            $transcodeResult = $this->startTranscode($inputPath, $tempFile);
            
            if (!$transcodeResult['success']) {
                return new JSONResponse([
                    'error' => 'Transcoding failed',
                    'debug' => $transcodeResult
                ], Http::STATUS_INTERNAL_SERVER_ERROR);
            }

            $this->logger->info('Completed 480p transcode for: ' . $path, ['app' => 'hyper_viewer']);

            return new JSONResponse([
                'url' => '/apps/hyper_viewer/api/proxy-stream?id=' . $fileId,
                'debug' => [
                    'backgroundTranscode' => true,
                    'tempFile' => basename($tempFile),
                    'cacheHit' => false,
                    'logFile' => $transcodeResult['logFile'],
                    'tempDir' => $this->tempDir
                ]
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
    public function proxyStream($id) {
        try {
            $tempFile = $this->tempDir . '/' . $id . '.mp4';
            
            // Wait for FFmpeg to create the file (up to 30 seconds)
            $maxWaitTime = 30;
            $waitInterval = 0.5; // 500ms
            $waited = 0;
            
            while (!file_exists($tempFile) && $waited < $maxWaitTime) {
                usleep($waitInterval * 1000000); // Convert to microseconds
                $waited += $waitInterval;
            }
            
            if (!file_exists($tempFile)) {
                $this->logger->error('Temp file not created after waiting: ' . $tempFile, ['app' => 'hyper_viewer']);
                $response = new Response('File not found after waiting: ' . $id . ' (waited ' . $waited . 's)');
                $response->setStatus(Http::STATUS_NOT_FOUND);
                $response->addHeader('Content-Type', 'text/plain');
                return $response;
            }
            
            // Wait for file to have some content (at least 1KB)
            $minSize = 1024;
            $maxContentWait = 10;
            $contentWaited = 0;
            
            while (filesize($tempFile) < $minSize && $contentWaited < $maxContentWait) {
                usleep($waitInterval * 1000000);
                $contentWaited += $waitInterval;
            }
            
            $this->logger->debug('Streaming file after waiting: ' . $waited . 's for creation, ' . $contentWaited . 's for content. Size: ' . filesize($tempFile) . ' bytes', ['app' => 'hyper_viewer']);

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
        } catch (\Exception $e) {
            $response = new Response('Stream error: ' . $e->getMessage());
            $response->setStatus(Http::STATUS_INTERNAL_SERVER_ERROR);
            $response->addHeader('Content-Type', 'text/plain');
            return $response;
        }
    }

    private function startTranscode($inputPath, $outputPath) {
        // Create unique log file for this transcode
        $logId = uniqid();
        $logFile = '/tmp/hyper_ffmpeg_' . $logId . '.log';
        
        // FFmpeg command for 480p ultrafast transcoding with web-compatible settings
        // Background process that writes directly to output file
        $cmd = sprintf(
            'nohup /usr/local/bin/ffmpeg -y -threads 3 -i %s ' .
            '-vf "scale=-2:480:flags=fast_bilinear" ' .
            '-c:v libx264 -preset ultrafast -tune zerolatency ' .
            '-profile:v baseline -level 3.0 -pix_fmt yuv420p ' .
            '-crf 28 -maxrate 1200k -bufsize 2400k ' .
            '-c:a aac -b:a 128k -ar 44100 ' .
            '-movflags +faststart+frag_keyframe+empty_moov ' .
            '-f mp4 %s > %s 2>&1 &',
            escapeshellarg($inputPath),
            escapeshellarg($outputPath),
            escapeshellarg($logFile)
        );

        $this->logger->debug('Starting background FFmpeg: ' . $cmd, ['app' => 'hyper_viewer']);

        // Fire and forget - start FFmpeg in background
        exec($cmd);
        
        // Return immediately - don't wait for completion
        return [
            'success' => true,
            'running' => true,
            'backgroundProcess' => true,
            'logFile' => $logFile,
            'cmd' => $cmd,
            'outputPath' => $outputPath
        ];
    }

    private function handleRangeRequest(string $filePath, int $fileSize, string $rangeHeader): Response {
        try {
            // Parse Range header (e.g., "bytes=0-1023" or "bytes=0-")
            if (!preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $matches)) {
                $response = new Response('Invalid range header: ' . $rangeHeader);
                $response->setStatus(Http::STATUS_REQUESTED_RANGE_NOT_SATISFIABLE);
                $response->addHeader('Content-Type', 'text/plain');
                return $response;
            }

            $start = (int)$matches[1];
            $end = $matches[2] !== '' ? (int)$matches[2] : $fileSize - 1;

            // Validate range
            if ($start >= $fileSize || $end >= $fileSize || $start > $end) {
                $response = new Response('Invalid range: ' . $start . '-' . $end . ' for file size ' . $fileSize);
                $response->setStatus(Http::STATUS_REQUESTED_RANGE_NOT_SATISFIABLE);
                $response->addHeader('Content-Range', 'bytes */' . $fileSize);
                $response->addHeader('Content-Type', 'text/plain');
                return $response;
            }

            $contentLength = $end - $start + 1;

            // Stream the range directly using native PHP headers
            $handle = fopen($filePath, 'rb');
            if (!$handle) {
                $response = new Response('Cannot open file: ' . $filePath);
                $response->setStatus(Http::STATUS_INTERNAL_SERVER_ERROR);
                $response->addHeader('Content-Type', 'text/plain');
                return $response;
            }

            // Clear any output buffering
            @ob_end_clean();

            fseek($handle, $start);

            // Send headers directly
            header('HTTP/1.1 206 Partial Content');
            header('Content-Type: video/mp4');
            header('Accept-Ranges: bytes');
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
            header('Content-Length: ' . $contentLength);
            header('Cache-Control: public, max-age=3600');
            header('Content-Disposition: inline; filename="' . basename($filePath) . '"');

            // Stream data in chunks
            $bufferSize = 8192;
            while (!feof($handle) && ($pos = ftell($handle)) <= $end) {
                $bytesToRead = min($bufferSize, $end - $pos + 1);
                echo fread($handle, $bytesToRead);
                flush();
                if (connection_aborted()) break;
            }

            fclose($handle);
            exit;
            
        } catch (\Exception $e) {
            $response = new Response('Range request error: ' . $e->getMessage());
            $response->setStatus(Http::STATUS_INTERNAL_SERVER_ERROR);
            $response->addHeader('Content-Type', 'text/plain');
            return $response;
        }
    }

    private function serveFile(string $filePath, int $fileSize): Response {
        // For large files, we should use streaming, but for now use simple response
        $content = file_get_contents($filePath);
        if ($content === false) {
            $response = new Response();
            $response->setStatus(Http::STATUS_INTERNAL_SERVER_ERROR);
            return $response;
        }

        $response = new Response($content);
        $response->addHeader('Content-Type', 'video/mp4');
        $response->addHeader('Accept-Ranges', 'bytes');
        $response->addHeader('Content-Length', (string)$fileSize);
        $response->addHeader('Cache-Control', 'no-cache');
        return $response;
    }

    private function isFFmpegOutputSuccessful(string $output, string $outputPath): bool {
        // Check for successful completion indicators
        $successIndicators = [
            'muxing overhead:',
            'kb/s:',
            'video:',
            'audio:',
            'global headers:'
        ];
        
        foreach ($successIndicators as $indicator) {
            if (strpos($output, $indicator) !== false) {
                return true;
            }
        }
        
        // Check for error patterns
        $errorPatterns = [
            'No such file or directory',
            'Permission denied',
            'Invalid data found',
            'Conversion failed',
            'Error while',
            'Unable to find'
        ];
        
        foreach ($errorPatterns as $pattern) {
            if (strpos($output, $pattern) !== false) {
                return false;
            }
        }
        
        // If no clear success/error indicators, check file existence
        return file_exists($outputPath);
    }
    
    private function isValidMP4File(string $filePath): bool {
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return false;
        }
        
        // Read first 32 bytes to check MP4 signature
        $header = fread($handle, 32);
        fclose($handle);
        
        if ($header === false || strlen($header) < 8) {
            return false;
        }
        
        // Check for MP4 file signature (ftyp box)
        // MP4 files start with a box size (4 bytes) followed by "ftyp"
        return strpos($header, 'ftyp') !== false;
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
