<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only diagnostic of the Stricker catalog before WooCommerce import.
 */
class SWCS_Catalog_Diagnostic {
    public static function get_products( $page = 1, $per_page = 25, $search = '' ) {
        $status = get_option( 'swcs_catalog_products_status', array() );
        if ( empty( $status['path'] ) || ! is_readable( $status['path'] ) ) return new WP_Error( 'products_missing', 'O arquivo Products CSV ainda não foi baixado.' );
        $types = self::load_product_types(); $handle = fopen( $status['path'], 'rb' );
        if ( ! $handle ) return new WP_Error( 'products_open', 'Não foi possível abrir o Products CSV.' );
        $first = fgets( $handle ); if ( false === $first ) { fclose( $handle ); return new WP_Error( 'products_empty', 'O Products CSV está vazio.' ); }
        $delimiter = SWCS_CSV::detect_for_diagnostic( $first ); rewind( $handle ); $header = self::normalise_header( fgetcsv( $handle, 0, $delimiter ) );
        if ( false === $header ) { fclose( $handle ); return new WP_Error( 'products_header', 'Não foi possível ler o cabeçalho do Products CSV.' ); }
        $search = trim( (string) $search ); $page = max( 1, (int) $page ); $per_page = min( 100, max( 10, (int) $per_page ) ); $start = ( $page - 1 ) * $per_page; $matched = 0; $rows = array();
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
        $handle = fopen( $status['path'], 'rb' ); if ( ! $handle ) return new WP_Error( 'products_open', 'Não foi possível abrir o Products CSV.' ); $first = fgets( $handle ); if ( false === $first ) { fclose( $handle ); return new WP_Error( 'products_empty', 'O Products CSV está vazio.' ); }
        rewind( $handle ); $delimiter = SWCS_CSV::detect_for_diagnostic( $first ); $header = self::normalise_header( fgetcsv( $handle, 0, $delimiter ) ); $types = self::load_product_types();
        while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter ) ) ) { $product = self::combine_row( $header, $row ); if ( self::value( $product, array( 'ProdReference', 'ProductReference', 'Reference', 'SKU' ) ) === (string) $reference ) { fclose( $handle ); return self::diagnose_product( $product, $types, true ); } }
        fclose( $handle ); return new WP_Error( 'product_not_found', 'Produto não encontrado.' );
    }

    private static function diagnose_product( $p, $types, $full = false ) {
        $reference = self::value( $p, array( 'ProdReference', 'ProductReference', 'Reference', 'SKU' ) ); $type_code = self::value( $p, array( 'TypeCode', 'ProdTypeCode', 'ProductTypeCode' ) ); $sub_code = self::value( $p, array( 'SubTypeCode', 'ProdSubTypeCode', 'ProductSubTypeCode' ) );
        $colors = self::list_value( $p, array( 'Colors', 'Color', 'ColorCodes' ) ); $sizes = self::list_value( $p, array( 'Sizes', 'Size', 'SizeCodes' ) ); $capacity = self::list_value( $p, array( 'Capacitys', 'Capacities', 'Capacity' ) );
        $has_colors = self::bool_value( self::value( $p, array( 'HasColors' ) ) ) || ! empty( $colors ); $has_sizes = self::bool_value( self::value( $p, array( 'HasSizes' ) ) ) || ! empty( $sizes ); $has_capacity = self::bool_value( self::value( $p, array( 'HasCapacitys', 'HasCapacities' ) ) ) || ! empty( $capacity );
        $cross = self::cross_dataset_variants( $reference ); $multi = ( count( $colors ) > 1 || count( $sizes ) > 1 || count( $capacity ) > 1 ); $variation_values = self::variation_value_count( $cross['variant_values'] ); $distinct_skus = count( $cross['skus'] );
        $variable = $multi || $variation_values > 1 || $distinct_skus > 1;
        $result = array( 'reference'=>$reference, 'name'=>self::value($p,array('Name','ProductName')), 'description'=>self::value($p,array('Description')), 'short_description'=>self::value($p,array('ShortDescription')), 'type_code'=>$type_code, 'type_name'=>isset($types['types'][$type_code])?$types['types'][$type_code]:'', 'subtype_code'=>$sub_code, 'subtype_name'=>isset($types['subtypes'][$type_code][$sub_code])?$types['subtypes'][$type_code][$sub_code]:'', 'colors'=>$colors, 'sizes'=>$sizes, 'capacity'=>$capacity, 'has_colors'=>$has_colors, 'has_sizes'=>$has_sizes, 'has_capacity'=>$has_capacity, 'variant_count'=>$cross['variant_count'], 'variant_values'=>$cross['variant_values'], 'skus'=>$cross['skus'], 'variation_attributes'=>$cross['variation_attributes'], 'matched_rows'=>$cross['matched_rows'], 'stock_count'=>$cross['stock_count'], 'stock_available'=>$cross['stock_available'], 'variant_sources'=>$cross['sources'], 'woocommerce_type'=>$variable?'Variável (diagnóstico)':'Simples (diagnóstico)' );
        if ( $full ) $result['raw'] = $p; return $result;
    }

    private static function cross_dataset_variants( $reference ) {
        $out = array( 'variant_count'=>0, 'stock_count'=>0, 'stock_available'=>0, 'variant_values'=>array(), 'skus'=>array(), 'variation_attributes'=>array(), 'matched_rows'=>array(), 'sources'=>array() ); $seen=array();
        foreach ( array('optionalscomplete','optionals','stocks') as $dataset ) {
            $status=get_option('swcs_catalog_'.$dataset.'_status',array()); if(empty($status['path'])||!is_readable($status['path']))continue; $rows=self::find_rows_by_reference($status['path'],$reference); if(empty($rows))continue; $out['sources'][]=$dataset;
            foreach($rows as $row){
                if('stocks'===$dataset){$out['stock_count']++;$stock=self::value($row,array('Stock','Quantity','Available','AvailableQuantity','Qty','StockQuantity'));if(''!==$stock&&(is_numeric(str_replace(',','.',$stock))?(float)str_replace(',','.',$stock)>0:self::bool_value($stock)))$out['stock_available']++;continue;}
                $identity=self::row_identity($row);if(isset($seen[$identity]))continue;$seen[$identity]=true;$out['variant_count']++;
                $sku=self::value($row,array('Sku','SKU','SkuReference','VariantSku','ProductSku','Reference'));if(''!==$sku)$out['skus'][]=$sku;
                foreach($row as $key=>$value){if(''===trim((string)$value)||!self::is_variation_candidate($key,$value))continue;$values=self::list_value(array($key=>$value),array($key));if(empty($values))continue;$out['variation_attributes'][$key]=array_values(array_unique(array_merge(isset($out['variation_attributes'][$key])?$out['variation_attributes'][$key]:array(),$values)));}
                foreach(array('Color','Colors','ColorName','ColorDescription','Size','Sizes','Capacity','Capacitys','Option','OptionName','Variant','VariantName','Sku','SKU','SkuReference','VariantSku','Reference','VariantCode','OptionCode') as $key){if(array_key_exists($key,$row)&&''!==trim((string)$row[$key])){$values=self::list_value($row,array($key));$out['variant_values'][$key]=array_values(array_unique(array_merge(isset($out['variant_values'][$key])?$out['variant_values'][$key]:array(),$values)));}}
                if(count($out['matched_rows'])<50)$out['matched_rows'][]=array('dataset'=>$dataset,'row'=>$row);
            }
        }
        $out['skus']=array_values(array_unique($out['skus'])); return $out;
    }

    private static function variation_value_count($values){$count=0;foreach($values as $key=>$items){if(in_array(strtolower($key),array('sku','reference','variantcode','optioncode','skureference'),true))continue;$count=max($count,count(array_unique($items)));}return $count;}
    private static function is_variation_candidate($key,$value){$k=strtolower((string)$key);$blocked=array('price','currency','ean','barcode','gtin','stock','quantity','available','date','updated','reference','sku','code','id','image','weight');foreach($blocked as $term)if(false!==strpos($k,$term))return false;return false!==strpos($k,'color')||false!==strpos($k,'colour')||false!==strpos($k,'size')||false!==strpos($k,'capacity')||false!==strpos($k,'option')||false!==strpos($k,'variant');}
    private static function row_identity($row){foreach(array('Sku','SKU','SkuReference','VariantSku','ProductSku','Reference','VariantCode','OptionCode') as $key){if(array_key_exists($key,$row)&&''!==trim((string)$row[$key]))return $key.':'.trim((string)$row[$key]);}return md5(wp_json_encode($row));}

    private static function find_rows_by_reference($path,$reference){$h=fopen($path,'rb');if(!$h)return array();$first=fgets($h);if(false===$first){fclose($h);return array();}rewind($h);$delimiter=SWCS_CSV::detect_for_diagnostic($first);$header=self::normalise_header(fgetcsv($h,0,$delimiter));$keys=array('ProdReference','ProductReference','Reference','SKU','Sku','ProdRef','ProductRef');$result=array();while(false!==($row=fgetcsv($h,0,$delimiter))){$r=self::combine_row($header,$row);foreach($keys as $key){if(array_key_exists($key,$r)&&(string)trim($r[$key])===(string)$reference){$result[]=$r;break;}}}fclose($h);return $result;}
    private static function load_product_types(){ $status=get_option('swcs_catalog_producttypes_status',array());$out=array('types'=>array(),'subtypes'=>array());if(empty($status['path'])||!is_readable($status['path']))return $out;$h=fopen($status['path'],'rb');if(!$h)return $out;$first=fgets($h);rewind($h);$delimiter=SWCS_CSV::detect_for_diagnostic($first);$header=self::normalise_header(fgetcsv($h,0,$delimiter));while(false!==($row=fgetcsv($h,0,$delimiter))){$r=self::combine_row($header,$row);$tc=self::value($r,array('TypeCode'));$td=self::value($r,array('TypeDescription'));if(''===$tc)continue;$out['types'][$tc]=$td;for($i=1;$i<=34;$i++){$sc=self::value($r,array('SubTypeCode'.$i));$sd=self::value($r,array('SubTypeDescription'.$i));if(''!==$sc)$out['subtypes'][$tc][$sc]=$sd;}}fclose($h);return $out;}
    private static function count_matches($path,$delimiter,$header,$search){$h=fopen($path,'rb');if(!$h)return 0;fgetcsv($h,0,$delimiter);$n=0;while(false!==($row=fgetcsv($h,0,$delimiter))){if(count($row)===1&&''===trim((string)$row[0]))continue;$p=self::combine_row($header,$row);if(''===$search||self::matches_search($p,$search))$n++;}fclose($h);return $n;}
    private static function matches_search($p,$q){$q=strtolower($q);foreach(array('ProdReference','Name','Description','TypeCode','SubTypeCode') as $k){foreach($p as $key=>$v)if(strtolower($key)===strtolower($k)&&false!==strpos(strtolower((string)$v),$q))return true;}return false;}
    private static function value($row,$keys){foreach($keys as $key)if(array_key_exists($key,$row))return trim((string)$row[$key]);return '';}
    private static function list_value($row,$keys){$v=self::value($row,$keys);if(''===$v)return array();$parts=preg_split('/\s*[,;|]+\s*/',$v);return array_values(array_filter(array_map('trim',$parts),'strlen'));}
    private static function bool_value($v){return in_array(strtolower(trim((string)$v)),array('1','true','yes','sim','y'),true);}
    private static function combine_row($header,$row){$row=array_pad($row,count($header),'');if(count($row)>count($header))$row=array_slice($row,0,count($header));return array_combine($header,$row);}
    private static function normalise_header($header){if(isset($header[0]))$header[0]=preg_replace('/^\xEF\xBB\xBF/','',$header[0]);return array_map(function($v){return trim((string)$v);},$header);}
}
