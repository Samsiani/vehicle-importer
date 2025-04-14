<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {

    // 🚀 Manual Trigger
    register_rest_route( 'vehicle-importer/v1', '/run-now', [
        'methods'  => 'POST',
        'callback' => 'vehicle_importer_run_now',
        'permission_callback' => function () {
            return current_user_can( 'manage_options' );
        }
    ]);

    // 📊 Status
    register_rest_route( 'vehicle-importer/v1', '/status', [
        'methods'  => 'GET',
        'callback' => 'vehicle_importer_get_status',
        'permission_callback' => function () {
            return current_user_can( 'manage_options' );
        }
    ]);

    // 📜 Logs
    register_rest_route( 'vehicle-importer/v1', '/logs', [
        'methods'  => 'GET',
        'callback' => 'vehicle_importer_get_logs',
        'permission_callback' => function () {
            return current_user_can( 'manage_options' );
        }
    ]);

    // 🔍 Manual VIN Import
    register_rest_route( 'vehicle-importer/v1', '/manual-import', [
        'methods'  => 'POST',
        'callback' => 'vehicle_importer_manual_import',
        'permission_callback' => function () {
            return current_user_can( 'manage_options' );
        }
    ]);

    // 📦 All Vehicles for Table
    register_rest_route( 'vehicle-importer/v1', '/all-vehicles', [
        'methods'  => 'GET',
        'callback' => 'vehicle_importer_all_vehicles',
        'permission_callback' => function () {
            return current_user_can( 'manage_options' );
        }
    ]);

});

// 🔁 Run Now
function vehicle_importer_run_now( WP_REST_Request $request ) {
    Vehicle_Importer::process_batch();
    return rest_ensure_response([
        'success' => true,
        'message' => '✅ Vehicle import batch processed successfully.'
    ]);
}

// 📊 Status
function vehicle_importer_get_status() {
    return rest_ensure_response([
        'next_run'    => wp_next_scheduled( 'vehicle_importer_cron_hook' ),
        'offset'      => get_option( 'vehicle_importer_offset', 0 ),
        'paused'      => get_option( 'vehicle_importer_paused', false ),
        'batch_size'  => get_option( 'vehicle_importer_batch_size', 10 )
    ]);
}

// 📜 Last Logs
function vehicle_importer_get_logs() {
    return rest_ensure_response([
        'logs' => Vehicle_Importer::get_last_log_lines( 20 )
    ]);
}

// ✍️ Manual VIN Import
function vehicle_importer_manual_import( WP_REST_Request $request ) {
    $vin = sanitize_text_field( $request->get_param( 'vin' ) );
    if ( ! $vin ) {
        return new WP_Error( 'no_vin', 'VIN not provided.', [ 'status' => 400 ] );
    }

    $vehicle = Vehicle_Importer::fetch_vehicle_by_vin( $vin );

    if ( empty( $vehicle ) || empty( $vehicle['vin'] ) ) {
        Vehicle_Importer::log("❌ VIN არ მოიძებნა: {$vin}");
        return new WP_Error( 'not_found', 'Vehicle not found or invalid response.', [ 'status' => 404 ] );
    }

    Vehicle_Importer::import_vehicle( $vehicle );
    return rest_ensure_response([
        'success' => true,
        'message' => "✅ იმპორტირებულია VIN: {$vin}"
    ]);
}

// 📋 Get All Vehicles for Searchable Table
function vehicle_importer_all_vehicles() {
    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids'
    ];

    $posts = get_posts( $args );
    $vehicles = [];

    foreach ( $posts as $post_id ) {
        $vin = get_post_meta( $post_id, '_sku', true );
        if ( ! $vin ) continue;

        $vehicles[] = [
            'vin'       => $vin,
            'make'      => get_post_meta( $post_id, 'Make', true ),
            'model'     => get_post_meta( $post_id, 'Model', true ),
            'year'      => get_post_meta( $post_id, 'Year', true ),
            'color'     => get_post_meta( $post_id, 'Color', true ),
            'permalink' => get_permalink( $post_id )
        ];
    }

    return rest_ensure_response( $vehicles );
}
