<?php
/**
 * The result map, and the rank scale every source ranks against.
 *
 * @package DahuOrderSearch
 */

defined( 'ABSPATH' ) || exit;

/**
 * A result map is `order_id => array( 'rank' => int, 'reasons' => string[] )`.
 *
 * Sources build one, this class merges them, and the list table turns the
 * merged map into an ordered list of order ids. Nothing here knows what a
 * source looks for — which is the point: the same merge serves the five
 * built-in sources and any a shop registers of its own.
 */
class Dahu_Order_Search_Results {

	/*
	 * The rank scale. Lower is more relevant.
	 *
	 * RANK_NATIVE is the one value that means something structural: it is
	 * where WooCommerce's own results sit. A hit ranked below it is shown
	 * *before* the native results, a hit ranked above it after. So a source
	 * claiming a rank under 10 is claiming "this is a better answer than
	 * anything the native search can return", which is true of an exact
	 * company name and of very little else.
	 */

	/** An exact match on a whole name or company — better than native. */
	const RANK_EXACT = 5;

	/** Where WooCommerce's own results sit. Not a rank a source should use. */
	const RANK_NATIVE = 10;

	/** A confident match: an identifier a source owns and recognises. */
	const RANK_STRONG = 20;

	/** A link from another order to this one. */
	const RANK_LINKED = 50;

	/** A value found in order or line meta. */
	const RANK_META = 60;

	/** A partial match somewhere in an address. */
	const RANK_ADDRESS = 70;

	/** A mention in a note — the weakest evidence there is. */
	const RANK_NOTE = 90;

	/**
	 * Records one order, with the reason it was found.
	 *
	 * An order found twice keeps its best rank and collects both reasons:
	 * "found by the company name AND mentioned in a note" is more useful to
	 * the person reading the screen than either half.
	 *
	 * @param array  $map      Result map, by reference.
	 * @param int    $order_id Order.
	 * @param string $reason   Why it matched, for the person reading.
	 * @param int    $rank     One of the RANK_* values.
	 */
	public static function add( array &$map, $order_id, $reason, $rank ) {
		$order_id = (int) $order_id;

		if ( $order_id <= 0 ) {
			return;
		}

		$rank   = (int) $rank;
		$reason = trim( (string) $reason );

		if ( ! isset( $map[ $order_id ] ) ) {
			$map[ $order_id ] = array(
				'rank'    => $rank,
				'reasons' => array(),
			);
		}

		$map[ $order_id ]['rank'] = min( $map[ $order_id ]['rank'], $rank );

		if ( '' !== $reason && ! in_array( $reason, $map[ $order_id ]['reasons'], true ) ) {
			$map[ $order_id ]['reasons'][] = $reason;
		}
	}

	/**
	 * Folds one result map into another.
	 *
	 * @param array $map   Target, by reference.
	 * @param array $other Source map.
	 */
	public static function merge( array &$map, array $other ) {
		foreach ( $other as $order_id => $hit ) {
			$rank    = isset( $hit['rank'] ) ? (int) $hit['rank'] : self::RANK_META;
			$reasons = isset( $hit['reasons'] ) ? (array) $hit['reasons'] : array();

			if ( ! $reasons ) {
				self::add( $map, $order_id, '', $rank );
				continue;
			}

			foreach ( $reasons as $reason ) {
				self::add( $map, $order_id, $reason, $rank );
			}
		}
	}

