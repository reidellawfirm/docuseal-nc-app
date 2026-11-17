const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

// Override entry points
webpackConfig.entry = {
	adminSettings: path.join(__dirname, 'src', 'adminSettings.js'),
	filesplugin: path.join(__dirname, 'src', 'filesplugin.js'),
	sidebar: path.join(__dirname, 'src', 'sidebar.js'),
}

// Fix Webpack 5 ESM "fully specified" errors for axios, webdav, @vue/devtools-shared, etc.
webpackConfig.module = webpackConfig.module || { rules: [] }
webpackConfig.module.rules = webpackConfig.module.rules || []
webpackConfig.module.rules.push({
	test: /\.m?js$/,
	include: /node_modules/,
	resolve: { fullySpecified: false },
})

// The NC webpack-vue-config hardcodes publicPath to '/apps/{appName}/js/' and,
// when built on Windows, even serializes it with backslashes. Both break
// dynamic chunk loading (the DocuSealModal lazy import) when the app is
// installed in custom_apps/ instead of apps/.
// Setting 'auto' makes webpack compute the base URL at runtime from the
// <script> src attribute that Nextcloud generates, which already points at
// the correct path (/apps/ or /custom_apps/), with forward slashes.
webpackConfig.output = Object.assign({}, webpackConfig.output, {
	publicPath: 'auto',
})

module.exports = webpackConfig
