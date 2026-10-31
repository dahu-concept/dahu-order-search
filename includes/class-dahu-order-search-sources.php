<?php
/**
 * Where the extra results come from: four built-in sources, one expansion,
 * and the registry a shop adds its own to.
 *
 * @package DahuOrderSearch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every query here is a prepared SELECT. Nothing in this class writes.
 */
class Dahu_Order_Search_Sources {

	/**
	 * Most orders one source may contribute to a single search.
	 *
	 * A ceiling, not a page size: a term like "a" would otherwise return the
	 * whole order-meta table. Filterable because a shop with a hundred
	 * thousand orders and a fast database may reasonably want more.
	 */
	const MAX_IDS = 1000;

	/**
	 * Which sources hit the ceiling on the last search.
	 *
	 * A truncated answer is worse than a wide one and worse than an empty
	 * one: it looks like a complete answer. The list table turns this into
	 * a notice above the orders screen, because a shop that is told its
	 * search was cut can narrow it, and a shop that is not told cannot.
	 *
	 * @var array<string,bool>
	 */
	private static $truncated = array();

	/**
	 * The sources whose answer was cut short on the last search.
	 *
	 * @return string[] Source labels.
	 */
	public static function truncated() {
		$sources = self::registered();
		$labels  = array();

		foreach ( array_keys( self::$truncated ) as $id ) {
			$labels[] = isset( $sources[ $id ] ) ? $sources[ $id ]['label'] : $id;
		}

		return $labels;
	}

	/**
	 * The sources, each ready to be switched off on the settings screen.
	 *
	 * A source is `array( 'label' => string, 'callback' => callable,
	 * 'rank' => int, 'filter' => string|null )`.
	 *
	 * The callback takes the search term and returns the orders it found, in
	 * any of the three shapes `Dahu_Order_Search_Results::normalise()`
	 * accepts — the simplest being `array( 123 => 'why it matched' )`.
	 *
	 * `filter`, when set, adds an entry to the search dropdown above the
	 * order list. Choosing it runs that source **alone**: no other source,
	 * and no native search either. That is what makes a dedicated filter
	 * worth having — "show me the order carrying this serial number, and
	 * nothing that merely looks like it".
	 *
	 * @return array<string,array> Keyed by source id.
	 */
	public static function registered() {
		$sources = array(
			'addresses'  => array(
				'label'    => __( 'Customer — company, phone, email, name, street, town, postcode, on both the billing and the shipping address', 'dahu-order-search' ),
				'callback' => array( __CLASS__, 'addresses' ),
				'rank'     => Dahu_Order_Search_Results::RANK_ADDRESS,
			),
			'order_meta' => array(
				'label'    => __( 'Order meta — the keys listed below (tracking number, invoice number, external reference…)', 'dahu-order-search' ),
				'callback' => array( __CLASS__, 'order_meta' ),
				'rank'     => Dahu_Order_Search_Results::RANK_META,
			),
			'item_meta'  => array(
				'label'    => __( 'Order line meta — the keys listed below (a serial number typed on a line, an engraving, a size)', 'dahu-order-search' ),
				'callback' => array( __CLASS__, 'item_meta' ),
				'rank'     => Dahu_Order_Search_Results::RANK_META,
			),
			'notes'      => array(
				'label'    => __( 'Order notes', 'dahu-order-search' ),
				'callback' => array( __CLASS__, 'notes' ),
				'rank'     => Dahu_Order_Search_Results::RANK_NOTE,
			),
		);

		/**
		 * Registers a source of order search results.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,array> $sources Keyed by source id.
		 */
		$sources = (array) apply_filters( 'dahu_order_search_sources', $sources );

		return self::valid( $sources );
	}

