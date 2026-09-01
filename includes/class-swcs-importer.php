<?php
if (!defined('ABSPATH')) exit;

class SWCS_Importer {
    public static function import_reference($reference) {
        $product_row = SWCS_Data::find_product($reference);
        if (!$product_row) return new WP_Error('swcs_product_not_found', 'Produto Stricker não encontrado.');

        $product = self::find_existing_product($reference);
        if (!$product) {
            $product = new WC_Product_Variable();
            $product->set_status('draft');
            $product->set_sku((string)$reference);
            $product->update_meta_data('_swcs_stricker_reference', (string)$reference);
            $product->set_name(self::value($product_row, 'Name', $reference));
            $product->set_description(self::value($product_row, 'Description'));
            $product->set_short_description(self::value($product_row, 'ShortDescription'));
            $product_id = $product->save();
        } else {
            $product_id = $product->get_id();
            // The product status and other editorial fields are deliberately preserved.
            // Catalog fields supplied by Stricker are refreshed on every synchronization.
            $product->set_sku((string)$reference);
            $product->set_name(self::value($product_row, 'Name', $product->get_name()));
            $product->set_description(self::value($product_row, 'Description', $product->get_description()));
            $product->set_short_description(self::value($product_row, 'ShortDescription', $product->get_short_description()));
            $product->update_meta_data('_swcs_stricker_reference', (string)$reference);
            $product->save();
        }

        if (!$product_id) return new WP_Error('swcs_product_save_failed', 'Não foi possível carregar/criar o produto WooCommerce.');

        return self::sync_variations($product_id, $reference);
    }

    private static function find_existing_product($reference) {
        $reference = (string)$reference;
        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_key' => '_swcs_stricker_reference',
            'meta_value' => $reference,
        ]);
        if (!$ids) return null;

        // Prefer the product whose current SKU is exactly the Stricker reference.
        foreach ($ids as $id) {
            $candidate = wc_get_product($id);
            if ($candidate && (string)$candidate->get_sku() === $reference) return $candidate;
        }
        foreach ($ids as $id) {
            $candidate = wc_get_product($id);
            if ($candidate && $candidate->is_type('variable')) return $candidate;
        }
        return wc_get_product($ids[0]);
    }

    private static function sync_variations($product_id, $reference) {
        $rows = SWCS_Data::find_optionals_complete($reference);
        if (!$rows) $rows = SWCS_Data::find_optionals($reference);
        if (!$rows) return $product_id;

        $parent = wc_get_product($product_id);
        if (!$parent || !$parent->is_type('variable')) {
            $parent = new WC_Product_Variable($product_id);
            $parent->set_status(get_post_status($product_id));
            $parent->save();
        }

        foreach ($rows as $row) {
            $sku = trim((string)self::value($row, 'Sku'));
            if ($sku === '') continue;
            $variation = self::find_variation($product_id, $sku);
            if (!$variation) {
                $variation = new WC_Product_Variation();
                $variation->set_parent_id($product_id);
            }
            $variation->set_sku($sku);
            if (isset($row['Name']) && $row['Name'] !== '') $variation->set_description((string)$row['Name']);
            $price = self::value($row, 'Price1', self::value($row, 'YourPrice'));
            if ($price !== '') {
                $variation->set_regular_price(wc_format_decimal($price));
                $variation->set_price(wc_format_decimal($price));
            }
            $variation->update_meta_data('_swcs_stricker_sku', $sku);
            $variation->update_meta_data('_swcs_stricker_reference', (string)$reference);
            $variation->save();
        }
        WC_Product_Variable::sync($product_id);
        return $product_id;
    }

    private static function find_variation($parent_id, $sku) {
        $ids = get_posts([
            'post_type' => 'product_variation',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
            'post_parent' => $parent_id,
            'meta_key' => '_swcs_stricker_sku',
            'meta_value' => (string)$sku,
        ]);
        if ($ids) return wc_get_product($ids[0]);
        return null;
    }

    private static function value($row, $key, $default = '') {
        return isset($row[$key]) ? (string)$row[$key] : $default;
    }
}
