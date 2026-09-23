# Seed and probe

`seed.sh` builds the probe catalogue and users on a WordPress + WooCommerce
site through WP-CLI; `probe.py` then checks every surface live and prints one
PASS/FAIL/SKIP row per surface (`python3 tests/probe/probe.py --help` lists the
chapters and how to add a surface). Both take the WP-CLI invocation in
`WP_CMD`.

## With wp-env (CI; needs Node 20 and Docker Compose v2)

```sh
npm install -g @wordpress/env@11.16.0   # the version CI pins; not a project dependency
npx wp-env start
WP_CMD="npx wp-env run cli wp" bash tests/probe/seed.sh
WP_CMD="npx wp-env run cli wp" python3 tests/probe/probe.py --base-url http://localhost:8888
```

`.wp-env.json` pins WordPress 7.1 and WooCommerce 11.1.0. CI runs each
WordPress and WooCommerce pair of its matrix by writing a gitignored
`.wp-env.override.json` with `core` and `plugins`, which wp-env merges over it;
the same file works locally.

## With a throwaway docker stack (no Node, no compose)

The local substitute for wp-env on a workstation without Node 20 or without
the Docker Compose v2 plugin (wp-env shells out to `docker compose`): the official
`wordpress:php8.3-apache`, `mariadb:11` and `wordpress:cli-php8.3` images on a
user-defined network, the repository bind-mounted read-only as the plugin.
Nothing here is committed as a compose file on purpose.

Bring it up (from the repository root):

```sh
docker network create bcm-probe
docker volume create bcm-probe-wp
docker run -d --name bcm-probe-db --network bcm-probe \
  -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wordpress \
  -e MARIADB_USER=wordpress -e MARIADB_PASSWORD=wordpress mariadb:11
docker run -d --name bcm-probe-wp --network bcm-probe -p 127.0.0.1:8888:80 \
  -e WORDPRESS_DB_HOST=bcm-probe-db -e WORDPRESS_DB_USER=wordpress \
  -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress -e WORDPRESS_DEBUG=1 \
  -v bcm-probe-wp:/var/www/html \
  -v "$PWD":/var/www/html/wp-content/plugins/bulk-catalogue-manager-for-woocommerce:ro \
  wordpress:php8.3-apache
export WP_CMD="docker run --rm -i --network bcm-probe --user 33:33 -e HOME=/tmp \
  -e WORDPRESS_DB_HOST=bcm-probe-db -e WORDPRESS_DB_USER=wordpress \
  -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress -e WORDPRESS_DEBUG=1 \
  -v bcm-probe-wp:/var/www/html \
  -v $PWD:/var/www/html/wp-content/plugins/bulk-catalogue-manager-for-woocommerce:ro \
  wordpress:cli-php8.3 wp"
sleep 15   # the first start copies WordPress into the volume
$WP_CMD core install --url=http://localhost:8888 --title=BCM --admin_user=admin \
  --admin_password=password --admin_email=admin@example.com --skip-email
$WP_CMD plugin install woocommerce --version=11.1.0 --activate
```

`WP_CMD` contains `$PWD`, so export it from the repository root (the path must
not contain spaces). The read-only mount matters: `wp plugin uninstall` in the
lifecycle chapter always passes `--skip-delete`, and the mount is a second
guard against deleting the working copy.

Seed and probe (the one-line invocation, after the export above):

```sh
bash tests/probe/seed.sh && python3 tests/probe/probe.py --base-url http://localhost:8888
```

`seed.sh 20000` adds the T76 scale catalogue (20,000 products, 70,000
variations) in resumable runs. The lifecycle chapter uninstalls and reactivates
the plugin; run `seed.sh` again before the next probe run.

Tear it down:

```sh
docker rm -f -v bcm-probe-wp bcm-probe-db
docker volume rm bcm-probe-wp
docker network rm bcm-probe
```

`-v` also removes the anonymous volume the `mariadb` image creates for its
data, so nothing is left behind.
