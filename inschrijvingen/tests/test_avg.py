"""AVG: privacyverklaring, akkoord bij registratie, toestemming ouder, gegevens downloaden, account verwijderen, opkuis."""
import json
from datetime import date, timedelta
from io import StringIO

from django.contrib.auth.models import User
from django.core.management import call_command
from django.test import TestCase, override_settings
from django.utils import timezone

from inschrijvingen import avg
from inschrijvingen.models import (BesteTijd, Melding, PloegLid, Ploeg, Profiel, PushAbonnement, Rolaanvraag, Wedstrijd,
                                   Zwemmer)
from inschrijvingen.rollen import zet_rollen

REGISTRATIE = {"password1": "Zwemmen-2026!", "password2": "Zwemmen-2026!", "voornaam": "Ann", "naam": "Peeters"}


def account(login, *rollen_, voornaam=""):
    u = User.objects.create_user(login, login, "geheim123!", first_name=voornaam)
    zet_rollen(u, rollen_)
    return u


def zwemmer(acc, voornaam="Lien", gd=date(2013, 3, 2)):
    return Zwemmer.objects.create(account=acc, voornaam=voornaam, naam="P", geboortedatum=gd, geslacht="F")


class PrivacyTest(TestCase):
    def test_pagina_zonder_aanmelden_en_gelinkt(self):
        r = self.client.get("/privacy/")
        self.assertContains(r, "Hasseltse Zwemvereniging Spartacus")
        self.assertContains(r, "Gegevensbeschermingsautoriteit")
        self.assertContains(r, "sessionid")  # cookievermelding
        self.assertNotContains(r, "Ik heb de privacyverklaring gelezen en ga akkoord")  # knop enkel aangemeld
        self.assertContains(self.client.get("/account/login/"), 'href="/privacy/"')
        self.assertContains(self.client.get("/registreren/"), 'href="/privacy/"')

    @override_settings(PRIVACY={"verantwoordelijke": "Club X vzw", "adres": "Zwembadlaan 1, 3500 Hasselt", "ondernemingsnummer": "0123.456.789",
                                "email": "privacy@club.be", "versie": "2027-01", "extra": "Onze DPO is Jan."})
    def test_instelbare_gegevens(self):
        r = self.client.get("/privacy/")
        for tekst in ("Club X vzw", "Zwembadlaan 1, 3500 Hasselt", "0123.456.789", "mailto:privacy@club.be", "2027-01", "Onze DPO is Jan."):
            self.assertContains(r, tekst)

    def test_registratie_vraagt_akkoord(self):
        r = self.client.post("/registreren/", {**REGISTRATIE, "email": "nieuw@test.be"})
        self.assertEqual(r.status_code, 200)
        self.assertContains(r, "Je moet de privacyverklaring aanvaarden")
        self.assertFalse(User.objects.filter(username="nieuw@test.be").exists())
        r = self.client.post("/registreren/", {**REGISTRATIE, "email": "nieuw@test.be", "privacy": "on"})
        self.assertEqual(r["Location"], "/welkom/")
        p = User.objects.get(username="nieuw@test.be").profiel
        self.assertIsNotNone(p.privacy_akkoord)
        self.assertEqual(p.privacy_versie, avg.versie())
        self.assertNotContains(self.client.get("/welkom/"), "Lees hoe de club met je gegevens omgaat")

    def test_bestaand_account_en_nieuwe_versie(self):
        u = account("oud@test.be", Profiel.OUDER)
        self.client.force_login(u)
        self.assertContains(self.client.get("/zwemmers/"), "Lees hoe de club met je gegevens omgaat")
        r = self.client.post("/privacy/")
        self.assertEqual(r["Location"], "/privacy/mijn-gegevens/")
        self.assertNotContains(self.client.get("/zwemmers/"), "Lees hoe de club met je gegevens omgaat")
        with self.settings(PRIVACY={**avg.instellingen(), "versie": "2099-01-01"}):
            self.assertContains(self.client.get("/zwemmers/"), "Lees hoe de club met je gegevens omgaat")
            self.assertContains(self.client.get("/privacy/mijn-gegevens/"), "Er is een nieuwe versie")


