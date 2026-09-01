<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Resolves Stricker image references and imports them into the WordPress Media Library.
 * The CSV normally contains a filename (e.g. 11103_103.jpg), not a complete URL.
 * This class deliberately does not invent an image CDN path. For the current test
 * implementation it can resolve an image through the official SPOT product page.
 */
class SWCS_Images {
    const CATALOG_BASE = 'https://www.spotgifts.com.br/pt/catalogo/';

    public static function resolve_from_product( $product ) {
        $reference = isset( $product['reference'] ) ? trim( (string) $product['reference'] ) : '';
        $filename  = isset( $product['main_image'] ) ? trim( (string) $product['main_image'] ) : '';
        $subtype   = isset( $product['subtype'] ) ? trim( (string) $product['subtype'] ) : '';

        if ( '' === $reference || '' === $filename ) {
            return new WP_Error( 'image_reference_missing', 'O produto não possui referência e MainImage suficientes para localizar a imagem.' );
        }

        if ( filter_var( $filename, FILTER_VALIDATE_URL ) ) {
            return array( 'url' => $filename, 'method' => 'direct_url', 'reference' => $reference, 'filename' => basename( wp_parse_url( $filename, PHP_URL_PATH ) ) );
        }

        if ( '' === $subtype ) {
            return new WP_Error( 'image_category_missing', 'O produto não possui SubType para tentar localizar a página oficial do produto.' );
        }

        $slug = sanitize_title( remove_accents( $subtype ) );
        $url  = trailingslashit( self::CATALOG_BASE . rawurlencode( $slug ) ) . rawurlencode( $reference ) . '/';
        $response = wp_remote_get( $url, array(
            'timeout'     => 30,
            'redirection' => 5,
            'sslverify'   => true,
            'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml' ),
            'user-agent'  => 'Stricker WooCommerce Catalog Sync/' . SWCS_VERSION,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'image_page_request', $response->get_error_message() );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status ) {
            return new WP_Error( 'image_page_http', 'A página oficial retornou HTTP ' . $status . '.' );
        }

        $html = wp_remote_retrieve_body( $response );
        $candidate = self::find_image_url( $html, $filename, $reference );
        if ( '' === $candidate ) {
            return new WP_Error( 'image_not_found', 'A página oficial foi encontrada, mas não foi localizada uma imagem correspondente a ' . $filename . '.' );
        }

        return array( 'url' => $candidate, 'method' => 'official_catalog_page', 'page_url' => $url, 'reference' => $reference, 'filename' => $filename );
    }

    public static function import( $product ) {
        $resolved = self::resolve_from_product( $product );
        if ( is_wp_error( $resolved ) ) { return $resolved; }

        $existing = self::find_attachment( $resolved['filename'] );
        if ( $existing ) {
            return array_merge( $resolved, array( 'attachment_id' => $existing, 'imported' => false ) );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $resolved['url'], 60 );
        if ( is_wp_error( $tmp ) ) { return new WP_Error( 'image_download', $tmp->get_error_message() ); }

        $file = array(
            'name'     => sanitize_file_name( $resolved['filename'] ),
            'tmp_name' => $tmp,
        );
        $attachment_id = media_handle_sideload( $file, 0, isset( $product['name'] ) ? $product['name'] : '' );
        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
            return new WP_Error( 'image_media', $attachment_id->get_error_message() );
        }

        update_post_meta( $attachment_id, '_swcs_source_url', esc_url_raw( $resolved['url'] ) );
        update_post_meta( $attachment_id, '_swcs_source_filename', $resolved['filename'] );
        update_post_meta( $attachment_id, '_swcs_prod_reference', $resolved['reference'] );

        return array_merge( $resolved, array( 'attachment_id' => $attachment_id, 'imported' => true ) );
    }

    private static function find_image_url( $html, $filename, $reference ) {
        $candidates = array();
        if ( preg_match_all( '/(?:src|data-src|data-lazy-src|srcset)=["\']([^"\']+)["\']/i', $html, $matches ) ) {
            foreach ( $matches[1] as $value ) {
                foreach ( preg_split( '/\s*,\s*/', $value ) as $part ) {
                    $part = trim( preg_replace( '/\s+\d+[wx]$/i', '', $part ) );
                    if ( '' !== $part ) { $candidates[] = html_entity_decode( $part, ENT_QUOTES, 'UTF-8' ); }
                }
            }
        }
        if ( preg_match_all( '/https?:\\/\\/[^"\'\\s>]+/i', $html, $url_matches ) ) {
            $candidates = array_merge( $candidates, $url_matches[0] );
        }

        $filename_l = strtolower( rawurldecode( $filename ) );
        $reference_l = strtolower( $reference );
        foreach ( array_unique( $candidates ) as $candidate ) {
            $candidate = trim( $candidate, "'\"" );
            if ( 0 === strpos( $candidate, '//' ) ) { $candidate = 'https:' . $candidate; }
            if ( 0 !== strpos( $candidate, 'http://' ) && 0 !== strpos( $candidate, 'https://' ) ) { continue; }
            $path = strtolower( rawurldecode( (string) wp_parse_url( $candidate, PHP_URL_PATH ) ) );
            if ( false !== strpos( $path, $filename_l ) || ( false !== strpos( $path, $reference_l ) && preg_match( '/\\.(?:jpe?g|png|webp)$/i', $path ) ) ) {
                return esc_url_raw( $candidate );
            }
        }
        return '';
    }

    private static function find_attachment( $filename ) {
        global $wpdb;
        $basename = sanitize_file_name( basename( $filename ) );
        if ( '' === $basename ) { return 0; }
        $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 1", '%' . $wpdb->esc_like( $basename ) ) );
        return $id;
    }
}
