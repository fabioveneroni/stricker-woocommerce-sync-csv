<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Creates/updates WooCommerce products from the local Stricker CSV catalog.
 * Images are intentionally not imported at this stage.
 */
class SWCS_Importer {
    public static function is_available() {
        return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
    }

    public static function import_reference( $reference ) {
        if ( ! self::is_available() ) {
            return new WP_Error( 'woocommerce_required', 'WooCommerce precisa estar ativo antes da importação.' );
        }
        $reference = trim( (string) $reference );
        if ( '' === $reference ) {
            return new WP_Error( 'missing_reference', 'Informe uma referência de produto.' );
        }

        $product_row = self::find_row( 'products', 'ProdReference', $reference );
        if ( is_wp_error( $product_row ) ) { return $product_row; }

        $product = SWCS_Mapper::product( $product_row );
        $variation_rows = self::find_rows( 'optionalscomplete', 'ProdReference', $reference );
        if ( is_wp_error( $variation_rows ) ) { return $variation_rows; }

        $variations = array();
        foreach ( $variation_rows as $row ) { $variations[] = SWCS_Mapper::variation( $row ); }
        $type = SWCS_Mapper::classification( $product, $variations );

        $existing_id = wc_get_product_id_by_sku( $reference );
        if ( $existing_id ) {
            $wc_product = wc_get_product( $existing_id );
        } else {
            $wc_product = ( 'variable' === $type ) ? new WC_Product_Variable() : new WC_Product_Simple();
        }
        if ( ! $wc_product ) { return new WP_Error( 'product_load_failed', 'Não foi possível carregar/criar o produto WooCommerce.' ); }

        $wc_product->set_name( self::clean_name( $product['name'], $reference ) );
        $wc_product->set_description( wp_kses_post( $product['description'] ) );
        $wc_product->set_short_description( wp_kses_post( $product['short_description'] ) );
        $wc_product->set_status( 'draft' );
        $wc_product->set_catalog_visibility( 'visible' );
        $wc_product->set_sku( $reference );

        if ( 'simple' === $type ) {
            $first = ! empty( $variations[0] ) ? $variations[0] : array();
            if ( isset( $first['price'] ) && null !== $first['price'] ) { $wc_product->set_regular_price( (string) $first['price'] ); }
            $wc_product->set_manage_stock( false );
            $wc_product->set_stock_status( ! empty( $product['stock_out'] ) ? 'outofstock' : 'instock' );
        }

        $id = $wc_product->save();
        if ( ! $id ) { return new WP_Error( 'product_save_failed', 'Falha ao salvar o produto WooCommerce.' ); }

        self::assign_categories( $id, $product );
        self::save_metadata( $id, $product );

        $created_variations = 0;
        if ( 'variable' === $type ) {
            self::configure_variable_attributes( $wc_product, $variations );
            $wc_product->save();
            $created_variations = self::sync_variations( $id, $variations );
        }

        return array( 'product_id' => $id, 'type' => $type, 'variations' => $created_variations );
    }

    private static function find_row( $dataset, $field, $value ) {
        $rows = self::find_rows( $dataset, $field, $value, 1 );
        if ( is_wp_error( $rows ) ) { return $rows; }
        return ! empty( $rows[0] ) ? $rows[0] : new WP_Error( 'not_found', 'Produto ' . $value . ' não encontrado no CSV ' . $dataset . '.' );
    }

