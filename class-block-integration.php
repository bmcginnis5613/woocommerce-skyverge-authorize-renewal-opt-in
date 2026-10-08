<?php
defined( 'ABSPATH' ) || exit;
class CER_Block_Integration implements \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface {
    public function get_name() { return 'cer-authorize-net'; }
    public function initialize() {
        $url = plugin_dir_url( __FILE__ ) . 'assets/';
        wp_enqueue_style( 'cer-authnet-opt-in', $url . 'style.css', array(), '1.0.7' );
        wp_register_script( 'cer-authnet-frontend', $url . 'frontend.js', array( 'wp-element', 'wp-data', 'wc-blocks-checkout', 'wc-blocks-data-store' ), '1.0.7', true );
        wp_register_script( 'cer-authnet-editor', $url . 'editor.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor' ), '1.0.7', true );
        $data = array( 'label' => CER_Authorize_Net::LABEL, 'details' => CER_Authorize_Net::details(), 'gateways' => CER_Authorize_Net::GATEWAYS );
        wp_localize_script( 'cer-authnet-frontend', 'cerAuthnet', $data );
        wp_localize_script( 'cer-authnet-editor', 'cerAuthnet', $data );
    }
    public function get_script_handles() { return array( 'cer-authnet-frontend' ); }
    public function get_editor_script_handles() { return array( 'cer-authnet-editor' ); }
    public function get_script_data() { return array(); }
}
