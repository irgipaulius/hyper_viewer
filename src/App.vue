<template>
	<div class="hyper-viewer-dashboard">
		<!-- Header -->
		<div class="dashboard-header">
			<h1>🎬 Hyper Viewer Management</h1>
			<p>Monitor HLS generation jobs and manage auto-generation settings</p>
			<button @click="refreshData" class="refresh-btn" :disabled="loading">
				<span v-if="loading">🔄</span>
				<span v-else>↻</span>
				Refresh
			</button>
		</div>

		<!-- Statistics Cards -->
		<div class="stats-grid">
			<div class="stat-card">
				<div class="stat-icon">⚡</div>
				<div class="stat-content">
					<div class="stat-number">{{ statistics.activeJobs }}</div>
					<div class="stat-label">Active Jobs</div>
				</div>
			</div>
			<div class="stat-card">
				<div class="stat-icon">🤖</div>
				<div class="stat-content">
					<div class="stat-number">{{ statistics.autoGenDirectories }}</div>
					<div class="stat-label">Auto-Gen Dirs</div>
				</div>
			</div>
			<div class="stat-card">
				<div class="stat-icon">✅</div>
				<div class="stat-content">
					<div class="stat-number">{{ statistics.completedJobs }}</div>
					<div class="stat-label">Completed</div>
				</div>
			</div>
			<div class="stat-card">
				<div class="stat-icon">❌</div>
				<div class="stat-content">
					<div class="stat-number">{{ statistics.failedJobs }}</div>
					<div class="stat-label">Failed</div>
				</div>
			</div>
		</div>

		<!-- Active Jobs Section -->
		<div class="section">
			<h2>🔥 Active Jobs</h2>
			<div v-if="activeJobs.length === 0" class="empty-state">
				<div class="empty-icon">😴</div>
				<p>No active jobs running</p>
			</div>
			<div v-else class="jobs-list">
				<div v-for="job in activeJobs" :key="job.cachePath" class="job-card">
					<div class="job-header">
						<div class="job-filename">{{ job.filename }}</div>
						<div class="job-status processing">{{ job.status }}</div>
					</div>
					<div class="job-progress">
						<div class="progress-bar">
							<div class="progress-fill" :style="{ width: job.progress + '%' }"></div>
						</div>
						<div class="progress-text">{{ job.progress }}%</div>
					</div>
					<div class="job-details">
						<span class="detail-item">⏱️ {{ job.time }}</span>
						<span class="detail-item">🎬 {{ job.frame }} frames</span>
						<span class="detail-item">⚡ {{ job.speed }}</span>
						<span class="detail-item">📺 {{ job.fps }} fps</span>
					</div>
					<div class="job-resolutions">
						<span v-for="res in job.resolutions" :key="res" class="resolution-tag">{{ res }}</span>
					</div>
				</div>
			</div>
		</div>

		<!-- Auto-Generation Management -->
		<div class="section">
			<h2>🤖 Auto-Generation Directories</h2>
			<div v-if="autoGenDirs.length === 0" class="empty-state">
				<div class="empty-icon">📁</div>
				<p>No auto-generation directories configured</p>
			</div>
			<div v-else class="auto-gen-list">
				<div v-for="dir in autoGenDirs" :key="dir.configKey" class="auto-gen-card">
					<div class="auto-gen-header">
						<div class="auto-gen-path">📁 {{ dir.directory }}</div>
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
						<button @click="removeAutoGeneration(dir.configKey)" class="remove-btn">
							🗑️ Remove
						</button>
					</div>
				</div>
			</div>
		</div>

		<!-- System Info -->
		<div class="section">
			<h2>ℹ️ System Information</h2>
			<div class="info-grid">
				<div class="info-item">
					<strong>Auto-Generation Interval:</strong> Every 15 minutes
				</div>
				<div class="info-item">
					<strong>Supported Formats:</strong> MOV, MP4
				</div>
				<div class="info-item">
					<strong>Cache Locations:</strong> Relative to video, User home
				</div>
				<div class="info-item">
					<strong>Last Refresh:</strong> {{ lastRefresh }}
				</div>
			</div>
		</div>
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
				autoGenDirectories: 0,
				completedJobs: 0,
				failedJobs: 0
			},
			lastRefresh: 'Never',
			refreshInterval: null
		}
	},
	async mounted() {
		console.log('🎬 Hyper Viewer Dashboard mounted!')
		await this.refreshData()
		
		// Set up auto-refresh every 30 seconds
		this.refreshInterval = setInterval(() => {
			this.refreshData()
		}, 30000)
	},
	beforeDestroy() {
		if (this.refreshInterval) {
			clearInterval(this.refreshInterval)
		}
	},
	methods: {
		async refreshData() {
			this.loading = true
			try {
				// Fetch all data in parallel
				const [activeJobsRes, autoGenRes, statsRes] = await Promise.all([
					axios.get(generateUrl('/apps/hyper_viewer/api/jobs/active')),
					axios.get(generateUrl('/apps/hyper_viewer/api/auto-generation')),
					axios.get(generateUrl('/apps/hyper_viewer/api/jobs/statistics'))
				])

				this.activeJobs = activeJobsRes.data.jobs || []
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
}

/* Sections */
.section {
	margin-bottom: 40px;
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
</style>
