<template>
	<div class="hyper-viewer-dashboard">
		<!-- Header -->
		<div class="dashboard-header">
			<div class="header-logo">
				<svg
					xmlns="http://www.w3.org/2000/svg"
					viewBox="0 0 24 24"
					fill="none"
					stroke="currentColor"
					stroke-width="2"
					stroke-linecap="round"
					stroke-linejoin="round">
					<path d="M23 7l-7 5 7 5V7z" />
					<rect
						x="1"
						y="5"
						width="15"
						height="14"
						rx="2"
						ry="2" />
					<line
						x1="5"
						y1="9"
						x2="12"
						y2="9" />
					<line
						x1="5"
						y1="13"
						x2="12"
						y2="13" />
				</svg>
			</div>
			<h1>Hyper Viewer Management</h1>
			<p>Monitor HLS generation jobs and manage auto-generation settings</p>
			<button class="refresh-btn" :disabled="loading" @click="refreshData">
				<span v-if="loading">🔄</span>
				<span v-else>↻</span>
				Refresh
			</button>
		</div>

		<!-- Quick Navigation -->
		<div class="quick-nav">
			<button class="nav-btn" @click="scrollToSection('stats')">
				📊 Stats
			</button>
			<button class="nav-btn" @click="scrollToSection('active-jobs')">
				🔥 Active
			</button>
			<button class="nav-btn" @click="scrollToSection('auto-gen')">
				🤖 Auto-Gen
			</button>
			<button class="nav-btn" @click="scrollToSection('system-info')">
				ℹ️ System
			</button>
		</div>

		<!-- Statistics Cards -->
		<div id="stats" class="stats-grid">
			<div class="stat-card">
				<div class="stat-icon">
					⚡
				</div>
				<div class="stat-content">
					<div class="stat-number">
						{{ statistics.activeJobs }}
					</div>
					<div class="stat-label">
						Active Jobs
					</div>
				</div>
			</div>
			<div class="stat-card">
				<div class="stat-icon">
					🤖
				</div>
				<div class="stat-content">
					<div class="stat-number">
						{{ statistics.autoGenDirectories }}
					</div>
					<div class="stat-label">
						Auto-Gen Dirs
					</div>
				</div>
			</div>
			<div class="stat-card clickable" @click="toggleCompletedJobs">
				<div class="stat-icon">
					✅
				</div>
				<div class="stat-content">
					<div class="stat-number">
						{{ statistics.completedJobs }}
					</div>
					<div class="stat-label">
						Completed
						<span class="toggle-indicator">{{ showCompletedJobs ? '▼' : '▶' }}</span>
					</div>
				</div>
			</div>
			<div class="stat-card">
				<div class="stat-icon">
					⏳
				</div>
				<div class="stat-content">
					<div class="stat-number">
						{{ statistics.pendingJobs }}
					</div>
					<div class="stat-label">
						Pending
					</div>
				</div>
			</div>
		</div>

		<!-- Completed Jobs List (Collapsible) -->
		<div v-if="showCompletedJobs && statistics.completedJobFilenames && statistics.completedJobFilenames.length > 0" class="completed-jobs-section">
			<div class="completed-jobs-header">
				<h3>✅ Completed Jobs ({{ statistics.completedJobFilenames.length }})</h3>
				<button @click="showCompletedJobs = false" class="close-btn">✕</button>
			</div>
			<div class="completed-jobs-list">
				<div 
					v-for="filename in statistics.completedJobFilenames" 
					:key="filename"
					class="completed-job-item"
					:title="filename">
					{{ filename }}
				</div>
			</div>
		</div>

		<!-- Active Jobs Section -->
		<div id="active-jobs" class="section">
			<h2>🔥 Active Jobs</h2>
			<div v-if="activeJobs.length === 0" class="empty-state">
				<div class="empty-icon">
					😴
				</div>
				<p>No active jobs running</p>
			</div>
			<div v-else class="jobs-list">
				<div v-for="job in activeJobs" :key="job.cachePath" class="job-card">
					<div class="job-header">
						<div class="job-filename">
							{{ job.filename }}
						</div>
						<div class="job-status processing">
							{{ job.status }}
						</div>
					</div>
					<div class="job-progress">
						<div class="progress-bar">
							<div class="progress-fill" :style="{ width: job.progress + '%' }" />
						</div>
						<div class="progress-text">
							{{ job.progress }}%
						</div>
					</div>
					<div class="job-details">
						<span class="detail-item">⏱️ {{ job.time }}</span>
						<span class="detail-item">🎬 {{ job.frame }} frames</span>
						<span class="detail-item">⚡ {{ job.speed }}</span>
						<span class="detail-item">📺 {{ job.fps }} fps</span>
						<span v-if="job.cacheSize" class="detail-item">💾 {{ job.cacheSize }}</span>
					</div>
					<div class="job-resolutions">
						<span v-for="res in job.resolutions" :key="res" class="resolution-tag">{{ res }}</span>
					</div>
				</div>
			</div>
		</div>

		<!-- Auto-Generation Management -->
		<div id="auto-gen" class="section">
			<h2>🤖 Auto-Generation Directories</h2>
			<div v-if="autoGenDirs.length === 0" class="empty-state">
				<div class="empty-icon">
					📁
				</div>
				<p>No auto-generation directories configured</p>
			</div>
			<div v-else class="auto-gen-list">
				<div v-for="dir in autoGenDirs" :key="dir.configKey" class="auto-gen-card">
					<div class="auto-gen-header">
						<div class="auto-gen-path">
							📁 {{ dir.directory }}
						</div>
						<div class="auto-gen-status" :class="{ enabled: dir.enabled, disabled: !dir.enabled }">
							{{ dir.enabled ? 'Enabled' : 'Disabled' }}
						</div>
					</div>
					<div class="auto-gen-details">
						<span class="detail-item">📍 {{ dir.cacheLocation }}</span>
						<span class="detail-item">📅 {{ formatDate(dir.registeredAt) }}</span>
					</div>
					<div class="auto-gen-resolutions">
						<span v-for="res in dir.resolutions" :key="res" class="resolution-tag">{{ res }}</span>
					</div>
					<div class="auto-gen-actions">
						<button class="edit-btn" @click="editAutoGeneration(dir)">
							✏️ Edit
						</button>
						<button class="remove-btn" @click="removeAutoGeneration(dir.configKey)">
							🗑️ Remove
						</button>
					</div>
				</div>
			</div>
		</div>

		<!-- Back to Top Button -->
		<button 
			v-show="showBackToTop" 
			class="back-to-top-btn" 
			title="Back to top"
			@click="scrollToTop">
			↑
		</button>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'App',
	data() {
		return {
			loading: false,
			activeJobs: [],
			autoGenDirs: [],
			statistics: {
				activeJobs: 0,
				completedJobs: 0,
				pendingJobs: 0,
				completedJobFilenames: []
			},
			lastRefresh: 'Never',
			refreshInterval: null,
			statsInterval: null,
			isPollingActive: false, // Track if centralized polling is running
			showBackToTop: false,
			showCompletedJobs: false
		}
	},
	async mounted() {
		console.log('🎬 Hyper Viewer Dashboard mounted!')
		await this.refreshData()
		
		// Set up auto-refresh every 10 seconds for job discovery
		this.refreshInterval = setInterval(() => {
			this.refreshActiveJobs()
		}, 10000)

		// Set up periodic refresh for statistics and auto-gen (every 10 seconds)
		this.statsInterval = setInterval(() => {
			this.refreshStatisticsAndAutoGen()
		}, 10000)

		// Set up scroll listener for back-to-top button
		window.addEventListener('scroll', this.handleScroll)
	},
	beforeDestroy() {
		if (this.refreshInterval) {
			clearInterval(this.refreshInterval)
		}
		if (this.statsInterval) {
			clearInterval(this.statsInterval)
		}
		// Stop centralized polling
		this.isPollingActive = false
		window.removeEventListener('scroll', this.handleScroll)
	},
	methods: {
		async refreshData() {
			this.loading = true
			try {
				// Fetch all data in parallel (full refresh)
				const [autoGenRes, statsRes] = await Promise.all([
					axios.get(generateUrl('/apps/hyper_viewer/api/auto-generation')),
					axios.get(generateUrl('/apps/hyper_viewer/api/jobs/statistics'))
				])

				// Also refresh active jobs initially
				await this.refreshActiveJobs()
				
				this.autoGenDirs = autoGenRes.data.autoGenDirs || []
				this.statistics = statsRes.data.stats || this.statistics

				this.lastRefresh = new Date().toLocaleTimeString()
				console.log('✅ Dashboard data refreshed', {
					activeJobs: this.activeJobs.length,
					autoGenDirs: this.autoGenDirs.length
				})

			} catch (error) {
				console.error('❌ Failed to refresh dashboard data:', error)
				OC.Notification.showTemporary('Failed to refresh dashboard data', { type: 'error' })
			} finally {
				this.loading = false
			}
		},

		async refreshStatisticsAndAutoGen() {
			try {
				// Refresh statistics and auto-gen directories (not active jobs)
				const [autoGenRes, statsRes] = await Promise.all([
					axios.get(generateUrl('/apps/hyper_viewer/api/auto-generation')),
					axios.get(generateUrl('/apps/hyper_viewer/api/jobs/statistics'))
				])
				
				this.autoGenDirs = autoGenRes.data.autoGenDirs || []
				this.statistics = statsRes.data.stats || this.statistics
				
			} catch (error) {
				console.error('❌ Failed to refresh statistics and auto-gen:', error)
			}
		},

		async refreshActiveJobs() {
			try {
				// Fast scan - just get list of active jobs (no detailed progress)
				const response = await axios.get(generateUrl('/apps/hyper_viewer/api/jobs/active'))
				const newActiveJobs = response.data.jobs || []
				
				// Update the jobs list (merge with existing progress data)
				this.activeJobs = newActiveJobs.map(newJob => {
					const existingJob = this.activeJobs.find(j => j.filename === newJob.filename)
					return existingJob ? { ...existingJob, ...newJob } : { ...newJob, progress: 0, frame: 0, fps: 0, speed: '0x', time: '00:00:00' }
				})
				
				// Start centralized polling if we have jobs and it's not already running
				if (this.activeJobs.length > 0 && !this.isPollingActive) {
					this.startCentralizedPolling()
				}
				
			} catch (error) {
				console.error('❌ Failed to refresh active jobs:', error)
			}
		},

		async startCentralizedPolling() {
			if (this.isPollingActive) {
				return // Already polling
			}
			
			this.isPollingActive = true
			console.log('🔄 Starting centralized job polling')
			
			// Sequential polling loop that processes one job at a time
			while (this.activeJobs.length > 0 && this.isPollingActive) {
				// Process each active job sequentially
				for (let i = 0; i < this.activeJobs.length; i++) {
					if (!this.isPollingActive) break
					
					const job = this.activeJobs[i]
					if (!job) continue
					
					try {
						const response = await axios.get(generateUrl(`/apps/hyper_viewer/api/jobs/active/${job.filename}`))
						
						// Update the specific job in the array
						this.$set(this.activeJobs, i, { ...this.activeJobs[i], ...response.data })
						
					} catch (error) {
						if (error.response?.status === 404) {
							// Job no longer active, remove from list
							this.activeJobs.splice(i, 1)
							i-- // Adjust index after removal
						}
					}
					
					// Wait 500ms before next request (max 2 requests per second)
					await new Promise(resolve => setTimeout(resolve, 500))
				}
				
				// Wait a bit before next cycle
				await new Promise(resolve => setTimeout(resolve, 2000))
			}
			
			this.isPollingActive = false
			console.log('⏹️ Stopped centralized job polling')
		},

		editAutoGeneration(dir) {
			// Create a simple edit dialog
			const resolutions = ['1080p', '720p', '480p', '360p', '240p']
			const currentResolutions = dir.resolutions || []
			
			// Build checkbox list
			const checkboxes = resolutions.map(res => {
				const checked = currentResolutions.includes(res) ? 'checked' : ''
				return `<label style="display: block; margin: 5px 0;"><input type="checkbox" value="${res}" ${checked}> ${res}</label>`
			}).join('')
			
			const enabledChecked = dir.enabled ? 'checked' : ''
			
			const dialogContent = `
				<div style="padding: 20px;">
					<h3>Edit Auto-Generation Settings</h3>
					<p><strong>Directory:</strong> ${dir.directory}</p>
					
					<div style="margin: 15px 0;">
						<label><input type="checkbox" id="enabled-checkbox" ${enabledChecked}> Enabled</label>
					</div>
					
					<div style="margin: 15px 0;">
						<strong>Resolutions:</strong><br>
						${checkboxes}
					</div>
					
					<div style="margin-top: 20px;">
						<button id="save-btn" style="background: #0082c9; color: white; padding: 8px 16px; border: none; border-radius: 4px; margin-right: 10px;">Save</button>
						<button id="cancel-btn" style="background: #ccc; color: black; padding: 8px 16px; border: none; border-radius: 4px;">Cancel</button>
					</div>
				</div>
			`
			
			// Create modal
			const modal = document.createElement('div')
			modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;'
			modal.innerHTML = `<div style="background: white; border-radius: 8px; max-width: 500px; width: 90%;">${dialogContent}</div>`
			
			document.body.appendChild(modal)
			
			// Handle save
			modal.querySelector('#save-btn').onclick = async () => {
				const enabled = modal.querySelector('#enabled-checkbox').checked
				const selectedResolutions = Array.from(modal.querySelectorAll('input[type="checkbox"][value]'))
					.filter(cb => cb.checked)
					.map(cb => cb.value)
				
				try {
					await axios.put(generateUrl(`/apps/hyper_viewer/api/auto-generation/${dir.configKey}`), {
						enabled,
						resolutions: selectedResolutions
					})
					
					// Update local data
					const dirIndex = this.autoGenDirs.findIndex(d => d.configKey === dir.configKey)
					if (dirIndex !== -1) {
						this.autoGenDirs[dirIndex].enabled = enabled
						this.autoGenDirs[dirIndex].resolutions = selectedResolutions
					}
					
					document.body.removeChild(modal)
					OC.Notification.showTemporary('Auto-generation settings updated successfully', { type: 'success' })
				} catch (error) {
					console.error('❌ Failed to update auto-generation settings:', error)
					OC.Notification.showTemporary('Failed to update settings', { type: 'error' })
				}
			}
			
			// Handle cancel
			modal.querySelector('#cancel-btn').onclick = () => {
				document.body.removeChild(modal)
			}
			
			// Handle click outside
			modal.onclick = (e) => {
				if (e.target === modal) {
					document.body.removeChild(modal)
				}
			}
		},

		async removeAutoGeneration(configKey) {
			if (!confirm('Are you sure you want to remove this auto-generation directory?')) {
				return
			}

			try {
				await axios.delete(generateUrl('/apps/hyper_viewer/api/auto-generation/' + configKey))
				
				// Remove from local array
				this.autoGenDirs = this.autoGenDirs.filter(dir => dir.configKey !== configKey)
				this.statistics.autoGenDirectories = Math.max(0, this.statistics.autoGenDirectories - 1)
				
				OC.Notification.showTemporary('Auto-generation removed successfully', { type: 'success' })
				console.log('🗑️ Removed auto-generation:', configKey)

			} catch (error) {
				console.error('❌ Failed to remove auto-generation:', error)
				OC.Notification.showTemporary('Failed to remove auto-generation', { type: 'error' })
			}
		},

		formatDate(timestamp) {
			if (!timestamp) return 'Unknown'
			return new Date(timestamp * 1000).toLocaleDateString()
		},

		handleScroll() {
			this.showBackToTop = window.scrollY > 300
		},

		scrollToTop() {
			window.scrollTo({
				top: 0,
				behavior: 'smooth'
			})
		},

		toggleCompletedJobs() {
			this.showCompletedJobs = !this.showCompletedJobs
		},

		scrollToSection(sectionId) {
			const element = document.getElementById(sectionId)
			if (element) {
				element.scrollIntoView({
					behavior: 'smooth',
					block: 'start'
				})
			}
		}
	}
}
</script>

