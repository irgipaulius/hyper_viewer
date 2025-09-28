#!/bin/bash
# Post-pull script for hyper_viewer Nextcloud app
# Fixes file ownership after git pull
# Usage: bash scripts/post-pull.sh

echo "🔧 Fixing file ownership for hyper_viewer..."
chown -R www:www /usr/local/www/nextcloud/apps/hyper_viewer
echo "✅ File ownership fixed! App ready for Nextcloud."
