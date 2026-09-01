<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Creates/updates WooCommerce products from the local Stricker CSV catalog.
 * Images are intentionally not imported at this stage.
 *
 * Identity rules:
 * - Parent products are identified by Stricker ProdReference.
 * - Variations are identified by Stricker Sku/WebSku.
 * - Stricker identity metadata is authoritative for future synchronisation.
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

        // The Stricker reference is the authoritative parent identity. Prefer our
        // metadata over a WooCommerce SKU lookup so old/test records are reused.
        $existing_id = self::find_parent_by_stricker_reference( $reference );
        if ( is_wp_error( $existing_id ) ) { return $existing_id; }
        if ( ! $existing_id ) {
            $existing_id = wc_get_product_id_by_sku( $reference );
        }

        if ( $existing_id ) {
            $post_type = get_post_type( $existing_id );
            if ( 'product' !== $post_type ) {
                return new WP_Error( 'parent_sku_conflict', 'O SKU ' . $reference . ' está associado a outro tipo de objeto WooCommerce.' );
            }

            // If the existing test/product record is simple but Stricker says it
            // has variation dimensions, convert the product type before creating
            // or updating variations. The existing product ID is preserved.
            if ( 'variable' === $type ) {
                self::ensure_variable_product_type( $existing_id );
            }

            $wc_product = wc_get_product( $existing_id );
        } else {
            $wc_product = ( 'variable' === $type ) ? new WC_Product_Variable() : new WC_Product_Simple();
        }
        if ( ! $wc_product ) {
            return new WP_Error( 'product_load_failed', 'Não foi possível carregar/criar o produto WooCommerce para a referência ' . $reference . '.' );
        }

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

        try {
            $id = $wc_product->save();
        } catch ( WC_Data_Exception $e ) {
            return new WP_Error( 'product_save_exception', 'Não foi possível salvar o produto ' . $reference . ': ' . $e->getMessage() );
        }
        if ( ! $id ) { return new WP_Error( 'product_save_failed', 'Falha ao salvar o produto WooCommerce ' . $reference . '.' ); }

        self::assign_categories( $id, $product );
        self::save_metadata( $id, $product );

        $created_variations = 0;
        if ( 'variable' === $type ) {
            self::configure_variable_attributes( $wc_product, $variations );
            try {
                $wc_product->save();
            } catch ( WC_Data_Exception $e ) {
                return new WP_Error( 'variable_product_save_exception', 'Não foi possível salvar os atributos do produto ' . $reference . ': ' . $e->getMessage() );
            }
            $variation_result = self::sync_variations( $id, $variations );
            if ( is_wp_error( $variation_result ) ) { return $variation_result; }
            $created_variations = $variation_result;
        }

        return array( 'product_id' => $id, 'type' => $type, 'variations' => $created_variations );
    }

    private static function ensure_variable_product_type( $product_id ) {
        $current_type = wp_get_object_terms( $product_id, 'product_type', array( 'fields' => 'slugs' ) );
        if ( ! is_wp_error( $current_type ) && in_array( 'variable', $current_type, true ) ) {
            return;
        }
        wp_set_object_terms( $product_id, 'variable', 'product_type', false );
        clean_post_cache( $product_id );
        wc_delete_product_transients( $product_id );
    }

    private static function find_parent_by_stricker_reference( $reference ) {
        $ids = get_posts( array(
            'post_type'      => 'product',
            'post_status'    => 'any',
            'posts_per_page' => 2,
            'fields'         => 'ids',
            'meta_key'       => '_swcs_stricker_reference',
            'meta_value'     => $reference,
        ) );
        if ( count( $ids ) > 1 ) {
            return new WP_Error( 'duplicate_stricker_reference', 'A referência Stricker ' . $reference . ' está associada a mais de um produto WooCommerce. Limpe os duplicados antes de importar novamente.' );
        }
        return ! empty( $ids[0] ) ? (int) $ids[0] : 0;
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
        $sets = array( 'Color' => array(), 'Size' => array(), 'Capacity' => array() );
        foreach ( $variations as $variation ) {
            foreach ( $sets as $key => &$values ) {
                $field = strtolower( $key );
                if ( ! empty( $variation[ $field ] ) ) { $values[] = trim( $variation[ $field ] ); }
            }
            unset( $values );
        }
        $attributes = array();
        foreach ( $sets as $name => $values ) {
            $values = array_values( array_unique( array_filter( $values ) ) );
            if ( empty( $values ) ) { continue; }
            $attributes[] = array( 'name' => $name, 'options' => $values );
        }
        $product->set_attributes( array_map( function( $data ) {
            $attribute = new WC_Product_Attribute();
            $attribute->set_name( $data['name'] );
            $attribute->set_options( $data['options'] );
            $attribute->set_visible( true );
            $attribute->set_variation( true );
            return $attribute;
        }, $attributes ) );
    }

    private static function find_variation_by_stricker_sku( $product_id, $sku ) {
        $ids = get_posts( array(
            'post_type'      => 'product_variation',
            'post_status'    => 'any',
            'posts_per_page' => 2,
            'fields'         => 'ids',
            'post_parent'    => $product_id,
            'meta_key'       => '_swcs_stricker_sku',
            'meta_value'     => $sku,
        ) );
        if ( count( $ids ) > 1 ) {
            return new WP_Error( 'duplicate_stricker_sku', 'O SKU Stricker ' . $sku . ' está associado a mais de uma variação do mesmo produto.' );
        }
        return ! empty( $ids[0] ) ? (int) $ids[0] : 0;
    }

    private static function sync_variations( $product_id, $variations ) {
        $count = 0;
        $existing_children = array();
        $children = get_posts( array( 'post_type' => 'product_variation', 'post_parent' => $product_id, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) );
        foreach ( $children as $child_id ) {
            $stricker_sku = get_post_meta( $child_id, '_swcs_stricker_sku', true );
            $sku = $stricker_sku ? $stricker_sku : get_post_meta( $child_id, '_sku', true );
            if ( $sku ) { $existing_children[ trim( (string) $sku ) ] = $child_id; }
        }

        foreach ( $variations as $variation ) {
            if ( empty( $variation['sku'] ) ) { continue; }

            $sku = trim( (string) $variation['sku'] );
            $variation_id = isset( $existing_children[ $sku ] ) ? (int) $existing_children[ $sku ] : 0;
            if ( ! $variation_id ) {
                $variation_id = self::find_variation_by_stricker_sku( $product_id, $sku );
                if ( is_wp_error( $variation_id ) ) { return $variation_id; }
            }

            if ( ! $variation_id ) {
                $global_id = wc_get_product_id_by_sku( $sku );
                if ( $global_id ) {
                    $global_product = wc_get_product( $global_id );
                    if ( $global_product && $global_product->is_type( 'variation' ) && (int) $global_product->get_parent_id() === (int) $product_id ) {
                        $variation_id = (int) $global_id;
                    } else {
                        return new WP_Error( 'variation_sku_conflict', 'O SKU da variação ' . $sku . ' já existe no WooCommerce e pertence a outro produto. Nenhuma alteração foi feita nessa variação.' );
                    }
                }
            }

            $wc_variation = $variation_id ? new WC_Product_Variation( $variation_id ) : new WC_Product_Variation();
            if ( ! $variation_id ) { $wc_variation->set_parent_id( $product_id ); }

            try {
                $wc_variation->set_sku( $sku );
                if ( null !== $variation['price'] ) { $wc_variation->set_regular_price( (string) $variation['price'] ); }
                $wc_variation->set_manage_stock( false );
                $wc_variation->set_stock_status( ! empty( $variation['stock_out'] ) ? 'outofstock' : 'instock' );
                $attrs = array();
                if ( ! empty( $variation['color'] ) ) { $attrs['attribute_color'] = $variation['color']; }
                if ( ! empty( $variation['size'] ) ) { $attrs['attribute_size'] = $variation['size']; }
                if ( ! empty( $variation['capacity'] ) ) { $attrs['attribute_capacity'] = $variation['capacity']; }
                $wc_variation->set_attributes( $attrs );
                $wc_variation->save();
            } catch ( WC_Data_Exception $e ) {
                return new WP_Error( 'variation_save_exception', 'Não foi possível salvar a variação ' . $sku . ': ' . $e->getMessage() );
            }

            update_post_meta( $wc_variation->get_id(), '_swcs_stricker_sku', $sku );
            update_post_meta( $wc_variation->get_id(), '_swcs_color_code', $variation['color_code'] );
            update_post_meta( $wc_variation->get_id(), '_swcs_image_reference', $variation['image'] );
            $count++;
        }
        return $count;
    }
}
