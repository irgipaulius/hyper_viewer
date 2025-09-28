/**
 * Files app integration for Hyper Viewer (Nextcloud 25 compatible)
 * Adds "Generate HLS Cache" action to MOV and MP4 files
 */

import Hls from 'hls.js'

console.log('🎬 Hyper Viewer Files integration loading...')

// Wait for Files app to be ready
document.addEventListener('DOMContentLoaded', function() {
	// Wait a bit more for Files app to fully initialize
	setTimeout(initializeFilesIntegration, 1000)
})

/**
 * Initialize files integration
 */
function initializeFilesIntegration() {
	console.log('🔧 Initializing Files integration...')

	// Check if we're in the Files app
	if (!window.OCA || !window.OCA.Files || !window.OCA.Files.fileActions) {
		console.log('⚠️ Files app not available, retrying in 2 seconds...')
		setTimeout(initializeFilesIntegration, 2000)
		return
	}

	console.log('✅ Files app detected, registering actions...')

	// Register "Generate HLS Cache" action for MOV files
	OCA.Files.fileActions.registerAction({
		name: 'generateHlsCacheMov',
		displayName: t('hyper_viewer', 'Generate HLS Cache'),
		mime: 'video/quicktime',
		permissions: OC.PERMISSION_UPDATE,
		iconClass: 'icon-category-multimedia',
		actionHandler(filename, context) {
			console.log('🚀 Generate HLS Cache action triggered for MOV:', filename)
			console.log('📁 Context:', context)
			openCacheGenerationDialog([{ filename, context }])
		},
	})

	// Register "Play with HLS" action for MOV files (higher priority)
	OCA.Files.fileActions.registerAction({
		name: 'playHlsMov',
		displayName: t('hyper_viewer', 'Play with HLS'),
		mime: 'video/quicktime',
		permissions: OC.PERMISSION_READ,
		iconClass: 'icon-play',
		async actionHandler(filename, context) {
			console.log('🎬 Play with HLS triggered for MOV:', filename)
			const directory = context?.dir || context?.fileList?.getCurrentDirectory() || '/'
			await playWithHls(filename, directory, context)
		},
	})

	// Register "Generate HLS Cache" action for MP4 files
	OCA.Files.fileActions.registerAction({
		name: 'generateHlsCacheMp4',
		displayName: t('hyper_viewer', 'Generate HLS Cache'),
		mime: 'video/mp4',
		permissions: OC.PERMISSION_UPDATE,
		iconClass: 'icon-category-multimedia',
		actionHandler(filename, context) {
			console.log('🚀 Generate HLS Cache action triggered for MP4:', filename)
			console.log('📁 Context:', context)
			openCacheGenerationDialog([{ filename, context }])
		},
	})

	// Register "Play with HLS" action for MP4 files (higher priority)
	OCA.Files.fileActions.registerAction({
		name: 'playHlsMp4',
		displayName: t('hyper_viewer', 'Play with HLS'),
		mime: 'video/mp4',
		permissions: OC.PERMISSION_READ,
		iconClass: 'icon-play',
		async actionHandler(filename, context) {
			console.log('🎬 Play with HLS triggered for MP4:', filename)
			const directory = context?.dir || context?.fileList?.getCurrentDirectory() || '/'
			await playWithHls(filename, directory, context)
		},
	})

	// Register bulk action for multiple file selection
	if (OCA.Files && OCA.Files.fileActions && OCA.Files.fileActions.registerAction) {
		// Add to bulk actions menu (appears when multiple files are selected)
		document.addEventListener('DOMContentLoaded', function() {
			// Wait for Files app to be fully loaded
			setTimeout(function() {
				if (window.FileActions && window.FileActions.register) {
					// Register bulk action
					window.FileActions.register('all', 'Generate HLS Cache (Bulk)', OC.PERMISSION_UPDATE, function() {
						return OC.imagePath('core', 'actions/category-multimedia')
					}, function(filename) {
						console.log('🚀 Bulk HLS Cache generation triggered')
						handleBulkCacheGeneration()
					})
				}
			}, 2000)
		})
	}

	console.log('✅ Hyper Viewer Files integration registered!')
}

