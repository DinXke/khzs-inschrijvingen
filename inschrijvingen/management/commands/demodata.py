"""Lokale testdata: demo-accounts + de VK-wedstrijd met wat inschrijvingen. Niet in productie gebruiken."""
from datetime import date, timedelta
from pathlib import Path

from django.conf import settings
from django.contrib.auth.models import User
from django.core.files import File
from django.core.management import call_command
from django.core.management.base import BaseCommand, CommandError

from inschrijvingen import lenex
from inschrijvingen.models import Deelname, Inschrijving, Melding, Nummer, Wedstrijd, Zwemmer


class Command(BaseCommand):
    help = "Demo-accounts + VK-lenex met inschrijvingen (enkel met DEBUG=1)."

    def handle(self, *args, **opts):
        if not settings.DEBUG:
            raise CommandError("Enkel voor lokale ontwikkeling (DJANGO_DEBUG=1).")
        call_command("demo_accounts", stdout=self.stdout)
        call_command("beheerder", gebruiker="admin", wachtwoord="demo", stdout=self.stdout)  # enkel lokaal (DEBUG)
        if Wedstrijd.objects.exists():
            return
        pad = Path(__file__).resolve().parents[2] / "tests" / "fixtures" / "vk_kb_jeugd.lxf"
        meet = lenex.parse_meet(lenex.lees_xml(pad.read_bytes()))
        with pad.open("rb") as f:
            w = lenex.importeer(meet, File(f, name=pad.name))
        w.status = Wedstrijd.OPEN
        w.deadline = date.today() + timedelta(days=14)  # zodat je in de demo kan inschrijven
        w.save()
        w.limietlijsten.set(w.alle_limietlijsten.filter(naam__startswith="Haai"))
        w.info = "Deze wedstrijd is enkel voor zwemmers die een Haai-limiet hebben. Busvervoer vertrekt om 6u30 aan het zwembad."

        lien = Zwemmer.objects.get(account__username="zwemmer1", voornaam="Lien")
        bram = Zwemmer.objects.get(account__username="zwemmer2")
        trainer = User.objects.get(username="trainer1")
        Deelname.objects.create(zwemmer=lien, wedstrijd=w, keuze=Deelname.DOET_MEE)
        Deelname.objects.create(zwemmer=bram, wedstrijd=w, keuze=Deelname.INTERESSE)
        for nr, status, sec, baan in ((2, Inschrijving.GOEDGEKEURD, 79.5, "LCM"), (6, Inschrijving.INGEDIEND, 37.8, "SCM")):
            n = Nummer.objects.get(sessie__wedstrijd=w, nummer=nr)
            Inschrijving.objects.create(zwemmer=lien, nummer=n, status=status,
                                        inschrijftijd=timedelta(seconds=sec), inschrijfbaan=baan)
        Melding.objects.create(zwemmer=lien, wedstrijd=w, door=trainer,
                               tekst="goedgekeurd: #2 100 m schoolslag\ninschrijftijd #6 50 m rugslag: NT → 37.80")
        from inschrijvingen import backup
        backup.maak("automatisch")
        self.stdout.write(self.style.SUCCESS("Demo-wedstrijd klaar."))
