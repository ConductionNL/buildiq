// SPDX-License-Identifier: EUPL-1.2
const path = require('path')
const fs = require('fs')
const webpack = require('webpack')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const { VueLoaderPlugin } = require('vue-loader')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'
// Production builds disable source maps entirely. The full `source-map` devtool
// (and Terser's own source-map generation) added significant memory and time on
// top of compilation, and emitted large .map files into js/ (openbuild has three
// entries — main/settings/builder — each bundling the shared nextcloud-vue lib).
// Dropping them keeps the output minified while lowering peak memory. Dev keeps
// cheap, fast line-level maps. Mirrors pipelinq/openregister.
webpackConfig.devtool = isDev ? 'cheap-source-map' : false

webpackConfig.stats = {
	colors: true,
	modules: false,
}

// @nextcloud/webpack-vue-config hardcodes publicPath to `/apps/{appId}/js/`, but an
// app installed under custom_apps is served from `/custom_apps/{appId}/js/`. Async
// chunks were therefore requested from a path that routes into the app and returns
// HTML, so the browser refused them on MIME grounds and the component never mounted.
// This stayed latent while only rarely-used components were code-split; it turns
// fatal as soon as an always-rendered one (CnDashboardPage) lands in a chunk.
// `'auto'` derives the path from the executing script's own URL, so it is correct
// under both apps/ and custom_apps/.
webpackConfig.output = { ...webpackConfig.output, publicPath: 'auto' }

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
let useLocalLib = process.env.USE_LOCAL_LIB !== 'false' && fs.existsSync(localLib)
if (useLocalLib && fs.existsSync(localLibPkg)) {
	try {
		// semver is an optional transitive dep — the try/catch degrades
		// gracefully when it is absent, so it is intentionally not declared
		// as a direct dependency of this app.
		// eslint-disable-next-line n/no-extraneous-require
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
	// @conduction/nextcloud-vue deliberately bundles @nextcloud/dialogs v6 into
	// its dist (to pin `spawnDialog` across consumers that alias an older
	// dialogs). That bundled FilePicker chunk imports node's `path`, and
	// webpack 5 no longer polyfills node builtins automatically — without this
	// fallback the build fails with "Can't resolve 'path'".
	fallback: { path: require.resolve('path-browserify') },
	alias: {
		'@': path.resolve(__dirname, 'src'),
		...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
		// Deduplicate shared packages so the aliased library source uses
		// the same instances as the app (prevents dual-Pinia / dual-Vue bugs).
		vue$: path.resolve(__dirname, 'node_modules/vue'),
		pinia$: path.resolve(__dirname, 'node_modules/pinia'),
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
// Register the exact-match style.css alias BEFORE the bare package alias below:
// enhanced-resolve applies the first matching entry, and the bare alias maps the
// package to its DIRECTORY, so '@nextcloud/dialogs/style.css' (imported by
// nextcloud-vue's useAppInstaller) would resolve to a non-existent root style.css.
// dialogs v6 ships the stylesheet at dist/style.css behind its "exports" map.
webpackConfig.resolve.alias['@nextcloud/dialogs/style.css$'] = path.resolve(__dirname, 'node_modules/@nextcloud/dialogs/dist/style.css')
webpackConfig.resolve.alias['@nextcloud/dialogs'] = path.resolve(__dirname, 'node_modules/@nextcloud/dialogs')

// dialogs v6 drags in a FilePicker chunk that imports node's `path`, and webpack 5 no
// longer auto-polyfills node core modules — without this the bundle fails to emit with
// "Can't resolve 'path'". This app only uses the toast APIs (showError/showSuccess), so
// the FilePicker code path never runs and an empty module is safe.
webpackConfig.resolve.fallback = {
	...(webpackConfig.resolve.fallback || {}),
	path: false,
}

module.exports = webpackConfig
