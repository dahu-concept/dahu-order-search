# Dahu Order Search

Trouve les commandes que la recherche de WooCommerce rate, et les ajoute aux
résultats que vous obtenez déjà.

Une boutique qui cherche une commande a en général une de ces trois choses en
main : un nom, un numéro de téléphone, ou un numéro écrit sur un bout de
papier. La recherche de commandes de WooCommerce traite le premier cas, et pas
grand-chose d'autre — et elle rate même celui-là quand la commande est une
entreprise dont le nom de facturation est vide et dont la société porte toute
l'identité.

Cette extension ajoute des sources au champ de recherche de la liste des
commandes. Ce qu'elles trouvent est **fusionné** avec les résultats de
WooCommerce et classé par pertinence. Elle ne remplace jamais la recherche que
la boutique a appris à employer.

Sous licence MIT. Fonctionne sur les deux magasins de commandes : les tables
propres à WooCommerce comme le magasin historique.

*La version anglaise de ce document est dans `README.md`. En cas d'écart, c'est
elle qui fait foi : c'est elle qui part sur wordpress.org.*

---

## Ce qu'elle cherche

| Source | Ce qu'elle regarde |
|---|---|
| **Client** | Société, téléphone, e-mail, prénom et nom, rue, ville, code postal — sur l'adresse de facturation *et* sur celle de livraison |
| **Métas de commande** | Les clés que vous listez : numéro de suivi, numéro de facture, référence venue d'un autre système |
| **Métas d'article** | Les clés d'article que vous listez : un numéro de série saisi sur une ligne, une gravure, une taille |
| **Notes de commande** | Le texte des notes |
| **Commandes liées** | Les commandes dont une méta pointe vers une commande trouvée, et celles vers lesquelles une commande trouvée pointe |

Chacune se décoche. Chacune est un `SELECT` préparé ; rien dans cette extension
n'écrit.

### Celle qui justifie l'extension

Une entreprise passe commande. Le nom de facturation est vide, la société est
`Zwiebelkuchen GmbH`, le téléphone est celui de la facture. Quelqu'un appelle à
son sujet.

La recherche de WooCommerce ne trouve pas cette commande par la société, et pas
davantage par le téléphone. Cette extension la trouve par les deux — et comme
la société correspond **exactement**, elle place cette commande **au-dessus**
de tout ce que la recherche native a renvoyé.

---

## Le classement

Chaque résultat porte un rang, et le rang décide de sa place par rapport aux
résultats de WooCommerce.

| Rang | Sens | Position |
|---|---|---|
| 5 | Un nom complet ou une société exactement égal au terme | **Avant** les résultats natifs |
| 10 | La place des résultats de WooCommerce | — |
| 20 | Une correspondance sûre sur un identifiant qu'une source possède | Après |
| 50 | Un lien depuis une autre commande | Après |
| 60 | Une valeur dans une méta de commande ou d'article | Après |
| 70 | Une correspondance partielle dans une adresse | Après |
| 90 | Une mention dans une note | Après |

Une commande dont le numéro **est** le terme recherché passe en tête, avant
tout le reste.

Une commande trouvée par plusieurs sources garde son meilleur rang et collecte
toutes les raisons : l'écran peut dire « trouvée par la société **et**
mentionnée dans une note » plutôt que d'en choisir une.

---

## La correction WooCommerce

Le menu déroulant de recherche de WooCommerce propose une entrée
**« N° de commande »**. Employée avec un nombre, elle fonctionne. Employée avec
autre chose — un nom, une référence, un mot — WooCommerce abandonne le terme en
silence et renvoie **toutes les commandes de la boutique**.

Une recherche qui correspond à tout est pire qu'une recherche qui ne
correspond à rien : rien est manifestement une réponse, tout ressemble à un
écran en panne.

Cette extension répond alors avec ce que ses propres sources trouvent — ce qui,
pour une boutique ayant configuré une clé de méta de référence, est en général
la commande cherchée. C'est une case à cocher sur l'écran de réglages, cochée
par défaut ; la décocher rend son comportement à WooCommerce.

Ne rien renvoyer était la première version, et c'était pire que ça n'en avait
l'air : WooCommerce retient ce choix de menu par utilisateur, donc quelqu'un
qui a une fois choisi « N° de commande » obtiendrait ensuite un « aucune
commande trouvée » assuré et inexpliqué à chaque recherche. Une liste vide se
lit comme un fait sur la boutique.

