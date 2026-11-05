<?php
/**
 * Plugin Name:       Ubac Search
 * Description:       Finds the orders WooCommerce's own search misses: a company name on a B2B order with no customer name, a phone number, an order note, a tracking or invoice number held in order meta, a value on an order line. Results are added to the native search, never substituted for it, and ranked by how well they match. Other plugins can register sources of their own.
 * Version:           1.1.0
 * Author:            Hugo Vial-Jaime
 * Author URI:        https://dahu-concept.fr
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       ubac-search
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   11.2
 *
 * @package DahuOrderSearch
 */

defined( 'ABSPATH' ) || exit;

define( 'DAHU_ORDER_SEARCH_VERSION', '1.1.0' );
define( 'DAHU_ORDER_SEARCH_FILE', __FILE__ );
define( 'DAHU_ORDER_SEARCH_PATH', plugin_dir_path( __FILE__ ) );
define( 'DAHU_ORDER_SEARCH_URL', plugin_dir_url( __FILE__ ) );
define( 'DAHU_ORDER_SEARCH_PAGE', 'dahu-order-search' );

/* -------------------------------------------------------------------------
 * Translations
 *
 * There is deliberately no load_plugin_textdomain() call here. Since
 * WordPress 4.6 the translations of a plugin hosted on WordPress.org are
 * loaded on demand, and the directory's review asks that the call be removed
 * rather than kept: this plugin requires 6.0, so there is no older WordPress
 * to cover. Translations come from translate.wordpress.org.
 * ---------------------------------------------------------------------- */

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
						esc_html__( 'Dahu Order Search needs WooCommerce. It is doing nothing until WooCommerce is active.', 'ubac-search' )
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
				esc_html__( 'Settings', 'ubac-search' )
			)
		);

		return $links;
	}
);

/*
 * The welcome screen, shown once just after activation.
 *
 * Registered here rather than inside the admin include, because the
 * activation flag has to be set whatever else is loaded.
 */
require_once DAHU_ORDER_SEARCH_PATH . 'includes/dahu-welcome.php';

/*
 * The flag, not a redirect: an activation hook that redirects breaks bulk
 * activation and WP-CLI, which activate several plugins in one request. The
 * welcome screen reads the flag on the next page load, where a redirect is
 * safe, and clears it when several plugins were activated together.
 */
register_activation_hook(
	__FILE__,
	static function () {
		Dahu_Welcome::mark_activated( 'dahu-order-search' );
	}
);

add_action(
	'init',
	static function () {
		Dahu_Welcome::register(
			array(
				'slug'       => 'dahu-order-search',
				'name'       => __( 'Order search', 'ubac-search' ),
				'tagline'    => __( 'Finds an order by what the customer tells you on the telephone.', 'ubac-search' ),
				'file'       => DAHU_ORDER_SEARCH_FILE,
				'version'    => DAHU_ORDER_SEARCH_VERSION,
				'textdomain' => 'dahu-order-search',
				'mark'       => array(
					'view'  => '0 0 112 112',
					'paths' => array(
						'M12.0 48.0 a38.0 38.0 0 1 0 76.0 0 a38.0 38.0 0 1 0 -76.0 0',
						'M78 76 L106 104',
						'M34 36 L66 36 M34 50 L60 50',
						'M34 64 L50 64',
					),
				),
				/*
				 * The welcome screen's words come from here: the shared file is
				 * copied byte for byte across the whole family, so it can carry
				 * no text domain of its own.
				 */
				'strings'    => array(
					'next'   => __( 'Next', 'ubac-search' ),
					'done'   => __( 'Done', 'ubac-search' ),
					'skip'   => __( 'Skip', 'ubac-search' ),
					'of'     => __( 'of', 'ubac-search' ),
					'denied' => __( 'You are not allowed to view this page.', 'ubac-search' ),
				),
				'points'     => array(
				array(
					'title' => __( 'Any detail', 'ubac-search' ),
					'text'  => __( 'A surname, part of an address, a telephone number, a serial.', 'ubac-search' ),
				),
				array(
					'title' => __( 'Both stores', 'ubac-search' ),
					'text'  => __( 'Works whichever order storage the shop is on.', 'ubac-search' ),
				),
				array(
					'title' => __( 'Fast where it matters', 'ubac-search' ),
					'text'  => __( 'Built for somebody with a customer waiting.', 'ubac-search' ),
				),
				),

				/*
				 * The one-time tour. Every selector points at something the
				 * screen always renders: a step whose target is missing is
				 * skipped without a sound. The source checkboxes sit in a loop
				 * over registered sources, so the card holding them is used.
				 */
				'tour_screen' => 'woocommerce_page_dahu-order-search',
				'tour'        => array(
					array(
						'selector' => '.dahu-ui-card',
						'title'    => __( 'Added, never replaced', 'ubac-search' ),
						'text'     => __( 'Extra sources add to the native search results.', 'ubac-search' ),
					),
					array(
						'selector' => '#dahu-order-search-order-meta',
						'title'    => __( 'Add your own keys', 'ubac-search' ),
						'text'     => __( 'One order meta key per line, such as a tracking number.', 'ubac-search' ),
					),
					array(
						'selector' => '#dahu-order-search-min',
						'title'    => __( 'Shortest term', 'ubac-search' ),
						'text'     => __( 'Below this length, only the native search answers.', 'ubac-search' ),
					),
				),
			)
		);
	}
);
