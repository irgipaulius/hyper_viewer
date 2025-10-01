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
            const manifestUrl = `/apps/hyper_viewer/hls/${encodedCachePath}/playlist.m3u8`
            console.log('📝 Constructed manifest URL:', manifestUrl)
            
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
                console.error('❌ Shaka load error:', err)
                console.error('❌ Error details:', {
                    code: err.code,
                    category: err.category,
                    severity: err.severity,
                    message: err.message
                })
                OC.dialogs.alert(`Error loading HLS video: ${err.message}`, 'Playback Error')
                console.log('🔄 Falling back to direct video src...')
                video.src = cachePath
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
