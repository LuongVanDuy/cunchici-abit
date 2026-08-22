<?php

defined( 'ABSPATH' ) || exit;

/**
 * Imports Abit product images into the WordPress Media Library.
 *
 * Live Abit data was verified to use extensionless Shopee CDN URLs such as
 * https://cf.shopee.vn/file/... which return normal image/jpeg bodies. We do
 * not rely on the remote URL extension. The body is downloaded through the
 * WordPress HTTP API, written to a temp file, inspected for its real MIME and
 * then sideloaded under a safe local filename.
 */
class Cunchici_Abit_Media_Sync {
	const SOURCE_HASH_META = '_cunchici_abit_source_image_hash';
	const SOURCE_URL_META  = '_cunchici_abit_source_image_url';
	const PRODUCT_SET_META = '_cunchici_abit_image_set_hash';
	const LAST_RESULT_META = '_cunchici_abit_last_image_sync';
	const MAX_IMAGE_BYTES  = 26214400; // 25 MB safety limit per image.

	/**
	 * Sync featured image + gallery for one WooCommerce product.
	 *
	 * Existing product images are left untouched when Abit has no valid image or
	 * every remote image fails. Old Media Library files are never deleted.
	 */
	public static function sync_product_images( WC_Product $product, array $urls, array $context = array() ) {
		$product_id = (int) $product->get_id();
		$urls       = self::normalize_urls( $urls );

		$result = array(
			'total'    => count( $urls ),
			'imported' => 0,
			'reused'   => 0,
			'applied'  => 0,
			'warnings' => array(),
		);

		if ( ! $product_id ) {
			return $result;
		}

		if ( ! $urls ) {
			self::save_result( $product_id, $result );
			return $result;
		}

		$attachment_ids = array();
		foreach ( $urls as $index => $url ) {
			$attachment_id = self::find_existing_attachment( $url );
			if ( $attachment_id ) {
				$result['reused']++;
			} else {
				$attachment_id = self::import_remote_image( $url, $product_id, $context, $index );
				if ( is_wp_error( $attachment_id ) ) {
					$result['warnings'][] = sprintf(
						'Ảnh %d: %s',
						$index + 1,
						$attachment_id->get_error_message()
					);
					continue;
				}
				$result['imported']++;
			}

			$attachment_id = absint( $attachment_id );
			if ( $attachment_id && wp_attachment_is_image( $attachment_id ) ) {
				$attachment_ids[] = $attachment_id;
			}
		}

		$attachment_ids = array_values( array_unique( array_filter( $attachment_ids ) ) );
		if ( ! $attachment_ids ) {
			// Never clear a valid local image just because the remote CDN failed.
			self::save_result( $product_id, $result );
			return $result;
		}

		$product->set_image_id( $attachment_ids[0] );
		$product->set_gallery_image_ids( array_slice( $attachment_ids, 1 ) );
		$product->save();

		$result['applied'] = count( $attachment_ids );
		update_post_meta( $product_id, self::PRODUCT_SET_META, sha1( implode( "\n", $urls ) ) );
		update_post_meta( $product_id, '_cunchici_abit_image_ids', implode( ',', $attachment_ids ) );
		self::save_result( $product_id, $result );

		return $result;
	}

	private static function save_result( $product_id, array $result ) {
		$result['synced_at'] = current_time( 'mysql' );
		update_post_meta(
			absint( $product_id ),
			self::LAST_RESULT_META,
			wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);
	}

