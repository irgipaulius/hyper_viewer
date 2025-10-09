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

    public function __construct(string $appName, IRequest $request, IRootFolder $rootFolder, IUserSession $userSession, ILogger $logger) {
        parent::__construct($appName, $request);
        $this->rootFolder = $rootFolder;
        $this->userSession = $userSession;
        $this->logger = $logger;
        
        // Temp directory will be set per-user in getUserTempDir()
        $this->tempDir = null;
    }

    /**
     * Get user-specific temp directory for transcoded files
     */
    private function getUserTempDir(): string {
        if ($this->tempDir === null) {
            $user = $this->userSession->getUser();
            if (!$user) {
                throw new \Exception('User not authenticated');
            }
            
            // Get user's home folder and create .cached_mp4 directory
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            $tempDirPath = '/.cached_mp4';
            
            try {
                if (!$userFolder->nodeExists($tempDirPath)) {
                    $userFolder->newFolder($tempDirPath);
                }
                $tempFolder = $userFolder->get($tempDirPath);
                $this->tempDir = $tempFolder->getStorage()->getLocalFile($tempFolder->getInternalPath());
            } catch (\Exception $e) {
                // Fallback to system temp directory
                $this->tempDir = sys_get_temp_dir() . '/hyper_viewer_' . $user->getUID();
                if (!is_dir($this->tempDir)) {
                    mkdir($this->tempDir, 0755, true);
                }
            }
        }
        
        return $this->tempDir;
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
            $tempFile = $this->getUserTempDir() . '/' . $fileId . '.mp4';

            // Check if already transcoded (unless force is true)
            if (!$force && file_exists($tempFile)) {
                return new JSONResponse([
                    'url' => '/apps/hyper_viewer/api/proxy-stream?id=' . $fileId,
                    'debug' => [
                        'fileSize' => filesize($tempFile),
                        'tempFile' => basename($tempFile),
                        'cacheHit' => true,
                        'path' => $path,
                        'fileId' => $fileId,
                        'tempDir' => $this->getUserTempDir()
                    ]
                ]);
            }
            
            // If forcing, delete existing file
            if ($force && file_exists($tempFile)) {
                unlink($tempFile);
            }

            // Clean up old files (older than 2 hours)
            $this->cleanupOldFiles();

            // Get input file path
            $inputPath = $file->getStorage()->getLocalFile($file->getInternalPath());
            if (!$inputPath) {
                return new JSONResponse(['error' => 'Cannot access file locally'], Http::STATUS_INTERNAL_SERVER_ERROR);
            }

            // Start transcoding
            $transcodeResult = $this->startTranscode($inputPath, $tempFile);
            
            if (!$transcodeResult['success']) {
                return new JSONResponse([
                    'error' => 'Transcoding failed',
                    'debug' => $transcodeResult
                ], Http::STATUS_INTERNAL_SERVER_ERROR);
            }

            return new JSONResponse([
                'url' => '/apps/hyper_viewer/api/proxy-stream?id=' . $fileId,
                'debug' => [
                    'backgroundTranscode' => true,
                    'tempFile' => basename($tempFile),
                    'cacheHit' => false,
                    'path' => $path,
                    'fileId' => $fileId,
                    'inputPath' => $inputPath,
                    'tempDir' => $this->getUserTempDir(),
                    'transcodeResult' => $transcodeResult
                ]
            ]);

        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
        } catch (NotPermittedException $e) {
            return new JSONResponse(['error' => 'Permission denied'], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            return new JSONResponse([
                'error' => 'Internal server error', 
                'debug' => [
                    'exception' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function proxyStream($id) {
        try {
            $tempDir = $this->getUserTempDir();
            $tempFile = $tempDir . '/' . $id . '.mp4';
            
            // Check if temp directory exists
            if (!is_dir($tempDir)) {
                header('HTTP/1.1 500 Internal Server Error');
                header('X-Debug-Error: Temp directory not found');
                header('X-Debug-Path: ' . $tempDir);
                exit;
            }
            
            // Wait for FFmpeg to create the file (up to 30 seconds)
            $maxWaitTime = 30;
            $waitInterval = 0.5; // 500ms
            $waited = 0;
            
            while (!file_exists($tempFile) && $waited < $maxWaitTime) {
                usleep((int)($waitInterval * 1000000)); // Convert to microseconds
                $waited += $waitInterval;
            }
            
            if (!file_exists($tempFile)) {
                // List files in temp directory for debugging
                $files = glob($tempDir . '/*');
                $fileList = implode(', ', array_map('basename', $files));
                
                header('HTTP/1.1 404 Not Found');
                header('X-Debug-Error: File not found after waiting');
                header('X-Debug-Wait-Time: ' . $waited . 's');
                header('X-Debug-ID: ' . $id);
                header('X-Debug-Expected-File: ' . basename($tempFile));
                header('X-Debug-Temp-Dir: ' . $tempDir);
                header('X-Debug-Files-In-Dir: ' . $fileList);
                exit;
            }
            
            // Wait for file to have some content (even just a few bytes)
            $minSize = 100; // Reduced to 100 bytes - just need some data
            $maxContentWait = 10; // Reduced wait time
            $contentWaited = 0;
            
            while (filesize($tempFile) < $minSize && $contentWaited < $maxContentWait) {
                usleep((int)($waitInterval * 1000000));
                $contentWaited += $waitInterval;
            }
            
            // Start streaming even with small files - progressive streaming will handle it
            $currentSize = filesize($tempFile);
            if ($currentSize == 0) {
                header('HTTP/1.1 202 Accepted');
                header('X-Debug-Error: Transcoding starting, no data yet');
                header('X-Debug-Current-Size: ' . $currentSize);
                header('X-Debug-Content-Wait-Time: ' . $contentWaited . 's');
                header('X-Debug-Suggestion: Please wait and try again in a few seconds');
                header('Retry-After: 3');
                exit;
            }

            // Clean up old files
            $this->cleanupOldFiles();

            $fileSize = filesize($tempFile);
            $rangeHeader = $this->request->getHeader('Range');

            // Add debug headers for troubleshooting
            header('X-Debug-File-Size: ' . $fileSize);
            header('X-Debug-File-Exists: ' . (file_exists($tempFile) ? 'true' : 'false'));
            header('X-Debug-Wait-Time: ' . $waited . 's');
            header('X-Debug-Content-Wait: ' . $contentWaited . 's');
            header('X-Debug-Range-Header: ' . ($rangeHeader ?: 'none'));

            if ($rangeHeader) {
                // Handle range requests for seeking
                $this->handleRangeRequest($tempFile, $fileSize, $rangeHeader);
            } else {
                // Always use chunked streaming for better UX, even for completed files
                $this->streamFileInChunks($tempFile, $fileSize);
            }
        } catch (\Exception $e) {
            header('HTTP/1.1 500 Internal Server Error');
            header('X-Debug-Exception: ' . $e->getMessage());
            header('X-Debug-File: ' . $e->getFile());
            header('X-Debug-Line: ' . $e->getLine());
            exit;
        }
    }

    private function startTranscode($inputPath, $outputPath) {
        // Create unique log file for this transcode in cache directory
        $logId = uniqid();
        $tempDir = $this->getUserTempDir();
        $logFile = $tempDir . '/ffmpeg_' . $logId . '.log';
        
        // FFmpeg command for 480p fragmented MP4 streaming with continuous output
        // Uses fragmented MP4 for progressive streaming during transcoding
        $cmd = sprintf(
            'nohup /usr/local/bin/ffmpeg -y -threads 3 -i %s ' .
            '-vf "scale=-2:480:flags=fast_bilinear" ' .
            '-c:v libx264 -preset ultrafast -tune zerolatency ' .
            '-profile:v baseline -level 3.0 -pix_fmt yuv420p ' .
            '-crf 28 -maxrate 1200k -bufsize 2400k ' .
            '-c:a aac -b:a 128k -ar 44100 ' .
            '-movflags +frag_keyframe+empty_moov+default_base_moof+frag_every_frame ' .
            '-frag_duration 1000000 -min_frag_duration 500000 ' .
            '-f mp4 %s > %s 2>&1 &',
            escapeshellarg($inputPath),
            escapeshellarg($outputPath),
            escapeshellarg($logFile)
        );

        // Ensure temp directory exists
        $tempDir = $this->getUserTempDir();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // Fire and forget - start FFmpeg in background
        exec($cmd, $output, $returnCode);
        
        // Return immediately - don't wait for completion
        return [
            'success' => true,
            'running' => true,
            'backgroundProcess' => true,
            'logFile' => $logFile,
            'cmd' => $cmd,
            'outputPath' => $outputPath,
            'execReturnCode' => $returnCode,
            'execOutput' => $output,
            'tempDirExists' => is_dir($tempDir),
            'tempDirWritable' => is_writable($tempDir)
        ];
    }

    private function handleRangeRequest(string $filePath, int $fileSize, string $rangeHeader): void {
        try {
            // Parse Range header (e.g., "bytes=0-1023" or "bytes=0-")
            if (!preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $matches)) {
                header('HTTP/1.1 416 Requested Range Not Satisfiable');
                header('X-Debug-Error: Invalid range header');
                header('X-Debug-Range-Header: ' . $rangeHeader);
                exit;
            }

            $start = (int)$matches[1];
            $isTranscoding = $this->isFileBeingTranscoded($filePath);
            
            if ($isTranscoding) {
                // For transcoding files, use progressive range streaming
                $this->streamProgressiveRange($filePath, $start, $matches[2]);
                return;
            }
            
            // For completed files, use normal range handling
            $end = $matches[2] !== '' ? (int)$matches[2] : $fileSize - 1;

            // Validate range
            if ($start >= $fileSize || $end >= $fileSize || $start > $end) {
                header('HTTP/1.1 416 Requested Range Not Satisfiable');
                header('Content-Range: bytes */' . $fileSize);
                header('X-Debug-Error: Invalid range');
                header('X-Debug-Range: ' . $start . '-' . $end);
                header('X-Debug-File-Size: ' . $fileSize);
                exit;
            }

            $contentLength = $end - $start + 1;

            // Stream the range directly using native PHP headers
            $handle = fopen($filePath, 'rb');
            if (!$handle) {
                header('HTTP/1.1 500 Internal Server Error');
                header('X-Debug-Error: Cannot open file');
                header('X-Debug-File-Path: ' . basename($filePath));
                exit;
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
            header('HTTP/1.1 500 Internal Server Error');
            header('X-Debug-Exception: ' . $e->getMessage());
            header('X-Debug-File: ' . $e->getFile());
            header('X-Debug-Line: ' . $e->getLine());
            exit;
        }
    }

    private function serveFile(string $filePath, int $fileSize): void {
        // Check if file is still being written (FFmpeg in progress)
        $isTranscoding = $this->isFileBeingTranscoded($filePath);
        
        if ($isTranscoding) {
            // Stream progressively with chunked encoding
            $this->streamProgressiveFile($filePath);
        } else {
            // File is complete, serve normally
            $handle = fopen($filePath, 'rb');
            if (!$handle) {
                header('HTTP/1.1 500 Internal Server Error');
                header('X-Debug-Error: Cannot open file');
                exit;
            }

            // Clear any output buffering
            @ob_end_clean();

            // Send headers directly
            header('HTTP/1.1 200 OK');
            header('Content-Type: video/mp4');
            header('Accept-Ranges: bytes');
            header('Content-Length: ' . $fileSize);
            header('Cache-Control: public, max-age=3600');
            header('Content-Disposition: inline; filename="' . basename($filePath) . '"');

            // Stream file in chunks
            $bufferSize = 8192;
            while (!feof($handle)) {
                echo fread($handle, $bufferSize);
                flush();
                if (connection_aborted()) break;
            }

            fclose($handle);
            exit;
        }
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

    private function isFileBeingTranscoded(string $filePath): bool {
        // Check if corresponding FFmpeg process is still running
        $fileId = basename($filePath, '.mp4');
        $tempDir = $this->getUserTempDir();
        $logPattern = $tempDir . '/ffmpeg_*.log';
        $logFiles = glob($logPattern);
        
        foreach ($logFiles as $logFile) {
            $logContent = file_get_contents($logFile);
            if ($logContent && strpos($logContent, $filePath) !== false) {
                // Check if log shows completion
                if (strpos($logContent, 'muxing overhead:') !== false ||
                    strpos($logContent, 'Conversion failed') !== false) {
                    return false; // Transcoding finished
                }
                return true; // Still transcoding
            }
        }
        
        // Fallback: check file age (if very recent, likely still transcoding)
        $fileAge = time() - filemtime($filePath);
        return $fileAge < 300; // Consider active if modified within 5 minutes
    }
    
    private function streamProgressiveFile(string $filePath): void {
        // Clear any output buffering
        @ob_end_clean();
        
        // Send headers for chunked streaming
        header('HTTP/1.1 200 OK');
        header('Content-Type: video/mp4');
        header('Accept-Ranges: bytes');
        header('Transfer-Encoding: chunked');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            header('HTTP/1.1 500 Internal Server Error');
            header('X-Debug-Error: Cannot open file for progressive streaming');
            exit;
        }
        
        $position = 0;
        $chunkSize = 16384; // Increased chunk size for better performance
        $maxWaitTime = 300; // 5 minutes max
        $startTime = time();
        $lastDataTime = time();
        
        while (time() - $startTime < $maxWaitTime) {
            // Get current file size
            clearstatcache(true, $filePath);
            $currentSize = filesize($filePath);
            
            if ($position < $currentSize) {
                // Read available data
                fseek($handle, $position);
                $bytesToRead = min($chunkSize, $currentSize - $position);
                $chunk = fread($handle, $bytesToRead);
                
                if ($chunk !== false && strlen($chunk) > 0) {
                    // Send chunk in HTTP chunked format
                    echo dechex(strlen($chunk)) . "\r\n";
                    echo $chunk . "\r\n";
                    flush();
                    
                    $position += strlen($chunk);
                    $lastDataTime = time();
                }
            } else {
                // Check if transcoding is complete
                if (!$this->isFileBeingTranscoded($filePath)) {
                    break; // Transcoding finished
                }
                
                // If no new data for 30 seconds, might be stalled
                if (time() - $lastDataTime > 30) {
                    break;
                }
                
                // Wait less time for more responsive streaming
                usleep(250000); // 250ms - more responsive
            }
            
            // Check if client disconnected
            if (connection_aborted()) {
                break;
            }
        }
        
        // Send final chunk (empty to signal end)
        echo "0\r\n\r\n";
        flush();
        
        fclose($handle);
        exit;
    }
    
    private function streamProgressiveRange(string $filePath, int $start, string $endStr): void {
        // Clear any output buffering
        @ob_end_clean();
        
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            header('HTTP/1.1 500 Internal Server Error');
            header('X-Debug-Error: Cannot open file for range streaming');
            exit;
        }
        
        // Seek to start position
        fseek($handle, $start);
        $position = $start;
        $chunkSize = 8192;
        $maxWaitTime = 300; // 5 minutes max
        $startTime = time();
        
        // Send initial headers for range request
        header('HTTP/1.1 206 Partial Content');
        header('Content-Type: video/mp4');
        header('Accept-Ranges: bytes');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        
        // If end is specified, we need to respect it, otherwise stream until file ends
        $hasEndLimit = ($endStr !== '');
        $endLimit = $hasEndLimit ? (int)$endStr : PHP_INT_MAX;
        
        $totalSent = 0;
        $headersSent = false;
        
        while (time() - $startTime < $maxWaitTime) {
            // Get current file size
            clearstatcache(true, $filePath);
            $currentSize = filesize($filePath);
            
            if ($position < $currentSize) {
                $availableBytes = $currentSize - $position;
                $bytesToRead = min($chunkSize, $availableBytes);
                
                // Respect end limit if specified
                if ($hasEndLimit && ($position + $bytesToRead) > ($endLimit + 1)) {
                    $bytesToRead = ($endLimit + 1) - $position;
                }
                
                if ($bytesToRead > 0) {
                    $chunk = fread($handle, $bytesToRead);
                    
                    if ($chunk !== false && strlen($chunk) > 0) {
                        // Send Content-Range header with first chunk
                        if (!$headersSent) {
                            $rangeEnd = $hasEndLimit ? $endLimit : '*';
                            header('Content-Range: bytes ' . $start . '-' . $rangeEnd . '/*');
                            $headersSent = true;
                        }
                        
                        echo $chunk;
                        flush();
                        
                        $position += strlen($chunk);
                        $totalSent += strlen($chunk);
                        
                        // If we've reached the end limit, stop
                        if ($hasEndLimit && $position > $endLimit) {
                            break;
                        }
                    }
                }
            } else {
                // Check if transcoding is complete
                if (!$this->isFileBeingTranscoded($filePath)) {
                    break; // Transcoding finished
                }
                
                // Wait a bit for more data
                usleep(500000); // 500ms
            }
            
            // Check if client disconnected
            if (connection_aborted()) {
                break;
            }
        }
        
        fclose($handle);
        exit;
    }

    private function cleanupOldFiles(): void {
        $cutoff = time() - (24 * 3600); // 2 hours ago
        $tempDir = $this->getUserTempDir();
        
        // Clean up old MP4 files
        $mp4Files = glob($tempDir . '/*.mp4');
        foreach ($mp4Files as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
        
        // Clean up old log files
        $logFiles = glob($tempDir . '/ffmpeg_*.log');
        foreach ($logFiles as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }

    /**
     * Stream a completed file optimized for immediate video playback
     */
    private function streamFileInChunks(string $filePath, int $fileSize): void {
        // Clear any output buffering
        @ob_end_clean();
        
        // For video files, we want to send just enough data for the browser to start playing
        // The browser will then make range requests for the rest
        $initialChunkSize = min(1048576, $fileSize); // 1MB or entire file if smaller
        
        // Send headers for partial content to enable range requests
        header('HTTP/1.1 206 Partial Content');
        header('Content-Type: video/mp4');
        header('Content-Length: ' . $initialChunkSize);
        header('Content-Range: bytes 0-' . ($initialChunkSize - 1) . '/' . $fileSize);
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=3600');
        header('Connection: keep-alive');
        
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            header('HTTP/1.1 500 Internal Server Error');
            header('X-Debug-Error: Cannot open completed file for streaming');
            exit;
        }
        
        // Stream the initial chunk in smaller pieces for responsiveness
        $chunkSize = 32768; // 32KB chunks
        $position = 0;
        
        while ($position < $initialChunkSize) {
            if (connection_aborted()) {
                break;
            }
            
            $bytesToRead = min($chunkSize, $initialChunkSize - $position);
            $chunk = fread($handle, $bytesToRead);
            
            if ($chunk !== false && strlen($chunk) > 0) {
                echo $chunk;
                flush();
                $position += strlen($chunk);
            } else {
                break;
            }
            
            // Small delay for smooth streaming
            usleep(500); // 0.5ms delay
        }
        
        fclose($handle);
        exit;
    }

}
