<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCP\IRequest;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Controller;
use OCP\Util;

class PageController extends Controller {
	protected $appName;

	public function __construct(string $appName, IRequest $request) {
		parent::__construct($appName, $request);
		$this->appName = $appName;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function index(): TemplateResponse {
		Util::addScript($this->appName, 'hyper_viewer-main');
		Util::addStyle($this->appName, 'icons');
		
		// Load file picker assets for export functionality
		Util::addScript('core', 'oc-dialogs');
		Util::addScript('files', 'filepicker');
		Util::addStyle('files', 'filepicker');
		
		return new TemplateResponse($this->appName, 'main');
	}
}