<style scoped>
.hyper-viewer-dashboard {
	padding: 20px;
	max-width: 1200px;
	margin: 0 auto;
	font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
	min-height: 100vh;
	overflow-y: auto;
	box-sizing: border-box;
}

/* Header */
.dashboard-header {
	text-align: center;
	margin-bottom: 30px;
	padding: 20px;
	background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
	border-radius: 12px;
	color: white;
	position: relative;
}

.header-logo {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 80px;
	height: 80px;
	margin-bottom: 15px;
	background: rgba(255, 255, 255, 0.15);
	border-radius: 20px;
	backdrop-filter: blur(10px);
	box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
}

.header-logo svg {
	width: 48px;
	height: 48px;
	stroke: white;
	filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
}

.dashboard-header h1 {
	margin: 0 0 10px 0;
	font-size: 2.5em;
	font-weight: 600;
}

.dashboard-header p {
	margin: 0 0 20px 0;
	opacity: 0.9;
	font-size: 1.1em;
}

.refresh-btn {
	background: rgba(255, 255, 255, 0.2);
	border: 2px solid rgba(255, 255, 255, 0.3);
	color: white;
	padding: 10px 20px;
	border-radius: 25px;
	cursor: pointer;
	font-size: 16px;
	transition: all 0.3s ease;
	backdrop-filter: blur(10px);
}

