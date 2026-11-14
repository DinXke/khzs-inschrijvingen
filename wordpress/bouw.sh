#!/usr/bin/env bash
# Maakt khzs-inschrijvingen.zip om te uploaden via WordPress → Plugins → Nieuwe plugin → Plugin uploaden.
#   ./bouw.sh               plugin zonder filmpjes/PDF's (klein)
#   ./bouw.sh --met-uitleg  inclusief instructiefilmpjes en handleidingen uit ../docs (± 40 MB)
set -euo pipefail
cd "$(dirname "$0")"
tmp=$(mktemp -d)
cp -r khzs-inschrijvingen "$tmp/"
if [[ "${1:-}" == "--met-uitleg" ]]; then
  mkdir -p "$tmp/khzs-inschrijvingen/assets/docs/video" "$tmp/khzs-inschrijvingen/assets/docs/pdf"
  cp ../docs/video/*.mp4 ../docs/video/*.vtt "$tmp/khzs-inschrijvingen/assets/docs/video/"
  cp ../docs/pdf/*.pdf "$tmp/khzs-inschrijvingen/assets/docs/pdf/"
fi
rm -f khzs-inschrijvingen.zip
(cd "$tmp" && zip -qr - khzs-inschrijvingen) > khzs-inschrijvingen.zip
rm -rf "$tmp"
echo "Klaar: $(pwd)/khzs-inschrijvingen.zip ($(du -h khzs-inschrijvingen.zip | cut -f1))"
