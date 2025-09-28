<?php
script('hyper_viewer', 'files-integration');
style('hyper_viewer', 'icons');

// Get URL parameters
$filename = $_GET['filename'] ?? 'Unknown Video';
$cachePath = $_GET['cachePath'] ?? '';
?>

<div id="hyper-viewer-player">
    <div class="player-header">
        <h2><?php p($filename); ?></h2>
        <button onclick="window.close()" class="close-button">Close</button>
    </div>
    
    <div id="video-container">
        <video id="shaka-video" controls autoplay style="width: 100%; height: 500px; background: #000;">
            Your browser does not support the video tag.
        </video>
    </div>
    
    <div class="player-info">
        <p><strong>HLS Cache:</strong> <span id="cache-path"><?php p($cachePath); ?></span></p>
        <p><strong>Status:</strong> <span id="player-status">Initializing...</span></p>
    </div>
</div>

<script>
// Initialize HLS player when page loads
document.addEventListener('DOMContentLoaded', function() {
    const cachePath = '<?php p($cachePath); ?>';
    if (cachePath) {
        console.log('🎬 Initializing HLS player with cache path:', cachePath);
        
        // Call the HLS initialization function
        if (typeof initializeShakaPlayer === 'function') {
            initializeShakaPlayer(cachePath);
        } else {
            console.error('initializeShakaPlayer function not found');
            document.getElementById('player-status').textContent = 'Error: Player not loaded';
        }
    } else {
        document.getElementById('player-status').textContent = 'Error: No cache path provided';
    }
});
</script>

<style>
#hyper-viewer-player {
    padding: 20px;
    max-width: 1000px;
    margin: 0 auto;
}

.player-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
}

.player-header h2 {
    margin: 0;
    color: #333;
}

.close-button {
    background: #ff4444;
    color: white;
    border: none;
    border-radius: 5px;
    padding: 8px 16px;
    cursor: pointer;
    font-size: 14px;
}

.close-button:hover {
    background: #cc0000;
}

#video-container {
    margin: 20px 0;
    text-align: center;
}

.player-info {
    margin-top: 15px;
    padding: 15px;
    background: #f5f5f5;
    border-radius: 5px;
    font-size: 14px;
}

.player-info p {
    margin: 8px 0;
}

#player-status {
    font-weight: bold;
    color: #0082c9;
}

#cache-path {
    font-family: monospace;
    background: #fff;
    padding: 2px 4px;
    border-radius: 3px;
}
</style>
