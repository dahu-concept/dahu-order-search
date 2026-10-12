<?php
/**
 * Uninstall.
 *
 * One option, holding settings. Nothing else: this plugin reads order data
 * and never writes any, so there is nothing of the shop's to take away with
 * it.
 *
 * @package DahuOrderSearch
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( is_multisite() ) {
	$sites = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $sites as $site_id ) {
		switch_to_blog( (int) $site_id );
		delete_option( 'dahu_order_search_settings' );
		restore_current_blog();
	}
} else {
	delete_option( 'dahu_order_search_settings' );
}
