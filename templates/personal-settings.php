<?php
script('hyper_viewer', 'settings');
style('hyper_viewer', 'settings');
?>

<div class="section" id="hyper_viewer_settings">
	<h2><?php p($l->t('Hyper Viewer')); ?></h2>
	<p class="settings-hint"><?php p($l->t('Configure HLS cache locations for video streaming')); ?></p>
	
	<div class="cache-locations">
		<h3><?php p($l->t('HLS Cache Locations')); ?></h3>
		<p><?php p($l->t('Hyper Viewer will search these locations for .m3u8 files in order:')); ?></p>
		
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
		
		<button id="add-cache-location" class="icon-add"><?php p($l->t('Add Location')); ?></button>
		<button id="save-cache-settings" class="primary"><?php p($l->t('Save')); ?></button>
	</div>
	
	<div class="cache-help">
		<h4><?php p($l->t('Path Examples:')); ?></h4>
		<ul>
			<li><code>./.cached_hls/</code> - <?php p($l->t('Relative to video file location')); ?></li>
			<li><code>~/.cached_hls/</code> - <?php p($l->t('In user home directory')); ?></li>
			<li><code>/mnt/cache/.cached_hls/</code> - <?php p($l->t('Absolute path (e.g., mounted storage)')); ?></li>
		</ul>
	</div>
	
	<div class="prototype-features">
		<h3><?php p($l->t('⚡ Prototype Features')); ?></h3>
		<p class="settings-hint"><?php p($l->t('Experimental features for testing and development')); ?></p>
		
		<div class="prototype-toggle">
			<input type="checkbox" id="live-transcode-enabled" class="checkbox" />
			<label for="live-transcode-enabled">
				<strong><?php p($l->t('Live Transcode Mode')); ?></strong>
				<br>
				<span class="prototype-description">
					<?php p($l->t('Stream .MOV files at 720p on-the-fly without generating .m3u8 files. Uses more server resources but provides instant playback.')); ?>
				</span>
			</label>
		</div>
		
		<div class="prototype-status" id="transcode-status" style="display: none;">
			<p><strong><?php p($l->t('Status:')); ?></strong> <span id="status-text"></span></p>
			<p><strong><?php p($l->t('Active processes:')); ?></strong> <span id="active-processes">0</span> / <span id="max-processes">1</span></p>
		</div>
	</div>
</div>
