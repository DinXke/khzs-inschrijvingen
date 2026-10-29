"""Demo-wedstrijden (verleden, open, nog niet open) met deelnames, inschrijvingen, uitslagen en meldingen.

De programma's zijn gebaseerd op echte voorprogramma's van zwemfed.be (Regiocup Bree, Sprintcup KLSVZ,
Kerstprijs ZCT); de oudere wedstrijden zijn verzonnen. Alles loopt via de gewone Lenex-import.
Gebruik: manage.py demo_wedstrijden            (maakt demo-accounts + wedstrijden, idempotent)
         manage.py demo_wedstrijden --verwijder (verwijdert enkel de demo-wedstrijden)"""
import random
from datetime import date, timedelta
from xml.etree import ElementTree as ET

from django.contrib.auth.models import User
from django.core.files.base import ContentFile
from django.core.management import call_command
from django.core.management.base import BaseCommand
from django.db import transaction

from inschrijvingen import lenex
from inschrijvingen.models import (BesteTijd, Deelname, Inschrijving, Melding, Profiel, Wedstrijd, Zwemmer)
from inschrijvingen.regels import leeftijd, past_in_nummer

JEUGD = [(11, 11), (12, 12), (13, 13), (14, 14), (15, 16), (17, -1)]
MINIEM = [(9, 9), (10, 10)]
PREFIX = "lenex/demo-"

# naam, stad, zwembad, baan, datum, start, deadline, status, kost(cent), info, nummers[(afstand, slag, geslacht, groepen)]
WEDSTRIJDEN = [
    ("Regiocup Limburg najaar (demo)", "Genk", "Zwembad Sportinn", "SCM", date(2026, 9, 27), "14:00", date(2026, 9, 13),
     Wedstrijd.DOORGESTUURD, 400, "",
     [(100, "FREE", "F", JEUGD), (100, "FREE", "M", JEUGD), (50, "BACK", "X", MINIEM), (100, "BREAST", "F", JEUGD),
      (100, "BREAST", "M", JEUGD), (50, "FLY", "F", JEUGD), (50, "FLY", "M", JEUGD), (200, "MEDLEY", "F", JEUGD),
      (200, "MEDLEY", "M", JEUGD)]),
    ("Herfstmeeting HZS (demo)", "Hasselt", "Kapermolen", "LCM", date(2026, 10, 11), "09:00", date(2026, 9, 27),
     Wedstrijd.DOORGESTUURD, 500, "Thuiswedstrijd: elke zwemmer helpt mee aan de cafetaria.",
     [(50, "FREE", "F", JEUGD), (50, "FREE", "M", JEUGD), (200, "FREE", "F", JEUGD), (200, "FREE", "M", JEUGD),
      (100, "BACK", "F", JEUGD), (100, "BACK", "M", JEUGD), (50, "BREAST", "X", MINIEM), (100, "FLY", "F", JEUGD),
      (100, "FLY", "M", JEUGD)]),
    ("Kerstprijs ZCT", "Tremelo", "Zwembad Tremelo", "SCM", date(2026, 12, 13), "09:30", date(2026, 11, 29),
     Wedstrijd.OPEN, 450, "Enkel voor zwemmers van 11 jaar en ouder. Kerstmuts mag!",
     [(100, "MEDLEY", "F", JEUGD), (100, "MEDLEY", "M", JEUGD), (50, "FREE", "F", JEUGD), (50, "FREE", "M", JEUGD),
      (100, "BACK", "F", JEUGD), (100, "BACK", "M", JEUGD), (200, "FREE", "F", JEUGD), (200, "FREE", "M", JEUGD),
      (50, "BREAST", "F", JEUGD), (50, "BREAST", "M", JEUGD)]),
    ("Regiocup 1 Bree", "Bree", "De Sprink", "SCM", date(2027, 1, 10), "14:00", date(2026, 12, 27),
     Wedstrijd.OPEN, 400, "Regionale wedstrijd op uitnodiging. Aanvang inzwemmen 13:00.",
     [(200, "BREAST", "F", JEUGD), (200, "BREAST", "M", JEUGD), (50, "BREAST", "X", MINIEM), (100, "FREE", "F", JEUGD),
      (100, "FREE", "M", JEUGD), (50, "FLY", "F", JEUGD), (50, "FLY", "M", JEUGD), (100, "FREE", "X", MINIEM),
      (400, "MEDLEY", "F", JEUGD), (400, "MEDLEY", "M", JEUGD)]),
    ("Sprintcup KLSVZ 2027", "Hannuit", "Plopsaqua Landen-Hannuit", "SCM", date(2027, 1, 16), "18:00", date(2027, 1, 2),
     Wedstrijd.CONCEPT, 500, "9-10-jarigen: max 2 individuele nummers. 11+: max 4 + eventueel 1 finale.",
     [(100, "BREAST", "X", MINIEM), (50, "FLY", "F", [(11, 12), (13, 14), (15, 16), (17, -1)]),
      (50, "FLY", "M", [(11, 12), (13, 14), (15, 16), (17, -1)]), (100, "BACK", "X", MINIEM),
      (50, "BACK", "F", [(11, 12), (13, 14), (15, 16), (17, -1)]), (50, "BACK", "M", [(11, 12), (13, 14), (15, 16), (17, -1)]),
      (50, "FREE", "F", [(11, 12), (13, 14), (15, 16), (17, -1)]), (50, "FREE", "M", [(11, 12), (13, 14), (15, 16), (17, -1)])]),
]