	/**
	 * Drops entries that cannot be used, rather than failing on them.
	 *
	 * A source registered by another plugin is third-party code running on
	 * an admin screen the shop needs. A missing callback there must cost
	 * that source, and nothing else: a fatal error on the order list because
	 * somebody's plugin was half-updated is a worse outcome than one missing
	 * source, and far harder to attribute.
	 *
	 * @param array $sources Raw registry.
	 * @return array<string,array>
	 */
	private static function valid( array $sources ) {
		$clean = array();

		foreach ( $sources as $id => $source ) {
			if ( ! is_string( $id ) || '' === $id || ! is_array( $source ) ) {
				continue;
			}

			if ( ! isset( $source['callback'] ) || ! is_callable( $source['callback'] ) ) {
				continue;
			}

			$clean[ $id ] = array(
				'label'    => isset( $source['label'] ) && is_string( $source['label'] ) ? $source['label'] : $id,
				'callback' => $source['callback'],
				'rank'     => isset( $source['rank'] ) ? (int) $source['rank'] : Dahu_Order_Search_Results::RANK_META,
				'filter'   => isset( $source['filter'] ) && is_string( $source['filter'] ) && '' !== $source['filter'] ? $source['filter'] : null,
			);
		}

		return $clean;
	}

	/**
	 * Is the term long enough for the extra sources to run?
	 *
	 * Public because the list table has to ask before deciding what to do
	 * with a dedicated filter: a short term there must leave the native
	 * search alone rather than answer "no orders found".
	 *
	 * @param string $term Search term.
	 * @return bool
	 */
	public static function term_is_long_enough( $term ) {
		$term = trim( (string) $term );

		return '' !== $term && mb_strlen( $term ) >= Dahu_Order_Search_Settings::min_length();
	}

	/**
	 * Runs the sources and returns one sorted result map.
	 *
	 * @param string $term Search term, already trimmed by the caller.
	 * @param string $only Run this source alone (a dedicated filter), or ''.
	 * @return array Result map, sorted.
	 */
	public static function collect( $term, $only = '' ) {
		$term  = trim( (string) $term );
		$found = array();

		self::$truncated = array();

		if ( ! self::term_is_long_enough( $term ) ) {
			return $found;
		}

		$sources = self::registered();

		foreach ( $sources as $id => $source ) {
			if ( '' !== $only ) {
				if ( $id !== $only ) {
					continue;
				}
			} elseif ( ! Dahu_Order_Search_Settings::source_enabled( $id ) ) {
				continue;
			}

			$returned = call_user_func( $source['callback'], $term );
			$map      = Dahu_Order_Search_Results::normalise( $returned, $source['rank'], $source['label'] );

			// The ceiling is "most orders ONE source may contribute", so it
			// has to hold for a registered source too. A third-party source
			// whose own query forgot its LIMIT would otherwise hand an
			// unbounded id list straight into `IN ( … )` and
			// `ORDER BY FIELD( … )`, which takes the orders screen down
			// rather than adding noise to it.
			if ( count( $map ) > self::max_ids() ) {
				self::$truncated[ $id ] = true;
				$map                    = array_slice( $map, 0, self::max_ids(), true );
			}

			Dahu_Order_Search_Results::merge( $found, $map );
		}

		if ( $found && Dahu_Order_Search_Settings::source_enabled( 'linked_orders' ) ) {
			Dahu_Order_Search_Results::merge( $found, self::linked_orders( array_keys( $found ) ) );
		}

		return Dahu_Order_Search_Results::sort( $found );
	}

	/* ---------------------------------------------------------------------
	 * The built-in sources
	 * ------------------------------------------------------------------ */

	/**
	 * Customer details, on both stores.
	 *
	 * This is the source that justifies the plugin. WooCommerce's own order
	 * search does find a customer name; what it does not find is a B2B order
	 * whose billing name is empty and whose company carries the whole
	 * identity, and it does not find a phone number. Both are how a shop
	 * actually looks for an order when somebody is on the telephone.
	 *
	 * @param string $term Search term.
	 * @return array Result map.
	 */
	public static function addresses( $term ) {
		$found = array();

		foreach ( self::address_rows( $term ) as $row ) {
			$who = trim( $row['company'] . ' ' . $row['first_name'] . ' ' . $row['last_name'] );

			if ( '' === $who ) {
				$who = (string) $row['phone'];
			}

			$exact = self::is_exact_name( $term, $row['first_name'], $row['last_name'], $row['company'] );

			Dahu_Order_Search_Results::add(
				$found,
				(int) $row['order_id'],
				'shipping' === $row['address_type']
					/* translators: %s: customer or company name. */
					? sprintf( __( 'Shipping address: %s', 'dahu-order-search' ), $who )
					/* translators: %s: customer or company name. */
					: sprintf( __( 'Billing address: %s', 'dahu-order-search' ), $who ),
				$exact ? Dahu_Order_Search_Results::RANK_EXACT : Dahu_Order_Search_Results::RANK_ADDRESS
			);
		}

		return $found;
	}

