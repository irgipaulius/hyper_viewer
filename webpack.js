const webpackConfig = require('@nextcloud/webpack-vue-config')
const path = require('path')

// Configure multiple entry points
webpackConfig.entry = {
	'hyper_viewer-main': path.join(__dirname, 'src', 'main.js'),
	'settings': path.join(__dirname, 'src', 'settings.js'),
	'files-integration': path.join(__dirname, 'src', 'files-integration.js'),
	'player': path.join(__dirname, 'src', 'player.js')
}

// Fix output filename to avoid double app name
webpackConfig.output = {
	...webpackConfig.output,
	filename: '[name].js'
}

module.exports = webpackConfig
