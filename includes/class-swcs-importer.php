<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Creates WooCommerce products from the locally downloaded Stricker CSV datasets.
 * Import is deliberately limited to one product at a time in this phase.
 */
class SWCS_Importer {
    public static function import_product( $reference ) {
        if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Product' ) ) {
            return new WP_Error( 'woocommerce_missing', 'WooCommerce precisa estar ativo para importar produtos.' );
        }
        $product = self::find_product( $reference );
        if ( is_wp_error( $product ) ) return $product;
        $variants = self::find_variants( $reference );
        if ( empty( $variants ) ) {
            $variants = array( array( 'Sku' => $reference, 'Name' => $product['Name'], 'Description' => $product['Description'], 'ShortDescription' => $product['ShortDescription'], 'YourPrice' => '', 'Price1' => '', 'ColorDesc1' => '', 'Size' => '', 'Capacity' => '' ) );
        }

        $prepared = self::prepare_variants( $variants );
        $is_variable = count( $prepared['variants'] ) > 1 && ! empty( $prepared['attributes'] );
        $category_id = self::get_category_id( $product['Type'], $product['SubType'] );
        $existing_id = wc_get_product_id_by_sku( $reference );

        if ( $is_variable ) {
            $wc = $existing_id ? wc_get_product( $existing_id ) : new WC_Product_Variable();
            if ( ! $wc || ! is_a( $wc, 'WC_Product_Variable' ) ) {
                $wc = new WC_Product_Variable();
                $existing_id = 0;
            }
        } else {
            $single = reset( $prepared['variants'] );
            $sku = ! empty( $single['sku'] ) ? $single['sku'] : $reference;
            $existing_id = wc_get_product_id_by_sku( $sku );
            $wc = $existing_id ? wc_get_product( $existing_id ) : new WC_Product_Simple();
            if ( ! $wc || ! is_a( $wc, 'WC_Product_Simple' ) ) $wc = new WC_Product_Simple();
        }

        $wc->set_name( $product['Name'] );
        $wc->set_description( $product['Description'] );
        $wc->set_short_description( $product['ShortDescription'] );
        $wc->set_status( 'draft' );
        $wc->set_catalog_visibility( 'visible' );
        if ( $category_id ) $wc->set_category_ids( array( $category_id ) );
        if ( ! $is_variable ) {
            $single = reset( $prepared['variants'] );
            $sku = ! empty( $single['sku'] ) ? $single['sku'] : $reference;
            if ( ! wc_get_product_id_by_sku( $sku ) || (int) $wc->get_id() === (int) wc_get_product_id_by_sku( $sku ) ) $wc->set_sku( $sku );
            if ( '' !== $single['price'] ) $wc->set_regular_price( $single['price'] );
            self::apply_stock( $wc, $single['stock'] );
        } else {
            $wc->set_sku( $reference );
            $attributes = array();
            foreach ( $prepared['attributes'] as $name => $options ) {
                $attribute = new WC_Product_Attribute();
                $attribute->set_name( $name );
                $attribute->set_options( array_values( $options ) );
                $attribute->set_visible( true );
                $attribute->set_variation( true );
                $attributes[] = $attribute;
            }
            $wc->set_attributes( $attributes );
        }

        $product_id = $wc->save();
        if ( ! $product_id ) return new WP_Error( 'product_save', 'Não foi possível salvar o produto.' );

        if ( $is_variable ) {
            $children = wc_get_products( array( 'parent' => $product_id, 'limit' => -1, 'return' => 'ids' ) );
            foreach ( $children as $child_id ) wp_delete_post( $child_id, true );
            foreach ( $prepared['variants'] as $variant ) self::create_variation( $product_id, $variant );
            WC_Product_Variable::sync( $product_id );
            wc_delete_product_transients( $product_id );
        }

        update_post_meta( $product_id, '_swcs_stricker_reference', $reference );
        update_post_meta( $product_id, '_swcs_imported_at', current_time( 'mysql' ) );
        update_post_meta( $product_id, '_swcs_import_version', SWCS_VERSION );

