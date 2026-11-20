<?php
/**
 * Validation and backwards-compatible feed settings storage.
 *
 * @package Cultivate_RSS_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Validate settings and preserve the existing hash/serialized-option contract. */
class CWP_RSS_Builder_Settings {

	const OPTION_PREFIX = 'cwp_rss_builder_feed_';

	/**
	 * Validate settings without writing anything.
	 *
	 * @param mixed $settings Feed settings.
	 * @return array|WP_Error Valid settings or an error.
	 */
	public static function validate( $settings ) {
		if ( ! is_array( $settings ) ) {
			return new WP_Error( 'cwp_rss_invalid_settings', __( 'Feed settings must be an array.', 'cultivate-rss-builder' ) );
		}
		$allowed = array( 'category', 'orderby', 'posts_per_page', 'offset', 'image_size', 'no_newsletter', 'date', 'recent_date', 'exclude_category', 'exclude_tag' );
		$clean   = array();
		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) {
				continue;
			}
			// Legacy feeds can store an unchecked checkbox as zero, false, or an off string.
			if ( 'no_newsletter' === $key && false === filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ) {
				$value = '';
			}
			if ( ! is_string( $value ) && ! is_int( $value ) ) {
				return new WP_Error( 'cwp_rss_invalid_setting', __( 'Feed settings must contain text or numbers.', 'cultivate-rss-builder' ) );
			}
			if ( in_array( $key, array( 'category', 'exclude_category', 'exclude_tag' ), true ) ) {
				// sanitize_text_field() removes percent octets used by non-Latin term slugs.
				$value = implode( ',', array_map( 'sanitize_title', array_map( 'trim', explode( ',', (string) $value ) ) ) );
			} else {
				$value = sanitize_text_field( (string) $value );
			}
			// WP_Query validates orderby itself. Preserve valid legacy values beyond the UI list.
			$number_pattern = 'posts_per_page' === $key ? '/^(?:-1|\d+)$/D' : '/^\d+$/D';
			if ( in_array( $key, array( 'posts_per_page', 'offset' ), true ) && '' !== $value && ! preg_match( $number_pattern, $value ) ) {
				return new WP_Error( 'cwp_rss_invalid_number', __( 'Use whole-number feed counts and offsets, or -1 for all posts.', 'cultivate-rss-builder' ) );
			}
			if ( 'no_newsletter' === $key && ! in_array( $value, array( '', 'on' ), true ) ) {
				return new WP_Error( 'cwp_rss_invalid_checkbox', __( 'The newsletter exclusion is invalid.', 'cultivate-rss-builder' ) );
			}
			$clean[ $key ] = $value;
		}
		return $clean;
	}

	/**
	 * Find an individual feed or a feed in the original shared option.
	 *
	 * @param string $hash Feed hash.
	 * @return mixed Stored settings, false if missing, or WP_Error for unreadable storage.
	 */
	public static function read( $hash ) {
		$settings = get_option( self::OPTION_PREFIX . $hash, false );
		if ( false !== $settings ) {
			return $settings;
		}

		$feeds = get_option( 'be_rss_builder', array() );
		if ( is_string( $feeds ) && is_serialized( $feeds ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Preserve legacy storage without object instantiation; reject malformed data below.
			$feeds = @unserialize( $feeds, array( 'allowed_classes' => false ) );
		}
		if ( ! is_array( $feeds ) ) {
			return new WP_Error( 'cwp_rss_invalid_storage', __( 'Saved feed settings could not be read. They have not been changed.', 'cultivate-rss-builder' ) );
		}
		return $feeds[ $hash ] ?? false;
	}

	/**
	 * Save settings only for an authorized caller. Admin HTTP writes also use a nonce.
	 *
	 * @param array $settings Feed settings.
	 * @return string|WP_Error Feed hash or error.
	 */
	public static function save( $settings ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'cwp_rss_forbidden', __( 'You cannot create feeds.', 'cultivate-rss-builder' ) );
		}
		$settings = self::validate( $settings );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		$settings = array_filter( $settings );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Preserve hashes used by existing feed URLs.
		$hash  = sha1( serialize( $settings ) );
		$saved = self::read( $hash );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		if ( false !== $saved ) {
			return $hash;
		}

		$option = self::OPTION_PREFIX . $hash;
		if ( ! add_option( $option, $settings, '', false ) ) {
			// A concurrent identical submission may have inserted this key after our first read.
			wp_cache_delete( 'notoptions', 'options' );
			if ( get_option( $option ) !== $settings ) {
				return new WP_Error( 'cwp_rss_save_failed', __( 'The feed could not be saved.', 'cultivate-rss-builder' ) );
			}
		}
		return $hash;
	}
}
