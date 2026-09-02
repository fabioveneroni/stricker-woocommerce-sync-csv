<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Creates/updates WooCommerce products from the locally downloaded Stricker CSV catalog. */
class SWCS_Importer {
    public static function is_available() { return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' ); }

    public static function import_reference( $reference ) {
        if ( ! self::is_available() ) return new WP_Error( 'woocommerce_missing', 'WooCommerce não está ativo.' );
        $products = self::load_products_by_reference( $reference );
        if ( empty( $products ) ) return new WP_Error( 'product_missing', 'Produto não encontrado no CSV Products.' );
        return self::upsert_product( $products[0], self::load_variations_by_reference( $reference ) );
    }

    public static function import_batch( $limit = 10, $offset = 0 ) {
        if ( ! self::is_available() ) return new WP_Error( 'woocommerce_missing', 'WooCommerce não está ativo.' );
        $limit = max( 1, min( 50, (int) $limit ) );
        $page = SWCS_CSV::read_page( 'products', floor( $offset / $limit ) + 1, $limit );
        if ( is_wp_error( $page ) ) return $page;
        $imported = 0;
        foreach ( $page['rows'] as $row ) {
            $reference = trim( (string) ( $row['ProdReference'] ?? '' ) );
            if ( '' === $reference ) continue;
            if ( ! is_wp_error( self::import_reference( $reference ) ) ) $imported++;
        }
        return array( 'imported' => $imported, 'read' => count( $page['rows'] ), 'next_offset' => $offset + count( $page['rows'] ), 'has_more' => count( $page['rows'] ) === $limit );
    }

    private static function load_products_by_reference( $reference ) { return self::find_rows( 'products', 'ProdReference', $reference ); }
    private static function load_variations_by_reference( $reference ) { return self::find_rows( 'optionalscomplete', 'ProdReference', $reference ); }

    private static function find_rows( $dataset, $key, $value ) {
        $status = get_option( 'swcs_catalog_' . $dataset . '_status', array() );
        if ( empty( $status['path'] ) || ! is_readable( $status['path'] ) ) return array();
        $handle = fopen( $status['path'], 'rb' ); if ( ! $handle ) return array();
        $delimiter = self::detect_delimiter( $handle ); $header = fgetcsv( $handle, 0, $delimiter );
        if ( false === $header ) { fclose( $handle ); return array(); }
        if ( isset( $header[0] ) ) $header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );
        $header = array_map( 'trim', $header ); $found = array();
        while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter ) ) ) {
            $row = array_pad( $row, count( $header ), '' );
            if ( count( $row ) > count( $header ) ) $row = array_slice( $row, 0, count( $header ) );
            $assoc = array_combine( $header, $row );
            if ( isset( $assoc[ $key ] ) && trim( (string) $assoc[ $key ] ) === (string) $value ) $found[] = $assoc;
        }
        fclose( $handle ); return $found;
    }

    private static function upsert_product( $row, $variation_rows ) {
        $reference = trim( (string) ( $row['ProdReference'] ?? '' ) );
        $is_variable = self::is_variable( $row, $variation_rows );
        $existing_id = self::find_product_id( $reference );
        $product = $existing_id ? wc_get_product( $existing_id ) : ( $is_variable ? new WC_Product_Variable() : new WC_Product_Simple() );
        if ( ! $product ) return new WP_Error( 'product_load', 'Não foi possível carregar o produto WooCommerce.' );

        try {
            // Stricker controls catalogue data. Existing WordPress/WooCommerce editorial state is preserved.
            $product->set_name( self::text( $row, 'Name' ) );
            $product->set_description( self::text( $row, 'Description' ) );
            $product->set_short_description( self::text( $row, 'ShortDescription' ) );
            $sku_error = self::set_unique_sku( $product, $reference, 'produto pai', $reference );
            if ( is_wp_error( $sku_error ) ) return $sku_error;
            self::set_category( $product, self::text( $row, 'Type' ), self::text( $row, 'SubType' ) );
            $product->update_meta_data( '_swcs_prod_reference', $reference );
            $product->update_meta_data( '_swcs_type_code', self::text( $row, 'TypeCode' ) );
            $product->update_meta_data( '_swcs_subtype_code', self::text( $row, 'SubTypeCode' ) );
            $product->update_meta_data( '_swcs_brand', self::text( $row, 'Brand' ) );
            $product->update_meta_data( '_swcs_source_updated_at', self::text( $row, 'UpdateDate' ) );

            if ( $is_variable && ! $product->is_type( 'variable' ) ) return new WP_Error( 'product_type_mismatch', 'O produto existente não é variável, mas o catálogo Stricker exige variações.' );
            $product_id = $product->save();
            if ( ! $product_id ) return new WP_Error( 'product_save', 'Não foi possível salvar o produto WooCommerce.' );

            if ( $is_variable ) {
                self::replace_attributes( $product_id, $variation_rows );
                $variation_count = self::sync_variations( $product_id, $variation_rows, $reference );
                if ( is_wp_error( $variation_count ) ) return $variation_count;
            } else {
                $mapped = ! empty( $variation_rows ) ? SWCS_Mapper::variation( $variation_rows[0] ) : array();
                if ( ! empty( $mapped['price'] ) ) $product->set_regular_price( (string) $mapped['price'] );
                $product->set_manage_stock( true );
                $product->set_stock_status( ! empty( $mapped['stock_out'] ) ? 'outofstock' : 'instock' );
                $product->save(); $variation_count = 0;
            }
        } catch ( Throwable $e ) {
            return new WP_Error( 'swcs_import_exception', 'Erro ao importar ' . $reference . ': ' . $e->getMessage() );
        }
        return array( 'product_id' => $product_id, 'reference' => $reference, 'type' => $is_variable ? 'variable' : 'simple', 'variations' => $variation_count );
    }

    private static function is_variable( $row, $variation_rows ) {
        if ( 'True' === (string) ( $row['HasColors'] ?? '' ) || 'True' === (string) ( $row['HasSizes'] ?? '' ) || 'True' === (string) ( $row['HasCapacitys'] ?? '' ) ) return true;
        foreach ( $variation_rows as $variation ) if ( ! empty( $variation['ColorDesc1'] ) || ! empty( $variation['Size'] ) || ! empty( $variation['Capacity'] ) ) return true;
        return count( $variation_rows ) > 1;
    }

    private static function replace_attributes( $product_id, $rows ) {
        $product = wc_get_product( $product_id ); if ( ! $product ) return new WP_Error( 'product_load', 'Não foi possível carregar o produto para configurar os atributos.' );
        $attributes = array();
        foreach ( array( 'ColorDesc1' => 'Color', 'Size' => 'Size', 'Capacity' => 'Capacity' ) as $field => $label ) {
            $values = array(); foreach ( $rows as $row ) { $value = trim( (string) ( $row[ $field ] ?? '' ) ); if ( '' !== $value ) $values[$value] = true; }
            if ( empty( $values ) ) continue;
            $attribute = new WC_Product_Attribute(); $attribute->set_id( 0 ); $attribute->set_name( $label ); $attribute->set_options( array_keys( $values ) ); $attribute->set_visible( true ); $attribute->set_variation( true ); $attributes[] = $attribute;
        }
        $product->set_attributes( $attributes ); $product->save(); return true;
    }

    private static function sync_variations( $product_id, $rows, $reference ) {
        $existing = array();
        foreach ( get_children( array( 'post_parent' => $product_id, 'post_type' => 'product_variation', 'post_status' => 'any', 'fields' => 'ids' ) ) as $id ) {
            $variation = wc_get_product( $id ); if ( ! $variation ) continue;
            $stored = (string) $variation->get_meta( '_swcs_stricker_sku', true ); if ( '' !== $stored ) $existing[$stored] = $id;
            $current = (string) $variation->get_sku(); if ( '' !== $current ) $existing[$current] = $id;
        }
        $count = 0;
        foreach ( $rows as $row ) {
            $mapped = SWCS_Mapper::variation( $row ); $sku = $mapped['sku']; if ( '' === $sku ) continue;
            $variation_id = isset( $existing[$sku] ) ? (int) $existing[$sku] : 0;

            // A variation SKU is globally unique in WooCommerce. If it already exists, only
            // adopt it when the record itself proves it belongs to this Stricker reference.
            if ( ! $variation_id && function_exists( 'wc_get_product_id_by_sku' ) ) {
                $global_id = (int) wc_get_product_id_by_sku( $sku );
                if ( $global_id ) {
                    $global = wc_get_product( $global_id );
                    $stored_reference = $global ? (string) $global->get_meta( '_swcs_stricker_reference', true ) : '';
                    $global_is_variation = $global && $global instanceof WC_Product_Variation;
                    $global_parent = $global ? (int) $global->get_parent_id() : 0;

                    if ( $global_is_variation && $stored_reference === $reference ) {
                        // This is an existing Stricker variation, even if its parent was changed
                        // or the variation became detached. Reattach it to the canonical parent.
                        $variation_id = $global_id;
                    } elseif ( $global_is_variation && $global_parent === (int) $product_id ) {
                        $variation_id = $global_id;
                    } else {
                        $owner_label = $global_is_variation ? 'variação WooCommerce' : 'produto WooCommerce';
                        return new WP_Error( 'duplicate_variation_sku', 'A variação ' . $sku . ' já pertence a outro ' . $owner_label . ' (ID ' . $global_id . '). Nenhuma alteração foi feita nesta variação.' );
                    }
                }
            }

            $variation = $variation_id ? wc_get_product( $variation_id ) : new WC_Product_Variation();
            if ( ! $variation ) return new WP_Error( 'variation_load', 'Não foi possível carregar a variação ' . $sku . '.' );
            try {
                $variation->set_parent_id( $product_id );
                $sku_error = self::set_unique_sku( $variation, $sku, 'variação', $reference ); if ( is_wp_error( $sku_error ) ) return $sku_error;
                if ( null !== $mapped['price'] ) $variation->set_regular_price( (string) $mapped['price'] );
                $variation->set_manage_stock( true ); $variation->set_stock_status( ! empty( $mapped['stock_out'] ) ? 'outofstock' : 'instock' );
                $variation->set_attributes( self::variation_attributes( $mapped ) );
                $variation->update_meta_data( '_swcs_stricker_sku', $sku ); $variation->update_meta_data( '_swcs_stricker_reference', $reference ); $variation->update_meta_data( '_swcs_color_code', $mapped['color_code'] );
                $variation->save(); $count++;
            } catch ( Throwable $e ) { return new WP_Error( 'variation_save', 'Erro ao salvar a variação ' . $sku . ': ' . $e->getMessage() ); }
        }
        WC_Product_Variable::sync( $product_id ); return $count;
    }

    private static function set_unique_sku( $product, $sku, $kind, $reference ) {
        $sku = (string) $sku; $current_id = (int) $product->get_id();
        $owner_id = function_exists( 'wc_get_product_id_by_sku' ) ? (int) wc_get_product_id_by_sku( $sku ) : 0;
        if ( $owner_id && $owner_id !== $current_id ) return new WP_Error( 'duplicate_sku', 'O SKU ' . $sku . ' da ' . $kind . ' já pertence ao WooCommerce ID ' . $owner_id . '. Nenhuma alteração foi feita nesta importação.' );
        $product->set_sku( $sku ); return true;
    }

    private static function variation_attributes( $mapped ) {
        $attributes = array(); if ( '' !== $mapped['color'] ) $attributes['color'] = $mapped['color']; if ( '' !== $mapped['size'] ) $attributes['size'] = $mapped['size']; if ( '' !== $mapped['capacity'] ) $attributes['capacity'] = $mapped['capacity']; return $attributes;
    }

    private static function set_category( $product, $type, $subtype ) {
        if ( '' === $type ) return; $parent = term_exists( $type, 'product_cat' ); if ( ! $parent ) $parent = wp_insert_term( $type, 'product_cat' ); if ( is_wp_error( $parent ) ) return;
        $parent_id = (int) ( is_array( $parent ) ? $parent['term_id'] : $parent ); $terms = array( $parent_id );
        if ( '' !== $subtype ) { $child = term_exists( $subtype, 'product_cat', $parent_id ); if ( ! $child ) $child = wp_insert_term( $subtype, 'product_cat', array( 'parent' => $parent_id ) ); if ( ! is_wp_error( $child ) ) $terms[] = (int) ( is_array( $child ) ? $child['term_id'] : $child ); }
        $product->set_category_ids( $terms );
    }

    private static function find_product_id( $reference ) { $ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_swcs_prod_reference', 'meta_value' => $reference ) ); return empty( $ids ) ? 0 : (int) $ids[0]; }
    private static function text( $row, $key ) { return trim( (string) ( $row[$key] ?? '' ) ); }
    private static function detect_delimiter( $handle ) { $position = ftell( $handle ); $sample = fgets( $handle ); fseek( $handle, $position ); $best = ','; $count = 0; foreach ( array( ';', ',', "\t", '|' ) as $delimiter ) { $current = substr_count( (string) $sample, $delimiter ); if ( $current > $count ) { $best = $delimiter; $count = $current; } } return $best; }
}
