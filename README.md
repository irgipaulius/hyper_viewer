# Hyper Viewer (Nextcloud app)

Prototype HLS-capable video viewer using Vue and Nextcloud components. This app will detect pre-generated HLS proxies and play them with Shaka Player.

## Development Setup
1. ☁ Clone this into your `apps` folder of your Nextcloud
2. 👩‍💻 In a terminal, run the command `make dev-setup` to install the dependencies
3. 🏗 Then to build the Javascript whenever you make changes, run `make build-js`
4. ✅ Enable the app through the app management of your Nextcloud
5. 🎉 Partytime!

## Server Deployment
After `git pull` on your server (as root), run this command:
```bash
bash scripts/post-pull.sh
```

This fixes file ownership so Nextcloud can access the app files properly.
