/**
 * Settings JavaScript for Hyper Viewer
 * Handles cache location configuration UI and dashboard monitoring
 */

console.log('🎬 Hyper Viewer settings script loaded!')

let refreshInterval = null
let statsInterval = null

document.addEventListener('DOMContentLoaded', function() {
	// Initialize dashboard
	initializeDashboard()
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
			console.error('❌ Failed to save cache locations:', error)
			OC.Notification.showTemporary('Failed to save cache locations')
		})
}

/**
 * Initialize dashboard monitoring
 */
function initializeDashboard() {
	console.log('📊 Initializing dashboard...')
	
	// Initial data load
	refreshStatistics()
	refreshActiveJobs()
	refreshAutoGeneration()
	
	// Set up auto-refresh every 10 seconds
	refreshInterval = setInterval(() => {
		refreshActiveJobs()
	}, 10000)
	
	statsInterval = setInterval(() => {
		refreshStatistics()
		refreshAutoGeneration()
	}, 10000)
}

/**
 * Refresh statistics
 */
function refreshStatistics() {
	fetch(OC.generateUrl('/apps/hyper_viewer/api/jobs/statistics'))
		.then(response => response.json())
		.then(data => {
			const stats = data.stats || {}
			document.getElementById('stat-active').textContent = stats.activeJobs || 0
			document.getElementById('stat-autogen').textContent = stats.autoGenDirectories || 0
			document.getElementById('stat-completed').textContent = stats.completedJobs || 0
			document.getElementById('stat-pending').textContent = stats.pendingJobs || 0
		})
		.catch(error => {
			console.error('❌ Failed to fetch statistics:', error)
		})
}

/**
 * Refresh active jobs
 */
function refreshActiveJobs() {
	fetch(OC.generateUrl('/apps/hyper_viewer/api/jobs/active'))
		.then(response => response.json())
		.then(data => {
			const jobs = data.activeJobs || []
			const container = document.getElementById('active-jobs-container')
			
			if (jobs.length === 0) {
				container.innerHTML = `
					<div class="empty-state">
						<div class="empty-icon">😴</div>
						<p>No active jobs running</p>
					</div>
				`
			} else {
				container.innerHTML = jobs.map(job => `
					<div class="job-card">
						<div class="job-header">
							<div class="job-filename">${escapeHtml(job.filename)}</div>
							<div class="job-status processing">${escapeHtml(job.status)}</div>
						</div>
						<div class="job-progress">
							<div class="progress-bar">
								<div class="progress-fill" style="width: ${job.progress}%"></div>
							</div>
							<div class="progress-text">${job.progress}%</div>
						</div>
						<div class="job-details">
							<span class="detail-item">⏱️ ${escapeHtml(job.time)}</span>
							<span class="detail-item">🎬 ${job.frame} frames</span>
							<span class="detail-item">⚡ ${escapeHtml(job.speed)}</span>
							<span class="detail-item">📺 ${escapeHtml(job.fps)} fps</span>
							${job.cacheSize ? `<span class="detail-item">💾 ${escapeHtml(job.cacheSize)}</span>` : ''}
						</div>
						<div class="job-resolutions">
							${job.resolutions.map(res => `<span class="resolution-tag">${escapeHtml(res)}</span>`).join('')}
						</div>
					</div>
				`).join('')
			}
		})
		.catch(error => {
			console.error('❌ Failed to fetch active jobs:', error)
		})
}

/**
 * Refresh auto-generation directories
 */
function refreshAutoGeneration() {
	fetch(OC.generateUrl('/apps/hyper_viewer/api/auto-generation'))
		.then(response => response.json())
		.then(data => {
			const dirs = data.autoGenDirs || []
			const container = document.getElementById('autogen-container')
			
			if (dirs.length === 0) {
				container.innerHTML = `
					<div class="empty-state">
						<div class="empty-icon">📁</div>
						<p>No auto-generation directories configured</p>
					</div>
				`
			} else {
				container.innerHTML = dirs.map(dir => `
					<div class="auto-gen-card">
						<div class="auto-gen-header">
							<div class="auto-gen-path">📁 ${escapeHtml(dir.directory)}</div>
							<div class="auto-gen-status ${dir.enabled ? 'enabled' : 'disabled'}">
								${dir.enabled ? 'Enabled' : 'Disabled'}
							</div>
						</div>
						<div class="auto-gen-details">
							<span class="detail-item">📍 ${escapeHtml(dir.cacheLocation)}</span>
							<span class="detail-item">📅 ${formatDate(dir.registeredAt)}</span>
						</div>
						<div class="auto-gen-resolutions">
							${dir.resolutions.map(res => `<span class="resolution-tag">${escapeHtml(res)}</span>`).join('')}
						</div>
						<div class="auto-gen-actions">
							<button class="button" onclick="removeAutoGeneration('${escapeHtml(dir.configKey)}')">
								🗑️ Remove
							</button>
						</div>
					</div>
				`).join('')
			}
		})
		.catch(error => {
			console.error('❌ Failed to fetch auto-generation settings:', error)
		})
}

/**
 * Remove auto-generation directory
 */
window.removeAutoGeneration = function(configKey) {
	if (!confirm('Remove this auto-generation directory?')) {
		return
	}
	
	fetch(OC.generateUrl('/apps/hyper_viewer/api/auto-generation/' + encodeURIComponent(configKey)), {
		method: 'DELETE'
	})
		.then(response => response.json())
		.then(data => {
			if (data.success) {
				OC.Notification.showTemporary('Auto-generation directory removed')
				refreshAutoGeneration()
				refreshStatistics()
			} else {
				OC.Notification.showTemporary('Failed to remove directory')
			}
		})
		.catch(error => {
			console.error('❌ Failed to remove auto-generation:', error)
			OC.Notification.showTemporary('Failed to remove directory')
		})
}

/**
 * Format date for display
 */
function formatDate(timestamp) {
	if (!timestamp) return 'Unknown'
	const date = new Date(timestamp * 1000)
	return date.toLocaleDateString() + ' ' + date.toLocaleTimeString()
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(text) {
	if (!text) return ''
	const div = document.createElement('div')
	div.textContent = text
	return div.innerHTML
}
