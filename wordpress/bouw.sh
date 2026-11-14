#!/usr/bin/env bash
# Maakt khzs-leden.zip om te uploaden via WordPress → Plugins → Nieuwe plugin → Plugin uploaden.
#   ./bouw.sh               plugin zonder filmpjes/PDF's (klein)
#   ./bouw.sh --met-uitleg  inclusief instructiefilmpjes en handleidingen uit ../docs (± 40 MB)
set -euo pipefail
cd "$(dirname "$0")"
tmp=$(mktemp -d)
cp -r khzs-leden "$tmp/"
if [[ "${1:-}" == "--met-uitleg" ]]; then
  mkdir -p "$tmp/khzs-leden/assets/docs/video" "$tmp/khzs-leden/assets/docs/pdf"
  cp ../docs/video/*.mp4 ../docs/video/*.vtt "$tmp/khzs-leden/assets/docs/video/"
  cp ../docs/pdf/*.pdf "$tmp/khzs-leden/assets/docs/pdf/"
fi
rm -f khzs-leden.zip
(cd "$tmp" && zip -qr - khzs-leden) > khzs-leden.zip
rm -rf "$tmp"
echo "Klaar: $(pwd)/khzs-leden.zip ($(du -h khzs-leden.zip | cut -f1))"
