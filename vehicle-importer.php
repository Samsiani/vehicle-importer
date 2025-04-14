<?php
/**
 * Plugin Name: Vehicle Importer
 * Description: VIN search, WooCommerce product import, cron-based updates with offset, logging, and manual trigger. Admin UI in React – სამუშაო ვერსია.
 * Version: 2.2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Include logic files
require_once plugin_dir_path( __FILE__ ) . 'includes/class-vehicle-importer.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/rest-api.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/admin-page.php';

// Register cron interval
add_filter( 'cron_schedules', function( $schedules ) {
    if ( ! isset( $schedules['every_five_minutes'] ) ) {
        $schedules['every_five_minutes'] = [
            'interval' => 300,
            'display'  => __( 'Every 5 Minutes' )
        ];
    }
    return $schedules;
});

// Schedule cron if not scheduled
add_action( 'init', function() {
    if ( ! wp_next_scheduled( 'vehicle_importer_cron_hook' ) ) {
        wp_schedule_event( time(), 'every_five_minutes', 'vehicle_importer_cron_hook' );
        Vehicle_Importer::log('✅ დაგეგმილია vehicle_importer_cron_hook');
    }
});

// Hook the cron job to the batch processor
add_action( 'vehicle_importer_cron_hook', function() {
    if ( get_option( 'vehicle_importer_paused', false ) ) {
        Vehicle_Importer::log('⏸ იმპორტი შეჩერებულია — batch გამოტოვებულია.');
        return;
    }
    Vehicle_Importer::process_batch();
});

// Admin UI assets (React script, styles, localized data)
add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( $hook !== 'tools_page_vehicle-import-now' ) return;

    wp_enqueue_script(
        'vehicle-importer-admin-ui',
        plugins_url( 'admin-ui/app.js', __FILE__ ),
        [ 'wp-element', 'wp-api-fetch' ],
        filemtime( plugin_dir_path( __FILE__ ) . 'admin-ui/app.js' ),
        true
    );

    wp_enqueue_style(
        'vehicle-importer-admin-style',
        plugins_url( 'assets/styles.css', __FILE__ ),
        [],
        filemtime( plugin_dir_path( __FILE__ ) . 'assets/styles.css' )
    );

    wp_localize_script( 'vehicle-importer-admin-ui', 'vehicleImporterData', [
        'nonce'   => wp_create_nonce( 'wp_rest' ),
        'restUrl' => rest_url( 'vehicle-importer/v1/' )
    ]);
});

// Prevent duplicate image download if it already exists in Media Library
add_filter( 'http_request_args', function( $args, $url ) {
    $filename = basename( parse_url( $url, PHP_URL_PATH ) );
    $title = pathinfo( $filename, PATHINFO_FILENAME );

    if ( $title ) {
        global $wpdb;

        $attachment = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
             WHERE post_type = 'attachment' 
             AND post_title = %s 
             AND post_status != 'trash'",
            $title
        ));

        if ( $attachment ) {
            Vehicle_Importer::log("⏭️ დუბლირებული სურათი: {$filename}, გადმოწერა გამოტოვებულია.");
            $args['timeout'] = 0.1;
        }
    }
    return $args;
}, 10, 2);

// REST endpoints
add_action( 'rest_api_init', function () {
    register_rest_route( 'vehicle-importer/v1', '/reset-offset', [
        'methods'  => 'POST',
        'callback' => function() {
            update_option( 'vehicle_importer_offset', 0 );
            Vehicle_Importer::log("🔄 Offset გადაყვანილია 0-ზე მომხმარებლის მოთხოვნით.");
            return rest_ensure_response([ 'success' => true, 'message' => 'Offset გადაყვანილია 0-ზე.' ]);
        },
        'permission_callback' => function() {
            return current_user_can( 'manage_options' );
        }
    ]);

    register_rest_route( 'vehicle-importer/v1', '/batch-size', [
        'methods'  => 'POST',
        'callback' => function( $request ) {
            $size = intval( $request->get_param( 'size' ) );
            if ( ! in_array( $size, [10, 20, 30, 50] ) ) {
                return new WP_Error( 'invalid_size', 'Invalid batch size.', [ 'status' => 400 ] );
            }
            update_option( 'vehicle_importer_batch_size', $size );
            Vehicle_Importer::log("⚙️ Batch size გადაყვანილია: {$size}");
            return rest_ensure_response([ 'success' => true, 'message' => "Batch size გადაყვანილია: {$size}" ]);
        },
        'permission_callback' => function() {
            return current_user_can( 'manage_options' );
        }
    ]);

    register_rest_route( 'vehicle-importer/v1', '/toggle-pause', [
        'methods'  => 'POST',
        'callback' => function() {
            $current = get_option( 'vehicle_importer_paused', false );
            $new = ! $current;
            update_option( 'vehicle_importer_paused', $new );
            Vehicle_Importer::log( $new ? '⏸ იმპორტი შეჩერებულია.' : '▶️ იმპორტი განახლდა.' );
            return rest_ensure_response([ 'paused' => $new ]);
        },
        'permission_callback' => function() {
            return current_user_can( 'manage_options' );
        }
    ]);

    register_rest_route( 'vehicle-importer/v1', '/manual-import', [
        'methods'  => 'POST',
        'callback' => function( $request ) {
            $vin = sanitize_text_field( $request->get_param( 'vin' ) );
            if ( ! $vin ) return new WP_Error( 'no_vin', 'VIN not provided.', [ 'status' => 400 ] );

            Vehicle_Importer::log("📥 Manual VIN Import Requested: {$vin}");
            $vehicle = Vehicle_Importer::fetch_vehicle_by_vin( $vin );

            if ( empty( $vehicle ) ) {
                Vehicle_Importer::log("❌ VIN არ მოიძებნა: {$vin}");
                return new WP_Error( 'not_found', 'Vehicle not found.', [ 'status' => 404 ] );
            }

            Vehicle_Importer::import_vehicle( $vehicle );
            return rest_ensure_response([ 'success' => true, 'message' => "✅ იმპორტირებულია VIN: {$vin}" ]);
        },
        'permission_callback' => function() {
            return current_user_can( 'manage_options' );
        }
    ]);
});
