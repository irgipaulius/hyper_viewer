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
			openCacheGenerationDialog(filename, context)
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
			openCacheGenerationDialog(filename, context)
		},
	})

	console.log('✅ Hyper Viewer Files integration registered!')
}

/**
 * Open cache generation dialog (simplified for testing)
 *
 * @param filename
 * @param context
 */
function openCacheGenerationDialog(filename, context) {
	console.log('🔧 Opening cache generation dialog for:', filename)
	console.log('📁 File context:', context)

	// Simple alert for now - we'll build a proper dialog later
	alert(`Generate HLS cache for: ${filename}\n\nThis will open a dialog to select cache location and start background processing.`)
}

console.log('✅ Hyper Viewer Files integration loaded!')
