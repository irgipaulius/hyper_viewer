<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2019 John Molakvoæ <skjnldsv@protonmail.com>
 *
 * @author John Molakvoæ <skjnldsv@protonmail.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\HyperViewer\Controller;

use OCP\IRequest;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Controller;
use OCP\Util;
use OCP\AppFramework\Http\ContentSecurityPolicy;

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
		Util::addScript($this->appName, 'hyper_viewer-main');
		Util::addStyle($this->appName, 'icons');
	
		$response = new TemplateResponse($this->appName, 'main');
	
		// Relax CSP for Shaka Player
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedMediaDomain('blob:');
		$csp->addAllowedScriptDomain('blob:');
		$csp->addAllowedWorkerSrcDomain('blob:');
		$response->setContentSecurityPolicy($csp);
	
		return $response;
	}
	

	/**
	 * Files integration endpoint with relaxed CSP for blob URLs
	 * 
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function filesIntegration() {
		Util::addInitScript($this->appName, 'files-integration');
	
		$response = new TemplateResponse($this->appName, 'empty');
	
		// Relax CSP for Shaka Player
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedMediaDomain('blob:');
		$csp->addAllowedScriptDomain('blob:');
		$csp->addAllowedWorkerSrcDomain('blob:');
		$response->setContentSecurityPolicy($csp);
	
		return $response;
	}
	/**
	 * Load files integration script globally
	 * This will be called automatically by Nextcloud when the Files app loads
	 */
	public function loadFilesIntegration() {
		// Use addInitScript to load before Files app (Nextcloud 25+ compatible)
		Util::addInitScript($this->appName, 'files-integration');
	}
}
