<?php
script('hyper_viewer', 'settings');
style('hyper_viewer', 'settings');
?>

<div class="section" id="hyper_viewer_settings">
	<h2 class="inlineblock"><?php p($l->t('Hyper Viewer')); ?></h2>
	<p class="settings-hint"><?php p($l->t('Monitor video processing jobs and configure HLS cache storage locations.')); ?></p>
	
	<!-- Job Statistics -->
	<h3><?php p($l->t('Job Statistics')); ?></h3>
	<table class="grid">
		<thead>
			<tr>
				<th><?php p($l->t('Active Jobs')); ?></th>
				<th><?php p($l->t('Auto-Gen Directories')); ?></th>
				<th><?php p($l->t('Completed')); ?></th>
				<th><?php p($l->t('Pending')); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td id="stat-active" class="stat-value">0</td>
				<td id="stat-autogen" class="stat-value">0</td>
				<td id="stat-completed" class="stat-value">0</td>
				<td id="stat-pending" class="stat-value">0</td>
			</tr>
		</tbody>
	</table>

	<!-- Active Jobs -->
	<h3><?php p($l->t('Active Jobs')); ?></h3>
	<div id="active-jobs-container">
		<p class="emptycontent-desc"><?php p($l->t('No active jobs running')); ?></p>
	</div>

	<!-- Auto-Generation Directories -->
	<h3><?php p($l->t('Auto-Generation Directories')); ?></h3>
	<div id="autogen-container">
		<p class="emptycontent-desc"><?php p($l->t('No auto-generation directories configured')); ?></p>
	</div>
	
	<!-- Cache Locations Configuration -->
	<h3><?php p($l->t('Cache Locations')); ?></h3>
	<p class="settings-hint"><?php p($l->t('Locations are tried in order. First writable location is used.')); ?></p>
	
	<div id="cache-location-list">
		<?php foreach ($_['cache_locations'] as $index => $location): ?>
			<div class="cache-location-item" data-index="<?php p($index); ?>">
				<input type="text" 
					   class="cache-location-input" 
					   value="<?php p($location); ?>" 
					   placeholder="<?php p($l->t('Enter cache path...')); ?>" />
				<button class="icon-delete remove-location" title="<?php p($l->t('Remove')); ?>"></button>
			</div>
		<?php endforeach; ?>
	</div>
	
	<div class="cache-actions">
		<button id="add-cache-location" class="icon-add"><?php p($l->t('Add Location')); ?></button>
		<button id="save-cache-settings" class="primary"><?php p($l->t('Save')); ?></button>
	</div>
	
	<p class="settings-hint">
		<strong><?php p($l->t('Examples:')); ?></strong><br>
		<code>./.cached_hls/</code> - <?php p($l->t('Relative to video file')); ?><br>
		<code>~/.cached_hls/</code> - <?php p($l->t('User home directory')); ?><br>
		<code>/mnt/cache/.cached_hls/</code> - <?php p($l->t('Absolute path')); ?>
	</p>
</div>