    private static function find_rows( $dataset, $field, $value, $limit = 0 ) {
        $status = get_option( 'swcs_catalog_' . $dataset . '_status', array() );
        if ( empty( $status['path'] ) || ! is_readable( $status['path'] ) ) {
            return new WP_Error( 'csv_missing', 'O CSV ' . $dataset . ' não está disponível. Baixe-o antes de importar.' );
        }
        $handle = fopen( $status['path'], 'rb' );
        if ( ! $handle ) { return new WP_Error( 'csv_open_failed', 'Não foi possível abrir o CSV ' . $dataset . '.' ); }
        $sample = fgets( $handle ); rewind( $handle );
        $delimiter = self::detect_delimiter( $sample );
        $header = fgetcsv( $handle, 0, $delimiter );
        if ( false === $header ) { fclose( $handle ); return new WP_Error( 'csv_header_failed', 'Não foi possível ler o cabeçalho do CSV ' . $dataset . '.' ); }
        if ( isset( $header[0] ) ) { $header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] ); }
        $header = array_map( 'trim', $header );
        $results = array();
        while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter ) ) ) {
            $row = array_pad( $row, count( $header ), '' );
            if ( count( $row ) > count( $header ) ) { $row = array_slice( $row, 0, count( $header ) ); }
            $data = array_combine( $header, $row );
            if ( isset( $data[ $field ] ) && trim( (string) $data[ $field ] ) === $value ) {
                $results[] = $data;
                if ( $limit > 0 && count( $results ) >= $limit ) { break; }
            }
        }
        fclose( $handle );
        return $results;
    }

    private static function detect_delimiter( $sample ) {
        $best = ';'; $best_count = -1;
        foreach ( array( ';', ',', "\t", '|' ) as $delimiter ) {
            $count = substr_count( (string) $sample, $delimiter );
            if ( $count > $best_count ) { $best = $delimiter; $best_count = $count; }
        }
        return $best;
    }

    private static function clean_name( $name, $reference ) {
        $name = trim( (string) $name );
        $prefix = $reference . '.';
        if ( 0 === strpos( $name, $prefix ) ) { $name = trim( substr( $name, strlen( $prefix ) ) ); }
        return $name !== '' ? $name : $reference;
    }

    private static function assign_categories( $product_id, $product ) {
        $parent = self::term_id( $product['type'], 0 );
        if ( $parent ) {
            $child = self::term_id( $product['subtype'], $parent );
            wp_set_object_terms( $product_id, $child ? array( $child ) : array( $parent ), 'product_cat', false );
        }
    }

    private static function term_id( $name, $parent = 0 ) {
        $name = trim( (string) $name );
        if ( '' === $name ) { return 0; }
        $term = term_exists( $name, 'product_cat', $parent );
        if ( $term ) { return (int) ( is_array( $term ) ? $term['term_id'] : $term ); }
        $created = wp_insert_term( $name, 'product_cat', array( 'parent' => (int) $parent ) );
        return is_wp_error( $created ) ? 0 : (int) $created['term_id'];
    }

    private static function save_metadata( $id, $product ) {
        update_post_meta( $id, '_swcs_stricker_reference', $product['reference'] );
        update_post_meta( $id, '_swcs_brand', $product['brand'] );
        update_post_meta( $id, '_swcs_materials', $product['material'] );
        update_post_meta( $id, '_swcs_main_image_reference', $product['main_image'] );
        update_post_meta( $id, '_swcs_last_update', $product['updated_at'] );
    }

    private static function configure_variable_attributes( $product, $variations ) {
        $sets = array(
            'Color' => array(),
            'Size' => array(),
            'Capacity' => array(),
        );
        foreach ( $variations as $variation ) {
            foreach ( $sets as $key => &$values ) {
                if ( ! empty( $variation[ strtolower( $key ) ] ) ) { $values[] = trim( $variation[ strtolower( $key ) ] ); }
            }
            unset( $values );
        }
        $attributes = array();
        foreach ( $sets as $name => $values ) {
            $values = array_values( array_unique( array_filter( $values ) ) );
            if ( empty( $values ) ) { continue; }
            $attributes[ sanitize_title( $name ) ] = array( 'name' => $name, 'options' => $values, 'visible' => true, 'variation' => true );
        }
        $product->set_attributes( array_map( function( $data ) { $attribute = new WC_Product_Attribute(); $attribute->set_name( $data['name'] ); $attribute->set_options( $data['options'] ); $attribute->set_visible( true ); $attribute->set_variation( true ); return $attribute; }, array_values( $attributes ) ) );
    }

    private static function sync_variations( $product_id, $variations ) {
        $count = 0;
        $existing = array();
        $children = get_posts( array( 'post_type' => 'product_variation', 'post_parent' => $product_id, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) );
        foreach ( $children as $child_id ) { $sku = get_post_meta( $child_id, '_sku', true ); if ( $sku ) { $existing[ $sku ] = $child_id; } }
        foreach ( $variations as $variation ) {
            if ( empty( $variation['sku'] ) ) { continue; }
            $variation_id = isset( $existing[ $variation['sku'] ] ) ? $existing[ $variation['sku'] ] : 0;
            $wc_variation = $variation_id ? new WC_Product_Variation( $variation_id ) : new WC_Product_Variation();
            if ( ! $variation_id ) { $wc_variation->set_parent_id( $product_id ); }
            $wc_variation->set_sku( $variation['sku'] );
            if ( null !== $variation['price'] ) { $wc_variation->set_regular_price( (string) $variation['price'] ); }
            $wc_variation->set_manage_stock( false );
            $wc_variation->set_stock_status( ! empty( $variation['stock_out'] ) ? 'outofstock' : 'instock' );
            $attrs = array();
            if ( ! empty( $variation['color'] ) ) { $attrs['attribute_color'] = $variation['color']; }
            if ( ! empty( $variation['size'] ) ) { $attrs['attribute_size'] = $variation['size']; }
            if ( ! empty( $variation['capacity'] ) ) { $attrs['attribute_capacity'] = $variation['capacity']; }
            $wc_variation->set_attributes( $attrs );
            $wc_variation->save();
            update_post_meta( $wc_variation->get_id(), '_swcs_stricker_sku', $variation['sku'] );
            update_post_meta( $wc_variation->get_id(), '_swcs_color_code', $variation['color_code'] );
            update_post_meta( $wc_variation->get_id(), '_swcs_image_reference', $variation['image'] );
            $count++;
        }
        return $count;
    }
}
