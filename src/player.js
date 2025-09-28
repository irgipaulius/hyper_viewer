/**
 * Dedicated HLS Player for Hyper Viewer
 * This runs in the player window with relaxed CSP
 */

import Hls from 'hls.js'

console.log('🎬 HLS Player loading...')

/**
 * Initialize HLS player with given cache path
 */
async function initializeHlsPlayer(cachePath) {
	const video = document.getElementById('shaka-video')
	const statusElement = document.getElementById('player-status')

	if (!video) {
		console.error('Video element not found')
		return
	}

	// Build HLS manifest URL
	const manifestUrl = '/apps/files/ajax/download.php'
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

		// Use HLS.js with relaxed CSP (blob URLs allowed)
		if (!Hls.isSupported()) {
			throw new Error('HLS.js is not supported in this browser')
		}

		console.log('🎬 Using HLS.js for HLS playback')

		// Create HLS.js instance
		const hls = new Hls({
			debug: false,
			enableWorker: true,
			lowLatencyMode: false,
			backBufferLength: 30,
			maxBufferLength: 60,
			maxMaxBufferLength: 120,
		})

		// Listen for errors
		hls.on(Hls.Events.ERROR, (event, data) => {
			console.error('HLS.js error:', data)
			if (statusElement) {
				statusElement.textContent = 'Error: ' + (data.details || 'HLS playback error')
				statusElement.style.color = '#ff4444'
			}
			
			// Try to recover from some errors
			if (data.fatal) {
				switch (data.type) {
				case Hls.ErrorTypes.NETWORK_ERROR:
					console.log('Trying to recover from network error')
					hls.startLoad()
					break
				case Hls.ErrorTypes.MEDIA_ERROR:
					console.log('Trying to recover from media error')
					hls.recoverMediaError()
					break
				default:
					console.log('Fatal error, destroying HLS instance')
					hls.destroy()
					break
				}
			}
		})

		// Listen for successful manifest loading
		hls.on(Hls.Events.MANIFEST_LOADED, () => {
			console.log('✅ HLS manifest loaded successfully')
			if (statusElement) {
				statusElement.textContent = 'Playing HLS stream (HLS.js)'
				statusElement.style.color = '#00aa00'
			}
		})

		// Attach HLS to video element
		hls.attachMedia(video)

		// Load the manifest
		hls.loadSource(manifestUrl)

		// Store HLS instance for cleanup
		window.currentHlsPlayer = hls

	} catch (error) {
		console.error('Failed to initialize HLS player:', error)

		if (statusElement) {
			statusElement.textContent = 'Failed to load: ' + error.message
			statusElement.style.color = '#ff4444'
		}

		// Fallback to original video file
		try {
			// Extract the original filename from the cache path
			const videoName = cachePath.split('/').pop() // MVI_0079
			const directory = cachePath.replace('/.cached_hls/' + videoName, '') // /Paulius
			
			// Try common video extensions
			const extensions = ['.MOV', '.mov', '.MP4', '.mp4']
			let originalVideoUrl = null
			
			for (const ext of extensions) {
				const testUrl = '/apps/files/ajax/download.php'
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
					statusElement.textContent = 'Playing original video (HLS fallback)'
					statusElement.style.color = '#ff8800'
				}
			} else {
				throw new Error('Original video file not found')
			}
		} catch (fallbackError) {
			console.error('Fallback also failed:', fallbackError)
			if (statusElement) {
				statusElement.textContent = 'Failed to load video: ' + error.message
				statusElement.style.color = '#ff4444'
			}
		}
	}
}

// Make the function globally available
window.initializeHlsPlayer = initializeHlsPlayer

console.log('✅ HLS Player loaded!')
