# Dahu Order Search

Finds the orders WooCommerce's own search misses, and adds them to the results you already get.

A shop looking for an order usually has one of three things in hand: a name, a phone number, or a number written on a piece of paper. WooCommerce's order search handles the first one and not much else — and it misses even that when the order is a business whose billing name is empty and whose company carries the whole identity.

This plugin adds sources to the search box above the order list. What they find is **merged** with WooCommerce's own results and ranked by how well it matches. It never replaces the search a shop has learned to use.

MIT licensed. Works on both order stores — WooCommerce's own tables and the legacy post store.

---

## What it searches

| Source | What it looks at |
|---|---|
| **Customer** | Company, phone, email, first and last name, street, town, postcode — on the billing *and* the shipping address |
| **Order meta** | Any meta keys you list: a tracking number, an invoice number, a reference from another system |
| **Order line meta** | Any line meta keys you list: a serial number typed on a line, an engraving, a size |
| **Order notes** | The text of order notes |
| **Linked orders** | Orders whose meta points at an order you found, and the orders a found order points at |

Each one can be switched off. Each one is a prepared `SELECT`; nothing in this plugin writes.

### The one that justifies the plugin

A business places an order. The billing name is blank, the company is `Zwiebelkuchen GmbH`, the phone is the one on the invoice. Somebody rings up about it.

WooCommerce's order search does not find that order by company and does not find it by phone. This plugin finds it by both, and because the company is an *exact* match it puts that order **above** everything the native search returned.

---

## Ranking

Results carry a rank, and the rank decides where they sit relative to WooCommerce's own results.

| Rank | Meaning | Position |
|---|---|---|
| 5 | An exact whole name or company | **Before** the native results |
| 10 | Where WooCommerce's own results sit | — |
| 20 | A confident match on an identifier a source owns | After |
| 50 | A link from another order | After |
| 60 | A value in order or line meta | After |
| 70 | A partial match in an address | After |
| 90 | A mention in a note | After |

An order whose number *is* the search term goes first, above everything.

An order found by several sources keeps its best rank and collects every reason, so the screen can say "found by the company name **and** mentioned in a note" rather than picking one.

---

## The WooCommerce correction

WooCommerce's search dropdown has an **Order ID** entry. Used with a number it works. Used with anything else — a name, a reference, a word — WooCommerce silently drops the search term and returns **every order in the shop**.

A search that matches everything is worse than one that matches nothing: nothing is obviously an answer, everything looks like a screen that has broken.

This plugin answers with whatever its own sources can find instead — which, for a shop that configured an order-reference meta key, is usually the order you were looking for. It is a checkbox on the settings screen, on by default, and switching it off gives WooCommerce's behaviour back.

Returning *nothing* was the first version, and it was worse than it looked: WooCommerce remembers this dropdown choice per user, so somebody who once picked "Order ID" would get a confident, unexplained "no orders found" on every search afterwards. An empty list reads as a fact about the shop.

*(Measured on WooCommerce 11.1.2, on WooCommerce's own order tables. The legacy post store has no such dropdown, so nothing to correct there.)*

---

## Registering a source

A source is a callback that takes the search term and returns the orders it found. Three return shapes are accepted; the simplest is `order_id => why`.

```php
add_filter(
	'dahu_order_search_sources',
	function ( array $sources ) {
		$sources['warranty'] = array(
			'label'    => 'Warranty claim number',
			'callback' => function ( $term ) {
				global $wpdb;

				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT order_id, claim FROM {$wpdb->prefix}my_warranty_claims WHERE claim LIKE %s LIMIT 200",
						'%' . $wpdb->esc_like( $term ) . '%'
					),
					ARRAY_A
				);

				$found = array();

				foreach ( $rows as $row ) {
					$found[ (int) $row['order_id'] ] = 'Warranty claim ' . $row['claim'];
				}

				return $found;
			},
			'rank'     => 20,
			'filter'   => 'Warranty claim',
		);

		return $sources;
	}
);
```

That gives you:

- a checkbox on the settings screen, labelled with `label`;
- your results merged into every ordinary search, at the rank you chose;
- an entry in the search dropdown, labelled with `filter`.

`filter` is optional, and it changes the behaviour: choosing your entry runs **your source alone** — no other source, and no native search either. That is what makes a dedicated entry worth having. Leave `filter` out and your source only ever adds.

### The three return shapes

```php
return array( 123, 456 );                                        // ids alone
return array( 123 => 'why it matched' );                         // id => reason
return array( 123 => array( 'rank' => 20, 'reasons' => array( 'why' ) ) );
```

Anything else is dropped rather than guessed at. A source that returns nonsense contributes nothing, not noise.

A source whose callback does not exist is dropped too, with everything else left running. Your half-updated plugin should cost your source and nothing more — a fatal error on the order list is a worse outcome, and much harder to attribute.

---

## Filters

| Filter | What it does |
|---|---|
| `dahu_order_search_sources` | Register, remove or relabel sources. |
| `dahu_order_search_max_ids` | The most orders one source may contribute to a single search. Default 1000. It holds for registered sources too, not only the built-in ones. |
| `dahu_order_search_order_post_types` | On the legacy store, the post types treated as orders. Default `shop_order`. |

---

## Settings

**WooCommerce → Order search.** Requires `manage_woocommerce`.

- which sources run;
- the order meta keys, line meta keys and linked-order meta keys to look at, one per line;
- the shortest term that triggers the extra sources (default 4 characters — below it the native search answers alone);
- whether to correct the Order ID filter.

An asterisk on its own in the line meta keys means *every visible line meta*. Internal keys — the ones beginning with an underscore — are always skipped, because they hold bare numbers and searching them makes any number match half the shop.

---

## Installing

Upload the folder to `wp-content/plugins/` and activate it. There is nothing to configure before it starts working: the customer and notes sources need no settings.

Requires WordPress 6.0, PHP 7.4, WooCommerce 7.0.

---

## Uninstalling

One option is removed. No order data is touched, because this plugin never writes any.

---

## Licence

MIT. See `LICENSE`.

Hugo Vial-Jaime — Dahu-Concept — <contact@dahu-concept.fr>
