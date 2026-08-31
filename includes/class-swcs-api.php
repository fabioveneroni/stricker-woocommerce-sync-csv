<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SWCS_API {
    const BASE_URL = 'https://ws.spotgifts.com.br/downloads/v1SSL/file';

    public static function get_access_key() {
        return trim( (string) get_option( 'swcs_access_key', '' ) );
    }

    public static function download_csv( $dataset, $language = 'PT' ) {
        $allowed = array( 'products', 'producttypes', 'optionals', 'optionalsPrice', 'optionalscomplete', 'customizationOptions', 'customizationTables', 'colors', 'stocks', 'orders', 'canceledproducts' );
        if ( ! in_array( $dataset, $allowed, true ) ) {
            return new WP_Error( 'invalid_dataset', 'Dataset não permitido.' );
        }

        $key = self::get_access_key();
        if ( '' === $key ) {
            return new WP_Error( 'missing_access_key', 'Access key não configurada.' );
        }

        $upload = wp_upload_dir();
        $dir = trailingslashit( $upload['basedir'] ) . 'swcs-catalog';
        if ( ! wp_mkdir_p( $dir ) ) {
            return new WP_Error( 'directory_error', 'Não foi possível criar a pasta do catálogo.' );
        }

        $url = add_query_arg(
            array(
                'AccessKey' => $key,
                'data'      => $dataset,
                'lang'      => strtoupper( $language ),
                'extension' => 'csv',
            ),
            self::BASE_URL
        );

        $path = trailingslashit( $dir ) . sanitize_file_name( $dataset . '-' . strtoupper( $language ) . '.csv' );
        $response = wp_remote_get( $url, array(
            'timeout'     => 300,
            'redirection' => 5,
            'stream'      => true,
            'filename'    => $path,
            'sslverify'   => true,
            'headers'     => array( 'Accept' => 'text/csv, text/plain, */*' ),
        ) );

        if ( is_wp_error( $response ) ) {
            @unlink( $path );
            return new WP_Error( 'download_error', $response->get_error_message() );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status ) {
            @unlink( $path );
            return new WP_Error( 'http_error', 'Erro HTTP ' . $status . ' ao baixar ' . $dataset . '.' );
        }

        if ( ! file_exists( $path ) || 0 === filesize( $path ) ) {
            @unlink( $path );
            return new WP_Error( 'empty_file', 'A API respondeu, mas o arquivo CSV está vazio.' );
        }

        update_option( 'swcs_catalog_' . $dataset . '_status', array(
            'status'    => 'completed',
            'dataset'   => $dataset,
            'language'  => strtoupper( $language ),
            'path'      => $path,
            'size'      => filesize( $path ),
            'completed' => current_time( 'mysql' ),
        ), false );

        return array( 'path' => $path, 'size' => filesize( $path ), 'status' => $status );
    }
}
