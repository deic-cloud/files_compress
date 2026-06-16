const path = require('path')

// Build deps live in the sibling app's node_modules (same versions; avoids a
// duplicate install). resolve.modules makes every bare require — including
// nested ones — fall back there.
const DEPS = path.join(__dirname, '..', 'user_group_admin', 'node_modules')

module.exports = (env, argv) => {
	const isDev = argv.mode === 'development'
	return {
		mode: isDev ? 'development' : 'production',
		devtool: false,
		entry: { 'files-action': path.join(__dirname, 'src', 'files-action.js') },
		output: {
			path: path.join(__dirname, 'js'),
			filename: '[name].js',
			clean: false,
		},
		resolve: { extensions: ['.js', '.mjs'], modules: [DEPS, 'node_modules'], fallback: { stream: false } },
		resolveLoader: { modules: [DEPS, 'node_modules'] },
		optimization: { splitChunks: false },
		module: {
			rules: [
				{
					test: /\.m?js$/,
					exclude: /node_modules/,
					use: { loader: 'babel-loader', options: { presets: [require.resolve('@babel/preset-env', { paths: [DEPS] })] } },
				},
				{ test: /\.css$/, use: ['style-loader', 'css-loader'] },
			],
		},
		externals: { OC: 'OC', OCA: 'OCA', OCP: 'OCP' },
	}
}
