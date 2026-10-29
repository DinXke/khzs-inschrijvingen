"""Lokale testdata: drie accounts (één per rol) en de VK-wedstrijd. Niet in productie gebruiken."""
from datetime import date, timedelta
from pathlib import Path

from django.conf import settings
from django.contrib.auth.models import User
from django.core.files import File
from django.core.management.base import BaseCommand, CommandError

from inschrijvingen import lenex
from inschrijvingen.models import BesteTijd, Profiel, Wedstrijd, Zwemmer

WACHTWOORD = "demo-khzs-2026"
ACCOUNTS = [
    ("secretaris@demo.khzs.be", "Sofie", "Secretaris", Profiel.SECRETARIS),
    ("trainer@demo.khzs.be", "Tom", "Trainer", Profiel.TRAINER),
    ("ouder@demo.khzs.be", "Olga", "Ouder", Profiel.ZWEMMER),
]


class Command(BaseCommand):
    help = "Maakt demo-accounts en importeert de VK-lenex (enkel met DEBUG=1)."

    def handle(self, *args, **opts):
        if not settings.DEBUG:
            raise CommandError("Enkel voor lokale ontwikkeling (DJANGO_DEBUG=1).")
        for email, vn, nm, rol in ACCOUNTS:
            u, _ = User.objects.get_or_create(username=email, defaults={"email": email, "first_name": vn, "last_name": nm})
            u.set_password(WACHTWOORD)
            u.save()
            Profiel.objects.update_or_create(user=u, defaults={"rol": rol})
        ouder = User.objects.get(username="ouder@demo.khzs.be")
        lien, _ = Zwemmer.objects.get_or_create(account=ouder, voornaam="Lien", naam="Peeters",
                                                defaults={"geboortedatum": date(2013, 3, 2), "geslacht": "F"})
        Zwemmer.objects.get_or_create(account=ouder, voornaam="Bram", naam="Peeters",
                                      defaults={"geboortedatum": date(2011, 7, 14), "geslacht": "M"})
        for afstand, slag, baan, sec in [(100, "BREAST", "LCM", 79.5), (50, "FREE", "SCM", 31.2), (200, "MEDLEY", "SCM", 170.0)]:
            BesteTijd.objects.get_or_create(zwemmer=lien, afstand=afstand, slag=slag, baan=baan,
                                            defaults={"tijd": timedelta(seconds=sec), "datum": date(2026, 3, 15), "wedstrijd": "Demo"})

        if not Wedstrijd.objects.exists():
            pad = Path(__file__).resolve().parents[2] / "tests" / "fixtures" / "vk_kb_jeugd.lxf"
            meet = lenex.parse_meet(lenex.lees_xml(pad.read_bytes()))
            with pad.open("rb") as f:
                w = lenex.importeer(meet, File(f, name=pad.name))
            w.status = Wedstrijd.OPEN
            w.deadline = date.today() + timedelta(days=14)  # zodat je in de demo kan inschrijven
            w.save()
            w.limietlijsten.set(w.alle_limietlijsten.filter(naam__startswith="Haai"))
        self.stdout.write(self.style.SUCCESS(f"Demo-accounts klaar (wachtwoord: zie {__file__})."))
