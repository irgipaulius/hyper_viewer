/**
 * Files app integration for Hyper Viewer
 * Adds "Generate Cache" action to MOV files and automatic HLS detection
 */

import { registerFileAction, Permission, Node, FileType } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'

console.log('🎬 Hyper Viewer Files integration loading...')

// Register "Generate Cache" action for MOV files
registerFileAction({
	id: 'hyper-viewer-generate-cache',
	displayName: () => t('hyper_viewer', 'Generate HLS Cache'),
	iconSvgInline: () => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
		<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
	</svg>`,
	
	// Only show for MOV files
	enabled: (nodes) => {
		console.log('🔍 Checking nodes for Generate Cache action:', nodes)
		return nodes.every(node => {
			const isMov = node.mime === 'video/quicktime' || node.basename?.toLowerCase().endsWith('.mov')
			const hasPermission = (node.permissions & Permission.UPDATE) !== 0
			console.log(`📁 ${node.basename}: MOV=${isMov}, Permission=${hasPermission}`)
			return isMov && hasPermission
		})
	},

	// Handle single or multiple file selection
	async exec(node, view, dir) {
		console.log('🚀 Generate Cache action triggered for:', node.basename)
		
		// Open cache generation dialog
		await openCacheGenerationDialog([node])
		return null
	},

	// Handle bulk selection
	async execBatch(nodes, view, dir) {
		console.log('🚀 Generate Cache BULK action triggered for:', nodes.map(n => n.basename))
		
		// Filter for MOV files and expand directories
		const movFiles = await expandAndFilterMovFiles(nodes)
		console.log('📹 Found MOV files:', movFiles.map(n => n.basename))
		
		// Open cache generation dialog
		await openCacheGenerationDialog(movFiles)
		return nodes.map(() => null)
	},

	order: 10
})

// Register automatic HLS detection for MOV files
registerFileAction({
	id: 'hyper-viewer-auto-play',
	displayName: () => t('hyper_viewer', 'Play with HLS'),
	iconSvgInline: () => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
		<path d="M8 5v14l11-7z"/>
	</svg>`,
	
	// Only show for MOV files that have HLS cache
	enabled: async (nodes) => {
		if (nodes.length !== 1) return false
		const node = nodes[0]
		
		const isMov = node.mime === 'video/quicktime' || node.basename?.toLowerCase().endsWith('.mov')
		if (!isMov) return false
		
		// Check if HLS cache exists
		const hasCache = await checkHlsCache(node)
		console.log(`🎥 ${node.basename}: HLS cache exists = ${hasCache}`)
		
		return hasCache
	},

	// Play with Shaka Player
	async exec(node, view, dir) {
		console.log('▶️ Auto-play HLS for:', node.basename)
		
		// Load Shaka Player with HLS cache
		await loadShakaPlayer(node)
		return null
	},

	// Make this the default action (higher order = higher priority)
	order: 100,
	default: true
})

/**
 * Check if HLS cache exists for a MOV file
 */
async function checkHlsCache(node) {
	try {
		// Get user's cache locations from settings
		const cacheLocations = await getUserCacheLocations()
		
		// Check each location for .m3u8 file
		for (const location of cacheLocations) {
			const cachePath = resolveCachePath(location, node)
			const manifestExists = await checkFileExists(cachePath + '/master.m3u8')
			
			if (manifestExists) {
				console.log(`✅ Found HLS cache at: ${cachePath}`)
				return true
			}
		}
		
		console.log(`❌ No HLS cache found for: ${node.basename}`)
		return false
	} catch (error) {
		console.error('Error checking HLS cache:', error)
		return false
	}
}

/**
 * Get user's configured cache locations
 */
async function getUserCacheLocations() {
	try {
		const response = await fetch('/apps/hyper_viewer/settings/cache-locations')
		const data = await response.json()
		return data.locations || ['./.cached_hls/', '~/.cached_hls/', '/mnt/cache/.cached_hls/']
	} catch (error) {
		console.error('Error fetching cache locations:', error)
		return ['./.cached_hls/', '~/.cached_hls/', '/mnt/cache/.cached_hls/']
	}
}

/**
 * Resolve cache path based on location pattern and node
 */
function resolveCachePath(location, node) {
	const filename = node.basename.replace(/\.[^/.]+$/, '') // Remove extension
	
	if (location.startsWith('./')) {
		// Relative to video file
		return node.dirname + '/' + location.substring(2) + filename
	} else if (location.startsWith('~/')) {
		// User home directory
		return location.replace('~', '/home/' + OC.getCurrentUser().uid) + filename
	} else {
		// Absolute path
		return location + filename
	}
}

/**
 * Check if file exists (simplified for prototype)
 */
async function checkFileExists(path) {
	// For now, return false - we'll implement proper file checking later
	// This would need to use Nextcloud's WebDAV API or a custom endpoint
	console.log(`🔍 Checking if file exists: ${path}`)
	return false
}

/**
 * Expand directories and filter for MOV files
 */
async function expandAndFilterMovFiles(nodes) {
	const movFiles = []
	
	for (const node of nodes) {
		if (node.type === FileType.File) {
			// Check if it's a MOV file
			const isMov = node.mime === 'video/quicktime' || node.basename?.toLowerCase().endsWith('.mov')
			if (isMov) {
				movFiles.push(node)
			}
		} else if (node.type === FileType.Folder) {
			// TODO: Recursively scan directory for MOV files
			// For now, just log that we'd scan this directory
			console.log(`📁 Would scan directory: ${node.path}`)
		}
	}
	
	return movFiles
}

/**
 * Open cache generation dialog
 */
async function openCacheGenerationDialog(movFiles) {
	console.log('🔧 Opening cache generation dialog for files:', movFiles.map(f => f.basename))
	
	// TODO: Implement modal dialog
	// For now, just show an alert
	const fileNames = movFiles.map(f => f.basename).join(', ')
	alert(`Generate HLS cache for: ${fileNames}\n\nThis will open a dialog to select cache location and start background processing.`)
}

/**
 * Load Shaka Player for HLS playback
 */
async function loadShakaPlayer(node) {
	console.log('🎬 Loading Shaka Player for:', node.basename)
	
	// TODO: Implement Shaka Player loading
	// For now, just show an alert
	alert(`Would load Shaka Player for: ${node.basename}\n\nThis will detect HLS cache and start playback.`)
}

console.log('✅ Hyper Viewer Files integration loaded!')
