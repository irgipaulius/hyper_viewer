<?php
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

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#filesIntegration', 'url' => '/files-integration', 'verb' => 'GET'],
		['name' => 'settings#setCacheLocations', 'url' => '/settings/cache-locations', 'verb' => 'POST'],
		['name' => 'settings#getCacheLocations', 'url' => '/settings/cache-locations', 'verb' => 'GET'],
		['name' => 'cache#generateCache', 'url' => '/cache/generate', 'verb' => 'POST'],
		['name' => 'cache#checkCache', 'url' => '/cache/check', 'verb' => 'POST'],
		['name' => 'cache#batchCheckCache', 'url' => '/cache/batch-check', 'verb' => 'POST'],
		['name' => 'cache#getProgress', 'url' => '/cache/progress/{cachePath}', 'verb' => 'GET', 'requirements' => ['cachePath' => '.+']],
		['name' => 'cache#discoverVideos', 'url' => '/cache/discover-videos', 'verb' => 'POST'],
		['name' => 'cache#registerAutoGeneration', 'url' => '/cache/register-auto-generation', 'verb' => 'POST'],
		['name' => 'cache#serveHlsFile', 'url' => '/hls/{cachePath}/{filename}', 'verb' => 'GET', 'requirements' => ['cachePath' => '.+', 'filename' => '.+']],
		
		// Progressive MP4 Proxy Transcoding
		['name' => 'transcode#proxyTranscode', 'url' => '/api/proxy-transcode', 'verb' => 'GET'],
		['name' => 'transcode#proxyStream', 'url' => '/api/proxy-stream', 'verb' => 'GET'],
		
		// Video Clipping
		['name' => 'clip#exportClip', 'url' => '/api/export-clip', 'verb' => 'POST'],
		
		// Management Dashboard API
		['name' => 'cache#getActiveJobs', 'url' => '/api/jobs/active', 'verb' => 'GET'],
		['name' => 'cache#getJobProgress', 'url' => '/api/jobs/active/{filename}', 'verb' => 'GET'],
		['name' => 'cache#getAutoGenerationSettings', 'url' => '/api/auto-generation', 'verb' => 'GET'],
		['name' => 'cache#updateAutoGeneration', 'url' => '/api/auto-generation/{configKey}', 'verb' => 'PUT'],
		['name' => 'cache#removeAutoGeneration', 'url' => '/api/auto-generation/{configKey}', 'verb' => 'DELETE'],
		['name' => 'cache#getJobStatistics', 'url' => '/api/jobs/statistics', 'verb' => 'GET'],
	]
];