	/**
	 * The address rows matching a term, whichever store is in use.
	 *
	 * Two queries rather than one, and deliberately. An exact match has to
	 * survive the ceiling: on a term like "Martin" a shop can have more than
	 * MAX_IDS partial matches, and the one row where the company *is*
	 * "Martin" would be cut off with them — the single row the person was
	 * looking for, lost to a limit meant to protect the database. So the
	 * exact matches are fetched by themselves first.
	 *
	 * Neither query uses CONCAT_WS. A full name spanning two columns is
	 * matched by requiring both words instead, which is more precise and
	 * does not depend on a function the database may not have.
	 *
	 * @param string $term Search term.
	 * @return array[] Rows with order_id, address_type, company, first_name, last_name, phone.
	 */
	private static function address_rows( $term ) {
		global $wpdb;

		$term  = trim( (string) preg_replace( '/\s+/', ' ', $term ) );
		$like  = '%' . $wpdb->esc_like( $term ) . '%';
		$words = self::first_and_last_word( $term );
		$limit = self::max_ids();

		if ( self::hpos_enabled() ) {
			$table = $wpdb->prefix . 'wc_order_addresses';
			$cols  = 'order_id, address_type, company, first_name, last_name, phone';

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from $wpdb->prefix, columns are literal.
			$exact = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT {$cols} FROM {$table} WHERE company = %s OR ( first_name = %s AND last_name = %s ) OR ( first_name = %s AND last_name = %s ) LIMIT %d",
					$term,
					$words[0],
					$words[1],
					$words[1],
					$words[0],
					$limit
				),
				ARRAY_A
			);

			$pair = '';

			if ( '' !== $words[1] ) {
				$pair = ' OR ( first_name LIKE %s AND last_name LIKE %s ) OR ( first_name LIKE %s AND last_name LIKE %s )';
			}

			$params = array( $like, $like, $like, $like, $like, $like, $like, $like );

			if ( '' !== $pair ) {
				$w0 = '%' . $wpdb->esc_like( $words[0] ) . '%';
				$w1 = '%' . $wpdb->esc_like( $words[1] ) . '%';
				array_push( $params, $w0, $w1, $w1, $w0 );
			}

