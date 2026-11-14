# Development checks

Install development dependencies with PHP 8.3 and Composer 2:

```sh
composer install
composer validate --strict
composer check
```

`composer lint:php` checks PHP syntax. `composer lint:wpcs` runs the complete
WordPress coding standard and PHPCompatibilityWP, targeting PHP 7.0 and later.
These checks cover the plugin entry point and first-party `includes` code. The
bundled third-party `includes/updater` library is deliberately excluded and must
be reviewed separately when updating that dependency. Do not ship `vendor` or
other development dependencies in the plugin archive.

The CI workflow runs standards on PHP 8.3 and syntax checks on PHP 7.0, 7.4, and
8.3. A compatibility sniff is static analysis, not evidence that the plugin ran
on every supported WordPress/PHP combination.

## JavaScript checks

With Node.js 24 LTS:

```sh
npm ci
npm run check:js
npm run lint:js
npm test
```

ESLint uses the official `@wordpress/eslint-plugin` ESNext and internationalization
rules, including this plugin's text domain. The shipped script uses WordPress
browser globals directly and needs no bundler or `wp-scripts` build. Unit tests
run with Node's built-in test runner. CI repeats these checks.

## Integration runtime

Use a fresh disposable WordPress installation, activate `cultivate-rss-builder`,
and execute the integration script through WP-CLI:

```sh
wp eval-file /path/to/plugin/tests/integration.php
```

The installation must use the URL `http://cultivate-rss-qa.test`; the script refuses other hosts. The integration checks cover real REST saves, permissions and invalid input, classic saves, old hashes and serialized settings, nonce-protected feed creation, international taxonomy queries, RSS2 XML/media output, GMT timestamps, fallback selection, and autosaves.

On WordPress 5.3 and later, the additional REST field is `cwp_rss_post_title` in edit context. Set it on an existing post. A collection create that includes the field returns HTTP 400 before insertion. The block editor already has an auto-draft ID on its first save. On WordPress 4.7–5.2, the suite checks the posts REST endpoint with the classic fallback active.

The script writes test fixtures. Run it only in a dedicated local test database,
never on a client or production site. It uses the real WordPress backend and
REST dispatch, so it complements syntax and standards checks without requiring
browser automation. Backend checks do not verify the editor's rendered layout,
keyboard operation, or client-side interactions. Verify those in the native
browser separately.

For Docker, mount the checkout into
`/var/www/html/wp-content/plugins/cultivate-rss-builder`, share the fresh WordPress
volume with a matching `wordpress:cli-*` container, and use a new Compose project
and database. No host port is necessary for WP-CLI integration checks. Keep
machine-specific Compose files and disposable state outside the repository.

The workflow exercises WordPress 6.8.3/PHP 7.4 and the latest WordPress/PHP 8.3.
The advertised WordPress 4.6 classic fallback and PHP 7.0 minimum also receive
static compatibility checks, but need separate runtime coverage before a release
claims those combinations were tested. Browser verification should cover a title
edit, save, reload, clear, new post, keyboard operation, and the classic fallback.

## Plugin Check scope

The CI Plugin Check job targets static security and performance patterns in
first-party production code. It excludes development dependencies, test fixtures,
and the unchanged bundled updater (`includes/updater`). No diagnostic codes are
ignored. Run a full Plugin Check separately when changing that dependency or
preparing a distribution package.

The existing GitHub updater is intentional and is incompatible with WordPress.org
plugin-directory rules. Raw source also contains development files that do not
belong in a release ZIP. Those directory/distribution findings are separate from
this refactor's scoped static checks, and a scoped pass is not a clean whole-plugin
Plugin Check report or proof of security or speed.
