"""Demo-accounts voor de testfase: sportsec1/2, trainer1/2, zwemmer1/2 en beheerder, wachtwoord 'demo'.
Idempotent; zet bestaande wachtwoorden terug op 'demo'. Verwijderen: manage.py demo_accounts --verwijder"""
from datetime import date, timedelta

from django.contrib.auth.models import User
from django.core.management.base import BaseCommand

from inschrijvingen.models import BesteTijd, Profiel, Zwemmer

WACHTWOORD = "demo"
ACCOUNTS = [
    # gebruikersnaam, voornaam, naam, rol, superuser
    ("beheerder", "Beheerder", "HZS", Profiel.SECRETARIS, True),
    ("sportsec1", "Sofie", "Sportsecretaris", Profiel.SECRETARIS, False),
    ("sportsec2", "Stijn", "Sportsecretaris", Profiel.SECRETARIS, False),
    ("trainer1", "Tom", "Trainer", Profiel.TRAINER, False),
    ("trainer2", "Tine", "Trainer", Profiel.TRAINER, False),
    ("zwemmer1", "Ann", "Peeters", Profiel.ZWEMMER, False),  # ouder met twee kinderen
    ("zwemmer2", "Bram", "Janssens", Profiel.ZWEMMER, False),
]
ZWEMMERS = {
    "zwemmer1": [
        ("Lien", "Peeters", date(2013, 3, 2), "F", [(100, "BREAST", "LCM", 79.5), (50, "FREE", "SCM", 31.2), (200, "MEDLEY", "SCM", 170.0)]),
        ("Noor", "Peeters", date(2015, 9, 21), "F", [(50, "BACK", "SCM", 38.4), (100, "FREE", "SCM", 72.1)]),
    ],
    "zwemmer2": [
        ("Bram", "Janssens", date(2011, 7, 14), "M", [(100, "FREE", "SCM", 58.9), (50, "FLY", "SCM", 29.4), (200, "BACK", "LCM", 141.3)]),
    ],
}


class Command(BaseCommand):
    help = "Maakt de demo-accounts (wachtwoord 'demo') aan of verwijdert ze."

    def add_arguments(self, parser):
        parser.add_argument("--verwijder", action="store_true")

    def handle(self, *args, verwijder=False, **opts):
        namen = [a[0] for a in ACCOUNTS]
        if verwijder:
            n, _ = User.objects.filter(username__in=namen).delete()
            self.stdout.write(f"Demo-accounts verwijderd ({n} objecten).")
            return
        for naam, vn, nm, rol, superuser in ACCOUNTS:
            u, _ = User.objects.get_or_create(username=naam, defaults={"first_name": vn, "last_name": nm,
                                                                         "email": f"{naam}@demo.khzs.be"})
            u.is_staff = u.is_superuser = superuser
            u.set_password(WACHTWOORD)
            u.save()
            Profiel.objects.update_or_create(user=u, defaults={"rol": rol})
            for zvn, znm, gd, gs, tijden in ZWEMMERS.get(naam, []):
                z, _ = Zwemmer.objects.get_or_create(account=u, voornaam=zvn, naam=znm,
                                                     defaults={"geboortedatum": gd, "geslacht": gs})
                for afstand, slag, baan, sec in tijden:
                    BesteTijd.objects.get_or_create(zwemmer=z, afstand=afstand, slag=slag, baan=baan, defaults={
                        "tijd": timedelta(seconds=sec), "datum": date(2026, 3, 15), "wedstrijd": "Demo"})
        self.stdout.write(self.style.SUCCESS("Demo-accounts klaar: " + ", ".join(namen) + " (wachtwoord: demo)"))
