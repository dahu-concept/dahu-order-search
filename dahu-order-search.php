<?php
/**
 * Plugin Name:       Dahu Order Search
 * Plugin URI:        https://dahu-concept.fr
 * Description:       Finds the orders WooCommerce's own search misses: a company name on a B2B order with no customer name, a phone number, an order note, a tracking or invoice number held in order meta, a value on an order line. Results are added to the native search, never substituted for it, and ranked by how well they match. Other plugins can register sources of their own.
 * Version:           1.0.0
 * Author:            Hugo Vial-Jaime
 * Author URI:        mailto:contact@dahu-concept.fr
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       dahu-order-search
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 *
 * @package DahuOrderSearch
 */

defined( 'ABSPATH' ) || exit;

define( 'DAHU_ORDER_SEARCH_VERSION', '1.0.0' );
define( 'DAHU_ORDER_SEARCH_FILE', __FILE__ );
define( 'DAHU_ORDER_SEARCH_PATH', plugin_dir_path( __FILE__ ) );
define( 'DAHU_ORDER_SEARCH_URL', plugin_dir_url( __FILE__ ) );
define( 'DAHU_ORDER_SEARCH_PAGE', 'dahu-order-search' );

/* -------------------------------------------------------------------------
 * Translations
 * ---------------------------------------------------------------------- */

add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'dahu-order-search', false, dirname( plugin_basename( DAHU_ORDER_SEARCH_FILE ) ) . '/languages' );
	}
);

/* -------------------------------------------------------------------------
 * Autoloader
 * ---------------------------------------------------------------------- */

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'Dahu_Order_Search_' ) ) {
			return;
		}

		$file = DAHU_ORDER_SEARCH_PATH . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/* -------------------------------------------------------------------------
 * HPOS
 *
 * Declared outside the WooCommerce gate below, because `before_woocommerce_init`
 * fires before `plugins_loaded` finishes and a declaration made too late is
 * a declaration WooCommerce never sees.
 * ---------------------------------------------------------------------- */

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DAHU_ORDER_SEARCH_FILE, true );
		}
	}
);

/* -------------------------------------------------------------------------
 * Bootstrap
 *
 * Everything this plugin does happens on one WooCommerce admin screen, so
 * everything is gated on WooCommerce being there and on being in the admin.
 * ---------------------------------------------------------------------- */

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}

					printf(
						'<div class="notice notice-warning"><p>%s</p></div>',
						esc_html__( 'Dahu Order Search needs WooCommerce. It is doing nothing until WooCommerce is active.', 'dahu-order-search' )
					);
				}
			);

			return;
		}

		if ( ! is_admin() ) {
			return;
		}

		Dahu_Order_Search_Settings::init();
		Dahu_Order_Search_List_Table::init();
	},
	20
);

/* -------------------------------------------------------------------------
 * Plugins screen: a link to the settings, and nothing else.
 *
 * No version number and no "visit the plugin site" row. The plugins screen
 * belongs to the shop.
 * ---------------------------------------------------------------------- */

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . DAHU_ORDER_SEARCH_PAGE ) ),
				esc_html__( 'Settings', 'dahu-order-search' )
			)
		);

		return $links;
	}
);
