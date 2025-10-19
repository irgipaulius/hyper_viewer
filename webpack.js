const webpackConfig = require('@nextcloud/webpack-vue-config')
const path = require('path')

// Configure multiple entry points
webpackConfig.entry = {
	'settings': path.join(__dirname, 'src', 'settings.js'),
	'files-integration': path.join(__dirname, 'src', 'files-integration.js')
}

// Fix output filename to avoid double app name
webpackConfig.output = {
	...webpackConfig.output,
	filename: '[name].js'
}

module.exports = webpackConfig
