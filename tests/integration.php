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
$before_get = $_GET;
$before_post = $_POST;
$before_request = $_REQUEST;
$before_method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null;
$before_user = get_current_user_id();
$before_main_query = $GLOBALS['wp_the_query'];
$before_global_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
$before_wp_query = $GLOBALS['wp_query'];
$had_custom_fields = post_type_supports( 'post', 'custom-fields' );
$post_ids = array();
$users = array();
$allowed_types = static function () { return array( 'post', 'cwp_rss_test' ); };

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
	$created = cwp_rss_request( 'POST', '/wp/v2/posts', array( 'title' => 'New RSS fixture', 'status' => 'draft', $field => 'New feed title' ), $author );
	cwp_rss_assert( 201 === $created->get_status(), 'Authorized collection create supports the title field.' );
	$new_id = $created->get_data()['id']; $post_ids[] = $new_id;
	cwp_rss_assert( 'New feed title' === get_post_meta( $new_id, 'be_rss_post_title', true ), 'New post gets its RSS title.' );
	$bad_create = cwp_rss_request( 'POST', '/wp/v2/posts', array( 'title' => 'Never create this', $field => null ), $author );
	cwp_rss_assert( 400 === $bad_create->get_status(), 'Reject null during collection create.' );
	cwp_rss_assert( array() === get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'title' => 'Never create this', 'fields' => 'ids' ) ), 'Invalid create must not leave a new post behind.' );
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

	// Check the saved title through actual RSS2 output, without changing feed settings.
	$_GET = array();
	$q = new WP_Query();
	$GLOBALS['wp_query'] = $q; $GLOBALS['wp_the_query'] = $q;
	$q->query( array( 'p' => $id, 'feed' => 'rss2', 'post_type' => 'post', 'post_status' => 'publish' ) );
	ob_start(); do_feed_rss2( false ); $xml = ob_get_clean();
	$parsed = simplexml_load_string( $xml );
	cwp_rss_assert( false !== $parsed && 1 === count( $parsed->channel->item ), 'RSS2 output parses and contains the owned article.' );
	cwp_rss_assert( $classic === (string) $parsed->channel->item->title, 'RSS output preserves the legacy title without double encoding.' );
	$GLOBALS['wp_query'] = $before_wp_query; $GLOBALS['wp_the_query'] = $before_main_query;

	// A new collection object ID does not exist during preflight. Core can create
	// a draft before a site-specific metadata capability filter denies the field.
	$deny_meta = static function ( $caps, $cap, $user_id, $args ) {
		return 'edit_post_meta' === $cap && isset( $args[1] ) && 'be_rss_post_title' === $args[1] ? array( 'do_not_allow' ) : $caps;
	};
	add_filter( 'map_meta_cap', $deny_meta, 10, 4 );
	$denied_meta = cwp_rss_request( 'POST', $path, array( $field => 'Denied metadata', 'content' => 'Never change existing body' ), $author );
	cwp_rss_assert( 403 === $denied_meta->get_status() && 'Body-only edit' === get_post( $id )->post_content, 'Existing-object metadata capability denial is preflighted before body writes.' );
	$denied_create = cwp_rss_request( 'POST', '/wp/v2/posts', array( 'title' => 'Denied meta create ' . $token, 'status' => 'draft', $field => 'Denied metadata' ), $author );
	$partial_posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'title' => 'Denied meta create ' . $token ) );
	$partial = $partial_posts ? $partial_posts[0] : null;
	if ( $partial ) { $post_ids[] = $partial->ID; }
	cwp_rss_assert( 403 === $denied_create->get_status() && $partial instanceof WP_Post && ! metadata_exists( 'post', $partial->ID, 'be_rss_post_title' ), 'Document core collection partial-write limitation without unsafe generic rollback.' );
	remove_filter( 'map_meta_cap', $deny_meta );
	wp_set_current_user( $admin );

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
	remove_filter( 'be_rss_builder_post_types', $allowed_types );
	$_GET = $before_get; $_POST = $before_post; $_REQUEST = $before_request;
	if ( null === $before_method ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $before_method; }
	$GLOBALS['wp_the_query'] = $before_main_query;
	$GLOBALS['post'] = $before_global_post;
	$GLOBALS['wp_query'] = $before_wp_query;
	if ( $had_custom_fields ) { add_post_type_support( 'post', 'custom-fields' ); }
	foreach ( $post_ids as $post_id ) { wp_delete_post( $post_id, true ); }
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $user_id ) { wp_delete_user( $user_id ); }
	unregister_post_type( 'cwp_rss_test' );
	wp_set_current_user( $before_user );
}
