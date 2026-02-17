<?php
/**
 * Plugin Name: FluentCart → sevDesk Sync
 * Description: Erstellt bei FluentCart-Bestellanlage eine Rechnung in sevDesk.
 * Version: 0.1.0
 * Author: Codex
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Pfade
define( 'FC_SEVDESK_PATH', plugin_dir_path( __FILE__ ) );
define( 'FC_SEVDESK_URL', plugin_dir_url( __FILE__ ) );

// Autoload vom SDK, falls vorhanden
$autoload = FC_SEVDESK_PATH . 'vendor/autoload.php';
if ( file_exists( $autoload ) ) {
    require_once $autoload;
}

// Simple PSR-4 Fallback für unsere Klassen
spl_autoload_register( function ( $class ) {
    $prefix = 'FcSevdesk\\';
    $base_dir = FC_SEVDESK_PATH . 'inc/';
    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }
    $relative_class = substr( $class, $len );
    $file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';
    if ( file_exists( $file ) ) {
        require $file;
    }
} );

// Settings: API-Key aus Option oder env
function fc_sevdesk_get_api_key() {
    $key = getenv( 'SEVDESK_API_KEY' );
    if ( ! $key ) {
        $key = get_option( 'sevdesk_api_key', '' );
    }
    return $key;
}

// Admin-Hinweis, falls SDK fehlt
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( ! file_exists( FC_SEVDESK_PATH . 'vendor/autoload.php' ) ) {
        echo '<div class="notice notice-error"><p><strong>FluentCart → sevDesk:</strong> Bitte SDK installieren: <code>composer require j-mastr/sevdesk-php-sdk guzzlehttp/guzzle</code></p></div>';
    }
} );

// Hook: Order angelegt
add_action( 'fluent_cart/order_created', function ( $payload ) {
    $apiKey = fc_sevdesk_get_api_key();
    if ( ! $apiKey ) {
        return;
    }
    $order = is_array( $payload ) ? ( $payload['order'] ?? null ) : ( $payload->order ?? null );
    if ( ! $order || ! class_exists( '\\Itsmind\\Sevdesk\\Api\\InvoiceApi' ) ) {
        return;
    }

    try {
        $sync = new \FcSevdesk\Sync( $apiKey );
        $sync->pushOrder( $order );
    } catch ( \Throwable $e ) {
        // Log als Order-Notiz
        if ( method_exists( $order, 'addNote' ) ) {
            $order->addNote( 'sevDesk Sync Fehler: ' . $e->getMessage() );
        }
    }
}, 20, 1 );

// Optional: Settings-Link in Plugins-Liste
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
    $settings_url = admin_url( 'options-general.php#sevdesk-api-key' );
    $links[] = '<a href="' . esc_url( $settings_url ) . '">Settings</a>';
    return $links;
} );
