#!/bin/bash
# Deployment script for hyper_viewer
# Run this after git pull on the server

echo "🚀 Deploying hyper_viewer..."

# Change to the app directory
cd "$(dirname "$0")/.."

# Fix ownership for Nextcloud
echo "📁 Fixing file ownership..."
chown -R www:www .

# Optional: Install/update npm dependencies if package.json changed
if [ -f "package.json" ]; then
    echo "📦 Checking npm dependencies..."
    npm ci --production
fi

# Optional: Build assets if needed
if [ -f "package.json" ] && grep -q "build" package.json; then
    echo "🔨 Building assets..."
    npm run build
fi

echo "✅ Deployment complete!"
