<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

class PersonalSettings implements ISettings {
	private IConfig $config;
	private string $appName;

	public function __construct(IConfig $config, string $appName) {
		$this->config = $config;
		$this->appName = $appName;
	}

	public function getForm(): TemplateResponse {
		$userId = \OC_User::getUser();
		
		// Get current cache locations (default to common paths)
		$cacheLocations = $this->config->getUserValue(
			$userId, 
			$this->appName, 
			'cache_locations', 
			json_encode([
				'./.cached_hls/',
				'~/.cached_hls/',
				'/mnt/cache/.cached_hls/'
			])
		);

		$parameters = [
			'cache_locations' => json_decode($cacheLocations, true)
		];

		return new TemplateResponse($this->appName, 'personal-settings', $parameters, '');
	}

	public function getSection(): string {
		return 'hyper_viewer';
	}

	public function getPriority(): int {
		return 50;
	}
}
