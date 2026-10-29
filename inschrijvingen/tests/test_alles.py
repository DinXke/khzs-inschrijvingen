import io
import zipfile
from datetime import date, timedelta
from pathlib import Path

from django.contrib.auth.models import User
from django.core.files.uploadedfile import SimpleUploadedFile
from django.test import TestCase

from inschrijvingen import lenex
from inschrijvingen.models import BesteTijd, Inschrijving, Nummer, Profiel, Wedstrijd, Zwemmer
from inschrijvingen.regels import leeftijd, parse_invoer_tijd, programma_voor, toon_tijd

VK = Path(__file__).parent / "fixtures" / "vk_kb_jeugd.lxf"

# Minimale Lenex 2-stijl uitnodiging: geen deadline, geen agegroups, kost op wedstrijdniveau, aflossing, gemengd.
OUD = b"""<?xml version="1.0" encoding="ISO-8859-1"?>
<LENEX version="2.0"><MEETS><MEET name="Clubwedstrijd \xe9t\xe9" city="Gent" course="LCM">
<FEES><FEE value="5.50"/></FEES>
<SESSIONS><SESSION number="1" date="2026-12-05" daytime="09:00">
<EVENTS>
<EVENT eventid="1" number="1" gender="M"><SWIMSTYLE distance="50" stroke="FREE" relaycount="1"/></EVENT>
<EVENT eventid="2" number="2" gender="X"><SWIMSTYLE distance="50" stroke="FREE" relaycount="4"/></EVENT>
<EVENT eventid="3" number="3"><SWIMSTYLE distance="100" stroke="UNKNOWN" name="Kinderestafette"/></EVENT>
<EVENT eventid="4" number="4" gender="F" round="FIN" preveventid="1"><SWIMSTYLE distance="50" stroke="FREE"/></EVENT>
</EVENTS></SESSION></SESSIONS></MEET></MEETS></LENEX>"""


def upload(data, naam="x.lxf"):
    return SimpleUploadedFile(naam, data)


class LenexTest(TestCase):
    def test_vk_bestand(self):
        meet = lenex.parse_meet(lenex.lees_xml(VK.read_bytes()))
        self.assertEqual(meet["deadline"], date(2026, 10, 16))
        self.assertEqual(len(meet["sessies"]), 6)
        self.assertEqual(sum(len(s["nummers"]) for s in meet["sessies"]), 104)
        self.assertEqual(meet["baan"], "SCM")
        self.assertEqual(meet["agedate"], date(2026, 11, 1))
        finales = [n for s in meet["sessies"] for n in s["nummers"] if n["ronde"] == "FIN"]
        self.assertEqual(len(finales), 28)
        self.assertFalse(any(n["inschrijfbaar"] for n in finales))
        self.assertTrue(meet["limietlijsten"])

    def test_oud_en_onvolledig_bestand(self):
        meet = lenex.parse_meet(lenex.lees_xml(OUD))
        self.assertEqual(meet["naam"], "Clubwedstrijd été")
        self.assertEqual(meet["deadline"], date(2026, 11, 28))
        self.assertTrue(meet["waarschuwingen"])
        n = {x["eventid"]: x for x in meet["sessies"][0]["nummers"]}
        self.assertEqual(n["1"]["kost_cent"], 550)
        self.assertEqual(n["1"]["leeftijdsgroepen"], [(-1, -1)])
        self.assertEqual(n["2"]["aflossing"], 4)
        self.assertFalse(n["3"]["inschrijfbaar"])
        self.assertFalse(n["4"]["inschrijfbaar"])

    def test_kale_lef_en_fouten(self):
        self.assertEqual(lenex.lees_xml(OUD).tag, "LENEX")
        with self.assertRaises(lenex.LenexFout):
            lenex.lees_xml(b"geen xml")
        with self.assertRaises(lenex.LenexFout):
            lenex.parse_meet(lenex.lees_xml(b"<LENEX><MEETS/></LENEX>"))
        bom = b'<!DOCTYPE x [<!ENTITY a "aaaa"><!ENTITY b "&a;&a;&a;">]><LENEX>&b;</LENEX>'
        with self.assertRaises(lenex.LenexFout):
            lenex.lees_xml(bom)

    def test_tijden(self):
        self.assertEqual(lenex.parse_tijd("00:01:02.86"), timedelta(seconds=62.86))
        self.assertEqual(lenex.format_tijd(timedelta(seconds=62.86)), "00:01:02.86")
        self.assertEqual(parse_invoer_tijd("1:02,86"), timedelta(seconds=62.86))
        self.assertEqual(parse_invoer_tijd("34.1"), timedelta(seconds=34.1))
        self.assertEqual(toon_tijd(timedelta(seconds=62.86)), "1:02.86")


