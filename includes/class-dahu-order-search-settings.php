<?php
/**
 * The settings screen, and the one option behind it.
 *
 * @package DahuOrderSearch
 */

defined( 'ABSPATH' ) || exit;

/**
 * One option, holding an array: which sources run, which meta keys they
 * look at, and how short a term is allowed to be.
 */
class Dahu_Order_Search_Settings {

	const OPTION     = 'dahu_order_search_settings';
	const GROUP      = 'dahu_order_search';
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Everything that can be switched on or off: the registered sources,
	 * plus the linked-orders expansion.
	 *
	 * The expansion is not a source — it does not look for the term, it
	 * follows links out of what the sources already found — but it is a
	 * thing a shop switches on and off, so it belongs on the same list.
	 *
	 * @return array<string,string> id => label.
	 */
	public static function toggles() {
		$toggles = array();

		foreach ( Dahu_Order_Search_Sources::registered() as $id => $source ) {
			$toggles[ $id ] = $source['label'];
		}

		$toggles['linked_orders'] = __( 'Linked orders — also show orders whose meta points at a found order, and the orders a found order points at (returns, replacements, renewals)', 'dahu-order-search' );

		return $toggles;
	}

	/**
	 * Defaults.
	 *
	 * Every source is on, and every list of meta keys is empty. A plugin
	 * that does nothing until it is configured gets uninstalled before it is
	 * understood — but a plugin that ships somebody else's meta keys as
	 * defaults is guessing at a shop it has never seen. The compromise: the
	 * sources that need no configuration are on, the ones that need keys are
	 * on and idle until keys are given.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'sources'             => array_fill_keys( array_keys( self::toggles() ), 1 ),
			'item_meta_keys'      => '',
			'order_meta_keys'     => '',
			'link_meta_keys'      => '',
			'min_length'          => 4,
			'fix_order_id_filter' => 1,
		);
	}

	/**
	 * The saved settings, merged over the defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$saved    = get_option( self::OPTION, array() );
		$defaults = self::defaults();

		if ( ! is_array( $saved ) ) {
			return $defaults;
		}

		$settings = array_merge( $defaults, $saved );

		// A source registered after the settings were last saved is absent
		// from the stored array. It should run — it was just installed — so
		// the defaults fill in rather than the zeroes.
		$settings['sources'] = isset( $saved['sources'] ) && is_array( $saved['sources'] )
			? array_merge( $defaults['sources'], $saved['sources'] )
			: $defaults['sources'];

		return $settings;
	}

	/**
	 * Is this source — or the expansion — switched on?
	 *
	 * @param string $key Source id.
	 * @return bool
	 */
	public static function source_enabled( $key ) {
		$settings = self::get();

		return ! empty( $settings['sources'][ $key ] );
	}

	/**
	 * A textarea setting read as a list of trimmed, non-empty lines.
	 *
	 * @param string $setting Setting name.
	 * @return string[]
	 */
	public static function lines( $setting ) {
		$settings = self::get();
		$raw      = isset( $settings[ $setting ] ) ? (string) $settings[ $setting ] : '';

		return array_values( array_filter( array_map( 'trim', (array) preg_split( '/\r\n|\r|\n/', $raw ) ), 'strlen' ) );
	}

	/**
	 * How short a term may be before the extra sources run.
	 *
	 * @return int
	 */
	public static function min_length() {
		$settings = self::get();

		return max( 2, min( 20, (int) $settings['min_length'] ) );
	}

