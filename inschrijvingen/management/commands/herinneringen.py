"""Dagelijks (systemd-timer): pushherinnering 1 dag voor de deadline van zwemmers en trainers."""
from datetime import timedelta

from django.core.management.base import BaseCommand
from django.db import IntegrityError
from django.urls import reverse

from inschrijvingen import push
from inschrijvingen.models import Profiel, VerstuurdeHerinnering, Wedstrijd
from inschrijvingen.regels import vandaag


class Command(BaseCommand):
    help = "Verstuurt deadline-herinneringen (1 dag op voorhand)."

    def handle(self, *args, **opts):
        morgen = vandaag() + timedelta(days=1)
        for w in Wedstrijd.objects.filter(status=Wedstrijd.OPEN):
            for soort, datum, rollen, tekst, url in (
                ("zwemmer", w.deadline_zwemmer, [Profiel.ZWEMMER],
                 f"Morgen ({datum_str(w.deadline_zwemmer)}) is de laatste dag om in te schrijven.",
                 reverse("wedstrijd", args=[w.pk])),
                ("trainer", w.deadline_trainer, [Profiel.TRAINER, Profiel.SECRETARIS],
                 f"Morgen ({datum_str(w.deadline_trainer)}) moeten de inschrijvingen nagekeken zijn.",
                 reverse("nakijken", args=[w.pk])),
            ):
                if datum != morgen:
                    continue
                try:
                    VerstuurdeHerinnering.objects.create(wedstrijd=w, soort=soort, datum=datum)
                except IntegrityError:
                    continue
                users = push.users_met("push_deadline", rollen, deelname_aan=w if soort == "zwemmer" else None)
                n = push.stuur(users, f"Deadline {w.naam}", tekst, url,
                               achtergrond=False)
                self.stdout.write(f"{w.naam} ({soort}): {n} toestel(len)")


def datum_str(d):
    return d.strftime("%d/%m")