class StroomTest(TestCase):
    def setUp(self):
        self.sec = User.objects.create_user("sec@khzs.be", "sec@khzs.be", "geheim123!")
        self.sec.profiel.rol = Profiel.SECRETARIS
        self.sec.profiel.save()
        self.tr = User.objects.create_user("tr@khzs.be", "tr@khzs.be", "geheim123!")
        self.tr.profiel.rol = Profiel.TRAINER
        self.tr.profiel.save()
        self.c = self.client
        self.c.force_login(self.sec)
        r = self.c.post("/wedstrijd/nieuw/", {"bestand": upload(VK.read_bytes(), "vk.lxf")})
        self.assertEqual(r.status_code, 302)
        self.w = Wedstrijd.objects.get()
        haai = [f"{n}|{b}" for n, b in {(l.naam, l.baan) for l in self.w.alle_limietlijsten.all()} if n.startswith("Haai")]
        self.c.post(f"/wedstrijd/{self.w.pk}/instellingen/", {
            "naam": self.w.naam, "deadline": "2026-10-16", "dagen_secretaris": 3, "status": "open", "limietgroepen": haai,
        })
        self.w.refresh_from_db()

    def test_deadlines(self):
        self.assertEqual(self.w.deadline_trainer, date(2026, 10, 13))
        self.assertEqual(self.w.deadline_zwemmer, date(2026, 10, 11))
        self.c.force_login(self.tr)
        self.c.post(f"/wedstrijd/{self.w.pk}/nakijken/", {"dagen_trainer": 5})
        self.w.refresh_from_db()
        self.assertEqual(self.w.deadline_zwemmer, date(2026, 10, 8))

    def test_volledige_stroom(self):
        self.c.logout()
        r = self.c.post("/registreren/", {
            "email": "ouder@test.be", "password1": "Zwemmen-2026!", "password2": "Zwemmen-2026!",
            "voornaam": "Lien", "naam": "Peeters", "geboortedatum": "2013-03-02", "geslacht": "F",
        })
        self.assertEqual(r.status_code, 302, r.content[:2000])
        z = Zwemmer.objects.get(voornaam="Lien")
        self.assertEqual(leeftijd(z, self.w), 13)

        jaren, programma = programma_voor(z, self.w)
        nummers = [b.nummer for _, rijen in programma for b in rijen]
        self.assertTrue(nummers)
        self.assertTrue(all(n.geslacht == "F" for n in nummers))
        self.assertTrue(all(any(g.bevat(13) for g in n.leeftijdsgroepen.all()) for n in nummers))

        n100sl = Nummer.objects.get(sessie__wedstrijd=self.w, nummer=2)  # 100 schoolslag dames 13-14
        # Zonder tijd: geen limiet → geweigerd (vóór de deadline).
        with self.settings(USE_TZ=True):
            from unittest import mock
            with mock.patch("inschrijvingen.regels.vandaag", return_value=date(2026, 10, 1)):
                self.c.post(f"/wedstrijd/{self.w.pk}/inschrijven/", {"zwemmer": z.pk, "nummer": [n100sl.pk]})
                self.assertFalse(Inschrijving.objects.exists())
                BesteTijd.objects.create(zwemmer=z, afstand=100, slag="BREAST", baan="LCM",
                                         tijd=timedelta(seconds=70), datum=date(2026, 5, 1))
                self.c.post(f"/wedstrijd/{self.w.pk}/inschrijven/", {"zwemmer": z.pk, "nummer": [n100sl.pk]})
                i = Inschrijving.objects.get()
                self.assertEqual(i.status, Inschrijving.INGEDIEND)
                self.assertEqual(i.inschrijftijd, timedelta(seconds=70))
            # Na de deadline mag de zwemmer niet meer wijzigen.
            with mock.patch("inschrijvingen.regels.vandaag", return_value=date(2026, 10, 12)):
                self.c.post(f"/wedstrijd/{self.w.pk}/inschrijven/", {"zwemmer": z.pk})
                self.assertTrue(Inschrijving.objects.exists())

        self.c.force_login(self.tr)
        r = self.c.get(f"/wedstrijd/{self.w.pk}/nakijken/")
        self.assertContains(r, "Peeters")
        self.c.post(f"/wedstrijd/{self.w.pk}/beoordelen/", {"actie": "goedgekeurd", "inschrijving": [i.pk]})
        i.refresh_from_db()
        self.assertEqual(i.status, Inschrijving.GOEDGEKEURD)

        # Trainer mag niet exporteren, secretaris wel.
        self.assertEqual(self.c.get(f"/wedstrijd/{self.w.pk}/export.lxf").status_code, 403)
        self.c.force_login(self.sec)
        r = self.c.get(f"/wedstrijd/{self.w.pk}/export.lxf")
        self.assertEqual(r.status_code, 200)
        with zipfile.ZipFile(io.BytesIO(r.content)) as zf:
            xml = zf.read(zf.namelist()[0])
        root = lenex.lees_xml(xml)
        entry = root.find(".//ATHLETE/ENTRIES/ENTRY")
        self.assertEqual(entry.get("eventid"), n100sl.eventid)
        self.assertEqual(entry.get("entrytime"), "00:01:10.00")
        self.assertEqual(root.find(".//ATHLETE").get("birthdate"), "2013-03-02")
        self.assertEqual(self.c.get(f"/wedstrijd/{self.w.pk}/export.xlsx").status_code, 200)

    def test_herupload_behoudt_inschrijvingen(self):
        z = Zwemmer.objects.create(account=self.sec, voornaam="A", naam="B", geboortedatum=date(2013, 1, 1), geslacht="M")
        n = Nummer.objects.get(sessie__wedstrijd=self.w, nummer=1)
        Inschrijving.objects.create(zwemmer=z, nummer=n)
        self.c.post(f"/wedstrijd/{self.w.pk}/opnieuw/", {"bestand": upload(VK.read_bytes(), "vk.lxf")})
        self.assertEqual(Wedstrijd.objects.count(), 1)
        self.assertEqual(Inschrijving.objects.count(), 1)
        self.assertTrue(self.w.limietlijsten.exists())

    def test_zwemmer_ziet_andermans_zwemmer_niet(self):
        andere = User.objects.create_user("x@x.be", "x@x.be", "geheim123!")
        z = Zwemmer.objects.create(account=self.sec, voornaam="A", naam="B", geboortedatum=date(2013, 1, 1), geslacht="M")
        self.c.force_login(andere)
        self.assertEqual(self.c.get(f"/zwemmers/{z.pk}/").status_code, 404)
        self.assertEqual(self.c.get(f"/wedstrijd/{self.w.pk}/nakijken/").status_code, 403)

    def test_pwa(self):
        self.assertEqual(self.c.get("/manifest.webmanifest")["Content-Type"], "application/manifest+json")
        self.assertEqual(self.c.get("/sw.js").status_code, 200)


