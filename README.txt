=== Dahu Order Search ===
Contributors: hugovialjaime
Tags: woocommerce, orders, search, admin, b2b
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Finds the orders WooCommerce's own search misses — by company, phone, order meta, line meta or note — and adds them to the results you already get.

== Description ==

A shop looking for an order usually has one of three things in hand: a name, a
phone number, or a number written on a piece of paper. WooCommerce's order
search handles the first and not much else — and it misses even that when the
order is a business whose billing name is empty and whose company carries the
whole identity.

Dahu Order Search adds sources to the search box above the order list. What
they find is merged with WooCommerce's own results and ranked by how well it
matches. It never replaces the search you already know.

= What it searches =

* Customer — company, phone, email, first and last name, street, town,
  postcode, on the billing and the shipping address.
* Order meta — any keys you list: a tracking number, an invoice number, a
  reference from another system.
* Order line meta — any line meta keys you list: a serial number typed on a
  line, an engraving, a size.
* Order notes.
* Linked orders — orders whose meta points at an order you found, and the
  orders a found order points at.

Each one can be switched off. Each one is a prepared SELECT; nothing in this
plugin writes.

= The one that justifies the plugin =

A business places an order. The billing name is blank, the company is
"Zwiebelkuchen GmbH", the phone is the one on the invoice. Somebody rings up.

WooCommerce's search finds that order by neither. This one finds it by both,
and because the company is an exact match it puts the order above everything
the native search returned.

= Ranking =

Results carry a rank, and the rank decides where they sit relative to
WooCommerce's own results. An exact whole name or company goes in front of
them; a mention in a note goes behind. An order whose number is the search
term goes first of all.

An order found by several sources keeps its best rank and collects every
reason.

= The WooCommerce correction =

WooCommerce's search dropdown has an "Order ID" entry. Used with a number it
works. Used with anything else it silently drops the term and returns every
order in the shop — a search that matches everything, which reads as a screen
that has broken rather than as an answer.

This plugin returns no results in that case instead. It is a checkbox, on by
default, and switching it off gives WooCommerce's behaviour back.

= For developers =

Other plugins can register sources of their own through the
`dahu_order_search_sources` filter: a label, a callback taking the search term,
a rank, and optionally an entry in the search dropdown. A source with its own
dropdown entry runs alone when that entry is chosen. Full example in README.md.

= External services =

None. This plugin makes no network request of any kind and sends nothing
anywhere. Every query it runs is a read against your own database.

== Installation ==

1. Upload the folder to `/wp-content/plugins/`.
2. Activate it from the Plugins screen.
3. Optionally, visit WooCommerce → Order search to list the meta keys worth
   searching.

Nothing has to be configured first: the customer and notes sources need no
settings.

== Frequently Asked Questions ==

= Does it replace the WooCommerce search? =

No. It adds to it. Everything WooCommerce would have found is still there, in
its own order; this plugin puts things in front of that when it has something
better and behind it otherwise. The one exception is a source with its own
entry in the search dropdown, which runs alone when you choose it — because
you asked for it by name.

= Does it work with High-Performance Order Storage? =

Yes, and with the legacy post store, and the answers are the same on both.

= Will it slow the order list down? =

Each source is one indexed query with a ceiling of 1000 rows, and nothing runs
until a term is at least four characters long. Switch off the sources you do
not need; the ceiling is filterable if you want more.

= It found nothing on a short term. =

Below the "shortest search term" setting the extra sources do not run and
WooCommerce answers alone. Four characters by default.

== Screenshots ==

1. The settings screen: which sources run, and which meta keys they look at.

== Changelog ==

= 1.1.0 =
* The activation screen now opens on the plugin's own mark rather than its initials, and the banner carries the same drawing as a watermark.

= 1.0.2 =
* The settings screen now carries WordPress's notice marker, so a settings notice appears under the page heading instead of inside the first card.

= 1.0.1 =
* Uninstall on multisite: the per-site loop no longer stops at the first hundred sites, so the settings and the welcome flags are removed from the whole network.

= 1.0.0 =
* First public release.

== Upgrade Notice ==

= 1.0.0 =
First public release.