        return array( 'product_id' => $product_id, 'type' => $is_variable ? 'variable' : 'simple', 'variants' => count( $prepared['variants'] ), 'attributes' => $prepared['attributes'] );
    }

    private static function create_variation( $parent_id, $variant ) {
        $variation = new WC_Product_Variation();
        $variation->set_parent_id( $parent_id );
        if ( ! empty( $variant['sku'] ) && ! wc_get_product_id_by_sku( $variant['sku'] ) ) $variation->set_sku( $variant['sku'] );
        if ( '' !== $variant['price'] ) $variation->set_regular_price( $variant['price'] );
        $variation->set_status( 'publish' );
        self::apply_stock( $variation, $variant['stock'] );
        $attrs = array();
        foreach ( $variant['attributes'] as $name => $value ) $attrs[ sanitize_title( $name ) ] = $value;
        $variation->set_attributes( $attrs );
        $variation->save();
    }

    private static function apply_stock( $product, $stock ) {
        if ( '' === $stock || null === $stock ) return;
        $numeric = str_replace( ',', '.', (string) $stock );
        if ( ! is_numeric( $numeric ) ) return;
        $qty = max( 0, (int) floor( (float) $numeric ) );
        $product->set_manage_stock( true );
        $product->set_stock_quantity( $qty );
        $product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
    }

    private static function prepare_variants( $rows ) {
        $variants = array(); $attributes = array(); $seen = array();
        foreach ( $rows as $row ) {
            $sku = self::value( $row, array( 'Sku', 'SKU', 'WebSku', 'VariantSku' ) );
            $identity = $sku !== '' ? $sku : md5( wp_json_encode( $row ) );
            if ( isset( $seen[ $identity ] ) ) continue; $seen[ $identity ] = true;
            $variant = array( 'sku'=>$sku, 'price'=>self::price( $row ), 'stock'=>self::stock_for_sku( $sku ), 'attributes'=>array() );
            $pairs = array(
                'Cor' => self::value( $row, array( 'ColorDesc1', 'ColorDescription', 'ColorName', 'Color' ) ),
                'Tamanho' => self::value( $row, array( 'Size', 'SizeDescription' ) ),
                'Capacidade' => self::value( $row, array( 'Capacity', 'CapacityDescription' ) ),
            );
            foreach ( $pairs as $name => $value ) {
                if ( '' === $value ) continue;
                $variant['attributes'][ $name ] = $value;
                if ( ! isset( $attributes[ $name ] ) ) $attributes[ $name ] = array();
                if ( ! in_array( $value, $attributes[ $name ], true ) ) $attributes[ $name ][] = $value;
            }
            $variants[] = $variant;
        }
        $multi_attribute = false;
        foreach ( $attributes as $values ) if ( count( $values ) > 1 ) { $multi_attribute = true; break; }
        if ( ! $multi_attribute ) $attributes = array();
        return array( 'variants'=>$variants, 'attributes'=>$attributes );
    }

    private static function find_product( $reference ) {
        $status = get_option( 'swcs_catalog_products_status', array() );
        if ( empty( $status['path'] ) || ! is_readable( $status['path'] ) ) return new WP_Error( 'products_missing', 'O Products CSV ainda não foi baixado.' );
        $rows = self::find_rows( $status['path'], $reference );
        if ( empty( $rows ) ) return new WP_Error( 'product_not_found', 'Produto não encontrado no Products CSV.' );
        return reset( $rows );
    }

    private static function find_variants( $reference ) {
        $out = array();
        foreach ( array( 'optionalscomplete', 'optionals' ) as $dataset ) {
            $status = get_option( 'swcs_catalog_'.$dataset.'_status', array() );
            if ( empty( $status['path'] ) || ! is_readable( $status['path'] ) ) continue;
            foreach ( self::find_rows( $status['path'], $reference ) as $row ) $out[] = $row;
        }
        return $out;
    }

    private static function stock_for_sku( $sku ) {
        if ( '' === $sku ) return '';
        $status = get_option( 'swcs_catalog_stocks_status', array() );
        if ( empty( $status['path'] ) || ! is_readable( $status['path'] ) ) return '';
        foreach ( self::find_rows_by_any( $status['path'], array( $sku ) ) as $row ) {
            $value = self::value( $row, array( 'Stock', 'Quantity', 'AvailableQuantity', 'Qty', 'StockQuantity', 'Available' ) );
            if ( '' !== $value ) return $value;
        }
        return '';
    }

    private static function get_category_id( $type, $subtype ) {
        $type = trim( (string) $type ); $subtype = trim( (string) $subtype ); if ( '' === $type ) return 0;
        $parent = term_exists( $type, 'product_cat' );
        if ( ! $parent ) $parent = wp_insert_term( $type, 'product_cat' );
        if ( is_wp_error( $parent ) ) return 0;
        $parent_id = (int) ( is_array( $parent ) ? $parent['term_id'] : $parent );
        if ( '' === $subtype ) return $parent_id;
        $child = term_exists( $subtype, 'product_cat', $parent_id );
        if ( ! $child ) $child = wp_insert_term( $subtype, 'product_cat', array( 'parent' => $parent_id ) );
        return is_wp_error( $child ) ? $parent_id : (int) ( is_array( $child ) ? $child['term_id'] : $child );
    }

    private static function find_rows( $path, $reference ) {
        return self::find_rows_by_any( $path, array( (string) $reference ) );
    }

    private static function find_rows_by_any( $path, $targets ) {
        $h = fopen( $path, 'rb' ); if ( ! $h ) return array(); $first = fgets( $h ); if ( false === $first ) { fclose( $h ); return array(); }
        rewind( $h ); $delimiter = SWCS_CSV::detect_for_diagnostic( $first ); $header = self::normalise_header( fgetcsv( $h, 0, $delimiter ) ); $result = array();
        $keys = array( 'ProdReference','ProductReference','ProdRef','ProductRef','Reference','Sku','SKU','WebSku','VariantSku' );
        while ( false !== ( $row = fgetcsv( $h, 0, $delimiter ) ) ) {
            $row = array_pad( $row, count( $header ), '' ); if ( count( $row ) > count( $header ) ) $row = array_slice( $row, 0, count( $header ) ); $r = array_combine( $header, $row );
            foreach ( $keys as $key ) if ( array_key_exists( $key, $r ) && in_array( trim( (string) $r[ $key ] ), $targets, true ) ) { $result[] = $r; break; }
        }
        fclose( $h ); return $result;
    }

    private static function price( $row ) { foreach ( array( 'YourPrice','Price1','Price','UnitPrice' ) as $key ) { $v=self::value($row,array($key)); if(''!==$v&&is_numeric(str_replace(',','.',$v))) return str_replace(',','.',$v); } return ''; }
    private static function value( $row, $keys ) { foreach ( $keys as $key ) if ( array_key_exists( $key, $row ) ) return trim( (string) $row[ $key ] ); return ''; }
    private static function normalise_header( $header ) { if ( isset( $header[0] ) ) $header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] ); return array_map( function($v){ return trim((string)$v); }, $header ); }
}
