# Dahu Order Search — résumé technique

Version 1.0.0. Document interne : ce que l'extension fait, pourquoi elle le
fait ainsi, ce qui a été mesuré et ce qui reste à vérifier sur une vraie
boutique.

---

## 1. Ce que c'est

Une extension d'administration qui ajoute des sources à la recherche de la
liste des commandes WooCommerce. Quatre fichiers de classe, une feuille de
style, aucun JavaScript.

| | |
|---|---|
| Classes | 4 |
| Tables créées | aucune |
| Options | une (`dahu_order_search_settings`) |
| Écrans | un (WooCommerce → Recherche de commandes) |
| Requêtes | des `SELECT` préparés, exclusivement |
| Chaînes traduites | 30 / 30 en français |
| **Assertions au banc** | **120**, sur les deux magasins de commandes |
| **Injections délibérées** | **84** (42 par magasin, moins une sans objet sur HPOS) |

### Les quatre classes

| Classe | Rôle |
|---|---|
| `Dahu_Order_Search_Results` | La carte de résultats et l'échelle de rangs. Ne sait rien de ce qu'une source cherche. |
| `Dahu_Order_Search_Sources` | Le registre, les quatre sources intégrées, l'extension aux commandes liées. |
| `Dahu_Order_Search_Settings` | L'option, l'écran, l'assainissement. |
| `Dahu_Order_Search_List_Table` | Les deux crochets de la liste, un par magasin. |

---

## 2. Le portage : ce qui change par rapport à la source Annad

L'extension d'origine (`dahu-order-search` 1.1.3, 1 468 lignes) est très liée à
deux autres extensions d'Annad.

### 2.1 Le pont `dahu-serials` devient un registre

La source portait une classe `Dahu_OS_Serials_Bridge` de 347 lignes qui mêlait
deux choses : des utilitaires génériques (`merge()`, `add()`), employés
partout, et le couplage réel à `dahu-serials` — `Dahu_Serials_Manager`, et des
lectures directes des tables `dahu_serials`, `dahu_serials_log` et
`dahu_pending_replacements`.

La séparation était l'option retenue au cadrage, et c'est elle qui rend
l'extension intéressante publiquement :

- les utilitaires deviennent `Dahu_Order_Search_Results`, qui ne sait rien des
  numéros de série ;
- le couplage disparaît, remplacé par le filtre `dahu_order_search_sources`.

Une boutique qui a des numéros de série branche une source de trente lignes.
L'exemple complet est dans les deux README. **C'est le même motif de greffon
que celui retenu pour les transporteurs dans `dahu-serial-numbers`.**

### 2.2 Ce qui ne part pas

