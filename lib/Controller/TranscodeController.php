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
                    'fileSize' => filesize($tempFile),
                    'tempFile' => basename($tempFile),
                    'cacheHit' => false,
                    'ffmpegOutput' => $transcodeResult['output'],
                    'isValidMP4' => $this->isValidMP4File($tempFile),
                    'fileExists' => file_exists($tempFile),
                    'tempDir' => $this->tempDir,
                    'returnCode' => $transcodeResult['returnCode']
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
            
            if (!file_exists($tempFile)) {
                $response = new Response('File not found: ' . $id);
                $response->setStatus(Http::STATUS_NOT_FOUND);
                $response->addHeader('Content-Type', 'text/plain');
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
        } catch (\Exception $e) {
            $response = new Response('Stream error: ' . $e->getMessage());
            $response->setStatus(Http::STATUS_INTERNAL_SERVER_ERROR);
            $response->addHeader('Content-Type', 'text/plain');
            return $response;
        }
    }

    private function startTranscode($inputPath, $outputPath) {
        // Escape shell arguments
        $input = escapeshellarg($inputPath);
        $output = escapeshellarg($outputPath);

        // FFmpeg command for 480p ultrafast transcoding with web-compatible settings
        // Output to stdout (-) for streaming
        $cmd = sprintf(
            '/usr/local/bin/ffmpeg -y -threads 3 -i %s ' .
            '-vf "scale=-2:480:flags=fast_bilinear" ' .
            '-c:v libx264 -preset ultrafast -tune zerolatency ' .
            '-profile:v baseline -level 3.0 -pix_fmt yuv420p ' .
            '-crf 28 -maxrate 1200k -bufsize 2400k ' .
            '-c:a aac -b:a 128k -ar 44100 ' .
            '-movflags +faststart+frag_keyframe+empty_moov ' .
            '-f mp4 -',
            $input
        );

        $this->logger->debug('FFmpeg command: ' . $cmd, ['app' => 'hyper_viewer']);

        // Execute asynchronously with streaming output
        $descriptorspec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout (video data)
            2 => ['pipe', 'w'],  // stderr (ffmpeg logs)
        ];
        
        $process = proc_open($cmd, $descriptorspec, $pipes);
        
        if (!is_resource($process)) {
            $this->logger->error('Failed to start FFmpeg process', ['app' => 'hyper_viewer']);
            return [
                'success' => false,
                'output' => 'Failed to start FFmpeg process',
                'returnCode' => -1,
                'fileExists' => false,
                'fileSize' => 0,
                'isValidMP4' => false,
                'cmd' => $cmd
            ];
        }
        
        // Close stdin as we don't need it
        fclose($pipes[0]);
        
        // Stream video data directly to output file
        $outputFile = fopen($outputPath, 'wb');
        if (!$outputFile) {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            return [
                'success' => false,
                'output' => 'Failed to create output file',
                'returnCode' => -1,
                'fileExists' => false,
                'fileSize' => 0,
                'isValidMP4' => false,
                'cmd' => $cmd
            ];
        }
        
        // Stream data from FFmpeg stdout to file
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 8192);
            if ($chunk !== false && strlen($chunk) > 0) {
                fwrite($outputFile, $chunk);
            }
        }
        
        // Get stderr output for logging
        $errorOutput = stream_get_contents($pipes[2]);
        
        // Close pipes and process
        fclose($pipes[1]);
        fclose($pipes[2]);
        fclose($outputFile);
        $return_code = proc_close($process);
        
        // Check if transcoding was actually successful (don't rely only on return code)
        $outputString = $errorOutput;
        
        $result = [
            'success' => false,
            'output' => $outputString,
            'returnCode' => $return_code,
            'fileExists' => file_exists($outputPath),
            'fileSize' => file_exists($outputPath) ? filesize($outputPath) : 0,
            'isValidMP4' => false,
            'cmd' => $cmd
        ];
        
        if ($return_code !== 0) {
            // Check if it's actually successful despite non-zero return code
            if ($this->isFFmpegOutputSuccessful($outputString, $outputPath)) {
                $this->logger->debug('FFmpeg returned non-zero code but output appears successful: ' . $return_code, ['app' => 'hyper_viewer']);
            } else {
                $this->logger->error('FFmpeg failed with code ' . $return_code . ': ' . $outputString, ['app' => 'hyper_viewer']);
                return $result;
            }
        }

        // Check if file exists and has reasonable size
        if (!file_exists($outputPath)) {
            $this->logger->error('FFmpeg completed but output file does not exist: ' . $outputPath, ['app' => 'hyper_viewer']);
            return $result;
        }

        $fileSize = filesize($outputPath);
        if ($fileSize < 1024) { // Less than 1KB is probably an error
            $this->logger->error('FFmpeg output file is too small (' . $fileSize . ' bytes): ' . $outputPath, ['app' => 'hyper_viewer']);
            return $result;
        }

        // Verify it's actually a valid MP4 file
        if (!$this->isValidMP4File($outputPath)) {
            $this->logger->error('FFmpeg output is not a valid MP4 file: ' . $outputPath, ['app' => 'hyper_viewer']);
            return $result;
        }

        $this->logger->debug('FFmpeg transcoding successful. Output file size: ' . $fileSize . ' bytes', ['app' => 'hyper_viewer']);
        
        $result['success'] = true;
        $result['fileSize'] = $fileSize;
        $result['isValidMP4'] = true;
        
        return $result;
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
