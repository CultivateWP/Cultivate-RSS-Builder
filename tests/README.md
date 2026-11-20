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

On WordPress 5.3 and later, the additional REST field is `cwp_rss_post_title` in edit context. Set it on an existing post. A collection create that includes the field returns HTTP 400 before insertion. The block editor already has an auto-draft ID on its first save. On WordPress 4.7–5.2, the suite checks the posts REST endpoint with the classic fallback active.

Backend and JavaScript unit tests do not verify the rendered editor. Before release, check title edit/save/reload/clear, new posts, keyboard operation, and classic fallback in a browser. These tests do not establish a save-time benchmark or remove legacy requests needed by other plugins.
