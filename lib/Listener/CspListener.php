<?php
declare(strict_types=1);

namespace OCA\HyperViewer\Listener;

use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\Events\AddContentSecurityPolicyEvent;
use OCP\EventDispatcher\IEventListener;

/**
 * Append blob: support for MSE / Shaka
 */
class CspListener implements IEventListener {
	public function handle($event): void {
		if (!($event instanceof AddContentSecurityPolicyEvent)) {
			return;
		}

		$policy = new EmptyContentSecurityPolicy();

		// ✅ Explicitly allow blob: for media
		$policy->addAllowedMediaDomain("'self'");
		$policy->addAllowedMediaDomain("blob:");

		// ✅ Scripts (for Shaka workers)
		$policy->addAllowedScriptDomain("'self'");
		$policy->addAllowedScriptDomain("blob:");

		// ✅ Workers
		$policy->addAllowedWorkerSrcDomain("'self'");
		$policy->addAllowedWorkerSrcDomain("blob:");

		// Some NC versions also need child-src
		if (method_exists($policy, 'addAllowedChildSrcDomain')) {
			$policy->addAllowedChildSrcDomain("'self'");
			$policy->addAllowedChildSrcDomain("blob:");
		}

		// Important: append to event
		$event->addPolicy($policy);
	}
}
