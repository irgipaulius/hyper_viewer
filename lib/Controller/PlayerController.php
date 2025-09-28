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
        
        // Build HTML content
        $html = '<div style="padding: 20px; border: 2px solid red; background: #f0f0f0;">';
        $html .= '<h3 style="color: red;">🎬 HLS Player Test - DEBUGGING</h3>';
        $html .= '<p><strong>File:</strong> ' . htmlspecialchars($filename) . '</p>';
        $html .= '<p><strong>Cache:</strong> ' . htmlspecialchars($cachePath) . '</p>';
        $html .= '<p><strong>Manifest URL:</strong> /apps/files/ajax/download.php?dir=' . urlencode($cachePath) . '&files=playlist.m3u8</p>';
        
        $html .= '<div style="border: 1px solid blue; padding: 10px; margin: 10px 0;">';
        $html .= '<h4>Video Player:</h4>';
        $html .= '<video controls style="width: 100%; height: 300px; background: #000; border: 2px solid green;">';
        $html .= '<source src="/apps/files/ajax/download.php?dir=' . urlencode($cachePath) . '&files=playlist.m3u8" type="application/vnd.apple.mpegurl">';
        $html .= 'Your browser does not support the video tag.';
        $html .= '</video>';
        $html .= '</div>';
        
        $html .= '<div style="background: yellow; padding: 10px; margin: 10px 0;">';
        $html .= '<h4>Debug Info:</h4>';
        $html .= '<p>Modal loaded successfully!</p>';
        $html .= '<p>Time: ' . date('Y-m-d H:i:s') . '</p>';
        $html .= '</div>';
        
        $html .= '<script>';
        $html .= 'console.log("🎬 Modal HTML loaded with debugging");';
        $html .= 'console.log("Filename:", "' . addslashes($filename) . '");';
        $html .= 'console.log("Cache path:", "' . addslashes($cachePath) . '");';
        $html .= '</script>';
        $html .= '</div>';
        
        // Create response with proper content type
        $response = new \OCP\AppFramework\Http\Response($html);
        $response->addHeader('Content-Type', 'text/html; charset=utf-8');
        
        return $response;
    }
}
