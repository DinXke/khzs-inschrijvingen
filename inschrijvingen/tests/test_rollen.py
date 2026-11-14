"""Meerdere rollen per account: aanvragen, goedkeuren, afkeuren, een andere rol geven, rechten en de migratie."""
from unittest import mock

from django.contrib.auth.models import User
from django.db import connection
from django.db.migrations.executor import MigrationExecutor
from django.test import TestCase, TransactionTestCase

from inschrijvingen.models import Profiel, Rol, Rolaanvraag
from inschrijvingen.rollen import heeft_rol, hoofdrol, rollen_van, vul_ontbrekende, zet_rollen

REGISTRATIE = {"password1": "Zwemmen-2026!", "password2": "Zwemmen-2026!", "voornaam": "Ann", "naam": "Peeters"}


def rollen(user):
    return set(Rol.objects.filter(user=user).values_list("rol", flat=True))


class RollenTest(TestCase):
    def setUp(self):
        self.baas = User.objects.create_user("baas@khzs.be", "baas@khzs.be", "geheim123!", is_superuser=True)
        self.bestuur = User.objects.create_user("bestuur@khzs.be", "bestuur@khzs.be", "geheim123!", first_name="Bert")
        zet_rollen(self.bestuur, [Profiel.BESTUURDER])
        self.lid = User.objects.create_user("lid@khzs.be", "lid@khzs.be", "geheim123!", first_name="Lien", last_name="P")
        zet_rollen(self.lid, [Profiel.OUDER])
        self.push = mock.patch("inschrijvingen.push.stuur", return_value=1)
        self.stuur = self.push.start()

    def tearDown(self):
        self.push.stop()

    def aanvraag(self, rol=Profiel.TRAINER):
        self.client.force_login(self.lid)
        self.client.post("/rollen/", {"rol": rol, "motivatie": "B-groep"})
        return Rolaanvraag.objects.get(user=self.lid, rol=rol)

    def test_aanvragen_en_goedkeuren(self):
        a = self.aanvraag()
        self.assertEqual(a.status, Rolaanvraag.OPEN)
        self.assertEqual(a.motivatie, "B-groep")
        self.assertFalse(heeft_rol(User.objects.get(pk=self.lid.pk), Profiel.TRAINER))
        # tweede keer dezelfde aanvraag: geweigerd
        self.client.post("/rollen/", {"rol": Profiel.TRAINER})
        self.assertEqual(Rolaanvraag.objects.filter(user=self.lid).count(), 1)
        # de bestuurder ziet de aanvraag en keurt goed
        self.client.force_login(self.bestuur)
        r = self.client.get("/gebruikers/")
        self.assertContains(r, "Rolaanvragen (1)")
        self.assertContains(r, "B-groep")
        self.client.post(f"/gebruikers/aanvraag/{a.pk}/", {"actie": "goedkeuren"})
        a.refresh_from_db()
        self.assertEqual(a.status, Rolaanvraag.GOEDGEKEURD)
        self.assertEqual(a.behandeld_door, self.bestuur)
        self.assertEqual(rollen(self.lid), {Profiel.OUDER, Profiel.TRAINER})
        self.assertEqual(Profiel.objects.get(user=self.lid).rol, Profiel.TRAINER)  # hoofdrol
        # push naar het lid
        users, titel, tekst, url = self.stuur.call_args.args
        self.assertEqual(list(users), [self.lid])
        self.assertIn("goedgekeurd", tekst)
        self.assertEqual(url, "/rollen/")
        # melding in de app, tot het lid Mijn rollen opent
        self.client.force_login(self.lid)
        self.assertContains(self.client.get("/wedstrijden/"), "Je aanvraag voor de rol trainer competitie is goedgekeurd.")
        self.client.get("/rollen/")
        self.assertNotContains(self.client.get("/wedstrijden/"), "is goedgekeurd")
        # nu trainer: rechten van de staf, en de eigen zwemmers blijven
        self.assertEqual(self.client.get("/zwemmers/uitslagen/").status_code, 200)

    def test_afkeuren_vraagt_reden(self):
        a = self.aanvraag(Profiel.SECRETARIS)
        self.client.force_login(self.bestuur)
        self.client.post(f"/gebruikers/aanvraag/{a.pk}/", {"actie": "afkeuren", "reden": ""})
        a.refresh_from_db()
        self.assertEqual(a.status, Rolaanvraag.OPEN)
        self.client.post(f"/gebruikers/aanvraag/{a.pk}/", {"actie": "afkeuren", "reden": "Vraag het eerst aan het bestuur."})
        a.refresh_from_db()
        self.assertEqual(a.status, Rolaanvraag.AFGEKEURD)
        self.assertEqual(rollen(self.lid), {Profiel.OUDER})
        self.assertIn("afgekeurd. Vraag het eerst aan het bestuur.", self.stuur.call_args.args[2])
        # al behandeld: niet opnieuw
        self.client.post(f"/gebruikers/aanvraag/{a.pk}/", {"actie": "goedkeuren"})
        self.assertEqual(rollen(self.lid), {Profiel.OUDER})
        self.client.force_login(self.lid)
        r = self.client.get("/rollen/")
        self.assertContains(r, "Afgekeurd")
        self.assertContains(r, "Vraag het eerst aan het bestuur.")

    def test_andere_rol_toekennen(self):
        a = self.aanvraag(Profiel.SECRETARIS)
        self.client.force_login(self.bestuur)
        self.client.post(f"/gebruikers/aanvraag/{a.pk}/", {"actie": "andere", "rol": Profiel.TRAINER, "reden": "Eerst als trainer."})
        a.refresh_from_db()
        self.assertEqual(a.status, Rolaanvraag.ANDERE_ROL)
        self.assertEqual(a.toegekende_rol, Profiel.TRAINER)
        self.assertEqual(rollen(self.lid), {Profiel.OUDER, Profiel.TRAINER})
        self.assertIn("kreeg de rol trainer", self.stuur.call_args.args[2])

    def test_zwemmer_en_ouder_zonder_goedkeuring(self):
        self.client.force_login(self.lid)
        self.client.post("/rollen/", {"rol": Profiel.ZWEMMER})
        self.assertEqual(rollen(self.lid), {Profiel.OUDER, Profiel.ZWEMMER})
        self.assertFalse(Rolaanvraag.objects.exists())
        # beheerder kan je niet aanvragen
        self.client.post("/rollen/", {"rol": Profiel.BEHEERDER})
        self.assertFalse(Rolaanvraag.objects.exists())

    def test_intrekken(self):
        a = self.aanvraag()
        self.client.post("/rollen/", {"actie": "intrekken", "aanvraag": a.pk})
        a.refresh_from_db()
        self.assertEqual(a.status, Rolaanvraag.INGETROKKEN)
        self.client.force_login(self.bestuur)
        self.assertNotContains(self.client.get("/gebruikers/"), "Rolaanvragen")

    def test_rollen_in_het_stappenplan(self):
        r = self.client.post("/registreren/", {**REGISTRATIE, "email": "nieuw@test.be"})
        self.assertEqual(r["Location"], "/welkom/")
        u = User.objects.get(username="nieuw@test.be")
        self.assertEqual(rollen(u), set())  # start zonder rol
        self.client.post("/welkom/?stap=kinderen", {"actie": "kind", "voornaam": "Lien", "naam": "Peeters",
                                                    "geboortedatum": "2013-03-02", "geslacht": "F"})
        self.assertEqual(rollen(u), {Profiel.OUDER})
        r = self.client.post("/welkom/?stap=staf", {"rollen": [Profiel.TRAINER, Profiel.BEHEERDER], "motivatie": "B-groep"})
        self.assertEqual(r["Location"], "/welkom/?stap=klaar")
        self.assertEqual(list(Rolaanvraag.objects.filter(user=u).values_list("rol", "motivatie")), [(Profiel.TRAINER, "B-groep")])
        self.assertContains(self.client.get("/welkom/?stap=klaar"), "Trainer competitie (wacht)")

    def test_rechtstreeks_toevoegen_en_afnemen(self):
        a = self.aanvraag()
        self.client.force_login(self.bestuur)
        self.client.post("/gebruikers/", {"user": self.lid.pk, "actie": "toevoegen", "rol": Profiel.TRAINER})
        self.assertEqual(rollen(self.lid), {Profiel.OUDER, Profiel.TRAINER})
        a.refresh_from_db()
        self.assertEqual(a.status, Rolaanvraag.GOEDGEKEURD)  # openstaande aanvraag meteen afgehandeld
        self.client.post("/gebruikers/", {"user": self.lid.pk, "actie": "afnemen", "rol": Profiel.OUDER})
        self.assertEqual(rollen(self.lid), {Profiel.TRAINER})
        # de laatste rol kan niet weg
        self.client.post("/gebruikers/", {"user": self.lid.pk, "actie": "afnemen", "rol": Profiel.TRAINER})
        self.assertEqual(rollen(self.lid), {Profiel.TRAINER})

    def test_bestuurder_beheert_enkel_leden_en_rollen(self):
        self.client.force_login(self.bestuur)
        self.assertEqual(self.client.get("/gebruikers/").status_code, 200)
        self.assertEqual(self.client.get("/wedstrijd/nieuw/").status_code, 403)
        self.assertEqual(self.client.get("/zwemmers/uitslagen/").status_code, 403)
        self.assertEqual(self.client.get("/backup/").status_code, 403)
        # geen beheerder aanstellen, en de rollen van een beheerder niet wijzigen
        self.client.post("/gebruikers/", {"user": self.lid.pk, "actie": "toevoegen", "rol": Profiel.BEHEERDER})
        self.assertEqual(rollen(self.lid), {Profiel.OUDER})
        self.client.post("/gebruikers/", {"user": self.baas.pk, "actie": "toevoegen", "rol": Profiel.TRAINER})
        self.assertNotIn(Profiel.TRAINER, rollen(self.baas))
        # een zwemmer of ouder ziet het gebruikersscherm niet
        self.client.force_login(self.lid)
        self.assertEqual(self.client.get("/gebruikers/").status_code, 403)

    def test_beheerder_stelt_beheerder_aan(self):
        self.client.force_login(self.baas)
        self.client.post("/gebruikers/", {"user": self.bestuur.pk, "actie": "toevoegen", "rol": Profiel.BEHEERDER})
        self.assertEqual(rollen(self.bestuur), {Profiel.BESTUURDER, Profiel.BEHEERDER})
        # een superuser blijft altijd beheerder
        self.client.post("/gebruikers/", {"user": self.baas.pk, "actie": "afnemen", "rol": Profiel.BEHEERDER})
        self.assertTrue(heeft_rol(User.objects.get(pk=self.baas.pk), Profiel.BEHEERDER))

    def test_rechten_van_meerdere_rollen_samen(self):
        zet_rollen(self.lid, [Profiel.OUDER, Profiel.TRAINER, Profiel.BESTUURDER])
        u = User.objects.get(pk=self.lid.pk)
        self.assertEqual(hoofdrol(u), Profiel.TRAINER)
        self.client.force_login(u)
        self.assertEqual(self.client.get("/zwemmers/uitslagen/").status_code, 200)  # trainer
        self.assertEqual(self.client.get("/gebruikers/").status_code, 200)  # bestuurder
        self.assertEqual(self.client.get("/wedstrijd/nieuw/").status_code, 403)  # geen sportsecretaris

    def test_sportsecretaris_behandelt_aanvragen(self):
        sec = User.objects.create_user("sec@khzs.be", "sec@khzs.be", "geheim123!")
        zet_rollen(sec, [Profiel.SECRETARIS])
        a = self.aanvraag(Profiel.BESTUURDER)
        self.client.force_login(sec)
        self.client.post(f"/gebruikers/aanvraag/{a.pk}/", {"actie": "goedkeuren"})
        self.assertIn(Profiel.BESTUURDER, rollen(self.lid))

    def test_nieuwe_aanvraag_meldt_rolbeheerders(self):
        from inschrijvingen.models import PushAbonnement
        PushAbonnement.objects.create(user=self.bestuur, endpoint="https://push.example/b", p256dh="x", auth="y")
        PushAbonnement.objects.create(user=self.lid, endpoint="https://push.example/l", p256dh="x", auth="y")
        self.aanvraag()
        users, titel, tekst, url = self.stuur.call_args.args
        self.assertEqual(list(users), [self.bestuur])
        self.assertEqual(titel, "Nieuwe rolaanvraag")
        self.assertIn("Lien P vraagt de rol trainer", tekst)

    def test_oude_backup_zonder_rollen(self):
        Rol.objects.filter(user=self.lid).delete()
        Profiel.objects.filter(user=self.lid).update(rol=Profiel.TRAINER)
        self.assertEqual(rollen_van(User.objects.get(pk=self.lid.pk)), {Profiel.TRAINER})  # terugvaloptie
        vul_ontbrekende()
        self.assertEqual(rollen(self.lid), {Profiel.TRAINER})


