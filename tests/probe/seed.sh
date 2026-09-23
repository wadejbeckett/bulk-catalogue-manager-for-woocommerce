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
#   10 simple products  bcm-probe-simple-01 .. -10   regular price 11.00 .. 20.00,
#                       stock managed, quantity 3 .. 30, odd in Probe Shirts,
#                       even in Probe Hats, 01-03 tagged bcm-probe-tag
#   1 variable product  bcm-probe-variable with Size Small/Medium/Large
#                       (bcm-probe-variable-small ..) at 30.00 / 31.00 / 32.00
#   1 draft product     bcm-probe-draft at 5.00
# Users (password $PROBE_PASSWORD): bcm_probe_admin (administrator),
# bcm_probe_manager (shop_manager), bcm_probe_subscriber (subscriber).
#
# Scale catalogue (T76): pass a product count as the first argument and the
# seeder adds that many products under category "Scale Catalogue", SKUs
# bcm-scale-00001 ..; every second one is variable with 7 variations, so
# `seed.sh 20000` builds 20,000 products and 70,000 variations. Scale products
# are created in runs of $SCALE_CHUNK per `wp eval` (bounded memory) and a
# product whose SKU already exists is left alone, so an interrupted build
# resumes where it stopped and a finished one is reused between runs.
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
#   PROBE_PASSWORD  the probe users' password. Default: bcm-probe-password (throwaway sites only)
#   SCALE_CHUNK     scale products per wp eval run. Default: 200
#
set -euo pipefail

WP_CMD=${WP_CMD:-wp}
SEED_JSON=${SEED_JSON:-"$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/seed.json"}
PROBE_PASSWORD=${PROBE_PASSWORD:-bcm-probe-password}
SCALE=${1:-0}
SCALE_CHUNK=${SCALE_CHUNK:-200}

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

for spec in bcm_probe_admin:administrator bcm_probe_manager:shop_manager bcm_probe_subscriber:subscriber; do
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

$root   = $term( 'product_cat', 'bcm-probe', 'Probe Catalogue' );
$shirts = $term( 'product_cat', 'bcm-probe-shirts', 'Probe Shirts', $root );
$hats   = $term( 'product_cat', 'bcm-probe-hats', 'Probe Hats', $root );
$tag    = $term( 'product_tag', 'bcm-probe-tag', 'Probe Tag' );

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
	$sku     = sprintf( 'bcm-probe-simple-%02d', $i );
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

$variable  = $upsert( 'WC_Product_Variable', 'bcm-probe-variable' );
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
	$vsku      = 'bcm-probe-variable-' . strtolower( $option );
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

$draft = $upsert( 'WC_Product_Simple', 'bcm-probe-draft' );
$draft->set_name( 'Probe Draft' );
$draft->set_status( 'draft' );
$draft->set_regular_price( '5.00' );
$draft->set_sale_price( '' );
$draft->set_category_ids( array( $root ) );
$draft->set_tag_ids( array() );
$draft_id = (int) $draft->save();

$users = array();
foreach ( array( 'admin' => 'bcm_probe_admin', 'manager' => 'bcm_probe_manager', 'subscriber' => 'bcm_probe_subscriber' ) as $role => $login ) {
	$users[ $role ] = array( 'login' => $login, 'id' => (int) get_user_by( 'login', $login )->ID );
}

$seed = array(
	'categories' => array( 'root' => $root, 'shirts' => $shirts, 'hats' => $hats ),
	'tag'        => $tag,
	'users'      => $users,
	'simple'     => $simple,
	'variable'   => array( 'id' => $variable_id, 'sku' => 'bcm-probe-variable', 'variations' => $variations ),
	'draft'      => array( 'id' => $draft_id, 'sku' => 'bcm-probe-draft' ),
);
echo "BCM_SEED_BEGIN\n" . wp_json_encode( $seed ) . "\nBCM_SEED_END\n";
PHP
)

# One scale run: creates the missing products numbered FROM .. TO.
SCALE_PHP=$(cat <<'PHP'
list( $from, $to ) = array_map( 'intval', explode( ':', $bcm_scale_range ) );
$found = get_term_by( 'slug', 'bcm-scale', 'product_cat' );
$cat   = $found ? (int) $found->term_id : (int) wp_insert_term( 'Scale Catalogue', 'product_cat', array( 'slug' => 'bcm-scale' ) )['term_id'];
wp_defer_term_counting( true );
$made = 0;
for ( $n = $from; $n <= $to; $n++ ) {
	$sku = sprintf( 'bcm-scale-%05d', $n );
	if ( wc_get_product_id_by_sku( $sku ) ) {
		continue;
	}
	$variable = 0 === $n % 2;
	$product  = $variable ? new WC_Product_Variable() : new WC_Product_Simple();
	$product->set_sku( $sku );
	$product->set_name( sprintf( 'Scale Product %05d', $n ) );
	$product->set_status( 'publish' );
	$product->set_category_ids( array( $cat ) );
	if ( $variable ) {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Option' );
		$attribute->set_options( array( 'A', 'B', 'C', 'D', 'E', 'F', 'G' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$parent = (int) $product->save();
		foreach ( array( 'A', 'B', 'C', 'D', 'E', 'F', 'G' ) as $k => $option ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent );
			$variation->set_sku( $sku . '-' . strtolower( $option ) );
			$variation->set_attributes( array( 'option' => $option ) );
			$variation->set_regular_price( number_format( 5 + ( $n % 50 ) + $k, 2, '.', '' ) );
			$variation->save();
		}
		WC_Product_Variable::sync( $parent );
	} else {
		$product->set_regular_price( number_format( 5 + ( $n % 90 ), 2, '.', '' ) );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $n % 40 );
		$product->save();
	}
	++$made;
}
wp_defer_term_counting( false );
echo "BCM_SCALE_MADE $made\n";
PHP
)

if [ "$SCALE" -gt 0 ]; then
	start=1
	while [ "$start" -le "$SCALE" ]; do
		end=$((start + SCALE_CHUNK - 1))
		[ "$end" -gt "$SCALE" ] && end=$SCALE
		# The range travels as a PHP prefix: WP_CMD may not pass the environment through.
		made=$(wpcli eval "\$bcm_scale_range = '$start:$end'; $SCALE_PHP" | sed -n 's/^BCM_SCALE_MADE //p')
		echo "seed.sh: scale $start-$end: created ${made:-0}" >&2
		start=$((end + 1))
	done
fi

RAW=$(wpcli eval "$SEED_PHP")
BODY=$(printf '%s\n' "$RAW" | sed -n '/BCM_SEED_BEGIN/,/BCM_SEED_END/p' | sed '1d;$d')
if [ -z "$BODY" ]; then
	echo "seed.sh: WP-CLI produced no seed JSON; raw output was:" >&2
	printf '%s\n' "$RAW" >&2
	exit 1
fi

BCM_SEED_BODY=$BODY python3 - "$SEED_JSON" "$PROBE_PASSWORD" "$SCALE" <<'PY'
import json, os, sys
path, password, scale = sys.argv[1], sys.argv[2], int(sys.argv[3])
seed = json.loads(os.environ["BCM_SEED_BODY"])
seed["password"] = password
seed["scale"] = scale
with open(path, "w", encoding="utf-8") as handle:
    json.dump(seed, handle, indent=1)
    handle.write("\n")
PY

echo "seed.sh: wrote $SEED_JSON" >&2