*(Mesuré sur WooCommerce 11.1.2, sur les tables de commandes de WooCommerce. Le
magasin historique n'a pas ce menu, donc rien à y corriger.)*

---

## Enregistrer une source

Une source est un rappel qui reçoit le terme recherché et rend les commandes
qu'il a trouvées. Trois formes de retour sont acceptées ; la plus simple est
`id_de_commande => pourquoi`.

```php
add_filter(
	'dahu_order_search_sources',
	function ( array $sources ) {
		$sources['garantie'] = array(
			'label'    => 'Numéro de dossier de garantie',
			'callback' => function ( $term ) {
				global $wpdb;

				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT order_id, dossier FROM {$wpdb->prefix}ma_garantie WHERE dossier LIKE %s LIMIT 200",
						'%' . $wpdb->esc_like( $term ) . '%'
					),
					ARRAY_A
				);

				$found = array();

				foreach ( $rows as $row ) {
					$found[ (int) $row['order_id'] ] = 'Dossier de garantie ' . $row['dossier'];
				}

				return $found;
			},
			'rank'     => 20,
			'filter'   => 'Dossier de garantie',
		);

		return $sources;
	}
);
```

Vous obtenez :

- une case à cocher sur l'écran de réglages, portant `label` ;
- vos résultats fusionnés dans chaque recherche ordinaire, au rang choisi ;
- une entrée dans le menu déroulant de recherche, portant `filter`.

`filter` est facultatif, et il change le comportement : choisir votre entrée
lance **votre source seule** — aucune autre source, et pas la recherche native
non plus. C'est ce qui rend une entrée dédiée utile. Sans `filter`, votre
source ne fait jamais qu'ajouter.

### Les trois formes de retour

```php
return array( 123, 456 );                                        // des ids seuls
return array( 123 => 'pourquoi' );                               // id => raison
return array( 123 => array( 'rank' => 20, 'reasons' => array( 'pourquoi' ) ) );
```

Toute autre forme est écartée plutôt que devinée. Une source qui rend
n'importe quoi ne contribue rien, pas du bruit.

Une source dont le rappel n'existe pas est écartée elle aussi, tout le reste
continuant de tourner. Votre extension à moitié mise à jour doit coûter votre
source et rien de plus : une erreur fatale sur la liste des commandes est un
plus mauvais résultat, et beaucoup plus difficile à imputer.

---

## Filtres

| Filtre | Ce qu'il fait |
|---|---|
| `dahu_order_search_sources` | Enregistrer, retirer ou renommer des sources. |
| `dahu_order_search_max_ids` | Le nombre maximal de commandes qu'une source peut remonter sur une recherche. 1000 par défaut. Il vaut aussi pour les sources enregistrées, pas seulement pour les intégrées. |
| `dahu_order_search_order_post_types` | Sur le magasin historique, les types de contenu traités comme des commandes. `shop_order` par défaut. |

---

## Réglages

**WooCommerce → Recherche de commandes.** Nécessite `manage_woocommerce`.

- quelles sources tournent ;
- les clés de méta de commande, de méta d'article et de commande liée à
  regarder, une par ligne ;
- le terme le plus court qui déclenche les sources supplémentaires (4
  caractères par défaut — en deçà, la recherche native répond seule) ;
- faut-il corriger le filtre « N° de commande ».

Un astérisque seul dans les clés de méta d'article signifie *toutes les métas
d'article visibles*. Les clés internes — celles qui commencent par un souligné
— sont toujours écartées : elles contiennent des nombres nus, et les chercher
ferait correspondre n'importe quel nombre à la moitié de la boutique.

---

## Installation

Déposer le dossier dans `wp-content/plugins/` et activer. Il n'y a rien à
configurer avant que ça marche : les sources client et notes n'ont besoin
d'aucun réglage.

Nécessite WordPress 6.0, PHP 7.4, WooCommerce 7.0.

---

## Désinstallation

Une option est supprimée. Aucune donnée de commande n'est touchée, puisque
cette extension n'en écrit jamais.

---

## Licence

MIT. Voir `LICENSE`.

Hugo Vial-Jaime — Dahu-Concept — <contact@dahu-concept.fr>