| Dans la source | Sort public |
|---|---|
| `SAV_LINK_META_KEYS` en dur (`_dahu_sav_origin_order_id`…) | Généralisé en « clés de méta de commande liée », configurables, les deux sens |
| `item_meta_keys` par défaut `'Nouveau moteur n°'` (via un filtre de `dahu-sav`) | Vide par défaut |
| `order_meta_keys` par défaut : deux clés Annad et une clé d'extension commerciale | Vide par défaut |
| `LEGACY_FILTER_KEY = 'motor_number'` (compatibilité v1.0.0 d'Annad) | Non repris |
| L'entrée « N° de série » en dur dans le menu déroulant | Posée par la source qui la demande, et placée **en fin de liste** (§3.2) |
| Le numéro de version et le lien « site de l'extension » sur la page Extensions | Non repris |

### 2.3 Les rangs deviennent une échelle publique

La source avait dix constantes de rang, dont six nommées d'après les numéros de
série. L'échelle publique en a six, nommées d'après ce qu'elles veulent dire,
et `RANK_NATIVE` est documentée comme le seul seuil structurel : en dessous, un
résultat passe **avant** ceux de WooCommerce ; au-dessus, après.

---

## 3. Ce qui a été mesuré dans WooCommerce

Trois comportements mesurés sur le banc (WordPress 7.1.3, WooCommerce 11.1.2),
sur les deux magasins. Dossier complet :
`claude/mesure-dahu-order-search-2026-10-09.md`.

### 3.1 `wc_get_orders( array( 's' => … ) )` ne sert à rien sur le magasin historique

Il rend **0, toujours, quel que soit le terme**. Mécanisme :

1. `WC_Data_Store_WP::get_wp_query_args()` ne connaît pas `s` et le recopie tel
   quel dans les arguments de `WP_Query` ;
2. la clause de recherche de `WP_Query` ajoute `AND post_password = ''` ;
3. **chaque commande WooCommerce porte un `post_password`** de la forme
   `wc_order_…`.

Aucune erreur, aucun avertissement, zéro résultat.

La source d'Annad reproduisait la recherche native par cet appel. La version
publique emploie **`wc_order_search( $term )`**, qui est ce que la table de
liste de WooCommerce appelle elle-même, et qui est juste sur les deux magasins
— mesuré : 2 sur 2, et 0 sur un terme introuvable, des deux côtés.

### 3.2 Le filtre « N° de commande » renvoie toute la boutique

Avec un terme non numérique, `search_filter = order_id` abandonne le terme et
renvoie toutes les commandes. Mesuré **extension désactivée** : 88 sur 88. Avec
un terme numérique : 1. Ce n'est donc pas une extension qui le cause.

Deux conséquences dans le code :

1. le garde-fou décrit dans les README, décochable ;
2. l'entrée d'une source va **en fin** de menu déroulant et non juste après
   « N° de commande », qui est là où la source d'origine la plaçait. Tant que ce
   défaut existe, mettre une entrée attrayante juste à côté de la seule entrée
   piégée de la liste envoie les gens dedans par accident.

### 3.3 `wc_order_search()` applique le filtre que l'extension accroche

Sur le magasin historique, `wc_order_search()` émet
`woocommerce_shop_order_search_results` — que cette extension accroche. Demander
à WooCommerce ce qu'il aurait trouvé seul rendait donc sa réponse **avec la
nôtre déjà dedans**, et faisait tourner toutes les sources deux fois par
recherche.

Un drapeau statique (`$resolving_native`) coupe la réentrance.

**Le résultat ne change pas** — ni un identifiant, ni un ordre : c'est pourquoi
aucune assertion sur les ids ne le voyait. Ce qui change est le travail, doublé.
L'assertion qui le prouve compte donc les appels, pas les résultats.

Sur HPOS le filtre équivalent est `woocommerce_cot_shop_order_search_results`,
que l'extension n'accroche pas : la réentrance y est impossible, et l'injection
correspondante est marquée sans objet sur ce magasin plutôt que comptée comme
manquée.

Trouvé par une **assertion témoin**, pas par la relecture : le témoin
« le natif ne trouve pas la commande que seules nos sources trouvent » tombait,
et c'est ce qui a révélé l'aller-retour.

---

## 4. Les deux chemins, un par magasin

### 4.1 Les tables de WooCommerce (HPOS)

Crochet `woocommerce_shop_order_list_table_prepare_items_query_args`, priorité
5. L'extension reçoit les arguments avant la requête, consomme `s` et pose
`id`, `include` et `orderby => include`.

`orderby => include` se traduit en `ORDER BY FIELD( id, … )`, que la pagination
et le total traversent sans dommage. Un tri choisi en cliquant une colonne
l'emporte.

**Une liste vide se dit à voix haute** : `array( 0 )` et non `array()`. Sans ça
la requête n'aurait plus aucune contrainte et rendrait la boutique entière.

### 4.2 Le magasin historique

Filtre `woocommerce_shop_order_search_results`, qui fournit déjà les IDs natifs
— il n'y a rien à rejouer, d'où un chemin de trois lignes. Puis `pre_get_posts`
pour demander `orderby => post__in`, sans quoi WooCommerce retrie par date et
jette le classement.

---

## 5. Décisions écrites

| Sujet | Décision |
|---|---|
| Union, jamais substitution | Sauf pour une source qui a son entrée dans le menu : la personne l'a demandée par son nom, et lui rendre en plus tout ce qui ressemble vaguement au terme rendrait l'entrée inutile. |
| Clés de méta par défaut | **Aucune.** Livrer les clés d'une autre boutique serait deviner ; les sources qui n'ont besoin d'aucun réglage sont actives, celles qui ont besoin de clés sont actives et inertes. |
| Un prénom seul n'est jamais exact | « Martin » étant le prénom de quelqu'un n'est pas une raison de placer sa commande devant tout le reste. Et une cliente dont le nom **est** un prénom ne devient pas exacte non plus : sur le magasin historique un nom vit dans deux lignes de méta, donc une ligne ne peut jamais prouver qu'elle porte le nom entier. Deux classements pour une même boutique selon un réglage qu'elle ne voit pas serait pire que la règle. |
| Pas de `CONCAT_WS` | Un nom complet à cheval sur deux colonnes est cherché en exigeant les deux mots. Plus précis, et sans dépendre d'une fonction que la base peut ne pas avoir. |
| Deux requêtes d'adresse, pas une | Les correspondances exactes sont cherchées séparément **et d'abord**. Sur un terme comme « Martin », une boutique peut avoir plus de 1 000 correspondances partielles, et la seule ligne où la société **est** « Martin » serait coupée avec elles — la ligne cherchée, perdue à cause d'un plafond posé pour protéger la base. |
| Une source cassée coûte une source | Un rappel qui n'existe pas est écarté, le reste tourne. Une erreur fatale sur la liste des commandes est un plus mauvais résultat, et beaucoup plus dure à imputer. |
| Aucune écriture | L'extension ne fait que des `SELECT`. La désinstallation retire une option et rien d'autre. |

---

## 6. Le banc

`_bench/t-order-search.php` — **120 assertions**, idempotentes, à lancer sur les
deux magasins :

```
php _bench/t-order-search.php
bash _bench/hpos.sh on && php _bench/t-order-search.php; bash _bench/hpos.sh off
```

`_bench/prove-order-search.py` — **42 injections**, passées sur les deux
magasins. Chaque injection doit faire tomber l'assertion **nommée**, pas
seulement rendre la suite rouge.

```
python3 _bench/prove-order-search.py --store both
```

### Ce que les injections ont trouvé

Six défauts, tous dans le banc ou dans la liste d'injections — sauf un, qui
était dans l'extension :

1. **La réentrance de `wc_order_search()`** (§3.3), trouvée par une assertion
   témoin.
2. **Trois assertions disparues.** Un remplacement de texte trop large avait
   emporté le bloc du plafond par source. Deux injections sont « restées
   vertes » parce que ce qu'elles devaient casser n'existait plus — c'est la
   seule raison pour laquelle on l'a su.
3. **Une assertion de plafond creuse** : `count(...) <= 1` sur une source qui
   n'aurait peut-être rendu qu'une ligne de toute façon. Un témoin mesure
   maintenant qu'elle en rend beaucoup plus sans plafond.
4. **Un terme trop court mesuré sur un fragment qui ne correspondait à rien.**
   `collect('ses')` rendait vide avec ou sans le garde-fou. Remplacé par `Zwi`,
   fragment d'une société du banc, avec un témoin sur `Zwie`.
5. **Le caractère exclusif du filtre, mesuré contre un natif vide.** Rien ne
   distinguait « ses ids seuls » de « ses ids plus rien ». La source de banc
   répond maintenant à un terme que le natif trouve aussi.
6. **Une forme de retour non-tableau** dont le garde-fou protège d'une alerte
   PHP et non d'un résultat : l'assertion porte sur l'alerte, par
   `set_error_handler`.

Et un garde-fou **délibérément absent** de la liste d'injections : le test
`is_array( $source )` du registre. Le retirer ne change rien, parce qu'une
entrée qui n'est pas un tableau échoue au test de rappel de la ligne suivante.
Le réclamer comme contrôle prouvé serait faux, et le laisser en échec permanent
apprendrait à ignorer les échecs. Il reste dans le code comme énoncé
d'intention, et c'est écrit dans l'en-tête du script.

---

## 7. Harnais statiques

| | |
|---|---|
| `test-conformite.php` | conformité wordpress.org |
| `test-prefixes.php` | préfixage, `eval()` |
| `test-ui.php` | jetons d'administration, styles en ligne, classes définies |
| `test-i18n.php` / `test-i18n-assets.php` | aucune chaîne française dans le code |
| `_bench/t-i18n.php` | le `.mo` chargé par le lecteur de WordPress |

**Faux positif connu** : le contrôle SQL de `test-conformite.php` cherche
`prepare` sur la même ligne que `$wpdb->get_results()`. Les huit requêtes de
cette extension sont écrites sur plusieurs lignes et ressortent en NOTE. Elles
sont toutes préparées.

---

## 8. Ce que le banc ne peut pas couvrir

Deux limites du banc, l'une connue de tout le chantier :

1. **L'échappement des jokers `LIKE`.** `WP_SQLite_DB::esc_like()` est
   délibérément un no-op sur l'ancien pilote. Toute assertion sur
   `esc_like()` passerait pour la mauvaise raison. Conséquence directe :
   **l'astérisque des clés de méta d'article n'est pas mesurable ici**, parce
   qu'il repose sur `NOT LIKE '\_%'`. Sans échappement le motif devient `_%`,
   qui veut dire « au moins un caractère » et attrape tout : la clause exclut
   alors la totalité des métas. Le banc le dit en NOTE plutôt que d'asserter.
2. **Les volumes.** Le banc porte quelques dizaines de commandes. Le plafond par
   source, les index et le coût réel d'un `LIKE '%terme%'` sur une table de
   métas ne se mesurent pas là-dessus.

---

## 9. À vérifier sur une vraie boutique

1. **L'astérisque des clés de méta d'article**, sur MySQL : poser `*`, chercher
   un nombre à deux chiffres, et vérifier que les lignes ne remontent pas
   toutes (ce serait le signe que les métas internes ne sont pas écartées).
2. **Les jokers `LIKE`** : taper `%` dans la recherche. La liste doit rester
   vide, pas renvoyer la boutique.
3. **Le volume.** Sur un catalogue réel, chronométrer une recherche avec toutes
   les sources actives. Si c'est lent, décocher les notes en premier : c'est la
   source la plus large et la moins précise.
4. **Le filtre « N° de commande »**, extension active puis désactivée, pour
   confirmer la mesure du §3.2 sur **votre** version de WooCommerce.
5. **Les deux magasins**, si vous pouvez basculer : les réponses doivent être
   les mêmes.
6. **Une commande B2B réelle** — société remplie, nom de facturation vide — pour
   vérifier qu'elle remonte bien en tête sur le nom exact de la société.
7. **Le tri par colonne** : cliquer « Date » pendant une recherche doit rendre
   l'ordre à WooCommerce, et non garder le classement par pertinence.
8. **La pagination** : une recherche qui rend plus d'une page doit avoir un
   total juste et une seconde page cohérente.
9. **Les captures** de la fiche wordpress.org : le `README.txt` en annonce deux,
   `.wordpress-org/screenshot-1.png` et `-2.png`.

---

## 10. La revue adverse — 9 octobre

Relecture par un agent qui n'avait pas écrit le code, consigne : trouver ce qui
fait voir les mauvaises commandes sans qu'on le sache. Douze défauts, dont deux
graves. Le banc passait 120/120 pendant ce temps.

### 10.1 Le natif était tronqué à dix commandes — et le total le disait complet

**Le pire, et il venait du correctif de la veille.** `wc_order_search()` avait
été retenu parce qu'il est juste sur les deux magasins. Il l'est — et sur les
tables de WooCommerce il passe par `wc_get_orders( array( 's' => … ) )` **sans
`limit`**, donc hérite du défaut de `WC_Object_Query` : `posts_per_page`, dix.

Mesuré sur ce banc : **10 identifiants rendus là où la boutique en a 72.**
L'union remplaçait la requête par cette liste tronquée, la liste affichait onze
commandes, annonçait « 11 éléments » et n'offrait pas de deuxième page. La règle
que cette extension s'était donnée — *ajouter, jamais substituer* — était violée
sur presque chaque recherche, puisqu'il suffit qu'une source trouve une ligne
pour déclencher le remplacement.

La mesure de la veille était juste et muette : deux commandes correspondaient,
dix est plus grand que deux, et le plafond ne s'est pas vu.

Corrigé : sur les tables de WooCommerce, les arguments de la table de liste
sont réemployés sans pagination — ce qui préserve aussi `search_filter`. Sur le
magasin historique, `wc_order_search()` reste le bon appel, sans plafond là-bas.

### 10.2 Le filtre « Clients » s'élargissait à tout

`native_ids()` ne passait aucun `search_filter`, et
`OrdersTableSearchQuery::sanitize_search_filters()` traduit l'absence de filtre
par **tous** les filtres. Choisir « Clients » pour réduire le bruit ramenait donc
des correspondances par nom de produit et par identifiant de transaction, rangées
parmi les résultats clients. Corrigé par 10.1.

### 10.3 Les autres

| | Défaut | Correctif |
|---|---|---|
| 3 | `normalise()` lisait `array( 1024 => '00123456' )` comme une liste d'identifiants dès que la raison était numérique : la commande 1024 disparaissait et une commande 123456 était inventée. L'exemple type de l'extension — une source de numéros de série — tombait dedans à chaque fois. | La forme est décidée une fois pour tout le tableau, pas entrée par entrée. |
| 4 | Sur le magasin historique, la requête de métas ne gardait pas les lignes qui sont des commandes : abonnements, remboursements et extensions tierces portent les mêmes clés, remplissaient le plafond, et les vraies commandes coupées ne revenaient jamais. Sans `ORDER BY`, le tri était en plus instable. | Jointure sur `wp_posts`, type filtrable, et un ordre déterministe. |
| 5 | Le filtre « N° de commande » employait `ctype_digit` là où WooCommerce emploie `is_numeric` : `15080.0` et `+15080` trouvaient une commande chez lui et rien ici. Et la correction court-circuitait les sources. | `is_numeric`, et la correction consulte les sources. |
| 6 | Un `orderby` périmé ou étranger dans une URL suffisait à couper le classement. | Seule une colonne que la liste sait trier compte. |
| 7 | Le plafond ne s'appliquait qu'aux requêtes intégrées : une source tierce sans `LIMIT` pouvait livrer une liste d'identifiants sans borne. | Le plafond vaut pour toute source. |
| 8 | Une troncature au plafond était silencieuse. | Elle est relevée, et lisible par `Dahu_Order_Search_Sources::truncated()`. |
| 11 | Une source décochée restait atteignable, exclusivement, par une URL restée dessus. | Le menu et le crochet emploient la même condition. |
| 12 | Le filtre « Clients » lançait la source adresses même décochée. | Il respecte la case. |
| 13 | Un terme trop court avec le filtre d'une source rendait « aucune commande » et supprimait la recherche native — l'inverse de ce que promet le réglage. | Le natif répond. |
| 14 | Un rang non numérique devenait 0, donc meilleur que tout. Une raison inutilisable faisait perdre la commande entière. | Les deux retombent sur le défaut de la source. |

### 10.4 Et ce que le banc a appris

Quatre assertions creuses, trouvées en écrivant les injections. La plus
instructive : « le filtre Clients ne s'élargit pas » se vérifiait sur une
commande que le filtre produits ne trouvait de toute façon pas — elle passait
avec ou sans le défaut. Elle se mesure maintenant au compte, contre un témoin
qui montre que des dizaines de commandes correspondent par le produit.

**120 → 142 assertions** (147 sur HPOS), **42 → 52 injections par magasin**.