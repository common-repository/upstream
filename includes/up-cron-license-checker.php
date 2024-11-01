<?php
/**
 * Cron License Checker.
 * These functions will add wp-cron to check the license status for each installed addons.
 * If the license is expired, then the addon will be disabled and there will be an admin notification about it.
 * 
 * @since  2.0.9
 */

define( 'UPSTREAM_LICENSE_CHECKER_EVENT_NAME', 'upstream_license_checker' );

/**
 * Check for any invalid add-ons license on WordPress init. If we found any, then: 
 * - Define UPSTREAM_HAS_INVALID_LICENSE constant to use across the plugin's code
 * - Disable the main functionality of the plugin by disable the access to the post type `project`, `client`, and `upst_milestone`
 * 
 * @since 2.0.9
 */
function upstream_check_invalid_addons_license_on_init(){
	$upstream_installed_addons = upstream_get_installed_addons();

	foreach ( $upstream_installed_addons as $installed_addon ) {
		$license_checker = new \Upstream\License_Checker( $installed_addon['edd_id'], $installed_addon['slug'] );
		$invalid_message = $license_checker->get_invalid_license_message();

		if ( $invalid_message ) {
			define( 'UPSTREAM_HAS_INVALID_LICENSE', true );			
			break;
		}
	}		
}
add_action( 'after_setup_theme', 'upstream_check_invalid_addons_license_on_init' );

/**
 * Disable the main functionality of the plugin by disable the access to the post type `project`, `client`, and `upst_milestone`
 * 
 * @since  2.0.9
 * @param  Array  $args      The register_post_type arguments to change.
 * @param  String $post_type The post typpe of the register_post_type.
 * @return Array             Updated register_post_type arguments.
 */
function upstream_disable_access_to_upstream_post_types( $args, $post_type ) {
	if ( ! defined( 'UPSTREAM_HAS_INVALID_LICENSE' ) ) {
		return $args;
	}
	
	if ( ! in_array( $post_type, array( 'project', 'client', 'upst_milestone' ) ) ) {
		return $args;
	}
	
	$args['public']             = false;
	$args['show_ui']            = false;
	$args['show_in_menu']       = false;
	$args['publicly_queryable'] = false;
	$args['query_var']          = false;
	$args['show_in_rest']       = false;

	return $args;
}
add_filter( 'register_post_type_args', 'upstream_disable_access_to_upstream_post_types', 10, 2 );

/**
 * Notice user about any invalid addon licenses
 * 
 * @since 2.0.9
 */
function upstream_invalid_license_notification() {
	$upstream_installed_addons = upstream_get_installed_addons();
	
	if ( empty( $upstream_installed_addons ) ) return;

	foreach ( $upstream_installed_addons as $installed_addon ) {
		$license_checker = new \Upstream\License_Checker( $installed_addon['edd_id'], $installed_addon['slug'] );
		$invalid_message = $license_checker->get_invalid_license_message();

		if ( $invalid_message ) {
			?>

			<div class="notice notice-warning">
				<p><?php echo wp_kses_post( $invalid_message ); ?></p>
			</div>
			
			<?php
		}
	}

	// Notice user that we must deactivate the plugin because there are invalid addons license.
	if ( defined( 'UPSTREAM_HAS_INVALID_LICENSE' ) ) {
		?>

		<div class="notice notice-error">
			<p><?php echo esc_html( 'The UpStream plugin has been disabled because of the add-on invalid license. Please validate the license of your add-ons or deactivate your affected add-ons in order to enable the UpStream plugin.', 'upstream' ); ?></p>
		</div>
		
		<?php
	}
}
add_action( 'admin_notices', 'upstream_invalid_license_notification' );	

/**
 * Register the license checker cron on plugin loaded.
 *
 * @since 2.0.9
 */
function upstream_register_license_checker_scheduled_event() {
	$event_name = UPSTREAM_LICENSE_CHECKER_EVENT_NAME;
	$next_event = wp_get_scheduled_event( $event_name );

	// register the cron event
    if ( ! $next_event ) {
		wp_schedule_event( time(), 'daily', $event_name );
    }
}
add_action( 'upstream_run', 'upstream_register_license_checker_scheduled_event' );

/**
 * Unregister the cron on plugin deactivation.
 * 
 * @since 2.0.9
 */
function upstream_unregister_license_checker_scheduled_event() {
    wp_clear_scheduled_hook( UPSTREAM_LICENSE_CHECKER_EVENT_NAME );
}
register_deactivation_hook( UPSTREAM_PLUGIN_FILE, 'upstream_unregister_license_checker_scheduled_event' );

/**
 * Check an Upstream addon license.
 * This function will check one plugin at a time.
 * 
 * @since 2.0.9
 */
function upstream_license_checker_function() {
	$upstream_installed_addons = upstream_get_installed_addons();

	if ( empty( $upstream_installed_addons ) ) return;

	foreach ( $upstream_installed_addons as $installed_addon ) {
		$license_checker = new \Upstream\License_Checker( $installed_addon['edd_id'], $installed_addon['slug'] );
		
		/**
		 * Check whether the addon's turn to check or not.
		 * So this will be one plugin check at a time.
		 */
		if ( $license_checker->is_turn_to_check() ) {
			$license_checker->license_check();
			break;
		}
	}
}
add_action( UPSTREAM_LICENSE_CHECKER_EVENT_NAME, 'upstream_license_checker_function', 10 );

/**
 * Get all installed (activated) Upstream addons.
 * 
 * @since 2.0.9
 */
function upstream_get_installed_addons() {
	$upstream_options_extensions = new UpStream_Options_Extensions( true );
	$upstream_all_addons         = $upstream_options_extensions->filter_allex_addons( array(), 'upstream' );
	$upstream_installed_addons   = array();
	$wordpress_installed_plugins = array_map( 'upstream_array_map_installed_plugin_slug', get_option( 'active_plugins', array() ) );
	
	foreach ( $upstream_all_addons as $upstream_addon ) {
		$slug = isset( $upstream_addon['slug'] ) ? $upstream_addon['slug'] : false;

		if ( in_array( $slug, $wordpress_installed_plugins ) ) {
			array_push( $upstream_installed_addons, $upstream_addon );
		}
	}

	return $upstream_installed_addons;
}

/**
 * Map the installed plugins array from file path to become plugin slug.
 * 
 * @since  2.0.9
 * @param  String $var Plugin file path.
 * @return String Plugin slug.
 */
function upstream_array_map_installed_plugin_slug( $var ) {
	$exp = explode( '/', $var );

	if ( isset( $exp[1] ) ) {
		return rtrim( $exp[1], '.php' );
	}

	return $var;
}

