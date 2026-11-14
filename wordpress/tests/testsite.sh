#!/usr/bin/env bash
# Zet een lokale WordPress-testsite op met de plugin KHZS Leden en de testhulp, en start ze.
#   wordpress/tests/testsite.sh [map] [poort]      standaard: ./.testsite  8080
# Vereist: php (met mysqli, zip, xml, openssl), composer, mariadb-server.
# Daarna: KHZS_URL=http://localhost:8080 python3 wordpress/tests/test_e2e.py
set -euo pipefail
HIER="$(cd "$(dirname "$0")" && pwd)"
PLUGIN="$(cd "$HIER/../khzs-leden" && pwd)"
MAP="$(mkdir -p "${1:-$HIER/../../.testsite}" && cd "${1:-$HIER/../../.testsite}" && pwd)"
POORT="${2:-8080}"
DB="khzs_test_$POORT"
SOCK="/run/mysqld/khzs-$POORT.sock"

# MariaDB (eigen datamap, enkel lokaal)
mkdir -p /run/mysqld "$MAP/mysql"
if [ ! -d "$MAP/mysql/mysql" ]; then mysql_install_db --user=root --datadir="$MAP/mysql" >/dev/null; fi
if ! [ -S "$SOCK" ]; then
  setsid nohup mariadbd --user=root --datadir="$MAP/mysql" --socket="$SOCK" --port=$((POORT + 10000)) --bind-address=127.0.0.1 </dev/null >"$MAP/mysql.log" 2>&1 &
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
ln -sfn "$PLUGIN" "$MAP/site/wp-content/plugins/khzs-leden"
ln -sfn "$HIER/khzs-test-helper.php" "$MAP/site/wp-content/mu-plugins/khzs-test-helper.php"
WP plugin activate khzs-leden >/dev/null
# The Events Calendar, voor de synchronisatie van de wedstrijden met de websitekalender (KHZS_TEC=0 slaat dit over).
# Lukt de installatie niet, dan worden die tests overgeslagen.
if [ "${KHZS_TEC:-1}" = 1 ]; then
  if [ ! -d "$MAP/plugins/the-events-calendar" ]; then
    composer config -q --no-interaction repositories.wpackagist composer https://wpackagist.org
    composer config -q --no-interaction allow-plugins.composer/installers true
    composer config -q --no-interaction --json extra.installer-paths '{"plugins/{$name}/": ["type:wordpress-plugin"]}'
    composer require -q --no-interaction wpackagist-plugin/the-events-calendar || echo "The Events Calendar niet geïnstalleerd"
  fi
  if [ -d "$MAP/plugins/the-events-calendar" ]; then
    ln -sfn "$MAP/plugins/the-events-calendar" "$MAP/site/wp-content/plugins/the-events-calendar"
    WP plugin activate the-events-calendar >/dev/null
  fi
fi
WP config set KHZS_DEMO true --raw >/dev/null   # demo op /leden-demo/ (demo / demo); nooit op een echte site
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
  # Volledig loskoppelen (anders blijft de uitvoer van dit script openstaan).
  setsid nohup php -d upload_max_filesize=30M -d post_max_size=40M -S "127.0.0.1:$POORT" -t "$MAP/site" "$MAP/router.php" \
    </dev/null >"$MAP/php.log" 2>&1 &
  sleep 2
fi
echo "Testsite: http://localhost:$POORT/leden/  (wp-admin: admin / admin123)  demo: /leden-demo/ (demo / demo)  map: $MAP"