.refresh-btn:hover:not(:disabled) {
	background: rgba(255, 255, 255, 0.3);
	transform: translateY(-2px);
}

.refresh-btn:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

/* Quick Navigation */
.quick-nav {
	display: flex;
	justify-content: center;
	gap: 15px;
	margin-bottom: 30px;
	flex-wrap: wrap;
}

.nav-btn {
	background: rgba(255, 255, 255, 0.9);
	border: 2px solid #667eea;
	color: #667eea;
	padding: 8px 16px;
	border-radius: 20px;
	cursor: pointer;
	font-size: 14px;
	font-weight: 500;
	transition: all 0.3s ease;
	backdrop-filter: blur(10px);
}

.nav-btn:hover {
	background: #667eea;
	color: white;
	transform: translateY(-2px);
	box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}

.nav-btn:active {
	transform: translateY(0);
}

/* Statistics Grid */
.stats-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 20px;
	margin-bottom: 40px;
}

.stat-card {
	background: white;
	border-radius: 12px;
	padding: 20px;
	box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
	display: flex;
	align-items: center;
	transition: transform 0.2s ease;
}

.stat-card:hover {
	transform: translateY(-2px);
	box-shadow: 0 8px 15px rgba(0, 0, 0, 0.15);
}

.stat-icon {
	font-size: 2.5em;
	margin-right: 15px;
}

