# Hyper Viewer (Nextcloud app)

Prototype HLS-capable video viewer using Vue and Nextcloud components. This app will detect pre-generated HLS proxies and play them with Shaka Player.

## Installation (No Build Required!)
1. ☁ Clone this into your `apps` folder of your Nextcloud
2. 🔧 Fix file ownership: `chown -R www:www hyper_viewer/`
3. ✅ Enable the app through the app management of your Nextcloud
4. 🎉 Ready to use!

**Note**: Build files are committed to the repository, so no Node.js or build step is required on the server.

## Development Setup
If you want to modify the frontend:
1. 📦 Install dependencies: `npm install`
2. 🔨 Build for development: `npm run dev` or `npm run watch`
3. 🚀 Build for production: `npm run build`
4. 📤 Commit the updated `js/` files

> NODE_OPTIONS="--openssl-legacy-provider" npm run build --fix 

## Server Deployment
After `git pull` on your server (as root), run this command:
```bash
bash scripts/post-pull.sh
```

This fixes file ownership so Nextcloud can access the app files properly.
