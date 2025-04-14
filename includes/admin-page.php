<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Register admin submenu page under Tools
 */
add_action( 'admin_menu', function() {
    add_submenu_page(
        'tools.php',
        'Vehicle Import Now',
        'Vehicle Import Now',
        'manage_options',
        'vehicle-import-now',
        'vehicle_importer_render_admin_page'
    );
});

/**
 * Render the admin page
 */
function vehicle_importer_render_admin_page() {
    echo '<div class="wrap">';
    echo '<h1>🚗 Vehicle Importer</h1>';
    echo '<div id="vehicle-import-ui"></div>'; // React app mounts here
    echo '</div>';
}
