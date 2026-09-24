#!/usr/bin/env bash
#
# Seeds the catalogue and users tests/probe/probe.py needs, idempotently.
#
# Everything is matched by SKU (products), slug (terms) or login (users), so a
# rerun updates in place and puts every fixture back to its seeded value; it
# never duplicates. Products are written through the WooCommerce CRUD classes
# under `wp eval`, never as raw post meta, so lookup tables, variation sync and
# term counts are WooCommerce's own.
#
# The fixed probe catalogue (category "Probe Catalogue" and its children):
#   10 simple products  bulkcat-probe-simple-01 .. -10   regular price 11.00 .. 20.00,
#                       stock managed, quantity 3 .. 30, odd in Probe Shirts,
#                       even in Probe Hats, 01-03 tagged bulkcat-probe-tag
#   1 variable product  bulkcat-probe-variable with Size Small/Medium/Large
#                       (bulkcat-probe-variable-small ..) at 30.00 / 31.00 / 32.00
#   1 draft product     bulkcat-probe-draft at 5.00
# Users (password $PROBE_PASSWORD): bulkcat_probe_admin (administrator),
# bulkcat_probe_manager (shop_manager), bulkcat_probe_subscriber (subscriber).
#
# Scale catalogue (T76): pass a product count as the first argument and the
# seeder adds that many products under category "Scale Catalogue", SKUs
# bulkcat-scale-00001 ..; every second one is variable with 7 variations, so
# `seed.sh 20000` builds 20,000 products and 70,000 variations. Scale products
# are bulk-inserted in runs of $SCALE_CHUNK per `wp eval`, one transaction
# each, as the exact rows WooCommerce's CRUD classes write for them (CRUD
# saves run at roughly 20 objects a second, which is hours at this size); a
# product whose SKU already exists is left alone, so an interrupted build
# resumes where it stopped and a finished one is reused between runs. A last
# run reads a sample back through wc_get_product() and fails the seed if
# WooCommerce sees anything other than what was meant. 20,000 products take
# about two minutes on the docker stack of tests/probe/README.md.
#
# Usage:
#   bash tests/probe/seed.sh [SCALE]
#   WP_CMD="npx wp-env run cli wp" bash tests/probe/seed.sh                  # wp-env
#   WP_CMD="docker run --rm -i ... wordpress:cli-php8.3 wp" bash tests/probe/seed.sh
#     (the throwaway docker stack in tests/probe/README.md)
#
# Environment:
#   WP_CMD          the WP-CLI invocation. Default: wp
#   SEED_JSON       where to write the fixture description. Default: tests/probe/seed.json (gitignored)
#   PROBE_PASSWORD  the probe users' password. Default: bulkcat-probe-password (throwaway sites only)
#   SCALE_CHUNK     scale products per wp eval run. Default: 1000
#
set -euo pipefail

WP_CMD=${WP_CMD:-wp}
SEED_JSON=${SEED_JSON:-"$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/seed.json"}
PROBE_PASSWORD=${PROBE_PASSWORD:-bulkcat-probe-password}
SCALE=${1:-0}
SCALE_CHUNK=${SCALE_CHUNK:-1000}

case "$SCALE" in
	'' | *[!0-9]*) echo "seed.sh: SCALE must be a whole number of products, got '$SCALE'" >&2; exit 2 ;;
esac

# Word-split WP_CMD deliberately: it is a command line, not a single binary.
wpcli() {
	# shellcheck disable=SC2086
	$WP_CMD "$@"
}

wpcli plugin is-active woocommerce >/dev/null 2>&1 || wpcli plugin activate woocommerce >/dev/null
wpcli plugin is-active bulk-catalogue-manager-for-woocommerce >/dev/null 2>&1 || wpcli plugin activate bulk-catalogue-manager-for-woocommerce >/dev/null
wpcli rewrite structure '/%postname%/' >/dev/null
wpcli option update woocommerce_coming_soon no >/dev/null

