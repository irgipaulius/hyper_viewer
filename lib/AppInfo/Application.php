<?php

declare(strict_types=1);

namespace OCA\HyperViewer\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Util;
use OCP\AppFramework\Http\ContentSecurityPolicy;

class Application extends App implements IBootstrap {
    public const APP_ID = 'hyper_viewer';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        // Register services/background jobs if needed
    }

    public function boot(IBootContext $context): void {
        // Extend normal CSP instead of wiping everything
        $csp = new ContentSecurityPolicy();

        // Allow media blobs for MSE (Shaka / HLS)
        $csp->addAllowedMediaDomain("'self'");
        $csp->addAllowedMediaDomain("blob:");

        // Allow scripts & workers from blob: (needed for MSE demuxers)
        $csp->addAllowedScriptDomain("'self'");
        $csp->addAllowedScriptDomain("blob:");

        $csp->addAllowedWorkerSrcDomain("'self'");
        $csp->addAllowedWorkerSrcDomain("blob:");

        // Register CSP for this app globally
        $context->registerContentSecurityPolicy($csp);

        // Ensure Files integration JS is always loaded
        Util::addScript(self::APP_ID, 'files-integration');
    }
}
