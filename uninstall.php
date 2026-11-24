<?php
/**
 * Uninstall.
 *
 * One option, holding settings. Nothing else: this plugin reads order data
 * and never writes any, so there is nothing of the shop's to take away with
 * it.
 *
 * @package UbacSearch
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( is_multisite() ) {
	// `number => 0`: without it WP_Site_Query stops at the first hundred
	// sites, and the options stay behind on every other one — an uninstall
	// that did exactly what it was told and cleaned a fraction of the
	// network, with nothing anywhere to say so.
	$ubac_search_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $ubac_search_sites as $ubac_search_site_id ) {
		switch_to_blog( (int) $ubac_search_site_id );
		delete_option( 'ubac_search_settings' );
		delete_option( 'ubac_welcome_seen_ubac-search' );
		// The same keys under the new slug: the key is built at runtime from the
		// slug, so it changed when the slug did. Without these three lines, an
		// update from the Ubac-named version leaves orphans that nothing names
		// any more.
		delete_option( 'ubac_welcome_seen_dahuconcept-order-search' );
		delete_option( 'ubac_welcome_pending_ubac-search' );
		delete_option( 'ubac_welcome_pending_dahuconcept-order-search' );
		delete_option( 'ubac_welcome_tour_ubac-search' );
		delete_option( 'ubac_welcome_tour_dahuconcept-order-search' );
		restore_current_blog();
	}
} else {
	delete_option( 'ubac_search_settings' );
	delete_option( 'ubac_welcome_seen_ubac-search' );
	delete_option( 'ubac_welcome_seen_dahuconcept-order-search' );
	delete_option( 'ubac_welcome_pending_ubac-search' );
	delete_option( 'ubac_welcome_pending_dahuconcept-order-search' );
	delete_option( 'ubac_welcome_tour_ubac-search' );
	delete_option( 'ubac_welcome_tour_dahuconcept-order-search' );
}
