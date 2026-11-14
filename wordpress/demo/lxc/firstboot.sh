#!/usr/bin/env bash
# In de nieuwe LXC (Debian 12), als root, via maak-lxc.sh: Docker, de repo, de demo, cron en cloudflared.
# Nog eens uitvoeren kan geen kwaad.
set -euo pipefail
exec > >(tee -a /var/log/khzs-demo-firstboot.log) 2>&1
echo "== start $(date)"
: "${CF_TUNNEL_TOKEN:?CF_TUNNEL_TOKEN ontbreekt}"
REPO="${REPO:-https://github.com/DinXke/khzs-leden}"
MAP=/opt/khzs-demo

apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y ca-certificates curl gnupg git cron
mkdir -p --mode=0755 /etc/apt/keyrings /usr/share/keyrings

# Docker (officiële repository)
curl -fsSL https://download.docker.com/linux/debian/gpg | gpg --dearmor --yes -o /etc/apt/keyrings/docker.gpg
echo "deb [signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/debian bookworm stable" > /etc/apt/sources.list.d/docker.list
# cloudflared
curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg > /usr/share/keyrings/cloudflare-main.gpg
echo 'deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared any main' > /etc/apt/sources.list.d/cloudflared.list
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin cloudflared
echo "== docker en cloudflared ok"

# Eigen clone voor de demo (update.sh zet ze telkens gelijk met main)
if [ -d "$MAP/.git" ]; then git -C "$MAP" pull -q --ff-only; else git clone -q --branch "${DEMO_TAK:-main}" "$REPO" "$MAP"; fi
cd "$MAP/wordpress/demo"
if [ ! -f .env ]; then
  sed -e "s|^DB_WACHTWOORD=.*|DB_WACHTWOORD=$(head -c 24 /dev/urandom | base64 | tr -d '/+=')|" .env.voorbeeld > .env
  chmod 600 .env
fi
./installeer.sh
echo "*/10 * * * * root $MAP/wordpress/demo/update.sh >> /var/log/khzs-demo.log 2>&1" > /etc/cron.d/khzs-demo

cloudflared service install "$CF_TUNNEL_TOKEN" || systemctl restart cloudflared
echo "== klaar $(date): https://khzs.be/leden-demo/ (na de Worker), tunnel: leden-demo.khzs.be"
