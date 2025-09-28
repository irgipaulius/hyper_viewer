const webpackConfig = require('@nextcloud/webpack-vue-config')
const path = require('path')

// Configure multiple entry points
webpackConfig.entry = {
	'hyper_viewer-main': path.join(__dirname, 'src', 'main.js'),
	'settings': path.join(__dirname, 'src', 'settings.js')
}

module.exports = webpackConfig
