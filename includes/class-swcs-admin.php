<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SWCS_Admin {
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_swcs_save_settings', array( __CLASS__, 'save_settings' ) );
        add_action( 'admin_post_swcs_download', array( __CLASS__, 'download' ) );
        add_action( 'admin_post_swcs_test_image', array( __CLASS__, 'test_image' ) );
        add_action( 'admin_post_swcs_import_product', array( __CLASS__, 'import_product' ) );
    }

    public static function activate() {
        if ( false === get_option( 'swcs_access_key', false ) ) { add_option( 'swcs_access_key', '' ); }
    }

    public static function menu() {
        add_menu_page( 'Stricker Catalog Sync', 'Stricker Sync', 'manage_options', 'swcs', array( __CLASS__, 'page' ), 'dashicons-update', 56 );
    }

    public static function save_settings() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'swcs_save_settings' ) ) { wp_die( 'Acesso negado.' ); }
        update_option( 'swcs_access_key', sanitize_text_field( wp_unslash( $_POST['access_key'] ?? '' ) ) );
        wp_safe_redirect( admin_url( 'admin.php?page=swcs&saved=1' ) ); exit;
    }

    public static function download() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'swcs_download' ) ) { wp_die( 'Acesso negado.' ); }
        $dataset = sanitize_key( wp_unslash( $_POST['dataset'] ?? '' ) );
        $result = SWCS_API::download_csv( $dataset, 'PT' );
        $args = array( 'page' => 'swcs', 'dataset' => $dataset );
        if ( is_wp_error( $result ) ) { $args['error'] = rawurlencode( $result->get_error_message() ); }
        else { $args['success'] = 1; }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) ); exit;
    }

    public static function import_product() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'swcs_import_product' ) ) { wp_die( 'Acesso negado.' ); }
        $reference = sanitize_text_field( wp_unslash( $_POST['product_reference'] ?? '' ) );
        $result = SWCS_Importer::import_reference( $reference );
        $args = array( 'page' => 'swcs', 'import_reference' => $reference );
        if ( is_wp_error( $result ) ) { $args['import_error'] = rawurlencode( $result->get_error_message() ); }
        else { $args['imported'] = 1; $args['import_type'] = rawurlencode( $result['type'] ); $args['import_variations'] = (int) $result['variations']; $args['import_product_id'] = (int) $result['product_id']; }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) ); exit;
    }

    public static function test_image() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'swcs_test_image' ) ) { wp_die( 'Acesso negado.' ); }
        $reference = sanitize_text_field( wp_unslash( $_POST['product_reference'] ?? '' ) );
        $product = self::find_product_for_reference( $reference );
        $args = array( 'page' => 'swcs', 'image_reference' => $reference );
        if ( is_wp_error( $product ) ) { $args['image_error'] = rawurlencode( $product->get_error_message() ); }
        else {
            $resolved = SWCS_Images::resolve_from_product( $product );
            if ( is_wp_error( $resolved ) ) { $args['image_error'] = rawurlencode( $resolved->get_error_message() ); }
            else { $args['image_url'] = rawurlencode( $resolved['url'] ); $args['image_method'] = rawurlencode( $resolved['method'] ); $args['image_page'] = rawurlencode( $resolved['page_url'] ?? '' ); }
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) ); exit;
    }

    private static function find_product_for_reference( $reference ) {
        if ( '' === $reference ) { return new WP_Error( 'missing_reference', 'Informe uma referência de produto.' ); }
        foreach ( array( 'products', 'optionalscomplete' ) as $dataset ) {
            $status = get_option( 'swcs_catalog_' . $dataset . '_status', array() );
            if ( empty( $status['path'] ) || ! is_readable( $status['path'] ) ) { continue; }
            $handle = fopen( $status['path'], 'rb' ); if ( ! $handle ) { continue; }
            $delimiter = self::detect_delimiter( $handle ); $header = fgetcsv( $handle, 0, $delimiter );
            if ( false === $header ) { fclose( $handle ); continue; }
            if ( isset( $header[0] ) ) { $header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] ); }
            $header = array_map( 'trim', $header );
            while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter ) ) ) {
                $row = array_pad( $row, count( $header ), '' ); if ( count( $row ) > count( $header ) ) { $row = array_slice( $row, 0, count( $header ) ); }
                $data = array_combine( $header, $row );
                if ( isset( $data['ProdReference'] ) && trim( (string) $data['ProdReference'] ) === $reference ) { fclose( $handle ); return SWCS_Mapper::product( $data ); }
            }
            fclose( $handle );
        }
        return new WP_Error( 'product_not_found', 'Produto ' . $reference . ' não encontrado nos CSVs baixados.' );
    }

    private static function detect_delimiter( $handle ) {
        $position = ftell( $handle ); $sample = fgets( $handle ); fseek( $handle, $position ); $best = ','; $best_count = 0;
        foreach ( array( ';', ',', "\t", '|' ) as $delimiter ) { $count = substr_count( (string) $sample, $delimiter ); if ( $count > $best_count ) { $best = $delimiter; $best_count = $count; } }
        return $best;
    }

    public static function page() {
        $datasets = array( 'producttypes' => 'Product Types', 'products' => 'Products', 'optionals' => 'Optionals', 'optionalsPrice' => 'Optionals Price', 'optionalscomplete' => 'Optionals Complete', 'customizationOptions' => 'Customization Options', 'customizationTables' => 'Customization Tables', 'colors' => 'Colors', 'stocks' => 'Stocks' );
        ?>
        <div class="wrap">
            <h1>Stricker WooCommerce Catalog Sync</h1>
            <?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p>Access key salva com sucesso.</p></div><?php endif; ?>
            <?php if ( isset( $_GET['success'] ) ) : ?><div class="notice notice-success"><p>Download de <?php echo esc_html( $_GET['dataset'] ?? '' ); ?> concluído com sucesso.</p></div><?php endif; ?>
            <?php if ( isset( $_GET['error'] ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( wp_unslash( $_GET['error'] ) ); ?></p></div><?php endif; ?>
            <?php if ( isset( $_GET['import_error'] ) ) : ?><div class="notice notice-error"><p>Importação: <?php echo esc_html( wp_unslash( $_GET['import_error'] ) ); ?></p></div><?php endif; ?>
            <?php if ( isset( $_GET['imported'] ) ) : ?><div class="notice notice-success"><p><strong>Produto importado/atualizado.</strong> ID WooCommerce: <?php echo (int) $_GET['import_product_id']; ?> · Tipo: <?php echo esc_html( $_GET['import_type'] ); ?> · Variações processadas: <?php echo (int) $_GET['import_variations']; ?></p></div><?php endif; ?>
            <?php if ( isset( $_GET['image_error'] ) ) : ?><div class="notice notice-error"><p>Teste de imagem: <?php echo esc_html( wp_unslash( $_GET['image_error'] ) ); ?></p></div><?php endif; ?>
            <?php if ( isset( $_GET['image_url'] ) ) : ?><div class="notice notice-success"><p><strong>Imagem localizada.</strong><br>Referência: <?php echo esc_html( $_GET['image_reference'] ?? '' ); ?><br>Método: <?php echo esc_html( $_GET['image_method'] ?? '' ); ?><br>URL: <a href="<?php echo esc_url( wp_unslash( $_GET['image_url'] ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( wp_unslash( $_GET['image_url'] ) ); ?></a></p></div><?php endif; ?>

            <h2>Conexão</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="swcs_save_settings"><?php wp_nonce_field( 'swcs_save_settings' ); ?>
                <table class="form-table"><tr><th><label for="swcs-access-key">Access Key</label></th><td><input id="swcs-access-key" name="access_key" type="password" class="regular-text" value="<?php echo esc_attr( SWCS_API::get_access_key() ); ?>" autocomplete="off"><p class="description">A chave é usada para construir as URLs oficiais de download CSV.</p></td></tr></table>
                <?php submit_button( 'Salvar Access Key' ); ?>
            </form>

            <h2>Importação de produto</h2>
            <?php if ( ! SWCS_Importer::is_available() ) : ?><div class="notice notice-warning inline"><p><strong>WooCommerce não está ativo.</strong> Instale/ative o WooCommerce antes de testar a criação de produtos.</p></div><?php endif; ?>
            <p>Esta etapa cria ou atualiza <strong>um produto por vez</strong>. O produto fica como rascunho. Imagens não são importadas nesta fase.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="swcs_import_product"><?php wp_nonce_field( 'swcs_import_product' ); ?>
                <input name="product_reference" type="text" class="regular-text" placeholder="Ex.: 11103" value="<?php echo esc_attr( $_GET['import_reference'] ?? '' ); ?>">
                <?php submit_button( 'Importar produto de teste', 'primary', 'submit', false ); ?>
            </form>

            <h2>Teste de imagem</h2>
            <p>Temporariamente mantido apenas para diagnóstico. Não cria produtos nem adiciona imagens.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="swcs_test_image"><?php wp_nonce_field( 'swcs_test_image' ); ?>
                <input name="product_reference" type="text" class="regular-text" placeholder="Ex.: 11103" value="<?php echo esc_attr( $_GET['image_reference'] ?? '' ); ?>">
                <?php submit_button( 'Testar imagem', 'secondary', 'submit', false ); ?>
            </form>

            <h2>Catálogos CSV</h2>
            <p>Os arquivos são baixados diretamente da API HTTPS da Stricker e salvos localmente para posterior processamento.</p>
            <table class="widefat striped"><thead><tr><th>Dataset</th><th>Status</th><th>Ação</th></tr></thead><tbody>
            <?php foreach ( $datasets as $key => $label ) : $status = get_option( 'swcs_catalog_' . $key . '_status', array() ); ?>
                <tr><td><strong><?php echo esc_html( $label ); ?></strong></td><td><?php echo ! empty( $status['completed'] ) ? 'Concluído em ' . esc_html( $status['completed'] ) . ' (' . esc_html( size_format( (int) ( $status['size'] ?? 0 ) ) ) . ')' : 'Ainda não baixado'; ?></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'swcs_download' ); ?><input type="hidden" name="action" value="swcs_download"><input type="hidden" name="dataset" value="<?php echo esc_attr( $key ); ?>"><?php submit_button( 'Baixar CSV', 'secondary', 'submit', false ); ?></form></td></tr>
            <?php endforeach; ?></tbody></table>
        </div>
        <?php
    }
}