/**
 * Handle bulk cache generation from Actions menu
 */
function handleBulkCacheGeneration() {
	// Get selected files from Files app
	const selectedFiles = []

	if (window.FileList && window.FileList.getSelectedFiles) {
		const selected = window.FileList.getSelectedFiles()
		selected.forEach(file => {
			// Filter for video files only
			if (file.mimetype === 'video/quicktime' || file.mimetype === 'video/mp4') {
				selectedFiles.push({
					filename: file.name,
					context: {
						dir: window.FileList.getCurrentDirectory(),
						fileInfoModel: file,
					},
				})
			}
		})
	}

	if (selectedFiles.length === 0) {
		OC.dialogs.alert('No video files selected. Please select MOV or MP4 files.', 'Generate HLS Cache')
		return
	}

	console.log('🎬 Bulk processing files:', selectedFiles.map(f => f.filename))
	openCacheGenerationDialog(selectedFiles)
}

/**
 * Open cache generation dialog with proper modal
 *
 * @param files Array of file objects with filename and context
 */
function openCacheGenerationDialog(files) {
	console.log('🔧 Opening cache generation dialog for files:', files.map(f => f.filename))

	const fileList = files.map(f => f.filename).join(', ')
	const isMultiple = files.length > 1

	// Create modal HTML content
	const modalContent = `
		<div class="hyper-viewer-cache-dialog">
			<h3>${isMultiple ? 'Generate HLS Cache (Bulk)' : 'Generate HLS Cache'}</h3>
			<p><strong>Files to process:</strong> ${fileList}</p>
			
			<div class="cache-location-section">
				<h4>Cache Location:</h4>
				<div class="cache-options">
					<label>
						<input type="radio" name="cache_location" value="relative" checked>
						<strong>Relative to video file</strong><br>
						<small>Creates <code>.cached_hls/</code> folder next to each video file</small>
					</label>
					<br><br>
					<label>
						<input type="radio" name="cache_location" value="home">
						<strong>User home directory</strong><br>
						<small>Creates <code>~/.cached_hls/</code> in your home folder</small>
					</label>
					<br><br>
					<label>
						<input type="radio" name="cache_location" value="custom">
						<strong>Custom location</strong><br>
						<input type="text" id="custom_path" placeholder="/mnt/cache/.cached_hls/" style="width: 100%; margin-top: 5px;" disabled>
					</label>
				</div>
			</div>
			
			<div class="processing-options">
				<h4>Processing Options:</h4>
				<label>
					<input type="checkbox" id="overwrite_existing" checked>
					Overwrite existing cache files
				</label>
				<br>
				<label>
					<input type="checkbox" id="notify_completion" checked>
					Notify when processing is complete
				</label>
			</div>
			
			<div class="progress-section" style="display: none;">
				<h4>Processing Progress:</h4>
				<div class="progress-bar">
					<div class="progress-fill" style="width: 0%;"></div>
				</div>
				<div class="progress-text">Preparing...</div>
			</div>
		</div>
		
		<style>
		.hyper-viewer-cache-dialog {
			padding: 20px;
			max-width: 500px;
		}
		.cache-options label {
			display: block;
			margin: 10px 0;
			cursor: pointer;
		}
		.cache-options input[type="radio"] {
			margin-right: 8px;
		}
		.processing-options label {
			display: block;
			margin: 8px 0;
			cursor: pointer;
		}
		.progress-bar {
			width: 100%;
			height: 20px;
			background-color: #f0f0f0;
			border-radius: 10px;
			overflow: hidden;
			margin: 10px 0;
		}
		.progress-fill {
			height: 100%;
			background-color: #0082c9;
			transition: width 0.3s ease;
		}
		.progress-text {
			text-align: center;
			font-size: 14px;
			color: #666;
		}
		</style>
	`

	// Show modal dialog
	OC.dialogs.confirmHtml(
		modalContent,
		'Generate HLS Cache',
		function(confirmed) {
			if (confirmed) {
				startCacheGeneration(files)
			}
		},
		true // modal
	)

	// Add event listeners after dialog is shown
	setTimeout(() => {
		// Handle custom location radio button
		const customRadio = document.querySelector('input[name="cache_location"][value="custom"]')
		const customPath = document.getElementById('custom_path')

		if (customRadio && customPath) {
			document.querySelectorAll('input[name="cache_location"]').forEach(radio => {
				radio.addEventListener('change', function() {
					customPath.disabled = this.value !== 'custom'
					if (this.value === 'custom') {
						customPath.focus()
					}
				})
			})
		}
	}, 100)
}