class WijzigTest(StroomTest):
    def _zwemmer(self):
        ouder = User.objects.create_user("o@o.be", "o@o.be", "geheim123!")
        z = Zwemmer.objects.create(account=ouder, voornaam="Lien", naam="P", geboortedatum=date(2013, 3, 2), geslacht="F")
        BesteTijd.objects.create(zwemmer=z, afstand=100, slag="BREAST", baan="LCM",
                                 tijd=timedelta(seconds=70), datum=date(2026, 5, 1))
        return ouder, z

    def test_zwemmer_schrapt_goedgekeurde_inschrijving(self):
        from unittest import mock
        ouder, z = self._zwemmer()
        n = Nummer.objects.get(sessie__wedstrijd=self.w, nummer=2)
        Inschrijving.objects.create(zwemmer=z, nummer=n, status=Inschrijving.GOEDGEKEURD)
        self.c.force_login(ouder)
        with mock.patch("inschrijvingen.regels.vandaag", return_value=date(2026, 10, 1)):
            self.c.post(f"/wedstrijd/{self.w.pk}/inschrijven/", {"zwemmer": z.pk})
        self.assertFalse(Inschrijving.objects.exists())

    def test_trainer_wijzigt_en_zwemmer_ziet_melding(self):
        from unittest import mock
        from inschrijvingen.models import Melding
        ouder, z = self._zwemmer()
        n2 = Nummer.objects.get(sessie__wedstrijd=self.w, nummer=2)
        n6 = Nummer.objects.get(sessie__wedstrijd=self.w, nummer=6)
        Inschrijving.objects.create(zwemmer=z, nummer=n2, inschrijftijd=timedelta(seconds=70), inschrijfbaan="LCM")
        self.c.force_login(self.tr)
        with mock.patch("inschrijvingen.regels.vandaag", return_value=date(2026, 10, 12)):
            self.c.post(f"/wedstrijd/{self.w.pk}/inschrijven/",
                        {"zwemmer": z.pk, "nummer": [n2.pk, n6.pk], f"tijd_{n2.pk}": "1:08.50"})
        i2 = Inschrijving.objects.get(nummer=n2)
        self.assertEqual(i2.inschrijftijd, timedelta(seconds=68.5))
        self.assertTrue(Inschrijving.objects.filter(nummer=n6).exists())
        m = Melding.objects.get()
        self.assertIn("1:08.50", m.tekst)
        self.assertIn("toegevoegd: #6", m.tekst)

        self.c.post(f"/wedstrijd/{self.w.pk}/beoordelen/", {"actie": "afgekeurd", "inschrijving": [i2.pk], "opmerking": "te traag"})
        self.assertEqual(Melding.objects.count(), 2)

        self.c.force_login(ouder)
        r = self.c.get("/wedstrijden/")
        self.assertContains(r, "gewijzigd door de club")
        r = self.c.get(f"/wedstrijd/{self.w.pk}/?zwemmer={z.pk}")
        self.assertContains(r, "Trainer Tom".split()[0])
        self.assertContains(r, "te traag")
        self.assertFalse(Melding.objects.filter(gelezen=False).exists())

    def test_eigen_wijziging_geeft_geen_melding(self):
        from unittest import mock
        from inschrijvingen.models import Melding
        ouder, z = self._zwemmer()
        n2 = Nummer.objects.get(sessie__wedstrijd=self.w, nummer=2)
        self.c.force_login(ouder)
        with mock.patch("inschrijvingen.regels.vandaag", return_value=date(2026, 10, 1)):
            self.c.post(f"/wedstrijd/{self.w.pk}/inschrijven/", {"zwemmer": z.pk, "nummer": [n2.pk]})
        self.assertTrue(Inschrijving.objects.exists())
        self.assertFalse(Melding.objects.exists())


