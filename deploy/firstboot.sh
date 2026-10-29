#!/usr/bin/env bash
# Draait één keer bij de eerste start van de LXC: code ophalen, cloudflared + app installeren.
set -uo pipefail
mkdir -p /opt/khzs
exec >>/opt/khzs/firstboot.log 2>&1
echo "== start $(date)"
for i in $(seq 1 90); do getent hosts deb.debian.org >/dev/null && break; sleep 2; done

set -a; source /etc/khzs-firstboot.env; set +a
# Bij een fout: log op een geheim pad zetten (nginx), zodat de installatie van buitenaf te controleren is.
toon_log() {
  echo "== MISLUKT $(date) (regel $1)"
  for d in /opt/khzs/setup /var/www/html/_setup; do mkdir -p "$d" && cp /opt/khzs/firstboot.log "$d/${SETUP_PAD:-log}.log"; done
  chmod -R a+rX /opt/khzs/setup /var/www/html/_setup 2>/dev/null; chmod a+x /opt/khzs
}
trap 'toon_log $LINENO' ERR
set -e
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y curl ca-certificates gnupg git
if [ -d /opt/khzs/app/.git ]; then git -C /opt/khzs/app pull -q --ff-only; else git clone --branch main "$REPO" /opt/khzs/app; fi

mkdir -p --mode=0755 /usr/share/keyrings
curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg > /usr/share/keyrings/cloudflare-main.gpg
echo 'deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared any main' > /etc/apt/sources.list.d/cloudflared.list
apt-get update && apt-get install -y cloudflared
cloudflared service install "$CF_TUNNEL_TOKEN" || systemctl restart cloudflared
echo "== cloudflared ok"

bash /opt/khzs/app/deploy/installeer.sh
rm -rf /etc/khzs-firstboot.env /opt/khzs/setup /var/www/html/_setup
systemctl disable khzs-firstboot.service
echo "== klaar $(date)"