class ToestemmingTest(TestCase):
    def setUp(self):
        self.ouder = account("ouder@test.be", Profiel.OUDER, voornaam="Ann")
        self.client.force_login(self.ouder)

    def kind(self, gd, **extra):
        return self.client.post("/welkom/?stap=kinderen", {"actie": "kind", "voornaam": "Mo", "naam": "Q", "geboortedatum": gd,
                                                           "geslacht": "M", **extra})

    def test_minderjarige_vraagt_toestemming(self):
        r = self.kind("2014-05-05")
        self.assertContains(r, "toestemming van een ouder of voogd nodig")
        self.assertFalse(Zwemmer.objects.exists())
        self.kind("2014-05-05", toestemming="on")
        z = Zwemmer.objects.get()
        self.assertIsNotNone(z.toestemming_op)
        self.assertEqual(z.toestemming_door, self.ouder)
        # Ook via + Zwemmer
        r = self.client.post("/zwemmers/nieuw/", {"voornaam": "Lot", "naam": "Q", "geboortedatum": "2016-01-01", "geslacht": "F"})
        self.assertContains(r, "toestemming van een ouder of voogd nodig")

    def test_meerderjarige_zonder_toestemming(self):
        self.kind("1990-05-05")
        self.assertIsNone(Zwemmer.objects.get().toestemming_op)

    def test_toestemming_achteraf(self):
        z = zwemmer(self.ouder)  # van vóór de AVG-functies
        self.assertContains(self.client.get(f"/zwemmers/{z.pk}/"), "Ik geef toestemming")
        self.client.post(f"/zwemmers/{z.pk}/", {"actie": "toestemming"})
        z.refresh_from_db()
        self.assertIsNotNone(z.toestemming_op)
        self.assertNotContains(self.client.get(f"/zwemmers/{z.pk}/"), "Ik geef toestemming")
        # Een trainer (ziet de zwemmer, maar is geen ouder) krijgt de vraag niet.
        z.toestemming_op = None
        z.save()
        self.client.force_login(account("tr@test.be", Profiel.TRAINER))
        self.assertNotContains(self.client.get(f"/zwemmers/{z.pk}/"), "Ik geef toestemming")


class RechtenTest(TestCase):
    def setUp(self):
        self.ouder = account("ouder@test.be", Profiel.OUDER, voornaam="Ann")
        self.papa = account("papa@test.be", Profiel.OUDER, voornaam="Piet")
        self.vreemde = account("vreemde@test.be", Profiel.OUDER)
        self.lien = zwemmer(self.ouder, "Lien")
        self.lien.extra_accounts.add(self.papa)  # gedeeld met de andere ouder
        self.bram = zwemmer(self.ouder, "Bram")  # enkel van dit account
        self.anders = zwemmer(self.vreemde, "Anders")
        BesteTijd.objects.create(zwemmer=self.lien, afstand=50, slag="FREE", baan="SCM", tijd=timedelta(seconds=33.12),
                                 datum=date(2026, 5, 1))
        self.ploeg = Ploeg.objects.create(naam="A-groep")
        PloegLid.objects.create(zwemmer=self.bram, ploeg=self.ploeg, status=PloegLid.GOEDGEKEURD)
        PushAbonnement.objects.create(user=self.ouder, endpoint="https://push.example/1", p256dh="x", auth="y", toestel="Firefox")
        self.client.force_login(self.ouder)

    def test_download(self):
        r = self.client.get("/privacy/mijn-gegevens.json")
        self.assertIn("attachment", r["Content-Disposition"])
        d = json.loads(r.content)
        self.assertEqual(d["account"]["e-mail"], "ouder@test.be")
        self.assertEqual(sorted(z["voornaam"] for z in d["zwemmers"]), ["Bram", "Lien"])  # niet die van een ander
        lien = next(z for z in d["zwemmers"] if z["voornaam"] == "Lien")
        self.assertEqual(lien["beste_tijden"][0]["tijd"], "33.12")
        self.assertEqual(lien["accounts"], ["Ann", "Piet"])
        self.assertEqual(d["toestellen_met_pushmeldingen"][0]["toestel"], "Firefox")
        self.assertNotIn("p256dh", r.content.decode())  # geen sleutels
        self.assertEqual(self.client.get("/privacy/mijn-gegevens/").status_code, 200)

    def test_download_vraagt_aanmelden(self):
        self.client.logout()
        self.assertEqual(self.client.get("/privacy/mijn-gegevens.json").status_code, 302)

    def test_verwijderen_met_gedeelde_zwemmer(self):
        r = self.client.get("/privacy/mijn-gegevens/")
        self.assertContains(r, "Deze zwemmers verdwijnen mee")
        self.assertContains(r, "Bram P")
        self.assertContains(r, "Deze zwemmers blijven")
        # Fout wachtwoord of geen bevestiging: niets gebeurt.
        self.client.post("/privacy/verwijderen/", {"bevestig": "VERWIJDER", "wachtwoord": "fout"})
        self.client.post("/privacy/verwijderen/", {"bevestig": "nee", "wachtwoord": "geheim123!"})
        self.assertTrue(User.objects.filter(pk=self.ouder.pk).exists())
        r = self.client.post("/privacy/verwijderen/", {"bevestig": "VERWIJDER", "wachtwoord": "geheim123!"})
        self.assertEqual(r["Location"], "/account/login/")
        self.assertFalse(User.objects.filter(pk=self.ouder.pk).exists())
        self.assertFalse(PushAbonnement.objects.exists())
        self.assertFalse(Zwemmer.objects.filter(pk=self.bram.pk).exists())
        self.assertFalse(PloegLid.objects.exists())
        self.lien.refresh_from_db()
        self.assertEqual(self.lien.account, self.papa)  # de andere ouder houdt Lien, met haar tijden
        self.assertEqual(self.lien.tijden.count(), 1)
        self.assertTrue(Zwemmer.objects.filter(pk=self.anders.pk).exists())
        self.assertEqual(self.client.get("/zwemmers/").status_code, 302)  # afgemeld

    def test_beheerdersaccount_niet_zelf(self):
        admin = User.objects.create_superuser("admin@test.be", "admin@test.be", "geheim123!")
        self.client.force_login(admin)
        self.assertContains(self.client.get("/privacy/mijn-gegevens/"), "Vraag het aan een andere beheerder")
        self.client.post("/privacy/verwijderen/", {"bevestig": "VERWIJDER", "wachtwoord": "geheim123!"})
        self.assertTrue(User.objects.filter(pk=admin.pk).exists())


