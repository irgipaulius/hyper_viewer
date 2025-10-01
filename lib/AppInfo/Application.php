<?php

declare(strict_types=1);

namespace OCA\HyperViewer\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Util;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;

class Application extends App implements IBootstrap {
    public const APP_ID = 'hyper_viewer';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        // Register any services or background jobs here if needed
    }

    public function boot(IBootContext $context): void {
        // ✅ Global CSP relaxation for Shaka / MSE blob URLs
        $csp = new EmptyContentSecurityPolicy();

        // Allow media blobs for MSE (video streaming)
        $csp->addAllowedMediaDomain("'self'");
        $csp->addAllowedMediaDomain("blob:");

        // Allow JS workers from blob:
        $csp->addAllowedScriptDomain("'self'");
        $csp->addAllowedScriptDomain("blob:");

        $csp->addAllowedWorkerSrcDomain("'self'");
        $csp->addAllowedWorkerSrcDomain("blob:");

        // Register CSP globally (affects Files app too)
        $context->registerContentSecurityPolicy($csp);

        // ✅ Ensure Files integration JS is always loaded
        Util::addScript(self::APP_ID, 'files-integration');
    }
}
