import wordpress from '@wordpress/eslint-plugin';

export default [
	{ ignores: [ 'vendor/**', 'includes/updater/**', 'node_modules/**' ] },
	...wordpress.configs.esnext,
	...wordpress.configs.i18n,
	{
		files: [ 'assets/editor.js' ],
		languageOptions: {
			globals: { window: 'readonly' },
		},
		rules: {
			'@wordpress/i18n-text-domain': [ 'error', { allowedTextDomain: 'cultivate-rss-builder' } ],
		},
	},
];
