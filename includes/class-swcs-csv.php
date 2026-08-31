<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SWCS_CSV {
    public static function analyse( $dataset ) {
        $status = get_option( 'swcs_catalog_' . $dataset . '_status', array() );
        if ( empty( $status['path'] ) || ! is_readable( $status['path'] ) ) return new WP_Error( 'file_missing', 'O arquivo CSV ainda não foi baixado.' );
        $path = $status['path']; $handle = fopen( $path, 'rb' );
        if ( ! $handle ) return new WP_Error( 'file_open', 'Não foi possível abrir o CSV.' );
        $first = fgets( $handle );
        if ( false === $first ) { fclose( $handle ); return new WP_Error( 'csv_empty', 'O arquivo CSV está vazio.' ); }
        $delimiter = self::detect_delimiter( $first ); rewind( $handle );
        $header = fgetcsv( $handle, 0, $delimiter );
        if ( false === $header ) { fclose( $handle ); return new WP_Error( 'csv_header', 'Não foi possível identificar o cabeçalho.' ); }
        $header = self::normalise_header( $header ); $rows = array(); $records = 0;
        while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter ) ) ) {
            if ( count( $row ) === 1 && '' === trim( (string) $row[0] ) ) continue;
            $records++; if ( count( $rows ) < 10 ) $rows[] = self::combine_row( $header, $row );
        }
        fclose( $handle );
        $analysis = array( 'dataset' => $dataset, 'size' => filesize( $path ), 'records' => $records, 'columns' => count( $header ), 'header' => $header, 'delimiter' => $delimiter, 'encoding' => self::detect_encoding( $path ), 'sample' => $rows, 'analysed' => current_time( 'mysql' ) );
        update_option( 'swcs_catalog_' . $dataset . '_analysis', $analysis, false ); return $analysis;
    }

    public static function read_page( $dataset, $page = 1, $per_page = 25 ) {
        $status = get_option( 'swcs_catalog_' . $dataset . '_status', array() );
        if ( empty( $status['path'] ) || ! is_readable( $status['path'] ) ) return new WP_Error( 'file_missing', 'O arquivo CSV ainda não foi baixado.' );
        $handle = fopen( $status['path'], 'rb' ); if ( ! $handle ) return new WP_Error( 'file_open', 'Não foi possível abrir o CSV.' );
        $first = fgets( $handle ); if ( false === $first ) { fclose( $handle ); return new WP_Error( 'csv_empty', 'O arquivo CSV está vazio.' ); }
        $delimiter = self::detect_delimiter( $first ); rewind( $handle ); $header = fgetcsv( $handle, 0, $delimiter );
        if ( false === $header ) { fclose( $handle ); return new WP_Error( 'csv_header', 'Não foi possível identificar o cabeçalho.' ); }
        $header = self::normalise_header( $header ); $rows = array(); $start = max( 0, ( (int) $page - 1 ) * (int) $per_page ); $index = 0;
        while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter ) ) ) { if ( count( $row ) === 1 && '' === trim( (string) $row[0] ) ) continue; if ( $index++ < $start ) continue; $rows[] = self::combine_row( $header, $row ); if ( count( $rows ) >= $per_page ) break; }
        fclose( $handle );
        $analysis = get_option( 'swcs_catalog_' . $dataset . '_analysis', array() );
        return array( 'header' => $header, 'rows' => $rows, 'page' => (int) $page, 'per_page' => (int) $per_page, 'total' => (int) ( $analysis['records'] ?? 0 ), 'delimiter' => $delimiter );
    }

    private static function detect_delimiter( $line ) { $best = ','; $count = 0; foreach ( array( ';', ',', "\t", '|' ) as $candidate ) { $n = substr_count( $line, $candidate ); if ( $n > $count ) { $best = $candidate; $count = $n; } } return $best; }
    private static function detect_encoding( $path ) { $sample = file_get_contents( $path, false, null, 0, 4096 ); if ( substr( $sample, 0, 3 ) === "\xEF\xBB\xBF" ) return 'UTF-8 with BOM'; if ( function_exists( 'mb_detect_encoding' ) ) { $detected = mb_detect_encoding( $sample, array( 'UTF-8', 'Windows-1252', 'ISO-8859-1' ), true ); if ( $detected ) return $detected; } return 'Não identificado'; }
    private static function combine_row( $header, $row ) { $row = array_pad( $row, count( $header ), '' ); if ( count( $row ) > count( $header ) ) $row = array_slice( $row, 0, count( $header ) ); return array_combine( $header, $row ); }
    private static function normalise_header( $header ) { if ( isset( $header[0] ) ) $header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] ); return array_map( function( $value ) { return trim( (string) $value ); }, $header ); }
}