/**
 * Start the actual cache generation process
 *
 * @param files Array of file objects
 */
async function startCacheGeneration(files) {
	console.log('Starting HLS cache generation for:', files.map(f => f.filename))

	// Get selected options
	const cacheLocation = document.querySelector('input[name="cache_location"]:checked')?.value || 'relative'
	const customPath = document.getElementById('custom_path')?.value || ''
	const overwriteExisting = document.getElementById('overwrite_existing')?.checked || false
	const notifyCompletion = document.getElementById('notify_completion')?.checked || true

	const options = {
		cacheLocation,
		customPath,
		overwriteExisting,
		notifyCompletion,
	}

	console.log('Cache generation options:', options)

	// Prepare files data for backend
	const filesData = files.map(file => ({
		filename: file.filename,
		directory: file.context?.dir || file.context?.fileList?.getCurrentDirectory() || '/',
	}))

	try {
		// Show progress dialog
		showProgressDialog(files.length)

		// Send to backend for processing
		const response = await fetch(OC.generateUrl('/apps/hyper_viewer/cache/generate'), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
			},
			body: JSON.stringify({
				files: filesData,
				cacheLocation: options.cacheLocation,
				customPath: options.customPath,
				overwriteExisting: options.overwriteExisting,
				notifyCompletion: options.notifyCompletion,
			}),
		})

		const result = await response.json()

		if (result.success) {
			console.log('HLS cache generation started successfully', result)

			// Start progress tracking
			if (result.jobId) {
				trackProgress(result.jobId, files.length)
			}

			OC.dialogs.info(
				`Cache generation started for ${files.length} file(s).\n\nLocation: ${getCacheLocationDescription(options)}\n\nProcessing will run in the background. ${notifyCompletion ? 'You will be notified when complete.' : ''}`,
				'HLS Cache Generation Started'
			)
		} else {
			throw new Error(result.error || 'Unknown error occurred')
		}

	} catch (error) {
		console.error('Failed to start HLS cache generation:', error)
		OC.dialogs.alert(
			`Failed to start cache generation: ${error.message}`,
			'Error'
		)
	}
}

/**
 * Get human-readable description of cache location
 *
 * @param options
 * @return {string} Description
 */
function getCacheLocationDescription(options) {
	switch (options.cacheLocation) {
	case 'relative':
		return 'Next to each video file (.cached_hls/)'
	case 'home':
		return 'User home directory (~/.cached_hls/)'
	case 'custom':
		return options.customPath || 'Custom location'
	default:
		return 'Unknown location'
	}
}

/**
 * Show progress dialog
 *
 * @param fileCount
 */
function showProgressDialog(fileCount) {
	// TODO: Implement progress dialog
	console.log(`📊 Starting progress tracking for ${fileCount} files`)
}

/**
 * Track progress of cache generation
 *
 * @param jobId
 * @param fileCount
 */
async function trackProgress(jobId, fileCount) {
	console.log(`📈 Tracking progress for job: ${jobId}`)

	const maxAttempts = 240 // 20 minutes max (for large videos)
	let attempts = 0

	const checkProgress = async () => {
		try {
			const response = await fetch(OC.generateUrl('/apps/hyper_viewer/cache/progress'), {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: OC.requestToken,
				},
				body: JSON.stringify({ jobId }),
			})

			const progress = await response.json()
			console.log('📊 Progress update:', progress)

			if (progress.status === 'completed') {
				console.log('✅ HLS cache generation completed!')
				return
			}

			if (progress.status === 'failed') {
				console.error('❌ HLS cache generation failed:', progress.message)
				return
			}

			// Continue tracking if still processing
			attempts++
			if (attempts < maxAttempts) {
				setTimeout(checkProgress, 5000) // Check every 5 seconds
			} else {
				console.log('⏰ Progress tracking timeout reached')
			}

		} catch (error) {
			console.error('Failed to check progress:', error)
		}
	}

	// Start checking progress after a short delay
	setTimeout(checkProgress, 2000)
}

