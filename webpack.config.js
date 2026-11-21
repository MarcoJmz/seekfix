/**
 * Build for the admin screen.
 *
 * WordPress 6.4 ships @wordpress/components and @wordpress/data versions that
 * are too old for @wordpress/dataviews, so every @wordpress package is bundled
 * at the version pinned in package.json. Only these stay external, because
 * they must be the same instance WordPress loads, and their APIs used here
 * exist since WordPress 6.4: React, ReactDOM and the JSX runtime, i18n
 * (translations), hooks (used by i18n), api-fetch (REST nonce) and date (site
 * timezone; dataviews only calls date, dateI18n, getDate and getSettings).
 *
 * @package SeekFix
 */

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

const SHARED = [
	'react',
	'react-dom',
	'react-dom/client',
	'react/jsx-runtime',
	'react/jsx-dev-runtime',
	'@wordpress/i18n',
	'@wordpress/hooks',
	'@wordpress/api-fetch',
	'@wordpress/date',
];

const bundleTheRest = ( request ) => {
	if ( SHARED.includes( request ) ) {
		return undefined;
	}

	return request.startsWith( '@wordpress/' ) ? false : undefined;
};

module.exports = {
	...defaultConfig,
	entry: {
		admin: './assets/src/index.js',
		'jsx-runtime-shim': './assets/src/jsx-runtime-shim.js',
	},
	output: {
		...defaultConfig.output,
		path: __dirname + '/assets/build',
	},
	plugins: [
		...defaultConfig.plugins.filter( ( plugin ) => ! ( plugin instanceof DependencyExtractionWebpackPlugin ) ),
		new DependencyExtractionWebpackPlugin( {
			requestToExternal: bundleTheRest,
			requestToHandle: bundleTheRest,
		} ),
	],
};
