"""Ploegen: aanmaken, aanvragen, goedkeuren of afkeuren, wie ziet wat, en wie bij een ploeg hoort."""
from datetime import date
from unittest import mock

from django.contrib.auth.models import User
from django.test import TestCase

from inschrijvingen import ploegen
from inschrijvingen.models import Ploeg, PloegLid, Profiel, PushAbonnement, Zwemmer
from inschrijvingen.rollen import zet_rollen


def account(login, *rollen_, voornaam=""):
    u = User.objects.create_user(login, login, "geheim123!", first_name=voornaam)
    zet_rollen(u, rollen_)
    return u


class PloegenTest(TestCase):
    def setUp(self):
        self.bestuur = account("bestuur@khzs.be", Profiel.BESTUURDER)
        self.tr_a = account("tra@khzs.be", Profiel.TRAINER, voornaam="Tom")
        self.tr_b = account("trb@khzs.be", Profiel.TRAINER)
        self.ouder = account("ouder@khzs.be", Profiel.OUDER, voornaam="Ann")
        self.papa = account("papa@khzs.be", Profiel.OUDER)
        self.andere = account("andere@khzs.be", Profiel.OUDER)
        self.lien = Zwemmer.objects.create(account=self.ouder, voornaam="Lien", naam="P", geboortedatum=date(2013, 3, 2), geslacht="F")
        self.lien.extra_accounts.add(self.papa)
        self.bram = Zwemmer.objects.create(account=self.andere, voornaam="Bram", naam="Q", geboortedatum=date(2012, 1, 1), geslacht="M")
        self.client.force_login(self.bestuur)
        self.client.post("/ploegen/", {"naam": "A-groep", "beschrijving": "Wedstrijdzwemmers", "trainers": [self.tr_a.pk], "actief": "on"})
        self.client.post("/ploegen/", {"naam": "B-groep", "trainers": [self.tr_b.pk], "actief": "on"})
        self.a, self.b = Ploeg.objects.get(naam="A-groep"), Ploeg.objects.get(naam="B-groep")
        self.client.get("/ploegen/")  # meldingen "aangemaakt" opgebruikt
        self.push = mock.patch("inschrijvingen.push.stuur", return_value=1)
        self.stuur = self.push.start()

    def tearDown(self):
        self.push.stop()

    def vraag(self, zwemmer=None, ploeg=None, wie=None):
        self.client.force_login(wie or self.ouder)
        self.client.post(f"/zwemmers/{(zwemmer or self.lien).pk}/ploeg/", {"ploeg": (ploeg or self.a).pk})
        return PloegLid.objects.get(zwemmer=zwemmer or self.lien, ploeg=ploeg or self.a)

    def test_aanmaken(self):
        self.assertEqual(list(self.a.trainers.all()), [self.tr_a])
        # enkel bestuur, sportsecretaris of beheerder maakt ploegen aan
        self.client.force_login(self.tr_a)
        self.assertEqual(self.client.post("/ploegen/", {"naam": "C", "actief": "on"}).status_code, 403)
        self.assertFalse(Ploeg.objects.filter(naam="C").exists())

    def test_aanvragen_en_goedkeuren_door_trainer_van_de_ploeg(self):
        PushAbonnement.objects.create(user=self.tr_a, endpoint="https://push.example/a", p256dh="x", auth="y")
        PushAbonnement.objects.create(user=self.tr_b, endpoint="https://push.example/b", p256dh="x", auth="y")
        lid = self.vraag()
        self.assertEqual(lid.status, PloegLid.OPEN)
        self.assertEqual(list(self.stuur.call_args.args[0]), [self.tr_a])  # trainer van de A-groep, niet die van B
        # een trainer van een andere ploeg mag niet beslissen
        self.client.force_login(self.tr_b)
        self.client.post(f"/ploegen/lid/{lid.pk}/", {"actie": "goedkeuren"})
        lid.refresh_from_db()
        self.assertEqual(lid.status, PloegLid.OPEN)
        self.client.force_login(self.tr_a)
        self.assertContains(self.client.get("/ploegen/"), "Aanvragen (1)")
        self.client.post(f"/ploegen/lid/{lid.pk}/", {"actie": "goedkeuren"})
        lid.refresh_from_db()
        self.assertEqual(lid.status, PloegLid.GOEDGEKEURD)
        self.assertEqual(set(self.stuur.call_args.args[0]), {self.ouder, self.papa})  # beide ouders
        self.assertIn("Lien zit nu in de ploeg A-groep", self.stuur.call_args.args[2])
        # melding in de app tot de zwemmer bekeken is
        self.client.force_login(self.papa)
        self.assertContains(self.client.get("/wedstrijden/"), "zit nu in A-groep")
        self.client.get(f"/zwemmers/{self.lien.pk}/")
        self.assertNotContains(self.client.get("/wedstrijden/"), "zit nu in A-groep")

    def test_afkeuren_vraagt_reden_en_opnieuw_aanvragen(self):
        lid = self.vraag()
        self.client.force_login(self.bestuur)
        self.client.post(f"/ploegen/lid/{lid.pk}/", {"actie": "afkeuren", "reden": ""})
        lid.refresh_from_db()
        self.assertEqual(lid.status, PloegLid.OPEN)
        self.client.post(f"/ploegen/lid/{lid.pk}/", {"actie": "afkeuren", "reden": "Eerst de B-groep."})
        lid.refresh_from_db()
        self.assertEqual(lid.status, PloegLid.AFGEKEURD)
        self.assertIn("afgekeurd. Eerst de B-groep.", self.stuur.call_args.args[2])
        self.assertEqual(self.vraag().status, PloegLid.OPEN)  # opnieuw aanvragen kan

    def test_staf_zet_zwemmer_meteen_in_ploeg(self):
        self.client.force_login(self.tr_a)
        self.client.post(f"/ploegen/{self.a.pk}/", {"actie": "toevoegen", "zwemmer": self.bram.pk})
        self.assertEqual(PloegLid.objects.get(zwemmer=self.bram).status, PloegLid.GOEDGEKEURD)
        # wie zijn eigen zwemmer in een ploeg zet die hij zelf traint: meteen, zonder melding
        kind = Zwemmer.objects.create(account=self.tr_a, voornaam="Kid", naam="T", geboortedatum=date(2014, 1, 1), geslacht="M")
        self.assertEqual(self.vraag(kind, self.a, self.tr_a).status, PloegLid.GOEDGEKEURD)
        self.assertTrue(PloegLid.objects.get(zwemmer=kind).gelezen)

    def test_wie_ziet_wat(self):
        PloegLid.objects.create(zwemmer=self.lien, ploeg=self.a, status=PloegLid.GOEDGEKEURD)
        # ouder: enkel de eigen ploeg
        self.client.force_login(self.ouder)
        r = self.client.get("/ploegen/")
        self.assertContains(r, "A-groep")
        self.assertNotContains(r, "B-groep")
        self.assertEqual(self.client.get(f"/ploegen/{self.b.pk}/").status_code, 403)
        self.assertEqual(self.client.get(f"/ploegen/{self.a.pk}/").status_code, 200)
        # trainers, bestuur en beheer zien alle ploegen
        for u in (self.tr_b, self.bestuur):
            self.client.force_login(u)
            r = self.client.get("/ploegen/")
            self.assertContains(r, "A-groep")
            self.assertContains(r, "B-groep")

    def test_wie_hoort_bij_een_ploeg(self):
        """Basis voor de latere communicatie per ploeg."""
        PloegLid.objects.create(zwemmer=self.lien, ploeg=self.a, status=PloegLid.GOEDGEKEURD)
        PloegLid.objects.create(zwemmer=self.bram, ploeg=self.a, status=PloegLid.OPEN)  # nog niet goedgekeurd
        self.assertEqual(list(ploegen.zwemmers_van(self.a)), [self.lien])
        self.assertEqual(set(ploegen.accounts_van(self.a)), {self.ouder, self.papa, self.tr_a})
        self.assertEqual(set(ploegen.lezers_van(self.a)), {self.ouder, self.papa, self.tr_a, self.tr_b, self.bestuur})
        self.assertEqual(list(ploegen.zichtbare_ploegen(self.andere)), [])
        self.assertEqual(list(ploegen.eigen_ploegen(self.tr_b)), [self.b])

    def test_ouder_trekt_in_of_haalt_uit_ploeg(self):
        lid = self.vraag()
        self.client.post(f"/ploegen/lid/{lid.pk}/", {"actie": "verwijderen"})
        self.assertFalse(PloegLid.objects.exists())
        # een vreemde kan dat niet
        lid = self.vraag()
        self.client.force_login(self.andere)
        self.client.post(f"/ploegen/lid/{lid.pk}/", {"actie": "verwijderen"})
        self.assertTrue(PloegLid.objects.exists())
        # en kan geen ploeg kiezen voor andermans zwemmer
        self.assertEqual(self.client.post(f"/zwemmers/{self.lien.pk}/ploeg/", {"ploeg": self.b.pk}).status_code, 404)

    def test_gearchiveerde_ploeg_niet_kiesbaar(self):
        self.client.post(f"/ploegen/{self.b.pk}/", {"actie": "bewaar", "naam": "B-groep"})  # actief uit
        self.b.refresh_from_db()
        self.assertFalse(self.b.actief)
        self.vraag(ploeg=self.a)
        self.client.post(f"/zwemmers/{self.lien.pk}/ploeg/", {"ploeg": self.b.pk})
        self.assertFalse(PloegLid.objects.filter(ploeg=self.b).exists())

    def test_kind_met_ploeg_in_het_stappenplan(self):
        self.client.force_login(self.andere)
        self.client.post("/welkom/?stap=kinderen", {"actie": "kind", "voornaam": "Mo", "naam": "Q", "geboortedatum": "2014-05-05",
                                                    "geslacht": "M", "ploeg": self.b.pk})
        self.assertEqual(PloegLid.objects.get(zwemmer__voornaam="Mo").status, PloegLid.OPEN)
