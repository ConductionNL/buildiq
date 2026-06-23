// SPDX-License-Identifier: EUPL-1.2
const path = require('path')
const fs = require('fs')
const webpack = require('webpack')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const { VueLoaderPlugin } = require('vue-loader')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'
webpackConfig.devtool = isDev ? 'cheap-source-map' : 'source-map'

webpackConfig.stats = {
	colors: true,
	modules: false,
}

const appId = 'openbuild'
webpackConfig.entry = {
	main: {
		import: path.join(__dirname, 'src', 'main.js'),
		filename: appId + '-main.js',
	},
	adminSettings: {
		import: path.join(__dirname, 'src', 'settings.js'),
		filename: appId + '-settings.js',
	},
	// Standalone runtime for a published virtual app (/apps/openbuild/builder/{slug}).
	builder: {
		import: path.join(__dirname, 'src', 'builder.js'),
		filename: appId + '-builder.js',
	},
}

// Use local source when available (monorepo dev), otherwise fall back to npm
// package. A local checkout is only used when its version satisfies this app's
// declared @conduction/nextcloud-vue range — otherwise a STALE local checkout
// (e.g. beta.7 when the app needs ^beta.101) would be silently bundled instead
// of the resolved node_modules package, producing wrong/broken builds.
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const localLibPkg = path.resolve(__dirname, '../nextcloud-vue/package.json')
let useLocalLib = fs.existsSync(localLib)
if (useLocalLib && fs.existsSync(localLibPkg)) {
	try {
		const semver = require('semver')
		const required = require('./package.json').dependencies['@conduction/nextcloud-vue']
		const localVersion = require(localLibPkg).version
		if (!semver.satisfies(localVersion, required, { includePrerelease: true })) {
			// eslint-disable-next-line no-console
			console.warn(
				`[webpack] Ignoring local ../nextcloud-vue (v${localVersion}); it does not satisfy `
				+ `the required range "${required}". Building against node_modules instead.`,
			)
			useLocalLib = false
		}
	} catch (e) {
		// semver unavailable or package read failed — keep the existsSync default.
	}
}

webpackConfig.resolve = {
	extensions: ['.vue', '.js'],
	alias: {
		'@': path.resolve(__dirname, 'src'),
		...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
		// Deduplicate shared packages so the aliased library source uses
		// the same instances as the app (prevents dual-Pinia / dual-Vue bugs).
		'vue$': path.resolve(__dirname, 'node_modules/vue'),
		'pinia$': path.resolve(__dirname, 'node_modules/pinia'),
		'@nextcloud/vue$': path.resolve(__dirname, 'node_modules/@nextcloud/vue'),
	},
}

webpackConfig.module = {
	rules: [
		{
			test: /\.vue$/,
			loader: 'vue-loader',
		},
		{
			test: /\.css$/,
			use: ['style-loader', 'css-loader'],
		},
		{
			test: /\.scss$/,
			use: ['style-loader', 'css-loader', 'sass-loader'],
		},
	],
}

webpackConfig.plugins = [
	new VueLoaderPlugin(),
	new webpack.DefinePlugin({ appName: JSON.stringify(appId) }),
	new webpack.DefinePlugin({ appVersion: JSON.stringify(process.env.npm_package_version) }),
]

// Force @nextcloud/dialogs to resolve from this app's node_modules,
// preventing the nextcloud-vue submodule's nested deps (Vue 3) from leaking in.
webpackConfig.resolve.alias['@nextcloud/dialogs'] = path.resolve(__dirname, 'node_modules/@nextcloud/dialogs')

module.exports = webpackConfig
