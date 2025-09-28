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
        Util::addScript($this->appName, 'files-integration');
        Util::addStyle($this->appName, 'icons');

        $response = new TemplateResponse($this->appName, 'player');
        
        // Apply custom CSP that allows blob URLs for HLS playback
        $csp = new HlsContentSecurityPolicy();
        $response->setContentSecurityPolicy($csp);
        
        return $response;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function modal() {
        // Endpoint for modal HLS player (called via AJAX)
        $response = new TemplateResponse($this->appName, 'player-modal', [], 'blank');
        
        // Apply custom CSP that allows blob URLs for HLS playback
        $csp = new HlsContentSecurityPolicy();
        $response->setContentSecurityPolicy($csp);
        
        return $response;
    }
}
