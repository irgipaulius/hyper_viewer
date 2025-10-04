/**
 * Settings JavaScript for Hyper Viewer
 * Handles cache location configuration UI
 */

console.log('🎬 Hyper Viewer settings script loaded!')

document.addEventListener('DOMContentLoaded', function() {
	console.log('🔧 Hyper Viewer settings DOM ready')

	const settingsSection = document.getElementById('hyper_viewer_settings')
	if (!settingsSection) {
		console.log('⚠️ Hyper Viewer settings section not found')
		return
	}

	console.log('✅ Hyper Viewer settings section found')

	// Add location button
	const addButton = document.getElementById('add-cache-location')
	if (addButton) {
		addButton.addEventListener('click', function() {
			console.log('➕ Adding new cache location')
			addCacheLocation()
		})
	}

	// Save button
	const saveButton = document.getElementById('save-cache-settings')
	if (saveButton) {
		saveButton.addEventListener('click', function() {
			console.log('💾 Saving cache settings')
			saveCacheSettings()
		})
	}

	// Remove location buttons
	document.addEventListener('click', function(e) {
		if (e.target.classList.contains('remove-location')) {
			console.log('🗑️ Removing cache location')
			e.target.closest('.cache-location-item').remove()
		}
	})

	// Prototype features
	initializePrototypeFeatures()
})

/**
 *
 */
function addCacheLocation() {
	const list = document.getElementById('cache-location-list')
	const newIndex = list.children.length

	const newItem = document.createElement('div')
	newItem.className = 'cache-location-item'
	newItem.setAttribute('data-index', newIndex)

	newItem.innerHTML = `
		<input type="text" 
			   class="cache-location-input" 
			   value="" 
			   placeholder="Enter cache path..." />
		<button class="icon-delete remove-location" title="Remove"></button>
	`

	list.appendChild(newItem)
	console.log('✅ Added new cache location input')
}

/**
 *
 */
function saveCacheSettings() {
	const inputs = document.querySelectorAll('.cache-location-input')
	const locations = Array.from(inputs)
		.map(input => input.value.trim())
		.filter(value => value.length > 0)

	console.log('📤 Saving cache locations:', locations)

	// Make AJAX request to save settings
	fetch(OC.generateUrl('/apps/hyper_viewer/settings/cache-locations'), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			requesttoken: OC.requestToken,
		},
		body: JSON.stringify({ locations }),
	})
		.then(response => response.json())
		.then(data => {
			console.log('✅ Cache settings saved successfully:', data)
			OC.Notification.showTemporary('Cache locations saved successfully')
		})
		.catch(error => {
			console.error('❌ Error saving cache settings:', error)
			OC.Notification.showTemporary('Error saving cache locations', { type: 'error' })
		})
}

/**
 * Initialize prototype features
 */
function initializePrototypeFeatures() {
	const liveTranscodeToggle = document.getElementById('live-transcode-enabled')
	const transcodeStatus = document.getElementById('transcode-status')
	
	if (!liveTranscodeToggle) return

	// Load saved setting
	const savedSetting = localStorage.getItem('hyper_viewer_live_transcode_enabled')
	if (savedSetting === 'true') {
		liveTranscodeToggle.checked = true
		showTranscodeStatus()
	}

	// Handle toggle changes
	liveTranscodeToggle.addEventListener('change', function() {
		console.log('⚡ Live transcode toggle changed:', this.checked)
		localStorage.setItem('hyper_viewer_live_transcode_enabled', this.checked.toString())
		
		if (this.checked) {
			showTranscodeStatus()
			updateTranscodeStatus()
		} else {
			transcodeStatus.style.display = 'none'
		}
		
		// Show notification
		const message = this.checked ? 
			'Live transcode mode enabled - .MOV files will stream on-the-fly' : 
			'Live transcode mode disabled - using standard HLS cache'
		OC.Notification.showTemporary(message)
	})

	// Update status periodically when enabled
	if (liveTranscodeToggle.checked) {
		setInterval(updateTranscodeStatus, 5000)
	}
}

/**
 * Show transcode status section
 */
function showTranscodeStatus() {
	const transcodeStatus = document.getElementById('transcode-status')
	if (transcodeStatus) {
		transcodeStatus.style.display = 'block'
	}
}

/**
 * Update transcode status
 */
function updateTranscodeStatus() {
	fetch(OC.generateUrl('/apps/hyper_viewer/api/transcode/status'), {
		method: 'GET',
		headers: {
			requesttoken: OC.requestToken
		}
	})
	.then(response => response.json())
	.then(data => {
		const statusText = document.getElementById('status-text')
		const activeProcesses = document.getElementById('active-processes')
		const maxProcesses = document.getElementById('max-processes')
		
		if (statusText) {
			statusText.textContent = data.active_processes > 0 ? 'Active' : 'Idle'
		}
		if (activeProcesses) {
			activeProcesses.textContent = data.active_processes || 0
		}
		if (maxProcesses) {
			maxProcesses.textContent = data.max_processes || 1
		}
	})
	.catch(error => {
		console.warn('⚠️ Could not fetch transcode status:', error)
	})
}

/**
 * Check if live transcode mode is enabled
 */
window.isLiveTranscodeEnabled = function() {
	return localStorage.getItem('hyper_viewer_live_transcode_enabled') === 'true'
}
