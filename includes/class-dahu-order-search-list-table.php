<?php
/**
 * Where the plugin meets the order list, on both order stores.
 *
 * @package DahuOrderSearch
 */

defined( 'ABSPATH' ) || exit;

/**
 * The rule this class exists to keep: results are **added** to WooCommerce's
 * own, never substituted for them. A shop has learned where its orders turn
 * up in the native search; this plugin puts things in front of that when it
 * has something better and behind it otherwise.
 *
 * The one exception is a source with a dedicated entry in the search
 * dropdown. Choosing "serial number" and getting back everything that merely
 * looks like one would make the entry pointless, so that one case is
 * exclusive — and the person asked for it by name.
 *
 * Two stores, two hooks:
 *
 * - WooCommerce's own order tables use
 *   `woocommerce_shop_order_list_table_prepare_items_query_args`, which
 *   hands over the arguments before the query runs.
 * - The legacy post store uses `woocommerce_shop_order_search_results`,
 *   which hands over the ids WooCommerce has already found. Nothing has to
 *   be re-run there, which is why that path is three lines.
 */
class Dahu_Order_Search_List_Table {

	/** Prefix for the dropdown values a source contributes. */
	const FILTER_PREFIX = 'dahu_src_';

	/**
	 * True while the native search is being resolved.
	 *
	 * `wc_order_search()` runs the data store's search, and the data store
	 * applies `woocommerce_shop_order_search_results` — the very filter this
	 * class hooks. Without this flag, asking WooCommerce what it would have
	 * found on its own returns its answer **with ours already folded in**,
	 * so every extended hit lands in the middle block instead of in front
	 * of or behind the native results, and the ranking quietly stops
	 * meaning anything. Found by a control assertion on the bench, not by
	 * reading the code.
	 *
	 * @var bool
	 */
	private static $resolving_native = false;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_hpos_admin_search_filters', array( __CLASS__, 'search_filters' ) );
		add_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args', array( __CLASS__, 'hpos_query_args' ), 5 );
		add_filter( 'woocommerce_shop_order_search_results', array( __CLASS__, 'legacy_results' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'legacy_order_by_relevance' ) );
	}

	/* ---------------------------------------------------------------------
	 * The search dropdown
	 * ------------------------------------------------------------------ */

	/**
	 * Adds one entry per source that asked for one.
	 *
	 * They go at the end rather than near the top. "Order ID" is the entry
	 * immediately below "All", and it is the one entry in the list that
	 * misbehaves on anything but a number — putting a new, tempting entry
	 * beside it sends people into it by accident.
	 *
	 * @param array $options value => label.
	 * @return array
	 */
	public static function search_filters( $options ) {
		$options = (array) $options;

		foreach ( Dahu_Order_Search_Sources::registered() as $id => $source ) {
			if ( null === $source['filter'] || ! Dahu_Order_Search_Settings::source_enabled( $id ) ) {
				continue;
			}

			$options[ self::FILTER_PREFIX . $id ] = $source['filter'];
		}

		return $options;
	}

	/**
	 * The source a dropdown value names, or '' for the ordinary filters.
	 *
	 * @param string $filter Dropdown value.
	 * @return string Source id, or ''.
	 */
	private static function source_for_filter( $filter ) {
		$filter = (string) $filter;

		if ( 0 !== strpos( $filter, self::FILTER_PREFIX ) ) {
			return '';
		}

		$id      = substr( $filter, strlen( self::FILTER_PREFIX ) );
		$sources = Dahu_Order_Search_Sources::registered();

		// Registered **and** ticked. The dropdown stops offering an unticked
		// source, but WooCommerce remembers the chosen filter per user and a
		// shared link carries it — so without this check a source the shop
		// switched off goes on answering, exclusively, from a URL nobody can
		// see and nothing can reach on purpose.
		if ( ! isset( $sources[ $id ] ) || ! Dahu_Order_Search_Settings::source_enabled( $id ) ) {
			return '';
		}

		return $id;
	}

	/* ---------------------------------------------------------------------
	 * WooCommerce's own order tables
	 * ------------------------------------------------------------------ */

	/**
	 * Rewrites the list table's query arguments.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public static function hpos_query_args( $args ) {
		$args = (array) $args;
		$term = isset( $args['s'] ) ? trim( (string) $args['s'] ) : '';

		if ( '' === $term || ! self::user_allowed() ) {
			return $args;
		}

		$filter = isset( $args['search_filter'] ) ? (string) $args['search_filter'] : '';
		$source = self::source_for_filter( $filter );

		// A source's own dropdown entry: that source alone, no native search.
		//
		// Except below the shortest-term setting, where the source does not
		// run at all. Returning nothing there would be a plain lie — the
		// setting's own description promises the native search answers on
		// its own — and the person would read "no orders found" as an
		// answer about their shop rather than about a three-letter term.
		if ( '' !== $source ) {
			if ( ! Dahu_Order_Search_Sources::term_is_long_enough( $term ) ) {
				return $args;
			}

			return self::replace_with( $args, Dahu_Order_Search_Sources::collect( $term, $source ), $args, false );
		}

		// The "Order ID" filter with something that is not an order number.
		// WooCommerce drops the term and returns the whole shop.
		//
		// What goes back is not "nothing" but "whatever our sources can
		// find", which is a better answer and a safer one. An empty list
		// reads as a fact about the shop, and WooCommerce persists this
		// dropdown choice per user — so somebody who once picked "Order ID"
		// would get a confident, wrong "no orders found" on every search
		// afterwards, with nothing on screen to explain it.
		//
		// `is_numeric()` and not `ctype_digit()`, because that is the test
		// WooCommerce itself applies: "15080.0" and "+15080" resolve to a
		// real order there, and used to resolve to nothing here.
		if ( 'order_id' === $filter && ! is_numeric( ltrim( $term, '#' ) ) ) {
			if ( ! Dahu_Order_Search_Settings::fixes_order_id_filter() ) {
				return $args;
			}

			return self::replace_with( $args, Dahu_Order_Search_Sources::collect( $term ), $args, false );
		}

		// The remaining WooCommerce filters search one field each and do it
		// correctly. "Customers" is the exception worth joining in on: it
		// misses a business whose billing name is empty and whose company
		// carries the whole identity.
		if ( '' !== $filter && 'all' !== $filter && 'customers' !== $filter ) {
			return $args;
		}

		if ( 'customers' === $filter ) {
			// The addresses source alone — and only if the shop left it on.
			// Running a source somebody unticked, because of which filter
			// they chose, is the plugin overruling a setting it offered.
			$found = Dahu_Order_Search_Settings::source_enabled( 'addresses' ) && Dahu_Order_Search_Sources::term_is_long_enough( $term )
				? Dahu_Order_Search_Sources::collect( $term, 'addresses' )
				: array();
		} else {
			$found = Dahu_Order_Search_Sources::collect( $term );
		}

		if ( ! $found ) {
			// Nothing to add. Leaving `s` in place keeps WooCommerce's own
			// search, its own ordering, and its own idea of the total.
			return $args;
		}

		return self::replace_with( $args, $found, $args, true );
	}

	/**
	 * Swaps the term for an explicit list of order ids.
	 *
	 * @param array  $args       Query arguments.
	 * @param array  $found      Sorted result map.
	 * @param string $term       Search term.
	 * @param bool   $with_native Union with WooCommerce's own results.
	 * @return array
	 */
	private static function replace_with( array $args, array $found, array $original, $with_native ) {
		$term   = isset( $original['s'] ) ? trim( (string) $original['s'] ) : '';
		$native = $with_native ? self::native_ids( $original ) : array();
		$ids    = Dahu_Order_Search_Results::order_ids( $term, $native, $found );

		unset( $args['s'], $args['search'], $args['search_filter'] );

		// An empty list has to be said out loud. Without this the query
		// would have no constraint left at all and would return the shop.
		$ids = $ids ? array_map( 'intval', $ids ) : array( 0 );

		$args['id']      = $ids;
		$args['include'] = $ids;

		// `orderby => include` becomes ORDER BY FIELD( id, … ), which the
		// pagination and the total survive. A column the person clicked wins.
		if ( ! self::user_sorted() ) {
			$args['orderby'] = 'include';
			unset( $args['order'] );
		}

		return $args;
	}

	/**
	 * What WooCommerce's own search would have found, all of it.
	 *
	 * Two paths, because the two order stores answer differently, and
	 * getting this wrong silently loses most of the native results:
	 *
	 * - **WooCommerce's own tables.** `wc_order_search()` is not the right
	 *   call here. It goes to `OrdersTableDataStore::search_orders()`, which
	 *   is `wc_get_orders( array( 's' => … ) )` with **no limit** — and so
	 *   inherits `WC_Object_Query`'s default of `get_option( 'posts_per_page' )`,
	 *   ten. Measured on this bench: 10 ids returned where the shop has 72
	 *   matches. Union that with our own and the list shows eleven orders,
	 *   says "11 items", and offers no second page. So the list table's own
	 *   arguments are reused instead, unpaginated — which also keeps
	 *   `search_filter`, without which "Customers" would quietly widen to
	 *   products and transaction ids.
	 * - **The legacy post store.** `wc_get_orders( array( 's' => … ) )` is
	 *   measurably useless there: the term is passed through to WP_Query,
	 *   whose search clause requires an empty `post_password`, and every
	 *   WooCommerce order carries one. Nought results, on every term, with
	 *   no error. `wc_order_search()` is what WooCommerce's own list table
	 *   calls there, and it has no limit on that store.
	 *
	 * @param array $args The list table's own query arguments.
	 * @return int[]
	 */
	private static function native_ids( array $args ) {
		if ( Dahu_Order_Search_Sources::hpos_enabled() ) {
			$native = $args;

			$native['limit']    = -1;
			$native['paginate'] = false;
			$native['return']   = 'ids';

			unset( $native['page'], $native['paged'], $native['offset'], $native['id'], $native['include'] );

			$ids = wc_get_orders( $native );

			return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		}

		$term = isset( $args['s'] ) ? trim( (string) $args['s'] ) : '';

		self::$resolving_native = true;

		try {
			$ids = wc_order_search( $term );
		} finally {
			self::$resolving_native = false;
		}

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/* ---------------------------------------------------------------------
	 * The legacy post store
	 * ------------------------------------------------------------------ */

	/**
	 * Adds our ids to the ones WooCommerce already found.
	 *
	 * @param int[]  $order_ids Ids from the native search.
	 * @param string $term      Search term.
	 * @return int[]
	 */
	public static function legacy_results( $order_ids, $term ) {
		if ( self::$resolving_native || ! self::user_allowed() ) {
			return $order_ids;
		}

		$found = Dahu_Order_Search_Sources::collect( (string) $term );

		if ( ! $found ) {
			return $order_ids;
		}

		return Dahu_Order_Search_Results::order_ids( (string) $term, (array) $order_ids, $found );
	}

	/**
	 * Keeps the relevance order on the legacy store.
	 *
	 * WooCommerce puts our ids into `post__in` and then sorts the list by
	 * date, which throws the ranking away. Asking for `post__in` ordering
	 * puts it back — unless the person clicked a column, in which case they
	 * have said what they want.
	 *
	 * @param WP_Query $query The query.
	 */
	public static function legacy_order_by_relevance( $query ) {
		if ( ! is_a( $query, 'WP_Query' ) || ! $query->is_main_query() ) {
			return;
		}

		if ( ! $query->get( 'shop_order_search' ) || ! $query->get( 'post__in' ) || self::user_sorted() ) {
			return;
		}

		$query->set( 'orderby', 'post__in' );
	}

	/* ---------------------------------------------------------------------
	 * Plumbing
	 * ------------------------------------------------------------------ */

	/**
	 * May the current user see orders at all?
	 *
	 * @return bool
	 */
	private static function user_allowed() {
		return current_user_can( 'edit_shop_orders' ) || current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Has the person chosen a sort by clicking a column?
	 *
	 * @return bool
	 */
	private static function user_sorted() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which column the list is sorted by.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';

		// Only a column the list can actually sort by. A stale or foreign
		// `orderby=` in a bookmarked URL is discarded by WooCommerce itself
		// and must not silently switch the ranking off here either.
		//
		// Note what this deliberately does NOT do: once somebody clicks a
		// column, WordPress carries `orderby` forward as a hidden field in
		// the search box, so every later search from that box stays sorted
		// their way and unranked. That is their choice, carried forward —
		// not a bug to work around. It is written in §9 as something to
		// watch on a real shop.
		$sortable = apply_filters(
			'dahu_order_search_sortable_columns',
			array( 'order_number', 'ID', 'id', 'date', 'date_created', 'order_date', 'order_total', 'total', 'wc_actions' )
		);

		return '' !== $orderby && in_array( $orderby, (array) $sortable, true );
	}
}
