#!/usr/bin/env bash
# Na elke nieuwe versie van de code: afhankelijkheden, migraties, statische bestanden, herstart.
set -euo pipefail
cd /opt/khzs/app
[ -d /opt/khzs/venv ] || python3 -m venv /opt/khzs/venv
/opt/khzs/venv/bin/pip install -q -r requirements.txt
set -a; source /etc/khzs.env; set +a
/opt/khzs/venv/bin/python manage.py migrate --noinput
/opt/khzs/venv/bin/python manage.py collectstatic --noinput -v0
chown -R khzs:khzs /opt/khzs/media
chown postgres:postgres /opt/khzs/backups
systemctl restart khzs 2>/dev/null || true
