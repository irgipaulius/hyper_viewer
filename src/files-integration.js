/**
 * Files app integration for Hyper Viewer (Nextcloud 25 compatible)
 * Adds "Generate HLS Cache" action to MOV and MP4 files
 */

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
function startCacheGeneration(files) {
	console.log('🚀 Starting HLS cache generation for:', files.map(f => f.filename))

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

	console.log('⚙️ Cache generation options:', options)

	// TODO: Send to backend for processing
	// For now, show a confirmation
	OC.dialogs.info(
		`Cache generation started for ${files.length} file(s).\n\nLocation: ${getCacheLocationDescription(options)}\n\nProcessing will run in the background. ${notifyCompletion ? 'You will be notified when complete.' : ''}`,
		'HLS Cache Generation Started'
	)
}

/**
 * Get human-readable description of cache location
 *
 * @param options Cache generation options
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

console.log('✅ Hyper Viewer Files integration loaded!')