for spec in bulkcat_probe_admin:administrator bulkcat_probe_manager:shop_manager bulkcat_probe_subscriber:subscriber; do
	login=${spec%%:*}
	role=${spec#*:}
	if wpcli user get "$login" --field=ID >/dev/null 2>&1; then
		wpcli user update "$login" --role="$role" --user_pass="$PROBE_PASSWORD" --skip-email >/dev/null
	else
		wpcli user create "$login" "$login@example.test" --role="$role" --user_pass="$PROBE_PASSWORD" >/dev/null
	fi
done

SEED_PHP=$(cat <<'PHP'
$term = function ( string $taxonomy, string $slug, string $name, int $parent = 0 ): int {
	$found = get_term_by( 'slug', $slug, $taxonomy );
	if ( $found ) {
		wp_update_term( $found->term_id, $taxonomy, array( 'name' => $name, 'parent' => $parent ) );
		return (int) $found->term_id;
	}
	$made = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug, 'parent' => $parent ) );
	if ( is_wp_error( $made ) ) {
		throw new RuntimeException( $made->get_error_message() );
	}
	return (int) $made['term_id'];
};

$root   = $term( 'product_cat', 'bulkcat-probe', 'Probe Catalogue' );
$shirts = $term( 'product_cat', 'bulkcat-probe-shirts', 'Probe Shirts', $root );
$hats   = $term( 'product_cat', 'bulkcat-probe-hats', 'Probe Hats', $root );
$tag    = $term( 'product_tag', 'bulkcat-probe-tag', 'Probe Tag' );

$upsert = function ( string $class, string $sku ) {
	$id = wc_get_product_id_by_sku( $sku );
	$product = $id ? wc_get_product( $id ) : null;
	if ( ! $product || ! is_a( $product, $class ) ) {
		$product = new $class();
	}
	$product->set_sku( $sku );
	return $product;
};

$simple = array();
for ( $i = 1; $i <= 10; $i++ ) {
	$sku     = sprintf( 'bulkcat-probe-simple-%02d', $i );
	$product = $upsert( 'WC_Product_Simple', $sku );
	$price   = number_format( 10 + $i, 2, '.', '' );
	$product->set_name( sprintf( 'Probe Simple %02d', $i ) );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_featured( false );
	$product->set_regular_price( $price );
	$product->set_sale_price( '' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( $i * 3 );
	$product->set_weight( '' );
	$product->set_category_ids( array( $i % 2 ? $shirts : $hats ) );
	$product->set_tag_ids( $i <= 3 ? array( $tag ) : array() );
	$id       = $product->save();
	$simple[] = array( 'id' => (int) $id, 'sku' => $sku, 'name' => $product->get_name(), 'regular_price' => $price, 'stock_quantity' => $i * 3 );
}

$variable  = $upsert( 'WC_Product_Variable', 'bulkcat-probe-variable' );
$attribute = new WC_Product_Attribute();
$attribute->set_name( 'Size' );
$attribute->set_options( array( 'Small', 'Medium', 'Large' ) );
$attribute->set_position( 0 );
$attribute->set_visible( true );
$attribute->set_variation( true );
$variable->set_name( 'Probe Variable' );
$variable->set_status( 'publish' );
$variable->set_catalog_visibility( 'visible' );
$variable->set_attributes( array( $attribute ) );
$variable->set_category_ids( array( $shirts ) );
$variable->set_tag_ids( array() );
$variable_id = (int) $variable->save();

$variations = array();
foreach ( array( 'Small' => '30.00', 'Medium' => '31.00', 'Large' => '32.00' ) as $option => $price ) {
	$vsku      = 'bulkcat-probe-variable-' . strtolower( $option );
	$variation = $upsert( 'WC_Product_Variation', $vsku );
	$variation->set_parent_id( $variable_id );
	$variation->set_status( 'publish' );
	$variation->set_attributes( array( 'size' => $option ) );
	$variation->set_regular_price( $price );
	$variation->set_sale_price( '' );
	$variation->set_manage_stock( false );
	$variation->set_stock_status( 'instock' );
	$variations[] = array( 'id' => (int) $variation->save(), 'sku' => $vsku, 'regular_price' => $price );
}
WC_Product_Variable::sync( $variable_id );

$draft = $upsert( 'WC_Product_Simple', 'bulkcat-probe-draft' );
$draft->set_name( 'Probe Draft' );
$draft->set_status( 'draft' );
$draft->set_regular_price( '5.00' );
$draft->set_sale_price( '' );
$draft->set_category_ids( array( $root ) );
$draft->set_tag_ids( array() );
$draft_id = (int) $draft->save();

$users = array();
foreach ( array( 'admin' => 'bulkcat_probe_admin', 'manager' => 'bulkcat_probe_manager', 'subscriber' => 'bulkcat_probe_subscriber' ) as $role => $login ) {
	$users[ $role ] = array( 'login' => $login, 'id' => (int) get_user_by( 'login', $login )->ID );
}

$seed = array(
	'categories' => array( 'root' => $root, 'shirts' => $shirts, 'hats' => $hats ),
	'tag'        => $tag,
	'users'      => $users,
	'simple'     => $simple,
	'variable'   => array( 'id' => $variable_id, 'sku' => 'bulkcat-probe-variable', 'variations' => $variations ),
	'draft'      => array( 'id' => $draft_id, 'sku' => 'bulkcat-probe-draft' ),
);
echo "BULKCAT_SEED_BEGIN\n" . wp_json_encode( $seed ) . "\nBULKCAT_SEED_END\n";
PHP
)

