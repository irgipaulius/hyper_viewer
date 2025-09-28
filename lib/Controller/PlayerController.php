<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCP\IRequest;
use OCP\AppFramework\Controller;

/**
 * Simple HLS video player controller
 */
class PlayerController extends Controller {

    public function __construct($appName, IRequest $request) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function modal() {
        $filename = $_GET['filename'] ?? 'Unknown Video';
        $cachePath = $_GET['cachePath'] ?? '';
        $manifestUrl = '/apps/files/ajax/download.php?dir=' . urlencode($cachePath) . '&files=playlist.m3u8';
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <link href="https://vjs.zencdn.net/8.5.2/video-js.css" rel="stylesheet">
    <style>
        body { margin: 0; padding: 20px; font-family: Arial, sans-serif; }
        .player-container { max-width: 800px; margin: 0 auto; }
        h2 { color: #333; margin-bottom: 20px; }
        .video-js { width: 100%; height: 400px; }
    </style>
</head>
<body>
    <div class="player-container">
        <h2>🎬 ' . htmlspecialchars($filename) . '</h2>
        
        <video
            id="hls-player"
            class="video-js vjs-default-skin"
            controls
            preload="auto"
            data-setup="{}">
            <source src="' . htmlspecialchars($manifestUrl) . '" type="application/x-mpegURL">
            <p class="vjs-no-js">
                To view this video please enable JavaScript, and consider upgrading to a web browser that
                <a href="https://videojs.com/html5-video-support/" target="_blank">supports HTML5 video</a>.
            </p>
        </video>
        
        <p style="margin-top: 15px; color: #666; font-size: 14px;">
            <strong>HLS Stream:</strong> ' . htmlspecialchars($cachePath) . '/playlist.m3u8
        </p>
    </div>

    <script src="https://vjs.zencdn.net/8.5.2/video.min.js"></script>
    <script>
        console.log("🎬 Video.js HLS Player loaded");
        
        var player = videojs("hls-player", {
            html5: {
                hls: {
                    enableLowInitialPlaylist: true,
                    smoothQualityChange: true,
                    overrideNative: true
                }
            }
        });
        
        player.ready(function() {
            console.log("✅ Video.js player ready");
            player.src({
                src: "' . addslashes($manifestUrl) . '",
                type: "application/x-mpegURL"
            });
        });
        
        player.on("error", function(e) {
            console.error("❌ Video.js error:", e);
        });
        
        player.on("loadstart", function() {
            console.log("📺 Loading HLS stream...");
        });
        
        player.on("canplay", function() {
            console.log("✅ HLS stream ready to play");
        });
    </script>
</body>
</html>';
        
        return new \OCP\AppFramework\Http\Response($html);
    }
}
