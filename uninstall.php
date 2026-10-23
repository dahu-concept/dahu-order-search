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
	// `number => 0`: without it WP_Site_Query stops at the first hundred
	// sites, and the options stay behind on every other one — an uninstall
	// that did exactly what it was told and cleaned a fraction of the
	// network, with nothing anywhere to say so.
	$sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $sites as $site_id ) {
		switch_to_blog( (int) $site_id );
		delete_option( 'dahu_order_search_settings' );
		delete_option( 'dahu_welcome_seen_dahu-order-search' );
		delete_option( 'dahu_welcome_pending_dahu-order-search' );
		delete_option( 'dahu_welcome_tour_dahu-order-search' );
		restore_current_blog();
	}
} else {
	delete_option( 'dahu_order_search_settings' );
	delete_option( 'dahu_welcome_seen_dahu-order-search' );
	delete_option( 'dahu_welcome_pending_dahu-order-search' );
	delete_option( 'dahu_welcome_tour_dahu-order-search' );
}
