<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCP\IRequest;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Controller;
use OCP\Util;
use OCA\HyperViewer\CSP\HlsContentSecurityPolicy;

/**
 * Controller for HLS video player with relaxed CSP for blob URLs
 */
class PlayerController extends Controller {
    protected $appName;

    public function __construct($appName, IRequest $request) {
        parent::__construct($appName, $request);
        $this->appName = $appName;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index() {
        // Load the HLS player scripts
        Util::addScript($this->appName, 'player');
        Util::addStyle($this->appName, 'icons');

        $response = new TemplateResponse($this->appName, 'player');
        
        // Try to apply custom CSP that allows blob URLs for HLS playback
        try {
            $csp = new HlsContentSecurityPolicy();
            $response->setContentSecurityPolicy($csp);
        } catch (\Exception $e) {
            // If CSP fails, continue without it for now
            error_log('HLS CSP failed: ' . $e->getMessage());
        }
        
        return $response;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function modal() {
        // Simple test first - return basic HTML without template
        $filename = $_GET['filename'] ?? 'Unknown Video';
        $cachePath = $_GET['cachePath'] ?? '';
        
        $html = '<div style="padding: 20px; border: 2px solid red; background: #f0f0f0;">
            <h3 style="color: red;">🎬 HLS Player Test - DEBUGGING</h3>
            <p><strong>File:</strong> ' . htmlspecialchars($filename) . '</p>
            <p><strong>Cache:</strong> ' . htmlspecialchars($cachePath) . '</p>
            <p><strong>Manifest URL:</strong> /apps/files/ajax/download.php?dir=' . urlencode($cachePath) . '&files=playlist.m3u8</p>
            
            <div style="border: 1px solid blue; padding: 10px; margin: 10px 0;">
                <h4>Video Player:</h4>
                <video controls style="width: 100%; height: 300px; background: #000; border: 2px solid green;">
                    <source src="/apps/files/ajax/download.php?dir=' . urlencode($cachePath) . '&files=playlist.m3u8" type="application/vnd.apple.mpegurl">
                    Your browser does not support the video tag.
                </video>
            </div>
            
            <div style="background: yellow; padding: 10px; margin: 10px 0;">
                <h4>Debug Info:</h4>
                <p>Modal loaded successfully!</p>
                <p>Time: ' . date('Y-m-d H:i:s') . '</p>
            </div>
            
            <script>
                console.log("🎬 Modal HTML loaded with debugging");
                console.log("Filename:", "' . addslashes($filename) . '");
                console.log("Cache path:", "' . addslashes($cachePath) . '");
            </script>
        </div>';
        
        return new \OCP\AppFramework\Http\Response($html);
    }
}
