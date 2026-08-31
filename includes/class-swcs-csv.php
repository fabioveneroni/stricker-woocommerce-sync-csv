<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SWCS_CSV {
    public static function read_page( $dataset, $page = 1, $per_page = 25 ) {
        $status = get_option( 'swcs_catalog_' . $dataset . '_status', array() );
        if ( empty( $status['path'] ) || ! is_readable( $status['path'] ) ) {
            return new WP_Error( 'file_missing', 'O arquivo CSV ainda não foi baixado.' );
        }

        $handle = fopen( $status['path'], 'rb' );
        if ( ! $handle ) { return new WP_Error( 'file_open', 'Não foi possível abrir o CSV.' ); }

        $header = fgetcsv( $handle, 0, ',' );
        if ( false === $header ) { fclose( $handle ); return new WP_Error( 'csv_header', 'Não foi possível identificar o cabeçalho.' ); }
        $header = self::normalise_header( $header );

        $rows = array();
        $start = max( 0, ( (int) $page - 1 ) * (int) $per_page );
        $index = 0;
        while ( false !== ( $row = fgetcsv( $handle, 0, ',' ) ) ) {
            if ( count( $row ) === 1 && '' === trim( (string) $row[0] ) ) { continue; }
            if ( $index++ < $start ) { continue; }
            $rows[] = self::combine_row( $header, $row );
            if ( count( $rows ) >= $per_page ) { break; }
        }
        fclose( $handle );

        return array( 'header' => $header, 'rows' => $rows, 'page' => (int) $page, 'per_page' => (int) $per_page );
    }

    private static function combine_row( $header, $row ) {
        $row = array_pad( $row, count( $header ), '' );
        if ( count( $row ) > count( $header ) ) { $row = array_slice( $row, 0, count( $header ) ); }
        return array_combine( $header, $row );
    }

    private static function normalise_header( $header ) {
        if ( isset( $header[0] ) ) { $header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] ); }
        return array_map( function( $value ) { return trim( (string) $value ); }, $header );
    }
}