	private static function normalize_urls( array $urls ) {
		$out = array();
		foreach ( $urls as $url ) {
			$url = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );
			if ( ! $url || ! wp_http_validate_url( $url ) ) {
				continue;
			}
			$out[] = $url;
		}
		return array_values( array_unique( $out ) );
	}

	private static function find_existing_attachment( $url ) {
		$hash = sha1( $url );
		$ids  = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 5,
				'fields'         => 'ids',
				'meta_key'       => self::SOURCE_HASH_META,
				'meta_value'     => $hash,
				'no_found_rows'  => true,
			)
		);

		foreach ( $ids as $id ) {
			if ( $url === (string) get_post_meta( $id, self::SOURCE_URL_META, true ) && wp_attachment_is_image( $id ) ) {
				return (int) $id;
			}
		}
		return 0;
	}

	private static function import_remote_image( $url, $product_id, array $context, $index ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = wp_tempnam( 'cunchici-abit-image' );
		if ( ! $tmp ) {
			return new WP_Error( 'cunchici_abit_image_temp', 'Không tạo được file tạm.' );
		}

		// Do not stream directly to disk here. Some WordPress/hosting HTTP stacks
		// behave differently with stream+filename. The verified Abit images are
		// ordinary JPEG responses, so retrieve one capped body and persist it.
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 45,
				'redirection'         => 5,
				'limit_response_size' => self::MAX_IMAGE_BYTES + 1,
				'headers'             => array( 'Accept' => 'image/jpeg,image/png,image/webp,image/avif,image/*,*/*;q=0.8' ),
				'user-agent'          => 'CunChic-Abit/' . ( defined( 'CUNCHICI_ABIT_VERSION' ) ? CUNCHICI_ABIT_VERSION : '1.0' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			@unlink( $tmp );
			return new WP_Error( 'cunchici_abit_image_download', 'WordPress HTTP: ' . $response->get_error_message() );
		}

		$status       = (int) wp_remote_retrieve_response_code( $response );
		$content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		if ( $status < 200 || $status >= 300 ) {
			@unlink( $tmp );
			return new WP_Error( 'cunchici_abit_image_http', sprintf( 'CDN trả HTTP %d (%s).', $status, $content_type ?: 'không có Content-Type' ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$size = strlen( (string) $body );
		if ( $size <= 0 ) {
			@unlink( $tmp );
			return new WP_Error( 'cunchici_abit_image_empty', sprintf( 'HTTP 200 nhưng body ảnh rỗng (%s).', $content_type ?: 'không có Content-Type' ) );
		}
		if ( $size > self::MAX_IMAGE_BYTES ) {
			@unlink( $tmp );
			return new WP_Error( 'cunchici_abit_image_too_large', 'Ảnh vượt giới hạn 25 MB.' );
		}

		$written = file_put_contents( $tmp, $body );
		unset( $body );
		if ( false === $written || (int) $written !== $size ) {
			@unlink( $tmp );
			return new WP_Error( 'cunchici_abit_image_write', 'Không ghi đủ dữ liệu ảnh vào file tạm.' );
		}

		$mime = function_exists( 'wp_get_image_mime' ) ? wp_get_image_mime( $tmp ) : '';
		$ext  = self::extension_for_mime( $mime );
		if ( ! $ext ) {
			@unlink( $tmp );
			return new WP_Error(
				'cunchici_abit_image_mime',
				sprintf( 'File tải về không phải ảnh WordPress hỗ trợ. HTTP Content-Type=%s; detected=%s.', $content_type ?: '—', $mime ?: '—' )
			);
		}

		$sku  = isset( $context['sku'] ) ? sanitize_file_name( (string) $context['sku'] ) : '';
		$base = $sku ?: 'abit-' . absint( isset( $context['abit_product_id'] ) ? $context['abit_product_id'] : $product_id );
		$name = sanitize_file_name( $base . '-' . ( $index + 1 ) . '-' . substr( sha1( $url ), 0, 8 ) . '.' . $ext );

		$file_array = array(
			'name'     => $name,
			'tmp_name' => $tmp,
		);
		$title = isset( $context['name'] ) ? wp_strip_all_tags( (string) $context['name'] ) : '';

		$attachment_id = media_handle_sideload( $file_array, $product_id, $title );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return new WP_Error( 'cunchici_abit_image_sideload', 'Media Library: ' . $attachment_id->get_error_message() );
		}

		update_post_meta( $attachment_id, self::SOURCE_HASH_META, sha1( $url ) );
		update_post_meta( $attachment_id, self::SOURCE_URL_META, esc_url_raw( $url ) );
		update_post_meta( $attachment_id, '_cunchici_abit_image', '1' );
		if ( $title && ! get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $title );
		}

		return (int) $attachment_id;
	}

	private static function extension_for_mime( $mime ) {
		$map = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
		);
		if ( ! isset( $map[ $mime ] ) ) {
			return '';
		}

		$ext     = $map[ $mime ];
		$allowed = get_allowed_mime_types();
		foreach ( $allowed as $extensions => $allowed_mime ) {
			if ( $allowed_mime === $mime && preg_match( '/(^|\|)' . preg_quote( $ext, '/' ) . '(\||$)/', $extensions ) ) {
				return $ext;
			}
		}
		return '';
	}
}
