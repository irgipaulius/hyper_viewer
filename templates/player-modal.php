<?php
// Get URL parameters
$filename = $_GET['filename'] ?? 'Unknown Video';
$cachePath = $_GET['cachePath'] ?? '';
?>

<div class="hyper-viewer-player-modal">
    <div class="player-header">
        <h3><?php p($filename); ?></h3>
    </div>
    
    <div id="video-container">
        <video id="shaka-video" controls autoplay style="width: 100%; height: 400px; background: #000;">
            Your browser does not support the video tag.
        </video>
    </div>
    
    <div class="player-info">
        <p><strong>HLS Cache:</strong> <span id="cache-path"><?php p($cachePath); ?></span></p>
        <p><strong>Status:</strong> <span id="player-status">Initializing...</span></p>
    </div>
</div>

<!-- Load HLS.js directly via CDN to avoid CSP issues with bundled version -->
<script src="https://cdn.jsdelivr.net/npm/hls.js@1.4.12/dist/hls.min.js"></script>

<script>
// Initialize HLS player immediately since this content has relaxed CSP
(function() {
    const cachePath = '<?php p($cachePath); ?>';
    const video = document.getElementById('shaka-video');
    const statusElement = document.getElementById('player-status');

    if (!cachePath) {
        statusElement.textContent = 'Error: No cache path provided';
        statusElement.style.color = '#ff4444';
        return;
    }

    console.log('🎬 Initializing HLS player with cache path:', cachePath);

    // Build HLS manifest URL
    const manifestUrl = '/apps/files/ajax/download.php'
        + '?dir=' + encodeURIComponent(cachePath)
        + '&files=' + encodeURIComponent('playlist.m3u8');

    console.log('🎬 Loading HLS manifest:', manifestUrl);

    if (statusElement) {
        statusElement.textContent = 'Loading HLS stream...';
    }

    try {
        // First try native HLS support (Safari, iOS)
        if (video.canPlayType('application/vnd.apple.mpegurl') || video.canPlayType('application/x-mpegURL')) {
            console.log('🍎 Using native HLS support');
            video.src = manifestUrl;
            video.load();
            if (statusElement) {
                statusElement.textContent = 'Playing HLS stream (native)';
                statusElement.style.color = '#00aa00';
            }
            return;
        }

        // Use HLS.js with relaxed CSP (blob URLs allowed)
        if (!window.Hls || !Hls.isSupported()) {
            throw new Error('HLS.js is not supported in this browser');
        }

        console.log('🎬 Using HLS.js for HLS playback');

        // Create HLS.js instance
        const hls = new Hls({
            debug: false,
            enableWorker: true,
            lowLatencyMode: false,
            backBufferLength: 30,
            maxBufferLength: 60,
            maxMaxBufferLength: 120,
        });

        // Listen for errors
        hls.on(Hls.Events.ERROR, function(event, data) {
            console.error('HLS.js error:', data);
            if (statusElement) {
                statusElement.textContent = 'Error: ' + (data.details || 'HLS playback error');
                statusElement.style.color = '#ff4444';
            }
            
            // Try to recover from some errors
            if (data.fatal) {
                switch (data.type) {
                case Hls.ErrorTypes.NETWORK_ERROR:
                    console.log('Trying to recover from network error');
                    hls.startLoad();
                    break;
                case Hls.ErrorTypes.MEDIA_ERROR:
                    console.log('Trying to recover from media error');
                    hls.recoverMediaError();
                    break;
                default:
                    console.log('Fatal error, destroying HLS instance');
                    hls.destroy();
                    break;
                }
            }
        });

        // Listen for successful manifest loading
        hls.on(Hls.Events.MANIFEST_LOADED, function() {
            console.log('✅ HLS manifest loaded successfully');
            if (statusElement) {
                statusElement.textContent = 'Playing HLS stream (HLS.js)';
                statusElement.style.color = '#00aa00';
            }
        });

        // Attach HLS to video element
        hls.attachMedia(video);

        // Load the manifest
        hls.loadSource(manifestUrl);

        // Store HLS instance for cleanup
        window.currentHlsPlayer = hls;

    } catch (error) {
        console.error('Failed to initialize HLS player:', error);

        if (statusElement) {
            statusElement.textContent = 'Failed to load: ' + error.message;
            statusElement.style.color = '#ff4444';
        }
    }
})();
</script>

<style>
.hyper-viewer-player-modal {
    padding: 20px;
    max-width: 800px;
    margin: 0 auto;
}

.player-header {
    margin-bottom: 15px;
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
}

.player-header h3 {
    margin: 0;
    color: #333;
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
