<?php
/** Real WordPress integration checks. Run only in the dedicated fresh QA site. */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'cultivate-rss-qa.test' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
	throw new RuntimeException( 'Run only in the dedicated Cultivate RSS QA installation.' );
}

$GLOBALS['cwp_rss_test_count'] = 0;
function cwp_rss_assert( $condition, $message ) {
	++$GLOBALS['cwp_rss_test_count'];
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function cwp_rss_request( $method, $route, $params, $user ) {
	wp_set_current_user( $user );
	$request = new WP_REST_Request( $method, $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_get_server()->dispatch( $request );
}
class CWP_RSS_Test_Stop extends Exception {}

$builder = null;
foreach ( $GLOBALS['wp_filter']['pre_get_posts']->callbacks as $callbacks ) {
	foreach ( $callbacks as $callback ) {
		$function = $callback['function'];
		if ( is_array( $function ) && $function[0] instanceof CWP_RSS_Builder ) {
			$builder = $function[0];
		}
	}
}
cwp_rss_assert( $builder instanceof CWP_RSS_Builder, 'Plugin bootstrap must register its feed hooks.' );
if ( version_compare( get_bloginfo( 'version' ), '5.3', '<' ) ) {
	$response = cwp_rss_request( 'GET', '/wp/v2/posts', array(), 0 );
	cwp_rss_assert( 200 === $response->get_status(), 'Legacy installs can still read the posts REST endpoint.' );
	WP_CLI::success( 'Legacy REST check passed on WordPress ' . get_bloginfo( 'version' ) . '.' );
	return;
}

$before_option = get_option( 'be_rss_builder', false );
$before_get = $_GET;
$before_post = $_POST;
$before_request = $_REQUEST;
$before_method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null;
$before_user = get_current_user_id();
$before_main_query = $GLOBALS['wp_the_query'];
$before_global_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
$before_wp_query = $GLOBALS['wp_query'];
$before_timezone = get_option( 'timezone_string' );
$had_custom_fields = post_type_supports( 'post', 'custom-fields' );
$feed_options = array();
$track_feed = static function ( $name ) use ( &$feed_options ) {
	if ( 0 === strpos( $name, 'cwp_rss_builder_feed_' ) ) {
		$feed_options[] = $name;
	}
};
add_action( 'added_option', $track_feed );
$post_ids = array();
$users = array();
$terms = array();
$allowed_types = static function () { return array( 'post', 'cwp_rss_test' ); };
$die_handler = static function () {
	return static function ( $message, $title = '', $args = array() ) {
		throw new CWP_RSS_Test_Stop( 'die:' . ( isset( $args['response'] ) ? $args['response'] : 0 ) );
	};
};
$redirect_handler = static function ( $location, $status ) {
	throw new CWP_RSS_Test_Stop( 'redirect:' . $status . ':' . $location );
};

try {
	$token = wp_generate_password( 8, false );
	foreach ( array( 'administrator', 'author', 'author', 'subscriber' ) as $index => $role ) {
		$id = wp_insert_user( array( 'user_login' => 'cwp_' . $token . '_' . $index, 'user_pass' => wp_generate_password(), 'role' => $role ) );
		cwp_rss_assert( ! is_wp_error( $id ), 'Create isolated role fixture.' );
		$users[] = $id;
	}
	list( $admin, $author, $other_author, $subscriber ) = $users;
	$id = wp_insert_post( array( 'post_title' => 'RSS integration fixture', 'post_content' => 'Original body', 'post_status' => 'publish', 'post_author' => $author ), true );
	cwp_rss_assert( ! is_wp_error( $id ), 'Create owned post fixture.' );
	$post_ids[] = $id;
	register_post_type( 'cwp_rss_test', array( 'public' => true, 'show_in_rest' => true, 'rest_base' => 'rss-articles', 'rest_namespace' => 'cwp-tests/v1', 'supports' => array( 'title', 'editor' ) ) );
	add_filter( 'be_rss_builder_post_types', $allowed_types );
	remove_post_type_support( 'post', 'custom-fields' );
	$GLOBALS['wp_rest_server'] = null;
	rest_get_server();
	cwp_rss_assert( ! post_type_supports( 'post', 'custom-fields' ), 'REST field must not enable the legacy Custom Fields feature.' );
	$field = CWP_RSS_Builder_Editor::FIELD;
	$path = '/wp/v2/posts/' . $id;

	foreach ( array( 'Fish & chips', 'Quotes "double" and \'single\'', 'A \\ backslash', '<b>literal title</b>', '&lt; &amp; literal entities' ) as $title ) {
		$response = cwp_rss_request( 'POST', $path, array( $field => $title ), $author );
		cwp_rss_assert( 200 === $response->get_status(), 'Author can save RSS title through normal post REST update.' );
		cwp_rss_assert( esc_attr( $title ) === get_post_meta( $id, 'be_rss_post_title', true ), 'Encoded legacy storage and literal backslashes must survive.' );
		$read = cwp_rss_request( 'GET', $path, array( 'context' => 'edit' ), $author )->get_data();
		cwp_rss_assert( html_entity_decode( esc_attr( $title ), ENT_QUOTES | ENT_HTML5, get_option( 'blog_charset' ) ) === $read[ $field ], 'Read the same decoded text shown by the old field.' );
	}
	$stable_title = get_post_meta( $id, 'be_rss_post_title', true );
	cwp_rss_request( 'POST', $path, array( 'content' => 'Body-only edit' ), $author );
	cwp_rss_assert( $stable_title === get_post_meta( $id, 'be_rss_post_title', true ), 'Omitted RSS field must preserve its value.' );
	foreach ( array( null, array( 'bad' ), 42, false ) as $invalid ) {
		$before_body = get_post( $id )->post_content;
		$response = cwp_rss_request( 'POST', $path, array( $field => $invalid, 'content' => 'Must not be written' ), $author );
		cwp_rss_assert( 400 === $response->get_status(), 'Invalid field type must be rejected.' );
		cwp_rss_assert( $before_body === get_post( $id )->post_content && $stable_title === get_post_meta( $id, 'be_rss_post_title', true ), 'Invalid input must fail before content or title is changed.' );
	}
	foreach ( array( 0, $subscriber, $other_author ) as $unauthorized ) {
		$response = cwp_rss_request( 'POST', $path, array( $field => 'Forbidden', 'content' => 'Forbidden body' ), $unauthorized );
		cwp_rss_assert( in_array( $response->get_status(), array( 401, 403 ), true ), 'Anonymous, subscriber and other author writes must be denied.' );
		cwp_rss_assert( 'Body-only edit' === get_post( $id )->post_content && $stable_title === get_post_meta( $id, 'be_rss_post_title', true ), 'Denied updates must have no article side effects.' );
	}
	$view = cwp_rss_request( 'GET', $path, array( 'context' => 'view' ), 0 )->get_data();
	cwp_rss_assert( ! array_key_exists( $field, $view ), 'Private editor REST field must be absent from public responses.' );
	$create_title = 'RSS collection fixture ' . $token;
	$create_params = array( 'title' => $create_title, 'status' => 'publish' );
	foreach ( array( 'New feed title', null ) as $value ) {
		$create_params[ $field ] = $value;
		$rejected = cwp_rss_request( 'POST', '/wp/v2/posts', $create_params, $author );
		$matching_posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'title' => $create_title, 'fields' => 'ids' ) );
		$post_ids = array_merge( $post_ids, $matching_posts );
		cwp_rss_assert( 400 === $rejected->get_status() && empty( $matching_posts ), 'Reject the RSS field on collection create before a published post can be inserted.' );
	}
	unset( $create_params[ $field ] );
	$created = cwp_rss_request( 'POST', '/wp/v2/posts', $create_params, $author );
	cwp_rss_assert( 201 === $created->get_status(), 'Collection create without the RSS field still succeeds.' );
	$post_ids[] = $created->get_data()['id'];
	$cpt = wp_insert_post( array( 'post_type' => 'cwp_rss_test', 'post_title' => 'CPT fixture', 'post_author' => $author ), true ); $post_ids[] = $cpt;
	$cpt_response = cwp_rss_request( 'POST', '/cwp-tests/v1/rss-articles/' . $cpt, array( $field => 'Custom route title' ), $author );
	cwp_rss_assert( 200 === $cpt_response->get_status(), 'Support filtered CPTs with custom namespace and REST base.' );
	cwp_rss_assert( 'Custom route title' === get_post_meta( $cpt, 'be_rss_post_title', true ), 'CPT title persists without custom-fields support.' );
	foreach ( array( '', '0' ) as $empty ) {
		cwp_rss_request( 'POST', $path, array( $field => $empty ), $author );
		cwp_rss_assert( ! metadata_exists( 'post', $id, 'be_rss_post_title' ), 'Empty and zero retain legacy deletion semantics.' );
	}
	update_post_meta( $id, 'be_rss_post_title', array( 'malformed' ) );
	$bad_stored = cwp_rss_request( 'POST', $path, array( $field => 'Do not replace malformed data', 'content' => 'Do not change body' ), $author );
	cwp_rss_assert( 409 === $bad_stored->get_status(), 'Reject malformed stored metadata before writing post content.' );
	cwp_rss_assert( 'Body-only edit' === get_post( $id )->post_content, 'Malformed stored title cannot cause a partial article edit.' );
	delete_post_meta( $id, 'be_rss_post_title' );

	wp_set_current_user( $author );
	$classic = 'Classic & "title" \\ path';
	$_POST = array( 'be_rss_builder_nonce' => wp_create_nonce( 'be_rss_builder' ), 'be_rss_post_title' => wp_slash( $classic ) );
	$builder->metabox_save( $id, get_post( $id ) );
	cwp_rss_assert( esc_attr( $classic ) === get_post_meta( $id, 'be_rss_post_title', true ), 'Classic field preserves text/backslashes with a valid nonce.' );
	foreach ( array( array(), array( 'be_rss_builder_nonce' => 'invalid', 'be_rss_post_title' => '' ), array( 'be_rss_builder_nonce' => wp_create_nonce( 'be_rss_builder' ) ), array( 'be_rss_builder_nonce' => wp_create_nonce( 'be_rss_builder' ), 'be_rss_post_title' => array() ) ) as $payload ) {
		$_POST = $payload; $builder->metabox_save( $id, get_post( $id ) );
		cwp_rss_assert( esc_attr( $classic ) === get_post_meta( $id, 'be_rss_post_title', true ), 'Missing/invalid classic input cannot clear a saved title.' );
	}
	wp_set_current_user( $other_author );
	$_POST = array( 'be_rss_builder_nonce' => wp_create_nonce( 'be_rss_builder' ), 'be_rss_post_title' => 'Denied' );
	$builder->metabox_save( $id, get_post( $id ) );
	cwp_rss_assert( esc_attr( $classic ) === get_post_meta( $id, 'be_rss_post_title', true ), 'Classic save checks actual post permission.' );

	$old_settings = array( 'posts_per_page' => '37', 'offset' => '2', 'date' => '-1 year', 'orderby' => 'modified', 'category' => 'newsletter', 'no_newsletter' => '0' );
	$old_hash = sha1( serialize( $old_settings ) );
	$incompatible_settings = array( 'offset' => 'historical value' );
	$incompatible_hash = sha1( serialize( $incompatible_settings ) );
	$old_map = array( $old_hash => $old_settings, $incompatible_hash => $incompatible_settings );
	update_option( 'be_rss_builder', maybe_serialize( $old_map ), false );
	$raw = get_option( 'be_rss_builder' );
	wp_set_current_user( 0 );
	$_GET = array( 'be_filter' => $old_hash );
	$expected_settings = $old_settings;
	$expected_settings['no_newsletter'] = '';
	cwp_rss_assert( $expected_settings === $builder->get_feed_data(), 'A saved legacy feed keeps its category and other filters when the checkbox is zero.' );
	cwp_rss_assert( $raw === get_option( 'be_rss_builder' ), 'Public hash read cannot rewrite storage.' );
	$_GET = array( 'be_filter' => array( 'posts_per_page' => '37', 'no_newsletter' => '0', 'date' => '2025-01-02T00:00:00+05:30' ) );
	cwp_rss_assert( '37' === $builder->get_feed_data()['posts_per_page'], 'Old public array URLs support values outside the UI dropdown.' );
	$builder->get_hash();
	wp_parse_str( wp_parse_url( $builder->get_feed_url(), PHP_URL_QUERY ), $linked_query );
	cwp_rss_assert( '2025-01-02T00:00:00+05:30' === $linked_query['be_filter']['date'], 'Following an inline feed link preserves the timezone offset.' );
	cwp_rss_assert( $raw === get_option( 'be_rss_builder' ), 'Public array feed/hash/URL helpers must be read-only.' );
	$_GET = array( 'be_filter' => array( 'orderby' => array( 'nested' ) ) );
	cwp_rss_assert( array() === $builder->get_feed_data(), 'Nested public settings cannot reach query sinks.' );
	cwp_rss_assert( is_wp_error( $builder->get_settings_hash( array( 'orderby' => 'modified' ) ) ), 'Unauthenticated programmatic settings writes are denied.' );
	wp_set_current_user( $admin );
	// Complete a second save after the first reader has obtained its legacy snapshot.
	$other_settings = array( 'category' => 'second-' . strtolower( $token ) );
	$new_settings = array( 'category' => 'first-' . strtolower( $token ) );
	$other_hash = null;
	$overlap = static function ( $snapshot ) use ( &$overlap, &$other_hash, $builder, $other_settings ) {
		remove_filter( 'option_be_rss_builder', $overlap );
		$other_hash = $builder->get_settings_hash( $other_settings );
		return $snapshot;
	};
	add_filter( 'option_be_rss_builder', $overlap );
	$new_hash = $builder->get_settings_hash( $new_settings );
	remove_filter( 'option_be_rss_builder', $overlap );
	foreach ( array( array( $other_hash, $other_settings ), array( $new_hash, $new_settings ) ) as list( $hash, $expected ) ) {
		$_GET = array( 'be_filter' => $hash );
		cwp_rss_assert( is_string( $hash ) && $expected === $builder->get_feed_data(), 'Each overlapping save returns a URL that resolves to its own settings.' );
	}
	cwp_rss_assert( $new_hash === $builder->get_settings_hash( $new_settings ), 'Retrying a feed save returns the same usable hash.' );
	cwp_rss_assert( $raw === get_option( 'be_rss_builder' ), 'Creating new feeds preserves the serialized legacy map.' );
	update_option( 'be_rss_builder', 'broken legacy settings' );
	$malformed_before = get_option( 'be_rss_builder' );
	cwp_rss_assert( is_wp_error( $builder->get_settings_hash( array( 'orderby' => 'modified' ) ) ), 'Malformed storage is not replaced with an empty map.' );
	cwp_rss_assert( $malformed_before === get_option( 'be_rss_builder' ), 'Malformed storage is preserved for recovery.' );
	update_option( 'be_rss_builder', maybe_serialize( $old_map ), false );

	add_filter( 'wp_die_handler', $die_handler );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	foreach ( array( $subscriber, $admin ) as $user ) {
		wp_set_current_user( $user );
		$_POST = array( 'be_filter' => array( 'orderby' => 'rand' ), 'cwp_rss_builder_nonce' => 'invalid' );
		$_REQUEST = $_POST;
		$before = get_option( 'be_rss_builder' ); $stopped = false;
		try { $builder->maybe_redirect(); } catch ( CWP_RSS_Test_Stop $e ) { $stopped = true; }
		cwp_rss_assert( $stopped && $before === get_option( 'be_rss_builder' ), 'Settings POST requires both capability and valid nonce before writes.' );
	}
	wp_set_current_user( $admin );
	$_POST = array( 'be_filter' => array( 'orderby' => 'rand' ), 'cwp_rss_builder_nonce' => wp_create_nonce( 'cwp_rss_builder_create_feed' ) );
	$_REQUEST = $_POST;
	add_filter( 'wp_redirect', $redirect_handler, -PHP_INT_MAX, 2 );
	$redirected = false;
	try { $builder->maybe_redirect(); } catch ( CWP_RSS_Test_Stop $e ) { $redirected = 0 === strpos( $e->getMessage(), 'redirect:303:' ); }
	cwp_rss_assert( $redirected, 'Authorized nonce-protected POST redirects using Post/Redirect/Get.' );
	remove_filter( 'wp_redirect', $redirect_handler, -PHP_INT_MAX );
	remove_filter( 'wp_die_handler', $die_handler );

	// Exercise actual feed queries and XML, including non-Latin WordPress slugs.
	$term = wp_insert_term( '中文 ' . $token, 'category', array( 'slug' => '中文' . $token ) );
	$excluded = wp_insert_term( '日本語 ' . $token, 'category', array( 'slug' => '日本語' . $token ) );
	cwp_rss_assert( ! is_wp_error( $term ) && ! is_wp_error( $excluded ), 'Create international taxonomy fixtures.' );
	$terms = array( $term['term_id'], $excluded['term_id'] );
	$slug = get_term( $term['term_id'], 'category' )->slug;
	$excluded_slug = get_term( $excluded['term_id'], 'category' )->slug;
	cwp_rss_assert( false !== strpos( $slug, '%' ), 'International fixture must use a percent-encoded WordPress slug.' );
	wp_set_post_categories( $id, array( $term['term_id'] ) );
	$control_id = wp_insert_post( array( 'post_title' => 'Unmatched control post', 'post_status' => 'publish', 'post_author' => $author ), true ); $post_ids[] = $control_id;
	$international_settings = array( 'category' => $slug, 'exclude_category' => $excluded_slug, 'orderby' => 'title', 'posts_per_page' => '-1', 'image_size' => 'full' );
	$international_hash = $builder->get_settings_hash( $international_settings );
	$_GET = array( 'be_filter' => $international_hash );
	cwp_rss_assert( $international_settings === $builder->get_feed_data(), 'International slugs, legacy title order and -1 survive settings storage.' );
	$q = new WP_Query();
	$GLOBALS['wp_query'] = $q; $GLOBALS['wp_the_query'] = $q;
	$observed_feed_count = null;
	$capture_count = static function ( $query ) use ( $q, &$observed_feed_count ) {
		if ( $query === $q ) { $observed_feed_count = $query->get( 'posts_per_page' ); }
	};
	add_action( 'pre_get_posts', $capture_count, PHP_INT_MAX );
	$q->query( array( 'feed' => 'rss2', 'post_type' => 'post', 'post_status' => 'publish' ) );
	remove_action( 'pre_get_posts', $capture_count, PHP_INT_MAX );
	cwp_rss_assert( 'title' === $q->get( 'orderby' ) && -1 === $observed_feed_count, 'Legacy order/count reach pre_get_posts without UI-only restrictions; core retains its existing feed count normalization.' );
	cwp_rss_assert( array( $id ) === wp_list_pluck( $q->posts, 'ID' ), 'International category filtering returns only matching posts.' );
	cwp_rss_assert( $slug === $q->get( 'tax_query' )[0]['terms'] && array( $excluded_slug ) === $q->get( 'tax_query' )[1]['terms'], 'Include and exclude taxonomy slugs retain percent octets.' );
	$attachment = wp_insert_attachment( array( 'post_title' => 'Synthetic feed image', 'post_mime_type' => 'image/jpeg', 'guid' => home_url( '/wp-content/uploads/cwp-rss-test.jpg' ) ), false, $id ); $post_ids[] = $attachment;
	update_post_meta( $attachment, '_wp_attached_file', 'cwp-rss-test.jpg' );
	wp_update_attachment_metadata( $attachment, array( 'file' => 'cwp-rss-test.jpg', 'width' => 600, 'height' => 400, 'sizes' => array() ) );
	update_post_meta( $id, '_thumbnail_id', $attachment );
	ob_start(); do_feed_rss2( false ); $xml = ob_get_clean();
	$parsed = simplexml_load_string( $xml );
	cwp_rss_assert( false !== $parsed && 1 === count( $parsed->channel->item ), 'Actual RSS2 output must parse as XML and contain the matching article.' );
	cwp_rss_assert( $classic === (string) $parsed->channel->item->title, 'RSS output preserves the legacy title without double encoding.' );
	$media = $parsed->channel->item->children( 'http://search.yahoo.com/mrss/' )->content->attributes();
	cwp_rss_assert( 600 === (int) $media['width'] && 400 === (int) $media['height'], 'RSS media attributes render from valid attachment metadata.' );
	$_GET = array( 'be_filter' => array( 'orderby' => 'modified' ) );
	$GLOBALS['post'] = get_post( $id );
	update_option( 'timezone_string', 'America/New_York' );
	cwp_rss_assert( get_post_modified_time( 'U', true ) === $builder->post_time( 0, 'U', true ), 'Modified feed timestamps honor requested GMT.' );
	update_option( 'timezone_string', $before_timezone );
	$GLOBALS['wp_query'] = $before_wp_query; $GLOBALS['wp_the_query'] = $before_main_query;

	$deny_meta = static function ( $caps, $cap, $user_id, $args ) {
		return 'edit_post_meta' === $cap && isset( $args[1] ) && 'be_rss_post_title' === $args[1] ? array( 'do_not_allow' ) : $caps;
	};
	add_filter( 'map_meta_cap', $deny_meta, 10, 4 );
	$denied_meta = cwp_rss_request( 'POST', $path, array( $field => 'Denied metadata', 'content' => 'Never change existing body' ), $author );
	cwp_rss_assert( 403 === $denied_meta->get_status() && 'Body-only edit' === get_post( $id )->post_content, 'Existing-object metadata capability denial is preflighted before body writes.' );
	$body_only = cwp_rss_request( 'POST', $path, array( 'content' => 'Allowed body edit' ), $author );
	cwp_rss_assert( 200 === $body_only->get_status() && 'Allowed body edit' === get_post( $id )->post_content && esc_attr( $classic ) === get_post_meta( $id, 'be_rss_post_title', true ), 'An editor without RSS title permission can update the article and preserve its RSS title.' );
	remove_filter( 'map_meta_cap', $deny_meta );
	wp_set_current_user( $admin );

	$_GET = array( 'be_filter' => array( 'exclude_tag' => '\"><script>alert(1)</script>' ) );
	ob_start(); $builder->page_content(); $form = ob_get_clean();
	cwp_rss_assert( false === strpos( $form, '<script>alert(1)</script>' ), 'Admin form escapes attacker-controlled settings.' );
	cwp_rss_assert( false !== strpos( $form, 'method="post"' ) && false !== strpos( $form, 'cwp_rss_builder_nonce' ), 'Builder form emits POST action and nonce.' );
	$editor = null;
	foreach ( $GLOBALS['wp_filter']['enqueue_block_editor_assets']->callbacks as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof CWP_RSS_Builder_Editor ) { $editor = $callback['function'][0]; }
		}
	}
	cwp_rss_assert( $editor instanceof CWP_RSS_Builder_Editor, 'Native editor hook is registered.' );
	$GLOBALS['post'] = get_post( $id );
	$boxes = array( 'post' => array( 'normal' => array( 'high' => array( 'be_rss_builder' => array( 'id' => 'be_rss_builder' ), 'other-plugin' => array( 'id' => 'other-plugin' ) ) ) ) );
	cwp_rss_assert( $boxes === $editor->replace_box( $boxes ), 'Keep classic fallback when native assets are not ready.' );
	foreach ( array( 'wp-plugins', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'wp-editor', 'wp-edit-post' ) as $handle ) { wp_register_script( $handle, '/qa-' . $handle . '.js', array(), 'test', true ); }
	$editor->enqueue();
	$native_boxes = $editor->replace_box( $boxes );
	cwp_rss_assert( ! isset( $native_boxes['post']['normal']['high']['be_rss_builder'] ) && isset( $native_boxes['post']['normal']['high']['other-plugin'] ), 'Native field replaces only the RSS box after assets enqueue.' );
	cwp_rss_assert( wp_script_is( CWP_RSS_Builder_Editor::SCRIPT, 'enqueued' ), 'Native editor asset is enqueued.' );

	// Core defines DOING_AUTOSAVE for the remainder of this PHP request. Run these last.
	$stable_title = get_post_meta( $id, 'be_rss_post_title', true );
	$auto = cwp_rss_request( 'POST', $path . '/autosaves', array( $field => 'Forbidden auto title', 'content' => 'Forbidden autosave body' ), $author );
	cwp_rss_assert( 400 === $auto->get_status(), 'Reject crafted additional-field autosave before mutation.' );
	cwp_rss_assert( $stable_title === get_post_meta( $id, 'be_rss_post_title', true ), 'Rejected autosave cannot overwrite parent title.' );
	$normal_auto = cwp_rss_request( 'POST', $path . '/autosaves', array( 'content' => 'Ordinary autosave body' ), $author );
	cwp_rss_assert( in_array( $normal_auto->get_status(), array( 200, 201 ), true ), 'Ordinary core autosave remains usable.' );
	cwp_rss_assert( $stable_title === get_post_meta( $id, 'be_rss_post_title', true ), 'Ordinary autosave preserves RSS title.' );

	WP_CLI::success( $GLOBALS['cwp_rss_test_count'] . ' real WordPress assertions passed on WordPress ' . get_bloginfo( 'version' ) . ' / PHP ' . PHP_VERSION . '.' );
} finally {
	remove_action( 'added_option', $track_feed );
	foreach ( $feed_options as $name ) { delete_option( $name ); }
	remove_filter( 'wp_redirect', $redirect_handler, -PHP_INT_MAX );
	remove_filter( 'wp_die_handler', $die_handler );
	remove_filter( 'be_rss_builder_post_types', $allowed_types );
	$_GET = $before_get; $_POST = $before_post; $_REQUEST = $before_request;
	if ( null === $before_method ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $before_method; }
	if ( false === $before_option ) { delete_option( 'be_rss_builder' ); } else { update_option( 'be_rss_builder', $before_option, false ); }
	$GLOBALS['wp_the_query'] = $before_main_query;
	$GLOBALS['post'] = $before_global_post;
	$GLOBALS['wp_query'] = $before_wp_query;
	update_option( 'timezone_string', $before_timezone );
	if ( $had_custom_fields ) { add_post_type_support( 'post', 'custom-fields' ); }
	foreach ( $post_ids as $post_id ) { wp_delete_post( $post_id, true ); }
	foreach ( $terms as $term_id ) { wp_delete_term( $term_id, 'category' ); }
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $user_id ) { wp_delete_user( $user_id ); }
	unregister_post_type( 'cwp_rss_test' );
	wp_set_current_user( $before_user );
}