.stat-number {
	font-size: 2em;
	font-weight: bold;
	color: #333;
	margin-bottom: 5px;
}

.stat-label {
	color: #666;
	font-size: 0.9em;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	position: relative;
}

.stat-card.clickable {
	cursor: pointer;
}

.stat-card.clickable:hover {
	background: #f8f9fa;
}

.toggle-indicator {
	margin-left: 8px;
	font-size: 0.8em;
	color: #999;
	transition: transform 0.2s ease;
}

/* Completed Jobs Section */
.completed-jobs-section {
	background: white;
	border-radius: 12px;
	box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
	margin-bottom: 30px;
	overflow: hidden;
	animation: slideDown 0.3s ease-out;
}

@keyframes slideDown {
	from {
		opacity: 0;
		transform: translateY(-10px);
	}
	to {
		opacity: 1;
		transform: translateY(0);
	}
}

.completed-jobs-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 20px;
	background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
	color: white;
}

.completed-jobs-header h3 {
	margin: 0;
	font-size: 1.3em;
	font-weight: 600;
}

.close-btn {
	background: rgba(255, 255, 255, 0.2);
	border: none;
	color: white;
	width: 30px;
	height: 30px;
	border-radius: 50%;
	cursor: pointer;
	font-size: 16px;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: background 0.2s ease;
}

