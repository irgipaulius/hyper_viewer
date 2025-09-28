<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

class SettingsController extends Controller {
	private IConfig $config;

	public function __construct(string $appName, IRequest $request, IConfig $config) {
		parent::__construct($appName, $request);
		$this->config = $config;
	}

	/**
	 * @NoAdminRequired
	 */
	public function setCacheLocations(array $locations): JSONResponse {
		$userId = \OC_User::getUser();
		
		// Validate and clean locations
		$cleanLocations = array_filter(array_map('trim', $locations));
		
		$this->config->setUserValue(
			$userId, 
			$this->appName, 
			'cache_locations', 
			json_encode($cleanLocations)
		);

		return new JSONResponse(['status' => 'success']);
	}

	/**
	 * @NoAdminRequired
	 */
	public function getCacheLocations(): JSONResponse {
		$userId = \OC_User::getUser();
		
		$locations = json_decode(
			$this->config->getUserValue($userId, $this->appName, 'cache_locations', '[]'), 
			true
		);

		return new JSONResponse(['locations' => $locations]);
	}
}
