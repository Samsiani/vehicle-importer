<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Vehicle_Importer {
    private static $token = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpZCI6MTAzNywiZnVsbG5hbWUiOiJJcmFrbGkgU2hlbGlhIn0.JU9mqmFUS3CZoUNJj9QjmUMQ061kP0vEzYn8luqu32g';

    public static function fetch_from_api( $url ) {
        $response = wp_remote_get( $url, [
            'headers' => [ 'Authorization' => 'Bearer ' . self::$token ]
        ]);

        if ( is_wp_error( $response ) ) return null;
        if ( wp_remote_retrieve_response_code( $response ) !== 200 ) return null;

        return json_decode( wp_remote_retrieve_body( $response ), true );
    }

    public static function fetch_vehicle_by_vin( $vin ) {
        $page = 1;
        while ( true ) {
            $url = "https://api.pglsystem.com/api/customer/api_integration/vehicles?page={$page}";
            self::log("🌐 Fetching page {$page} to find VIN: {$vin}");
            $response = self::fetch_from_api( $url );

            if ( empty( $response['data'] ) ) {
                self::log("❌ VIN არ მოიძებნა: {$vin} — გვერდი ცარიელია.");
                return null;
            }

            foreach ( $response['data'] as $vehicle ) {
                if ( $vehicle['vin'] === $vin ) {
                    self::log("✅ VIN აღმოჩენილია გვერდზე {$page}: {$vin}");
                    return $vehicle;
                }
            }

            if ( count( $response['data'] ) < 10 ) {
                self::log("❌ VIN არ მოიძებნა: {$vin} — ბოლო გვერდია.");
                return null;
            }

            $page++;
        }
    }

    public static function log( $message ) {
        $upload_dir = wp_upload_dir();
        $log_file = trailingslashit( $upload_dir['basedir'] ) . 'vehicle-import-log.txt';
        $datetime = current_time( 'mysql' );
        file_put_contents( $log_file, "[{$datetime}] {$message}\n", FILE_APPEND );
    }

    public static function get_last_log_lines( $lines = 10 ) {
        $upload_dir = wp_upload_dir();
        $log_file = trailingslashit( $upload_dir['basedir'] ) . 'vehicle-import-log.txt';
        if ( ! file_exists( $log_file ) ) return [];
        return array_slice( file( $log_file ), -$lines );
    }

    public static function process_batch() {
        $offset = intval( get_option( 'vehicle_importer_offset', 0 ) );
        $limit = intval( get_option( 'vehicle_importer_batch_size', 10 ) );
        $page = floor( $offset / $limit ) + 1;

        $url = "https://api.pglsystem.com/api/customer/api_integration/vehicles?page={$page}";
        $response = self::fetch_from_api( $url );

        if ( empty( $response['data'] ) ) {
            update_option( 'vehicle_importer_offset', 0 );
            self::log('❌ No vehicles fetched. Offset reset.');
            return;
        }

        foreach ( array_slice( $response['data'], 0, $limit ) as $vehicle ) {
            self::import_vehicle( $vehicle );
            $offset++;
        }

        update_option( 'vehicle_importer_offset', $offset );
    }

    public static function import_vehicle( $vehicle ) {
        $vin = sanitize_text_field( $vehicle['vin'] ?? '' );
        if ( ! $vin ) return;

        self::log("🧾 VEHICLE KEYS: " . implode(', ', array_keys($vehicle)));

        $existing = new WP_Query([
            'post_type' => 'product',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [[ 'key' => '_sku', 'value' => $vin ]]
        ]);

        if ( $existing->have_posts() ) {
            self::log("🔁 Already exists: {$vin}");
            return;
        }

        $post_id = wp_insert_post([
            'post_title'  => $vehicle['year'] . ' ' . $vehicle['make'] . ' ' . $vehicle['model'],
            'post_type'   => 'product',
            'post_status' => 'publish'
        ]);

        if ( is_wp_error( $post_id ) ) return;

        wp_set_post_terms( $post_id, [ get_option( 'default_product_cat', get_term_by( 'slug', 'uncategorized', 'product_cat' )->term_id ) ], 'product_cat' );

        $product = wc_get_product( $post_id );
        if ( $product ) {
            $product->set_sku( $vin );
            $product->set_regular_price( '0' );
            $product->save();
        }

        $map = [
            'make' => 'Make',
            'model' => 'Model',
            'year' => 'Year',
            'color' => 'Color',
            'lot_number' => 'Lot Number',
            'is_key_present' => 'Key',
            'date_of_pickup' => 'Pickup Date',
            'deliver_date' => 'Delivery Date',
            'container_number' => 'Container Number',
            'loading_date' => 'Loading Date',
            'booking_number' => 'Booking Number',
            'departure_date' => 'Departure Date',
            'arival_date' => 'Arrival Date'
        ];

        $attributes = [];

        foreach ( $map as $key => $label ) {
            if ( ! empty( $vehicle[$key] ) ) {
                $value = $key === 'is_key_present' ? ( $vehicle[$key] ? 'Yes' : 'No' ) : $vehicle[$key];
                $attributes[ sanitize_title( $label ) ] = [
                    'name'         => wc_clean( $label ),
                    'value'        => wc_clean( $value ),
                    'position'     => 0,
                    'is_visible'   => 1,
                    'is_variation' => 0,
                    'is_taxonomy'  => 0
                ];
            }
        }

        // ✅ TRACKING LINK as post_meta AND optional attribute
        $tracking_link = !empty($vehicle['tracking_link']) ? $vehicle['tracking_link'] : (!empty($vehicle['trackingLink']) ? $vehicle['trackingLink'] : null);
        $shipline_name = $vehicle['shipline_name'] ?? $vehicle['shiplineName'] ?? 'Link';

        if ( $tracking_link ) {
            update_post_meta( $post_id, '_tracking_link_url', esc_url_raw( $tracking_link ) );
            update_post_meta( $post_id, '_tracking_link_label', sanitize_text_field( $shipline_name ) );

            $html = '<a href="' . esc_url( $tracking_link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $shipline_name ) . '</a>';
            $attributes['tracking-link'] = [
                'name'         => 'Tracking Link',
                'value'        => $html,
                'position'     => 99,
                'is_visible'   => 1,
                'is_variation' => 0,
                'is_taxonomy'  => 0
            ];
        }

        update_post_meta( $post_id, '_product_attributes', $attributes );
        self::log("🔍 FINAL ATTRIBUTES: " . print_r($attributes, true));

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $images = self::get_vehicle_images( $vehicle['id'] );
        $gallery_ids = [];

        foreach ( $images as $index => $img_url ) {
            $attachment_id = media_sideload_image( $img_url, $post_id, null, 'id' );
            if ( ! is_wp_error( $attachment_id ) ) {
                if ( $index === 0 ) {
                    set_post_thumbnail( $post_id, $attachment_id );
                } else {
                    $gallery_ids[] = $attachment_id;
                }
            }
        }

        if ( ! empty( $gallery_ids ) ) {
            update_post_meta( $post_id, '_product_image_gallery', implode(',', $gallery_ids) );
        }

        self::log("✅ Created new: {$vin}");
    }

    public static function get_vehicle_images( $vehicle_id ) {
        $url = "https://api.pglsystem.com/api/customer/api_integration/vehicles/images/{$vehicle_id}";
        $response = wp_remote_get( $url, [
            'headers' => [ 'Authorization' => 'Bearer ' . self::$token ]
        ]);

        if ( is_wp_error( $response ) ) return [];
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $images = [];

        if ( ! empty( $data['images'] ) ) {
            foreach ( $data['images'] as $img ) {
                if ( isset( $img['url'] ) ) {
                    $images[] = esc_url_raw( $img['url'] );
                }
            }
        }
        return $images;
    }
}