			$params[] = $limit;

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $pair holds placeholders only.
			$partial = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT {$cols} FROM {$table}
					WHERE company LIKE %s OR phone LIKE %s OR email LIKE %s
						OR first_name LIKE %s OR last_name LIKE %s
						OR address_1 LIKE %s OR city LIKE %s OR postcode LIKE %s{$pair}
					LIMIT %d",
					$params
				),
				ARRAY_A
			);

			return self::note_truncation( array_merge( (array) $exact, (array) $partial ), $limit, 'addresses' );
		}

		return self::legacy_address_rows( $term, $like, $words, $limit );
	}

	/**
	 * The same thing on the legacy store, where one address is nine rows of
	 * postmeta and a full name spans two of them.
	 *
	 * @param string   $term  Search term.
	 * @param string   $like  Escaped LIKE pattern.
	 * @param string[] $words First and last word of the term.
	 * @param int      $limit Ceiling.
	 * @return array[]
	 */
	private static function legacy_address_rows( $term, $like, array $words, $limit ) {
		global $wpdb;

		$fields = array( 'company', 'phone', 'email', 'first_name', 'last_name', 'address_1', 'city', 'postcode' );
		$keys   = array();

		foreach ( array( 'billing', 'shipping' ) as $type ) {
			foreach ( $fields as $field ) {
				$keys[] = '_' . $type . '_' . $field;
			}
		}

		$rows = array();

		foreach ( self::meta_rows( $keys, $like, $limit, 'addresses' ) as $row ) {
			$type              = 0 === strpos( $row['meta_key'], '_shipping_' ) ? 'shipping' : 'billing';
			$is_company        = '_company' === substr( $row['meta_key'], -8 );
			$rows[]            = array(
				'order_id'     => $row['order_id'],
				'address_type' => $type,
				'company'      => $is_company ? $row['meta_value'] : '',
				'first_name'   => '_first_name' === substr( $row['meta_key'], -11 ) ? $row['meta_value'] : '',
				'last_name'    => '_last_name' === substr( $row['meta_key'], -10 ) ? $row['meta_value'] : '',
				'phone'        => '_phone' === substr( $row['meta_key'], -6 ) ? $row['meta_value'] : '',
			);
		}

		if ( '' === $words[1] ) {
			return $rows;
		}

		// A full name is two postmeta rows, so neither matches "Olivier
		// Trousse" on its own. One join, only when the term has two words.
		$w0 = '%' . $wpdb->esc_like( $words[0] ) . '%';
		$w1 = '%' . $wpdb->esc_like( $words[1] ) . '%';

		$types  = self::order_post_types();
		$typ_ph = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$params = array_merge( array( $w0, $w1, $w1, $w0 ), $types, array( $limit ) );

		// Two joins rather than one: the second is the post-type guard, for
		// the same reason as in meta_rows(). The explicit pairing of the
		// two meta keys replaces a REPLACE() on the joined column, which
		// defeated the meta_key index on every two-word search — and "jean
		// dupont" is the most ordinary search there is.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- tables are fixed, the rest are generated placeholders.
		$joined = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT fn.post_id AS order_id, fn.meta_key, fn.meta_value AS first_name, ln.meta_value AS last_name
				FROM {$wpdb->postmeta} fn
				INNER JOIN {$wpdb->postmeta} ln ON ln.post_id = fn.post_id
					AND ( ( fn.meta_key = '_billing_first_name' AND ln.meta_key = '_billing_last_name' )
						OR ( fn.meta_key = '_shipping_first_name' AND ln.meta_key = '_shipping_last_name' ) )
				INNER JOIN {$wpdb->posts} p ON p.ID = fn.post_id
				WHERE ( ( fn.meta_value LIKE %s AND ln.meta_value LIKE %s ) OR ( fn.meta_value LIKE %s AND ln.meta_value LIKE %s ) )
					AND p.post_type IN ( {$typ_ph} )
				ORDER BY fn.post_id DESC
				LIMIT %d",
				$params
			),
			ARRAY_A
		);

		foreach ( (array) $joined as $row ) {
			$rows[] = array(
				'order_id'     => $row['order_id'],
				'address_type' => 0 === strpos( $row['meta_key'], '_shipping_' ) ? 'shipping' : 'billing',
				'company'      => '',
				'first_name'   => $row['first_name'],
				'last_name'    => $row['last_name'],
				'phone'        => '',
			);
		}

		return $rows;
	}

	/**
	 * Order meta, on the keys the shop listed.
	 *
	 * @param string $term Search term.
	 * @return array Result map.
	 */
	public static function order_meta( $term ) {
		global $wpdb;

		$keys  = Dahu_Order_Search_Settings::lines( 'order_meta_keys' );
		$found = array();

		if ( ! $keys ) {
			return $found;
		}

		foreach ( self::meta_rows( $keys, '%' . $wpdb->esc_like( $term ) . '%', self::max_ids(), 'order_meta' ) as $row ) {
			Dahu_Order_Search_Results::add(
				$found,
				(int) $row['order_id'],
				/* translators: 1: meta key, 2: the matching value. */
				sprintf( __( 'Order meta %1$s: %2$s', 'dahu-order-search' ), $row['meta_key'], self::excerpt( $row['meta_value'], $term ) ),
				Dahu_Order_Search_Results::RANK_META
			);
		}

		return $found;
	}

	/**
	 * Order line meta, on the keys the shop listed.
	 *
	 * @param string $term Search term.
	 * @return array Result map.
	 */
	public static function item_meta( $term ) {
		global $wpdb;

		$keys = Dahu_Order_Search_Settings::lines( 'item_meta_keys' );

		if ( ! $keys ) {
			return array();
		}

		$params = array( '%' . $wpdb->esc_like( $term ) . '%' );

		if ( in_array( '*', $keys, true ) ) {
			// Every visible line meta. The internal ones are excluded because
			// `_qty` and `_product_id` hold bare numbers, and searching those
			// makes any number match half the shop.
			$key_sql  = 'oim.meta_key NOT LIKE %s';
			$params[] = $wpdb->esc_like( '_' ) . '%';
		} else {
			$key_sql = 'oim.meta_key IN ( ' . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ' )';
			$params  = array_merge( $params, $keys );
		}

		$params[] = self::max_ids();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $key_sql holds placeholders or a literal.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT oi.order_id, oim.meta_key, oim.meta_value
				FROM {$wpdb->prefix}woocommerce_order_itemmeta oim
				INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim.order_item_id
				WHERE oim.meta_value LIKE %s AND {$key_sql}
				LIMIT %d",
				$params
			),
			ARRAY_A
		);

		$found = array();

		self::note_truncation( (array) $rows, self::max_ids(), 'item_meta' );

		foreach ( (array) $rows as $row ) {
			Dahu_Order_Search_Results::add(
				$found,
				(int) $row['order_id'],
				/* translators: 1: meta key, 2: the matching value. */
				sprintf( __( 'Order line %1$s: %2$s', 'dahu-order-search' ), $row['meta_key'], self::excerpt( $row['meta_value'], $term ) ),
				Dahu_Order_Search_Results::RANK_META
			);
		}

		return $found;
	}

	/**
	 * Order notes.
	 *
	 * Notes live in the comments table on both stores, so this one query
	 * serves both.
	 *
	 * @param string $term Search term.
	 * @return array Result map.
	 */
	public static function notes( $term ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_post_ID AS order_id, comment_content, comment_date
				FROM {$wpdb->comments}
				WHERE comment_type = 'order_note' AND comment_content LIKE %s
				ORDER BY comment_ID DESC
				LIMIT %d",
				'%' . $wpdb->esc_like( $term ) . '%',
				self::max_ids()
			),
			ARRAY_A
		);

		$found = array();

		self::note_truncation( (array) $rows, self::max_ids(), 'notes' );

		foreach ( (array) $rows as $row ) {
			Dahu_Order_Search_Results::add(
				$found,
				(int) $row['order_id'],
				sprintf(
					/* translators: 1: date, 2: an excerpt of the note. */
					__( 'Note of %1$s: %2$s', 'dahu-order-search' ),
					date_i18n( get_option( 'date_format' ), (int) mysql2date( 'U', $row['comment_date'] ) ),
					self::excerpt( $row['comment_content'], $term )
				),
				Dahu_Order_Search_Results::RANK_NOTE
			);
		}

		return $found;
	}

	/* ---------------------------------------------------------------------
	 * The expansion
	 * ------------------------------------------------------------------ */

	/**
	 * Orders linked to the ones already found, in both directions.
	 *
	 * Shops link orders to each other all the time — a return to its
	 * original order, a replacement to what it replaces, a renewal to the
	 * first subscription order — and the link is almost always one order's
	 * meta holding another order's id. Searching for the original and not
	 * being shown the return is the thing that sends somebody back to the
	 * list a second time.
	 *
	 * Both directions, because only one of them is ever stored.
	 *
	 * @param int[] $order_ids Orders found by the sources.
	 * @return array Result map.
	 */
	public static function linked_orders( array $order_ids ) {
		global $wpdb;

		$keys      = Dahu_Order_Search_Settings::lines( 'link_meta_keys' );
		$order_ids = array_values( array_unique( array_filter( array_map( 'intval', $order_ids ) ) ) );
		$found     = array();

		if ( ! $keys || ! $order_ids ) {
			return $found;
		}

		// A ceiling here too: the sources may have returned MAX_IDS orders,
		// and an IN () list that long is a query nobody wants on an admin
		// screen. The first ones are the best ranked, which is the right
		// half to keep.
		$order_ids = array_slice( $order_ids, 0, 200 );

		list( $table, $id_col ) = self::meta_table();

		$key_ph = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$id_ph  = implode( ', ', array_fill( 0, count( $order_ids ), '%s' ) );
		$params = array_merge( $keys, array_map( 'strval', $order_ids ), array( self::max_ids() ) );

		// Direction 1: another order points at one of ours.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table and column are fixed, the rest are generated placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT {$id_col} AS order_id, meta_value FROM {$table} WHERE meta_key IN ( {$key_ph} ) AND meta_value IN ( {$id_ph} ) LIMIT %d",
				$params
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			Dahu_Order_Search_Results::add(
				$found,
				(int) $row['order_id'],
				/* translators: %d: the order this one is linked to. */
				sprintf( __( 'Linked to order #%d', 'dahu-order-search' ), (int) $row['meta_value'] ),
				Dahu_Order_Search_Results::RANK_LINKED
			);
		}

		// Direction 2: one of ours points at another order.
		$params = array_merge( $keys, array_map( 'strval', $order_ids ), array( self::max_ids() ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table and column are fixed, the rest are generated placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT {$id_col} AS order_id, meta_value FROM {$table} WHERE meta_key IN ( {$key_ph} ) AND {$id_col} IN ( {$id_ph} ) LIMIT %d",
				$params
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$target = (int) $row['meta_value'];

			if ( $target <= 0 ) {
				continue;
			}

			Dahu_Order_Search_Results::add(
				$found,
				$target,
				/* translators: %d: the order that links to this one. */
				sprintf( __( 'Linked from order #%d', 'dahu-order-search' ), (int) $row['order_id'] ),
				Dahu_Order_Search_Results::RANK_LINKED
			);
		}

		return $found;
	}

	/* ---------------------------------------------------------------------
	 * Plumbing
	 * ------------------------------------------------------------------ */

	/**
	 * Rows from the order meta table, whichever store is in use.
	 *
	 * @param string[] $keys  Meta keys.
	 * @param string   $like  Escaped LIKE pattern.
	 * @param int      $limit Ceiling.
	 * @return array[]
	 */
	private static function meta_rows( array $keys, $like, $limit, $source = '' ) {
		global $wpdb;

		if ( ! $keys ) {
			return array();
		}

		$key_ph = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );

		if ( self::hpos_enabled() ) {
			$table  = $wpdb->prefix . 'wc_orders_meta';
			$params = array_merge( $keys, array( $like, $limit ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table and column are fixed, the rest are generated placeholders.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT order_id AS order_id, meta_key, meta_value FROM {$table} WHERE meta_key IN ( {$key_ph} ) AND meta_value LIKE %s ORDER BY order_id DESC LIMIT %d",
					$params
				),
				ARRAY_A
			);

			return self::note_truncation( (array) $rows, $limit, $source );
		}

		// The legacy store, and the join is not decoration.
		//
		// `_billing_company`, `_billing_phone` and the rest are not unique
		// to orders: subscriptions, refunds and any plugin that reuses the
		// keys carry them too. Without the join those rows fill the ceiling,
		// WP_Query then drops them for not being orders, and the orders that
		// were cut off never come back — so a shop with more subscription
		// records than the ceiling stops finding its own orders, and which
		// rows survive is whatever the database happened to return.
		//
		// The ORDER BY is part of the same fix: a LIMIT without one makes
		// the cut arbitrary and unstable between two refreshes of the same
		// search.
		$types  = self::order_post_types();
		$typ_ph = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$params = array_merge( $keys, array( $like ), $types, array( $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- tables are fixed, the rest are generated placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id AS order_id, pm.meta_key, pm.meta_value
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key IN ( {$key_ph} ) AND pm.meta_value LIKE %s AND p.post_type IN ( {$typ_ph} )
				ORDER BY pm.post_id DESC
				LIMIT %d",
				$params
			),
			ARRAY_A
		);

		return self::note_truncation( (array) $rows, $limit, $source );
	}

	/**
	 * The post types that are orders on the legacy store.
	 *
	 * @return string[]
	 */
	private static function order_post_types() {
		/**
		 * Filters the post types treated as orders on the legacy store.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $types Default array( 'shop_order' ).
		 */
		$types = (array) apply_filters( 'dahu_order_search_order_post_types', array( 'shop_order' ) );
		$types = array_values( array_filter( array_map( 'strval', $types ), 'strlen' ) );

		return $types ? $types : array( 'shop_order' );
	}

	/**
	 * Records that a source's answer was cut short, and returns the rows.
	 *
	 * @param array  $rows   Rows returned.
	 * @param int    $limit  The ceiling that was applied.
	 * @param string $source Source id, or '' not to record.
	 * @return array
	 */
	private static function note_truncation( array $rows, $limit, $source ) {
		if ( '' !== $source && count( $rows ) >= (int) $limit ) {
			self::$truncated[ $source ] = true;
		}

		return $rows;
	}

	/**
	 * The order meta table and its id column, for the active store.
	 *
	 * @return array{0:string,1:string}
	 */
	private static function meta_table() {
		global $wpdb;

		return self::hpos_enabled()
			? array( $wpdb->prefix . 'wc_orders_meta', 'order_id' )
			: array( $wpdb->postmeta, 'post_id' );
	}

	/**
	 * Is WooCommerce storing orders in its own tables?
	 *
	 * @return bool
	 */
	public static function hpos_enabled() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * The per-source ceiling.
	 *
	 * @return int
	 */
	public static function max_ids() {
		/**
		 * Filters the greatest number of orders one source may return.
		 *
		 * @since 1.0.0
		 *
		 * @param int $max Default Dahu_Order_Search_Sources::MAX_IDS.
		 */
		return max( 1, (int) apply_filters( 'dahu_order_search_max_ids', self::MAX_IDS ) );
	}

	/**
	 * The first and last word of a term, for matching a name across two
	 * columns. A one-word term gives an empty second element.
	 *
	 * @param string $term Search term.
	 * @return array{0:string,1:string}
	 */
	private static function first_and_last_word( $term ) {
		$words = preg_split( '/\s+/', trim( (string) $term ) );
		$words = array_values( array_filter( (array) $words, 'strlen' ) );

		if ( count( $words ) < 2 ) {
			return array( isset( $words[0] ) ? $words[0] : '', '' );
		}

		return array( $words[0], $words[ count( $words ) - 1 ] );
	}

	/**
	 * Is the term exactly this person's whole name, either way round, or
	 * exactly this company?
	 *
	 * Case, accents and repeated spaces are ignored. A first name on its own
	 * never counts, because "Martin" being somebody's first name is not a
	 * reason to put their order above everything else.
	 *
	 * @param string $term    Search term.
	 * @param string $first   First name.
	 * @param string $last    Last name.
	 * @param string $company Company.
	 * @return bool
	 */
	private static function is_exact_name( $term, $first, $last, $company ) {
		$needle = self::fold( $term );

		if ( '' === $needle ) {
			return false;
		}

		$candidates = array( $company );

		if ( '' !== trim( (string) $first ) && '' !== trim( (string) $last ) ) {
			$candidates[] = $first . ' ' . $last;
			$candidates[] = $last . ' ' . $first;
		}

		foreach ( $candidates as $candidate ) {
			if ( '' !== self::fold( $candidate ) && self::fold( $candidate ) === $needle ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Lowercase, unaccented, single-spaced — for comparing names.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function fold( $text ) {
		$text = remove_accents( wp_strip_all_tags( (string) $text ) );
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );

		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * A short piece of text centred on the term.
	 *
	 * @param string $text Full value.
	 * @param string $term Search term.
	 * @return string
	 */
	private static function excerpt( $text, $term ) {
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );

		if ( mb_strlen( $text ) <= 80 ) {
			return $text;
		}

		$pos   = function_exists( 'mb_stripos' ) ? mb_stripos( $text, $term ) : stripos( $text, $term );
		$start = false === $pos ? 0 : max( 0, $pos - 30 );

		return ( $start > 0 ? '…' : '' ) . mb_substr( $text, $start, 80 ) . '…';
	}
}
