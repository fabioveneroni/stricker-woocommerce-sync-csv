<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SWCS_Admin {
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_swcs_save_settings', array( __CLASS__, 'save_settings' ) );
        add_action( 'admin_post_swcs_download', array( __CLASS__, 'download' ) );
    }

    public static function activate() {
        if ( false === get_option( 'swcs_access_key', false ) ) { add_option( 'swcs_access_key', '' ); }
    }

    public static function menu() {
        add_menu_page( 'Stricker Catalog Sync', 'Stricker Sync', 'manage_woocommerce', 'swcs', array( __CLASS__, 'page' ), 'dashicons-update', 56 );
    }

    public static function save_settings() {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'swcs_save_settings' ) ) { wp_die( 'Acesso negado.' ); }
        update_option( 'swcs_access_key', sanitize_text_field( wp_unslash( $_POST['access_key'] ?? '' ) ) );
        wp_safe_redirect( admin_url( 'admin.php?page=swcs&saved=1' ) ); exit;
    }

    public static function download() {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'swcs_download' ) ) { wp_die( 'Acesso negado.' ); }
        $dataset = sanitize_key( wp_unslash( $_POST['dataset'] ?? '' ) );
        $result = SWCS_API::download_csv( $dataset, 'PT' );
        $args = array( 'page' => 'swcs', 'dataset' => $dataset );
        if ( is_wp_error( $result ) ) { $args['error'] = rawurlencode( $result->get_error_message() ); }
        else { $args['success'] = 1; }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) ); exit;
    }

    public static function page() {
        $datasets = array( 'producttypes' => 'Product Types', 'products' => 'Products', 'optionals' => 'Optionals', 'optionalsPrice' => 'Optionals Price', 'optionalscomplete' => 'Optionals Complete', 'customizationOptions' => 'Customization Options', 'customizationTables' => 'Customization Tables', 'colors' => 'Colors', 'stocks' => 'Stocks' );
        ?>
        <div class="wrap">
            <h1>Stricker WooCommerce Catalog Sync</h1>
            <?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p>Access key salva com sucesso.</p></div><?php endif; ?>
            <?php if ( isset( $_GET['success'] ) ) : ?><div class="notice notice-success"><p>Download de <?php echo esc_html( $_GET['dataset'] ?? '' ); ?> concluído com sucesso.</p></div><?php endif; ?>
            <?php if ( isset( $_GET['error'] ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( wp_unslash( $_GET['error'] ) ); ?></p></div><?php endif; ?>

            <h2>Conexão</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="swcs_save_settings">
                <?php wp_nonce_field( 'swcs_save_settings' ); ?>
                <table class="form-table"><tr><th><label for="swcs-access-key">Access Key</label></th><td><input id="swcs-access-key" name="access_key" type="password" class="regular-text" value="<?php echo esc_attr( SWCS_API::get_access_key() ); ?>" autocomplete="off"><p class="description">A chave é armazenada nas opções do WordPress e usada para construir as URLs oficiais de download CSV.</p></td></tr></table>
                <?php submit_button( 'Salvar Access Key' ); ?>
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
