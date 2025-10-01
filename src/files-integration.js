/**
 * Files app integration for Hyper Viewer (Nextcloud 25 compatible)
 * Adds "Generate HLS Cache" action to MOV and MP4 files
 */

import shaka from 'shaka-player/dist/shaka-player.ui.js'
import 'shaka-player/dist/controls.css'

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

	// Register "Generate HLS Cache" action for directories
	OCA.Files.fileActions.registerAction({
		name: 'generateHlsCacheDirectory',
		displayName: t('hyper_viewer', 'Generate HLS Cache (Directory)'),
		mime: 'httpd/unix-directory',
		permissions: OC.PERMISSION_UPDATE,
		iconClass: 'icon-category-multimedia',
		actionHandler(filename, context) {
			console.log('🚀 Generate HLS Cache action triggered for directory:', filename)
			console.log('📁 Context:', context)
			openDirectoryCacheGenerationDialog(filename, context)
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

	// Create modal HTML content with cleaner UI
	const modalContent = `
		<div class="hyper-viewer-cache-dialog">
			<h3>Generate HLS Cache</h3>
			<p class="file-list"><strong>Files:</strong> ${fileList}</p>
			
			<div class="section">
				<label class="section-title">Cache Location</label>
				<div class="radio-group">
					<label class="radio-option">
						<input type="radio" name="cache_location" value="relative" checked>
						<span>Next to video files</span>
					</label>
					<label class="radio-option">
						<input type="radio" name="cache_location" value="home">
						<span>Home directory</span>
					</label>
					<label class="radio-option">
						<input type="radio" name="cache_location" value="custom">
						<span>Custom path</span>
						<input type="text" id="custom_path" placeholder="/mnt/cache/.cached_hls/" disabled>
					</label>
				</div>
			</div>
			
			<div class="section">
				<label class="section-title">Resolution Renditions</label>
				<div class="checkbox-group">
					<label class="checkbox-option">
						<input type="checkbox" name="resolution" value="1080p">
						<span>1080p (4000k) - Full HD</span>
					</label>
					<label class="checkbox-option">
						<input type="checkbox" name="resolution" value="720p" checked>
						<span>720p (2000k) - HD</span>
					</label>
					<label class="checkbox-option">
						<input type="checkbox" name="resolution" value="480p" checked>
						<span>480p (800k) - SD</span>
					</label>
					<label class="checkbox-option">
						<input type="checkbox" name="resolution" value="360p">
						<span>360p (500k) - Low</span>
					</label>
					<label class="checkbox-option">
						<input type="checkbox" name="resolution" value="240p" checked>
						<span>240p (300k) - Mobile</span>
					</label>
				</div>
			</div>
			
			<div class="section">
				<label class="checkbox-option">
					<input type="checkbox" id="overwrite_existing" checked>
					<span>Overwrite existing cache</span>
				</label>
			</div>
		</div>
		
		<style>
		.hyper-viewer-cache-dialog {
			padding: 20px;
			max-width: 450px;
			font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
		}
		.hyper-viewer-cache-dialog h3 {
			margin: 0 0 15px 0;
			color: #333;
			font-size: 18px;
		}
		.file-list {
			margin: 0 0 20px 0;
			padding: 10px;
			background: #f8f9fa;
			border-radius: 6px;
			font-size: 14px;
			color: #666;
		}
		.section {
			margin-bottom: 20px;
		}
		.section-title {
			display: block;
			font-weight: 600;
			color: #333;
			margin-bottom: 10px;
			font-size: 14px;
		}
		.radio-group, .checkbox-group {
			display: flex;
			flex-direction: column;
			gap: 8px;
		}
		.radio-option, .checkbox-option {
			display: flex;
			align-items: center;
			cursor: pointer;
			padding: 8px;
			border-radius: 4px;
			transition: background-color 0.2s;
		}
		.radio-option:hover, .checkbox-option:hover {
			background-color: #f5f5f5;
		}
		.radio-option input, .checkbox-option input {
			margin: 0 10px 0 0;
		}
		.radio-option span, .checkbox-option span {
			font-size: 14px;
			color: #333;
		}
		#custom_path {
			margin-left: 24px;
			margin-top: 5px;
			width: calc(100% - 24px);
			padding: 6px 8px;
			border: 1px solid #ddd;
			border-radius: 4px;
			font-size: 13px;
		}
		#custom_path:disabled {
			background-color: #f5f5f5;
			color: #999;
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
 * Open directory cache generation dialog with recursive scanning and auto-generation options
 *
 * @param directoryName Name of the directory
 * @param context Directory context from Files app
 */
async function openDirectoryCacheGenerationDialog(directoryName, context) {
	console.log('🔧 Opening directory cache generation dialog for:', directoryName)

	const directory = context?.dir || context?.fileList?.getCurrentDirectory() || '/'
	const fullPath = directory === '/' ? `/${directoryName}` : `${directory}/${directoryName}`

	// Discover video files in directory
	console.log('🔍 Discovering video files in directory:', fullPath)
	const videoFiles = await discoverVideoFilesInDirectory(fullPath)
	
	if (videoFiles.length === 0) {
		OC.dialogs.alert('No video files (MOV/MP4) found in this directory.', 'No Videos Found')
		return
	}

	const fileList = videoFiles.map(f => f.filename).join(', ')
	const fileCount = videoFiles.length

	// Create modal HTML content for directory processing
	const modalContent = `
		<div class="hyper-viewer-cache-dialog">
			<h3>Generate HLS Cache (Directory)</h3>
			<p class="directory-info"><strong>Directory:</strong> ${fullPath}</p>
			<p class="file-list"><strong>Found ${fileCount} video files:</strong><br>${fileList}</p>
			
			<div class="section">
				<label class="section-title">Cache Location</label>
				<div class="radio-group">
					<label class="radio-option">
						<input type="radio" name="cache_location" value="relative" checked>
						<span>Next to video files</span>
					</label>
					<label class="radio-option">
						<input type="radio" name="cache_location" value="home">
						<span>Home directory</span>
					</label>
					<label class="radio-option">
						<input type="radio" name="cache_location" value="custom">
						<span>Custom path</span>
						<input type="text" id="custom_path" placeholder="/mnt/cache/.cached_hls/" disabled>
					</label>
				</div>
			</div>
			
			<div class="section">
				<label class="section-title">Resolution Renditions</label>
				<div class="checkbox-group">
					<label class="checkbox-option">
						<input type="checkbox" name="resolution" value="1080p">
						<span>1080p (4000k) - Full HD</span>
					</label>
					<label class="checkbox-option">
						<input type="checkbox" name="resolution" value="720p" checked>
						<span>720p (2000k) - HD</span>
					</label>
					<label class="checkbox-option">
						<input type="checkbox" name="resolution" value="480p" checked>
						<span>480p (800k) - SD</span>
					</label>
					<label class="checkbox-option">
						<input type="checkbox" name="resolution" value="360p">
						<span>360p (500k) - Low</span>
					</label>
					<label class="checkbox-option">
						<input type="checkbox" name="resolution" value="240p" checked>
						<span>240p (300k) - Mobile</span>
					</label>
				</div>
			</div>
			
			<div class="section">
				<label class="checkbox-option">
					<input type="checkbox" id="overwrite_existing" checked>
					<span>Overwrite existing cache</span>
				</label>
				<label class="checkbox-option">
					<input type="checkbox" id="enable_auto_generation">
					<span>Enable auto-generation for new videos in this directory</span>
				</label>
			</div>
		</div>
		
		<style>
		.hyper-viewer-cache-dialog {
			padding: 20px;
			max-width: 500px;
			font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
		}
		.hyper-viewer-cache-dialog h3 {
			margin: 0 0 15px 0;
			color: #333;
			font-size: 18px;
		}
		.directory-info {
			margin: 0 0 10px 0;
			padding: 8px;
			background: #e3f2fd;
			border-radius: 6px;
			font-size: 14px;
			color: #1976d2;
			font-weight: 500;
		}
		.file-list {
			margin: 0 0 20px 0;
			padding: 10px;
			background: #f8f9fa;
			border-radius: 6px;
			font-size: 13px;
			color: #666;
			max-height: 120px;
			overflow-y: auto;
		}
		.section {
			margin-bottom: 20px;
		}
		.section-title {
			display: block;
			font-weight: 600;
			color: #333;
			margin-bottom: 10px;
			font-size: 14px;
		}
		.radio-group, .checkbox-group {
			display: flex;
			flex-direction: column;
			gap: 8px;
		}
		.radio-option, .checkbox-option {
			display: flex;
			align-items: center;
			cursor: pointer;
			padding: 8px;
			border-radius: 4px;
			transition: background-color 0.2s;
		}
		.radio-option:hover, .checkbox-option:hover {
			background-color: #f5f5f5;
		}
		.radio-option input, .checkbox-option input {
			margin: 0 10px 0 0;
		}
		.radio-option span, .checkbox-option span {
			font-size: 14px;
			color: #333;
		}
		#custom_path {
			margin-left: 24px;
			margin-top: 5px;
			width: calc(100% - 24px);
			padding: 6px 8px;
			border: 1px solid #ddd;
			border-radius: 4px;
			font-size: 13px;
		}
		#custom_path:disabled {
			background-color: #f5f5f5;
			color: #999;
		}
		</style>
	`

	// Show modal dialog
	OC.dialogs.confirmHtml(
		modalContent,
		'Generate HLS Cache (Directory)',
		function(confirmed) {
			if (confirmed) {
				startDirectoryCacheGeneration(videoFiles, fullPath)
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
 * Discover video files recursively in a directory
 *
 * @param directoryPath Path to the directory to scan
 * @return Array of video file objects
 */
async function discoverVideoFilesInDirectory(directoryPath) {
	try {
		const response = await fetch(OC.generateUrl('/apps/hyper_viewer/cache/discover-videos'), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
			},
			body: JSON.stringify({
				directory: directoryPath
			}),
		})

		const result = await response.json()
		
		if (result.success) {
			return result.files || []
		} else {
			throw new Error(result.error || 'Failed to discover video files')
		}
	} catch (error) {
		console.error('Failed to discover video files:', error)
		OC.dialogs.alert(`Failed to scan directory: ${error.message}`, 'Discovery Error')
		return []
	}
}

/**
 * Start directory cache generation process
 *
 * @param videoFiles Array of video file objects
 * @param directoryPath Full path to the directory
 */
async function startDirectoryCacheGeneration(videoFiles, directoryPath) {
	console.log('Starting directory HLS cache generation for:', directoryPath)

	// Get selected options (same as regular dialog)
	const cacheLocation = document.querySelector('input[name="cache_location"]:checked')?.value || 'relative'
	const customPath = document.getElementById('custom_path')?.value || ''
	const overwriteExisting = document.getElementById('overwrite_existing')?.checked || false
	const enableAutoGeneration = document.getElementById('enable_auto_generation')?.checked || false
	
	// Get selected resolutions
	const selectedResolutions = Array.from(document.querySelectorAll('input[name="resolution"]:checked'))
		.map(checkbox => checkbox.value)
	
	// Default to 720p, 480p, 240p if none selected
	const resolutions = selectedResolutions.length > 0 ? selectedResolutions : ['720p', '480p', '240p']

	const options = {
		cacheLocation,
		customPath,
		overwriteExisting,
		resolutions,
		enableAutoGeneration,
		directoryPath
	}

	console.log('Directory cache generation options:', options)

	try {
		// Start cache generation for all discovered files
		await startCacheGeneration(videoFiles)

		// If auto-generation is enabled, register the directory for monitoring
		if (enableAutoGeneration) {
			await registerDirectoryForAutoGeneration(directoryPath, options)
		}

	} catch (error) {
		console.error('Failed to start directory cache generation:', error)
		OC.dialogs.alert(`Failed to start directory processing: ${error.message}`, 'Processing Error')
	}
}

/**
 * Register directory for automatic HLS generation monitoring
 *
 * @param directoryPath Path to monitor
 * @param options Generation options to use for new files
 */
async function registerDirectoryForAutoGeneration(directoryPath, options) {
	try {
		const response = await fetch(OC.generateUrl('/apps/hyper_viewer/cache/register-auto-generation'), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
			},
			body: JSON.stringify({
				directory: directoryPath,
				options
			}),
		})

		const result = await response.json()
		
		if (result.success) {
			console.log('Directory registered for auto-generation:', directoryPath)
			OC.dialogs.info(
				`Directory "${directoryPath}" has been registered for automatic HLS generation.\n\nNew video files added to this directory will automatically have HLS cache generated.`,
				'Auto-Generation Enabled'
			)
		} else {
			throw new Error(result.error || 'Failed to register directory')
		}
	} catch (error) {
		console.error('Failed to register directory for auto-generation:', error)
		OC.dialogs.alert(`Failed to enable auto-generation: ${error.message}`, 'Auto-Generation Error')
	}
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
	
	// Get selected resolutions
	const selectedResolutions = Array.from(document.querySelectorAll('input[name="resolution"]:checked'))
		.map(checkbox => checkbox.value)
	
	// Default to 720p, 480p, 240p if none selected
	const resolutions = selectedResolutions.length > 0 ? selectedResolutions : ['720p', '480p', '240p']

	const options = {
		cacheLocation,
		customPath,
		overwriteExisting,
		resolutions,
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
				resolutions: options.resolutions,
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
				`Cache generation started for ${files.length} file(s).\n\nLocation: ${getCacheLocationDescription(options)}\n\nResolutions: ${options.resolutions.join(', ')}\n\nProcessing will run in the background.`,
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

function loadShakaPlayer(filename, cachePath, context) {
    console.log(`🎬 Play with HLS triggered for: ${filename}`)
    console.log(`📁 Cache path: ${cachePath}`)
    console.log('🎯 Context:', context)

    // Create unique video ID to avoid conflicts
    const videoId = `hyperVideo_${Date.now()}`
    let shakaPlayer = null
    let shakaUI = null

    // Create modal container
    const modal = document.createElement('div')
    modal.className = 'hyper-viewer-modal'
    modal.innerHTML = `
        <div class="hyper-viewer-backdrop"></div>
        <div class="hyper-viewer-content">
            <button class="hyper-viewer-close">×</button>
            <video id="${videoId}" width="100%" height="auto" autoplay></video>
        </div>
        <style>
            .hyper-viewer-modal {
                position: fixed; top: 0; left: 0; right: 0; bottom: 0;
                z-index: 9999; display: flex; align-items: center; justify-content: center;
            }
            .hyper-viewer-backdrop {
                position: absolute; top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(0,0,0,0.7);
            }
            .hyper-viewer-content {
                position: relative; background: #000; padding: 10px; border-radius: 8px;
                width: 80%; max-width: 960px;
            }
            .hyper-viewer-close {
                position: absolute; top: 5px; right: 10px; font-size: 20px;
                background: transparent; border: none; color: white; cursor: pointer;
            }
            .shaka-video-container {
                width: 100%; height: auto; position: relative;
            }
        </style>
    `
    document.body.appendChild(modal)

    // Get video element from modal
    const video = modal.querySelector(`#${videoId}`)

    // Close button with cleanup
    modal.querySelector('.hyper-viewer-close').onclick = () => {
        if (shakaUI) {
            shakaUI.destroy()
        }
        if (shakaPlayer) {
            shakaPlayer.destroy()
        }
        modal.remove()
    }

    // Initialize Shaka Player
    console.log('🔍 Checking Shaka Player availability...')
    if (shaka) {
        console.log('✅ Shaka Player library found, initializing...')
        try {
            console.log('🔧 Installing Shaka polyfills...')
            shaka.polyfill.installAll()
            
            console.log('🎬 Creating Shaka Player...')
            shakaPlayer = new shaka.Player(video)
            
            // Construct HLS manifest URL using our app's HLS serving endpoint
            // Convert cache path like "/Paulius/.cached_hls/MVI_0079" to proper URL
            const encodedCachePath = encodeURIComponent(cachePath)
            // Try master.m3u8 first (adaptive streaming), fallback to playlist.m3u8 (legacy)
            const manifestUrl = `/apps/hyper_viewer/hls/${encodedCachePath}/master.m3u8`
            console.log('📝 Constructed adaptive manifest URL:', manifestUrl)
            
            console.log('⏳ Loading HLS manifest...')
            shakaPlayer.load(manifestUrl).then(() => {
                console.log('✅ Shaka successfully loaded HLS video:', manifestUrl)
                console.log('🎥 Video should now be playing')
                
                // Initialize UI after video loads successfully
                console.log('🎨 Initializing Shaka UI after video load...')
                setTimeout(() => {
                    try {
                        // Create a container div for the UI
                        const videoContainer = document.createElement('div')
                        videoContainer.className = 'shaka-video-container'
                        video.parentElement.insertBefore(videoContainer, video)
                        videoContainer.appendChild(video)
                        
                        // Initialize UI with minimal configuration
                        shakaUI = new shaka.ui.Overlay(shakaPlayer, videoContainer, video)
                        
                        // Configure UI after creation
                        const config = {
                            controlPanelElements: [
                                'play_pause',
                                'time_and_duration',
                                'mute',
                                'volume',
                                'fullscreen'
                            ]
                        }
                        shakaUI.configure(config)
                        console.log('✅ Shaka Player with UI created successfully')
                    } catch (uiError) {
                        console.error('❌ Shaka UI creation failed:', uiError)
                        console.log('🔄 Continuing with basic player (no custom UI)')
                        // Add basic video controls as fallback
                        video.controls = true
                    }
                }, 500)
            }).catch(err => {
                console.error('❌ Shaka load error for master.m3u8:', err)
                console.log('🔄 Trying fallback to playlist.m3u8...')
                
                // Try fallback to legacy single-bitrate playlist
                const fallbackUrl = `/apps/hyper_viewer/hls/${encodedCachePath}/playlist.m3u8`
                return shakaPlayer.load(fallbackUrl).then(() => {
                    console.log('✅ Fallback playlist loaded successfully:', fallbackUrl)
                }).catch(fallbackErr => {
                    console.error('❌ Both master.m3u8 and playlist.m3u8 failed:', fallbackErr)
                    console.error('❌ Error details:', {
                        code: fallbackErr.code,
                        category: fallbackErr.category,
                        severity: fallbackErr.severity,
                        message: fallbackErr.message
                    })
                    OC.dialogs.alert(`Error loading HLS video: ${fallbackErr.message}`, 'Playback Error')
                    throw fallbackErr
                })
            })
        } catch (error) {
            console.error('❌ Shaka initialization error:', error)
            console.error('❌ Error stack:', error.stack)
            console.log('🔄 Falling back to direct video src...')
            // Fallback to direct video
            video.src = cachePath
        }
    } else {
        console.warn('⚠️ Shaka Player not available, using fallback')
        console.log('🔄 Setting video src directly:', cachePath)
        // Fallback raw video tag
        video.src = cachePath
    }
}