	/**
	 * Should the plugin correct WooCommerce's "Order ID" filter?
	 *
	 * @return bool
	 */
	public static function fixes_order_id_filter() {
		$settings = self::get();

		return ! empty( $settings['fix_order_id_filter'] );
	}

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 99 );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * The screen's own stylesheet, on the screen that uses it and nowhere
	 * else.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function enqueue( $hook ) {
		if ( 'woocommerce_page_' . DAHU_ORDER_SEARCH_PAGE !== $hook ) {
			return;
		}

		// The family sheet first, and its own second: the plugin's own rules
		// are written to sit on top of the shared components, so the order is
		// a dependency and not a hope. One handle across the family -- the
		// file is byte-identical everywhere, so whichever plugin loads it
		// first serves all of them, and a shop with six of these installed
		// downloads one copy.
		wp_enqueue_style(
			'dahu-ui',
			DAHU_ORDER_SEARCH_URL . 'assets/css/dahu-ui.css',
			array(),
			DAHU_ORDER_SEARCH_VERSION
		);

		wp_enqueue_style(
			'dahu-order-search-admin',
			DAHU_ORDER_SEARCH_URL . 'assets/css/admin.css',
			array( 'dahu-ui' ),
			DAHU_ORDER_SEARCH_VERSION
		);
	}

	/**
	 * The submenu entry.
	 */
	public static function register_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Order search', 'dahu-order-search' ),
			__( 'Order search', 'dahu-order-search' ),
			self::CAPABILITY,
			DAHU_ORDER_SEARCH_PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Registers the option.
	 */
	public static function register_setting() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Cleans the form before it is stored.
	 *
	 * @param mixed $input Submitted value.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$clean = array( 'sources' => array() );

		foreach ( array_keys( self::toggles() ) as $key ) {
			$clean['sources'][ $key ] = empty( $input['sources'][ $key ] ) ? 0 : 1;
		}

		foreach ( array( 'item_meta_keys', 'order_meta_keys', 'link_meta_keys' ) as $key ) {
			$raw           = isset( $input[ $key ] ) ? (string) $input[ $key ] : '';
			$lines         = array_filter( array_map( 'sanitize_text_field', (array) preg_split( '/\r\n|\r|\n/', $raw ) ), 'strlen' );
			$clean[ $key ] = implode( "\n", $lines );
		}

		$clean['min_length']          = isset( $input['min_length'] ) ? max( 2, min( 20, absint( $input['min_length'] ) ) ) : 4;
		$clean['fix_order_id_filter'] = empty( $input['fix_order_id_filter'] ) ? 0 : 1;

		return $clean;
	}

	/**
	 * The screen.
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'dahu-order-search' ) );
		}

		$settings = self::get();
		$name     = self::OPTION;
		?>
		<div class="wrap dahu-ui">
			<div class="dahu-ui-head">
				<h1 class="dahu-ui-head__title"><?php esc_html_e( 'Order search', 'dahu-order-search' ); ?></h1>
				<p class="dahu-ui-head__sub"><?php esc_html_e( 'Finds an order by what the customer tells you on the telephone.', 'dahu-order-search' ); ?></p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<div class="dahu-ui-card">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Sources', 'dahu-order-search' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Sources', 'dahu-order-search' ); ?></legend>
								<?php foreach ( self::toggles() as $key => $label ) : ?>
									<label class="dahu-order-search__check">
										<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[sources][<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $settings['sources'][ $key ] ) ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="dahu-order-search-order-meta"><?php esc_html_e( 'Order meta keys', 'dahu-order-search' ); ?></label></th>
						<td>
							<textarea id="dahu-order-search-order-meta" name="<?php echo esc_attr( $name ); ?>[order_meta_keys]" rows="4" class="large-text code"><?php echo esc_textarea( $settings['order_meta_keys'] ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'One key per line. A tracking number, an invoice number, a reference from another system — anything stored on the order that somebody might search for.', 'dahu-order-search' ); ?>
								<?php esc_html_e( 'To find the key a value is stored under, open an order and look at its custom fields.', 'dahu-order-search' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="dahu-order-search-item-meta"><?php esc_html_e( 'Order line meta keys', 'dahu-order-search' ); ?></label></th>
						<td>
							<textarea id="dahu-order-search-item-meta" name="<?php echo esc_attr( $name ); ?>[item_meta_keys]" rows="3" class="large-text code"><?php echo esc_textarea( $settings['item_meta_keys'] ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'One key per line. A single asterisk means every visible line meta — the internal ones are always skipped, because they hold bare numbers that would make any number match half the shop.', 'dahu-order-search' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="dahu-order-search-link-meta"><?php esc_html_e( 'Linked order meta keys', 'dahu-order-search' ); ?></label></th>
						<td>
							<textarea id="dahu-order-search-link-meta" name="<?php echo esc_attr( $name ); ?>[link_meta_keys]" rows="3" class="large-text code"><?php echo esc_textarea( $settings['link_meta_keys'] ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'One key per line. Keys whose value is another order\'s number. Both directions are followed, because only one of them is ever stored.', 'dahu-order-search' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="dahu-order-search-min"><?php esc_html_e( 'Shortest search term', 'dahu-order-search' ); ?></label></th>
						<td>
							<input id="dahu-order-search-min" type="number" min="2" max="20" name="<?php echo esc_attr( $name ); ?>[min_length]" value="<?php echo esc_attr( (string) $settings['min_length'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Below this many characters the extra sources do not run, and the native search is left to answer on its own.', 'dahu-order-search' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'WooCommerce correction', 'dahu-order-search' ); ?></th>
						<td>
							<label class="dahu-order-search__check">
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[fix_order_id_filter]" value="1" <?php checked( ! empty( $settings['fix_order_id_filter'] ) ); ?> />
								<?php esc_html_e( 'Return nothing when the "Order ID" filter is used with text that is not a number', 'dahu-order-search' ); ?>
							</label>
							<p class="description dahu-order-search__caution">
								<?php esc_html_e( 'Left alone, WooCommerce shows every order in the shop instead, which reads as a search that stopped working. Switch this off to get WooCommerce\'s own behaviour back.', 'dahu-order-search' ); ?>
							</p>
						</td>
					</tr>
				</table>
				</div>

				<?php submit_button( null, 'dahu-ui-btn dahu-ui-btn--go' ); ?>
			</form>
		</div>
		<?php
	}
}
