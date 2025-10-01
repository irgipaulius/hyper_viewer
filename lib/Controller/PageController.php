<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCP\IRequest;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Controller;
use OCP\Util;

class PageController extends Controller {
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
        // Load main JS and CSS for HyperViewer
        Util::addScript($this->appName, 'hyper_viewer-main');
        Util::addStyle($this->appName, 'icons');

        return new TemplateResponse($this->appName, 'main');
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function filesIntegration() {
        // Load the Files integration script
        Util::addInitScript($this->appName, 'files-integration');

        // Return empty template (only scripts are injected)
        return new TemplateResponse($this->appName, 'empty');
    }

    /**
     * Load files integration script globally
     * This will be called automatically by Nextcloud when the Files app loads
     */
    public function loadFilesIntegration() {
        Util::addInitScript($this->appName, 'files-integration');
    }
}
