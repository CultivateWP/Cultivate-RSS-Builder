# Cultivate RSS Builder

**Contributors:** billerickson  
**Requires at least:** 4.6  
**Tested up to:** 6.8  
**Stable tag:** 1.1.0  
**License:** GPLv2 or later  
**License URI:** http://www.gnu.org/licenses/gpl-2.0.html

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

This plugin also adds a "RSS Post Title" metabox for specifying a separate title for use in the RSS feed. You can disable this using:

`add_filter( 'be_rss_builder_post_types', '__return_empty_array' );`

### No Newsletter tag

When building your feed, there's a checkbox labeled "Exclude posts tagged 'no-newsletter'". This allows content editors to easily mark certain content as excluded, like sponsored posts.

You should use the `ea_no_newsletter_term_id` filter to specify the term_id used for this tag. Ex:

`add_filter( 'ea_no_newsletter_term_id', function() { return 123; });`