class MigratieTest(TransactionTestCase):
    """0012_rollen: iedereen houdt de rol die hij had."""

    van, naar = [("inschrijvingen", "0011_zwemmer_accounts")], [("inschrijvingen", "0012_rollen")]

    def test_bestaande_rollen_blijven(self):
        executor = MigrationExecutor(connection)
        executor.migrate(self.van)
        apps = executor.loader.project_state(self.van).apps
        OudUser = apps.get_model("auth", "User")
        OudProfiel = apps.get_model("inschrijvingen", "Profiel")
        verwacht = {}
        for naam, rol, su in [("z", "zwemmer", False), ("t", "trainer", False), ("s", "secretaris", False),
                              ("b", "beheerder", False), ("su", "zwemmer", True)]:
            u = OudUser.objects.create(username=naam, is_superuser=su)
            OudProfiel.objects.create(user=u, rol=rol)
            verwacht[naam] = {rol, "beheerder"} if su else {rol}
        OudUser.objects.create(username="zonder-profiel")
        verwacht["zonder-profiel"] = {"zwemmer"}

        executor = MigrationExecutor(connection)
        executor.loader.build_graph()
        executor.migrate(self.naar)
        apps = executor.loader.project_state(self.naar).apps
        Rol_ = apps.get_model("inschrijvingen", "Rol")
        for naam, rollen_ in verwacht.items():
            self.assertEqual(set(Rol_.objects.filter(user__username=naam).values_list("rol", flat=True)), rollen_, naam)
        self.assertEqual(apps.get_model("inschrijvingen", "Profiel").objects.get(user__username="su").rol, "beheerder")
        # en terug
        executor = MigrationExecutor(connection)
        executor.loader.build_graph()
        executor.migrate(self.van)
        executor = MigrationExecutor(connection)
        executor.loader.build_graph()
        executor.migrate(executor.loader.graph.leaf_nodes())