	/**
	 * Accepts whatever shape a registered source returned and normalises it.
	 *
	 * A source outside this plugin should not have to know the map format to
	 * be useful. Three shapes are accepted:
	 *
	 *   array( 123, 456 )                      ids alone
	 *   array( 123 => 'why it matched' )       id => reason
	 *   array( 123 => array( 'rank' => …, 'reasons' => array( … ) ) )
	 *
	 * Anything else is dropped rather than guessed at. A source that returns
	 * nonsense should contribute nothing, not contribute noise.
	 *
	 * @param mixed  $returned     What the source's callback returned.
	 * @param int    $default_rank Rank for the shapes that do not carry one.
	 * @param string $label        Source label, used when there is no reason.
	 * @return array Result map.
	 */
	public static function normalise( $returned, $default_rank, $label = '' ) {
		$map = array();

		if ( ! is_array( $returned ) ) {
			return $map;
		}

		// Decided once, for the whole array, and not per entry. Deciding per
		// entry made `array( 1024 => '00123456' )` — the documented
		// `id => reason` shape — look like a list of ids whenever the reason
		// happened to be all digits: order 1024 was dropped and an order
		// 123456 invented. A source reporting numeric serial numbers, which
		// is this plugin's own worked example, hit it every time.
		$is_list = array() === $returned || array_keys( $returned ) === range( 0, count( $returned ) - 1 );

		foreach ( $returned as $key => $value ) {
			if ( $is_list ) {
				if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
					self::add( $map, (int) $value, $label, $default_rank );
				}

				continue;
			}

			$order_id = (int) $key;

			if ( $order_id <= 0 ) {
				continue;
			}

			if ( is_scalar( $value ) ) {
				self::add( $map, $order_id, (string) $value, $default_rank );
				continue;
			}

			if ( is_array( $value ) ) {
				$rank    = isset( $value['rank'] ) && is_numeric( $value['rank'] ) ? (int) $value['rank'] : $default_rank;
				$reasons = isset( $value['reasons'] ) ? (array) $value['reasons'] : array();
				$kept    = 0;

				foreach ( $reasons as $reason ) {
					if ( is_scalar( $reason ) ) {
						self::add( $map, $order_id, (string) $reason, $rank );
						$kept++;
					}
				}

				// A source that reported an order with unusable reasons
				// still reported the order. Losing the order because its
				// explanation was malformed is losing the thing that
				// matters to keep the thing that does not.
				if ( ! $kept ) {
					self::add( $map, $order_id, $label, $rank );
				}
			}
		}

		return $map;
	}

	/**
	 * Sorts a result map: best rank first, then most recent order first.
	 *
	 * The tie-break matters. Within one rank the orders are equally good
	 * answers, and the list a shop expects when nothing distinguishes two
	 * rows is the one WooCommerce gives by default — newest at the top.
	 *
	 * @param array $map Result map.
	 * @return array The same map, ordered.
	 */
	public static function sort( array $map ) {
		uksort(
			$map,
			static function ( $a, $b ) use ( $map ) {
				if ( $map[ $a ]['rank'] !== $map[ $b ]['rank'] ) {
					return $map[ $a ]['rank'] < $map[ $b ]['rank'] ? -1 : 1;
				}

				return (int) $b - (int) $a;
			}
		);

		return $map;
	}

	/**
	 * Merges the extended results with the native ones into one ordered list.
	 *
	 *   1. the order whose id is exactly the term, if there is one;
	 *   2. extended hits ranked better than native;
	 *   3. the native results, in the order WooCommerce returned them;
	 *   4. the rest of the extended hits, already sorted.
	 *
	 * Step 3 is why this plugin adds rather than replaces. A shop that has
	 * learned where its orders turn up in the native search keeps that
	 * knowledge; the extension only puts things in front of it when it has
	 * something better, and behind it otherwise.
	 *
	 * @param string $term   Search term.
	 * @param int[]  $native Ids WooCommerce found on its own.
	 * @param array  $found  Sorted result map.
	 * @return int[]
	 */
	public static function order_ids( $term, array $native, array $found ) {
		$head = array();
		$tail = array();

		foreach ( $found as $order_id => $hit ) {
			if ( $hit['rank'] < self::RANK_NATIVE ) {
				$head[] = (int) $order_id;
			} else {
				$tail[] = (int) $order_id;
			}
		}

		$ids   = array_values( array_unique( array_merge( $head, array_map( 'intval', $native ), $tail ) ) );
		$exact = self::order_id_term( $term );
		$pos   = $exact ? array_search( $exact, $ids, true ) : false;

		if ( false !== $pos && $pos > 0 ) {
			array_splice( $ids, $pos, 1 );
			array_unshift( $ids, $exact );
		}

		return $ids;
	}

	/**
	 * The term read as an order number, or 0.
	 *
	 * @param string $term Search term.
	 * @return int
	 */
	public static function order_id_term( $term ) {
		$term = ltrim( trim( (string) $term ), '#' );

		return ctype_digit( $term ) ? (int) $term : 0;
	}
}