.close-btn:hover {
	background: rgba(255, 255, 255, 0.3);
}

.completed-jobs-list {
	max-height: 400px;
	overflow-y: auto;
	padding: 0;
}

.completed-job-item {
	padding: 12px 20px;
	border-bottom: 1px solid #f0f0f0;
	font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
	font-size: 0.9em;
	color: #333;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	transition: background 0.2s ease;
}

.completed-job-item:hover {
	background: #f8f9fa;
}

.completed-job-item:last-child {
	border-bottom: none;
}

/* Custom scrollbar for completed jobs list */
.completed-jobs-list::-webkit-scrollbar {
	width: 8px;
}

.completed-jobs-list::-webkit-scrollbar-track {
	background: #f1f1f1;
}

.completed-jobs-list::-webkit-scrollbar-thumb {
	background: #c1c1c1;
	border-radius: 4px;
}

.completed-jobs-list::-webkit-scrollbar-thumb:hover {
	background: #a8a8a8;
}

/* Sections */
.section {
	margin-bottom: 40px;
	scroll-margin-top: 20px; /* For smooth scrolling to sections */
}

.section h2 {
	font-size: 1.8em;
	margin-bottom: 20px;
	color: #333;
	border-bottom: 3px solid #667eea;
	padding-bottom: 10px;
}

