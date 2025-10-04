/**
 * Plyr Time Display Fix for Nextcloud HyperViewer
 * 
 * Globally customizes all Plyr players to use YouTube-style time format:
 * - Replaces countdown format "-0:15" with "0:00 / 0:15"
 * - Hides the remaining timer element
 * - Adds a styled divider between current time and duration
 * 
 * @copyright Copyright (c) 2024 HyperViewer
 * @license GNU AGPL version 3 or any later version
 */

(function() {
    'use strict';

    /**
     * Format time in MM:SS or HH:MM:SS format
     * @param {number} seconds - Time in seconds
     * @returns {string} Formatted time string
     */
    function formatTime(seconds) {
        if (isNaN(seconds) || seconds < 0) {
            return '0:00';
        }

        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = Math.floor(seconds % 60);

        if (hours > 0) {
            return `${hours}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
        } else {
            return `${minutes}:${secs.toString().padStart(2, '0')}`;
        }
    }

    /**
     * Customize a Plyr player's time display
     * @param {HTMLElement} player - The Plyr player element
     */
    function customizePlayerTimeDisplay(player) {
        // Wait for Plyr to be fully initialized
        setTimeout(() => {
            const currentTimeElement = player.querySelector('.plyr__time--current');
            const durationElement = player.querySelector('.plyr__time--duration');
            const remainingElement = player.querySelector('.plyr__time--remaining');

            if (!currentTimeElement || !durationElement) {
                console.log('⏰ Plyr time elements not found, retrying...');
                // Retry after a longer delay
                setTimeout(() => customizePlayerTimeDisplay(player), 1000);
                return;
            }

            console.log('⏰ Customizing Plyr time display for player');

            // Hide the remaining time element if it exists
            if (remainingElement) {
                remainingElement.style.display = 'none';
            }

            // Create a container for our custom time display
            const timeContainer = document.createElement('div');
            timeContainer.className = 'plyr__time plyr__time--custom';
            timeContainer.style.cssText = `
                display: flex;
                align-items: center;
                font-variant-numeric: tabular-nums;
                font-size: inherit;
                line-height: inherit;
            `;

            // Create current time span
            const currentSpan = document.createElement('span');
            currentSpan.className = 'plyr__time-current';
            currentSpan.textContent = '0:00';

            // Create divider span
            const dividerSpan = document.createElement('span');
            dividerSpan.className = 'plyr__time-divider';
            dividerSpan.textContent = ' / ';
            dividerSpan.style.cssText = `
                opacity: 0.7;
                margin: 0 2px;
                font-weight: normal;
            `;

            // Create duration span
            const durationSpan = document.createElement('span');
            durationSpan.className = 'plyr__time-duration';
            durationSpan.textContent = '0:00';

            // Assemble the custom time display
            timeContainer.appendChild(currentSpan);
            timeContainer.appendChild(dividerSpan);
            timeContainer.appendChild(durationSpan);

            // Replace the original current time element
            currentTimeElement.parentNode.replaceChild(timeContainer, currentTimeElement);

            // Hide the original duration element since we're showing it in our custom display
            durationElement.style.display = 'none';

            // Get the Plyr instance
            const plyrInstance = player.plyr;
            if (!plyrInstance) {
                console.warn('⚠️ Plyr instance not found on player element');
                return;
            }

            // Update time display function
            function updateTimeDisplay() {
                const currentTime = plyrInstance.currentTime || 0;
                const duration = plyrInstance.duration || 0;

                currentSpan.textContent = formatTime(currentTime);
                durationSpan.textContent = formatTime(duration);
            }

            // Listen for time updates
            plyrInstance.on('timeupdate', updateTimeDisplay);
            plyrInstance.on('loadedmetadata', updateTimeDisplay);
            plyrInstance.on('durationchange', updateTimeDisplay);

            // Initial update
            updateTimeDisplay();

            console.log('✅ Plyr time display customized successfully');
        }, 500);
    }

    /**
     * Initialize time display customization for existing players
     */
    function initializeExistingPlayers() {
        // Look for existing Plyr players
        const existingPlayers = document.querySelectorAll('.plyr, [data-plyr]');
        existingPlayers.forEach(player => {
            if (player.plyr) {
                console.log('🎬 Found existing Plyr player, customizing...');
                customizePlayerTimeDisplay(player);
            }
        });
    }

    /**
     * Set up mutation observer to catch new Plyr players
     */
    function setupMutationObserver() {
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType === Node.ELEMENT_NODE) {
                        // Check if the added node is a Plyr player
                        if (node.classList && (node.classList.contains('plyr') || node.hasAttribute('data-plyr'))) {
                            console.log('🎬 New Plyr player detected via mutation observer');
                            // Wait a bit for Plyr to initialize
                            setTimeout(() => {
                                if (node.plyr) {
                                    customizePlayerTimeDisplay(node);
                                }
                            }, 1000);
                        }

                        // Check for Plyr players within the added node
                        const plyrPlayers = node.querySelectorAll ? node.querySelectorAll('.plyr, [data-plyr]') : [];
                        plyrPlayers.forEach(player => {
                            console.log('🎬 New Plyr player found within added node');
                            setTimeout(() => {
                                if (player.plyr) {
                                    customizePlayerTimeDisplay(player);
                                }
                            }, 1000);
                        });
                    }
                });
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });

        console.log('👀 Mutation observer set up for Plyr players');
    }

    /**
     * Initialize the Plyr time fix
     */
    function initialize() {
        console.log('⏰ Initializing Plyr Time Fix...');

        // Wait for DOM to be ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => {
                initializeExistingPlayers();
                setupMutationObserver();
            });
        } else {
            initializeExistingPlayers();
            setupMutationObserver();
        }

        // Also listen for window load in case some players are initialized later
        window.addEventListener('load', () => {
            setTimeout(initializeExistingPlayers, 2000);
        });
    }

    // Start the initialization
    initialize();

    // Expose globally for debugging
    window.HyperViewerPlyrTimeFix = {
        formatTime,
        customizePlayerTimeDisplay,
        initializeExistingPlayers
    };

    console.log('✅ Plyr Time Fix loaded successfully');
})();
