#!/usr/bin/env bash
# Maakt khzs.zip om te uploaden via WordPress → Plugins → Nieuwe plugin → Plugin uploaden.
#   ./bouw.sh               plugin zonder filmpjes/PDF's (klein)
#   ./bouw.sh --met-uitleg  inclusief instructiefilmpjes en handleidingen uit ../docs (± 40 MB)
set -euo pipefail
cd "$(dirname "$0")"
tmp=$(mktemp -d)
cp -r khzs "$tmp/"
if [[ "${1:-}" == "--met-uitleg" ]]; then
  mkdir -p "$tmp/khzs/assets/docs/video" "$tmp/khzs/assets/docs/pdf"
  cp ../docs/video/*.mp4 ../docs/video/*.vtt "$tmp/khzs/assets/docs/video/"
  cp ../docs/pdf/*.pdf "$tmp/khzs/assets/docs/pdf/"
fi
rm -f khzs.zip
(cd "$tmp" && zip -qr - khzs) > khzs.zip
rm -rf "$tmp"
echo "Klaar: $(pwd)/khzs.zip ($(du -h khzs.zip | cut -f1))"
