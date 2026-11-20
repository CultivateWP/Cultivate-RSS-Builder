<?php
/**
 * Native block-editor field with classic-editor fallback.
 *
 * @package Cultivate_RSS_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Integrate with the normal post REST save, without enabling Custom Fields. */
class CWP_RSS_Builder_Editor {

	const FIELD  = 'cwp_rss_post_title';
	const SCRIPT = 'cwp-rss-builder-editor';

	/**
	 * Feed plugin.
	 *
	 * @var CWP_RSS_Builder
	 */
	private $builder;
	/**
	 * Supported REST post type objects.
	 *
	 * @var array
	 */
	private $types = array();
	/**
	 * Post with the native script successfully enqueued.
	 *
	 * @var int
	 */
	private $ready_post = 0;

	/**
	 * Register integration hooks.
	 *
	 * @param CWP_RSS_Builder $builder Feed plugin.
	 */
	public function __construct( $builder ) {
		$this->builder = $builder;
		add_action( 'rest_api_init', array( $this, 'register' ) );
		add_filter( 'rest_request_before_callbacks', array( $this, 'before_callbacks' ), 10, 3 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
		add_filter( 'filter_block_editor_meta_boxes', array( $this, 'replace_box' ) );
	}

	/**
	 * Resolve a post type that uses the standard post REST controller.
	 *
	 * @param string $name Post type.
	 * @return WP_Post_Type|null Post type or unsupported.
	 */
	private function supported_type( $name ) {
		$type = get_post_type_object( $name );
		return $type && $type->show_in_rest && method_exists( $type, 'get_rest_controller' ) && $type->get_rest_controller() instanceof WP_REST_Posts_Controller ? $type : null;
	}

	/** Register the edit-context field for allowed REST-enabled post types. */
	public function register() {
		if ( ! function_exists( 'register_rest_field' ) ) {
			return;
		}
		foreach ( $this->builder->get_post_types() as $name ) {
			$type = $this->supported_type( $name );
			if ( ! $type ) {
				continue;
			}
			$this->types[ $name ] = $type;
			register_rest_field(
				$name,
				self::FIELD,
				array(
					'get_callback'    => array( $this, 'read' ),
					'update_callback' => array( $this, 'write' ),
					'schema'          => array(
						'description' => __( 'RSS title override.', 'cultivate-rss-builder' ),
						'type'        => 'string',
						'context'     => array( 'edit' ),
						'arg_options' => array( 'sanitize_callback' => array( $this, 'sanitize_input' ) ),
					),
				)
			);
			add_filter( 'rest_pre_insert_' . $name, array( $this, 'preflight' ), 10, 2 );
		}
	}

	/**
	 * Prevent null from becoming an accidental destructive empty string.
	 *
	 * @param mixed $value Submitted title.
	 * @return string|WP_Error Title or invalid-input error.
	 */
	public function sanitize_input( $value ) {
		return is_string( $value ) ? $value : new WP_Error( 'cwp_rss_invalid_title', __( 'RSS title must be text.', 'cultivate-rss-builder' ), array( 'status' => 400 ) );
	}

	/**
	 * Get the actual route prefix, including custom REST namespaces/bases.
	 *
	 * @param WP_Post_Type $type Post type.
	 * @return string Collection route.
	 */
	private function route_base( $type ) {
		$namespace = ! empty( $type->rest_namespace ) ? $type->rest_namespace : 'wp/v2';
		$base      = ! empty( $type->rest_base ) ? $type->rest_base : $type->name;
		return '/' . trim( $namespace, '/' ) . '/' . trim( $base, '/' );
	}

	/**
	 * Check input, routes and object capabilities before any post mutation.
	 *
	 * @param mixed           $value Title.
	 * @param WP_Post_Type    $type Post type.
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error Validation result.
	 */
	private function validate( $value, $type, $request ) {
		if ( ! is_string( $value ) ) {
			return $this->sanitize_input( $value );
		}
		$base   = $this->route_base( $type );
		$route  = $request->get_route();
		$id     = absint( $request['id'] );
		$method = $request->get_method();
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || $post->post_type !== $type->name || 0 !== strcasecmp( $route, $base . '/' . $id ) || ! in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			return new WP_Error( 'cwp_rss_unsupported_route', __( 'The RSS title cannot be changed in this request.', 'cultivate-rss-builder' ), array( 'status' => 400 ) );
		}
		if ( ! $this->builder->can_edit_title( $id ) ) {
			return new WP_Error( 'cwp_rss_forbidden', __( 'You cannot edit this RSS title.', 'cultivate-rss-builder' ), array( 'status' => 403 ) );
		}
		if ( ! is_string( get_post_meta( $id, $this->builder->rss_post_title_key, true ) ) ) {
			return new WP_Error( 'cwp_rss_invalid_storage', __( 'The saved RSS title is not text and has not been changed.', 'cultivate-rss-builder' ), array( 'status' => 409 ) );
		}
		return true;
	}

	/**
	 * Reject unsupported autosave/revision writes before their controllers run.
	 * Ordinary autosaves omit this additional field and remain unchanged.
	 *
	 * @param mixed           $response Existing preempted response.
	 * @param array           $handler Matched handler.
	 * @param WP_REST_Request $request Request.
	 * @return mixed Existing response or validation error.
	 */
	public function before_callbacks( $response, $handler, $request ) {
		if ( null !== $response || ! $request->has_param( self::FIELD ) ) {
			return $response;
		}
		foreach ( $this->types as $type ) {
			$base  = $this->route_base( $type );
			$route = $request->get_route();
			if ( 0 === stripos( $route, $base . '/' ) ) {
				$result = $this->validate( $request[ self::FIELD ], $type, $request );
				return is_wp_error( $result ) ? $result : $response;
			}
		}
		return $response;
	}

