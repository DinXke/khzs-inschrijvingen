#!/usr/bin/env bash
# Zet de demo-WordPress op (eenmalig; nog eens uitvoeren kan geen kwaad).
#   cp .env.voorbeeld .env   (en aanvullen)
#   ./installeer.sh
set -euo pipefail
cd "$(dirname "$0")"
[ -f .env ] || { echo "Maak eerst .env (kopie van .env.voorbeeld)." >&2; exit 1; }
set -a; . ./.env; set +a

docker compose up -d
wp() { docker compose run --rm -T wpcli wp "$@"; }

# Wachten tot de WordPress-container zijn bestanden en wp-config.php heeft gezet en de database antwoordt.
for _ in $(seq 60); do
  docker compose exec -T wordpress test -f /var/www/html/wp-config.php 2>/dev/null && wp db check >/dev/null 2>&1 && break
  sleep 2
done

if ! wp core is-installed >/dev/null 2>&1; then
  WW=$(head -c 18 /dev/urandom | base64 | tr -d '/+=')
  wp core install --url="${DEMO_URL:-http://localhost:8094}" --title="KHZS Leden (demo)" --admin_user=beheer \
    --admin_password="$WW" --admin_email="${BEHEER_EMAIL:-beheer@example.com}" --skip-email >/dev/null
  echo "wp-admin van de demo: gebruiker beheer, wachtwoord $WW  (bewaar dit; het wordt niet opnieuw getoond)"
fi
wp option update blog_public 0 >/dev/null          # niet in zoekmachines
wp option update default_comment_status closed >/dev/null
wp rewrite structure '/%postname%/' >/dev/null
wp plugin activate khzs-leden >/dev/null
wp rewrite flush >/dev/null
wp eval 'KHZS\demo_terugzetten(); echo "Demo opgebouwd (KHZS Leden " . KHZS\VERSIE . ")\n";'
echo "Demo: ${DEMO_URL:-http://localhost:8094}/leden-demo/  (demo / demo)"
echo "Houd ze bij met update.sh, bv. elke 10 minuten via cron (zie README.md)."