/* Empty States */
.empty-state {
	text-align: center;
	padding: 60px 20px;
	background: #f8f9fa;
	border-radius: 12px;
	border: 2px dashed #dee2e6;
}

.empty-icon {
	font-size: 4em;
	margin-bottom: 15px;
	opacity: 0.5;
}

.empty-state p {
	color: #6c757d;
	font-size: 1.1em;
	margin: 0;
}

/* Job Cards */
.jobs-list {
	display: grid;
	gap: 20px;
}

.job-card {
	background: white;
	border-radius: 12px;
	padding: 20px;
	box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
	border-left: 4px solid #28a745;
}

.job-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 15px;
}

.job-filename {
	font-weight: 600;
	font-size: 1.1em;
	color: #333;
}

.job-status {
	padding: 4px 12px;
	border-radius: 20px;
	font-size: 0.85em;
	font-weight: 500;
	text-transform: uppercase;
}

.job-status.processing {
	background: #fff3cd;
	color: #856404;
}

.job-progress {
	display: flex;
	align-items: center;
	margin-bottom: 15px;
}

.progress-bar {
	flex: 1;
	height: 8px;
	background: #e9ecef;
	border-radius: 4px;
	overflow: hidden;
	margin-right: 15px;
}

.progress-fill {
	height: 100%;
	background: linear-gradient(90deg, #28a745, #20c997);
	transition: width 0.3s ease;
}

.progress-text {
	font-weight: 600;
	color: #28a745;
	min-width: 50px;
}

.job-details {
	display: flex;
	flex-wrap: wrap;
	gap: 15px;
	margin-bottom: 15px;
}

.detail-item {
	background: #f8f9fa;
	padding: 5px 10px;
	border-radius: 15px;
	font-size: 0.9em;
	color: #495057;
}

.job-resolutions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.resolution-tag {
	background: #667eea;
	color: white;
	padding: 4px 8px;
	border-radius: 12px;
	font-size: 0.8em;
	font-weight: 500;
}

/* Auto-Generation Cards */
.auto-gen-list {
	display: grid;
	gap: 20px;
}

.auto-gen-card {
	background: white;
	border-radius: 12px;
	padding: 20px;
	box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
	border-left: 4px solid #6f42c1;
}

.auto-gen-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 15px;
}

