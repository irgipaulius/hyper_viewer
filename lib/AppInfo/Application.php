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
use OCA\HyperViewer\BackgroundJob\AutoHlsGenerationJob;

class Application extends App implements IBootstrap {
	public const APP_ID = 'hyper_viewer';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// Hook CSP into pages rendered by other apps (Files/Viewer)
		$context->registerEventListener(AddContentSecurityPolicyEvent::class, CspListener::class);
		
		// Settings are registered via info.xml for better compatibility
		
		// Register auto-generation cron job
		$context->registerService('AutoHlsGenerationJob', function() {
			return \OC::$server->get(AutoHlsGenerationJob::class);
		});
	}

	public function boot(IBootContext $context): void {
		// Always inject our Files integration JS
		Util::addScript(self::APP_ID, 'files-integration');
		
		// Inject global Plyr time display fix
		Util::addScript(self::APP_ID, 'plyr-timefix');
		Util::addStyle(self::APP_ID, 'plyr-timefix');
		
		// Register auto-generation cron job
		$jobList = $context->getServerContainer()->get(\OCP\BackgroundJob\IJobList::class);
		if (!$jobList->has(AutoHlsGenerationJob::class, null)) {
			$jobList->add(AutoHlsGenerationJob::class);
		}
	}
}
