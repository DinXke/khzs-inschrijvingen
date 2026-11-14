#!/usr/bin/env bash
# Houdt de demo bij (bv. elke 10 minuten via cron):
#  - haalt de tak op die de demo volgt (DEMO_TAK, standaard main);
#  - bij een nieuwe versie: plugin bijwerken (deze map is een eigen clone van de repo), database bijwerken en de
#    demo terugzetten naar de begintoestand, zodat de demodata bij de nieuwe code past;
#  - lukt dat niet, dan terug naar de vorige versie;
#  - draait de geplande taken van WordPress (o.a. het nachtelijke terugzetten om 03:30).
# Werk nooit zelf in deze clone: lokale wijzigingen worden overschreven.
set -euo pipefail
# Alles in een functie: bash leest het hele script in vóór git dit bestand zelf bijwerkt.
main() {
HIER="$(cd "$(dirname "$0")" && pwd)"
cd "$HIER"
[ -f .env ] && { set -a; . ./.env; set +a; }
TAK="${DEMO_TAK:-main}"
REPO="$(git -C "$HIER" rev-parse --show-toplevel)"
wp() { docker compose run --rm -T wpcli wp "$@"; }
log() { echo "$(date '+%F %T') $*"; }

opbouwen() {
  wp rewrite flush >/dev/null && wp eval 'KHZS\demo_terugzetten();'
}

oud="$(git -C "$REPO" rev-parse HEAD)"
git -C "$REPO" fetch -q origin "$TAK"
nieuw="$(git -C "$REPO" rev-parse "origin/$TAK")"
if [ "$oud" != "$nieuw" ]; then
  git -C "$REPO" reset -q --hard "$nieuw"
  if opbouwen; then
    log "demo bijgewerkt naar ${nieuw:0:7} en teruggezet"
  else
    log "FOUT bij ${nieuw:0:7}: terug naar ${oud:0:7}" >&2
    git -C "$REPO" reset -q --hard "$oud"
    opbouwen || true
    exit 1
  fi
fi
wp cron event run --due-now >/dev/null
}
main "$@"
exit
