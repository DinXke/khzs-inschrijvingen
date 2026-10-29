#!/usr/bin/env bash
# Elke 10 minuten (khzs-update.timer): nieuwe commits op origin/main ophalen en installeren.
set -uo pipefail
cd /opt/khzs/app
git fetch -q origin main || exit 0
OUD=$(git rev-parse HEAD); NIEUW=$(git rev-parse origin/main)
[ "$OUD" = "$NIEUW" ] && exit 0
exec >>/opt/khzs/update.log 2>&1
echo "== $(date) $OUD -> $NIEUW"
git reset -q --hard "$NIEUW"
cp deploy/*.service deploy/*.timer /etc/systemd/system/ && systemctl daemon-reload
if bash deploy/update.sh; then
  echo "== OK"
else
  echo "== MISLUKT, terug naar $OUD"
  git reset -q --hard "$OUD" && bash deploy/update.sh
fi