/**
 * Check if HLS cache exists for a video file
 *
 * @param filename
 * @param directory
 */
async function checkHlsCache(filename, directory) {
	try {
		const response = await fetch(OC.generateUrl('/apps/hyper_viewer/cache/check'), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
			},
			body: JSON.stringify({
				filename,
				directory,
			}),
		})

		const result = await response.json()
		return result.exists ? result.cachePath : null

	} catch (error) {
		console.error('Failed to check HLS cache:', error)
		return null
	}
}

/**
 * Play video with HLS if cache exists, otherwise fallback to regular player
 *
 * @param filename
 * @param directory
 * @param context
 */
async function playWithHls(filename, directory, context) {
	console.log(`🎬 Checking HLS cache for: ${filename}`)

	try {
		// Check if HLS cache exists
		const cachePath = await checkHlsCache(filename, directory)

		if (cachePath) {
			console.log(`✅ HLS cache found at: ${cachePath}`)
			// Load Shaka Player with HLS
			loadShakaPlayer(filename, cachePath, context)
		} else {
			console.log(`❌ No HLS cache found for: ${filename}`)
			// Fallback to regular video player or show message
			OC.dialogs.confirm(
				`No HLS cache found for "${filename}".\n\nWould you like to generate HLS cache now?`,
				'HLS Cache Not Found',
				function(confirmed) {
					if (confirmed) {
						openCacheGenerationDialog([{ filename, context }])
					} else {
						// Let default video player handle it
						console.log('🎥 Falling back to default video player')
					}
				}
			)
		}
	} catch (error) {
		console.error('Error checking HLS cache:', error)
		OC.dialogs.alert('Failed to check HLS cache. Using default video player.', 'Error')
	}
}

/**
 * Load Shaka Player with HLS content
 *
 * @param filename
 * @param cachePath
 * @param context
 */
function loadShakaPlayer(filename, cachePath, context) {
	console.log(`🎬 Loading Shaka Player for: ${filename}`)
	console.log(`📁 Cache path: ${cachePath}`)

	// Create full-screen video player modal
	const playerHtml = `
		<div class="hyper-viewer-player">
			<div class="player-header">
				<h3>${filename}</h3>
				<button class="close-player" onclick="closeShakaPlayer()">×</button>
			</div>
			<video id="shaka-video" controls autoplay style="width: 100%; height: 400px; background: #000;">
				Your browser does not support the video tag.
			</video>
			<div class="player-info">
				<p><strong>HLS Cache:</strong> ${cachePath}</p>
				<p><strong>Status:</strong> <span id="player-status">Loading...</span></p>
			</div>
		</div>
		
		<style>
		.hyper-viewer-player {
			padding: 20px;
			max-width: 800px;
			margin: 0 auto;
		}
		.player-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 15px;
		}
		.player-header h3 {
			margin: 0;
			color: #333;
		}
		.close-player {
			background: #ff4444;
			color: white;
			border: none;
			border-radius: 50%;
			width: 30px;
			height: 30px;
			cursor: pointer;
			font-size: 18px;
			line-height: 1;
		}
		.close-player:hover {
			background: #cc0000;
		}
		.player-info {
			margin-top: 15px;
			padding: 10px;
			background: #f5f5f5;
			border-radius: 5px;
			font-size: 14px;
		}
		.player-info p {
			margin: 5px 0;
		}
		#player-status {
			font-weight: bold;
			color: #0082c9;
		}
		</style>
	`

	// Show player in modal
	OC.dialogs.confirmHtml(
		playerHtml,
		`Play: ${filename}`,
		function(confirmed) {
			// Modal closed
			console.log('🎬 Shaka Player modal closed')
		},
		true // modal
	)

	// Initialize Shaka Player after modal is shown
	setTimeout(() => {
		initializeShakaPlayer(cachePath)
	}, 500)
}