class PushTest(TestCase):
    def setUp(self):
        import tempfile
        self.tmp = tempfile.TemporaryDirectory()
        self.over = self.settings(VAPID_FILE=f"{self.tmp.name}/v.pem", PUSH_SYNCHROON=True)
        self.over.enable()
        self.sec = User.objects.create_user("sec@khzs.be", "sec@khzs.be", "geheim123!")  # eerste = secretaris
        self.ouder = User.objects.create_user("o@o.be", "o@o.be", "geheim123!")
        self.client.force_login(self.ouder)
        r = self.client.post("/meldingen/abonneer/", data='{"endpoint":"https://push.example/abc","keys":{"p256dh":"x","auth":"y"}}',
                             content_type="application/json")
        self.assertEqual(r.status_code, 200)

    def tearDown(self):
        self.over.disable()
        self.tmp.cleanup()

    def test_eerste_account_is_secretaris(self):
        self.assertEqual(self.sec.profiel.rol, Profiel.SECRETARIS)
        self.assertEqual(self.ouder.profiel.rol, Profiel.ZWEMMER)

    def test_pagina_en_sleutel(self):
        r = self.client.get("/meldingen/")
        self.assertContains(r, "Meldingen aanzetten")
        self.assertEqual(len(__import__("inschrijvingen.push", fromlist=["x"]).publieke_sleutel()), 87)

    def test_nieuwe_wedstrijd_en_herinnering(self):
        from unittest import mock
        from django.core.management import call_command
        from inschrijvingen.models import Deelname
        z = Zwemmer.objects.create(account=self.ouder, voornaam="L", naam="P", geboortedatum=date(2013, 3, 2), geslacht="F")
        self.client.force_login(self.sec)
        with mock.patch("pywebpush.webpush") as wp:
            self.client.post("/wedstrijd/nieuw/", {"bestand": upload(VK.read_bytes(), "vk.lxf")})
            self.assertEqual(wp.call_count, 1)
            self.assertIn("Nieuwe wedstrijd", wp.call_args.kwargs["data"])
            w = Wedstrijd.objects.get()
            Deelname.objects.create(zwemmer=z, wedstrijd=w, keuze="interesse")
            self.client.post(f"/wedstrijd/{w.pk}/instellingen/", {
                "naam": w.naam, "deadline": "2026-10-16", "dagen_secretaris": 3, "status": "open"})
            self.assertEqual(wp.call_count, 2)
            self.assertIn("Inschrijvingen open", wp.call_args.kwargs["data"])
            w.refresh_from_db()
            with mock.patch("inschrijvingen.management.commands.herinneringen.vandaag",
                            return_value=w.deadline_zwemmer - timedelta(days=1)):
                call_command("herinneringen", stdout=io.StringIO())
                call_command("herinneringen", stdout=io.StringIO())  # geen dubbele
            self.assertEqual(wp.call_count, 3)
            self.assertIn("Morgen", wp.call_args.kwargs["data"])

    def test_voorkeur_uit_geen_push(self):
        from unittest import mock
        p = self.ouder.profiel
        p.push_nieuwe_wedstrijd = False
        p.save()
        self.client.force_login(self.sec)
        with mock.patch("pywebpush.webpush") as wp:
            self.client.post("/wedstrijd/nieuw/", {"bestand": upload(VK.read_bytes(), "vk.lxf")})
            w = Wedstrijd.objects.get()
            self.client.post(f"/wedstrijd/{w.pk}/instellingen/", {
                "naam": w.naam, "deadline": "2026-10-16", "dagen_secretaris": 3, "status": "open"})
            self.assertEqual(wp.call_count, 0)