class OpkuisTest(TestCase):
    def setUp(self):
        self.ouder = account("ouder@test.be", Profiel.OUDER)
        self.lien = zwemmer(self.ouder)
        self.w = Wedstrijd.objects.create(naam="Test", baan="SCM", deadline=date(2026, 12, 1), lenex_bestand="x.lxf")

    def oud(self, obj, veld, dagen):
        type(obj).objects.filter(pk=obj.pk).update(**{veld: timezone.now() - timedelta(days=dagen)})

    def test_bewaartermijnen(self):
        oud_m = Melding.objects.create(zwemmer=self.lien, wedstrijd=self.w, tekst="oud")
        nieuw_m = Melding.objects.create(zwemmer=self.lien, wedstrijd=self.w, tekst="nieuw")
        self.oud(oud_m, "aangemaakt", 400)
        afgehandeld = Rolaanvraag.objects.create(user=self.ouder, rol=Profiel.TRAINER, status=Rolaanvraag.AFGEKEURD,
                                                 behandeld_op=timezone.now() - timedelta(days=400))
        open_ = Rolaanvraag.objects.create(user=self.ouder, rol=Profiel.BESTUURDER)
        self.oud(open_, "aangemaakt", 400)
        inactief = account("weg@test.be", Profiel.OUDER)
        User.objects.filter(pk=inactief.pk).update(last_login=timezone.now() - timedelta(days=400))
        PushAbonnement.objects.create(user=inactief, endpoint="https://push.example/oud", p256dh="x", auth="y")
        User.objects.filter(pk=self.ouder.pk).update(last_login=timezone.now())
        PushAbonnement.objects.create(user=self.ouder, endpoint="https://push.example/actief", p256dh="x", auth="y")
        Zwemmer.objects.filter(pk=self.lien.pk).update(koppelcode="ABCDEFGH", koppelcode_tot=timezone.now() - timedelta(days=1))

        uit = StringIO()
        call_command("herinneringen", stdout=uit)  # de opkuis draait mee met de dagelijkse herinneringen
        self.assertIn("Opkuis: koppelcodes 1, meldingen 1", uit.getvalue())
        self.assertEqual(list(Melding.objects.all()), [nieuw_m])
        self.assertFalse(Rolaanvraag.objects.filter(pk=afgehandeld.pk).exists())
        self.assertTrue(Rolaanvraag.objects.filter(pk=open_.pk).exists())  # open aanvragen blijven
        self.assertEqual(list(PushAbonnement.objects.values_list("endpoint", flat=True)), ["https://push.example/actief"])
        self.lien.refresh_from_db()
        self.assertEqual((self.lien.koppelcode, self.lien.koppelcode_tot), ("", None))
        self.assertEqual(avg.opkuis()["meldingen"], 0)  # tweede keer: niets meer
