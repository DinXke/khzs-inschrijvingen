#!/usr/bin/env bash
# Eenmalige installatie IN de LXC (Debian 12), als root. Verwacht de code in /opt/khzs/app.
set -euo pipefail
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y python3 python3-venv postgresql nginx curl ca-certificates git

id khzs >/dev/null 2>&1 || useradd --system --home /opt/khzs --shell /usr/sbin/nologin khzs
mkdir -p /opt/khzs/media /opt/khzs/backups/pg /opt/khzs/backups/app /opt/khzs/data
chown khzs:khzs /opt/khzs/data /opt/khzs/backups/app && chmod 700 /opt/khzs/data /opt/khzs/backups/app
chown postgres:postgres /opt/khzs/backups/pg

if [ ! -f /etc/khzs.env ]; then
  DBPW=$(python3 -c 'import secrets;print(secrets.token_urlsafe(24))')
  SK=$(python3 -c 'import secrets;print(secrets.token_urlsafe(50))')
  sudo -u postgres psql -c "CREATE USER khzs WITH PASSWORD '$DBPW';"
  sudo -u postgres psql -c "CREATE DATABASE khzs OWNER khzs;"
  cat > /etc/khzs.env <<ENV
DJANGO_SECRET_KEY=$SK
DJANGO_DEBUG=0
DJANGO_ALLOWED_HOSTS=inschrijvingen.khzs.be,localhost,127.0.0.1
DJANGO_CSRF_ORIGINS=https://inschrijvingen.khzs.be
DJANGO_MEDIA_ROOT=/opt/khzs/media
DATABASE_URL=postgres://khzs:$DBPW@localhost/khzs
# Vul SMTP in om wachtwoord-resetmails te versturen:
DJANGO_EMAIL_BACKEND=django.core.mail.backends.console.EmailBackend
SMTP_HOST=
SMTP_PORT=587
SMTP_USER=
SMTP_PASSWORD=
DEFAULT_FROM_EMAIL=inschrijvingen@khzs.be
CLUB_NAAM=HZS Spartacus
CLUB_CODE=KHZS
VAPID_FILE=/opt/khzs/data/vapid.pem
BACKUP_DIR=/opt/khzs/backups/app
ENV
  chmod 640 /etc/khzs.env; chgrp khzs /etc/khzs.env
fi

cp /opt/khzs/app/deploy/nginx.conf /etc/nginx/sites-available/khzs
ln -sf /etc/nginx/sites-available/khzs /etc/nginx/sites-enabled/khzs
rm -f /etc/nginx/sites-enabled/default
cp /opt/khzs/app/deploy/backup.cron /etc/cron.d/khzs-backup
cp /opt/khzs/app/deploy/*.service /opt/khzs/app/deploy/*.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now khzs-herinneringen.timer khzs-update.timer khzs-backup.timer

bash /opt/khzs/app/deploy/update.sh
if [ "${DEMO_ACCOUNTS:-0}" = "1" ]; then
  (set -a; source /etc/khzs.env; set +a; cd /opt/khzs/app && /opt/khzs/venv/bin/python manage.py demo_accounts)
fi
systemctl enable --now khzs
nginx -t && systemctl reload nginx