# One scale run: inserts the missing products numbered FROM .. TO, and their
# variations, as multi-row INSERTs inside one transaction. The rows are the
# ones WooCommerce 11.1's CRUD classes write for these products (posts,
# postmeta, product_type and product_cat relationships, wc_product_meta_lookup),
# checked against wc_get_product() by the verification run below; the fixed
# probe catalogue above still goes through CRUD. A run either commits all of
# its products or none, so an interrupted build resumes cleanly.
SCALE_PHP=$(cat <<'PHP'
global $wpdb;
list( $from, $to ) = array_map( 'intval', explode( ':', $bulkcat_scale_range ) );
$found = get_term_by( 'slug', 'bulkcat-scale', 'product_cat' );
$cat   = $found ? (int) $found->term_id : (int) wp_insert_term( 'Scale Catalogue', 'product_cat', array( 'slug' => 'bulkcat-scale' ) )['term_id'];
$tt    = array(
	'cat'      => (int) get_term( $cat, 'product_cat' )->term_taxonomy_id,
	'simple'   => (int) get_term_by( 'slug', 'simple', 'product_type' )->term_taxonomy_id,
	'variable' => (int) get_term_by( 'slug', 'variable', 'product_type' )->term_taxonomy_id,
);
$skus = array();
for ( $n = $from; $n <= $to; $n++ ) {
	$skus[ $n ] = sprintf( 'bulkcat-scale-%05d', $n );
}
$have = array_flip( $wpdb->get_col( "SELECT sku FROM {$wpdb->wc_product_meta_lookup} WHERE sku IN ('" . implode( "','", array_map( 'esc_sql', $skus ) ) . "')" ) );
$todo = array_diff_key( $skus, array_flip( array_map( fn( $s ) => (int) substr( $s, 10 ), array_keys( $have ) ) ) );
if ( ! $todo ) {
	echo "BULKCAT_SCALE_MADE 0\n";
	return;
}

$insert = function ( string $table, array $columns, array $rows ) use ( $wpdb ): void {
	foreach ( array_chunk( $rows, 1000 ) as $part ) {
		$values = array();
		foreach ( $part as $row ) {
			$values[] = '(' . implode( ',', array_map( fn( $v ) => null === $v ? 'NULL' : "'" . esc_sql( (string) $v ) . "'", $row ) ) . ')';
		}
		$sql = "INSERT INTO {$table} (`" . implode( '`,`', $columns ) . '`) VALUES ' . implode( ',', $values );
		if ( false === $wpdb->query( $sql ) ) {
			throw new RuntimeException( $wpdb->last_error );
		}
	}
};
$now     = current_time( 'mysql' );
$now_gmt = current_time( 'mysql', true );
$post    = function ( string $type, int $parent, string $title, string $slug, string $excerpt, string $comments ) use ( $now, $now_gmt ): array {
	return array( 0, $now, $now_gmt, '', $title, $excerpt, 'publish', $comments, 'closed', $slug, '', '', $now, $now_gmt, '', $parent, '', 0, $type );
};
$post_columns = array( 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent', 'guid', 'menu_order', 'post_type' );
$options      = array( 'A', 'B', 'C', 'D', 'E', 'F', 'G' );
$attributes   = serialize( array( 'option' => array( 'name' => 'Option', 'value' => implode( ' | ', $options ), 'position' => 0, 'is_visible' => 1, 'is_variation' => 1, 'is_taxonomy' => 0 ) ) );
$version      = WC_VERSION;

$wpdb->query( 'START TRANSACTION' );

// Parents.
$rows = array();
foreach ( $todo as $n => $sku ) {
	$rows[] = $post( 'product', 0, sprintf( 'Scale Product %05d', $n ), sprintf( 'scale-product-%05d', $n ), '', 'open' );
}
$insert( $wpdb->posts, $post_columns, $rows );
$names  = array_map( fn( $n ) => sprintf( 'scale-product-%05d', $n ), array_keys( $todo ) );
$parent = array();
foreach ( $wpdb->get_results( "SELECT ID, post_name FROM {$wpdb->posts} WHERE post_type = 'product' AND post_name IN ('" . implode( "','", $names ) . "')" ) as $row ) {
	$parent[ (int) substr( $row->post_name, 14 ) ] = (int) $row->ID;
}

// Variations of every even (variable) product.
$rows = array();
foreach ( $todo as $n => $sku ) {
	if ( 0 !== $n % 2 ) {
		continue;
	}
	foreach ( $options as $option ) {
		$rows[] = $post( 'product_variation', $parent[ $n ], sprintf( 'Scale Product %05d - %s', $n, $option ), sprintf( 'scale-product-%05d-%s', $n, strtolower( $option ) ), 'Option: ' . $option, 'closed' );
	}
}
$insert( $wpdb->posts, $post_columns, $rows );
$variation = array();
if ( $parent ) {
	foreach ( $wpdb->get_results( "SELECT ID, post_parent, post_name FROM {$wpdb->posts} WHERE post_type = 'product_variation' AND post_parent IN (" . implode( ',', $parent ) . ')' ) as $row ) {
		$variation[ (int) $row->post_parent ][ strtoupper( substr( $row->post_name, -1 ) ) ] = (int) $row->ID;
	}
}
$wpdb->query( "UPDATE {$wpdb->posts} SET guid = CONCAT('" . esc_sql( home_url( '/' ) ) . "?post_type=', post_type, '&p=', ID) WHERE guid = '' AND post_name LIKE 'scale-product-%'" );

// Meta, relationships and lookup rows, in the shapes CRUD writes.
$common = function ( string $manage, string $stock, string $status, string $tax_class ): array {
	return array( 'total_sales' => '0', '_tax_status' => 'taxable', '_tax_class' => $tax_class, '_manage_stock' => $manage, '_backorders' => 'no', '_sold_individually' => 'no', '_virtual' => 'no', '_downloadable' => 'no', '_download_limit' => '-1', '_download_expiry' => '-1', '_stock' => $stock, '_stock_status' => $status, '_wc_average_rating' => '0', '_wc_review_count' => '0' );
};
$lookup = function ( int $id, string $sku, string $min, string $max, $stock, string $tax_class ): array {
	return array( $id, $sku, '', 0, 0, $min, $max, 0, $stock, 'instock', 0, '0.00', 0, 'taxable', $tax_class );
};
$meta = array();
$rels = array();
$look = array();
foreach ( $todo as $n => $sku ) {
	$id = $parent[ $n ];
	$rels[] = array( $id, $tt['cat'], 0 );
	if ( 0 !== $n % 2 ) {
		$price = number_format( 5 + ( $n % 90 ), 2, '.', '' );
		$stock = (string) ( $n % 40 );
		$pairs = array( '_sku' => $sku, '_regular_price' => $price ) + $common( 'yes', $stock, 'instock', '' ) + array( '_product_version' => $version, '_price' => $price );
		foreach ( $pairs as $key => $value ) {
			$meta[] = array( $id, $key, $value );
		}
		$rels[] = array( $id, $tt['simple'], 0 );
		$look[] = $lookup( $id, $sku, $price, $price, $stock, '' );
		continue;
	}
	$prices = array();
	foreach ( $options as $k => $option ) {
		$vid      = $variation[ $id ][ $option ];
		$vsku     = $sku . '-' . strtolower( $option );
		$price    = number_format( 5 + ( $n % 50 ) + $k, 2, '.', '' );
		$prices[] = $price;
		$pairs    = array( '_variation_description' => '', '_sku' => $vsku, '_regular_price' => $price ) + $common( 'no', '', 'instock', 'parent' ) + array( 'attribute_option' => $option, '_price' => $price, '_product_version' => $version );
		foreach ( $pairs as $key => $value ) {
			$meta[] = array( $vid, $key, $value );
		}
		$look[] = $lookup( $vid, $vsku, $price, $price, null, 'parent' );
	}
	$pairs = array( '_sku' => $sku ) + $common( 'no', '', 'instock', '' ) + array( '_product_attributes' => $attributes, '_product_version' => $version );
	foreach ( $pairs as $key => $value ) {
		$meta[] = array( $id, $key, $value );
	}
	foreach ( $prices as $price ) {
		$meta[] = array( $id, '_price', $price );
	}
	$rels[] = array( $id, $tt['variable'], 0 );
	$look[] = $lookup( $id, $sku, min( $prices ), max( $prices ), null, '' );
}
$insert( $wpdb->postmeta, array( 'post_id', 'meta_key', 'meta_value' ), $meta );
$insert( $wpdb->term_relationships, array( 'object_id', 'term_taxonomy_id', 'term_order' ), $rels );
$insert( $wpdb->wc_product_meta_lookup, array( 'product_id', 'sku', 'global_unique_id', 'virtual', 'downloadable', 'min_price', 'max_price', 'onsale', 'stock_quantity', 'stock_status', 'rating_count', 'average_rating', 'total_sales', 'tax_status', 'tax_class' ), $look );
$wpdb->query( 'COMMIT' );

wp_update_term_count_now( array_values( $tt ), 'product_cat' );
wp_update_term_count_now( array( $tt['simple'], $tt['variable'] ), 'product_type' );
wc_delete_product_transients();
echo 'BULKCAT_SCALE_MADE ' . count( $todo ) . "\n";
PHP
)

# Reads the scale catalogue back through WooCommerce: counts, and a sample of
# simple and variable products against the values the insert meant.
VERIFY_PHP=$(cat <<'PHP'
global $wpdb;
$scale    = (int) $bulkcat_scale;
$problems = array();
$products = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->wc_product_meta_lookup} l JOIN {$wpdb->posts} p ON p.ID = l.product_id WHERE p.post_type = 'product' AND l.sku LIKE 'bulkcat-scale-%'" );
$children = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->wc_product_meta_lookup} l JOIN {$wpdb->posts} p ON p.ID = l.product_id WHERE p.post_type = 'product_variation' AND l.sku LIKE 'bulkcat-scale-%'" );
if ( $products < $scale || $children < 7 * intdiv( $scale, 2 ) ) {
	$problems[] = "counts $products products, $children variations";
}
foreach ( array_unique( array( 1, 2, $scale - 1, $scale ) ) as $n ) {
	if ( $n < 1 ) {
		continue;
	}
	$product = wc_get_product( wc_get_product_id_by_sku( sprintf( 'bulkcat-scale-%05d', $n ) ) );
	if ( ! $product || $product->get_category_ids() === array() ) {
		$problems[] = "product $n unreadable";
		continue;
	}
	if ( 0 === $n % 2 ) {
		$prices = $product->get_variation_prices();
		$want   = number_format( 5 + ( $n % 50 ), 2, '.', '' );
		if ( ! $product->is_type( 'variable' ) || 7 !== count( $product->get_children() ) || $want !== number_format( (float) min( $prices['price'] ), 2, '.', '' ) ) {
			$problems[] = "variable $n: " . $product->get_type() . ' ' . count( $product->get_children() ) . ' children';
		}
		$first = wc_get_product( $product->get_children()[0] );
		if ( ! $first || 'A' !== $first->get_attribute( 'option' ) || $want !== $first->get_regular_price() ) {
			$problems[] = "variation of $n";
		}
	} elseif ( ! $product->is_type( 'simple' ) || number_format( 5 + ( $n % 90 ), 2, '.', '' ) !== $product->get_regular_price() || $n % 40 !== $product->get_stock_quantity() ) {
		$problems[] = "simple $n";
	}
}
echo $problems ? 'BULKCAT_SCALE_BAD ' . implode( '; ', $problems ) . "\n" : "BULKCAT_SCALE_OK $products $children\n";
PHP
)

