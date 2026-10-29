"""Volledige backup (alle gegevens + opgeladen bestanden) als zip, en terugzetten."""
import io
import json
import zipfile
from datetime import datetime, timedelta
from pathlib import Path, PurePosixPath

from django.conf import settings
from django.core import serializers
from django.core.management import call_command
from django.db import transaction

UITSLUITEN = ["contenttypes", "auth.permission", "sessions", "admin.logentry"]


def map_():
    pad = Path(settings.BACKUP_DIR)
    pad.mkdir(parents=True, exist_ok=True)
    return pad


def maak(label="manueel") -> Path:
    data = io.StringIO()
    call_command("dumpdata", exclude=UITSLUITEN, natural_foreign=True, natural_primary=True, indent=1, stdout=data)
    naam = f"hzs-backup-{datetime.now():%Y%m%d-%H%M%S}-{label}.zip"
    doel = map_() / naam
    tmp = doel.with_suffix(".tmp")
    with zipfile.ZipFile(tmp, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("data.json", data.getvalue())
        z.writestr("info.json", json.dumps({"gemaakt": datetime.now().isoformat(), "label": label}))
        media = Path(settings.MEDIA_ROOT)
        if media.exists():
            for f in media.rglob("*"):
                if f.is_file():
                    z.write(f, f"media/{f.relative_to(media)}")
    tmp.rename(doel)
    return doel


def lijst():
    return sorted(map_().glob("hzs-backup-*.zip"), reverse=True)


def opruimen(dagen):
    grens = datetime.now() - timedelta(days=dagen)
    for f in map_().glob("hzs-backup-*-automatisch.zip"):
        if datetime.fromtimestamp(f.stat().st_mtime) < grens:
            f.unlink()


def zoek(naam):
    """Enkel bestanden uit de backupmap, op naam (geen paden)."""
    pad = map_() / Path(naam).name
    if not pad.name.startswith("hzs-backup-") or pad.suffix != ".zip" or not pad.exists():
        raise FileNotFoundError(naam)
    return pad


def herstel(zipbestand):
    """Vervangt ALLE gegevens door die uit de backup. Maakt eerst zelf een veiligheidsbackup."""
    with zipfile.ZipFile(zipbestand) as z:
        if "data.json" not in z.namelist():
            raise ValueError("Geen geldige backup (data.json ontbreekt).")
        data = z.read("data.json").decode()
        objecten = list(serializers.deserialize("json", data))  # valideert vóór we iets wissen
        veilig = maak("voor-herstel")
        with transaction.atomic():
            call_command("flush", interactive=False, verbosity=0)
            for obj in objecten:
                obj.save()
        media = Path(settings.MEDIA_ROOT)
        for naam in z.namelist():
            p = PurePosixPath(naam)
            if p.parts[:1] == ("media",) and ".." not in p.parts and not naam.endswith("/"):
                doel = media.joinpath(*p.parts[1:])
                doel.parent.mkdir(parents=True, exist_ok=True)
                doel.write_bytes(z.read(naam))
    return len(objecten), veilig
