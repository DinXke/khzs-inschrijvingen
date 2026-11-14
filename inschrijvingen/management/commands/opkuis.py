"""AVG: gegevens wissen waarvan de bewaartermijn voorbij is (zie inschrijvingen/avg.py). Draait dagelijks mee met de herinneringen."""
from django.core.management.base import BaseCommand

from inschrijvingen import avg


class Command(BaseCommand):
    help = "Wist oude meldingen, aanvragen, pushabonnementen, verlopen koppelcodes en sessies (bewaartermijnen, AVG)."

    def handle(self, *args, **opts):
        n = avg.opkuis()
        self.stdout.write("Opkuis: " + ", ".join(f"{k} {v}" for k, v in n.items()))
