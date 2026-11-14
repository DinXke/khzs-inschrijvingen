#!/usr/bin/env bash
# Op de Proxmox-host, als root: maakt een nieuwe Debian 12-LXC "leden-demo" met Docker en zet de demo erin.
#   CF_TUNNEL_TOKEN=<token van de tunnel leden-demo> ./maak-lxc.sh <vmid> [opslag] [bridge] [vlan]
# Voorbeeld: CF_TUNNEL_TOKEN=eyJ… ./maak-lxc.sh 231 local-lvm vmbr0 30
# De tunnel (Cloudflare Zero Trust → Networks → Tunnels) heeft als public hostname leden-demo.khzs.be → http://localhost:8094.
set -euo pipefail
VMID="${1:?geef een vrij vmid}"
OPSLAG="${2:-local-lvm}"
BRUG="${3:-vmbr0}"
VLAN="${4:-}"
: "${CF_TUNNEL_TOKEN:?zet CF_TUNNEL_TOKEN (token van de Cloudflare-tunnel)}"
HIER="$(cd "$(dirname "$0")" && pwd)"

pveam update >/dev/null
TEMPLATE="$(pveam available --section system | awk '/debian-12-standard/ {print $2}' | sort | tail -1)"
pveam list local | grep -q "$TEMPLATE" || pveam download local "$TEMPLATE"

NET="name=eth0,bridge=$BRUG,ip=dhcp${VLAN:+,tag=$VLAN}"
pct create "$VMID" "local:vztmpl/$TEMPLATE" --hostname leden-demo --description "Demo KHZS Leden (khzs.be/leden-demo), los van productie" \
  --cores 2 --memory 2048 --swap 512 --rootfs "$OPSLAG:16" --net0 "$NET" \
  --unprivileged 1 --features nesting=1,keyctl=1 --onboot 1
pct start "$VMID"
for _ in $(seq 60); do pct exec "$VMID" -- getent hosts deb.debian.org >/dev/null 2>&1 && break; sleep 2; done

pct push "$VMID" "$HIER/firstboot.sh" /root/khzs-demo-firstboot.sh
pct exec "$VMID" -- env CF_TUNNEL_TOKEN="$CF_TUNNEL_TOKEN" bash /root/khzs-demo-firstboot.sh
echo "LXC $VMID klaar. Log: pct exec $VMID -- cat /var/log/khzs-demo-firstboot.log"