GEZINNEN = [  # account, voornaam ouder, naam, kinderen [(voornaam, geboortedatum, geslacht)]
    ("zwemmer3", "Koen", "Vermeulen", [("Mila", date(2012, 5, 11), "F"), ("Lars", date(2014, 2, 3), "M")]),
    ("zwemmer4", "Sara", "Claes", [("Jules", date(2010, 11, 28), "M")]),
    ("zwemmer5", "Pieter", "Maes", [("Fien", date(2016, 8, 19), "F"), ("Ella", date(2013, 12, 1), "F")]),
    ("zwemmer6", "Hilde", "Wouters", [("Arne", date(2009, 4, 7), "M"), ("Kobe", date(2011, 9, 15), "M")]),
]

BASIS = {("FREE", 50): 30, ("FREE", 100): 66, ("FREE", 200): 145, ("BACK", 50): 36, ("BACK", 100): 76,
         ("BREAST", 50): 40, ("BREAST", 100): 86, ("BREAST", 200): 186, ("FLY", 50): 34, ("FLY", 100): 75,
         ("MEDLEY", 100): 77, ("MEDLEY", 200): 165, ("MEDLEY", 400): 350}


def richttijd(z, afstand, slag, op, rnd):
    """Plausibele tijd: jonger = trager, 50 m-bad iets trager."""
    leeftijd_ = op.year - z.geboortedatum.year
    sec = BASIS.get((slag, afstand), afstand * 0.75) * (1 + max(0, 16 - leeftijd_) * 0.06) * rnd.uniform(0.95, 1.08)
    return timedelta(seconds=round(sec, 2))


def maak_lenex(naam, stad, zwembad, baan, dag, start, deadline, kost, nummers):
    root = ET.Element("LENEX", version="3.0")
    meet = ET.SubElement(ET.SubElement(root, "MEETS"), "MEET", name=naam, city=stad, course=baan,
                         deadline=deadline.isoformat(), nation="BEL")
    ET.SubElement(meet, "AGEDATE", value=f"{dag.year}-12-31", type="YEAR")
    ET.SubElement(meet, "POOL", name=zwembad)
    ET.SubElement(meet, "QUALIFY", **{"from": f"{dag.year - 1}-01-01", "until": deadline.isoformat()})
    sessie = ET.SubElement(ET.SubElement(meet, "SESSIONS"), "SESSION", number="1", date=dag.isoformat(), daytime=start)
    events = ET.SubElement(sessie, "EVENTS")
    for i, (afstand, slag, geslacht, groepen) in enumerate(nummers, start=1):
        e = ET.SubElement(events, "EVENT", eventid=str(9000 + i), number=str(i), order=str(i), gender=geslacht, round="TIM")
        ET.SubElement(e, "SWIMSTYLE", distance=str(afstand), stroke=slag, relaycount="1")
        ET.SubElement(e, "FEE", currency="EUR", value=str(kost))
        ag = ET.SubElement(e, "AGEGROUPS")
        for j, (a, b) in enumerate(groepen):
            ET.SubElement(ag, "AGEGROUP", agegroupid=str(9000 + i * 10 + j), agemin=str(a), agemax=str(b))
    return b'<?xml version="1.0" encoding="UTF-8"?>\n' + ET.tostring(root, encoding="utf-8")


