<?php

declare(strict_types=1);

namespace OCA\HyperViewer\CSP;

use OCP\AppFramework\Http\ContentSecurityPolicy;

/**
 * Custom Content Security Policy for HLS video playback
 * Allows blob URLs which are required for HLS players like Shaka Player and HLS.js
 */
class HlsContentSecurityPolicy extends ContentSecurityPolicy {
    public function __construct() {
        parent::__construct();

        // Allow media loaded from blob: URLs (required for HLS players)
        $this->addAllowedMediaDomain('blob:');

        // Allow scripts from blob: URLs (required for HLS.js workers)
        $this->addAllowedScriptDomain('blob:');

        // Try to allow workers if the method exists (Nextcloud version dependent)
        if (method_exists($this, 'addAllowedWorkerSrcDomain')) {
            $this->addAllowedWorkerSrcDomain('blob:');
        }
        
        // Try to allow object sources if the method exists
        if (method_exists($this, 'addAllowedObjectDomain')) {
            $this->addAllowedObjectDomain('blob:');
        }
    }
}