/**
 * Initialize Shaka Player with HLS content
 *
 * @param cachePath
 */
async function initializeShakaPlayer(cachePath) {
	const video = document.getElementById('shaka-video')
	const statusElement = document.getElementById('player-status')

	if (!video) {
		console.error('Video element not found')
		return
	}

	// Build HLS manifest URL
	const manifestUrl = OC.generateUrl('/apps/files/ajax/download.php')
		+ '?dir=' + encodeURIComponent(cachePath)
		+ '&files=' + encodeURIComponent('playlist.m3u8')

	console.log(`🎬 Loading HLS manifest: ${manifestUrl}`)

	if (statusElement) {
		statusElement.textContent = 'Loading HLS stream...'
	}

	try {
		// First try native HLS support (Safari, iOS, some Android browsers)
		if (video.canPlayType('application/vnd.apple.mpegurl') || video.canPlayType('application/x-mpegURL')) {
			console.log('🍎 Using native HLS support')
			video.src = manifestUrl
			video.load()
			if (statusElement) {
				statusElement.textContent = 'Playing HLS stream (native)'
				statusElement.style.color = '#00aa00'
			}
			return
		}

		// Since HLS.js also creates blob URLs that violate CSP, 
		// fall back to playing the original video file directly
		console.log('🎬 CSP prevents HLS playback, falling back to original video')
		
		if (statusElement) {
			statusElement.textContent = 'Loading original video...'
		}

		// Extract the original filename from the cache path
		// cachePath format: /Paulius/.cached_hls/MVI_0079
		const videoName = cachePath.split('/').pop() // MVI_0079
		const directory = cachePath.replace('/.cached_hls/' + videoName, '') // /Paulius
		
		// Try common video extensions
		const extensions = ['.MOV', '.mov', '.MP4', '.mp4']
		let originalVideoUrl = null
		
		for (const ext of extensions) {
			const testUrl = OC.generateUrl('/apps/files/ajax/download.php')
				+ '?dir=' + encodeURIComponent(directory)
				+ '&files=' + encodeURIComponent(videoName + ext)
			
			try {
				const response = await fetch(testUrl, { method: 'HEAD' })
				if (response.ok) {
					originalVideoUrl = testUrl
					console.log(`✅ Found original video: ${videoName + ext}`)
					break
				}
			} catch (e) {
				// Continue trying other extensions
			}
		}
		
		if (originalVideoUrl) {
			video.src = originalVideoUrl
			video.load()
			
			if (statusElement) {
				statusElement.textContent = 'Playing original video (CSP fallback)'
				statusElement.style.color = '#ff8800'
			}
		} else {
			throw new Error('Original video file not found')
		}

	} catch (error) {
		console.error('Failed to initialize Shaka Player:', error)

		if (statusElement) {
			statusElement.textContent = 'Failed to load: ' + error.message
			statusElement.style.color = '#ff4444'
		}

		// Fallback to regular video playback
		const videoUrl = OC.generateUrl('/apps/files/ajax/download.php')
			+ '?dir=' + encodeURIComponent(cachePath.replace('/.cached_hls/' + cachePath.split('/').pop(), ''))
			+ '&files=' + encodeURIComponent(cachePath.split('/').pop().replace(/\.[^/.]+$/, '') + '.mov')

		video.src = videoUrl
		video.load()
	}
}

/**
 * Close HLS Player modal
 */
window.closeShakaPlayer = function() {
	// Clean up HLS.js instance
	if (window.currentHlsPlayer) {
		window.currentHlsPlayer.destroy()
		window.currentHlsPlayer = null
	}

	// Clean up legacy Shaka Player instance (if any)
	if (window.currentShakaPlayer) {
		window.currentShakaPlayer.destroy()
		window.currentShakaPlayer = null
	}

	// Close the modal (this is handled by OC.dialogs)
	const modal = document.querySelector('.oc-dialog')
	if (modal) {
		modal.remove()
	}
}

console.log('✅ Hyper Viewer Files integration loaded!')
