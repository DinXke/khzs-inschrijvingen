#!/usr/bin/env bash
# Zet een lokale WordPress-testsite op met de KHZS-plugin en de testhulp, en start ze.
#   wordpress/tests/testsite.sh [map] [poort]      standaard: ./.testsite  8080
# Vereist: php (met mysqli, zip, xml, openssl), composer, mariadb-server.
# Daarna: KHZS_URL=http://localhost:8080 python3 wordpress/tests/test_e2e.py
set -euo pipefail
HIER="$(cd "$(dirname "$0")" && pwd)"
PLUGIN="$(cd "$HIER/../khzs" && pwd)"
MAP="$(mkdir -p "${1:-$HIER/../../.testsite}" && cd "${1:-$HIER/../../.testsite}" && pwd)"
POORT="${2:-8080}"
DB="khzs_test_$POORT"
SOCK="/run/mysqld/khzs-$POORT.sock"

# MariaDB (eigen datamap, enkel lokaal)
mkdir -p /run/mysqld "$MAP/mysql"
if [ ! -d "$MAP/mysql/mysql" ]; then mysql_install_db --user=root --datadir="$MAP/mysql" >/dev/null; fi
if ! [ -S "$SOCK" ]; then
  nohup mariadbd --user=root --datadir="$MAP/mysql" --socket="$SOCK" --port=$((POORT + 10000)) --bind-address=127.0.0.1 >"$MAP/mysql.log" 2>&1 &
  for _ in $(seq 30); do [ -S "$SOCK" ] && break; sleep 1; done
fi
mysql -uroot --socket="$SOCK" -e "CREATE DATABASE IF NOT EXISTS $DB; CREATE USER IF NOT EXISTS 'wp'@'127.0.0.1' IDENTIFIED BY 'wp'; GRANT ALL ON $DB.* TO 'wp'@'127.0.0.1';"

# WordPress + wp-cli via composer (wordpress.org is niet altijd bereikbaar)
cd "$MAP"
[ -f composer.json ] || echo '{}' > composer.json
[ -d vendor/johnpbloch/wordpress-core ] || composer require -q --no-interaction johnpbloch/wordpress-core:^6.8 wp-cli/wp-cli-bundle
ln -sfn "$MAP/vendor/johnpbloch/wordpress-core" "$MAP/site"
WP() { php -d memory_limit=512M "$MAP/vendor/wp-cli/wp-cli/php/boot-fs.php" --path="$MAP/site" --allow-root "$@"; }
if [ ! -f "$MAP/site/wp-config.php" ]; then
  WP config create --dbname="$DB" --dbuser=wp --dbpass=wp --dbhost="127.0.0.1:$((POORT + 10000))" --skip-check
  WP config set WP_DEBUG true --raw
  WP config set WP_DEBUG_LOG "'$MAP/debug.log'" --raw
  WP config set WP_DEBUG_DISPLAY false --raw
fi
WP core is-installed 2>/dev/null || WP core install --url="http://localhost:$POORT" --title=Test --admin_user=admin \
  --admin_password=admin123 --admin_email=admin@example.com --skip-email
WP rewrite structure '/%postname%/' >/dev/null
mkdir -p "$MAP/site/wp-content/plugins" "$MAP/site/wp-content/mu-plugins"
ln -sfn "$PLUGIN" "$MAP/site/wp-content/plugins/khzs"
ln -sfn "$HIER/khzs-test-helper.php" "$MAP/site/wp-content/mu-plugins/khzs-test-helper.php"
WP plugin activate khzs >/dev/null
WP rewrite flush >/dev/null

# PHP-webserver met router voor mooie permalinks
cat > "$MAP/router.php" <<'PHP'
<?php
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$f = $_SERVER['DOCUMENT_ROOT'] . $p;
if ($p !== '/' && is_file($f) && !str_ends_with($f, '.php')) return false;
if ($p !== '/' && is_file($f)) { require $f; return; }
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
PHP
if ! curl -s -o /dev/null "http://localhost:$POORT/"; then
  (cd "$MAP/site" && nohup php -d upload_max_filesize=30M -d post_max_size=40M -S "127.0.0.1:$POORT" -t "$MAP/site" "$MAP/router.php" >"$MAP/php.log" 2>&1 &)
  sleep 2
fi
echo "Testsite: http://localhost:$POORT/club/  (wp-admin: admin / admin123)  map: $MAP"
