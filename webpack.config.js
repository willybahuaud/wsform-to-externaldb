const path = require( 'path' );
const MiniCssExtractPlugin = require( 'mini-css-extract-plugin' );
const baseConfig = require( '@wordpress/scripts/config/webpack.config' );

const entryPoints = {
	'ws-form-action-config': path.resolve( __dirname, 'src/js/ws-form-action-config.js' ),
};

module.exports = ( env, argv ) => {
	const config = { ...baseConfig };

	const plugins = ( config.plugins || [] ).map( ( plugin ) => {
		if (
			plugin &&
			plugin.constructor &&
			'MiniCssExtractPlugin' === plugin.constructor.name
		) {
			return new MiniCssExtractPlugin( {
				filename: '../css/[name].css',
			} );
		}

		return plugin;
	} );

	return {
		...config,
		entry: entryPoints,
		output: {
			...config.output,
			filename: '[name].js',
			path: path.resolve( __dirname, 'admin/js' ),
		},
		plugins,
	};
};
