<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Converts Stricker CSV rows into a stable internal product model.
 * This layer does not create WooCommerce products yet.
 */
class SWCS_Mapper {
    public static function product( $row ) {
        $product = array(
            'reference'      => self::value( $row, 'ProdReference' ),
            'name'           => self::value( $row, 'Name' ),
            'description'    => self::value( $row, 'Description' ),
            'short_description' => self::value( $row, 'ShortDescription' ),
            'seo_name'       => self::value( $row, 'SEOName' ),
            'seo_short_description' => self::value( $row, 'SEOShortDescription' ),
            'type_code'      => self::value( $row, 'TypeCode' ),
            'type'           => self::value( $row, 'Type' ),
            'subtype_code'   => self::value( $row, 'SubTypeCode' ),
            'subtype'        => self::value( $row, 'SubType' ),
            'brand'          => self::value( $row, 'Brand' ),
            'material'       => self::value( $row, 'Materials' ),
            'country_of_origin' => self::value( $row, 'CountryOfOrigin' ),
            'taric'          => self::value( $row, 'Taric' ),
            'certificates'   => self::value( $row, 'Certificates' ),
            'keywords'       => self::value( $row, 'KeyWords' ),
            'main_image'     => self::value( $row, 'MainImage' ),
            'additional_images' => self::value( $row, 'AditionalImageList' ),
            'video'          => self::value( $row, 'VideoLink' ),
            'weight_grams'   => self::number( self::value( $row, 'WeightGr' ) ),
            'available'      => self::bool( self::value( $row, 'AvailableGross' ) ),
            'stock_out'      => self::bool( self::value( $row, 'IsStockOut' ) ),
            'seasonal'       => self::bool( self::value( $row, 'IsSeasonal' ) ),
            'online_exclusive' => self::bool( self::value( $row, 'OnlineExclusive' ) ),
            'updated_at'     => self::value( $row, 'UpdateDate' ),
        );

        return $product;
    }

    public static function variation( $row ) {
        return array(
            'sku'            => self::value( $row, 'Sku' ) ?: self::value( $row, 'WebSku' ),
            'web_sku'        => self::value( $row, 'WebSku' ),
            'reference'      => self::value( $row, 'ProdReference' ),
            'color'          => self::value( $row, 'ColorDesc1' ),
            'color_hex'      => self::value( $row, 'ColorHex1' ),
            'color_code'     => self::value( $row, 'ColorCode' ),
            'size'           => self::value( $row, 'Size' ),
            'capacity'       => self::value( $row, 'Capacity' ),
            'image'          => self::value( $row, 'MainImage' ),
            'your_price'     => self::number( self::value( $row, 'YourPrice' ) ),
            'min_quantity'   => self::number( self::value( $row, 'MinQt1' ) ),
            'price'          => self::number( self::value( $row, 'Price1' ) ),
            'stock_out'      => self::bool( self::value( $row, 'IsStockOut' ) ),
            'available'      => self::bool( self::value( $row, 'AvailableGross' ) ),
            'new_product'    => self::bool( self::value( $row, 'NewProduct' ) ),
            'no_replenishment' => self::bool( self::value( $row, 'NoReplenishment' ) ),
        );
    }

    public static function classification( $product, $variations = array() ) {
        $has_variation_dimensions = false;
        foreach ( $variations as $variation ) {
            if ( ! empty( $variation['color'] ) || ! empty( $variation['size'] ) || ! empty( $variation['capacity'] ) ) {
                $has_variation_dimensions = true;
                break;
            }
        }
        return $has_variation_dimensions || count( $variations ) > 1 ? 'variable' : 'simple';
    }

    private static function value( $row, $key ) {
        return isset( $row[ $key ] ) ? trim( (string) $row[ $key ] ) : '';
    }

    private static function number( $value ) {
        if ( '' === $value ) { return null; }
        $value = str_replace( array( ' ', ',' ), array( '', '.' ), $value );
        return is_numeric( $value ) ? (float) $value : null;
    }

    private static function bool( $value ) {
        return in_array( strtolower( trim( (string) $value ) ), array( 'true', '1', 'yes', 'sim' ), true );
    }
}
