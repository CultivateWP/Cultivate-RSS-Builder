# RSS editor regression checks

The editor script uses native WordPress globals and needs no build step or npm dependencies. With Node.js:

```sh
node --check assets/editor.js
node --test tests/editor.test.mjs
```

For backend checks, activate the plugin in a fresh disposable WordPress site with the URL `http://cultivate-rss-qa.test`, then run:

```sh
wp eval-file /path/to/plugin/tests/integration.php
```

The script refuses other hosts. It creates and removes synthetic users/posts, so never run it on a client or production site. It covers normal REST saves, permissions and rejected inputs, legacy title storage and RSS output, custom REST namespaces/post types, classic saves, metabox fallback selection and autosaves. It does not audit the rest of the plugin.

The additional REST field is `cwp_rss_post_title` in edit context. Existing-object input and metadata permissions are checked before content writes. On collection creation, WordPress creates the draft before calling additional-field writers. A third-party metadata permission denial may therefore leave a new draft without the RSS title. This is tested; the plugin does not attempt to roll back arbitrary core/third-party side effects.

Backend and JavaScript unit tests do not verify the rendered editor. Before release, check title edit/save/reload/clear, new posts, keyboard operation, and classic fallback in a browser. These tests do not establish a save-time benchmark or remove legacy requests needed by other plugins.