if [ "$SCALE" -gt 0 ]; then
	start=1
	while [ "$start" -le "$SCALE" ]; do
		end=$((start + SCALE_CHUNK - 1))
		[ "$end" -gt "$SCALE" ] && end=$SCALE
		# The range travels as a PHP prefix: WP_CMD may not pass the environment through.
		made=$(wpcli eval "\$bulkcat_scale_range = '$start:$end'; $SCALE_PHP" | sed -n 's/^BULKCAT_SCALE_MADE //p')
		echo "seed.sh: scale $start-$end: created ${made:-0}" >&2
		start=$((end + 1))
	done
	checked=$(wpcli eval "\$bulkcat_scale = $SCALE; $VERIFY_PHP")
	case "$checked" in
		*BULKCAT_SCALE_OK*) echo "seed.sh: scale catalogue verified: ${checked#*BULKCAT_SCALE_OK }" >&2 ;;
		*) echo "seed.sh: scale catalogue does not read back as seeded: $checked" >&2; exit 1 ;;
	esac
fi

RAW=$(wpcli eval "$SEED_PHP")
BODY=$(printf '%s\n' "$RAW" | sed -n '/BULKCAT_SEED_BEGIN/,/BULKCAT_SEED_END/p' | sed '1d;$d')
if [ -z "$BODY" ]; then
	echo "seed.sh: WP-CLI produced no seed JSON; raw output was:" >&2
	printf '%s\n' "$RAW" >&2
	exit 1
fi

BULKCAT_SEED_BODY=$BODY python3 - "$SEED_JSON" "$PROBE_PASSWORD" "$SCALE" <<'PY'
import json, os, sys
path, password, scale = sys.argv[1], sys.argv[2], int(sys.argv[3])
seed = json.loads(os.environ["BULKCAT_SEED_BODY"])
seed["password"] = password
seed["scale"] = scale
with open(path, "w", encoding="utf-8") as handle:
    json.dump(seed, handle, indent=1)
    handle.write("\n")
PY

echo "seed.sh: wrote $SEED_JSON" >&2
