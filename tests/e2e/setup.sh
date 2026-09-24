#!/usr/bin/env bash
# Monta un WordPress local con SQLite + WooCommerce + los 4 plugins y datos de prueba.
# Uso: bash tests/e2e/setup.sh /ruta/de/trabajo   (requiere php >= 7.4 con pdo_sqlite, git, curl, node + playwright)
set -e
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
W="${1:-/tmp/ts-wp}"; mkdir -p "$W/dl"; cd "$W"
[ -d wordpress ] || git clone --depth 1 --branch 6.8.2 https://github.com/WordPress/WordPress wordpress
[ -d dl/sqlite ] || git clone --depth 1 https://github.com/WordPress/sqlite-database-integration dl/sqlite
[ -f dl/woocommerce.zip ] || curl -sSL -o dl/woocommerce.zip https://github.com/woocommerce/woocommerce/releases/download/10.1.2/woocommerce.zip
[ -f wp-cli.phar ] || curl -sSL -o wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
WP="$W/wordpress"; P="$WP/wp-content/plugins"
# SQLite drop-in (layout monorepo del trunk)
rm -rf "$P/sqlite-database-integration"; cp -r dl/sqlite/packages/plugin-sqlite-database-integration "$P/sqlite-database-integration"
rm -rf "$P/sqlite-database-integration/wp-includes/database"; cp -r dl/sqlite/packages/mysql-on-sqlite/src "$P/sqlite-database-integration/wp-includes/database"
cp "$P/sqlite-database-integration/db.copy" "$WP/wp-content/db.php"
sed -i "s#'{SQLITE_IMPLEMENTATION_FOLDER_PATH}'#__DIR__.'/plugins/sqlite-database-integration'#g; s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" "$WP/wp-content/db.php"
# Plugins
( cd "$P" && unzip -q -o "$W/dl/woocommerce.zip" )
for z in "$REPO"/*.zip; do mkdir -p "$W/tmpz"; unzip -q -o "$z" -d "$W/tmpz"; done
cp -r "$W/tmpz"/* "$P/"; rm -rf "$W/tmpz"
ln -sfn "$REPO/total-sucursales" "$P/total-sucursales"
# Tema clásico (los temas de bloques no ejecutan el filtro de catálogo de MLI)
[ -d "$WP/wp-content/themes/twentytwentyone" ] || git clone -q --depth 1 https://github.com/WordPress/twentytwentyone "$WP/wp-content/themes/twentytwentyone"
cat > "$WP/wp-config.php" <<'CFG'
<?php
define( 'DB_NAME', 'wp' ); define( 'DB_USER', 'wp' ); define( 'DB_PASSWORD', 'wp' ); define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' ); $table_prefix = 'wp_';
define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', true ); define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_HOME', 'http://127.0.0.1:8080' ); define( 'WP_SITEURL', 'http://127.0.0.1:8080' );
define( 'DISABLE_WP_CRON', true ); define( 'AUTOMATIC_UPDATER_DISABLED', true ); define( 'FS_METHOD', 'direct' );
foreach ( array( 'AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT' ) as $k ) { define( $k, $k . '-dev' ); }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
CFG
mkdir -p "$WP/wp-content/database"
wp(){ php "$W/wp-cli.phar" --allow-root --path="$WP" "$@"; }
wp core is-installed 2>/dev/null || wp core install --url=http://127.0.0.1:8080 --title="Total Sucursales Test" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
wp theme activate twentytwentyone
wp plugin activate woocommerce
wp plugin activate states-and-municipalities-of-venezuela-for-woocommerce woocommerce-advanced-shipping WooCommerce-Multi-Locations-Inventory-Management total-sucursales
wp eval-file "$REPO/tests/e2e/seed.php"
wp option update woocommerce_hold_stock_minutes ""   # la reserva de stock usa SQL que SQLite no soporta
ID=$(wp post create --post_type=page --post_title="Inicio" --post_status=publish --porcelain --post_content='<h2>Bienvenido</h2>[ts_selector_estado][wcmlim_locations_switch]<p><a href="/shop/">Ir a la tienda</a></p>')
wp option update show_on_front page; wp option update page_on_front "$ID"
cp "$REPO"/tests/e2e/*.js "$REPO"/tests/e2e/*.php "$REPO/tests/e2e/run-all.sh" "$W/"   # las pruebas se corren desde aquí
echo "Listo. Arranca:  cd $W && php -S 127.0.0.1:8080 -t wordpress router.php"
echo "Pruebas clásico: cd $W && node e2e.js"
echo "Pruebas bloques: wp theme activate twentytwentyfive && wp eval-file pages.php blocks && node e2e-blocks.js"
echo "Toda la batería: cd $W && bash run-all.sh"
