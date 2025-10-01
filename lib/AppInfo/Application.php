<?php

declare(strict_types=1);

namespace OCA\HyperViewer\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Util;
use OCP\AppFramework\Http\Events\AddContentSecurityPolicyEvent;
use OCA\HyperViewer\Listener\CspListener;

class Application extends App implements IBootstrap {
	public const APP_ID = 'hyper_viewer';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// Hook CSP into pages rendered by other apps (Files/Viewer)
		$context->registerEventListener(AddContentSecurityPolicyEvent::class, CspListener::class);
	}

	public function boot(IBootContext $context): void {
		// Always inject our Files integration JS
		Util::addScript(self::APP_ID, 'files-integration');
	}
}
