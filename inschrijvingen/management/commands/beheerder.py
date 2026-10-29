"""Maakt of herstelt het beheerdersaccount (rol Beheerder + toegang tot /beheer/).
Gebruik: manage.py beheerder [--gebruiker admin] [--wachtwoord ...]
Zonder --wachtwoord wordt een sterk wachtwoord gegenereerd en één keer getoond."""
import secrets

from django.contrib.auth.models import User
from django.core.management.base import BaseCommand

from inschrijvingen.models import Profiel


class Command(BaseCommand):
    help = "Maakt of herstelt het beheerdersaccount."

    def add_arguments(self, parser):
        parser.add_argument("--gebruiker", default="admin")
        parser.add_argument("--wachtwoord")

    def handle(self, *args, gebruiker, wachtwoord=None, **opts):
        wachtwoord = wachtwoord or secrets.token_urlsafe(18)
        u, nieuw = User.objects.get_or_create(username=gebruiker, defaults={"first_name": "Beheerder"})
        u.is_staff = u.is_superuser = True
        u.set_password(wachtwoord)
        u.save()
        Profiel.objects.update_or_create(user=u, defaults={"rol": Profiel.BEHEERDER})
        self.stdout.write(f"{'Aangemaakt' if nieuw else 'Bijgewerkt'}: {gebruiker} / {wachtwoord}")
