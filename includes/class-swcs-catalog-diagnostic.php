<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Builds a read-only diagnostic view of the Stricker catalog before any
 * WooCommerce products are created.
 */
class SWCS_Catalog_Diagnostic {
    public static function get_products( $page = 1, $per_page = 25, $search = '' ) {
        $status = get_option( 'swcs_catalog_products_status', array() );
        if ( empty( $status['path'] ) || ! is_readable( $status['path'] ) ) return new WP_Error( 'products_missing', 'O arquivo Products CSV ainda não foi baixado.' );
        $types = self::load_product_types(); $handle = fopen( $status['path'], 'rb' );
        if ( ! $handle ) return new WP_Error( 'products_open', 'Não foi possível abrir o Products CSV.' );
        $first = fgets( $handle ); if ( false === $first ) { fclose( $handle ); return new WP_Error( 'products_empty', 'O Products CSV está vazio.' ); }
        $delimiter = SWCS_CSV::detect_for_diagnostic( $first ); rewind( $handle ); $header = fgetcsv( $handle, 0, $delimiter );
        if ( false === $header ) { fclose( $handle ); return new WP_Error( 'products_header', 'Não foi possível ler o cabeçalho do Products CSV.' ); }
        $header = self::normalise_header( $header ); $search = trim( (string) $search ); $page = max( 1, (int) $page ); $per_page = min( 100, max( 10, (int) $per_page ) ); $start = ( $page - 1 ) * $per_page; $matched = 0; $rows = array();
        while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter ) ) ) {
            if ( count( $row ) === 1 && '' === trim( (string) $row[0] ) ) continue;
            $product = self::combine_row( $header, $row ); if ( '' !== $search && ! self::matches_search( $product, $search ) ) continue;
            if ( $matched++ < $start ) continue; $rows[] = self::diagnose_product( $product, $types ); if ( count( $rows ) >= $per_page ) break;
        }
        fclose( $handle ); $total = self::count_matches( $status['path'], $delimiter, $header, $search );
        return array( 'rows' => $rows, 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'pages' => max( 1, (int) ceil( $total / $per_page ) ), 'search' => $search );
    }

    public static function get_product( $reference ) {
        $status = get_option( 'swcs_catalog_products_status', array() ); if ( empty( $status['path'] ) || ! is_readable( $status['path'] ) ) return new WP_Error( 'products_missing', 'O arquivo Products CSV ainda não foi baixado.' );
        $handle = fopen( $status['path'], 'rb' ); if ( ! $handle ) return new WP_Error( 'products_open', 'Não foi possível abrir o Products CSV.' ); $first = fgets( $handle ); rewind( $handle ); $delimiter = SWCS_CSV::detect_for_diagnostic( $first ); $header = self::normalise_header( fgetcsv( $handle, 0, $delimiter ) ); $types = self::load_product_types();
        while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter ) ) ) { $product = self::combine_row( $header, $row ); if ( self::value( $product, array( 'ProdReference', 'ProductReference', 'Reference', 'SKU' ) ) === (string) $reference ) { fclose( $handle ); return self::diagnose_product( $product, $types, true ); } }
        fclose( $handle ); return new WP_Error( 'product_not_found', 'Produto não encontrado.' );
    }

    private static function diagnose_product( $p, $types, $full = false ) {
        $reference = self::value( $p, array( 'ProdReference', 'ProductReference', 'Reference', 'SKU' ) ); $type_code = self::value( $p, array( 'TypeCode', 'ProdTypeCode', 'ProductTypeCode' ) ); $sub_code = self::value( $p, array( 'SubTypeCode', 'ProdSubTypeCode', 'ProductSubTypeCode' ) );
        $colors = self::list_value( $p, array( 'Colors', 'Color', 'ColorCodes' ) ); $sizes = self::list_value( $p, array( 'Sizes', 'Size', 'SizeCodes' ) ); $capacity = self::list_value( $p, array( 'Capacitys', 'Capacities', 'Capacity' ) );
        $has_colors = self::bool_value( self::value( $p, array( 'HasColors' ) ) ) || ! empty( $colors ); $has_sizes = self::bool_value( self::value( $p, array( 'HasSizes' ) ) ) || ! empty( $sizes ); $has_capacity = self::bool_value( self::value( $p, array( 'HasCapacitys', 'HasCapacities' ) ) ) || ! empty( $capacity ); $variable = $has_colors || $has_sizes || $has_capacity;
        $result = array( 'reference'=>$reference, 'name'=>self::value($p,array('Name','ProductName')), 'description'=>self::value($p,array('Description')), 'short_description'=>self::value($p,array('ShortDescription')), 'type_code'=>$type_code, 'type_name'=>isset($types['types'][$type_code])?$types['types'][$type_code]:'', 'subtype_code'=>$sub_code, 'subtype_name'=>isset($types['subtypes'][$type_code][$sub_code])?$types['subtypes'][$type_code][$sub_code]:'', 'colors'=>$colors, 'sizes'=>$sizes, 'capacity'=>$capacity, 'has_colors'=>$has_colors, 'has_sizes'=>$has_sizes, 'has_capacity'=>$has_capacity, 'woocommerce_type'=>$variable?'Variável (diagnóstico)':'Simples (diagnóstico)', 'raw_flags'=>array('IsTextil'=>self::value($p,array('IsTextil')),'HasColors'=>self::value($p,array('HasColors')),'HasSizes'=>self::value($p,array('HasSizes')),'HasCapacitys'=>self::value($p,array('HasCapacitys')),'CombinedSizes'=>self::value($p,array('CombinedSizes'))) );
        if ( $full ) $result['raw'] = $p; return $result;
    }

    private static function load_product_types() {
        $status=get_option('swcs_catalog_producttypes_status',array()); $out=array('types'=>array(),'subtypes'=>array()); if(empty($status['path'])||!is_readable($status['path']))return $out; $h=fopen($status['path'],'rb'); if(!$h)return $out; $first=fgets($h); rewind($h); $delimiter=SWCS_CSV::detect_for_diagnostic($first); $header=self::normalise_header(fgetcsv($h,0,$delimiter));
        while(false!==($row=fgetcsv($h,0,$delimiter))){$r=self::combine_row($header,$row);$tc=self::value($r,array('TypeCode'));$td=self::value($r,array('TypeDescription'));if(''===$tc)continue;$out['types'][$tc]=$td;for($i=1;$i<=34;$i++){ $sc=self::value($r,array('SubTypeCode'.$i));$sd=self::value($r,array('SubTypeDescription'.$i));if(''!==$sc)$out['subtypes'][$tc][$sc]=$sd; }} fclose($h); return $out;
    }
    private static function count_matches($path,$delimiter,$header,$search){$h=fopen($path,'rb');if(!$h)return 0;fgetcsv($h,0,$delimiter);$n=0;while(false!==($row=fgetcsv($h,0,$delimiter))){if(count($row)===1&&''===trim((string)$row[0]))continue;$p=self::combine_row($header,$row);if(''===$search||self::matches_search($p,$search))$n++;}fclose($h);return $n;}
    private static function matches_search($p,$q){$q=strtolower($q);foreach(array('ProdReference','Name','Description','TypeCode','SubTypeCode') as $k){foreach($p as $key=>$v)if(strtolower($key)===strtolower($k)&&false!==strpos(strtolower((string)$v),$q))return true;}return false;}
    private static function value($row,$keys){foreach($keys as $key)if(array_key_exists($key,$row))return trim((string)$row[$key]);return '';}
    private static function list_value($row,$keys){$v=self::value($row,$keys);if(''===$v)return array();$parts=preg_split('/\s*[,;|]+\s*/',$v);return array_values(array_filter(array_map('trim',$parts),'strlen'));}
    private static function bool_value($v){return in_array(strtolower(trim((string)$v)),array('1','true','yes','sim','y'),true);}
    private static function combine_row($header,$row){$row=array_pad($row,count($header),'');if(count($row)>count($header))$row=array_slice($row,0,count($header));return array_combine($header,$row);}
    private static function normalise_header($header){if(isset($header[0]))$header[0]=preg_replace('/^\xEF\xBB\xBF/','',$header[0]);return array_map(function($v){return trim((string)$v);},$header);}
}
