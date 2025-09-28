/**
 * Files app integration for Hyper Viewer (Nextcloud 25 compatible)
 * Adds "Generate Cache" action to MOV files and automatic HLS detection
 */

console.log('🎬 Hyper Viewer Files integration loading...')

// Wait for Files app to be ready
document.addEventListener('DOMContentLoaded', function() {
	// Wait a bit more for Files app to fully initialize
	setTimeout(initializeFilesIntegration, 1000)
})

/**
 *
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

	// Register "Generate Cache" action for MOV files
	window.OCA.Files.fileActions.register(
		'video/quicktime', // MIME type for MOV files
		'Generate HLS Cache',
		OC.PERMISSION_UPDATE,
		function() {
			// Icon - using a simple gear icon
			return OC.imagePath('core', 'actions/settings-dark')
		},
		function(filename, context) {
			console.log('🚀 Generate Cache action triggered for:', filename)
			console.log('📁 Context:', context)

			// Show simple dialog for now
			openCacheGenerationDialog(filename, context)
		}
	)

	// Register "Generate Cache" action for MP4 files
	window.OCA.Files.fileActions.register(
		'video/mp4', // MIME type for MP4 files
		'Generate HLS Cache',
		OC.PERMISSION_UPDATE,
		function() {
			// Icon - using a simple gear icon
			return OC.imagePath('core', 'actions/settings-dark')
		},
		function(filename, context) {
			console.log('🚀 Generate Cache action triggered for:', filename)
			console.log('📁 Context:', context)

			// Show simple dialog for now
			openCacheGenerationDialog(filename, context)
		}
	)

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
