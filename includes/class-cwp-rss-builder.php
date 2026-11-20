<?php
/**
 * Feed query customization and classic administration.
 *
 * @package      Cultivate_RSS_Builder
 * @author       CultivateWP
 * @since        1.0.0
 * @license      GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Customize RSS feeds and their administration fields. */
class CWP_RSS_Builder {

	/**
	 * Current validated feed settings.
	 *
	 * @var array
	 */
	public $settings = array();

	/**
	 * Public feed selector and form settings key.
	 *
	 * @var string
	 */
	public $settings_key = 'be_filter';

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	public $settings_page = 'be_rss_builder';

	/**
	 * Legacy post title metadata key.
	 *
	 * @var string
	 */
	public $rss_post_title_key = 'be_rss_post_title';

	/** Register the feed and administration hooks. */
	public function __construct() {

		// Settings Page.
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_post_cwp_rss_builder_create_feed', array( $this, 'maybe_redirect' ) );

		// Customize RSS Feed.
		add_action( 'pre_get_posts', array( $this, 'rss_query' ) );
		add_filter( 'the_title_rss', array( $this, 'the_title_rss' ) );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'disable_srcset_in_feed' ) );
		add_action( 'rss2_item', array( $this, 'rss_media_content' ) );
		add_filter( 'get_post_time', array( $this, 'post_time' ), 10, 3 );

		// Metabox.
		add_action( 'add_meta_boxes', array( $this, 'metabox_register' ), 10, 2 );
		add_action( 'save_post', array( $this, 'metabox_save' ), 1, 2 );
		if ( version_compare( $GLOBALS['wp_version'], '5.3', '>=' ) ) {
			new CWP_RSS_Builder_Editor( $this );
		}
	}

	/**
	 * Register the settings page.
	 *
	 * @return void
	 */
	public function register_page() {
		add_options_page( __( 'RSS Builder', 'cultivate-rss-builder' ), __( 'RSS Builder', 'cultivate-rss-builder' ), 'manage_options', $this->settings_page, array( $this, 'page_content' ) );
	}

	/**
	 * Create a feed through the nonce-protected settings form.
	 *
	 * @return void
	 */
	public function maybe_redirect() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_die( esc_html__( 'You cannot create feeds.', 'cultivate-rss-builder' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'cwp_rss_builder_create_feed', 'cwp_rss_builder_nonce' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The complete array is validated by the storage helper before any write.
		$settings = isset( $_POST[ $this->settings_key ] ) ? wp_unslash( $_POST[ $this->settings_key ] ) : array();
		$hash     = $this->get_settings_hash( $settings );
		if ( is_wp_error( $hash ) ) {
			wp_die( esc_html( $hash->get_error_message() ), '', array( 'response' => 400 ) );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					$this->settings_key => $hash,
					'page'              => $this->settings_page,
				),
				admin_url( 'options-general.php' )
			),
			303
		);
		exit;
	}


	/**
	 * Render the feed settings page.
	 *
	 * @return void
	 */
	public function page_content() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage feeds.', 'cultivate-rss-builder' ), '', array( 'response' => 403 ) );
		}
		$this->settings = $this->get_feed_data();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Cultivate RSS Builder', 'cultivate-rss-builder' ) . '</h1>';
		$url = $this->get_feed_url();
		echo '<p>' . esc_html__( 'Feed URL:', 'cultivate-rss-builder' ) . ' <a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a></p>';

		echo '<form class="rss-builder" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'cwp_rss_builder_create_feed', 'cwp_rss_builder_nonce' );
		echo '<input type="hidden" name="action" value="cwp_rss_builder_create_feed" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		// Category.
		$this->build_term_dropdown(
			array(
				'label'    => __( 'Limit to category:', 'cultivate-rss-builder' ),
				'name'     => 'category',
				'empty'    => __( 'All Categories', 'cultivate-rss-builder' ),
				'taxonomy' => 'category',
			)
		);

		// Orderby.
		$options = array(
			'modified'      => __( 'Recently Updated', 'cultivate-rss-builder' ),
			'comment_count' => __( 'Most Comments', 'cultivate-rss-builder' ),
			'rand'          => __( 'Random', 'cultivate-rss-builder' ),
		);
		if ( function_exists( 'shared_counts' ) ) {
			$options['meta_value_num'] = __( 'Most Shares', 'cultivate-rss-builder' );
		}
		$this->build_dropdown(
			array(
				'label'   => __( 'Order by', 'cultivate-rss-builder' ),
				'name'    => 'orderby',
				'empty'   => __( 'Most Recent', 'cultivate-rss-builder' ),
				'options' => $options,
			)
		);

		// Posts Per Page.
		$this->build_dropdown(
			array(
				'label'   => __( 'How Many', 'cultivate-rss-builder' ),
				'name'    => 'posts_per_page',
				'empty'   => __( '(Default)', 'cultivate-rss-builder' ),
				'options' => array(
					'1'  => '1',
					'5'  => '5',
					'10' => '10',
					'20' => '20',
				),
			)
		);

		// Offset.
		$this->build_dropdown(
			array(
				'label'   => __( 'Offset', 'cultivate-rss-builder' ),
				'name'    => 'offset',
				'empty'   => '0',
				'options' => array(
					'0' => '0',
					'1' => '1',
					'2' => '2',
					'3' => '3',
					'4' => '4',
					'5' => '5',
				),
			)
		);

		// Thumbnail Size.
		$this->build_dropdown(
			array(
				'label'   => __( 'Thumbnail Size', 'cultivate-rss-builder' ),
				'name'    => 'image_size',
				'empty'   => __( '(Default)', 'cultivate-rss-builder' ),
				'options' => $this->image_size_options(),
			)
		);

		// Exclude "no newsletter" tag.
		$this->build_checkbox(
			array(
				'label' => __( 'Exclude posts tagged "no-newsletter"', 'cultivate-rss-builder' ),
				'name'  => 'no_newsletter',
			)
		);

		// Exclude by date.
		$this->build_text_input(
			array(
				'label' => __( 'Exclude posts older than', 'cultivate-rss-builder' ),
				'name'  => 'date',
			)
		);

		// Exclude recent posts.
		$this->build_text_input(
			array(
				'label' => __( 'Exclude posts published since', 'cultivate-rss-builder' ),
				'name'  => 'recent_date',
			)
		);

		// Exclude by category.
		$this->build_text_input(
			array(
				'label' => __( 'Exclude posts in category', 'cultivate-rss-builder' ),
				'name'  => 'exclude_category',
			)
		);

		// Exclude by tag.
		$this->build_text_input(
			array(
				'label' => __( 'Exclude posts in tag', 'cultivate-rss-builder' ),
				'name'  => 'exclude_tag',
			)
		);

		echo '</tbody></table>';

		// Form Submit.
		submit_button( __( 'Get Feed URL', 'cultivate-rss-builder' ) );

		echo '</form>';
		echo '</div>';
	}

	/**
	 * Get the feed URL with its validated settings selector.
	 *
	 * @return string
	 */
	public function get_feed_url() {
		$filter = $this->requested_filter();
		$feed   = get_bloginfo( 'rss2_url' );
		if ( false === $filter || array() === $filter ) {
			return $feed;
		}
		// add_query_arg() expects new values to be URL-encoded.
		return add_query_arg( $this->settings_key, rawurlencode_deep( $filter ), $feed );
	}


	/**
	 * Get the existing hash or hash the inline settings.
	 *
	 * @return string|false
	 */
	public function get_hash() {
		$filter = $this->requested_filter();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Preserve hashes used by existing feed URLs.
		return is_array( $filter ) ? sha1( serialize( array_filter( $filter ) ) ) : $filter;
	}


	/**
	 * Validate and save settings under their legacy hash.
	 *
	 * @param array $settings Feed settings.
	 * @return string|WP_Error
	 */
	public function get_settings_hash( $settings = array() ) {
		return CWP_RSS_Builder_Settings::save( $settings );
	}


	/**
	 * Read validated settings without changing saved data.
	 *
	 * @return array
	 */
	public function get_feed_data() {
		$filter = $this->requested_filter();
		if ( is_array( $filter ) ) {
			return $filter;
		}
		if ( false === $filter ) {
			return array();
		}
		$saved = CWP_RSS_Builder_Settings::read( $filter );
		if ( false === $saved || is_wp_error( $saved ) ) {
			return array();
		}
		$settings = CWP_RSS_Builder_Settings::validate( $saved );
		return is_wp_error( $settings ) ? array() : $settings;
	}


	/**
	 * Render a settings dropdown.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function build_dropdown( $args ) {

		$current = $this->field_current( $args );

		echo '<tr>';
		echo '<th scope="row">';
		$this->field_label( $args );
		echo '</th>';

		echo '<td>';
		echo '<select id="' . esc_attr( $this->field_name( $args ) ) . '" name="' . esc_attr( $this->field_name( $args ) ) . '">';

		if ( ! empty( $args['empty'] ) ) {
			echo '<option value=""' . selected( false, $current, false ) . '>' . esc_html( $args['empty'] ) . '</option>';
		}

		foreach ( $args['options'] as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $value, $current, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';
	}

	/**
	 * Render a taxonomy settings dropdown.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function build_term_dropdown( $args ) {

		$args['options'] = array();
		$terms           = get_terms( array( 'taxonomy' => $args['taxonomy'] ) );
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			$args['options'][ $term->slug ] = $term->name;
		}

		$this->build_dropdown( $args );
	}

	/**
	 * Render a settings checkbox.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function build_checkbox( $args ) {
		echo '<tr><th scope="row">';
		echo '</th><td>';
		echo '<input type="checkbox" id="' . esc_attr( $this->field_name( $args ) ) . '" name="' . esc_attr( $this->field_name( $args ) ) . '"' . checked( $this->field_current( $args ), 'on', false ) . ' />';
		$this->field_label( $args );
		echo '</td></tr>';
	}

	/**
	 * Render a settings text field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function build_text_input( $args ) {
		echo '<tr><th scope="row">';
		$this->field_label( $args );
		echo '</th><td>';
		echo '<input type="text" id="' . esc_attr( $this->field_name( $args ) ) . '" name="' . esc_attr( $this->field_name( $args ) ) . '" value="' . esc_attr( $this->field_current( $args ) ) . '" />';
		echo '</td></tr>';
	}

	/**
	 * Get the current escaped field value.
	 *
	 * @param array $args Field arguments.
	 * @return string|false
	 */
	public function field_current( $args ) {
		return ! empty( $this->settings[ $args['name'] ] ) ? esc_attr( $this->settings[ $args['name'] ] ) : false;
	}

	/**
	 * Render the field label.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function field_label( $args ) {
		if ( ! empty( $args['label'] ) ) {
			echo '<label style="margin-right: 10px;" for="' . esc_attr( $this->field_name( $args ) ) . '">' . esc_html( $args['label'] ) . '</label>';
		}
	}

	/**
	 * Get the nested settings field name.
	 *
	 * @param array $args Field arguments.
	 * @return string
	 */
	public function field_name( $args ) {
		return $this->settings_key . '[' . $args['name'] . ']';
	}

	/**
	 * Split a comma-separated settings value.
	 *
	 * @param string $text Settings value.
	 * @return string[]
	 */
	public function string_to_array( $text ) {

		// convert comma-space to comma.
		$text = str_replace( ', ', ',', $text );

		// convert to array using comma.
		return explode( ',', $text );
	}

	/**
	 * Get registered image size labels.
	 *
	 * @return array
	 */
	public function image_size_options() {
		global $_wp_additional_image_sizes;
		$additional_sizes = function_exists( 'wp_get_additional_image_sizes' ) ? wp_get_additional_image_sizes() : (array) $_wp_additional_image_sizes;
		$sizes            = array(
			'thumbnail' => array(
				'width'  => intval( get_option( 'thumbnail_size_w' ) ),
				'height' => intval( get_option( 'thumbnail_size_h' ) ),
				'crop'   => 1,
			),
		);
		$sizes            = array_merge( $sizes, $additional_sizes );

		$output = array();
		foreach ( $sizes as $size => $settings ) {
			$output[ $size ] = $size . ' (' . $settings['width'] . 'x' . $settings['height'] . ')';
		}

		return $output;
	}

	/**
	 * Customize the main RSS query using validated settings.
	 *
	 * @param WP_Query $query Current query.
	 * @return void
	 */
	public function rss_query( $query ) {
		if ( $query->is_main_query() && ! is_admin() && $query->is_feed() ) {

			$settings = $this->get_feed_data();
			if ( empty( $settings ) ) {
				return;
			}

			// Orderby.
			if ( ! empty( $settings['orderby'] ) ) {
				$query->set( 'orderby', esc_attr( $settings['orderby'] ) );
				if ( 'meta_value_num' === $settings['orderby'] ) {
					$query->set( 'meta_key', 'shared_counts_total' );
				}
			}

			// Posts per page.
			if ( ! empty( $settings['posts_per_page'] ) ) {
				$query->set( 'posts_per_page', intval( $settings['posts_per_page'] ) );
			}

			// Offset.
			if ( ! empty( $settings['offset'] ) ) {
				$query->set( 'offset', intval( $settings['offset'] ) );
			}

			// Date.
			$date_query = array();
			if ( ! empty( $settings['date'] ) ) {
				$date_query[] = array( 'after' => $settings['date'] );
			}
			if ( ! empty( $settings['recent_date'] ) ) {
				$date_query[] = array( 'before' => $settings['recent_date'] );
			}
			if ( ! empty( $date_query ) ) {
				$query->set( 'date_query', $date_query );
			}

			// Tax Query.
			$tax_query = array();

			// -- Category.
			if ( ! empty( $settings['category'] ) ) {
				$tax_query[] = array(
					'taxonomy' => 'category',
					'field'    => 'slug',
					'terms'    => $settings['category'],
				);
			}

			// -- No Newsletter.
			if ( ! empty( $settings['no_newsletter'] ) && 'on' === $settings['no_newsletter'] ) {
				$no_newsletter_term_id = apply_filters( 'ea_no_newsletter_term_id', 18790 );
				$tax_query[]           = array(
					'taxonomy' => 'post_tag',
					'field'    => 'term_id',
					'terms'    => $no_newsletter_term_id,
					'operator' => 'NOT IN',
				);
			}

			// -- Exclude category.
			if ( ! empty( $settings['exclude_category'] ) ) {
				$tax_query[] = array(
					'taxonomy' => 'category',
					'field'    => 'slug',
					'terms'    => $this->string_to_array( $settings['exclude_category'] ),
					'operator' => 'NOT IN',
				);
			}

			// -- Exclude trag.
			if ( ! empty( $settings['exclude_tag'] ) ) {
				$tax_query[] = array(
					'taxonomy' => 'post_tag',
					'field'    => 'slug',
					'terms'    => $this->string_to_array( $settings['exclude_tag'] ),
					'operator' => 'NOT IN',
				);
			}

			// -- Apply tax query.
			if ( ! empty( $tax_query ) ) {
				$query->set( 'tax_query', $tax_query );
			}
		}
	}

	/**
	 * Apply the legacy RSS title override.
	 *
	 * @param string $title Default feed title.
	 * @return string
	 */
	public function the_title_rss( $title ) {
		$stored    = get_post_meta( get_the_ID(), $this->rss_post_title_key, true );
		$alt_title = is_string( $stored ) ? esc_html( $stored ) : '';
		return ! empty( $alt_title ) ? $alt_title : $title;
	}

	/**
	 * Prepend a featured image to RSS content.
	 *
	 * @param string $content Feed content.
	 * @return string
	 */
	public function image_in_rss_content( $content ) {

		$settings = $this->get_feed_data();
		if ( ! empty( $settings ) ) {
			return $content;
		}

		global $post;
		if ( has_post_thumbnail( $post->ID ) ) {

			$image_size = 'featured';
			$settings   = $this->get_feed_data();
			if ( ! empty( $settings ) && ! empty( $settings['image_size'] ) ) {
				$image_size = esc_attr( $settings['image_size'] );
			}

			$content = '<img src="' . esc_url( wp_get_attachment_image_url( get_post_thumbnail_id( $post->ID ), $image_size ) ) . '" alt="' . esc_attr( get_the_title( $post->ID ) ) . '"/>' . $content;
		}
		return $content;
	}

	/**
	 * Disable responsive image sources in feeds.
	 *
	 * @param array|false $display Image sources.
	 * @return array|false
	 */
	public function disable_srcset_in_feed( $display ) {
		if ( is_feed() ) {
			$display = false;
		}
		return $display;
	}

	/**
	 * Output featured image media metadata.
	 *
	 * @return void
	 */
	public function rss_media_content() {
		global $post;
		if ( has_post_thumbnail( $post->ID ) ) {

			$image_size = 'featured';
			$settings   = $this->get_feed_data();
			if ( ! empty( $settings ) && ! empty( $settings['image_size'] ) ) {
				$image_size = esc_attr( $settings['image_size'] );
			}

			$image = wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), $image_size );
			if ( ! is_array( $image ) || count( $image ) < 3 ) {
				return;
			}
			echo '<media:content xmlns:media="http://search.yahoo.com/mrss/" medium="image" type="image/jpeg" url="' . esc_url( $image[0] ) . '" width="' . (int) $image[1] . '" height="' . (int) $image[2] . '" />';

		}
	}

	/**
	 * Use the modified time for feeds ordered by updates.
	 *
	 * @param string|int|false $time Original post time.
	 * @param string           $format Date format.
	 * @param bool             $gmt Whether to use GMT.
	 * @return string|int|false
	 */
	public function post_time( $time, $format, $gmt ) {

		if ( ! is_feed() ) {
			return $time;
		}

		$settings = $this->get_feed_data();
		if ( empty( $settings ) ) {
			return $time;
		}

		if ( isset( $settings['orderby'] ) && 'modified' === $settings['orderby'] ) {
			$time = get_post_modified_time( $format, $gmt );
		}

		return $time;
	}

	/**
	 * Register the classic RSS title metabox.
	 *
	 * @param string $post_type Current screen's post type.
	 * @param mixed  $post      Object being edited.
	 */
	public function metabox_register( $post_type, $post ) {
		if ( $post instanceof WP_Post && in_array( $post_type, $this->get_post_types(), true ) && $this->can_edit_title( $post->ID ) ) {
			add_meta_box( 'be_rss_builder', __( 'RSS Post Title', 'cultivate-rss-builder' ), array( $this, 'metabox_render' ), $post_type, 'normal', 'high' );
		}
	}

	/**
	 * Can the current user change the RSS title for this post?
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function can_edit_title( $post_id ) {
		return current_user_can( 'edit_post', $post_id ) && current_user_can( 'edit_post_meta', $post_id, $this->rss_post_title_key );
	}

	/**
	 * Render the classic RSS title field.
	 *
	 * @return void
	 */
	public function metabox_render() {

		$current = get_post_meta( get_the_ID(), $this->rss_post_title_key, true );
		if ( ! is_string( $current ) ) {
			echo '<p>' . esc_html__( 'The saved RSS title is not text and has not been changed.', 'cultivate-rss-builder' ) . '</p>';
			return;
		}

		// Security nonce.
		wp_nonce_field( 'be_rss_builder', 'be_rss_builder_nonce' );

		echo '<p><label for="' . esc_attr( $this->rss_post_title_key ) . '">' . esc_html__( 'RSS Post Title', 'cultivate-rss-builder' ) . '</label>';
		echo '<input class="widefat" type="text" id="' . esc_attr( $this->rss_post_title_key ) . '" name="' . esc_attr( $this->rss_post_title_key ) . '" value="' . esc_attr( $current ) . '" />';
		echo '</p>';
	}

	/**
	 * Save an authorized classic RSS title submission.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function metabox_save( $post_id, $post ) {
		if ( ! isset( $_POST['be_rss_builder_nonce'], $_POST[ $this->rss_post_title_key ] ) || ! is_string( $_POST['be_rss_builder_nonce'] ) || ! is_string( $_POST[ $this->rss_post_title_key ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['be_rss_builder_nonce'] ) ), 'be_rss_builder' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, $this->get_post_types(), true ) || ! $this->can_edit_title( $post_id ) || ! is_string( get_post_meta( $post_id, $this->rss_post_title_key, true ) ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Preserve literal title text; write_title() encodes legacy storage and all output is escaped.
		CWP_RSS_Builder_Editor::write_title( $post_id, $this->rss_post_title_key, wp_unslash( $_POST[ $this->rss_post_title_key ] ) );
	}

	/**
	 * Get allowed existing post types.
	 *
	 * @return string[] Post types.
	 */
	public function get_post_types() {
		$types = apply_filters( 'be_rss_builder_post_types', array( 'post' ) );
		return is_array( $types ) ? array_values(
			array_filter(
				$types,
				static function ( $type ) {
					return is_string( $type ) && post_type_exists( $type );
				}
			)
		) : array();
	}
	/**
	 * Validate the read-only public feed selector.
	 *
	 * @return array|string|false Feed selector.
	 */
	private function requested_filter() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public feeds are intentionally unauthenticated and never persist settings.
		if ( ! isset( $_GET[ $this->settings_key ] ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validate the public hash or array below; no writes occur here.
		$input = wp_unslash( $_GET[ $this->settings_key ] );
		if ( is_array( $input ) ) {
			$settings = CWP_RSS_Builder_Settings::validate( $input );
			return is_wp_error( $settings ) ? false : $settings;
		}
		return is_string( $input ) && preg_match( '/^[a-f0-9]{40}$/D', $input ) ? $input : false;
	}
}
new CWP_RSS_Builder();