class Command(BaseCommand):
    help = "Demo-wedstrijden met inschrijvingen, uitslagen en meldingen."

    def add_arguments(self, parser):
        parser.add_argument("--verwijder", action="store_true")

    @transaction.atomic
    def handle(self, *args, verwijder=False, **opts):
        demo = Wedstrijd.objects.filter(lenex_bestand__startswith=PREFIX)
        if verwijder:
            n = demo.count()
            demo.delete()
            User.objects.filter(username__in=[g[0] for g in GEZINNEN]).delete()
            self.stdout.write(f"{n} demo-wedstrijden en de extra demo-gezinnen verwijderd.")
            return

        call_command("demo_accounts", stdout=self.stdout)
        for account, vn, nm, kinderen in GEZINNEN:
            u, _ = User.objects.get_or_create(username=account, defaults={
                "first_name": vn, "last_name": nm, "email": f"{account}@demo.khzs.be"})
            u.set_password("demo")
            u.save()
            Profiel.objects.update_or_create(user=u, defaults={"rol": Profiel.ZWEMMER})
            for kvn, gd, gs in kinderen:
                Zwemmer.objects.get_or_create(account=u, voornaam=kvn, naam=nm, defaults={"geboortedatum": gd, "geslacht": gs})

        demo.delete()  # opnieuw opbouwen, zodat de data altijd consistent is
        rnd = random.Random(2026)
        zwemmers = list(Zwemmer.objects.filter(account__email__endswith="@demo.khzs.be").select_related("account"))
        trainer = User.objects.get(username="trainer1")

        for naam, stad, zwembad, baan, dag, start, deadline, status, kost, info, nummers in WEDSTRIJDEN:
            xml = maak_lenex(naam, stad, zwembad, baan, dag, start, deadline, kost, nummers)
            bestand = ContentFile(xml, name=f"demo-{dag:%Y%m%d}.lef")
            w = lenex.importeer(lenex.parse_meet(lenex.lees_xml(xml)), bestand)
            w.status, w.info = status, info
            w.dagen_secretaris, w.dagen_trainer = 3, 2
            w.save()
            verleden = dag < date.today()

            for z in zwemmers:
                jaren = leeftijd(z, w)
                mogelijk = [n for s in w.sessies.prefetch_related("nummers__leeftijdsgroepen") for n in s.nummers.all()
                            if n.inschrijfbaar and past_in_nummer(z, n, jaren)]
                if not mogelijk:
                    continue
                keuze = rnd.choices([Deelname.DOET_MEE, Deelname.INTERESSE, Deelname.DOET_NIET_MEE, None],
                                    [5, 2, 1, 1] if status != Wedstrijd.CONCEPT else [2, 4, 1, 3])[0]
                if keuze is None:
                    continue
                d = Deelname.objects.create(zwemmer=z, wedstrijd=w, keuze=keuze)
                if keuze != Deelname.DOET_MEE or status == Wedstrijd.CONCEPT:
                    continue
                for n in rnd.sample(mogelijk, k=min(len(mogelijk), rnd.randint(2, 4))):
                    beste = z.tijden.filter(afstand=n.afstand, slag=n.slag).order_by("tijd").first()
                    tijd = beste.tijd if beste else (richttijd(z, n.afstand, n.slag, dag, rnd) if rnd.random() < .7 else None)
                    st = Inschrijving.GOEDGEKEURD if verleden else rnd.choice(
                        [Inschrijving.INGEDIEND, Inschrijving.INGEDIEND, Inschrijving.GOEDGEKEURD, Inschrijving.AFGEKEURD])
                    Inschrijving.objects.create(zwemmer=z, nummer=n, status=st, inschrijftijd=tijd,
                                                inschrijfbaan=(beste.baan if beste else w.baan) if tijd else "",
                                                opmerking="eerst 50 m proberen" if st == Inschrijving.AFGEKEURD else "")
                    if st == Inschrijving.AFGEKEURD:
                        Melding.objects.create(zwemmer=z, wedstrijd=w, door=trainer, gelezen=False,
                                               tekst=f"afgekeurd: #{n.nummer} {n.omschrijving} (eerst 50 m proberen)")
                if not verleden and rnd.random() < .15:
                    d.geweigerd, d.weigerreden = True, "Nog niet in de wedstrijdgroep"
                    d.save()
                    Inschrijving.objects.filter(zwemmer=z, nummer__sessie__wedstrijd=w).update(
                        status=Inschrijving.AFGEKEURD, opmerking="deelname geweigerd")
                    Melding.objects.create(zwemmer=z, wedstrijd=w, door=trainer,
                                           tekst="deelname geweigerd: Nog niet in de wedstrijdgroep")

            if verleden:
                w.deelnames.filter(keuze=Deelname.DOET_MEE).update(gefinaliseerd=True, ingegeven=True)
            elif status == Wedstrijd.OPEN:  # een paar zwemmers al gefinaliseerd, één ervan al ingegeven
                for i, d in enumerate(w.deelnames.filter(keuze=Deelname.DOET_MEE, geweigerd=False)[:3]):
                    d.gefinaliseerd, d.gefinaliseerd_door, d.ingegeven = True, trainer, i == 0
                    d.save()
                    Inschrijving.objects.filter(zwemmer=d.zwemmer, nummer__sessie__wedstrijd=w,
                                                status=Inschrijving.INGEDIEND).update(status=Inschrijving.GOEDGEKEURD)

            if verleden:  # uitslagen verwerken → beste tijden
                resultaten = []
                for i in Inschrijving.objects.filter(nummer__sessie__wedstrijd=w).select_related("zwemmer", "nummer"):
                    basis = i.inschrijftijd or richttijd(i.zwemmer, i.nummer.afstand, i.nummer.slag, dag, rnd)
                    z = i.zwemmer
                    resultaten.append({"voornaam": z.voornaam, "naam": z.naam, "geboortedatum": z.geboortedatum,
                                       "licentie": z.licentie, "geslacht": z.geslacht, "afstand": i.nummer.afstand,
                                       "slag": i.nummer.slag, "baan": w.baan, "datum": dag, "wedstrijd": w.naam,
                                       "tijd": basis * rnd.uniform(0.96, 1.02)})
                lenex.importeer_uitslagen(resultaten)

        self.stdout.write(self.style.SUCCESS(
            f"{len(WEDSTRIJDEN)} demo-wedstrijden, {len(zwemmers)} demo-zwemmers, "
            f"{Inschrijving.objects.filter(nummer__sessie__wedstrijd__lenex_bestand__startswith=PREFIX).count()} inschrijvingen."))