class DeelnameTest(TestCase):
    def setUp(self):
        self.sec = User.objects.create_user("sec@khzs.be", "sec@khzs.be", "geheim123!")
        self.client.force_login(self.sec)
        self.client.post("/wedstrijd/nieuw/", {"bestand": upload(VK.read_bytes(), "vk.lxf")})
        self.w = Wedstrijd.objects.get()
        self.ouder = User.objects.create_user("o@o.be", "o@o.be", "geheim123!")
        self.z = Zwemmer.objects.create(account=self.ouder, voornaam="Lien", naam="P", geboortedatum=date(2013, 3, 2), geslacht="F")

    def test_interesse_bij_concept(self):
        from unittest import mock
        from inschrijvingen.models import Deelname
        self.client.force_login(self.ouder)
        with mock.patch("inschrijvingen.views.vandaag", return_value=date(2026, 9, 1)):
            r = self.client.get("/wedstrijden/")
        self.assertContains(r, "Nog niet open")
        r = self.client.get(f"/wedstrijd/{self.w.pk}/?zwemmer={self.z.pk}")
        self.assertContains(r, "Doe je mee?")
        self.client.post(f"/wedstrijd/{self.w.pk}/deelname/", {"zwemmer": self.z.pk, "keuze": "interesse"})
        self.assertEqual(Deelname.objects.get().keuze, "interesse")
        self.client.force_login(self.sec)
        r = self.client.get(f"/wedstrijd/{self.w.pk}/nakijken/")
        self.assertContains(r, "Interesse · 1")

    def test_herinnering_enkel_bij_interesse(self):
        import tempfile
        from unittest import mock
        from django.core.management import call_command
        from inschrijvingen.models import Deelname, PushAbonnement
        andere = User.objects.create_user("a@a.be", "a@a.be", "geheim123!")
        Zwemmer.objects.create(account=andere, voornaam="X", naam="Y", geboortedatum=date(2013, 1, 1), geslacht="M")
        for u in (self.ouder, andere):
            PushAbonnement.objects.create(user=u, endpoint=f"https://push.example/{u.pk}", p256dh="x", auth="y")
        Deelname.objects.create(zwemmer=self.z, wedstrijd=self.w, keuze="doet_mee")
        self.w.status = "open"
        self.w.save()
        with tempfile.TemporaryDirectory() as d, self.settings(VAPID_FILE=f"{d}/v.pem", PUSH_SYNCHROON=True), \
                mock.patch("pywebpush.webpush") as wp, \
                mock.patch("inschrijvingen.management.commands.herinneringen.vandaag",
                           return_value=self.w.deadline_zwemmer - timedelta(days=1)):
            call_command("herinneringen", stdout=io.StringIO())
        self.assertEqual(wp.call_count, 1)
        self.assertIn(f"/{self.ouder.pk}", wp.call_args.kwargs["subscription_info"]["endpoint"])
