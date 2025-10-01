<?php
declare(strict_types=1);

namespace OCA\HyperViewer\Listener;

use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\Events\AddContentSecurityPolicyEvent;
use OCP\EventDispatcher\IEventListener;

/**
 * Appends blob: allowances so MSE players (Shaka) can attach blob media/worker URLs.
 * This runs for pages rendered by other apps too (e.g. Files / Viewer).
 */
class CspListener implements IEventListener {
	public function handle($event): void {
		if (!($event instanceof AddContentSecurityPolicyEvent)) {
			return;
		}

		$policy = new ContentSecurityPolicy();

		// Let <video> use blob: sources (MSE)
		$policy->addAllowedMediaDomain("'self'");
		$policy->addAllowedMediaDomain("blob:");

		// Allow scripts/workers from blob: (Shaka’s MSE bits)
		$policy->addAllowedScriptDomain("'self'");
		$policy->addAllowedScriptDomain("blob:");

		$policy->addAllowedWorkerSrcDomain("'self'");
		$policy->addAllowedWorkerSrcDomain("blob:");

		// Older engines sometimes key off child-src for workers
		if (method_exists($policy, 'addAllowedChildSrcDomain')) {
			$policy->addAllowedChildSrcDomain("'self'");
			$policy->addAllowedChildSrcDomain("blob:");
		}

		$event->addPolicy($policy);
	}
}
