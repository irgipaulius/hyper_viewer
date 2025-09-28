<?php

declare(strict_types=1);

namespace OCA\HyperViewer\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Util;
use OCA\HyperViewer\BackgroundJob\HlsCacheGenerationJob;

class Application extends App implements IBootstrap {
	public const APP_ID = 'hyper_viewer';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// Register any services here if needed
	}

	public function boot(IBootContext $context): void {
		// Load files integration script globally
		// This ensures it's available whenever the Files app loads
		Util::addScript(self::APP_ID, 'files-integration');
	}
}