.auto-gen-path {
	font-weight: 600;
	font-size: 1.1em;
	color: #333;
}

.auto-gen-status {
	padding: 4px 12px;
	border-radius: 20px;
	font-size: 0.85em;
	font-weight: 500;
	text-transform: uppercase;
}

.auto-gen-status.enabled {
	background: #d4edda;
	color: #155724;
}

.auto-gen-status.disabled {
	background: #f8d7da;
	color: #721c24;
}

.auto-gen-details {
	display: flex;
	flex-wrap: wrap;
	gap: 15px;
	margin-bottom: 15px;
}

.auto-gen-resolutions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-bottom: 15px;
}

.auto-gen-actions {
	text-align: right;
}

.remove-btn {
	background: #dc3545;
	color: white;
	border: none;
	padding: 8px 16px;
	border-radius: 6px;
	cursor: pointer;
	font-size: 0.9em;
	transition: background 0.2s ease;
}

.remove-btn:hover {
	background: #c82333;
}

/* System Info */
.info-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
	gap: 15px;
}

.info-item {
	background: white;
	padding: 15px;
	border-radius: 8px;
	box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.info-item strong {
	color: #495057;
}

/* Responsive Design */
@media (max-width: 768px) {
	.hyper-viewer-dashboard {
		padding: 15px;
	}
	
	.dashboard-header h1 {
		font-size: 2em;
	}
	
	.stats-grid {
		grid-template-columns: repeat(2, 1fr);
	}
	
	.job-header, .auto-gen-header {
		flex-direction: column;
		align-items: flex-start;
		gap: 10px;
	}
	
	.job-details {
		justify-content: center;
	}
}

@media (max-width: 480px) {
	.stats-grid {
		grid-template-columns: 1fr;
	}
}

/* Back to Top Button */
.back-to-top-btn {
	position: fixed;
	bottom: 30px;
	right: 30px;
	width: 50px;
	height: 50px;
	background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
	color: white;
	border: none;
	border-radius: 50%;
	font-size: 20px;
	font-weight: bold;
	cursor: pointer;
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
	transition: all 0.3s ease;
	z-index: 1000;
	display: flex;
	align-items: center;
	justify-content: center;
}

.back-to-top-btn:hover {
	transform: translateY(-3px);
	box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
	background: linear-gradient(135deg, #5a67d8 0%, #6b46c1 100%);
}

.back-to-top-btn:active {
	transform: translateY(-1px);
}

/* Smooth scrolling for the entire page */
html {
	scroll-behavior: smooth;
}

/* Better scrollbar styling for webkit browsers */
.hyper-viewer-dashboard::-webkit-scrollbar {
	width: 8px;
}

.hyper-viewer-dashboard::-webkit-scrollbar-track {
	background: #f1f1f1;
	border-radius: 4px;
}

.hyper-viewer-dashboard::-webkit-scrollbar-thumb {
	background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
	border-radius: 4px;
}

.hyper-viewer-dashboard::-webkit-scrollbar-thumb:hover {
	background: linear-gradient(135deg, #5a67d8 0%, #6b46c1 100%);
}

/* Improve section spacing for better scrolling */
.section:last-child {
	margin-bottom: 100px; /* Extra space at bottom for comfortable scrolling */
}
</style>
