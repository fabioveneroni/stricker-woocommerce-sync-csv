<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SWCS_Import_Action {
    public static function init() {
        add_action( 'admin_post_swcs_import_product', array( __CLASS__, 'import_product' ) );
        add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
    }

    public static function import_product() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'swcs_import_product' ) ) wp_die( 'Acesso negado.' );
        $reference = sanitize_text_field( wp_unslash( $_POST['product_ref'] ?? '' ) );
        if ( '' === $reference ) wp_die( 'Referência do produto não informada.' );
        $result = SWCS_Importer::import_product( $reference );
        $args = array( 'page' => 'swcs', 'product_ref' => $reference );
        if ( is_wp_error( $result ) ) $args['import_error'] = rawurlencode( $result->get_error_message() );
        else { $args['imported'] = 1; $args['wc_product_id'] = (int) $result['product_id']; $args['wc_type'] = $result['type']; }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function notice() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) || 'swcs' !== ( $_GET['page'] ?? '' ) ) return;
        if ( ! empty( $_GET['import_error'] ) ) {
            echo '<div class="notice notice-error"><p><strong>Importação:</strong> ' . esc_html( wp_unslash( $_GET['import_error'] ) ) . '</p></div>';
        }
        if ( ! empty( $_GET['imported'] ) ) {
            $id = absint( $_GET['wc_product_id'] ?? 0 );
            $type = sanitize_key( $_GET['wc_type'] ?? '' );
            $url = $id ? get_edit_post_link( $id, '' ) : '';
            echo '<div class="notice notice-success"><p><strong>Produto importado com sucesso.</strong> Tipo WooCommerce: ' . esc_html( $type ) . '. Status inicial: rascunho.';
            if ( $url ) echo ' <a href="' . esc_url( $url ) . '">Editar produto</a>';
            echo '</p></div>';
        }
        if ( empty( $_GET['product_ref'] ) ) return;
        $reference = sanitize_text_field( wp_unslash( $_GET['product_ref'] ) );
        $action = esc_url( admin_url( 'admin-post.php' ) );
        echo '<div class="notice notice-info" style="padding-bottom:10px"><p><strong>Importação controlada</strong></p><p>O próximo passo cria/atualiza somente este produto no WooCommerce, usando os CSVs locais. O produto será criado como rascunho para conferência.</p><form method="post" action="' . $action . '">';
        wp_nonce_field( 'swcs_import_product' );
        echo '<input type="hidden" name="action" value="swcs_import_product"><input type="hidden" name="product_ref" value="' . esc_attr( $reference ) . '">';
        submit_button( 'Importar este produto como rascunho', 'primary', 'submit', false );
        echo '</form></div>';
    }
}
SWCS_Import_Action::init();