	/**
	 * Additional-field update callbacks run after the post write; validate early.
	 *
	 * @param stdClass|WP_Error $prepared Prepared post.
	 * @param WP_REST_Request   $request Request.
	 * @return stdClass|WP_Error Prepared post or error.
	 */
	public function preflight( $prepared, $request ) {
		if ( is_wp_error( $prepared ) || ! $request->has_param( self::FIELD ) ) {
			return $prepared;
		}
		// Core assigns the ID before this hook for updates, including the editor's first auto-draft save.
		if ( empty( $prepared->ID ) ) {
			return new WP_Error( 'cwp_rss_create_with_title', __( 'Create the post first, then update its RSS title.', 'cultivate-rss-builder' ), array( 'status' => 400 ) );
		}

		foreach ( $this->types as $type ) {
			if ( 0 === stripos( $request->get_route(), $this->route_base( $type ) . '/' ) ) {
				$result = $this->validate( $request[ self::FIELD ], $type, $request );
				return is_wp_error( $result ) ? $result : $prepared;
			}
		}
		return $prepared;
	}

	/**
	 * Read decoded text only for an editor who can edit the actual post.
	 *
	 * @param array           $post_data Prepared post data.
	 * @param string          $field Field name.
	 * @param WP_REST_Request $request Request.
	 * @return string|null Title or unavailable field.
	 */
	public function read( $post_data, $field, $request ) {
		$id = isset( $post_data['id'] ) ? absint( $post_data['id'] ) : 0;
		if ( 'edit' !== $request['context'] || ! $id || ! current_user_can( 'edit_post', $id ) || ! in_array( get_post_type( $id ), $this->builder->get_post_types(), true ) ) {
			return null;
		}
		$value = get_post_meta( $id, $this->builder->rss_post_title_key, true );
		return is_string( $value ) ? html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, get_option( 'blog_charset' ) ) : null;
	}

	/**
	 * Update a validated title through normal REST persistence.
	 *
	 * @param string          $value Title.
	 * @param WP_Post         $post Updated post.
	 * @param string          $field Field name.
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error Save result.
	 */
	public function write( $value, $post, $field, $request ) {
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! isset( $this->types[ $post->post_type ] ) ) {
			return new WP_Error( 'cwp_rss_forbidden', __( 'The RSS title is unavailable for this post type.', 'cultivate-rss-builder' ), array( 'status' => 403 ) );
		}
		$result = $this->validate( $value, $type, $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $this->builder->can_edit_title( $post->ID ) ) {
			return new WP_Error( 'cwp_rss_forbidden', __( 'You cannot edit this RSS title.', 'cultivate-rss-builder' ), array( 'status' => 403 ) );
		}
		return self::write_title( $post->ID, $this->builder->rss_post_title_key, $value );
	}

	/**
	 * Preserve encoded legacy storage and its empty/'0' clearing behavior.
	 *
	 * @param int    $id Post ID.
	 * @param string $key Metadata key.
	 * @param string $value Unslashed title text.
	 * @return true|WP_Error Save result.
	 */
	public static function write_title( $id, $key, $value ) {
		$stored = esc_attr( $value );
		if ( empty( $stored ) ) {
			delete_post_meta( $id, $key );
			$saved = ! metadata_exists( 'post', $id, $key );
		} else {
			update_post_meta( $id, $key, wp_slash( $stored ) );
			$saved = get_post_meta( $id, $key, true ) === $stored;
		}
		return $saved ? true : new WP_Error( 'cwp_rss_save_failed', __( 'The RSS title could not be saved.', 'cultivate-rss-builder' ), array( 'status' => 500 ) );
	}

	/** Enqueue only for a supported, authorized block-editor post. */
	public function enqueue() {
		global $post;
		$this->ready_post = 0;
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, $this->builder->get_post_types(), true ) || ! $this->supported_type( $post->post_type ) || ! $this->builder->can_edit_title( $post->ID ) || ! is_string( get_post_meta( $post->ID, $this->builder->rss_post_title_key, true ) ) ) {
			return;
		}
		$deps = array( 'wp-plugins', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'wp-editor', 'wp-edit-post' );
		foreach ( $deps as $dependency ) {
			if ( ! wp_script_is( $dependency, 'registered' ) ) {
				return;
			}
		}
		wp_enqueue_script( self::SCRIPT, plugins_url( 'assets/editor.js', dirname( __DIR__ ) . '/cultivate-rss-builder.php' ), $deps, CWP_RSS_BUILDER_VERSION, true );
		$ready = wp_add_inline_script(
			self::SCRIPT,
			'window.cwpRssBuilder = ' . wp_json_encode(
				array(
					'postId' => $post->ID,
					'field'  => self::FIELD,
				)
			) . ';',
			'before'
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( self::SCRIPT, 'cultivate-rss-builder' );
		}
		if ( $ready && wp_script_is( self::SCRIPT, 'enqueued' ) ) {
			$this->ready_post = (int) $post->ID;
		}
	}

	/**
	 * Retain every other box and retain the fallback if native assets are missing.
	 *
	 * @param array $boxes Metabox registry.
	 * @return array Registry.
	 */
	public function replace_box( $boxes ) {
		global $post;
		if ( ! is_array( $boxes ) || ! $post instanceof WP_Post || ! $this->ready_post || $this->ready_post !== (int) $post->ID ) {
			return $boxes;
		}
		$post_boxes = isset( $boxes[ $post->post_type ] ) ? $boxes[ $post->post_type ] : array();
		foreach ( $post_boxes as $location => $priorities ) {
			foreach ( $priorities as $priority => $registered ) {
				unset( $boxes[ $post->post_type ][ $location ][ $priority ]['be_rss_builder'] );
			}
		}
		return $boxes;
	}
}
