# Cultivate RSS Builder

**Contributors:** billerickson  
**Requires at least:** 4.6  
**Tested up to:** 7.1\
**Stable tag:** 1.2.0\
**License:** GPLv2 or later  
**License URI:** http://www.gnu.org/licenses/gpl-2.0.html

**Requires PHP:** 7.0

Build custom RSS feeds for email marketing. Go to Settings > RSS Builder to create a new feed.

After making your selections, click "Get Feed URL" and copy the URL at the top of the page.

![screenshot](https://p198.p4.n0.cdn.getcloudapp.com/items/jkuROKz8/ef3adc89-e9a0-4195-b865-7a1897a3a88b.jpg?v=c7e978a02e000e3d8cc10b39cb545a99)

### Customization Options

- Limit to a Category
- Change the sort order ( most recent, recently updated, most comments, or random )
- Change how many are returned
- Control the offset (first posts excluded from feed) - useful when using multiple feeds in a single email campaign
- Change image size for featured image
- Exclude posts older than a date, or a `strtotime()` compatible string (ex: "-1 year ago")
- Exclude posts published since a date (ex: "1 month ago")
- Exclude posts in a specific category or tag


### Feed of updated posts

Set the order to "Recently Updated", and exclude posts published since "3 months ago". When a post is first published, the updated date equals the published date, which is why we want to exclude recently published posts.

![screenshot](https://p198.p4.n0.cdn.zight.com/items/nOuOXb8l/f0138563-bb42-4dd2-b260-4575cc5c1d5b.jpg?source=viewer&v=c1ad6c879c5b85c8ebdc9a02236b9eec)

### Custom RSS Post Title

Use the "RSS Post Title" panel in the block editor to specify a separate title for the RSS feed. The title saves with the normal post update through WordPress REST, without this plugin's classic metabox save. Existing RSS titles remain in the same metadata field and require no migration.

The classic-editor metabox remains available when using the classic editor, an unsupported REST controller, WordPress older than 5.3, or when the required native editor assets are unavailable. PHP 7.0 or newer is required. Removing this plugin's legacy metabox does not remove metabox save requests used by other plugins.

Both editors show the RSS title control to users who can edit the post and its RSS title metadata. REST clients can set the title by updating a post with an existing ID. When creating a post through the REST API, omit `cwp_rss_post_title` and set it in a subsequent update.

You can disable RSS titles using:

`add_filter( 'be_rss_builder_post_types', '__return_empty_array' );`

### No Newsletter tag

When building your feed, there's a checkbox labeled "Exclude posts tagged 'no-newsletter'". This allows content editors to easily mark certain content as excluded, like sponsored posts.

You should use the `ea_no_newsletter_term_id` filter to specify the term_id used for this tag. Ex:

`add_filter( 'ea_no_newsletter_term_id', function() { return 123; });`

### Feed settings and compatibility

Creating a feed requires the `manage_options` capability and a nonce-protected POST from Settings > RSS Builder. Existing `be_filter` hash URLs and inline-array feed URLs remain readable without saving settings. Version 1.2.0 saves each new feed separately so simultaneous submissions keep their own settings. Existing feeds continue to use their original URLs and stored settings without a migration.

The editor's additional REST field is `cwp_rss_post_title`, available only in the `edit` context. It accepts text; an empty string or `"0"` clears the override, matching previous behavior. Omitting it preserves the existing title. Native support requires a filtered post type that uses `WP_REST_Posts_Controller` or a compatible subclass. Autosaves omit this field, so unsaved RSS-title edits persist on the normal save rather than through autosave.

Feed-count behavior continues to follow WordPress core's existing RSS query normalization, including the site's syndication setting. This refactor does not change core's handling of `posts_per_page` for feeds.

For development checks and the disposable integration-test setup, see [the development checks](https://github.com/CultivateWP/Cultivate-RSS-Builder/blob/master/tests/README.md).
