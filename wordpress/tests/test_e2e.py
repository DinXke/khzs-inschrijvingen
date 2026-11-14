"""End-to-end-tests van de WordPress-plugin, dezelfde scenario's als inschrijvingen/tests/test_alles.py.

Vereist een testsite met de plugin en tests/khzs-test-helper.php (als mu-plugin), bv. op http://localhost:8080:
    KHZS_URL=http://localhost:8080 python3 wordpress/tests/test_e2e.py
"""
import base64
import io
import os
import re
import shutil
import subprocess
import sys
import time
import unittest
import zipfile
from pathlib import Path
from urllib.parse import quote
from xml.etree import ElementTree as ET

import requests

BASIS = os.environ.get("KHZS_URL", "http://localhost:8080").rstrip("/")
APP = BASIS + "/leden/"
HIER = Path(__file__).resolve().parent
VK = (HIER.parent.parent / "inschrijvingen" / "tests" / "fixtures" / "vk_kb_jeugd.lxf").read_bytes()

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

UITSLAG = b"""<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="Kerstcriterium" course="LCM"><SESSIONS>
<SESSION number="1" date="2026-12-20"><EVENTS>
<EVENT eventid="10" number="1" gender="F"><SWIMSTYLE distance="100" stroke="BREAST" relaycount="1"/></EVENT>
<EVENT eventid="11" number="2" gender="F"><SWIMSTYLE distance="50" stroke="FREE" relaycount="1"/></EVENT>
</EVENTS></SESSION></SESSIONS>
<CLUBS><CLUB name="HZS"><ATHLETES>
<ATHLETE firstname="Lien" lastname="Peeters" birthdate="2013-03-02" gender="F"><RESULTS>
<RESULT eventid="10" swimtime="00:01:18.20"/><RESULT eventid="11" swimtime="00:00:33.00" status="DSQ"/>
</RESULTS></ATHLETE>
<ATHLETE firstname="Iemand" lastname="Anders" birthdate="2012-01-01" gender="F"><RESULTS><RESULT eventid="11" swimtime="00:00:30.00"/></RESULTS></ATHLETE>
</ATHLETES></CLUB></CLUBS></MEET></MEETS></LENEX>"""


def hulp(actie, **data):
    r = requests.post(f"{BASIS}/?khzs_test={actie}", json=data, timeout=30)
    r.raise_for_status()
    return r.json()


def sql(q):
    r = hulp("sql", sql=q)
    assert not r["fout"], r["fout"]
    return r["rijen"]


def exe(q):
    r = hulp("exec", sql=q)
    assert not r["fout"], r["fout"]
    return r


def een(q):
    rijen = sql(q)
    return next(iter(rijen[0].values())) if rijen else None


def gebruiker(login, rol=None, first_name="", wprol="subscriber", penningmeester=False):
    return hulp("user", login=login, rol=rol, first_name=first_name, wprol=wprol, penningmeester=penningmeester)["id"]


def zwemmer(account, voornaam="Lien", naam="P", gd="2013-03-02", geslacht="F", licentie=""):
    return exe(f"INSERT INTO {{p}}zwemmers (account_id, voornaam, naam, geboortedatum, geslacht, licentie, swimrankings_id) "
               f"VALUES ({account}, '{voornaam}', '{naam}', '{gd}', '{geslacht}', '{licentie}', '')")["id"]


def tijd(zid, afstand, slag, baan, hon, datum="2026-05-01", bron="manueel"):
    return exe(f"INSERT INTO {{p}}tijden (zwemmer_id, afstand, slag, baan, tijd, datum, wedstrijd, bron) "
               f"VALUES ({zid}, {afstand}, '{slag}', '{baan}', {hon}, '{datum}', '', '{bron}')")["id"]


def nummer_id(wid, nr):
    return int(een(f"SELECT n.id FROM {{p}}nummers n JOIN {{p}}sessies s ON s.id=n.sessie_id WHERE s.wedstrijd_id={wid} AND n.nummer={nr}"))


def inschrijving(zid, nid, status="ingediend", tijd_hon=None, baan=""):
    t = "NULL" if tijd_hon is None else tijd_hon
    return exe(f"INSERT INTO {{p}}inschrijvingen (zwemmer_id, nummer_id, inschrijftijd, inschrijfbaan, status, opmerking, aangemaakt) "
               f"VALUES ({zid}, {nid}, {t}, '{baan}', '{status}', '', NOW())")["id"]


def registreer(k, email, voornaam="Ann", naam="Peeters"):
    """Account aanmaken; daarna volgt het stappenplan (welkom)."""
    r = k.post("registreren/", {"email": email, "password1": "Zwemmen-2026!", "password2": "Zwemmen-2026!", "voornaam": voornaam, "naam": naam,
                                "privacy": "1"})
    assert r.status_code == 302 and r.headers["Location"].endswith("/leden/welkom/"), r.text[:2000]
    k.nonce = None
    return r


def kind(k, voornaam, naam, gd, geslacht, ploeg=""):
    return k.post("welkom/", {"actie": "kind", "voornaam": voornaam, "naam": naam, "geboortedatum": gd, "geslacht": geslacht,
                              "ploeg": ploeg, "toestemming": "1"}, query={"stap": "kinderen"})


class Klant:
    def __init__(self, app=APP):
        self.app = app
        self.s = requests.Session()
        self.nonce = None
        self.vandaag = None

    def _h(self):
        return {"X-Vandaag": self.vandaag} if self.vandaag else {}

    def login(self, login, pw="geheim123!"):
        self.s.cookies.clear()
        r = self.s.post(self.app + "aanmelden/", data={"username": login, "password": pw}, allow_redirects=False)
        assert r.status_code == 302, r.text[:500]
        self.nonce = None
        return self

    def logout(self):
        self.s.cookies.clear()
        self.nonce = None

    def get(self, pad, **params):
        return self.s.get(self.app + pad, params=params, allow_redirects=False, headers=self._h())

    def _nonce(self):
        if not self.nonce:
            html = self.s.get(self.app + "uitleg/", headers=self._h()).text
            m = re.search(r'name="_khzs" value="([^"]+)"', html)
            self.nonce = m.group(1) if m else ""
        return self.nonce

    def post(self, pad, data=None, files=None, query=None):
        data = dict(data or {})
        data.setdefault("_khzs", self._nonce())
        return self.s.post(self.app + pad, data=data, files=files, params=query, allow_redirects=False, headers=self._h())

    def json(self, pad, body):
        return self.s.post(self.app + pad, json=body, headers={"X-KHZS-Nonce": self._nonce(), **self._h()}, allow_redirects=False)


class Basis(unittest.TestCase):
    def setUp(self):
        hulp("reset")
        self.c = Klant()

    def assertContains(self, r, tekst):
        self.assertIn(tekst, r.text if hasattr(r, "text") else r)

    def assertNotContains(self, r, tekst):
        self.assertNotIn(tekst, r.text)


class LenexTest(Basis):
    def parse(self, data, soort="meet"):
        return hulp("parse", data=base64.b64encode(data).decode(), soort=soort)

    def test_vk_bestand(self):
        meet = self.parse(VK)["v"]
        self.assertEqual(meet["deadline"], "2026-10-16")
        self.assertEqual(len(meet["sessies"]), 6)
        self.assertEqual(sum(len(s["nummers"]) for s in meet["sessies"]), 104)
        self.assertEqual(meet["baan"], "SCM")
        self.assertEqual(meet["agedate"], "2026-11-01")
        finales = [n for s in meet["sessies"] for n in s["nummers"] if n["ronde"] == "FIN"]
        self.assertEqual(len(finales), 28)
        self.assertFalse(any(n["inschrijfbaar"] for n in finales))
        self.assertTrue(meet["limietlijsten"])

    def test_oud_en_onvolledig_bestand(self):
        meet = self.parse(OUD)["v"]
        self.assertEqual(meet["naam"], "Clubwedstrijd été")
        self.assertEqual(meet["deadline"], "2026-11-28")
        self.assertTrue(meet["waarschuwingen"])
        n = {x["eventid"]: x for x in meet["sessies"][0]["nummers"]}
        self.assertEqual(n["1"]["kost_cent"], 550)
        self.assertEqual(n["1"]["leeftijdsgroepen"], [[-1, -1]])
        self.assertEqual(n["2"]["aflossing"], 4)
        self.assertFalse(n["3"]["inschrijfbaar"])
        self.assertFalse(n["4"]["inschrijfbaar"])

    def test_kale_lef_en_fouten(self):
        self.assertIn("v", self.parse(OUD))
        self.assertEqual(self.parse(b"geen xml")["fout"], "LenexFout")
        self.assertEqual(self.parse(b"<LENEX><MEETS/></LENEX>")["fout"], "LenexFout")
        bom = b'<!DOCTYPE x [<!ENTITY a "aaaa"><!ENTITY b "&a;&a;&a;">]><LENEX>&b;</LENEX>'
        self.assertEqual(self.parse(bom)["fout"], "LenexFout")
        zipje = io.BytesIO()
        with zipfile.ZipFile(zipje, "w") as z:
            z.writestr("leeg.txt", "x")
        self.assertEqual(self.parse(zipje.getvalue())["fout"], "LenexFout")

    def test_tijden(self):
        self.assertEqual(hulp("eval", f="parse_tijd", args=["00:01:02.86"])["v"], 6286)
        self.assertEqual(hulp("eval", f="format_tijd", args=[6286])["v"], "00:01:02.86")
        self.assertEqual(hulp("eval", f="parse_invoer_tijd", args=["1:02,86"])["v"], 6286)
        self.assertEqual(hulp("eval", f="parse_invoer_tijd", args=["34.1"])["v"], 3410)
        self.assertEqual(hulp("eval", f="toon_tijd", args=[6286])["v"], "1:02.86")
        self.assertEqual(hulp("eval", f="parse_invoer_tijd", args=["abc"])["fout"], "InvalidArgumentException")


class StroomTest(Basis):
    def setUp(self):
        super().setUp()
        self.sec = gebruiker("sec@khzs.be", "secretaris")
        self.tr = gebruiker("tr@khzs.be", "trainer", first_name="Trainer")
        self.c.login("sec@khzs.be")
        r = self.c.post("wedstrijd/nieuw/", files={"bestand": ("vk.lxf", VK)})
        self.assertEqual(r.status_code, 302, r.text[:2000])
        self.w = int(een("SELECT id FROM {p}wedstrijden"))
        haai = sorted({f"{l['naam']}|{l['baan']}" for l in sql(f"SELECT naam, baan FROM {{p}}limietlijsten WHERE wedstrijd_id={self.w}")
                       if l["naam"].startswith("Haai")})
        self.assertTrue(haai)
        r = self.c.post(f"wedstrijd/{self.w}/instellingen/", {
            "naam": "VK KB Jeugd", "deadline": "2026-10-16", "dagen_secretaris": 3, "status": "open", "limietgroepen[]": haai,
        })
        self.assertEqual(r.status_code, 302, r.text[:2000])

    def test_deadlines(self):
        r = self.c.get(f"wedstrijd/{self.w}/instellingen/")
        self.assertContains(r, "Zwemmers tot<b>zo 11 Okt</b>")
        self.assertContains(r, "Trainers tot<b>di 13 Okt</b>")
        self.c.login("tr@khzs.be")
        self.c.post(f"wedstrijd/{self.w}/nakijken/", {"dagen_trainer": 5, "info": ""})
        r = self.c.get(f"wedstrijd/{self.w}/nakijken/")
        self.assertContains(r, "Zwemmers tot<b>do 8 Okt</b>")

    def test_volledige_stroom(self):
        self.c.logout()
        registreer(self.c, "ouder@test.be")
        r = kind(self.c, "Lien", "Peeters", "2013-03-02", "F")
        self.assertEqual(r.status_code, 302, r.text[:3000])
        z = int(een("SELECT id FROM {p}zwemmers"))
        r = self.c.get(f"wedstrijd/{self.w}/")
        self.assertContains(r, "categorie <b>13 jaar</b>")
        nummers = re.findall(r'name="nummer\[\]" value="(\d+)"', r.text)
        self.assertTrue(nummers)
        geslachten = {x["geslacht"] for x in sql(f"SELECT geslacht FROM {{p}}nummers WHERE id IN ({','.join(nummers)})")}
        self.assertLessEqual(geslachten, {"F", "X"})

        n100sl = nummer_id(self.w, 2)  # 100 schoolslag dames 13-14
        # Zonder tijd: geen limiet → geweigerd (vóór de deadline).
        self.c.vandaag = "2026-10-01"
        self.c.post(f"wedstrijd/{self.w}/inschrijven/", {"zwemmer": z, "nummer[]": [n100sl]})
        self.assertFalse(sql("SELECT id FROM {p}inschrijvingen"))
        tijd(z, 100, "BREAST", "LCM", 7000)
        self.c.post(f"wedstrijd/{self.w}/inschrijven/", {"zwemmer": z, "nummer[]": [n100sl]})
        i = sql("SELECT * FROM {p}inschrijvingen")
        self.assertEqual(len(i), 1, "limiet zou gehaald moeten zijn")
        self.assertEqual(i[0]["status"], "ingediend")
        self.assertEqual(int(i[0]["inschrijftijd"]), 7000)
        # Na de deadline kan het niet meer.
        self.c.vandaag = "2026-10-12"
        self.c.post(f"wedstrijd/{self.w}/inschrijven/", {"zwemmer": z})
        self.assertTrue(sql("SELECT id FROM {p}inschrijvingen"))

        self.c.login("tr@khzs.be")
        r = self.c.get(f"wedstrijd/{self.w}/nakijken/")
        self.assertContains(r, "Peeters")
        self.c.post(f"wedstrijd/{self.w}/beoordelen/", {"actie": "goedgekeurd", "inschrijving[]": [i[0]["id"]]})
        self.assertEqual(een("SELECT status FROM {p}inschrijvingen"), "goedgekeurd")

        self.assertEqual(self.c.get(f"wedstrijd/{self.w}/export.lxf").status_code, 403)
        self.c.login("sec@khzs.be")
        r = self.c.get(f"wedstrijd/{self.w}/export.lxf")
        self.assertEqual(r.status_code, 200)
        with zipfile.ZipFile(io.BytesIO(r.content)) as zf:
            root = ET.fromstring(zf.read(zf.namelist()[0]))
        entry = root.find(".//ENTRY")
        self.assertEqual(entry.get("eventid"), een(f"SELECT eventid FROM {{p}}nummers WHERE id={n100sl}"))
        self.assertEqual(entry.get("entrytime"), "00:01:10.00")
        self.assertEqual(root.find(".//ATHLETE").get("birthdate"), "2013-03-02")
        r = self.c.get(f"wedstrijd/{self.w}/export.xlsx")
        self.assertEqual(r.status_code, 200)
        with zipfile.ZipFile(io.BytesIO(r.content)) as zf:
            blad = zf.read("xl/worksheets/sheet1.xml").decode()
            self.assertIn("Peeters", blad)
            self.assertIn("1:10.00", blad)
            ET.fromstring(zf.read("xl/styles.xml"))
            ET.fromstring(zf.read("xl/worksheets/sheet2.xml"))

    def test_herupload_behoudt_inschrijvingen(self):
        o = gebruiker("o@o.be")
        z = zwemmer(o)
        inschrijving(z, nummer_id(self.w, 2))
        r = self.c.post(f"wedstrijd/{self.w}/opnieuw/", files={"bestand": ("vk.lxf", VK)})
        self.assertEqual(r.status_code, 302)
        self.assertEqual(int(een("SELECT COUNT(*) FROM {p}wedstrijden")), 1)
        self.assertEqual(int(een("SELECT COUNT(*) FROM {p}inschrijvingen")), 1)
        self.assertTrue(int(een("SELECT COUNT(*) FROM {p}limietlijsten WHERE gekozen=1")))

    def test_zwemmer_ziet_andermans_zwemmer_niet(self):
        a = gebruiker("a@a.be")
        gebruiker("b@b.be")
        z = zwemmer(a)
        self.c.login("b@b.be")
        self.assertEqual(self.c.get(f"zwemmers/{z}/").status_code, 404)
        self.assertEqual(self.c.get(f"wedstrijd/{self.w}/nakijken/").status_code, 403)
        self.assertEqual(self.c.get(f"wedstrijd/{self.w}/", zwemmer=z).status_code, 404)

    def test_pwa(self):
        self.assertIn("application/manifest+json", self.c.get("manifest.webmanifest").headers["Content-Type"])
        self.assertEqual(self.c.get("sw.js").status_code, 200)
        self.assertEqual(self.c.get("offline/").status_code, 200)
        self.assertContains(self.c.get("installeer/"), "Installeer de app")
        self.assertIn("khzs-pwa", requests.get(BASIS + "/").text)  # melding op de website

    def test_zonder_nonce_geweigerd(self):
        r = self.c.s.post(APP + f"wedstrijd/{self.w}/verwijderen/", data={}, allow_redirects=False)
        self.assertEqual(r.status_code, 403)
        self.assertEqual(int(een("SELECT COUNT(*) FROM {p}wedstrijden")), 1)


class WijzigTest(StroomTest):
    def _zwemmer(self):
        o = gebruiker("o@o.be")
        z = zwemmer(o)
        tijd(z, 100, "BREAST", "LCM", 7000)
        return z

    def test_zwemmer_schrapt_goedgekeurde_inschrijving(self):
        z = self._zwemmer()
        inschrijving(z, nummer_id(self.w, 2), "goedgekeurd")
        self.c.login("o@o.be")
        self.c.vandaag = "2026-10-01"
        self.c.post(f"wedstrijd/{self.w}/inschrijven/", {"zwemmer": z})
        self.assertFalse(sql("SELECT id FROM {p}inschrijvingen"))

    def test_trainer_wijzigt_en_zwemmer_ziet_melding(self):
        z = self._zwemmer()
        n2, n6 = nummer_id(self.w, 2), nummer_id(self.w, 6)
        inschrijving(z, n2, tijd_hon=7000, baan="LCM")
        self.c.login("tr@khzs.be")
        self.c.vandaag = "2026-10-12"
        self.c.post(f"wedstrijd/{self.w}/inschrijven/", {"zwemmer": z, "nummer[]": [n2, n6], f"tijd_{n2}": "1:08.50"})
        self.assertEqual(int(een(f"SELECT inschrijftijd FROM {{p}}inschrijvingen WHERE nummer_id={n2}")), 6850)
        self.assertTrue(sql(f"SELECT id FROM {{p}}inschrijvingen WHERE nummer_id={n6}"))
        m = sql("SELECT tekst FROM {p}meldingen")
        self.assertEqual(len(m), 1)
        self.assertIn("1:08.50", m[0]["tekst"])
        self.assertIn("toegevoegd: #6", m[0]["tekst"])
        i2 = een(f"SELECT id FROM {{p}}inschrijvingen WHERE nummer_id={n2}")
        self.c.post(f"wedstrijd/{self.w}/beoordelen/", {"actie": "afgekeurd", "inschrijving[]": [i2], "opmerking": "te traag"})
        self.assertEqual(int(een("SELECT COUNT(*) FROM {p}meldingen")), 2)

        self.c.login("o@o.be")
        self.c.vandaag = "2026-10-01"
        self.assertContains(self.c.get("wedstrijden/"), "gewijzigd door de club")
        r = self.c.get(f"wedstrijd/{self.w}/", zwemmer=z)
        self.assertContains(r, "Trainer Trainer")
        self.assertContains(r, "te traag")
        self.assertFalse(sql("SELECT id FROM {p}meldingen WHERE gelezen=0"))

    def test_eigen_wijziging_geeft_geen_melding(self):
        z = self._zwemmer()
        self.c.login("o@o.be")
        self.c.vandaag = "2026-10-01"
        self.c.post(f"wedstrijd/{self.w}/inschrijven/", {"zwemmer": z, "nummer[]": [nummer_id(self.w, 2)]})
        self.assertTrue(sql("SELECT id FROM {p}inschrijvingen"))
        self.assertFalse(sql("SELECT id FROM {p}meldingen"))

    def test_deelname_weigeren(self):
        z = self._zwemmer()
        n2 = nummer_id(self.w, 2)
        inschrijving(z, n2)
        self.c.login("tr@khzs.be")
        self.c.post(f"wedstrijd/{self.w}/deelname/beoordelen/", {"zwemmer": z, "actie": "weigeren", "reden": "nog niet klaar"})
        self.assertEqual(int(een("SELECT geweigerd FROM {p}deelnames")), 1)
        self.assertEqual(een("SELECT status FROM {p}inschrijvingen"), "afgekeurd")
        self.assertIn("nog niet klaar", een("SELECT tekst FROM {p}meldingen"))
        self.c.login("o@o.be")
        self.c.vandaag = "2026-10-01"
        r = self.c.get(f"wedstrijd/{self.w}/")
        self.assertContains(r, "Deelname geweigerd door de club: nog niet klaar")
        self.c.post(f"wedstrijd/{self.w}/inschrijven/", {"zwemmer": z})
        self.assertTrue(sql(f"SELECT id FROM {{p}}inschrijvingen WHERE nummer_id={n2}"))
        self.c.login("tr@khzs.be")
        self.c.post(f"wedstrijd/{self.w}/deelname/beoordelen/", {"zwemmer": z, "actie": "toestaan"})
        self.c.login("o@o.be")
        self.c.vandaag = "2026-10-01"
        self.c.post(f"wedstrijd/{self.w}/inschrijven/", {"zwemmer": z})
        self.assertFalse(sql(f"SELECT id FROM {{p}}inschrijvingen WHERE nummer_id={n2}"))
        self.c.post(f"wedstrijd/{self.w}/deelname/", {"zwemmer": z, "keuze": "doet_mee"})
        self.assertEqual(een("SELECT keuze FROM {p}deelnames"), "doet_mee")

    def test_finaliseren_en_ingegeven(self):
        z = self._zwemmer()
        inschrijving(z, nummer_id(self.w, 2))
        self.c.login("tr@khzs.be")
        self.c.post(f"wedstrijd/{self.w}/finaliseren/", {"zwemmer": "alle", "finaliseer": "finaliseer"})
        self.assertEqual(een("SELECT status FROM {p}inschrijvingen"), "goedgekeurd")
        self.assertEqual(int(een("SELECT gefinaliseerd FROM {p}deelnames")), 1)
        # Zwemmer kan niet meer wijzigen.
        self.c.login("o@o.be")
        self.c.vandaag = "2026-10-01"
        self.c.post(f"wedstrijd/{self.w}/inschrijven/", {"zwemmer": z})
        self.assertTrue(sql("SELECT id FROM {p}inschrijvingen"))
        # Secretariaat markeert als ingegeven; een wijziging van de trainer zet 'gewijzigd na ingave'.
        self.c.login("sec@khzs.be")
        self.c.post(f"wedstrijd/{self.w}/finaliseren/", {"zwemmer": z}, query={"actie": "ingegeven"})
        self.assertEqual(int(een("SELECT ingegeven FROM {p}deelnames")), 1)
        r = self.c.get(f"wedstrijd/{self.w}/export.lxf", enkel="gefinaliseerd")
        self.assertEqual(r.status_code, 200)
        self.c.login("tr@khzs.be")
        self.c.vandaag = "2026-10-12"
        self.c.post(f"wedstrijd/{self.w}/inschrijven/", {"zwemmer": z, "nummer[]": [nummer_id(self.w, 2), nummer_id(self.w, 6)]})
        d = sql("SELECT ingegeven, gewijzigd_na_ingave FROM {p}deelnames")[0]
        self.assertEqual((int(d["ingegeven"]), int(d["gewijzigd_na_ingave"])), (0, 1))
        r = self.c.get(f"wedstrijd/{self.w}/overzicht/", weergave="nummer", filter="alle")
        self.assertEqual(r.status_code, 200)
        self.assertContains(r, "Lien")

    def test_trainer_zet_info_zwemmer_ziet_ze(self):
        z = self._zwemmer()
        self.c.login("tr@khzs.be")
        self.c.post(f"wedstrijd/{self.w}/nakijken/", {"dagen_trainer": 2, "info": "Enkel voor de A-groep"})
        self.c.login("o@o.be")
        self.assertContains(self.c.get(f"wedstrijd/{self.w}/"), "Enkel voor de A-groep")

    def test_bijlage(self):
        self.c.login("tr@khzs.be")
        self.c.post(f"wedstrijd/{self.w}/bijlage/", {"naam": "Busregeling"}, files={"bestand": ("bus.pdf", b"%PDF-1.4 test")})
        b = sql("SELECT * FROM {p}bijlagen")
        self.assertEqual(b[0]["naam"], "Busregeling")
        r = self.c.post(f"wedstrijd/{self.w}/bijlage/", {}, files={"bestand": ("virus.exe", b"MZ")})
        self.assertEqual(int(een("SELECT COUNT(*) FROM {p}bijlagen")), 1)
        gebruiker("x@x.be")
        self.c.login("x@x.be")
        r = self.c.get(f"bijlage/{b[0]['id']}/")
        self.assertEqual(r.status_code, 200)
        self.assertEqual(r.content, b"%PDF-1.4 test")
        self.c.logout()
        self.assertEqual(self.c.get(f"bijlage/{b[0]['id']}/").status_code, 302)

    def test_zwemmer_ziet_geen_bedragen(self):
        z = self._zwemmer()
        self.c.login("o@o.be")
        self.assertNotContains(self.c.get(f"wedstrijd/{self.w}/", zwemmer=z), "€")
        self.c.login("tr@khzs.be")
        self.assertContains(self.c.get(f"wedstrijd/{self.w}/", zwemmer=z), "€")


class PushTest(Basis):
    def setUp(self):
        super().setUp()
        self.sec = gebruiker("sec@khzs.be", "secretaris")
        self.ouder = gebruiker("o@o.be")
        self.c.login("o@o.be")
        r = self.c.json("meldingen/abonneer/", {"endpoint": f"https://push.example/{self.ouder}", "keys": {"p256dh": "x", "auth": "y"}})
        self.assertEqual(r.status_code, 200, r.text)

    def test_pagina_en_sleutel(self):
        r = self.c.get("meldingen/")
        self.assertContains(r, "Meldingen aanzetten")
        self.assertEqual(len(hulp("eval", f="publieke_sleutel")["v"]), 87)

    def test_nieuwe_wedstrijd_en_herinnering(self):
        z = zwemmer(self.ouder)
        sec = Klant().login("sec@khzs.be")
        sec.post("wedstrijd/nieuw/", files={"bestand": ("vk.lxf", VK)})
        push = hulp("push")
        self.assertEqual(len(push), 1)
        self.assertIn("Nieuwe wedstrijd", push[0]["bericht"]["titel"])
        w = int(een("SELECT id FROM {p}wedstrijden"))
        self.c.post(f"wedstrijd/{w}/deelname/", {"zwemmer": z, "keuze": "interesse"})
        sec.post(f"wedstrijd/{w}/instellingen/", {"naam": "VK", "deadline": "2026-10-16", "dagen_secretaris": 3, "status": "open"})
        push = hulp("push")
        self.assertEqual(len(push), 2)
        self.assertIn("Inschrijvingen open", push[1]["bericht"]["titel"])
        # Herinnering de dag voor de deadline van de zwemmers (11/10).
        r = requests.post(f"{BASIS}/?khzs_test=herinneringen", headers={"X-Vandaag": "2026-10-10"}).json()
        push = hulp("push")
        self.assertEqual(len(push), 3, r)
        self.assertIn("Morgen", push[2]["bericht"]["tekst"])
        # Niet twee keer.
        requests.post(f"{BASIS}/?khzs_test=herinneringen", headers={"X-Vandaag": "2026-10-10"})
        self.assertEqual(len(hulp("push")), 3)

    def test_voorkeur_uit_geen_push(self):
        self.c.post("meldingen/", {"push_deadline": "on"})  # nieuwe wedstrijd uit
        sec = Klant().login("sec@khzs.be")
        sec.post("wedstrijd/nieuw/", files={"bestand": ("vk.lxf", VK)})
        self.assertEqual(len(hulp("push")), 0)

    def test_herinnering_enkel_bij_interesse(self):
        ander = gebruiker("x@x.be")
        k2 = Klant().login("x@x.be")
        k2.json("meldingen/abonneer/", {"endpoint": f"https://push.example/{ander}", "keys": {"p256dh": "x", "auth": "y"}})
        z = zwemmer(self.ouder)
        zwemmer(ander, "Bram")
        sec = Klant().login("sec@khzs.be")
        sec.post("wedstrijd/nieuw/", files={"bestand": ("vk.lxf", VK)})
        w = int(een("SELECT id FROM {p}wedstrijden"))
        self.c.post(f"wedstrijd/{w}/deelname/", {"zwemmer": z, "keuze": "interesse"})
        sec.post(f"wedstrijd/{w}/instellingen/", {"naam": "VK", "deadline": "2026-10-16", "dagen_secretaris": 3, "status": "open"})
        voor = len(hulp("push"))
        requests.post(f"{BASIS}/?khzs_test=herinneringen", headers={"X-Vandaag": "2026-10-10"})
        nieuw = hulp("push")[voor:]
        self.assertEqual(len(nieuw), 1)
        self.assertTrue(nieuw[0]["endpoint"].endswith(f"/{self.ouder}"))

    def test_versleuteling_en_vapid(self):
        """aes128gcm (RFC 8291) ontcijferen en de VAPID-JWT (RFC 8292) controleren met de cryptography-bibliotheek."""
        try:
            from cryptography.hazmat.primitives import hashes, serialization
            from cryptography.hazmat.primitives.asymmetric import ec
            from cryptography.hazmat.primitives.asymmetric.utils import encode_dss_signature
            from cryptography.hazmat.primitives.ciphers.aead import AESGCM
            from cryptography.hazmat.primitives.kdf.hkdf import HKDF
        except ImportError:
            self.skipTest("cryptography niet geïnstalleerd")
        import json
        import struct
        b64 = lambda b: base64.urlsafe_b64encode(b).rstrip(b"=").decode()
        dec = lambda x: base64.urlsafe_b64decode(x + "=" * (-len(x) % 4))
        priv = ec.generate_private_key(ec.SECP256R1())
        pub = priv.public_key().public_bytes(serialization.Encoding.X962, serialization.PublicFormat.UncompressedPoint)
        auth = os.urandom(16)
        d = base64.b64decode(hulp("versleutel", tekst='{"titel":"hallo"}', p256dh=b64(pub), auth=b64(auth))["v"])
        zout, rs, n = d[:16], struct.unpack(">I", d[16:20])[0], d[20]
        as_pub, cijfer = d[21:21 + n], d[21 + n:]
        gedeeld = priv.exchange(ec.ECDH(), ec.EllipticCurvePublicKey.from_encoded_point(ec.SECP256R1(), as_pub))
        ikm = HKDF(hashes.SHA256(), 32, auth, b"WebPush: info\0" + pub + as_pub).derive(gedeeld)
        cek = HKDF(hashes.SHA256(), 16, zout, b"Content-Encoding: aes128gcm\0").derive(ikm)
        nonce = HKDF(hashes.SHA256(), 12, zout, b"Content-Encoding: nonce\0").derive(ikm)
        tekst = AESGCM(cek).decrypt(nonce, cijfer, None)
        self.assertEqual((rs, tekst), (4096, b'{"titel":"hallo"}\x02'))

        h = hulp("eval", f="vapid_header", args=["https://fcm.googleapis.com/fcm/send/abc"])["v"]
        jwt, k = h.split("t=")[1].split(",")[0], h.split("k=")[1]
        kop, claims, sig = jwt.split(".")
        sig = dec(sig)
        sleutel = ec.EllipticCurvePublicKey.from_encoded_point(ec.SECP256R1(), dec(k))
        sleutel.verify(encode_dss_signature(int.from_bytes(sig[:32], "big"), int.from_bytes(sig[32:], "big")),
                       f"{kop}.{claims}".encode(), ec.ECDSA(hashes.SHA256()))
        self.assertEqual(json.loads(dec(claims))["aud"], "https://fcm.googleapis.com")


class ZwemmerTest(StroomTest):
    def _lien(self):
        o = gebruiker("o@o.be")
        return o, zwemmer(o, "Lien", "Peeters", "2013-03-02")

    def test_zwemmer_kan_geen_tijden_invoeren(self):
        o, z = self._lien()
        self.c.login("o@o.be")
        self.c.post(f"zwemmers/{z}/", {"t-slag": "FREE", "t-afstand": 50, "t-baan": "SCM", "t-tijd": "20.00", "t-datum": "2026-01-01"})
        self.assertFalse(sql("SELECT id FROM {p}tijden"))
        t = tijd(z, 50, "FREE", "SCM", 4000, "2026-01-01")
        self.assertEqual(self.c.post(f"tijd/{t}/verwijderen/").status_code, 403)

    def test_staf_voegt_tijd_toe(self):
        o, z = self._lien()
        self.c.login("tr@khzs.be")
        self.c.post(f"zwemmers/{z}/", {"t-slag": "FREE", "t-afstand": 50, "t-baan": "SCM", "t-tijd": "31,5", "t-datum": "2026-01-01"})
        self.assertEqual(int(een("SELECT tijd FROM {p}tijden")), 3150)

    def test_uitslagen_import(self):
        o, z = self._lien()
        tijd(z, 100, "BREAST", "LCM", 8000, "2026-01-01")
        self.c.login("tr@khzs.be")
        r = self.c.post("zwemmers/uitslagen/", files={"bestand": ("uitslag.lef", UITSLAG)})
        self.assertContains(r, "1 verbeterde")
        t = sql("SELECT * FROM {p}tijden")
        self.assertEqual(len(t), 1)
        self.assertEqual(int(t[0]["tijd"]), 7820)
        self.assertEqual(t[0]["bron"], "uitslagen")
        self.assertContains(r, "Iemand Anders")

    def test_gegevens_wijzigen(self):
        o, z = self._lien()
        self.c.login("o@o.be")
        r = self.c.post(f"zwemmers/{z}/", {"z-voornaam": "Lien", "z-naam": "Peeters", "z-geboortedatum": "2013-03-02",
                                           "z-geslacht": "F", "z-licentie": "123", "z-swimrankings_id": "456"})
        self.assertEqual(r.status_code, 302)
        self.assertEqual(een("SELECT licentie FROM {p}zwemmers"), "123")
        self.assertContains(self.c.get(f"zwemmers/{z}/"), "athleteId=456")


class BackupTest(StroomTest):
    def test_backup_en_terugzetten(self):
        o = gebruiker("o@o.be")
        z = zwemmer(o, "A")
        self.c.post(f"wedstrijd/{self.w}/deelname/", {"zwemmer": z, "keuze": "interesse"})
        self.assertEqual(self.c.get("backup/").status_code, 403)  # secretaris is geen beheerder
        adm = Klant().login("admin", "admin123")
        adm.post("backup/", {"actie": "maak"})
        naam = re.search(r"(hzs-backup-[\w-]+\.zip)", adm.get("backup/").text).group(1)
        self.assertEqual(adm.get(f"backup/{naam}").status_code, 200)
        exe("DELETE FROM {p}zwemmers")
        exe("DELETE FROM {p}deelnames")
        exe(f"UPDATE {{p}}wedstrijden SET naam='weg' WHERE id={self.w}")
        hulp("exec", sql=f"DELETE FROM wp_usermeta WHERE meta_key='khzs_rol' AND user_id={self.tr}")
        adm.post("backup/", {"actie": "herstel", "naam": naam, "bevestig": "HERSTEL"})
        self.assertTrue(sql("SELECT id FROM {p}zwemmers WHERE voornaam='A'"))
        self.assertEqual(een("SELECT naam FROM {p}wedstrijden"), "VK KB Jeugd")
        self.assertEqual(een("SELECT keuze FROM {p}deelnames"), "interesse")
        self.assertEqual(hulp("meta", id=self.tr, key="khzs_rol")["v"], "trainer")
        self.assertIn("voor-herstel", adm.get("backup/").text)
        self.assertEqual(adm.get("backup/..%2Fwp-config.php").status_code, 404)


class RegistratieTest(Basis):
    def test_ouder_registreert_twee_kinderen(self):
        registreer(self.c, "ann@test.be")
        kind(self.c, "Lien", "Peeters", "2013-03-02", "F")
        kind(self.c, "Bram", "Peeters", "2015-06-01", "M")
        self.assertEqual(sorted(x["voornaam"] for x in sql("SELECT voornaam FROM {p}zwemmers")), ["Bram", "Lien"])
        uid = int(een("SELECT ID FROM wp_users WHERE user_login='ann@test.be'"))
        self.assertEqual(hulp("meta", id=uid, key="khzs_rollen")["v"], "ouder")
        self.assertEqual(een("SELECT meta_value FROM wp_usermeta m JOIN wp_users u ON u.ID=m.user_id WHERE u.user_login='ann@test.be' AND meta_key='first_name'"), "Ann")
        # Ingelogd na registratie.
        self.assertEqual(self.c.get("wedstrijden/").status_code, 200)

    def test_stappen_overslaan(self):
        registreer(self.c, "leeg@test.be", "A", "B")
        uid = int(een("SELECT ID FROM wp_users WHERE user_login='leeg@test.be'"))
        self.assertEqual(hulp("meta", id=uid, key="khzs_rollen")["v"], "")  # een nieuw account start zonder rol
        for stap in ("zelf", "kinderen", "staf", "klaar"):
            self.assertEqual(self.c.get("welkom/", stap=stap).status_code, 200)
        self.assertContains(self.c.get("rollen/"), "Nog geen rol")
        self.assertEqual(self.c.get("zwemmers/uitslagen/").status_code, 403)

    def test_bestaand_email(self):
        gebruiker("dubbel@test.be")
        r = self.c.post("registreren/", {"email": "dubbel@test.be", "password1": "Zwemmen-2026!", "password2": "Zwemmen-2026!",
                                         "voornaam": "A", "naam": "B", "privacy": "1"})
        self.assertContains(r, "Er bestaat al een account")

    def test_login_fout(self):
        r = self.c.s.post(APP + "aanmelden/", data={"username": "niemand", "password": "x"})
        self.assertContains(r, "E-mail of wachtwoord klopt niet")


class RolTest(StroomTest):
    def test_beheerder_rol(self):
        adm = Klant().login("admin", "admin123")
        self.assertEqual(adm.get("backup/").status_code, 200)
        self.assertEqual(self.c.get("backup/").status_code, 403)
        # Secretaris kan geen beheerder aanstellen.
        self.c.post("gebruikers/", {"user": self.tr, "rol": "beheerder"})
        self.assertEqual(hulp("meta", id=self.tr, key="khzs_rol")["v"], "trainer")
        adm.post("gebruikers/", {"user": self.tr, "rol": "beheerder"})
        self.assertEqual(hulp("meta", id=self.tr, key="khzs_rol")["v"], "beheerder")
        tr = Klant().login("tr@khzs.be")
        self.assertEqual(tr.get("backup/").status_code, 200)
        self.assertEqual(tr.get(f"wedstrijd/{self.w}/instellingen/").status_code, 200)
        # Secretaris kan een beheerder niet terugzetten.
        self.c.post("gebruikers/", {"user": self.tr, "rol": "zwemmer"})
        self.assertEqual(hulp("meta", id=self.tr, key="khzs_rol")["v"], "beheerder")

    def test_gebruikers_zoeken(self):
        r = self.c.get("gebruikers/", q="tr@")
        self.assertContains(r, "tr@khzs.be")
        self.assertNotContains(r, "sec@khzs.be")

    def test_wedstrijd_verwijderen(self):
        o = gebruiker("o@o.be")
        z = zwemmer(o)
        inschrijving(z, nummer_id(self.w, 2))
        self.c.post(f"wedstrijd/{self.w}/verwijderen/")
        for t in ["wedstrijden", "sessies", "nummers", "leeftijdsgroepen", "limietlijsten", "limieten", "inschrijvingen"]:
            self.assertEqual(int(een(f"SELECT COUNT(*) FROM {{p}}{t}")), 0, t)

    def test_wp_gebruiker_verwijderd(self):
        o = gebruiker("o@o.be")
        z = zwemmer(o)
        inschrijving(z, nummer_id(self.w, 2))
        hulp("deluser", id=o)
        self.assertEqual(int(een("SELECT COUNT(*) FROM {p}zwemmers")), 0)
        self.assertEqual(int(een("SELECT COUNT(*) FROM {p}inschrijvingen")), 0)


class KoppelTest(StroomTest):
    """Een zwemmer delen met een tweede account (bv. gescheiden ouders)."""

    def _gezin(self):
        mama = gebruiker("mama@o.be", first_name="Mama")
        papa = gebruiker("papa@o.be", first_name="Papa")
        z = zwemmer(mama, "Lien", "P")
        return mama, papa, z

    def accounts(self, z):
        return {int(z_["account_id"]) for z_ in sql(f"SELECT account_id FROM {{p}}zwemmers WHERE id={z}")} | \
            {int(r["user_id"]) for r in sql(f"SELECT user_id FROM {{p}}zwemmer_accounts WHERE zwemmer_id={z}")}

    def test_koppelen_met_code(self):
        mama, papa, z = self._gezin()
        p = Klant().login("papa@o.be")
        self.assertEqual(p.get(f"zwemmers/{z}/").status_code, 404)
        m = Klant().login("mama@o.be")
        m.post(f"zwemmers/{z}/delen/")
        code = een(f"SELECT koppelcode FROM {{p}}zwemmers WHERE id={z}")
        self.assertEqual(len(code), 8)
        self.assertContains(m.get(f"zwemmers/{z}/"), f"{code[:4]}-{code[4:]}")
        r = p.post("zwemmers/koppelen/", {"code": f"{code[:4].lower()}-{code[4:]}"})
        self.assertTrue(r.headers["Location"].endswith(f"/zwemmers/{z}/"))
        self.assertEqual(self.accounts(z), {mama, papa})
        self.assertContains(p.get("zwemmers/"), "Lien")
        self.assertEqual(p.get(f"wedstrijd/{self.w}/", zwemmer=z).status_code, 200)
        # Papa schrijft in; een wijziging door de trainer gaat naar beide ouders.
        p.json("meldingen/abonneer/", {"endpoint": "https://push.example/papa", "keys": {"p256dh": "x", "auth": "y"}})
        m.json("meldingen/abonneer/", {"endpoint": "https://push.example/mama", "keys": {"p256dh": "x", "auth": "y"}})
        p.vandaag = "2026-10-01"
        p.post(f"wedstrijd/{self.w}/inschrijven/", {"zwemmer": z, "nummer[]": [nummer_id(self.w, 6)]})
        i = een("SELECT id FROM {p}inschrijvingen")
        self.assertTrue(i)
        t = Klant().login("tr@khzs.be")
        t.post(f"wedstrijd/{self.w}/beoordelen/", {"actie": "afgekeurd", "inschrijving[]": [i]})
        self.assertEqual({x["endpoint"] for x in hulp("push") if "gewijzigd" in x["bericht"]["titel"]},
                         {"https://push.example/papa", "https://push.example/mama"})
        # Mama ziet de melding ook in haar overzicht.
        m.vandaag = "2026-10-01"
        self.assertContains(m.get("wedstrijden/"), "gewijzigd door de club")

    def test_foute_code_en_ontkoppelen(self):
        mama, papa, z = self._gezin()
        p = Klant().login("papa@o.be")
        p.post("zwemmers/koppelen/", {"code": "AAAA-AAAA"})
        self.assertEqual(self.accounts(z), {mama})
        exe(f"INSERT INTO {{p}}zwemmer_accounts (zwemmer_id, user_id, aangemaakt) VALUES ({z}, {papa}, NOW())")
        self.assertEqual(p.post(f"zwemmers/{z}/ontkoppelen/", {"user": mama}).status_code, 403)
        p.post(f"zwemmers/{z}/ontkoppelen/", {"user": papa})
        self.assertEqual(self.accounts(z), {mama})
        m = Klant().login("mama@o.be")
        m.post(f"zwemmers/{z}/ontkoppelen/", {"user": mama})  # enige account: kan niet
        self.assertEqual(int(een(f"SELECT account_id FROM {{p}}zwemmers WHERE id={z}")), mama)
        exe(f"INSERT INTO {{p}}zwemmer_accounts (zwemmer_id, user_id, aangemaakt) VALUES ({z}, {papa}, NOW())")
        m.post(f"zwemmers/{z}/ontkoppelen/", {"user": mama})
        self.assertEqual(int(een(f"SELECT account_id FROM {{p}}zwemmers WHERE id={z}")), papa)
        self.assertFalse(sql("SELECT id FROM {p}zwemmer_accounts"))

    def test_account_verwijderd_zwemmer_blijft(self):
        mama, papa, z = self._gezin()
        exe(f"INSERT INTO {{p}}zwemmer_accounts (zwemmer_id, user_id, aangemaakt) VALUES ({z}, {papa}, NOW())")
        hulp("deluser", id=mama)
        self.assertEqual(int(een(f"SELECT account_id FROM {{p}}zwemmers WHERE id={z}")), papa)
        self.assertFalse(sql("SELECT id FROM {p}zwemmer_accounts"))

    def test_registreren_met_koppelcode_zonder_zwemmer(self):
        mama, papa, z = self._gezin()
        Klant().login("mama@o.be").post(f"zwemmers/{z}/delen/")
        code = een(f"SELECT koppelcode FROM {{p}}zwemmers WHERE id={z}")
        k = Klant()
        registreer(k, "plusmama@test.be", "Els", "V")
        r = k.post("welkom/", {"actie": "koppel", "code": code}, query={"stap": "kinderen"})
        self.assertEqual(r.status_code, 302, r.text[:2000])
        nieuw = int(een("SELECT ID FROM wp_users WHERE user_login='plusmama@test.be'"))
        self.assertIn(nieuw, self.accounts(z))
        self.assertEqual(int(een("SELECT COUNT(*) FROM {p}zwemmers")), 1)

    def test_staf_koppelt_op_email(self):
        mama, papa, z = self._gezin()
        Klant().login("tr@khzs.be").post("zwemmers/account/", {"zwemmer": z, "email": "papa@o.be"})
        self.assertEqual(self.accounts(z), {mama, papa})
        r = Klant().login("tr@khzs.be").get("zwemmers/")
        self.assertContains(r, "mama@o.be, papa@o.be")


class ZelfZwemmerTest(Basis):
    def test_mezelf_toevoegen(self):
        gebruiker("an@test.be", first_name="An")
        exe("UPDATE wp_usermeta m JOIN wp_users u ON u.ID=m.user_id SET m.meta_value='Vos' WHERE u.user_login='an@test.be' AND m.meta_key='last_name'")
        k = Klant().login("an@test.be")
        self.assertContains(k.get("zwemmers/"), "+ Mezelf")
        r = k.get("zwemmers/nieuw/", zelf=1)
        self.assertContains(r, 'value="An"')
        self.assertContains(r, 'value="Vos"')
        k.post("zwemmers/nieuw/", {"voornaam": "An", "naam": "Vos", "geboortedatum": "1985-04-01", "geslacht": "F"}, query={"zelf": 1})
        self.assertEqual(een("SELECT voornaam FROM {p}zwemmers"), "An")
        self.assertNotContains(k.get("zwemmers/"), "+ Mezelf")

    def test_welkom_zelf_zwemmer(self):
        registreer(self.c, "an@test.be", "An", "Vos")
        r = self.c.get("welkom/")
        self.assertContains(r, "Zwem je zelf?")
        self.assertContains(r, 'value="An"')
        self.c.post("welkom/", {"voornaam": "An", "naam": "Vos", "geboortedatum": "1985-04-01", "geslacht": "F"}, query={"stap": "zelf"})
        self.assertEqual(een("SELECT voornaam FROM {p}zwemmers"), "An")
        uid = int(een("SELECT ID FROM wp_users WHERE user_login='an@test.be'"))
        self.assertEqual(hulp("meta", id=uid, key="khzs_rollen")["v"], "zwemmer")


class TrainersBasis(Basis):
    """Gemeenschappelijke opzet: secretaris, trainers Tom en Lies (met toestel), penningmeester Piet, A-groep op maandag."""

    def setUp(self):
        super().setUp()
        self.sec = gebruiker("sec@khzs.be", "secretaris", first_name="Sara")
        self.tom = gebruiker("tom@khzs.be", "trainer", first_name="Tom")
        self.lies = gebruiker("lies@khzs.be", "trainer", first_name="Lies")
        self.pm = gebruiker("pm@khzs.be", None, first_name="Piet", penningmeester=True)
        gebruiker("ouder@khzs.be")
        self.s = Klant().login("sec@khzs.be")
        self.s.vandaag = "2026-09-01"
        r = self.s.post("trainers/beheer/", {"wat": "moment", "naam": "A-groep", "weekdag": 1, "start": "18:00", "einde": "19:30",
                                            "locatie": "Groot bad", "van": "2026-09-01"})
        self.assertEqual(r.status_code, 302, r.text[:1500])
        self.m = int(een("SELECT id FROM {p}tr_momenten"))
        self.t = Klant().login("tom@khzs.be")
        self.t.vandaag = "2026-09-01"
        self.l = Klant().login("lies@khzs.be")
        self.l.vandaag = "2026-09-01"
        self.l.json("meldingen/abonneer/", {"endpoint": "https://push.example/lies", "keys": {"p256dh": "x", "auth": "y"}})
        self.t.json("meldingen/abonneer/", {"endpoint": "https://push.example/tom", "keys": {"p256dh": "x", "auth": "y"}})
        self.t.post("trainers/vast/", {"moment": self.m, "aan": 1})

    def actie(self, k, actie, datum, **extra):
        return k.post("trainers/actie/", {"moment": self.m, "datum": datum, "actie": actie, **extra})


class TrainersTest(TrainersBasis):
    """Module trainers: rooster, vaste trainers, afmelden/overnemen, afgelast, uren en verloning."""

    def test_rooster_afmelden_overnemen_en_verloning(self):
        self.assertEqual(een("SELECT van FROM {p}tr_vast"), "2026-09-01")
        r = self.t.get("trainers/")
        self.assertContains(r, "A-groep")
        self.assertContains(r, "Ik geef training")
        # Tom kan niet op 14/9 → Lies krijgt een melding en neemt over.
        self.actie(self.t, "afwezig", "2026-09-14", reden="ziek")
        self.assertEqual(een("SELECT soort FROM {p}tr_wijzigingen"), "afwezig")
        push = hulp("push")
        self.assertEqual([p["endpoint"] for p in push], ["https://push.example/lies"])
        self.assertIn("Vervanger gezocht", push[0]["bericht"]["titel"])
        self.assertContains(self.l.get("trainers/"), "Vervanger gezocht")
        self.actie(self.l, "overnemen", "2026-09-14", vervangt=self.tom)
        self.assertEqual(int(een("SELECT vervangt_id FROM {p}tr_wijzigingen WHERE soort='vervanger'")), self.tom)
        self.assertIn("neemt", hulp("push")[-1]["bericht"]["tekst"])
        self.assertEqual(hulp("push")[-1]["endpoint"], "https://push.example/tom")
        # Tom kan niet meer 'toch' komen: Lies heeft het al overgenomen.
        self.actie(self.t, "aanwezig", "2026-09-14")
        self.assertEqual(int(een("SELECT COUNT(*) FROM {p}tr_wijzigingen WHERE soort='afwezig'")), 1)
        # Afgelast op 21/9.
        self.s.post("trainers/beheer/", {"wat": "afgelast", "moment": 0, "van": "2026-09-21", "tot": "2026-09-21", "reden": "Zwembad dicht"})
        r = self.t.get("trainers/planning/", week="2026-09-21")
        self.assertContains(r, "Zwembad dicht")
        # Aanwezigheid bevestigen: enkel bevestigde trainingen tellen mee.
        self.t.vandaag = self.l.vandaag = "2026-09-30"
        for d in ("2026-09-07", "2026-09-28"):
            self.actie(self.t, "ja", d)
        self.actie(self.l, "ja", "2026-09-14")
        # Tarieven door de penningmeester.
        pm = Klant().login("pm@khzs.be")
        pm.vandaag = "2026-09-30"
        pm.post("trainers/tarieven/", {"user": self.tom, "soort": "uur", "bedrag": "15,00", "geldig_vanaf": "2026-09-01"})
        pm.post("trainers/tarieven/", {"user": self.lies, "soort": "beurt", "bedrag": "20", "geldig_vanaf": "2026-09-01"})
        r = pm.get("trainers/uren/", maand="2026-09")
        self.assertContains(r, "€ 45,00")   # Tom: 7/9 + 28/9 = 2 × 1,5 u × 15
        self.assertContains(r, "€ 20,00")   # Lies: 14/9 per training
        self.assertContains(r, "€ 65,00")   # totaal
        # Nieuw tarief vanaf 20/9: oude trainingen houden het oude tarief.
        pm.post("trainers/tarieven/", {"user": self.tom, "soort": "uur", "bedrag": "18", "geldig_vanaf": "2026-09-20"})
        self.assertContains(pm.get("trainers/uren/", maand="2026-09"), "€ 49,50")
        # Uitbetaald markeren, daarna een correctie → waarschuwing.
        pm.post("trainers/uren/", {"user": self.tom, "actie": "uitbetaald"}, query={"maand": "2026-09"})
        self.assertEqual(int(een("SELECT bedrag_cent FROM {p}tr_uitbetaald")), 4950)
        self.s.vandaag = "2026-09-30"
        self.actie(self.s, "afwezig", "2026-09-28", user=self.tom)
        self.assertContains(pm.get("trainers/uren/", maand="2026-09"), "gewijzigd na uitbetaling")
        # Excel-export.
        r = pm.get("trainers/uren/export/", maand="2026-09")
        self.assertEqual(r.status_code, 200)
        with zipfile.ZipFile(io.BytesIO(r.content)) as zf:
            self.assertIn("Tom", zf.read("xl/worksheets/sheet1.xml").decode())
            ET.fromstring(zf.read("xl/worksheets/sheet2.xml"))
        # Tom ziet enkel zijn eigen uren.
        self.t.vandaag = "2026-09-30"
        r = self.t.get("trainers/uren/", maand="2026-09")
        self.assertContains(r, "Mijn uren")
        self.assertNotContains(r, "Lies")

    def test_voorbij_en_toegang(self):
        self.t.vandaag = "2026-09-30"
        self.actie(self.t, "afwezig", "2026-09-28")
        self.assertFalse(sql("SELECT id FROM {p}tr_wijzigingen"))  # voorbij: enkel secretariaat
        # Ongeldige datum (geen maandag) → 404.
        self.assertEqual(self.t.get(f"trainers/training/{self.m}/2026-09-29/").status_code, 404)
        self.assertEqual(self.t.get(f"trainers/training/{self.m}/2026-09-28/").status_code, 200)
        o = Klant().login("ouder@khzs.be")
        self.assertEqual(o.get("trainers/").status_code, 403)
        self.assertEqual(o.get("trainers/uren/").status_code, 403)
        self.assertNotContains(o.get("meldingen/"), "Trainingen")
        self.assertEqual(self.t.get("trainers/tarieven/").status_code, 403)
        self.assertEqual(self.t.get("trainers/uren/export/").status_code, 403)
        self.assertEqual(self.t.get("trainers/beheer/").status_code, 403)
        pm = Klant().login("pm@khzs.be")
        self.assertEqual(pm.get("trainers/").status_code, 302)  # bestuur zonder trainersrol → overzicht
        self.assertContains(pm.get("trainers/uren/"), "Verloning trainers")
        self.assertContains(pm.get("meldingen/"), "Verloning")
        # Een trainer kan geen anderen afmelden.
        self.t.vandaag = "2026-09-01"
        r = self.actie(self.t, "afwezig", "2026-09-14", user=self.lies)
        self.assertEqual(r.status_code, 403)
        # Penningmeester aanduiden kan enkel een beheerder.
        self.assertEqual(self.s.post("gebruikers/", {"user": self.lies, "penningmeester": "1"}).status_code, 403)

    def test_stoppen_extra_en_module_uit(self):
        self.actie(self.l, "helpen", "2026-09-07")
        self.assertEqual(een("SELECT soort FROM {p}tr_wijzigingen"), "extra")
        self.actie(self.l, "schrappen", "2026-09-07")
        self.assertFalse(sql("SELECT id FROM {p}tr_wijzigingen"))
        self.actie(self.t, "afwezig", "2026-09-21")
        self.t.vandaag = "2026-09-10"
        self.t.post("trainers/vast/", {"moment": self.m, "aan": 0})
        self.assertEqual(een("SELECT tot FROM {p}tr_vast"), "2026-09-09")
        self.assertFalse(sql("SELECT id FROM {p}tr_wijzigingen"))  # afmelding na de stopdatum opgeruimd
        # Training met uren verwijderen → stopt i.p.v. wissen.
        self.s.vandaag = "2026-09-10"
        self.s.post(f"trainers/moment/{self.m}/", {"wat": "verwijderen"})
        self.assertTrue(sql("SELECT id FROM {p}tr_momenten"))
        self.assertEqual(een("SELECT tot FROM {p}tr_momenten"), "2026-09-09")
        # Module uitschakelen: routes verdwijnen, gegevens blijven.
        exe("SELECT 1")
        hulp("exec", sql="UPDATE wp_options SET option_value='a:1:{i:0;s:14:\"inschrijvingen\";}' WHERE option_name='khzs_modules'")
        hulp("exec", sql="INSERT IGNORE INTO wp_options (option_name, option_value, autoload) VALUES ('khzs_modules', 'a:1:{i:0;s:14:\"inschrijvingen\";}', 'auto')")
        try:
            requests.post(f"{BASIS}/?khzs_test=cache")
            self.assertEqual(self.t.get("trainers/").status_code, 404)
            self.assertTrue(sql("SELECT id FROM {p}tr_momenten"))
        finally:
            hulp("exec", sql="DELETE FROM wp_options WHERE option_name='khzs_modules'")
            requests.post(f"{BASIS}/?khzs_test=cache")

    def test_oud_adres(self):
        for oud in ("inschrijvingen", "club"):
            r = requests.get(f"{BASIS}/{oud}/wedstrijden/", allow_redirects=False)
            self.assertEqual(r.status_code, 301)
            self.assertTrue(r.headers["Location"].endswith("/leden/wedstrijden/"))


# ---------- eigen domein (leden.khzs.be via een Cloudflare Worker) ----------

WORKER_POORT = int(os.environ.get("KHZS_WORKER_POORT", "8098"))
# Domein van de app: de Worker, nagebootst met Node. 127.0.0.1 is een andere host dan localhost, dus andere cookies.
DOMEIN = f"http://127.0.0.1:{WORKER_POORT}"


def domein_optie(waarde):
    exe("DELETE FROM wp_options WHERE option_name='khzs_app_domein'")
    if waarde:
        exe(f"INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('khzs_app_domein', '{waarde}', 'auto')")
    requests.post(f"{BASIS}/?khzs_test=cache")


@unittest.skipUnless(shutil.which("node"), "Node is nodig om de Worker na te bootsen")
class EigenDomeinTest(Basis):
    """De app op een eigen domein: de Worker (wordpress/leden-domein/worker.js) stuurt door naar BASIS/leden/."""

    @classmethod
    def setUpClass(cls):
        worker = HIER.parent / "leden-domein" / "worker.js"
        cls.proces = subprocess.Popen(["node", str(HIER / "worker_sim.mjs"), str(worker), str(WORKER_POORT), BASIS + "/leden"],
                                      stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(50):
            try:
                requests.get(DOMEIN + "/domein-test/", timeout=2)
                break
            except requests.ConnectionError:
                time.sleep(0.1)

    @classmethod
    def tearDownClass(cls):
        cls.proces.terminate()
        cls.proces.wait()
        domein_optie("")

    def setUp(self):
        super().setUp()
        domein_optie(DOMEIN)
        gebruiker("ouder@khzs.be")
        self.d = Klant(DOMEIN + "/")

    def tearDown(self):
        domein_optie("")

    def test_oud_adres_verwijst_naar_domein(self):
        r = requests.get(f"{BASIS}/leden/wedstrijden/?a=1", allow_redirects=False)
        self.assertEqual((r.status_code, r.headers["Location"]), (302, f"{DOMEIN}/wedstrijden/?a=1"))
        self.assertIn("no-store", r.headers["Cache-Control"])
        r = requests.get(f"{BASIS}/leden/", allow_redirects=False)
        self.assertEqual(r.headers["Location"], f"{DOMEIN}/")
        r = requests.post(f"{BASIS}/leden/aanmelden/", data={"username": "x"}, allow_redirects=False)
        self.assertEqual((r.status_code, r.headers["Location"]), (307, f"{DOMEIN}/aanmelden/"))
        r = requests.get(f"{BASIS}/club/meldingen/", allow_redirects=False)
        self.assertEqual(r.headers["Location"], f"{DOMEIN}/meldingen/")
        # Een app die nog op het oude adres geïnstalleerd is, krijgt een service worker die zichzelf opruimt.
        sw = requests.get(f"{BASIS}/leden/sw.js", allow_redirects=False)
        self.assertEqual(sw.status_code, 200)
        self.assertIn("unregister", sw.text)
        # Zonder eigen domein blijft alles op /leden/.
        domein_optie("")
        self.assertEqual(requests.get(f"{BASIS}/leden/aanmelden/", allow_redirects=False).status_code, 200)

    def test_aanmelden_op_domein(self):
        r = self.d.get("meldingen/")
        self.assertEqual(r.headers["Location"], f"{DOMEIN}/aanmelden/?next={quote(DOMEIN + '/meldingen/', safe='')}")
        r = self.d.s.post(DOMEIN + "/aanmelden/", data={"username": "ouder@khzs.be", "password": "geheim123!",
                                                        "next": DOMEIN + "/meldingen/"}, allow_redirects=False)
        self.assertEqual((r.status_code, r.headers["Location"]), (302, f"{DOMEIN}/meldingen/"))
        # De aanmeldcookies horen enkel bij het domein van de app (zonder COOKIE_DOMAIN).
        self.assertTrue([c for c in self.d.s.cookies if c.name.startswith("wordpress_logged_in_")])
        self.assertEqual({c.domain for c in self.d.s.cookies}, {"127.0.0.1"})
        r = self.d.get("meldingen/")
        self.assertEqual(r.status_code, 200)
        self.assertContains(r, f'href="{DOMEIN}/meldingen/"')
        self.assertNotContains(r, f'href="{BASIS}/leden/')
        # Formulieren met nonce werken via het domein.
        r = self.d.post("afmelden/")
        self.assertEqual((r.status_code, r.headers["Location"]), (302, f"{DOMEIN}/aanmelden/"))
        self.assertEqual(self.d.get("meldingen/").status_code, 302)

    def test_next_enkel_naar_toegelaten_hosts(self):
        for doel, verwacht in [(DOMEIN + "/meldingen/", DOMEIN + "/meldingen/"), ("https://kwaad.example/", DOMEIN + "/")]:
            r = requests.post(DOMEIN + "/aanmelden/", data={"username": "ouder@khzs.be", "password": "geheim123!", "next": doel},
                              allow_redirects=False)
            self.assertEqual(r.headers["Location"], verwacht)

    def test_manifest_service_worker_en_assets(self):
        m = requests.get(DOMEIN + "/manifest.webmanifest").json()
        self.assertEqual((m["id"], m["scope"], m["start_url"]), (DOMEIN + "/", DOMEIN + "/", DOMEIN + "/?bron=app"))
        self.assertTrue(all(i["src"].startswith(DOMEIN + "/wp-content/plugins/khzs-leden/assets/img/") for i in m["icons"]))
        sw = requests.get(DOMEIN + "/sw.js")
        self.assertEqual(sw.headers["Service-Worker-Allowed"], "/")
        self.assertIn(f'"{DOMEIN}/wp-content/plugins/khzs-leden/assets/app.css?v=', sw.text)  # zelfde domein: cache werkt
        self.assertIn(f'const START = "{DOMEIN}/"', sw.text)
        html = requests.get(DOMEIN + "/aanmelden/").text
        self.assertIn(f'href="{DOMEIN}/manifest.webmanifest"', html)
        self.assertIn('{scope: "\\/"}', html)
        css = requests.get(DOMEIN + "/wp-content/plugins/khzs-leden/assets/app.css")
        self.assertEqual(css.status_code, 200)
        self.assertIn("--primair", css.text)
        # Wachtwoord vergeten loopt via wp-login.php van de site en komt daarna terug op het domein.
        self.assertIn(f"{BASIS}/wp-login.php?action=lostpassword&#038;redirect_to={quote(DOMEIN + '/aanmelden/', safe='')}", html)

    def test_installatiemelding_op_website(self):
        html = requests.get(BASIS + "/").text
        self.assertIn(f'"installeer":"{DOMEIN}/installeer/"', html)

    def test_worker_herschrijft_doorverwijzingen(self):
        # Domein nog niet ingesteld: de plugin verwijst naar BASIS/leden/…, de Worker zet dat om naar het domein.
        domein_optie("")
        r = requests.post(DOMEIN + "/aanmelden/", data={"username": "ouder@khzs.be", "password": "geheim123!"}, allow_redirects=False)
        self.assertEqual((r.status_code, r.headers["Location"]), (302, f"{DOMEIN}/"))
        r = requests.get(DOMEIN + "/meldingen/", allow_redirects=False)
        self.assertTrue(r.headers["Location"].startswith(f"{DOMEIN}/aanmelden/"))

    def test_domein_controle(self):
        self.assertIn("niet bereikbaar", hulp("eval", f="domein_controle", args=["http://127.0.0.1:9"])["v"])
        self.assertEqual(requests.get(DOMEIN + "/domein-test/").json(), {"app": "khzs-leden", "domein": f"127.0.0.1:{WORKER_POORT}"})
        self.assertEqual(requests.get(f"{BASIS}/leden/domein-test/").json()["domein"], "")
        # Vanaf de site zelf naar de nagebootste Worker (in Docker: KHZS_DOMEIN_VANUIT_SITE=http://host.docker.internal:8098).
        vanuit_site = os.environ.get("KHZS_DOMEIN_VANUIT_SITE", DOMEIN)
        fout = hulp("eval", f="domein_controle", args=[vanuit_site])["v"]
        if "niet bereikbaar" in fout:
            self.skipTest("de site kan de nagebootste Worker niet bereiken")
        self.assertEqual(fout, "")
        # De site zelf (zonder Worker) antwoordt niet als de app.
        fout = hulp("eval", f="domein_controle", args=[vanuit_site.rsplit(":", 1)[0] + ":" + BASIS.rsplit(":", 1)[1]])["v"]
        self.assertIn("stuurt niet door", fout)


class AanwezigheidTest(TrainersBasis):
    """Trainers: na de training 'Was je aanwezig?' (push met Ja/Nee), uren op basis van bevestigingen, bestuur past aan."""

    def vraag(self, nu):
        return hulp("eval", f="tr_vraag_aanwezigheid", args=[nu])["v"]

    def bevestiging(self, uid, datum):
        rijen = sql(f"SELECT aanwezig, bron, door_id FROM {{p}}tr_bevestigingen WHERE user_id={uid} AND datum='{datum}'")
        return rijen[0] if rijen else None

    def test_vraag_na_training_en_antwoord_vanuit_melding(self):
        # Nog bezig → geen vraag; na het einde (19:30) wel, en maar één keer.
        self.assertEqual(self.vraag("2026-09-07 19:00"), {"gevraagd": 0, "herinnerd": 0})
        self.assertEqual(self.vraag("2026-09-07 19:45"), {"gevraagd": 1, "herinnerd": 0})
        self.assertEqual(self.vraag("2026-09-07 20:00"), {"gevraagd": 0, "herinnerd": 0})
        push = hulp("push")[-1]
        self.assertEqual(push["endpoint"], "https://push.example/tom")
        b = push["bericht"]
        self.assertEqual(b["titel"], "Was je aanwezig?")
        self.assertIn("A-groep", b["tekst"])
        self.assertEqual([a["actie"] for a in b["acties"]], ["ja", "nee"])
        self.assertTrue(b["acties"][0]["post"].endswith("/leden/trainers/bevestig/"))
        token = b["acties"][0]["velden"]["t"]
        # De service worker toont de knoppen en antwoordt rechtstreeks.
        sw = requests.get(APP + "sw.js").text
        self.assertIn("opties.actions", sw)
        self.assertIn("actie.post", sw)
        # Nog niet bevestigd: telt niet mee, staat apart.
        self.t.vandaag = "2026-09-08"
        r = self.t.get("trainers/")
        self.assertContains(r, "Was je aanwezig?")
        self.assertContains(r, "niet bevestigd")
        # Antwoord met één tik vanuit de melding: zonder aanmelden, met het token.
        anoniem = Klant()
        anoniem.vandaag = "2026-09-08"
        r = anoniem.get("trainers/bevestig/", t=token)
        self.assertContains(r, "Was je aanwezig?")
        r = anoniem.post("trainers/bevestig/", {"t": token, "antwoord": "ja", "json": "1"})
        self.assertEqual(r.status_code, 200, r.text[:300])
        self.assertTrue(r.json()["ok"])
        self.assertEqual(self.bevestiging(self.tom, "2026-09-07"), {"aanwezig": "1", "bron": "melding", "door_id": str(self.tom)})
        # Een vervalst token werkt niet.
        vals = token[:-1] + ("0" if token[-1] != "0" else "1")
        self.assertEqual(anoniem.post("trainers/bevestig/", {"t": vals, "antwoord": "nee", "json": "1"}).status_code, 400)
        self.assertEqual(anoniem.post("trainers/bevestig/", {"t": "", "antwoord": "nee"}).status_code, 400)
        # Geen antwoord → één herinnering, de volgende ochtend (niet 's nachts, minstens 12 uur later).
        self.assertEqual(self.vraag("2026-09-14 19:45")["gevraagd"], 1)
        self.assertEqual(self.vraag("2026-09-15 07:00")["herinnerd"], 0)
        self.assertEqual(self.vraag("2026-09-15 08:00")["herinnerd"], 1)
        self.assertIn("Nog niet bevestigd", hulp("push")[-1]["bericht"]["titel"])
        self.assertEqual(self.vraag("2026-09-15 12:00")["herinnerd"], 0)
        # Antwoord 'nee' in de app.
        self.t.vandaag = "2026-09-15"
        self.actie(self.t, "nee", "2026-09-14")
        self.assertEqual(self.bevestiging(self.tom, "2026-09-14")["aanwezig"], "0")
        # Voorkeur uit → geen vraag meer.
        exe(f"INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES ({self.tom}, 'khzs_push_aanwezig', '0')")
        aantal = len(hulp("push"))
        self.assertEqual(self.vraag("2026-09-21 19:45")["gevraagd"], 0)
        self.assertEqual(len(hulp("push")), aantal)
        # Uren: enkel 7/9 telt; 21/9 en 28/9 blijven 'niet bevestigd'.
        pm = Klant().login("pm@khzs.be")
        pm.vandaag = "2026-09-30"
        pm.post("trainers/tarieven/", {"user": self.tom, "soort": "uur", "bedrag": "15", "geldig_vanaf": "2026-09-01"})
        r = pm.get("trainers/uren/", maand="2026-09")
        self.assertContains(r, "€ 22,50")
        self.assertContains(r, "2 (3:00)")
        r = pm.get("trainers/overzicht/", maand="2026-09")
        self.assertContains(r, "2 niet bevestigd")
        r = pm.get("trainers/uren/export/", maand="2026-09")
        with zipfile.ZipFile(io.BytesIO(r.content)) as zf:
            self.assertIn("Niet bevestigd", zf.read("xl/sharedStrings.xml").decode() if "xl/sharedStrings.xml" in zf.namelist()
                          else zf.read("xl/worksheets/sheet1.xml").decode())

    def test_bestuur_past_aan_en_trainer_ziet_melding(self):
        pm = Klant().login("pm@khzs.be")
        pm.vandaag = "2026-09-10"
        self.t.vandaag = "2026-09-10"
        # De penningmeester (bestuur, geen trainer) komt op het overzicht.
        r = pm.get("trainers/")
        self.assertEqual(r.status_code, 302)
        self.assertIn("/trainers/overzicht/", r.headers["Location"])
        r = pm.get("trainers/overzicht/", maand="2026-09")
        self.assertContains(r, "Tom")
        self.assertContains(r, "1 niet bevestigd")
        self.assertContains(pm.get("trainers/overzicht/", maand="2026-09", trainer=self.tom), "Vaste trainingen (schema)")
        # Aanwezigheid aanpassen → melding en logboek voor de trainer.
        self.actie(pm, "ja", "2026-09-07", user=self.tom)
        self.assertEqual(self.bevestiging(self.tom, "2026-09-07"), {"aanwezig": "1", "bron": "bestuur", "door_id": str(self.pm)})
        log = sql("SELECT wat, door_id, gelezen FROM {p}tr_log WHERE user_id=%d" % self.tom)
        self.assertEqual(len(log), 1)
        self.assertIn("Aanwezig op A-groep", log[0]["wat"])
        self.assertEqual(log[0]["door_id"], str(self.pm))
        push = hulp("push")[-1]
        self.assertEqual(push["endpoint"], "https://push.example/tom")
        self.assertEqual(push["bericht"]["titel"], "Je planning is aangepast")
        self.assertIn("Piet", push["bericht"]["tekst"])
        # De trainer ziet wat er veranderde, door wie en wanneer.
        r = self.t.get("trainers/")
        self.assertContains(r, "Aangepast door het bestuur")
        self.assertContains(r, "Aanwezig op A-groep")
        r = self.t.get("trainers/wijzigingen/")
        self.assertContains(r, "door Piet")
        self.assertEqual(een("SELECT gelezen FROM {p}tr_log"), "1")
        self.assertNotContains(self.t.get("trainers/"), "Aangepast door het bestuur")
        # Schema van een andere trainer aanpassen (ook in het verleden).
        pm.post("trainers/vast/", {"moment": self.m, "user": self.lies, "aan": 1, "vanaf": "2026-09-01"})
        self.assertEqual(een("SELECT van FROM {p}tr_vast WHERE user_id=%d" % self.lies), "2026-09-01")
        self.assertIn("toegevoegd vanaf 1/09/2026", een("SELECT wat FROM {p}tr_log WHERE user_id=%d" % self.lies))
        # Alles in één keer bevestigen (enkel voorbije trainingen: 7/9).
        r = pm.post("trainers/overzicht/", {"actie": "alles_ja", "user": self.lies}, query={"maand": "2026-09", "trainer": self.lies})
        self.assertEqual(r.status_code, 302)
        self.assertEqual(int(een("SELECT COUNT(*) FROM {p}tr_bevestigingen WHERE user_id=%d AND aanwezig=1" % self.lies)), 1)
        self.assertEqual(int(een("SELECT COUNT(*) FROM {p}tr_log WHERE user_id=%d" % self.lies)), 2)
        # Een trainer ziet het overzicht niet en kan anderen niet bevestigen.
        self.assertEqual(self.t.get("trainers/overzicht/").status_code, 403)
        self.assertEqual(self.actie(self.t, "nee", "2026-09-07", user=self.lies).status_code, 403)
        self.assertEqual(Klant().login("ouder@khzs.be").get("trainers/overzicht/").status_code, 403)
        # De secretaris kan ook aanpassen (bv. iemand achteraf toevoegen: telt als aanwezig).
        self.s.vandaag = "2026-09-10"
        self.actie(self.s, "toevoegen", "2026-09-07", user=self.sec)
        self.assertEqual(self.bevestiging(self.sec, "2026-09-07")["aanwezig"], "1")

    def test_trainer_past_eigen_schema_en_voorbije_training_aan(self):
        self.t.vandaag = self.l.vandaag = "2026-09-20"
        # Achteraf: Lies was er als extra trainer op 14/9 → telt meteen als aanwezig.
        self.actie(self.l, "helpen", "2026-09-14")
        self.assertEqual(een("SELECT soort FROM {p}tr_wijzigingen WHERE user_id=%d" % self.lies), "extra")
        self.assertEqual(self.bevestiging(self.lies, "2026-09-14"), {"aanwezig": "1", "bron": "trainer", "door_id": str(self.lies)})
        self.assertFalse(sql("SELECT id FROM {p}tr_log"))  # zelf gedaan: geen melding
        # Eigen schema met een datum in het verleden (begrensd tot het begin van vorige maand en de start van de training).
        self.l.post("trainers/vast/", {"moment": self.m, "aan": 1, "vanaf": "2026-07-01"})
        self.assertEqual(een("SELECT van FROM {p}tr_vast WHERE user_id=%d" % self.lies), "2026-09-01")
        # Afmelden voor een voorbije training kan niet meer; 'nee' wel. Bevestigen vóór de training kan niet.
        self.actie(self.t, "afwezig", "2026-09-14")
        self.assertFalse(sql("SELECT id FROM {p}tr_wijzigingen WHERE soort='afwezig'"))
        self.actie(self.t, "nee", "2026-09-14")
        self.assertEqual(self.bevestiging(self.tom, "2026-09-14")["aanwezig"], "0")
        self.actie(self.t, "ja", "2026-09-21")
        self.assertIsNone(self.bevestiging(self.tom, "2026-09-21"))
        r = self.t.get(f"trainers/training/{self.m}/2026-09-14/")
        self.assertContains(r, "niet aanwezig")
        self.assertContains(r, "Ik was er")
        # Na 'uitbetaald' kan de trainer niets meer wijzigen, ook niet via de melding; het bestuur wel.
        self.t.vandaag = "2026-09-30"
        self.actie(self.t, "ja", "2026-09-28")
        pm = Klant().login("pm@khzs.be")
        pm.vandaag = "2026-09-30"
        pm.post("trainers/uren/", {"user": self.tom, "actie": "uitbetaald"}, query={"maand": "2026-09"})
        self.t.vandaag = "2026-10-02"
        self.actie(self.t, "nee", "2026-09-28")
        self.assertEqual(self.bevestiging(self.tom, "2026-09-28")["aanwezig"], "1")
        token = hulp("eval", f="tr_token", args=[self.tom, self.m, "2026-09-28"])["v"]
        anoniem = Klant()
        anoniem.vandaag = "2026-10-02"
        r = anoniem.post("trainers/bevestig/", {"t": token, "antwoord": "nee", "json": "1"})
        self.assertEqual(r.status_code, 400)
        self.assertIn("uitbetaald", r.json()["tekst"])
        pm.vandaag = "2026-10-02"
        self.actie(pm, "nee", "2026-09-28", user=self.tom)
        self.assertEqual(self.bevestiging(self.tom, "2026-09-28")["aanwezig"], "0")
        self.assertContains(pm.get("trainers/uren/", maand="2026-09"), "gewijzigd na uitbetaling")
        self.assertContains(self.t.get("trainers/"), "Niet aanwezig op A-groep")

    def test_bestuurder_die_ook_trainer_is(self):
        # Bert is trainer én zit in het bestuur (hier: penningmeester).
        bert = gebruiker("bert@khzs.be", "trainer", first_name="Bert", penningmeester=True)
        b = Klant().login("bert@khzs.be")
        b.vandaag = "2026-09-01"
        b.post("trainers/vast/", {"moment": self.m, "aan": 1})
        # Hij ziet zijn eigen trainingen én kan naar het overzicht van alle trainers.
        r = b.get("trainers/")
        self.assertEqual(r.status_code, 200)
        self.assertContains(r, "Mijn trainingen")
        self.assertContains(r, "Overzicht trainers")
        self.assertContains(b.get("trainers/overzicht/", maand="2026-09"), "Bert")
        b.vandaag = "2026-09-30"
        # Eigen schema: ook voor hem begrensd tot het begin van vorige maand.
        b.post("trainers/vast/", {"moment": self.m, "aan": 0, "vanaf": "2026-09-01"})
        b.post("trainers/vast/", {"moment": self.m, "aan": 1, "vanaf": "2026-01-01"})
        self.assertEqual(een("SELECT MIN(van) FROM {p}tr_vast WHERE user_id=%d" % bert), "2026-09-01")
        # Eigen trainingen bevestigen zoals elke trainer; geen melding aan zichzelf.
        self.actie(b, "ja", "2026-09-07")
        self.assertEqual(self.bevestiging(bert, "2026-09-07")["bron"], "trainer")
        self.assertFalse(sql("SELECT id FROM {p}tr_log WHERE user_id=%d" % bert))
        # Na uitbetaling kan hij zijn eigen uren niet meer wijzigen; een andere bestuurder of het secretariaat wel.
        b.post("trainers/uren/", {"user": bert, "actie": "uitbetaald"}, query={"maand": "2026-09"})
        b.vandaag = "2026-10-02"
        self.actie(b, "nee", "2026-09-07")
        self.assertEqual(self.bevestiging(bert, "2026-09-07")["aanwezig"], "1")
        self.s.vandaag = "2026-10-02"
        self.actie(self.s, "nee", "2026-09-07", user=bert)
        self.assertEqual(self.bevestiging(bert, "2026-09-07")["aanwezig"], "0")
        self.assertContains(b.get("trainers/"), "Aangepast door het bestuur")
        # De uren van anderen past hij als bestuurder wel aan (Tom krijgt een melding).
        self.actie(b, "ja", "2026-09-28", user=self.tom)
        self.assertEqual(self.bevestiging(self.tom, "2026-09-28")["bron"], "bestuur")
        self.assertIn("Bert", hulp("push")[-1]["bericht"]["tekst"])


class VerlofSeizoenTest(TrainersBasis):
    """Trainers: trainingen per ploeg, verlof per ploeg met melding, en begin en einde van het seizoen."""

    def setUp(self):
        super().setUp()
        self.bestuur = gebruiker("bestuur@khzs.be", first_name="Bert")
        exe(f"INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES ({self.bestuur}, 'khzs_rollen', 'bestuurder')")
        self.b = Klant().login("bestuur@khzs.be")
        self.b.vandaag = "2026-10-01"
        self.pa = exe("INSERT INTO {p}ploegen (naam, beschrijving, actief, aangemaakt) VALUES ('A-groep', '', 1, NOW())")["id"]
        self.pb = exe("INSERT INTO {p}ploegen (naam, beschrijving, actief, aangemaakt) VALUES ('B-groep', '', 1, NOW())")["id"]

    def vraag(self, nu):
        return hulp("eval", f="tr_vraag_aanwezigheid", args=[nu])["v"]

    def trainingen(self, van, tot):
        return [(t["moment"]["naam"], t["datum"]) for t in hulp("eval", f="tr_trainingen", args=[van, tot])["v"]]

    def pushes(self, wie):
        return [p["bericht"] for p in hulp("push") if p["endpoint"] == f"https://push.example/{wie}"]

    def test_verlof_per_ploeg(self):
        # De A-groep traint op maandag; 'Samen' (woensdag) is voor de A- en de B-groep, met Lies.
        r = self.s.post(f"trainers/moment/{self.m}/", {"wat": "bewaar", "naam": "A-groep", "weekdag": 1, "start": "18:00", "einde": "19:30",
                                                      "locatie": "Groot bad", "van": "2026-09-01", "ploegen[]": [self.pa]})
        self.assertEqual(r.status_code, 302, r.text[:1500])
        self.assertEqual(int(een(f"SELECT ploeg_id FROM {{p}}tr_moment_ploegen WHERE moment_id={self.m}")), self.pa)
        # Ook het bestuur (niet enkel het secretariaat) beheert de trainingen.
        r = self.b.post("trainers/beheer/", {"wat": "moment", "naam": "Samen", "weekdag": 3, "start": "19:00", "einde": "20:00",
                                            "van": "2026-09-01", "ploegen[]": [self.pa, self.pb]})
        self.assertEqual(r.status_code, 302, r.text[:1500])
        samen = int(een("SELECT id FROM {p}tr_momenten WHERE naam='Samen'"))
        self.assertEqual(int(een(f"SELECT COUNT(*) FROM {{p}}tr_moment_ploegen WHERE moment_id={samen}")), 2)
        self.l.vandaag = "2026-10-01"
        self.l.post("trainers/vast/", {"moment": samen, "aan": 1})
        r = self.b.get("trainers/beheer/")
        self.assertContains(r, "A-groep, B-groep")
        self.assertContains(r, "Ploeg (al haar trainingen)")
        # Een trainer beheert niets.
        self.assertEqual(self.t.post("trainers/beheer/", {"wat": "afgelast", "voor": f"p{self.pa}", "van": "2026-10-26"}).status_code, 403)

        # Herfstvakantie voor de A-groep: Tom krijgt een melding, 'Samen' gaat door (de B-groep traint nog).
        r = self.b.post("trainers/beheer/", {"wat": "afgelast", "voor": f"p{self.pa}", "soort": "vakantie", "van": "2026-10-26",
                                            "tot": "2026-11-01", "reden": "Herfstvakantie"})
        self.assertEqual(r.status_code, 302)
        rij = sql("SELECT moment_id, ploeg_id, soort, door_id FROM {p}tr_afgelast")[0]
        self.assertEqual(rij, {"moment_id": "0", "ploeg_id": str(self.pa), "soort": "vakantie", "door_id": str(self.bestuur)})
        tom = self.pushes("tom")
        self.assertEqual(tom[-1]["titel"], "Training afgelast")
        self.assertIn("Herfstvakantie", tom[-1]["tekst"])
        self.assertIn("A-groep", tom[-1]["tekst"])
        self.assertFalse([p for p in self.pushes("lies") if p["titel"] == "Training afgelast"])
        self.t.vandaag = "2026-10-20"
        r = self.t.get("trainers/planning/", week="2026-10-26")
        self.assertContains(r, "Afgelast: Herfstvakantie")
        self.assertEqual(r.text.count("Afgelast:"), 1)  # enkel de A-groep, niet 'Samen'
        # Verlof voor de B-groep: nu valt ook 'Samen' weg, Lies krijgt een melding.
        self.b.post("trainers/beheer/", {"wat": "afgelast", "voor": f"p{self.pb}", "soort": "verlof", "van": "2026-10-28", "tot": "2026-10-28"})
        lies = self.pushes("lies")
        self.assertEqual(lies[-1]["titel"], "Training afgelast")
        self.assertIn("Verlof", lies[-1]["tekst"])
        self.assertEqual(self.t.get("trainers/planning/", week="2026-10-26").text.count("Afgelast:"), 2)
        # Geen vraag 'Was je aanwezig?' en niet te bevestigen.
        self.assertEqual(self.vraag("2026-10-26 19:45")["gevraagd"], 0)
        self.assertEqual(self.vraag("2026-10-28 20:15")["gevraagd"], 0)
        self.t.vandaag = "2026-10-27"
        self.actie(self.t, "ja", "2026-10-26")
        self.assertFalse(sql("SELECT id FROM {p}tr_bevestigingen WHERE datum='2026-10-26'"))
        # Verlof van de A-groep weg → de trainingen gaan toch door, Tom krijgt een melding.
        self.b.post("trainers/beheer/", {"wat": "afgelast_weg", "id": een(f"SELECT id FROM {{p}}tr_afgelast WHERE ploeg_id={self.pa}")})
        self.assertEqual(self.pushes("tom")[-1]["titel"], "Training gaat toch door")
        self.assertEqual(self.vraag("2026-10-26 19:45")["gevraagd"], 1)
        # Voorkeur uit → geen melding.
        exe(f"INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES ({self.tom}, 'khzs_push_afgelast', '0')")
        aantal = len(self.pushes("tom"))
        self.b.post("trainers/beheer/", {"wat": "afgelast", "voor": f"p{self.pa}", "soort": "zwembad", "van": "2026-11-02", "tot": "2026-11-02"})
        self.assertEqual(len(self.pushes("tom")), aantal)
        self.assertContains(self.b.get("trainers/beheer/"), "zwembad dicht")
        # Een ploeg verwijderen: de trainingen blijven, de koppeling en het verlof van de ploeg verdwijnen.
        self.b.post(f"ploegen/{self.pa}/", {"actie": "verwijder_ploeg"})
        self.assertFalse(sql(f"SELECT id FROM {{p}}tr_moment_ploegen WHERE ploeg_id={self.pa}"))
        self.assertFalse(sql(f"SELECT id FROM {{p}}tr_afgelast WHERE ploeg_id={self.pa}"))
        self.assertEqual(int(een("SELECT COUNT(*) FROM {p}tr_momenten")), 2)
        # Ongeldige invoer.
        self.b.post("trainers/beheer/", {"wat": "afgelast", "voor": "p999", "van": "2026-11-02"})
        self.b.post("trainers/beheer/", {"wat": "afgelast", "voor": "alle", "van": "2026-11-05", "tot": "2026-11-01"})
        self.assertEqual(int(een("SELECT COUNT(*) FROM {p}tr_afgelast")), 1)

    def test_seizoen(self):
        # Voor de seizoenen loopt alles door.
        self.assertEqual(len(self.trainingen("2027-07-01", "2027-07-31")), 4)
        self.b.post("trainers/beheer/", {"wat": "seizoen", "naam": "", "van": "2026-09-01", "tot": "2027-06-30"})
        self.b.post("trainers/beheer/", {"wat": "seizoen", "naam": "Seizoen 27-28", "van": "2027-09-01", "tot": "2028-06-30"})
        self.b.post("trainers/beheer/", {"wat": "seizoen", "van": "2027-06-01", "tot": "2027-08-01"})  # overlapt
        self.b.post("trainers/beheer/", {"wat": "seizoen", "van": "2027-08-01", "tot": "2027-07-01"})  # einde voor begin
        self.assertEqual([r["naam"] for r in sql("SELECT naam FROM {p}tr_seizoenen ORDER BY van")], ["Seizoen 2026–2027", "Seizoen 27-28"])
        # Zomer: geen trainingen in de planning, op de trainingspagina, in de uren of bij Mijn trainingen.
        self.assertEqual(self.trainingen("2027-07-01", "2027-08-31"), [])
        self.assertEqual(len(self.trainingen("2027-06-01", "2027-06-30")), 4)
        self.assertEqual(self.trainingen("2027-09-01", "2027-09-07"), [("A-groep", "2027-09-06")])
        self.t.vandaag = "2027-07-10"
        r = self.t.get("trainers/planning/", week="2027-07-05")
        self.assertContains(r, "Buiten het seizoen")
        self.assertNotContains(r, "Groot bad")
        self.assertEqual(self.t.get(f"trainers/training/{self.m}/2027-07-05/").status_code, 404)
        self.assertEqual(self.t.get(f"trainers/training/{self.m}/2027-06-28/").status_code, 200)
        r = self.t.get("trainers/")
        self.assertContains(r, "Buiten het seizoen")
        self.assertNotContains(r, "Ik geef training")
        # Geen vraag 'Was je aanwezig?' buiten het seizoen, wel erin.
        self.assertEqual(self.vraag("2027-07-05 19:45")["gevraagd"], 0)
        self.assertEqual(self.vraag("2027-06-28 19:45")["gevraagd"], 1)
        self.assertEqual(self.actie(self.t, "ja", "2027-07-05").status_code, 404)
        # Uren: in juli telt niets.
        pm = Klant().login("pm@khzs.be")
        pm.vandaag = "2027-08-01"
        pm.post("trainers/tarieven/", {"user": self.tom, "soort": "uur", "bedrag": "15", "geldig_vanaf": "2026-09-01"})
        exe(f"INSERT INTO {{p}}tr_bevestigingen (moment_id, datum, user_id, aanwezig, bron) VALUES ({self.m}, '2027-07-05', {self.tom}, 1, 'trainer')")
        self.assertNotContains(pm.get("trainers/uren/", maand="2027-07"), "€ 22,50")
        # Het bestuur ziet een waarschuwing na het einde van het laatste seizoen.
        self.b.vandaag = "2028-07-02"
        self.assertContains(self.b.get("trainers/beheer/"), "Voeg het volgende seizoen toe")
        # Dagen vóór het eerste seizoen blijven zoals ze waren.
        self.assertTrue(hulp("eval", f="tr_in_seizoen", args=["2026-08-31"])["v"])
        # Seizoenen weg → alles loopt weer door.
        for sid in [r["id"] for r in sql("SELECT id FROM {p}tr_seizoenen")]:
            self.b.post("trainers/beheer/", {"wat": "seizoen_weg", "id": sid})
        self.assertEqual(len(self.trainingen("2027-07-01", "2027-07-31")), 4)


class SoortenKalenderTest(TrainersBasis):
    """Trainers van de zwemschool en van de competitie, en de kalender (dag, week, maand)."""

    def setUp(self):
        super().setUp()
        self.bestuur = gebruiker("bestuur@khzs.be", first_name="Bert")
        exe(f"INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES ({self.bestuur}, 'khzs_rollen', 'bestuurder')")
        self.b = Klant().login("bestuur@khzs.be")
        self.b.vandaag = "2026-09-16"

    def tekst(self, r):
        return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", r.text))

    def test_trainer_zwemschool_ziet_geen_wedstrijden(self):
        zs = gebruiker("zs@khzs.be", "trainer_zwemschool", first_name="Zoë")
        z = Klant().login("zs@khzs.be")
        z.vandaag = "2026-09-01"
        # Startpagina is Trainingen; geen menu Wedstrijden, geen nakijken of uitslagen.
        r = z.get("")
        self.assertEqual(r.status_code, 302)
        self.assertTrue(r.headers["Location"].endswith("/leden/trainers/"), r.headers["Location"])
        self.assertEqual(z.get("wedstrijden/").status_code, 302)
        menu = z.get("trainers/").text.replace("\n", "")
        self.assertIn(">Trainingen</a>", menu)
        self.assertIn(">Ploegen</a>", menu)
        self.assertNotIn(">Wedstrijden</a>", menu)
        self.assertEqual(z.get("zwemmers/uitslagen/").status_code, 403)
        # Wel: eigen schema, aanwezigheid en de meldingen voor trainers.
        z.post("trainers/vast/", {"moment": self.m, "aan": 1})
        self.assertEqual(int(een(f"SELECT COUNT(*) FROM {{p}}tr_vast WHERE user_id={zs}")), 1)
        self.assertContains(z.get("trainers/"), "Ik geef training")
        self.assertContains(z.get("meldingen/"), "Na een training: was je aanwezig?")
        # Ploegen: de ploegen van de zwemschool wel, die van de competitie niet. Ze kan trainer van een ploeg zijn.
        self.b.post("ploegen/", {"naam": "A-groep", "soort": "competitie", "trainers[]": [self.tom], "actief": "on"})
        self.b.post("ploegen/", {"naam": "Zwemschool rood", "soort": "zwemschool", "trainers[]": [zs], "actief": "on"})
        self.b.post("ploegen/", {"naam": "Zwemschool geel", "soort": "zwemschool", "actief": "on"})
        pa = int(een("SELECT id FROM {p}ploegen WHERE naam='A-groep'"))
        rood = int(een("SELECT id FROM {p}ploegen WHERE naam='Zwemschool rood'"))
        self.assertEqual(een(f"SELECT soort FROM {{p}}ploegen WHERE id={rood}"), "zwemschool")
        self.assertEqual(een(f"SELECT soort FROM {{p}}ploegen WHERE id={pa}"), "competitie")
        self.assertEqual(int(een(f"SELECT user_id FROM {{p}}ploeg_trainers WHERE ploeg_id={rood}")), zs)
        r = z.get("ploegen/")
        self.assertContains(r, "Zwemschool rood")
        self.assertContains(r, "Zwemschool geel")
        self.assertNotContains(r, "A-groep")
        self.assertEqual(z.get(f"ploegen/{pa}/").status_code, 403)
        self.assertContains(z.get(f"ploegen/{rood}/"), "<h1>Zwemschool rood")  # _layout.php overschreef $p (de ploeg)
        # Een trainer van de competitie houdt alles, ook alle ploegen.
        self.assertEqual(self.t.get("wedstrijden/").status_code, 200)
        self.assertEqual(self.t.get("zwemmers/uitslagen/").status_code, 200)
        self.assertContains(self.t.get("ploegen/"), "Zwemschool geel")
        # Een trainer van de zwemschool die ook ouder is, ziet de wedstrijden voor de eigen kinderen.
        beide = gebruiker("beide@khzs.be")
        exe(f"INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES ({beide}, 'khzs_rollen', 'trainer_zwemschool,ouder')")
        k = Klant().login("beide@khzs.be")
        self.assertEqual(k.get("wedstrijden/").status_code, 200)
        self.assertEqual(k.get("zwemmers/uitslagen/").status_code, 403)
        # De rol aanvragen kan in het stappenplan en bij Mijn rollen.
        self.assertContains(z.get("rollen/"), "Trainer competitie")
        nieuw = Klant()
        registreer(nieuw, "nieuw@khzs.be")
        nieuw.post("welkom/", {"rollen[]": ["trainer_zwemschool"]}, query={"stap": "staf"})
        self.assertEqual(een("SELECT rol FROM {p}rolaanvragen WHERE status='open'"), "trainer_zwemschool")

    def test_kalender(self):
        # Woensdag: B-groep met Lies. Tom (A-groep, maandag) meldt zich af op 14/9, Lies neemt over.
        self.s.post("trainers/beheer/", {"wat": "moment", "naam": "B-groep", "weekdag": 3, "start": "17:00", "einde": "18:00",
                                         "locatie": "Klein bad", "van": "2026-09-01"})
        mb = int(een("SELECT id FROM {p}tr_momenten WHERE naam='B-groep'"))
        self.l.post("trainers/vast/", {"moment": mb, "aan": 1})
        self.actie(self.t, "afwezig", "2026-09-14", reden="ziek")
        self.actie(self.l, "overnemen", "2026-09-14", vervangt=self.tom)
        self.t.vandaag = "2026-09-16"

        # Week (standaard), eigen trainingen: de A-groep op 14/9 met de vervanger, niet de B-groep van Lies.
        r = self.t.get("trainers/kalender/")
        self.assertEqual(r.status_code, 200, r.text[:1500])
        t = self.tekst(r)
        self.assertIn("14 Sep – 20 Sep 2026", t)
        self.assertIn("A-groep", t)
        self.assertIn("Lies voor Tom", t)
        self.assertIn('<s title="afgemeld">Tom</s>', r.text)
        self.assertNotIn(">B-groep</strong>", r.text)
        self.assertIn(f"/trainers/training/{self.m}/2026-09-14/", r.text)  # openen om te bevestigen of aan te passen
        # Filter naar alle trainingen.
        self.assertIn(">B-groep</strong>", self.t.get("trainers/kalender/", wie="alle").text)
        # Een trainer kan niet op een andere trainer filteren: dat wordt de eigen kalender.
        self.assertNotIn(">B-groep</strong>", self.t.get("trainers/kalender/", wie=str(self.lies)).text)

        # Maand: vier maandagen in september (7, 14, 21, 28); de grid loopt van 31/8 tot 4/10.
        r = self.t.get("trainers/kalender/", weergave="maand", datum="2026-09-10")
        self.assertEqual(r.text.count(">A-groep</strong>"), 4)
        self.assertIn("september 2026", self.tekst(r))
        self.assertEqual(r.text.count('class="tr-k-cel'), 35)
        self.assertIn("weergave=dag&#038;datum=2026-09-21", r.text)

        # Dag: 7/9 is voorbij en niet bevestigd → meteen bevestigen vanuit de kalender.
        r = self.t.get("trainers/kalender/", weergave="dag", datum="2026-09-07")
        self.assertIn("niet bevestigd", r.text)
        self.assertIn("✓ Ja", r.text)
        terug = APP + "trainers/kalender/?weergave=dag&datum=2026-09-07"
        r = self.actie(self.t, "ja", "2026-09-07", terug=terug)
        self.assertEqual(r.headers["Location"], terug)
        self.assertEqual(een(f"SELECT aanwezig FROM {{p}}tr_bevestigingen WHERE user_id={self.tom} AND datum='2026-09-07'"), "1")
        self.assertIn("aanwezig", self.t.get("trainers/kalender/", weergave="dag", datum="2026-09-07").text)
        # In de week staan de vinkjes van de bevestiging (✓) bij de naam en het blok is 'aanwezig'.
        r = self.t.get("trainers/kalender/", datum="2026-09-07")
        self.assertIn('<span class="tr-merk ja">✓</span>', r.text)
        self.assertIn("tr-k-blok mijn st-ja", r.text)
        # Toekomst: afmelden kan vanuit de dagweergave.
        r = self.t.get("trainers/kalender/", weergave="dag", datum="2026-09-21")
        self.assertIn("Kan niet", r.text)
        # Op een lege dag.
        self.assertIn("Je hebt geen training op deze dag.", self.t.get("trainers/kalender/", weergave="dag", datum="2026-09-22").text)

        # Bestuur: standaard alles, filteren op trainer of op ploeg.
        r = self.b.get("trainers/kalender/")
        self.assertIn(">A-groep</strong>", r.text)
        self.assertIn(">B-groep</strong>", r.text)
        r = self.b.get("trainers/kalender/", wie=str(self.lies))
        self.assertIn(">B-groep</strong>", r.text)
        self.assertIn(">A-groep</strong>", r.text)  # Lies vervangt Tom op 14/9
        r = self.b.get("trainers/kalender/", wie=str(self.lies), datum="2026-09-23")
        self.assertNotIn(">A-groep</strong>", r.text)
        pa = exe("INSERT INTO {p}ploegen (naam, beschrijving, soort, actief, aangemaakt) VALUES ('A-ploeg', '', 'competitie', 1, NOW())")["id"]
        exe(f"INSERT INTO {{p}}tr_moment_ploegen (moment_id, ploeg_id) VALUES ({self.m}, {pa})")
        r = self.b.get("trainers/kalender/", ploeg=str(pa))
        self.assertIn(">A-groep</strong>", r.text)
        self.assertNotIn(">B-groep</strong>", r.text)
        self.assertIn("A-ploeg", r.text)
        # Een onbekende weergave wordt de week; wie geen trainer of bestuur is, kan niet binnen.
        self.assertIn("14 Sep – 20 Sep 2026", self.tekst(self.b.get("trainers/kalender/", weergave="jaar")))
        self.assertEqual(Klant().login("ouder@khzs.be").get("trainers/kalender/").status_code, 403)


class ZwemmersAanwezigTest(TrainersBasis):
    """Trainers duiden aan welke zwemmers op een training waren; zwemmers en ouders bevestigen niets en zien niets."""

    def setUp(self):
        super().setUp()
        self.bestuur = gebruiker("bestuur@khzs.be", first_name="Bert")
        exe(f"INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES ({self.bestuur}, 'khzs_rollen', 'bestuurder')")
        self.b = Klant().login("bestuur@khzs.be")
        self.zs = gebruiker("zs@khzs.be", "trainer_zwemschool", first_name="Zoë")
        self.z = Klant().login("zs@khzs.be")
        self.ouder = int(een("SELECT ID FROM wp_users WHERE user_login='ouder@khzs.be'"))
        self.o = Klant().login("ouder@khzs.be")
        # De A-groep (maandag) is voor een ploeg van de competitie en een groep van de zwemschool.
        self.pa = exe("INSERT INTO {p}ploegen (naam, beschrijving, soort, actief, aangemaakt) VALUES ('A-ploeg', '', 'competitie', 1, NOW())")["id"]
        self.rood = exe("INSERT INTO {p}ploegen (naam, beschrijving, soort, actief, aangemaakt) VALUES ('Rood', '', 'zwemschool', 1, NOW())")["id"]
        for pl in (self.pa, self.rood):
            exe(f"INSERT INTO {{p}}tr_moment_ploegen (moment_id, ploeg_id) VALUES ({self.m}, {pl})")
        self.zw = {}
        for naam, pl in (("Mila", self.pa), ("Jules", self.pa), ("Kobe", self.pa), ("Noor", self.rood), ("Robbe", self.rood)):
            self.zw[naam] = zwemmer(self.ouder, voornaam=naam, naam="Test")
            exe(f"INSERT INTO {{p}}ploegleden (zwemmer_id, ploeg_id, status, aangemaakt) VALUES ({self.zw[naam]}, {pl}, 'goedgekeurd', NOW())")
        for k in (self.t, self.l, self.z, self.b, self.o):
            k.vandaag = "2026-09-07"

    def zwemmers(self, k, ids, datum="2026-09-07", **extra):
        return k.post("trainers/zwemmers/actie/", {"moment": self.m, "datum": datum, "zwemmer[]": ids, **extra})

    def aanwezig(self, naam, datum="2026-09-07"):
        r = een(f"SELECT aanwezig FROM {{p}}tr_zwemmers WHERE zwemmer_id={self.zw[naam]} AND datum='{datum}'")
        return None if r is None else int(r)

    def test_aanduiden_rechten_en_overzicht(self):
        url = f"trainers/training/{self.m}/2026-09-07/"
        # Vóór de dag van de training kan het nog niet.
        self.l.vandaag = "2026-09-01"
        self.assertContains(self.l.get(url), "vanaf de dag van de training")
        self.l.vandaag = "2026-09-07"
        # Lies (competitie, niet ingepland) ziet de zwemmers van de competitieploeg, allemaal aangevinkt; niet die van de zwemschool.
        r = self.l.get(url)
        self.assertEqual(r.status_code, 200, r.text[:1500])
        self.assertContains(r, 'id="zwemmers"')
        self.assertContains(r, "Mila Test")
        self.assertNotContains(r, "Noor Test")
        self.assertEqual(r.text.count('name="zwemmer[]" value'), 3)
        self.assertEqual(r.text.count(" checked>"), 3)
        self.assertContains(r, "Iedereen aanwezig")
        # Tom staat op de training: bij Mijn trainingen staat ze bij 'Zwemmers aanduiden'.
        self.assertContains(self.t.get("trainers/"), "Zwemmers aanduiden")
        self.assertContains(self.t.get("trainers/kalender/"), "zwemmers aanduiden")
        # Lies duidt aan: Kobe was er niet.
        r = self.zwemmers(self.l, [self.zw["Mila"], self.zw["Jules"]])
        self.assertEqual(r.status_code, 302)
        self.assertEqual([self.aanwezig(n) for n in ("Mila", "Jules", "Kobe", "Noor")], [1, 1, 0, None])
        r = self.l.get(url)
        self.assertContains(r, "Aangeduid door Lies")
        self.assertEqual(r.text.count(" checked>"), 2)
        self.assertNotContains(self.t.get("trainers/"), "Zwemmers aanduiden")
        self.assertContains(self.t.get("trainers/"), "2/3 zwemmers aanwezig")
        self.assertContains(self.t.get("trainers/kalender/"), "2/3 zwemmers")
        # Zoë (zwemschool) ziet enkel de groep van de zwemschool; zwemmers van de competitie meesturen verandert niets.
        r = self.z.get(url)
        self.assertContains(r, "Noor Test")
        self.assertNotContains(r, "Mila Test")
        self.zwemmers(self.z, [self.zw["Noor"], self.zw["Kobe"]])
        self.assertEqual([self.aanwezig(n) for n in ("Noor", "Robbe", "Kobe")], [1, 0, 0])
        # Het bestuur ziet beide ploegen.
        r = self.b.get(url)
        self.assertContains(r, "Mila Test")
        self.assertContains(r, "Noor Test")
        # Ouders en zwemmers: geen toegang, geen vraag 'Was je aanwezig?'.
        self.assertEqual(self.o.get(url).status_code, 403)
        self.assertEqual(self.o.get("trainers/zwemmers/").status_code, 403)
        self.assertEqual(self.zwemmers(self.o, [self.zw["Kobe"]]).status_code, 403)
        self.assertEqual(self.aanwezig("Kobe"), 0)
        self.assertNotContains(self.o.get("meldingen/"), "was je aanwezig")
        self.o.json("meldingen/abonneer/", {"endpoint": "https://push.example/ouder", "keys": {"p256dh": "x", "auth": "y"}})
        hulp("eval", f="tr_vraag_aanwezigheid", args=["2026-09-07 20:00:00"])
        self.assertNotIn("https://push.example/ouder", [p["endpoint"] for p in hulp("push")])

        # Overzicht: Lies ziet de competitie, met percentages; Zoë de zwemschool; het bestuur alles.
        r = self.l.get("trainers/zwemmers/", van="2026-09-01", tot="2026-09-30")
        self.assertEqual(r.status_code, 200, r.text[:1500])
        t = re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", r.text))
        self.assertIn("A-ploeg", t)
        self.assertIn("67 %", t)
        self.assertIn("Kobe Test", t)
        self.assertNotIn("Noor Test", t)
        r = self.z.get("trainers/zwemmers/", van="2026-09-01", tot="2026-09-30")
        self.assertContains(r, "Noor Test")
        self.assertNotContains(r, "Mila Test")
        r = self.b.get("trainers/zwemmers/", van="2026-09-01", tot="2026-09-30")
        self.assertContains(r, "Noor Test")
        self.assertContains(r, "Mila Test")
        r = self.b.get("trainers/zwemmers/", van="2026-09-01", tot="2026-09-30", ploeg=str(self.rood))
        self.assertNotContains(r, "Mila Test")
        # Excel: per zwemmer, per ploeg en detail.
        r = self.l.get("trainers/zwemmers/export/", van="2026-09-01", tot="2026-09-30")
        self.assertEqual(r.status_code, 200)
        with zipfile.ZipFile(io.BytesIO(r.content)) as zf:
            alles = "".join(zf.read(n).decode() for n in zf.namelist() if n.endswith(".xml"))
        for tekst in ("Per zwemmer", "Per ploeg", "Detail", "Kobe Test", "A-ploeg"):
            self.assertIn(tekst, alles)
        self.assertNotIn("Noor Test", alles)
        # Wissen: enkel de eigen soort; de aanduiding van de zwemschool blijft.
        self.zwemmers(self.l, [], actie="wis")
        self.assertEqual([self.aanwezig(n) for n in ("Mila", "Noor")], [None, 1])
        # Afgelast: niets aan te duiden.
        self.s.post("trainers/beheer/", {"wat": "afgelast", "moment": 0, "van": "2026-09-14", "tot": "2026-09-14", "reden": "Zwembad dicht"})
        self.l.vandaag = "2026-09-14"
        self.zwemmers(self.l, [self.zw["Mila"]], datum="2026-09-14")
        self.assertIsNone(self.aanwezig("Mila", "2026-09-14"))
        # Een zwemmer verwijderen wist ook de aanwezigheden.
        hulp("eval", f="verwijder_zwemmer", args=[self.zw["Noor"]])
        self.assertEqual(int(een(f"SELECT COUNT(*) FROM {{p}}tr_zwemmers WHERE zwemmer_id={self.zw['Noor']}")), 0)

    def status(self, naam, datum="2026-09-07"):
        r = sql(f"SELECT aanwezig, status FROM {{p}}tr_zwemmers WHERE zwemmer_id={self.zw[naam]} AND datum='{datum}'")[0]
        return int(r["aanwezig"]), r["status"]

    def test_statussen_en_statistiek(self):
        # Lore zwemt zelf (eigen account) in de A-ploeg; een ander gezin heeft niets met deze zwemmers te maken.
        zelf = gebruiker("zelf@khzs.be", "zwemmer")
        self.zw["Lore"] = zwemmer(zelf, voornaam="Lore", naam="Test")
        exe(f"INSERT INTO {{p}}ploegleden (zwemmer_id, ploeg_id, status, aangemaakt) VALUES ({self.zw['Lore']}, {self.pa}, 'goedgekeurd', NOW())")
        gebruiker("ander@khzs.be", "ouder")
        zk, ander = Klant().login("zelf@khzs.be"), Klant().login("ander@khzs.be")
        for k in (self.t, self.l, self.z, self.b, self.o, zk, ander):
            k.vandaag = "2026-09-14"
        # 7/9: Mila vroeger weg, Jules te laat, Kobe ziek (ook al stond het vinkje aan), Lore aanwezig.
        ids = [self.zw[n] for n in ("Mila", "Kobe", "Lore")]
        r = self.zwemmers(self.l, ids, **{f"status[{self.zw['Mila']}]": "vroeger", f"status[{self.zw['Jules']}]": "laat",
                                          f"status[{self.zw['Kobe']}]": "ziek", f"status[{self.zw['Lore']}]": "onzin"})
        self.assertEqual(r.status_code, 302)
        self.assertEqual([self.status(n) for n in ("Mila", "Jules", "Kobe", "Lore")], [(1, "vroeger"), (1, "laat"), (0, "ziek"), (1, "")])
        r = self.l.get(f"trainers/training/{self.m}/2026-09-07/")
        self.assertContains(r, 'value="laat" selected')
        self.assertContains(r, "gekozen st-ziek")
        # 14/9: Kobe en Lore aanwezig, Mila en Jules gewoon afwezig.
        self.zwemmers(self.l, [self.zw["Kobe"], self.zw["Lore"]], datum="2026-09-14")

        # Statistiek voor trainers: totalen, per maand, per training, per weekdag, verdeling en per zwemmer.
        r = self.l.get("trainers/zwemmers/", van="2026-09-01", tot="2026-09-30")
        t = re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", r.text))
        for tekst in ("Per maand", "Per training", "Per weekdag", "Verdeling van de zwemmers", "Per zwemmer", "keer te laat", "zonder ziek"):
            self.assertIn(tekst, t)
        self.assertIn("september 2026", t)
        self.assertIn(f"aanwezigheid/{self.zw['Kobe']}/", r.text)
        self.assertIn("tr-zw-balk", r.text)
        # Kobe: 1 van 2 (50 %), zonder de ziekte 1 van 1 (100 %).
        rij = re.search(r"Kobe Test.*?</tr>", r.text, re.S).group(0)
        self.assertIn("50 %", rij)
        self.assertIn("100 %", rij)
        r = self.l.get("trainers/zwemmers/export/", van="2026-09-01", tot="2026-09-30")
        with zipfile.ZipFile(io.BytesIO(r.content)) as zf:
            alles = "".join(zf.read(n).decode() for n in zf.namelist() if n.endswith(".xml"))
        for tekst in ("Per maand", "Per training", "Te laat", "Vroeger weg", "Aanwezig zonder ziek (%)", "ziek"):
            self.assertIn(tekst, alles)

        # De ouder ziet de statistiek van de eigen kinderen: kort op de pagina van de zwemmer, volledig apart.
        r = self.o.get(f"zwemmers/{self.zw['Mila']}/")
        self.assertContains(r, "Trainingen")
        self.assertContains(r, f"aanwezigheid/{self.zw['Mila']}/")
        r = self.o.get(f"aanwezigheid/{self.zw['Mila']}/")
        self.assertEqual(r.status_code, 200, r.text[:1500])
        t = re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", r.text))
        self.assertIn("Aanwezigheid Mila Test", t)
        self.assertIn("vroeger weg", t)
        self.assertIn("50 %", t)
        self.assertIn("Laatst aanwezig", t)
        self.assertIn("Gemiddelde van A-ploeg", t)
        self.assertNotIn("Kobe Test", t.split("Aanwezigheid Mila Test", 1)[1])  # geen andere zwemmers in de statistiek
        # De zwemmer zelf: enkel de eigen statistiek, niet het overzicht van de trainers.
        self.assertEqual(zk.get(f"aanwezigheid/{self.zw['Lore']}/").status_code, 200)
        self.assertEqual(zk.get(f"aanwezigheid/{self.zw['Mila']}/").status_code, 403)
        self.assertEqual(zk.get("trainers/zwemmers/").status_code, 403)
        # Een ander gezin en een trainer van de andere soort: geen toegang. De trainer van de competitie wel.
        self.assertEqual(ander.get(f"aanwezigheid/{self.zw['Mila']}/").status_code, 403)
        self.assertEqual(self.z.get(f"aanwezigheid/{self.zw['Mila']}/").status_code, 403)
        self.assertEqual(self.l.get(f"aanwezigheid/{self.zw['Mila']}/").status_code, 200)
        self.assertEqual(self.b.get(f"aanwezigheid/{self.zw['Mila']}/").status_code, 200)


class RollenTest(Basis):
    """Meerdere rollen per account: aanvragen, goedkeuren, afkeuren, een andere rol geven (zoals test_rollen.py in Django)."""

    def setUp(self):
        super().setUp()
        self.bestuur = gebruiker("bestuur@khzs.be", first_name="Bert")
        exe(f"INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES ({self.bestuur}, 'khzs_rollen', 'bestuurder')")
        self.lid = gebruiker("lid@khzs.be", "ouder", first_name="Lien")  # enkel khzs_rol: account van vóór de rollen
        self.b = Klant().login("bestuur@khzs.be")
        self.l = Klant().login("lid@khzs.be")
        self.l.json("meldingen/abonneer/", {"endpoint": f"https://push.example/{self.lid}", "keys": {"p256dh": "x", "auth": "y"}})

    def rollen(self, uid):
        return set(filter(None, hulp("meta", id=uid, key="khzs_rollen")["v"].split(",")))

    def aanvraag(self, rol="trainer"):
        self.l.post("rollen/", {"rol": rol, "motivatie": "B-groep"})
        return int(een(f"SELECT id FROM {{p}}rolaanvragen WHERE user_id={self.lid} AND rol='{rol}'"))

    def status(self, a):
        return een(f"SELECT status FROM {{p}}rolaanvragen WHERE id={a}")

    def test_aanvragen_en_goedkeuren(self):
        a = self.aanvraag()
        self.assertEqual(self.status(a), "open")
        self.assertEqual(self.l.get("zwemmers/uitslagen/").status_code, 403)
        self.l.post("rollen/", {"rol": "trainer"})  # tweede keer: geweigerd
        self.assertEqual(int(een("SELECT COUNT(*) FROM {p}rolaanvragen")), 1)
        r = self.b.get("gebruikers/")
        self.assertContains(r, "Rolaanvragen (1)")
        self.assertContains(r, "B-groep")
        self.b.post(f"gebruikers/aanvraag/{a}/", {"actie": "goedkeuren"})
        self.assertEqual(self.status(a), "goedgekeurd")
        self.assertEqual(self.rollen(self.lid), {"ouder", "trainer"})
        self.assertEqual(hulp("meta", id=self.lid, key="khzs_rol")["v"], "trainer")
        push = hulp("push")[-1]["bericht"]
        self.assertIn("goedgekeurd", push["tekst"])
        self.assertTrue(push["url"].endswith("/leden/rollen/"))
        self.assertContains(self.l.get("meldingen/"), "Je aanvraag voor de rol trainer competitie is goedgekeurd.")
        self.l.get("rollen/")
        self.assertNotContains(self.l.get("meldingen/"), "is goedgekeurd")
        self.assertEqual(self.l.get("zwemmers/uitslagen/").status_code, 200)

    def test_afkeuren_vraagt_reden(self):
        a = self.aanvraag("secretaris")
        self.b.post(f"gebruikers/aanvraag/{a}/", {"actie": "afkeuren", "reden": ""})
        self.assertEqual(self.status(a), "open")
        self.b.post(f"gebruikers/aanvraag/{a}/", {"actie": "afkeuren", "reden": "Vraag het eerst aan het bestuur."})
        self.assertEqual(self.status(a), "afgekeurd")
        self.assertEqual(self.rollen(self.lid), set())  # niets gewijzigd: nog steeds enkel khzs_rol
        self.assertIn("afgekeurd. Vraag het eerst aan het bestuur.", hulp("push")[-1]["bericht"]["tekst"])
        self.b.post(f"gebruikers/aanvraag/{a}/", {"actie": "goedkeuren"})
        self.assertEqual(self.status(a), "afgekeurd")
        self.assertContains(self.l.get("rollen/"), "Vraag het eerst aan het bestuur.")

    def test_andere_rol_toekennen(self):
        a = self.aanvraag("secretaris")
        self.b.post(f"gebruikers/aanvraag/{a}/", {"actie": "andere", "rol": "trainer", "reden": "Eerst als trainer."})
        self.assertEqual(self.status(a), "andere_rol")
        self.assertEqual(een(f"SELECT toegekende_rol FROM {{p}}rolaanvragen WHERE id={a}"), "trainer")
        self.assertEqual(self.rollen(self.lid), {"ouder", "trainer"})
        self.assertIn("kreeg de rol trainer", hulp("push")[-1]["bericht"]["tekst"])

    def test_zwemmer_zonder_goedkeuring_en_intrekken(self):
        self.l.post("rollen/", {"rol": "zwemmer"})
        self.assertEqual(self.rollen(self.lid), {"ouder", "zwemmer"})
        self.l.post("rollen/", {"rol": "beheerder"})
        self.assertEqual(int(een("SELECT COUNT(*) FROM {p}rolaanvragen")), 0)
        a = self.aanvraag()
        self.l.post("rollen/", {"actie": "intrekken", "aanvraag": a})
        self.assertEqual(self.status(a), "ingetrokken")

    def test_rollen_in_het_stappenplan(self):
        k = Klant()
        registreer(k, "nieuw@test.be", "Ann", "P")
        uid = int(een("SELECT ID FROM wp_users WHERE user_login='nieuw@test.be'"))
        self.assertEqual(self.rollen(uid), set())
        kind(k, "Lien", "P", "2013-03-02", "F")
        self.assertEqual(self.rollen(uid), {"ouder"})
        r = k.post("welkom/", {"rollen[]": ["trainer", "beheerder"], "motivatie": "B-groep"}, query={"stap": "staf"})
        self.assertTrue(r.headers["Location"].endswith("stap=klaar"))
        self.assertEqual(sql(f"SELECT rol, motivatie FROM {{p}}rolaanvragen WHERE user_id={uid}"), [{"rol": "trainer", "motivatie": "B-groep"}])
        self.assertContains(k.get("welkom/", stap="klaar"), "Trainer competitie (wacht)")

    def test_rechtstreeks_en_rechten_bestuurder(self):
        a = self.aanvraag()
        self.b.post("gebruikers/", {"user": self.lid, "actie": "toevoegen", "rol": "trainer"})
        self.assertEqual(self.status(a), "goedgekeurd")
        self.b.post("gebruikers/", {"user": self.lid, "actie": "afnemen", "rol": "ouder"})
        self.assertEqual(self.rollen(self.lid), {"trainer"})
        self.b.post("gebruikers/", {"user": self.lid, "actie": "afnemen", "rol": "trainer"})  # laatste rol blijft
        self.assertEqual(self.rollen(self.lid), {"trainer"})
        self.b.post("gebruikers/", {"user": self.lid, "actie": "toevoegen", "rol": "beheerder"})
        self.assertNotIn("beheerder", self.rollen(self.lid))
        # bestuurder: leden en rollen, geen wedstrijden, uitslagen of backup
        self.assertEqual(self.b.get("wedstrijd/nieuw/").status_code, 403)
        self.assertEqual(self.b.get("zwemmers/uitslagen/").status_code, 403)
        self.assertEqual(self.b.get("backup/").status_code, 403)
        self.assertEqual(Klant().login("lid@khzs.be").get("gebruikers/").status_code, 403)
        # WordPress-beheerder blijft altijd beheerder
        adm = Klant().login("admin", "admin123")
        aid = int(een("SELECT ID FROM wp_users WHERE user_login='admin'"))
        adm.post("gebruikers/", {"user": aid, "actie": "afnemen", "rol": "beheerder"})
        self.assertEqual(adm.get("backup/").status_code, 200)

    def test_nieuwe_aanvraag_meldt_rolbeheerders(self):
        self.b.json("meldingen/abonneer/", {"endpoint": f"https://push.example/{self.bestuur}", "keys": {"p256dh": "x", "auth": "y"}})
        self.aanvraag()
        push = hulp("push")
        self.assertEqual([p["endpoint"] for p in push], [f"https://push.example/{self.bestuur}"])
        self.assertIn("Lien vraagt de rol trainer", push[0]["bericht"]["tekst"])
        self.assertContains(self.b.get("meldingen/"), "Nieuwe rolaanvraag om te behandelen")
        self.assertNotContains(self.l.get("meldingen/"), "Nieuwe rolaanvraag om te behandelen")



class PloegenTest(Basis):
    """Ploegen: aanmaken, aanvragen, goedkeuren of afkeuren, wie ziet wat (zoals test_ploegen.py in Django)."""

    def setUp(self):
        super().setUp()
        self.bestuur = gebruiker("bestuur@khzs.be")
        self.tr_a = gebruiker("tra@khzs.be", "trainer", first_name="Tom")
        self.tr_b = gebruiker("trb@khzs.be", "trainer")
        self.ouder = gebruiker("ouder@khzs.be", "ouder", first_name="Ann")
        self.papa = gebruiker("papa@khzs.be", "ouder")
        self.andere = gebruiker("andere@khzs.be", "ouder")
        exe(f"INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES ({self.bestuur}, 'khzs_rollen', 'bestuurder')")
        self.lien = zwemmer(self.ouder, "Lien", "P")
        exe(f"INSERT INTO {{p}}zwemmer_accounts (zwemmer_id, user_id, aangemaakt) VALUES ({self.lien}, {self.papa}, NOW())")
        self.bram = zwemmer(self.andere, "Bram", "Q", geslacht="M")
        self.b = Klant().login("bestuur@khzs.be")
        self.b.post("ploegen/", {"naam": "A-groep", "beschrijving": "Wedstrijdzwemmers", "trainers[]": [self.tr_a], "actief": "on"})
        self.b.post("ploegen/", {"naam": "B-groep", "trainers[]": [self.tr_b], "actief": "on"})
        self.pa = int(een("SELECT id FROM {p}ploegen WHERE naam='A-groep'"))
        self.pb = int(een("SELECT id FROM {p}ploegen WHERE naam='B-groep'"))
        self.o = Klant().login("ouder@khzs.be")

    def lid(self, zwemmer=None, ploeg=None):
        return sql(f"SELECT * FROM {{p}}ploegleden WHERE zwemmer_id={zwemmer or self.lien} AND ploeg_id={ploeg or self.pa}")[0]

    def test_aanmaken(self):
        self.assertEqual([int(x["user_id"]) for x in sql(f"SELECT user_id FROM {{p}}ploeg_trainers WHERE ploeg_id={self.pa}")], [self.tr_a])
        Klant().login("tra@khzs.be").post("ploegen/", {"naam": "C", "actief": "on"})
        self.assertFalse(sql("SELECT id FROM {p}ploegen WHERE naam='C'"))

    def test_aanvragen_en_goedkeuren_door_trainer_van_de_ploeg(self):
        for wie, uid in (("tra@khzs.be", self.tr_a), ("trb@khzs.be", self.tr_b), ("papa@khzs.be", self.papa)):
            Klant().login(wie).json("meldingen/abonneer/", {"endpoint": f"https://push.example/{uid}", "keys": {"p256dh": "x", "auth": "y"}})
        self.o.post(f"zwemmers/{self.lien}/ploeg/", {"ploeg": self.pa})
        lid = self.lid()
        self.assertEqual(lid["status"], "open")
        self.assertEqual([x["endpoint"] for x in hulp("push")], [f"https://push.example/{self.tr_a}"])
        Klant().login("trb@khzs.be").post(f"ploegen/lid/{lid['id']}/", {"actie": "goedkeuren"})
        self.assertEqual(self.lid()["status"], "open")
        ta = Klant().login("tra@khzs.be")
        self.assertContains(ta.get("ploegen/"), "Aanvragen (1)")
        ta.post(f"ploegen/lid/{lid['id']}/", {"actie": "goedkeuren"})
        self.assertEqual(self.lid()["status"], "goedgekeurd")
        self.assertIn("Lien zit nu in de ploeg A-groep", hulp("push")[-1]["bericht"]["tekst"])
        p = Klant().login("papa@khzs.be")
        self.assertContains(p.get("meldingen/"), "zit nu in A-groep")
        p.get(f"zwemmers/{self.lien}/")
        self.assertNotContains(p.get("meldingen/"), "zit nu in A-groep")

    def test_afkeuren_vraagt_reden_en_opnieuw_aanvragen(self):
        self.o.post(f"zwemmers/{self.lien}/ploeg/", {"ploeg": self.pa})
        lid = self.lid()
        self.b.post(f"ploegen/lid/{lid['id']}/", {"actie": "afkeuren", "reden": ""})
        self.assertEqual(self.lid()["status"], "open")
        self.b.post(f"ploegen/lid/{lid['id']}/", {"actie": "afkeuren", "reden": "Eerst de B-groep."})
        self.assertEqual(self.lid()["status"], "afgekeurd")
        self.o.post(f"zwemmers/{self.lien}/ploeg/", {"ploeg": self.pa})
        self.assertEqual(self.lid()["status"], "open")

    def test_staf_zet_zwemmer_meteen_in_ploeg(self):
        Klant().login("tra@khzs.be").post(f"ploegen/{self.pa}/", {"actie": "toevoegen", "zwemmer": self.bram})
        self.assertEqual(self.lid(self.bram)["status"], "goedgekeurd")

    def test_wie_ziet_wat(self):
        exe(f"INSERT INTO {{p}}ploegleden (zwemmer_id, ploeg_id, status, aangemaakt) VALUES ({self.lien}, {self.pa}, 'goedgekeurd', NOW())")
        r = self.o.get("ploegen/")
        self.assertContains(r, "A-groep")
        self.assertNotContains(r, "B-groep")
        self.assertEqual(self.o.get(f"ploegen/{self.pb}/").status_code, 403)
        self.assertEqual(self.o.get(f"ploegen/{self.pa}/").status_code, 200)
        for wie in ("trb@khzs.be", "bestuur@khzs.be"):
            r = Klant().login(wie).get("ploegen/")
            self.assertContains(r, "A-groep")
            self.assertContains(r, "B-groep")

    def test_wie_hoort_bij_een_ploeg(self):
        exe(f"INSERT INTO {{p}}ploegleden (zwemmer_id, ploeg_id, status, aangemaakt) VALUES ({self.lien}, {self.pa}, 'goedgekeurd', NOW())")
        exe(f"INSERT INTO {{p}}ploegleden (zwemmer_id, ploeg_id, status, aangemaakt) VALUES ({self.bram}, {self.pa}, 'open', NOW())")
        self.assertEqual(set(hulp("eval", f="ploeg_accounts", args=[self.pa])["v"]), {self.ouder, self.papa, self.tr_a})
        lezers = set(hulp("eval", f="ploeg_lezers", args=[self.pa])["v"])
        self.assertTrue({self.ouder, self.papa, self.tr_a, self.tr_b, self.bestuur} <= lezers)
        self.assertNotIn(self.andere, lezers)

    def test_ouder_trekt_in_en_vreemde_kan_niet(self):
        self.o.post(f"zwemmers/{self.lien}/ploeg/", {"ploeg": self.pa})
        self.o.post(f"ploegen/lid/{self.lid()['id']}/", {"actie": "verwijderen"})
        self.assertFalse(sql("SELECT id FROM {p}ploegleden"))
        self.o.post(f"zwemmers/{self.lien}/ploeg/", {"ploeg": self.pa})
        Klant().login("andere@khzs.be").post(f"ploegen/lid/{self.lid()['id']}/", {"actie": "verwijderen"})
        self.assertTrue(sql("SELECT id FROM {p}ploegleden"))

    def test_kind_met_ploeg_in_het_stappenplan(self):
        k = Klant().login("andere@khzs.be")
        kind(k, "Mo", "Q", "2014-05-05", "M", self.pb)
        self.assertEqual(een("SELECT l.status FROM {p}ploegleden l JOIN {p}zwemmers z ON z.id=l.zwemmer_id WHERE z.voornaam='Mo'"), "open")



class WebshopTest(Basis):
    """Knop naar de webshop (optie khzs_webshop): in het menu op de pc en bij Mijn zwemmers; leeg = geen knop."""

    def test_knop_naar_webshop(self):
        gebruiker("ouder@x.be")
        k = self.c.login("ouder@x.be")
        r = k.get("zwemmers/")
        self.assertEqual(r.text.count('href="https://www.robsport.nl/nl/796-hzs-hasselt" target="_blank"'), 2)
        self.assertContains(r, "Naar de webshop")
        hulp("exec", sql="INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('khzs_webshop', '', 'auto') "
                         "ON DUPLICATE KEY UPDATE option_value=''")
        hulp("cache")
        try:
            self.assertNotContains(k.get("zwemmers/"), "Webshop")
        finally:
            hulp("exec", sql="DELETE FROM wp_options WHERE option_name='khzs_webshop'")
            hulp("cache")


PNG = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==")


class CommunicatieTest(Basis):
    """Module communicatie: secties, kanalen per ploeg en per wedstrijd, threads, vastpinnen, meldingen, aankondigingen en hulpvragen."""

    def setUp(self):
        super().setUp()
        self.bestuur = gebruiker("bestuur@khzs.be", first_name="Bea")
        exe(f"INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES ({self.bestuur}, 'khzs_rollen', 'bestuurder')")
        self.tr_c = gebruiker("trc@khzs.be", "trainer", first_name="Tom")
        self.tr_z = gebruiker("trz@khzs.be", "trainer_zwemschool", first_name="Nina")
        self.ouder = gebruiker("ouder@khzs.be", "ouder", first_name="Ann")
        self.papa = gebruiker("papa@khzs.be", "ouder", first_name="Jan")
        self.andere = gebruiker("andere@khzs.be", "ouder", first_name="Els")
        self.lien = zwemmer(self.ouder, "Lien", "P")
        exe(f"INSERT INTO {{p}}zwemmer_accounts (zwemmer_id, user_id, aangemaakt) VALUES ({self.lien}, {self.papa}, NOW())")
        self.bram = zwemmer(self.andere, "Bram", "Q", geslacht="M")
        self.b = Klant().login("bestuur@khzs.be")
        self.b.post("ploegen/", {"naam": "A-groep", "trainers[]": [self.tr_c], "actief": "on", "soort": "competitie"})
        self.b.post("ploegen/", {"naam": "Dolfijnen", "trainers[]": [self.tr_z], "actief": "on", "soort": "zwemschool"})
        self.pa = int(een("SELECT id FROM {p}ploegen WHERE naam='A-groep'"))
        self.pz = int(een("SELECT id FROM {p}ploegen WHERE naam='Dolfijnen'"))
        exe(f"INSERT INTO {{p}}ploegleden (zwemmer_id, ploeg_id, status, aangemaakt) VALUES ({self.lien}, {self.pa}, 'goedgekeurd', NOW()), "
            f"({self.bram}, {self.pz}, 'goedgekeurd', NOW())")
        self.assertEqual(self.b.get("berichten/").status_code, 200)  # bouwt de secties en de kanalen van de ploegen op
        self.o = Klant().login("ouder@khzs.be")
        self.tc = Klant().login("trc@khzs.be")

    def kanaal(self, naam):
        return int(een(f"SELECT id FROM {{p}}com_kanalen WHERE naam='{naam}'"))

    def schrijf(self, k, kanaal, tekst, **extra):
        return k.post(f"berichten/kanaal/{kanaal}/", {"actie": "plaats", "tekst": tekst, **extra})

    def berichten(self, kanaal):
        return [r["tekst"] for r in sql(f"SELECT tekst FROM {{p}}com_berichten WHERE kanaal_id={kanaal} ORDER BY id")]

    def abonneer(self, *wie):
        for login, uid in wie:
            Klant().login(login).json("meldingen/abonneer/", {"endpoint": f"https://push.example/{uid}", "keys": {"p256dh": "x", "auth": "y"}})
        self.geen_push()

    def geen_push(self):
        hulp("exec", sql="DELETE FROM wp_options WHERE option_name='khzs_test_push'")
        hulp("cache")

    def pushes(self):
        return [(int(p["endpoint"].rsplit("/", 1)[1]), p["bericht"]) for p in hulp("push")]

    def tekst(self, r):
        """Tekst van de inhoud (zonder het menu)."""
        main = re.search(r"<main.*?</main>", r.text, re.S).group(0)
        return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", main))

    def test_opbouw_en_wie_ziet_wat(self):
        self.assertEqual([r["naam"] for r in sql("SELECT naam FROM {p}com_secties ORDER BY volgorde")], ["Algemeen", "Competitie", "Zwemschool"])
        self.assertEqual(een(f"SELECT s.soort FROM {{p}}com_kanalen k JOIN {{p}}com_secties s ON s.id=k.sectie_id WHERE k.ploeg_id={self.pz}"), "zwemschool")
        alle = ["Algemeen", "Trainers en bestuur", "Competitie algemeen", "Wedstrijden", "Zwemschool algemeen", "A-groep", "Dolfijnen"]
        verwacht = {
            "bestuur@khzs.be": alle,
            "trc@khzs.be": alle,
            # Trainer van de zwemschool: niets van de competitie.
            "trz@khzs.be": ["Algemeen", "Trainers en bestuur", "Zwemschool algemeen", "Dolfijnen"],
            "ouder@khzs.be": ["Algemeen", "Competitie algemeen", "Wedstrijden", "A-groep"],
            "papa@khzs.be": ["Algemeen", "Competitie algemeen", "Wedstrijden", "A-groep"],
            "andere@khzs.be": ["Algemeen", "Zwemschool algemeen", "Dolfijnen"],
        }
        for login, zien in verwacht.items():
            k = Klant().login(login)
            t = self.tekst(k.get("berichten/"))
            for naam in alle:
                self.assertEqual(f" {naam} " in t, naam in zien, f"{login} en {naam}")
                self.assertEqual(k.get(f"berichten/kanaal/{self.kanaal(naam)}/").status_code, 200 if naam in zien else 403, f"{login} en {naam}")
        # Het kanaal van een ploeg volgt de ploeg: naam en archief.
        self.b.post(f"ploegen/{self.pa}/", {"actie": "bewaar", "naam": "A-ploeg", "soort": "competitie", "trainers[]": [self.tr_c]})
        self.o.get("berichten/")
        self.assertEqual(een(f"SELECT CONCAT(naam, gearchiveerd) FROM {{p}}com_kanalen WHERE ploeg_id={self.pa}"), "A-ploeg1")
        self.assertIn("Dit kanaal is gearchiveerd", self.o.get(f"berichten/kanaal/{self.kanaal('A-ploeg')}/").text)

    def test_posten_meldingen_en_dempen(self):
        alg, a = self.kanaal("Algemeen"), self.kanaal("A-groep")
        self.abonneer(("trc@khzs.be", self.tr_c), ("papa@khzs.be", self.papa), ("bestuur@khzs.be", self.bestuur),
                      ("trz@khzs.be", self.tr_z), ("ouder@khzs.be", self.ouder))
        # Algemeen: enkel trainers en bestuur posten.
        self.assertIn("In dit kanaal posten enkel trainers en bestuur", self.o.get(f"berichten/kanaal/{alg}/").text)
        self.schrijf(self.o, alg, "Mag ik?")
        self.assertEqual(self.berichten(alg), [])
        self.schrijf(self.b, alg, "Welkom allemaal")
        self.assertEqual(self.berichten(alg), ["Welkom allemaal"])
        self.assertEqual({u for u, _ in self.pushes()}, {self.tr_c, self.papa, self.tr_z, self.ouder})  # iedereen behalve de schrijver
        # Ploegkanaal: de leden en de trainer van de ploeg krijgen een melding, het bestuur (dat alles ziet) niet.
        self.geen_push()
        r = self.schrijf(self.o, a, "Lien is woensdag ziek.")
        self.assertEqual(r.status_code, 302)
        self.assertEqual({u for u, _ in self.pushes()}, {self.tr_c, self.papa})
        p = dict(self.pushes())[self.papa]
        self.assertEqual((p["titel"], p["tekst"], p["tag"]), ("A-groep", "Ann: Lien is woensdag ziek.", f"khzs-kanaal-{a}"))
        self.assertTrue(p["url"].endswith(f"/leden/berichten/kanaal/{a}/#b{een('SELECT MAX(id) FROM {p}com_berichten')}"))
        # Papa dempt het kanaal, het bestuur volgt het.
        Klant().login("papa@khzs.be").post(f"berichten/kanaal/{a}/", {"actie": "meldingen", "aan": "0"})
        self.b.post(f"berichten/kanaal/{a}/", {"actie": "meldingen", "aan": "1"})
        self.geen_push()
        self.schrijf(self.o, a, "Ze is weer beter.")
        self.assertEqual({u for u, _ in self.pushes()}, {self.tr_c, self.bestuur})
        # Vermelding: ook met de voorkeur voor gewone berichten uit, en in een gedempt kanaal.
        self.o.post("meldingen/", {"push_com_vermelding": "on"})  # de rest uit, ook push_com_bericht
        self.o.post(f"berichten/kanaal/{a}/", {"actie": "meldingen", "aan": "0"})
        self.geen_push()
        self.schrijf(self.tc, a, "@Ann beterschap aan Lien!")
        mid = int(een("SELECT MAX(id) FROM {p}com_berichten"))
        self.assertEqual(een(f"SELECT user_id FROM {{p}}com_vermeldingen WHERE bericht_id={mid}"), str(self.ouder))
        push = dict(self.pushes())
        self.assertEqual(push[self.ouder]["titel"], "Tom vermeldde je in A-groep")
        self.assertIn(self.bestuur, push)
        self.assertIn('<span class="com-vermeld">@Ann</span>', self.o.get(f"berichten/kanaal/{a}/").text)
        # Ongelezen: getal bij Berichten (enkel kanalen met meldingen aan); het kanaal openen zet het op nul.
        papa = Klant().login("papa@khzs.be")
        papa.get(f"berichten/kanaal/{alg}/")
        self.assertNotIn('class="tab-badge"', papa.get("uitleg/").text)  # A-groep is gedempt
        self.schrijf(self.b, alg, "Nog een bericht")
        self.assertIn('<span class="tab-badge">1</span>', papa.get("uitleg/").text)
        papa.get(f"berichten/kanaal/{alg}/")
        self.assertNotIn('class="tab-badge"', papa.get("uitleg/").text)

    def test_versturen_zonder_herladen_en_vernieuwen(self):
        a = self.kanaal("A-groep")
        r = self.o.s.post(APP + f"berichten/kanaal/{a}/", data={"_khzs": self.o._nonce(), "actie": "plaats", "tekst": "Via fetch", "na": 0},
                          headers={"X-KHZS-Ajax": "1"})
        j = r.json()
        self.assertTrue(j["ok"])
        self.assertIn("Via fetch", j["html"])
        self.assertIn("com-eigen", j["html"])
        r = self.o.s.post(APP + f"berichten/kanaal/{self.kanaal('Algemeen')}/", data={"_khzs": self.o._nonce(), "actie": "plaats", "tekst": "x"},
                          headers={"X-KHZS-Ajax": "1"})
        self.assertEqual(r.status_code, 400)
        self.assertIn("enkel trainers en bestuur", r.json()["fout"])
        self.schrijf(self.tc, a, "Antwoord van de trainer")
        j = self.o.get(f"berichten/kanaal/{a}/nieuw/", na=j["laatste"]).json()
        self.assertIn("Antwoord van de trainer", j["html"])
        self.assertIn("Trainer competitie", j["html"])  # rol naast de naam
        self.assertNotIn("Via fetch", j["html"])
        self.assertEqual(self.o.get(f"berichten/kanaal/{a}/nieuw/", na=j["laatste"]).json()["html"], "")
        self.assertEqual(Klant().login("andere@khzs.be").get(f"berichten/kanaal/{a}/nieuw/", na=0).status_code, 403)

    def test_bewerken_verwijderen_vastpinnen_en_bijlagen(self):
        a = self.kanaal("A-groep")
        self.schrijf(self.o, a, "Eerst")
        mid = int(een("SELECT MAX(id) FROM {p}com_berichten"))
        papa = Klant().login("papa@khzs.be")
        self.assertEqual(papa.post(f"berichten/bericht/{mid}/", {"actie": "bewerken", "tekst": "Gehackt"}).status_code, 403)
        self.assertEqual(self.o.post(f"berichten/bericht/{mid}/", {"actie": "vastpinnen"}).status_code, 403)
        self.o.post(f"berichten/bericht/{mid}/", {"actie": "bewerken", "tekst": "Eerst (aangepast)"})
        self.assertEqual(self.berichten(a), ["Eerst (aangepast)"])
        self.assertIn("· bewerkt", papa.get(f"berichten/kanaal/{a}/").text)
        self.tc.post(f"berichten/bericht/{mid}/", {"actie": "vastpinnen"})
        self.assertIn("📌 Vastgepind (1)", papa.get(f"berichten/kanaal/{a}/").text)
        self.tc.post(f"berichten/bericht/{mid}/", {"actie": "verwijderen"})  # moderatie door de trainer van de ploeg
        r = papa.get(f"berichten/kanaal/{a}/")
        self.assertIn("Bericht verwijderd", r.text)
        self.assertNotIn("Vastgepind", r.text)
        self.assertEqual(self.berichten(a), [""])
        # Bijlagen: enkel trainers en bestuur, enkel foto's en pdf's, enkel te zien voor wie het kanaal ziet.
        self.o.post(f"berichten/kanaal/{a}/", {"actie": "plaats", "tekst": "foto"}, files={"bijlage": ("foto.png", PNG, "image/png")})
        self.assertEqual(self.berichten(a), [""])
        self.tc.post(f"berichten/kanaal/{a}/", {"actie": "plaats", "tekst": "Uitslagen"}, files={"bijlage": ("uitslag.pdf", b"%PDF-1.4 test", "application/pdf")})
        self.tc.post(f"berichten/kanaal/{a}/", {"actie": "plaats", "tekst": ""}, files={"bijlage": ("raar.exe", b"MZ....", "application/octet-stream")})
        self.tc.post(f"berichten/kanaal/{a}/", {"actie": "plaats", "tekst": ""}, files={"bijlage": ("groep.png", PNG, "image/png")})
        rijen = sql(f"SELECT id, bijlage_naam, bijlage_type FROM {{p}}com_berichten WHERE kanaal_id={a} AND bijlage<>'' ORDER BY id")
        self.assertEqual([(r["bijlage_naam"], r["bijlage_type"]) for r in rijen], [("uitslag.pdf", "pdf"), ("groep.png", "png")])
        r = papa.get(f"berichten/bijlage/{rijen[1]['id']}/")
        self.assertEqual((r.status_code, r.headers["Content-Type"], r.content), (200, "image/png", PNG))
        self.assertIn(f'<img class="com-foto" loading="lazy" src="{APP}berichten/bijlage/{rijen[1]["id"]}/"', papa.get(f"berichten/kanaal/{a}/").text)
        self.assertEqual(Klant().login("andere@khzs.be").get(f"berichten/bijlage/{rijen[0]['id']}/").status_code, 403)
        self.assertEqual(Klant().get(f"berichten/bijlage/{rijen[0]['id']}/").status_code, 302)  # niet aangemeld

    def test_threads_en_beheer(self):
        a, z = self.kanaal("A-groep"), self.kanaal("Dolfijnen")
        # De trainer van de ploeg beheert het eigen kanaal; hier lezen de leden enkel.
        self.assertEqual(self.tc.get(f"berichten/kanaal/{a}/instellingen/").status_code, 200)
        self.assertEqual(self.tc.get(f"berichten/kanaal/{z}/instellingen/").status_code, 403)
        self.assertEqual(self.o.get(f"berichten/kanaal/{a}/instellingen/").status_code, 403)
        self.tc.post(f"berichten/kanaal/{a}/instellingen/", {"beschrijving": "Voor de A-groep"})
        self.assertEqual(een(f"SELECT CONCAT(leden_posten, beschrijving) FROM {{p}}com_kanalen WHERE id={a}"), "0Voor de A-groep")
        self.schrijf(self.o, a, "Kan niet")
        self.assertEqual(self.berichten(a), [])
        # Een automatisch kanaal kan je niet verwijderen.
        self.b.post(f"berichten/kanaal/{a}/instellingen/", {"actie": "verwijderen"})
        self.assertTrue(sql(f"SELECT id FROM {{p}}com_kanalen WHERE id={a}"))
        # Thread in het ploegkanaal: dezelfde mensen zien hem.
        self.tc.post(f"berichten/kanaal/{a}/", {"actie": "thread", "naam": "Stage Pasen", "tekst": "Info volgt"})
        t = self.kanaal("Stage Pasen")
        self.assertEqual(een(f"SELECT CONCAT(ouder_id, '/', toegang, '/', leden_posten) FROM {{p}}com_kanalen WHERE id={t}"), f"{a}/erven/0")
        self.assertEqual(self.berichten(t), ["Info volgt"])
        self.assertIn("Stage Pasen", self.tekst(self.o.get("berichten/")))
        self.assertEqual(Klant().login("andere@khzs.be").get(f"berichten/kanaal/{t}/").status_code, 403)
        self.assertEqual(self.o.post(f"berichten/kanaal/{a}/", {"actie": "thread", "naam": "Van een ouder"}).status_code, 403)
        # Beheer: secties en kanalen, enkel voor het bestuur.
        self.assertEqual(self.tc.get("berichten/beheer/").status_code, 403)
        self.b.post("berichten/beheer/", {"actie": "sectie", "naam": "Vrijwilligers", "soort": "algemeen"})
        s = int(een("SELECT id FROM {p}com_secties WHERE naam='Vrijwilligers'"))
        self.b.post("berichten/beheer/", {"actie": "kanaal", "sectie_id": s, "naam": "Ouders Dolfijnen", "toegang": "ploeg", "ploeg_id": self.pz, "leden_posten": "on"})
        k = self.kanaal("Ouders Dolfijnen")
        self.assertEqual(Klant().login("andere@khzs.be").get(f"berichten/kanaal/{k}/").status_code, 200)
        self.assertEqual(self.o.get(f"berichten/kanaal/{k}/").status_code, 403)
        self.b.post("berichten/beheer/", {"actie": "sectie_verwijder", "id": s})
        self.assertTrue(sql(f"SELECT id FROM {{p}}com_secties WHERE id={s}"))  # niet leeg
        self.schrijf(self.b, k, "Even testen")
        self.b.post(f"berichten/kanaal/{k}/instellingen/", {"actie": "verwijderen"})
        self.assertFalse(sql(f"SELECT id FROM {{p}}com_kanalen WHERE id={k}"))
        self.assertFalse(sql(f"SELECT id FROM {{p}}com_berichten WHERE kanaal_id={k}"))
        self.b.post("berichten/beheer/", {"actie": "sectie_verwijder", "id": s})
        self.assertFalse(sql(f"SELECT id FROM {{p}}com_secties WHERE id={s}"))

    def test_wedstrijdthread_volgt_de_deelname(self):
        w = exe("INSERT INTO {p}wedstrijden (naam, baan, deadline, status, info, aangemaakt) VALUES ('Testmeeting', 'SCM', '2026-12-01', 'concept', '', NOW())")["id"]
        exe(f"INSERT INTO {{p}}sessies (wedstrijd_id, nummer, datum) VALUES ({w}, 1, '2026-12-05')")
        self.b.get("berichten/")
        self.assertFalse(sql("SELECT id FROM {p}com_kanalen WHERE naam='Testmeeting'"))  # concept: nog geen thread
        exe(f"UPDATE {{p}}wedstrijden SET status='open' WHERE id={w}")
        exe(f"INSERT INTO {{p}}deelnames (zwemmer_id, wedstrijd_id, keuze, gewijzigd) VALUES ({self.lien}, {w}, 'interesse', NOW())")
        self.b.get("berichten/")
        t = self.kanaal("Testmeeting")
        self.assertEqual(een(f"SELECT CONCAT(toegang, '/', ouder_id) FROM {{p}}com_kanalen WHERE id={t}"), f"wedstrijd/{self.kanaal('Wedstrijden')}")
        self.assertIn("zaterdag 5 december 2026", een(f"SELECT beschrijving FROM {{p}}com_kanalen WHERE id={t}"))
        for login, ziet in (("ouder@khzs.be", 200), ("papa@khzs.be", 200), ("trc@khzs.be", 200), ("bestuur@khzs.be", 200),
                            ("andere@khzs.be", 403), ("trz@khzs.be", 403)):
            self.assertEqual(Klant().login(login).get(f"berichten/kanaal/{t}/").status_code, ziet, login)
        # Bram doet mee: hij komt erbij. Wie 'Ik doe niet mee' kiest of geweigerd wordt, blijft erin.
        exe(f"INSERT INTO {{p}}deelnames (zwemmer_id, wedstrijd_id, keuze, gewijzigd) VALUES ({self.bram}, {w}, 'doet_mee', NOW())")
        exe(f"UPDATE {{p}}deelnames SET keuze='doet_niet_mee', geweigerd=1 WHERE zwemmer_id={self.lien}")
        self.assertEqual(Klant().login("andere@khzs.be").get(f"berichten/kanaal/{t}/").status_code, 200)
        self.assertEqual(self.o.get(f"berichten/kanaal/{t}/").status_code, 200)
        # Meldingen: deelnemers en trainers van de competitie, niet het bestuur.
        self.abonneer(("trc@khzs.be", self.tr_c), ("papa@khzs.be", self.papa), ("bestuur@khzs.be", self.bestuur), ("andere@khzs.be", self.andere))
        self.schrijf(self.o, t, "Wie rijdt er mee?")
        self.assertEqual({u for u, _ in self.pushes()}, {self.tr_c, self.papa, self.andere})
        # Knop op de pagina van de wedstrijd.
        self.assertIn(f'berichten/kanaal/{t}/">💬 Wedstrijdchat', self.o.get(f"wedstrijd/{w}/").text)
        # Een jaar na de wedstrijd: archief, enkel nog lezen.
        exe(f"UPDATE {{p}}sessies SET datum=CURDATE() - INTERVAL 400 DAY WHERE wedstrijd_id={w}")
        self.assertEqual(hulp("eval", f="avg_opkuis")["v"]["wedstrijdthreads_gearchiveerd"], 1)
        self.assertIn("Dit kanaal is gearchiveerd", self.o.get(f"berichten/kanaal/{t}/").text)
        self.schrijf(self.o, t, "Te laat")
        self.assertEqual(self.berichten(t), ["Wie rijdt er mee?"])

    def test_aankondigingen_en_hulpvraag(self):
        self.abonneer(("ouder@khzs.be", self.ouder), ("trz@khzs.be", self.tr_z), ("andere@khzs.be", self.andere), ("bestuur@khzs.be", self.bestuur))
        self.assertEqual(self.o.post("berichten/aankondigingen/", {"soort": "info", "titel": "x", "kanalen[]": 0}).status_code, 403)
        r = self.b.post("berichten/aankondigingen/", {"soort": "hulp", "titel": "Helpers pastadag", "tekst": "Wie helpt mee?", "kanalen[]": 0,
                                                       "nodig": 5, "wanneer": "zaterdag 6 december"})
        self.assertEqual(r.status_code, 302, r.text[:1000])
        aid = int(een("SELECT id FROM {p}com_aankondigingen"))
        self.assertEqual({u for u, _ in self.pushes()}, {self.ouder, self.tr_z, self.andere})
        self.assertEqual(self.pushes()[0][1]["titel"], "🙋 Vraag naar hulp: Helpers pastadag")
        # Bovenaan Berichten, met de knoppen; een nieuwe aankondiging geeft een getal in het menu tot je Berichten opent.
        self.assertIn('<span class="tab-badge">1</span>', self.o.get("uitleg/").text)
        r = self.o.get("berichten/")
        self.assertIn("Helpers pastadag", r.text)
        self.assertIn('name="antwoord" value="ja"', r.text)
        self.assertNotIn('class="tab-badge"', self.o.get("uitleg/").text)
        self.o.post(f"berichten/aankondiging/{aid}/", {"actie": "rsvp", "antwoord": "ja", "personen": 2, "opmerking": "Keuken"})
        self.o.post(f"berichten/aankondiging/{aid}/", {"actie": "rsvp", "antwoord": "ja", "personen": 3, "opmerking": "Keuken"})
        Klant().login("papa@khzs.be").post(f"berichten/aankondiging/{aid}/", {"actie": "rsvp", "antwoord": "misschien"})
        Klant().login("andere@khzs.be").post(f"berichten/aankondiging/{aid}/", {"actie": "rsvp", "antwoord": "nee", "personen": 4})
        rijen = sql(f"SELECT user_id, antwoord, personen FROM {{p}}com_rsvp WHERE aankondiging_id={aid} ORDER BY user_id")
        self.assertEqual([(int(r["user_id"]), r["antwoord"], int(r["personen"])) for r in rijen],
                         [(self.ouder, "ja", 3), (self.papa, "misschien", 1), (self.andere, "nee", 0)])
        # Leden zien de totalen, niet de lijst; het bestuur ziet de lijst en downloadt ze als Excel.
        r = self.o.get(f"berichten/aankondiging/{aid}/")
        self.assertIn("3 van de 5 nodige personen komen", self.tekst(r))
        self.assertNotIn("Wie komt helpen?", r.text)
        self.assertEqual(self.o.get(f"berichten/aankondiging/{aid}/export.xlsx").status_code, 403)
        r = self.b.get(f"berichten/aankondiging/{aid}/")
        self.assertIn("Wie komt helpen?", r.text)
        self.assertIn("Keuken", r.text)
        x = self.b.get(f"berichten/aankondiging/{aid}/export.xlsx")
        self.assertEqual(x.status_code, 200)
        blad = zipfile.ZipFile(io.BytesIO(x.content)).read("xl/worksheets/sheet1.xml").decode()
        for stuk in ("Ann", "ouder@khzs.be", "Misschien", "Totaal Ja", "<v>3</v>"):
            self.assertIn(stuk, blad)
        # Een trainer maakt een aankondiging voor zijn eigen ploeg, niet voor iedereen.
        self.tc.post("berichten/aankondigingen/", {"soort": "dringend", "titel": "Voor iedereen", "kanalen[]": 0})
        self.tc.post("berichten/aankondigingen/", {"soort": "dringend", "titel": "Training valt weg", "kanalen[]": self.kanaal("A-groep")})
        self.assertEqual([r["titel"] for r in sql("SELECT titel FROM {p}com_aankondigingen ORDER BY id")], ["Helpers pastadag", "Training valt weg"])
        self.assertIn("Training valt weg", self.o.get(f"berichten/kanaal/{self.kanaal('A-groep')}/").text)
        self.assertNotIn("Training valt weg", Klant().login("andere@khzs.be").get("berichten/").text)
        # Beëindigen: niet meer bovenaan, wel nog bij Aankondigingen.
        self.b.post(f"berichten/aankondiging/{aid}/", {"actie": "beeindigen"})
        self.assertNotIn("Helpers pastadag", self.o.get("berichten/").text)
        self.assertIn("Helpers pastadag", self.o.get("berichten/aankondigingen/").text)
        self.assertEqual(self.o.post(f"berichten/aankondiging/{aid}/", {"actie": "verwijderen"}).status_code, 403)
        self.b.post(f"berichten/aankondiging/{aid}/", {"actie": "verwijderen"})
        self.assertFalse(sql("SELECT id FROM {p}com_rsvp"))

    def test_aankondiging_voor_meerdere_kanalen(self):
        a, d = self.kanaal("A-groep"), self.kanaal("Dolfijnen")
        andere = Klant().login("andere@khzs.be")
        # Het formulier toont vinkjes: Iedereen en elk kanaal.
        r = self.b.get("berichten/aankondigingen/")
        self.assertIn('name="kanalen[]" value="0" checked', r.text)
        self.assertIn(f'name="kanalen[]" value="{d}"', r.text)
        r = self.b.post("berichten/aankondigingen/", {"soort": "info", "titel": "Zwembad dicht", "kanalen[]": [a, d]})
        self.assertEqual(r.status_code, 302, r.text[:1000])
        aid = int(een("SELECT id FROM {p}com_aankondigingen WHERE titel='Zwembad dicht'"))
        self.assertEqual(een(f"SELECT kanalen FROM {{p}}com_aankondigingen WHERE id={aid}"), f"{min(a, d)},{max(a, d)}")
        # Wie minstens één van de kanalen ziet, ziet de aankondiging, ook in elk van die kanalen.
        self.assertIn("Zwembad dicht", self.o.get("berichten/").text)
        self.assertIn("Zwembad dicht", andere.get("berichten/").text)
        self.assertIn("Zwembad dicht", self.o.get(f"berichten/kanaal/{a}/").text)
        self.assertIn("Zwembad dicht", andere.get(f"berichten/kanaal/{d}/").text)
        self.assertIn("A-groep, Dolfijnen", self.tekst(self.b.get(f"berichten/aankondiging/{aid}/")))
        # Enkel Dolfijnen: de ouder van de A-groep ziet ze niet meer.
        self.b.post(f"berichten/aankondiging/{aid}/", {"actie": "bewaar", "soort": "info", "titel": "Zwembad dicht", "kanalen[]": [d]})
        self.assertNotIn("Zwembad dicht", self.o.get("berichten/").text)
        self.assertEqual(self.o.get(f"berichten/aankondiging/{aid}/").status_code, 403)
        self.assertIn("Zwembad dicht", andere.get("berichten/").text)
        # Iedereen samen met een kanaal: voor iedereen.
        self.b.post(f"berichten/aankondiging/{aid}/", {"actie": "bewaar", "soort": "info", "titel": "Zwembad dicht", "kanalen[]": [0, d]})
        self.assertEqual(een(f"SELECT kanalen FROM {{p}}com_aankondigingen WHERE id={aid}"), "")
        self.assertIn("Zwembad dicht", self.o.get("berichten/").text)
        # Een trainer kiest enkel uit de eigen kanalen, en minstens één.
        r = self.tc.post("berichten/aankondigingen/", {"soort": "info", "titel": "Ook Dolfijnen", "kanalen[]": [a, d]})
        self.assertIn("Kies voor wie de aankondiging is.", r.text)
        r = self.tc.post("berichten/aankondigingen/", {"soort": "info", "titel": "Niemand"})
        self.assertIn("Kies voor wie de aankondiging is.", r.text)
        self.assertFalse(sql("SELECT id FROM {p}com_aankondigingen WHERE titel IN ('Ook Dolfijnen', 'Niemand')"))
        # Overzetting van versie 1: één kanaal in kanaal_id wordt de lijst kanalen.
        exe(f"INSERT INTO {{p}}com_aankondigingen (soort, titel, tekst, kanaal_id, aangemaakt) VALUES ('info', 'Oud', '', {a}, NOW())")
        exe("UPDATE wp_options SET option_value='1' WHERE option_name='khzs_com_db_versie'")
        hulp("cache")
        self.o.get("berichten/")
        self.assertEqual(sql("SELECT kanaal_id, kanalen FROM {p}com_aankondigingen WHERE titel='Oud'"), [{"kanaal_id": "0", "kanalen": str(a)}])
        self.assertIn("Oud", self.o.get(f"berichten/kanaal/{a}/").text)
        self.assertNotIn("Oud", andere.get("berichten/").text)

    def test_voorkeuren_en_avg(self):
        r = self.o.get("meldingen/")
        for label in ("Nieuw bericht in een kanaal", "Iemand vermeldt mij", "Nieuwe aankondiging", "Nieuwe vraag naar hulp"):
            self.assertIn(label, r.text)
        a = self.kanaal("A-groep")
        self.schrijf(self.o, a, "Mijn bericht")
        self.assertEqual(self.o.get("privacy/mijn-gegevens.json").json()["communicatie"]["berichten"][0]["tekst"], "Mijn bericht")
        hulp("deluser", id=self.ouder)
        self.assertEqual(self.berichten(a), [])
        # Een nieuw lid begint zonder ongelezen berichten van vroeger.
        self.schrijf(self.b, self.kanaal("Algemeen"), "Oud nieuws")
        time.sleep(1.1)
        gebruiker("nieuw@khzs.be", "ouder")
        self.assertNotIn('class="tab-badge"', Klant().login("nieuw@khzs.be").get("uitleg/").text)


class TabbalkTest(Basis):
    """Onderbalk op de gsm: hoogstens 5 knoppen; bij veel menu-items de rest en Afmelden onder 'Meer'."""

    def balk(self, html):
        balk = re.search(r'<nav class="tabbalk">(.*?)</nav>', html, re.S).group(1)
        return re.findall(r"</svg>([^<]+)</(?:a|button)>", balk)

    def test_ouder_berichten_en_meer(self):
        gebruiker("ouder@x.be")
        html = self.c.login("ouder@x.be").get("wedstrijden/").text
        self.assertEqual(self.balk(html), ["Wedstrijden", "Berichten", "Profiel", "Uitleg", "Meer"])
        blad = re.search(r'<div class="meer-blad".*?</div>', html, re.S).group(0)
        self.assertEqual(re.findall(r"</svg>([^<]+)</a>", blad), ["Meldingen", "Afmelden"])

    def test_veel_items_onder_meer_met_backup(self):
        html = self.c.login("admin", "admin123").get("wedstrijden/").text
        self.assertEqual(self.balk(html), ["Wedstrijden", "Berichten", "Zwemmers", "Trainingen", "Meer"])
        blad = re.search(r'<div class="meer-blad".*?</div>', html, re.S).group(0)
        meer = re.findall(r"</svg>([^<]+)</a>", blad)
        self.assertEqual(meer, ["Verloning", "Ploegen", "Gebruikers", "Uitleg", "Meldingen", "Backup", "Afmelden"])
        # Actieve pagina onder 'Meer': de knop Meer licht op.
        self.assertIn('class="actief" id="meer-knop"', self.c.get("gebruikers/").text)


DEMO = BASIS + "/leden-demo/"
DEMO_DAG = "2026-11-14"


class DemoKlant(Klant):
    """Klant voor de demo op /leden-demo/ (zelfde werking, ander adres)."""

    def __init__(self):
        super().__init__()
        self.vandaag = DEMO_DAG

    def login(self, login="demo", pw="demo"):
        r = self.s.post(DEMO + "aanmelden/", data={"username": login, "password": pw}, allow_redirects=False, headers=self._h())
        assert r.status_code == 302, r.text[:500]
        self.nonce = None
        return self

    def get(self, pad, **params):
        return self.s.get(DEMO + pad, params=params, allow_redirects=False, headers=self._h())

    def _nonce(self):
        if not self.nonce:
            m = re.search(r'name="_khzs" value="([^"]+)"', self.s.get(DEMO + "uitleg/", headers=self._h()).text)
            self.nonce = m.group(1) if m else ""
        return self.nonce

    def post(self, pad, data=None, files=None, query=None):
        data = dict(data or {})
        data.setdefault("_khzs", self._nonce())
        return self.s.post(DEMO + pad, data=data, files=files, params=query, allow_redirects=False, headers=self._h())


def demo_terugzetten():
    """Zoals de nachtelijke taak: buiten de demo (gewoon verzoek), op een vaste datum."""
    r = requests.post(f"{BASIS}/?khzs_test=eval", json={"f": "demo_terugzetten"}, headers={"X-Vandaag": DEMO_DAG}, timeout=120)
    r.raise_for_status()
    assert "fout" not in r.json(), r.json()


def demo_aantal(tabel):
    return int(een(f"SELECT COUNT(*) FROM wp_khzsdemo_{tabel}"))


class DemoTest(Basis):
    """Demo op /leden-demo/: alle schermen met demodata, geen PWA, volledig los van de echte app, terugzetten."""

    @classmethod
    def setUpClass(cls):
        if requests.get(DEMO + "aanmelden/").status_code != 200:
            raise unittest.SkipTest("Demo staat uit: zet define('KHZS_DEMO', true) in wp-config.php (testsite.sh doet dat).")
        demo_terugzetten()

    def setUp(self):
        super().setUp()  # wist enkel de echte gegevens
        self.d = DemoKlant().login()

    def tekst(self, r):
        return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", r.text))

    def test_aanmelden_en_alle_schermen(self):
        self.assertEqual([(c.name, c.path) for c in self.d.s.cookies], [("khzs_demo_sessie", "/leden-demo/")])
        r = self.d.get("wedstrijden/")
        self.assertEqual(r.status_code, 200)
        for status in ("Concept", "Open", "Gesloten", "Doorgestuurd"):
            self.assertContains(r, status)
        self.assertContains(r, "Clubmeeting ZCT")
        self.assertContains(r, "<b>Demo</b>: alle gegevens zijn verzonnen")
        self.assertContains(r, "Demo terugzetten")
        # Alle rollen: menu van beheerder, trainer en penningmeester; eigen zwemmers als ouder en als zwemmer.
        menu = r.text.replace("\n", "")
        for item in ("Wedstrijden", "Zwemmers", "Trainingen", "Verloning", "Gebruikers", "Backup"):
            self.assertIn(f">{item}</a>", menu)
        zw = self.d.get("zwemmers/")
        for naam in ("Demo Gebruiker", "Lina Gebruiker", "Mats Gebruiker", "Mila Vermeulen"):
            self.assertContains(zw, naam)
        self.assertContains(zw, "demo@demo.invalid, sofie@demo.invalid")  # gedeeld met de andere ouder
        tr = self.tekst(self.d.get("trainers/"))
        self.assertIn("Competitie 3", tr)
        self.assertIn("vervanger gezocht voor Lotte", tr)
        beheer = self.tekst(self.d.get("trainers/beheer/"))
        for ploeg in ("Kern", "Beloften", "Competitie 1 nationaal", "Zwemschool Startersgroep", "Zwemschool Pre-competitie 2"):
            self.assertIn(ploeg, beheer)
        oktober = self.tekst(self.d.get("trainers/uren/", maand="2026-10"))
        self.assertIn("gewijzigd na uitbetaling", oktober)
        self.assertIn("nog niet uitbetaald", oktober)
        self.assertContains(self.d.get("trainers/tarieven/"), "Nieuw tarief na diploma Trainer B")
        self.assertContains(self.d.get("gebruikers/"), "katrien@demo.invalid")
        self.assertEqual(self.d.get("zwemmers/uitslagen/").status_code, 200)
        self.assertContains(self.d.get("meldingen/"), "In de demo staan pushmeldingen uit")
        self.assertEqual(self.d.get("backup/").status_code, 200)
        # Een gesloten wedstrijd om na te kijken, een open wedstrijd met bijlage.
        wid = int(een("SELECT id FROM wp_khzsdemo_wedstrijden WHERE status='gesloten'"))
        self.assertEqual(self.d.get(f"wedstrijd/{wid}/nakijken/").status_code, 200)
        wid = int(een("SELECT id FROM wp_khzsdemo_wedstrijden WHERE naam='Clubmeeting ZCT'"))
        self.assertContains(self.d.get(f"wedstrijd/{wid}/"), "Busregeling")
        # Andere demo-accounts (ook wachtwoord demo): een trainer ziet geen verloning en kan de demo niet terugzetten.
        t = DemoKlant().login("lotte")
        self.assertNotIn(">Verloning</a>", t.get("trainers/").text)
        self.assertEqual(t.post("demo/terugzetten/").status_code, 403)

    def test_accounts_met_een_rol(self):
        # trainer/trainer, zwemmer/zwemmer, ouder/ouder, bestuur/bestuur: elk één rol, wachtwoord = gebruikersnaam.
        verwacht = {
            "trainer": (["Wedstrijden", "Zwemmers", "Trainingen"], ["Verloning", "Gebruikers", "Backup"], "Competitie 2"),
            "zwemmer": (["Wedstrijden", "Profiel"], ["Trainingen", "Verloning", "Gebruikers"], "Lore Daems"),
            "ouder": (["Wedstrijden", "Profiel"], ["Trainingen", "Verloning", "Gebruikers"], "Finn Bosmans"),
            "bestuur": (["Wedstrijden", "Profiel", "Verloning", "Trainingen", "Ploegen", "Gebruikers"], ["Backup"], None),
        }
        for login, (wel, niet, inhoud) in verwacht.items():
            with self.subTest(login):
                self.assertNotEqual(requests.post(DEMO + "aanmelden/", data={"username": login, "password": "demo"},
                                                  allow_redirects=False).status_code, 302)  # niet met 'demo'
                k = DemoKlant().login(login, login)
                menu = re.search(r"<nav>(.*?)</nav>", k.get("wedstrijden/").text, re.S).group(1)
                for item in wel:
                    self.assertIn(f">{item}</a>", menu)
                for item in niet:
                    self.assertNotIn(f">{item}</a>", menu)
                if inhoud:
                    self.assertIn(inhoud, k.get("trainers/" if login == "trainer" else "zwemmers/").text)
        self.assertIn("Lies Bosmans", DemoKlant().login("ouder", "ouder").get("zwemmers/").text)
        self.assertEqual(een("SELECT m.meta_value FROM wp_khzsdemo_usermeta m JOIN wp_khzsdemo_users u ON u.ID=m.user_id "
                             "WHERE u.user_login='bestuur' AND m.meta_key='khzs_rollen'"), "bestuurder")

    def test_app_als_demo_zonder_push_of_registratie(self):
        # Installeerbaar, maar duidelijk de demo: eigen naam, iconen met DEMO, scope /leden-demo/.
        r = self.d.get("wedstrijden/")
        self.assertContains(r, "<title>Wedstrijden · KHZS Leden Demo</title>")
        self.assertContains(r, 'rel="manifest"')
        self.assertContains(r, "/assets/img/demo/apple-touch-icon.png")
        m = self.d.get("manifest.webmanifest").json()
        self.assertEqual((m["name"], m["short_name"]), ("KHZS Leden Demo", "KHZS Leden Demo"))
        self.assertTrue(m["scope"].endswith("/leden-demo/"))
        self.assertTrue(all("/img/demo/" in i["src"] for i in m["icons"]))
        self.assertEqual(requests.head(m["icons"][0]["src"]).status_code, 200)
        sw = self.d.get("sw.js")
        self.assertEqual(sw.status_code, 200)
        self.assertEqual(sw.headers["Service-Worker-Allowed"], "/leden-demo/")
        self.assertContains(self.d.get("installeer/"), "KHZS Leden Demo")
        # De echte app heet gewoon KHZS Leden.
        self.assertEqual(requests.get(APP + "manifest.webmanifest").json()["name"], "KHZS Leden")
        self.assertEqual(self.d.get("registreren/").status_code, 404)
        self.assertEqual(self.d.s.post(DEMO + "meldingen/abonneer/", json={}).status_code, 404)
        anoniem = requests.get(DEMO + "aanmelden/")
        self.assertNotIn("Wachtwoord vergeten", anoniem.text)
        self.assertNotIn("Maak een profiel aan", anoniem.text)
        # Een backup terugzetten kan niet in de demo.
        r = self.d.post("backup/", {"actie": "herstel", "bevestig": "HERSTEL", "naam": "x.zip"})
        self.assertEqual(r.status_code, 302)
        self.assertIn("kan niet in de demo", self.tekst(self.d.get("backup/")))

    def test_los_van_de_echte_app(self):
        echt = gebruiker("ouder@x.be", first_name="Echte")
        zwemmer(echt, "Echt", "Kind")
        # Het demo-account bestaat niet in de echte app en niet in wp-admin.
        r = requests.post(APP + "aanmelden/", data={"username": "demo", "password": "demo"}, allow_redirects=False)
        self.assertEqual(r.status_code, 200)
        r = requests.post(BASIS + "/wp-login.php", data={"log": "demo", "pwd": "demo"},
                          cookies={"wordpress_test_cookie": "WP Cookie check"}, allow_redirects=False)
        self.assertFalse([c for c in r.cookies if c.name.startswith("wordpress_logged_in")])
        # Met het demo-cookie ben je in de echte app niet aangemeld.
        r = self.d.s.get(APP + "wedstrijden/", allow_redirects=False)
        self.assertEqual(r.status_code, 302)
        self.assertIn("/leden/aanmelden/", r.headers["Location"])
        # Echte accounts bestaan niet in de demo, en wie in de echte app is aangemeld, is dat niet in de demo.
        r = requests.post(DEMO + "aanmelden/", data={"username": "ouder@x.be", "password": "geheim123!"}, allow_redirects=False)
        self.assertEqual(r.status_code, 200)
        k = Klant().login("admin", "admin123")
        r = k.s.get(DEMO + "wedstrijden/", allow_redirects=False)
        self.assertIn("/leden-demo/aanmelden/", r.headers["Location"])
        # In de demo aan- en afmelden laat de echte sessie ongemoeid.
        k.s.post(DEMO + "aanmelden/", data={"username": "demo", "password": "demo"})
        self.assertContains(k.s.get(DEMO + "zwemmers/"), "Lina Gebruiker")
        self.assertEqual(k.get("wedstrijden/").status_code, 200)
        n = re.search(r'name="_khzs" value="([^"]+)"', k.s.get(DEMO + "uitleg/").text).group(1)
        k.s.post(DEMO + "afmelden/", data={"_khzs": n})
        self.assertEqual(k.s.get(DEMO + "zwemmers/", allow_redirects=False).status_code, 302)
        self.assertEqual(k.get("wedstrijden/").status_code, 200)
        # Lijsten: geen demo-accounts in de echte app, geen echte accounts of zwemmers in de demo.
        self.assertNotContains(k.get("gebruikers/"), "demo.invalid")
        self.assertNotContains(k.get("zwemmers/"), "Lina Gebruiker")
        self.assertNotContains(self.d.get("gebruikers/"), "ouder@x.be")
        self.assertNotContains(self.d.get("zwemmers/"), "Echt Kind")
        self.assertEqual(int(een("SELECT COUNT(*) FROM wp_users WHERE user_email LIKE '%demo.invalid'")), 0)
        self.assertEqual(int(een("SELECT COUNT(*) FROM {p}zwemmers")), 1)
        self.assertEqual(int(een("SELECT MIN(ID) FROM wp_khzsdemo_users")), 900000001)

    def test_terugzetten(self):
        echt = gebruiker("ouder@x.be")
        zwemmer(echt, "Echt", "Kind")
        n = demo_aantal("zwemmers")
        self.d.post("zwemmers/nieuw/", {"voornaam": "Nieuw", "naam": "Kind", "geboortedatum": "2015-01-01", "geslacht": "F", "toestemming": "1"})
        self.assertEqual(demo_aantal("zwemmers"), n + 1)
        r = self.d.post("demo/terugzetten/")
        self.assertEqual(r.status_code, 302)
        self.assertTrue(r.headers["Location"].endswith("/leden-demo/aanmelden/"))
        self.assertEqual(demo_aantal("zwemmers"), n)
        self.assertEqual(self.d.get("wedstrijden/").status_code, 302)  # opnieuw aanmelden
        self.d.login()
        self.assertEqual(self.d.get("wedstrijden/").status_code, 200)
        # Ook de nachtelijke taak (buiten de demo); de echte gegevens blijven.
        demo_terugzetten()
        self.assertEqual(demo_aantal("zwemmers"), n)
        self.assertEqual(int(een("SELECT COUNT(*) FROM {p}zwemmers")), 1)
        self.assertEqual(int(een("SELECT COUNT(*) FROM wp_users WHERE user_login='ouder@x.be'")), 1)

    def test_na_update_opnieuw_opgebouwd(self):
        n = demo_aantal("zwemmers")
        self.d.post("zwemmers/nieuw/", {"voornaam": "Nieuw", "naam": "Kind", "geboortedatum": "2015-01-01", "geslacht": "F", "toestemming": "1"})
        self.assertEqual(demo_aantal("zwemmers"), n + 1)
        self.assertContains(self.d.get("wedstrijden/"), "Clubmeeting ZCT")  # zelfde versie: niets teruggezet
        self.assertEqual(demo_aantal("zwemmers"), n + 1)
        # Nieuwe versie van de plugin (andere code-versie): het eerste verzoek bouwt de demo opnieuw op.
        exe("UPDATE wp_options SET option_value='oude versie' WHERE option_name='khzs_demo_code'")
        hulp("cache")
        self.d.get("wedstrijden/")
        self.assertEqual(demo_aantal("zwemmers"), n)

    def test_soorten_trainers_ploegen_en_kalender(self):
        # Nina is enkel trainer van de zwemschool: ze start bij Trainingen en ziet geen wedstrijden.
        n = DemoKlant().login("nina")
        r = n.get("")
        self.assertTrue(r.headers["Location"].endswith("/leden-demo/trainers/"), r.headers["Location"])
        menu = n.get("trainers/").text.replace("\n", "")
        self.assertNotIn(">Wedstrijden</a>", menu)
        pl = self.tekst(n.get("ploegen/"))
        self.assertIn("Zwemschool Startersgroep", pl)
        self.assertNotIn("Kern", pl)
        # Lotte is beide: ze ziet de wedstrijden en alle ploegen.
        lotte = DemoKlant().login("lotte")
        self.assertEqual(lotte.get("wedstrijden/").status_code, 200)
        self.assertIn("Kern", self.tekst(lotte.get("ploegen/")))
        # Ploegen met hun zwemmers en trainingen; de kalender toont de bevestigingen van voorbije trainingen.
        self.assertGreater(demo_aantal("ploegleden"), 10)
        self.assertGreater(demo_aantal("tr_moment_ploegen"), 20)
        self.assertGreater(demo_aantal("tr_bevestigingen"), 100)
        r = self.d.get("trainers/kalender/", weergave="maand")
        self.assertEqual(r.status_code, 200)
        r = self.d.get("trainers/kalender/", wie="alle", datum="2026-11-04")
        self.assertIn('<span class="tr-merk ja">✓</span>', r.text)
        self.assertIn("Zwemschool Groep 4 (rood)", self.tekst(self.d.get("trainers/kalender/", wie="alle", weergave="dag", datum="2026-11-14")))

    def test_aanwezigheid_zwemmers(self):
        # Aanwezigheden voor de voorbije trainingen (niet de laatste twee dagen): overzicht per ploeg en per zwemmer.
        self.assertGreater(demo_aantal("tr_zwemmers"), 100)
        self.assertEqual(int(een("SELECT COUNT(*) FROM wp_khzsdemo_tr_zwemmers WHERE datum>='2026-11-12'")), 0)
        t = self.tekst(self.d.get("trainers/zwemmers/"))
        self.assertIn("Per zwemmer", t)
        self.assertIn("Lina Gebruiker", t)
        self.assertIn("Noor Gebruiker", t)
        # Nina (zwemschool) ziet enkel de zwemschool; een ouder ziet niets.
        t = self.tekst(DemoKlant().login("nina").get("trainers/zwemmers/"))
        self.assertIn("Noor Gebruiker", t)
        self.assertNotIn("Lina Gebruiker", t)
        o = DemoKlant().login("ouder", "ouder")
        self.assertEqual(o.get("trainers/zwemmers/").status_code, 403)
        self.assertEqual(self.d.get("trainers/zwemmers/export/").status_code, 200)
        # Te laat, vroeger weg en ziek komen voor; de ouder ziet de statistiek van Finn, niet die van een ander kind.
        for st in ("laat", "vroeger", "ziek"):
            self.assertGreater(int(een(f"SELECT COUNT(*) FROM wp_khzsdemo_tr_zwemmers WHERE status='{st}'")), 0, st)
        finn = een("SELECT id FROM wp_khzsdemo_zwemmers WHERE voornaam='Finn'")
        lina = een("SELECT id FROM wp_khzsdemo_zwemmers WHERE voornaam='Lina'")
        self.assertIn(f"aanwezigheid/{finn}/", o.get(f"zwemmers/{finn}/").text)
        t = self.tekst(o.get(f"aanwezigheid/{finn}/"))
        self.assertIn("Aanwezigheid Finn Bosmans", t)
        self.assertIn("Per maand", t)
        self.assertEqual(o.get(f"aanwezigheid/{lina}/").status_code, 403)
        self.assertIn("Per weekdag", self.tekst(self.d.get("trainers/zwemmers/")))

    def test_berichten(self):
        # Secties, kanalen per ploeg, een thread, wedstrijdthreads, aankondigingen van elke soort en een hulpvraag met antwoorden.
        r = self.d.get("berichten/")
        t = self.tekst(r)
        for stuk in ("Algemeen", "Competitie", "Zwemschool", "Competitie 2", "Stage Pasen", "Clubmeeting ZCT", "Trainers en bestuur",
                     "Helpers voor de pastadag", "Zwembad Kapermolen zaterdag dicht", "Juryleden en tijdopnemers", "+ 2 andere aankondigingen"):
            self.assertIn(stuk, t)
        self.assertNotIn("Clubfoto", t)  # afgelopen aankondiging
        # Hoogstens drie bovenaan; de rest (en de afgelopen) bij Aankondigingen.
        alle = self.tekst(self.d.get("berichten/aankondigingen/"))
        for stuk in ("Nieuwjaarsreceptie", "Welkom in de nieuwe app", "Clubfoto"):
            self.assertIn(stuk, alle)
        self.assertIn("7 van de 15 nodige personen komen", t)
        self.assertIn('class="tab-badge"', r.text)
        self.assertGreaterEqual(demo_aantal("com_berichten"), 20)
        self.assertEqual(demo_aantal("com_aankondigingen"), 6)
        self.assertEqual(int(een("SELECT COUNT(DISTINCT soort) FROM wp_khzsdemo_com_aankondigingen")), 4)
        # Vastgepind bericht en vermelding in een kanaal.
        k = een("SELECT id FROM wp_khzsdemo_com_kanalen WHERE naam='Competitie 2'")
        self.assertIn("📌 Vastgepind (1)", self.d.get(f"berichten/kanaal/{k}/").text)
        staf = een("SELECT id FROM wp_khzsdemo_com_kanalen WHERE naam='Trainers en bestuur'")
        self.assertIn('<span class="com-vermeld">@Lotte Janssens</span>', self.d.get(f"berichten/kanaal/{staf}/").text)
        # Het bestuur ziet de lijst van de hulpvraag; een ouder enkel de totalen.
        a = een("SELECT id FROM wp_khzsdemo_com_aankondigingen WHERE titel='Helpers voor de pastadag'")
        self.assertIn("Wie komt helpen?", self.d.get(f"berichten/aankondiging/{a}/").text)
        self.assertNotIn("Wie komt helpen?", DemoKlant().login("ouder", "ouder").get(f"berichten/aankondiging/{a}/").text)
        # Een ouder van de zwemschool en de competitie: enkel de eigen ploegen.
        o = self.tekst(DemoKlant().login("ouder", "ouder").get("berichten/"))
        self.assertIn("Beloften", o)
        self.assertNotIn("Competitie 2", o)
        self.assertNotIn("Trainers en bestuur", o)

    def test_nieuwe_module_in_bestaande_demo(self):
        # Een demo van vóór de communicatiemodule: haar tabellen ontbreken, terwijl de module op de echte site al geïnstalleerd is.
        for tab in ("secties", "kanalen", "berichten", "vermeldingen", "status", "wedstrijdzwemmers", "aankondigingen", "rsvp"):
            exe(f"DROP TABLE IF EXISTS wp_khzsdemo_com_{tab}")
        exe("DELETE FROM wp_options WHERE option_name='khzs_demo_tr_db_versie'")
        hulp("cache")
        try:
            demo_terugzetten()  # zoals update.sh na een nieuwe versie
            self.assertGreaterEqual(demo_aantal("com_berichten"), 20)
            # Een nieuwe sectie staat meteen tussen de keuzes voor een nieuw kanaal (na het terugzetten: opnieuw aanmelden).
            d = DemoKlant().login()
            d.post("berichten/beheer/", {"actie": "sectie", "naam": "Ouderwerking", "soort": "algemeen"})
            sid = een("SELECT id FROM wp_khzsdemo_com_secties WHERE naam='Ouderwerking'")
            self.assertIsNotNone(sid)
            keuze = re.search(r'<select name="sectie_id".*?</select>', d.get("berichten/beheer/").text, re.S).group(0)
            self.assertIn(f'<option value="{sid}">Ouderwerking</option>', keuze)
        finally:
            demo_terugzetten()

    def test_demo_uit(self):
        # Zonder KHZS_DEMO bestaat de demo niet, ook niet via een oude rewrite-regel (nooit de echte app op dit adres).
        r = requests.get(DEMO + "aanmelden/", headers={"X-KHZS-Demo-Uit": "1"})
        self.assertEqual(r.status_code, 404)
        self.assertNotIn("Aanmelden", r.text)
        self.assertEqual(requests.get(DEMO + "aanmelden/").status_code, 200)


KALENDER_LXF = b"""<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="Lentemeeting Hasselt" city="Hasselt" course="SCM" deadline="2027-02-20"
 organizer="HZS Spartacus" organizer.url="https://www.khzs.be">
<FACILITY name="Kapermolen" city="Hasselt" street="Kapermolenpark 1" zip="3500" nation="BEL"/>
<POOL name="Zwembad Kapermolen"/>
<FEES><FEE value="550"/></FEES>
<SESSIONS>
<SESSION number="1" date="2027-03-06" daytime="09:00" warmupfrom="08:00" endtime="12:30"><EVENTS>
<EVENT eventid="1" number="1" gender="F"><SWIMSTYLE distance="50" stroke="FREE" relaycount="1"/></EVENT></EVENTS></SESSION>
<SESSION number="2" date="2027-03-07" daytime="14:00"><EVENTS>
<EVENT eventid="2" number="2" gender="M"><SWIMSTYLE distance="100" stroke="BACK" relaycount="1"/></EVENT></EVENTS></SESSION>
</SESSIONS></MEET></MEETS></LENEX>"""


def tec_evenementen():
    r = requests.post(f"{BASIS}/?khzs_test=tec_evenementen", json={}, timeout=30)
    r.raise_for_status()
    return r.json()


class KalenderTest(Basis):
    """Synchronisatie van de wedstrijden met de evenementenkalender (The Events Calendar). Overgeslagen zonder die plugin."""

    @classmethod
    def setUpClass(cls):
        if not hulp("tec")["actief"]:
            raise unittest.SkipTest("The Events Calendar is niet actief op de testsite (testsite.sh installeert die plugin).")

    def setUp(self):
        super().setUp()
        hulp("tec_leeg")
        self.sec = gebruiker("sec@khzs.be", "secretaris")
        self.c.login("sec@khzs.be")
        self.c.vandaag = "2026-11-14"
        self.instellingen()
        r = self.c.post("wedstrijd/nieuw/", files={"bestand": ("lente.lxf", KALENDER_LXF)})
        self.assertEqual(r.status_code, 302, r.text[:2000])
        self.w = int(een("SELECT id FROM {p}wedstrijden"))

    def instellingen(self, **extra):
        data = {"actie": "instellingen", "aan": "1", "statussen[]": ["open", "gesloten", "doorgestuurd"], "categorie": "Wedstrijden",
                "publiceren": "publish"}
        data.update(extra)
        r = self.c.post("kalender/", data)
        self.assertEqual(r.status_code, 302, r.text[:2000])

    def zet(self, status="open", naam="Lentemeeting Hasselt", info=""):
        r = self.c.post(f"wedstrijd/{self.w}/instellingen/", {"naam": naam, "deadline": "2027-02-20", "dagen_secretaris": 3,
                                                             "status": status, "info": info})
        self.assertEqual(r.status_code, 302, r.text[:2000])

    def evenement(self):
        ev = [e for e in tec_evenementen() if str(e["meta"].get("_khzs_wedstrijd_id")) == str(self.w)]
        self.assertLessEqual(len(ev), 1)
        return ev[0] if ev else None

    def test_aanmaken_wijzigen_en_enkel_publieke_info(self):
        self.assertEqual(tec_evenementen(), [], "een concept komt niet op de kalender")
        self.zet("open", info="Busvertrek 7u aan het zwembad.")
        ev = self.evenement()
        self.assertIsNotNone(ev)
        self.assertEqual(ev["status"], "publish")
        self.assertEqual(ev["titel"], "Lentemeeting Hasselt")
        self.assertEqual(ev["meta"]["_EventStartDate"], "2027-03-06 09:00:00")
        self.assertEqual(ev["meta"]["_EventEndDate"], "2027-03-07 17:00:00")  # geen einduur in Lenex: start + 3 uur
        self.assertEqual(ev["locatie"], "Zwembad Kapermolen")
        self.assertEqual(ev["stad"], "Hasselt")
        self.assertEqual(ev["organisator"], "HZS Spartacus")
        self.assertEqual(ev["categorieen"], ["Wedstrijden"])
        self.assertTrue(ev["meta"]["_EventURL"].endswith(f"/leden/wedstrijd/{self.w}/"))
        for t in ("Inschrijven via de ledenapp", "inzwemmen 08:00", "start 09:00", "zaterdag 6 maart 2027", "25 m-bad", "HZS Spartacus"):
            self.assertIn(t, ev["inhoud"])
        # Nooit kosten, interne deadlines of de info (die staat standaard uit).
        for t in ("€", "5,50", "20/02", "Busvertrek"):
            self.assertNotIn(t, ev["inhoud"])
        # Wijziging in de app: hetzelfde evenement wordt bijgewerkt.
        self.zet("gesloten", naam="Lentemeeting HZS")
        ev2 = self.evenement()
        self.assertEqual((ev2["id"], ev2["titel"]), (ev["id"], "Lentemeeting HZS"))
        # Info voor zwemmers enkel als die publiek mag.
        self.instellingen(info="1")
        self.zet("gesloten", naam="Lentemeeting HZS", info="Busvertrek 7u aan het zwembad.")
        self.assertIn("Busvertrek", self.evenement()["inhoud"])
        self.assertContains(self.c.get(f"wedstrijd/{self.w}/instellingen/"), "Op de websitekalender: gepubliceerd.")
        # Nieuwe Lenex-versie: bijgewerkt.
        r = self.c.post(f"wedstrijd/{self.w}/opnieuw/", files={"bestand": ("lente.lxf", KALENDER_LXF.replace(b"2027-03-07", b"2027-03-08"))})
        self.assertEqual(r.status_code, 302)
        self.assertEqual(self.evenement()["meta"]["_EventEndDate"], "2027-03-08 17:00:00")

    def test_concept_annuleert_en_verwijderen(self):
        self.zet("open")
        ev = self.evenement()
        self.zet("concept")
        ev2 = self.evenement()
        self.assertEqual((ev2["id"], ev2["status"], ev2["meta"].get("_tribe_events_status")), (ev["id"], "publish", "canceled"))
        self.zet("open")
        self.assertEqual(self.evenement()["meta"].get("_tribe_events_status"), "scheduled")
        r = self.c.post(f"wedstrijd/{self.w}/verwijderen/")
        self.assertEqual(r.status_code, 302)
        self.assertIsNone(self.evenement(), "koppeling is weg")
        ev3 = [e for e in tec_evenementen() if e["id"] == ev["id"]][0]
        self.assertEqual((ev3["status"], ev3["meta"]["_tribe_events_status"], str(ev3["meta"]["_khzs_was_wedstrijd_id"])),
                         ("publish", "canceled", str(self.w)))

    def test_concept_evenement_naar_prullenbak_en_terug(self):
        self.instellingen(publiceren="draft")
        self.zet("open")
        ev = self.evenement()
        self.assertEqual(ev["status"], "draft")
        self.zet("concept")
        self.assertEqual(self.evenement()["status"], "trash")
        self.zet("open")
        ev2 = self.evenement()
        self.assertEqual((ev2["id"], ev2["status"]), (ev["id"], "draft"))

    def test_voorbij_evenement_blijft_staan(self):
        self.zet("doorgestuurd")
        ev = self.evenement()
        self.c.vandaag = "2027-04-01"
        self.c.post(f"wedstrijd/{self.w}/verwijderen/")
        ev2 = [e for e in tec_evenementen() if e["id"] == ev["id"]][0]
        self.assertEqual(ev2["status"], "publish")
        self.assertNotEqual(ev2["meta"].get("_tribe_events_status"), "canceled")

    def test_handmatige_wijziging_niet_overschreven(self):
        self.zet("open")
        ev = self.evenement()
        hulp("tec_wijzig", ID=ev["id"], post_content="Met de hand aangepast door de webmaster.")
        self.zet("open", naam="Andere naam")
        ev2 = self.evenement()
        self.assertEqual(ev2["titel"], "Lentemeeting Hasselt")
        self.assertEqual(ev2["inhoud"], "Met de hand aangepast door de webmaster.")
        self.assertIn("_khzs_conflict", ev2["meta"])
        self.assertContains(self.c.get("kalender/"), "Met de hand gewijzigd op de website")
        self.assertContains(self.c.get(f"wedstrijd/{self.w}/instellingen/"), "met de hand gewijzigd op de website")
        # Statuswijzigingen gelden wel (annuleren raakt de inhoud niet).
        self.zet("concept", naam="Andere naam")
        self.assertEqual(self.evenement()["meta"].get("_tribe_events_status"), "canceled")
        self.zet("open", naam="Andere naam")
        # Overschrijven met de app.
        self.c.post("kalender/", {"actie": "overschrijven", "evenement": ev["id"]})
        ev3 = self.evenement()
        self.assertEqual(ev3["titel"], "Andere naam")
        self.assertIn("Inschrijven via de ledenapp", ev3["inhoud"])
        self.assertNotIn("_khzs_conflict", ev3["meta"])
        # Opnieuw met de hand gewijzigd en ‘niet meer bijwerken’: de app blijft eraf.
        hulp("tec_wijzig", ID=ev["id"], post_title="Eigen titel")
        self.zet("open", naam="Nog een naam")
        self.c.post("kalender/", {"actie": "vast", "evenement": ev["id"]})
        self.zet("concept", naam="Nog een naam")
        ev4 = self.evenement()
        self.assertEqual((ev4["titel"], ev4["meta"].get("_tribe_events_status")), ("Eigen titel", "scheduled"))
        self.assertContains(self.c.get("kalender/"), "Wordt niet meer bijgewerkt vanuit de app.")

    def test_knop_alles_synchroniseren(self):
        self.instellingen(aan="")
        self.zet("open")
        self.assertEqual(tec_evenementen(), [], "synchronisatie staat uit")
        self.instellingen()
        r = self.c.post("kalender/", {"actie": "alles"})
        self.assertEqual(r.status_code, 302)
        self.assertContains(self.c.get("kalender/"), "Gesynchroniseerd: 1 aangemaakt.")
        self.assertIsNotNone(self.evenement())
        # Een wedstrijd die verdwijnt zonder hook (bv. een teruggezette backup): de knop annuleert het evenement.
        exe(f"DELETE FROM {{p}}wedstrijden WHERE id={self.w}")
        self.c.post("kalender/", {"actie": "alles"})
        self.assertContains(self.c.get("kalender/"), "1 geannuleerd")
        # Enkel voor het secretariaat.
        gebruiker("tr@khzs.be", "trainer")
        self.c.login("tr@khzs.be")
        self.assertEqual(self.c.get("kalender/").status_code, 403)

    def test_publieke_bijlage(self):
        self.zet("open")
        self.c.post(f"wedstrijd/{self.w}/bijlage/", {"naam": "Voorprogramma"}, files={"bestand": ("vp.pdf", b"%PDF-1.4 vp")})
        self.c.post(f"wedstrijd/{self.w}/bijlage/", {"naam": "Busregeling"}, files={"bestand": ("bus.pdf", b"%PDF-1.4 bus")})
        vp, bus = [int(b["id"]) for b in sql("SELECT id FROM {p}bijlagen ORDER BY id")]
        self.assertNotIn("Voorprogramma", self.evenement()["inhoud"])
        r = self.c.post(f"bijlage/{vp}/publiek/", {"publiek": "1"})
        self.assertEqual(r.status_code, 302)
        inhoud = self.evenement()["inhoud"]
        self.assertIn("Voorprogramma", inhoud)
        self.assertIn(f"/leden/bijlage/{vp}/", inhoud)
        self.assertNotIn("Busregeling", inhoud)
        anoniem = requests.get(APP + f"bijlage/{vp}/", allow_redirects=False)
        self.assertEqual((anoniem.status_code, anoniem.content), (200, b"%PDF-1.4 vp"))
        self.assertEqual(requests.get(APP + f"bijlage/{bus}/", allow_redirects=False).status_code, 302)
        # Wedstrijd terug naar concept: ook de bijlage is niet meer publiek.
        self.zet("concept")
        self.assertEqual(requests.get(APP + f"bijlage/{vp}/", allow_redirects=False).status_code, 302)
        self.zet("open")
        self.c.post(f"bijlage/{vp}/verwijderen/")
        self.assertNotIn("Voorprogramma", self.evenement()["inhoud"])

    def test_nooit_in_de_demo(self):
        demo_terugzetten()
        self.assertEqual(tec_evenementen(), [])
        d = DemoKlant().login()
        self.assertNotIn("Websitekalender", d.get("wedstrijden/").text)
        self.assertEqual(tec_evenementen(), [])


def optie(naam, waarde=None):
    """Optie van WordPress zetten (of wissen met None)."""
    exe(f"DELETE FROM wp_options WHERE option_name='{naam}'")
    if waarde is not None:
        exe(f"INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('{naam}', '{waarde}', 'yes')")
    hulp("cache")


class AvgTest(Basis):
    """AVG: privacyverklaring, akkoord, toestemming ouder, gegevens downloaden, account verwijderen of afsluiten, opkuis."""

    LEES = "Lees hoe de club met je gegevens omgaat"

    def tearDown(self):
        for o in ("khzs_privacy_verantwoordelijke", "khzs_privacy_adres", "khzs_privacy_email", "khzs_privacy_versie", "khzs_privacy_extra"):
            optie(o)

    def gezin(self):
        self.ouder = gebruiker("ouder@test.be", first_name="Ann")
        self.papa = gebruiker("papa@test.be", first_name="Piet")
        self.vreemde = gebruiker("vreemde@test.be")
        self.lien = zwemmer(self.ouder, "Lien")
        exe(f"INSERT INTO {{p}}zwemmer_accounts (zwemmer_id, user_id, aangemaakt) VALUES ({self.lien}, {self.papa}, NOW())")
        self.bram = zwemmer(self.ouder, "Bram")
        self.anders = zwemmer(self.vreemde, "Anders")
        tijd(self.lien, 50, "FREE", "SCM", 3312)
        exe(f"INSERT INTO {{p}}push (user_id, endpoint, endpoint_hash, p256dh, auth, toestel, aangemaakt) "
            f"VALUES ({self.ouder}, 'https://push.example/1', SHA2('https://push.example/1', 256), 'x', 'y', 'Firefox', NOW())")
        return Klant().login("ouder@test.be")

    def test_pagina_zonder_aanmelden_en_gelinkt(self):
        r = self.c.get("privacy/")
        self.assertEqual(r.status_code, 200)
        for tekst in ("Hasseltse Zwemvereniging Spartacus", "Gegevensbeschermingsautoriteit", "wordpress_logged_in_", "khzs_pwa_weg",
                      "Uren, tarieven en uitbetalingen"):
            self.assertContains(r, tekst)
        self.assertNotContains(r, "Ik heb de privacyverklaring gelezen en ga akkoord")  # knop enkel aangemeld
        self.assertContains(self.c.get("aanmelden/"), "/leden/privacy/")
        self.assertContains(self.c.get("registreren/"), "/leden/privacy/")

    def test_instelbare_gegevens(self):
        for o, w in (("khzs_privacy_verantwoordelijke", "Club X vzw"), ("khzs_privacy_adres", "Zwembadlaan 1, 3500 Hasselt"),
                     ("khzs_privacy_email", "privacy@club.be"), ("khzs_privacy_versie", "2027-01"), ("khzs_privacy_extra", "Onze DPO is Jan.")):
            optie(o, w)
        r = self.c.get("privacy/")
        for tekst in ("Club X vzw", "Zwembadlaan 1, 3500 Hasselt", "mailto:privacy@club.be", "versie 2027-01", "Onze DPO is Jan."):
            self.assertContains(r, tekst)

    def test_registratie_vraagt_akkoord(self):
        velden = {"email": "nieuw@test.be", "password1": "Zwemmen-2026!", "password2": "Zwemmen-2026!", "voornaam": "Ann", "naam": "P"}
        r = self.c.post("registreren/", velden)
        self.assertEqual(r.status_code, 200)
        self.assertContains(r, "Je moet de privacyverklaring aanvaarden")
        self.assertIsNone(een("SELECT ID FROM wp_users WHERE user_login='nieuw@test.be'"))
        r = self.c.post("registreren/", {**velden, "privacy": "1"})
        self.assertEqual(r.status_code, 302)
        uid = int(een("SELECT ID FROM wp_users WHERE user_login='nieuw@test.be'"))
        self.assertEqual(hulp("meta", id=uid, key="khzs_privacy_versie")["v"], "2026-11-14")
        self.assertTrue(hulp("meta", id=uid, key="khzs_privacy_akkoord")["v"])
        self.c.nonce = None
        self.assertNotContains(self.c.get("welkom/"), self.LEES)

    def test_bestaand_account_en_nieuwe_versie(self):
        gebruiker("oud@test.be", rol="ouder")
        k = Klant().login("oud@test.be")
        self.assertContains(k.get("meldingen/"), self.LEES)
        r = k.post("privacy/")
        self.assertTrue(r.headers["Location"].endswith("/leden/privacy/mijn-gegevens/"))
        self.assertNotContains(k.get("meldingen/"), self.LEES)
        optie("khzs_privacy_versie", "2099-01-01")
        self.assertContains(k.get("meldingen/"), self.LEES)
        self.assertContains(k.get("privacy/mijn-gegevens/"), "Er is een nieuwe versie")

    def test_toestemming_minderjarige(self):
        gebruiker("ouder@test.be", rol="ouder", first_name="Ann")
        k = Klant().login("ouder@test.be")
        velden = {"actie": "kind", "voornaam": "Mo", "naam": "Q", "geboortedatum": "2014-05-05", "geslacht": "M"}
        r = k.post("welkom/", velden, query={"stap": "kinderen"})
        self.assertContains(r, "toestemming van een ouder of voogd nodig")
        self.assertEqual(int(een("SELECT COUNT(*) FROM {p}zwemmers")), 0)
        k.post("welkom/", {**velden, "toestemming": "1"}, query={"stap": "kinderen"})
        z = sql("SELECT * FROM {p}zwemmers")[0]
        self.assertTrue(z["toestemming_op"])
        self.assertEqual(z["toestemming_door"], z["account_id"])
        r = k.post("zwemmers/nieuw/", {"voornaam": "Lot", "naam": "Q", "geboortedatum": "2016-01-01", "geslacht": "F"})
        self.assertContains(r, "toestemming van een ouder of voogd nodig")
        k.post("zwemmers/nieuw/", {"voornaam": "Ben", "naam": "Q", "geboortedatum": "1990-01-01", "geslacht": "M"})  # meerderjarig
        self.assertIsNone(een("SELECT toestemming_op FROM {p}zwemmers WHERE voornaam='Ben'"))

    def test_toestemming_achteraf(self):
        k = self.gezin()
        self.assertContains(k.get(f"zwemmers/{self.bram}/"), "Ik geef toestemming")
        k.post(f"zwemmers/{self.bram}/", {"actie": "toestemming"})
        self.assertTrue(een(f"SELECT toestemming_op FROM {{p}}zwemmers WHERE id={self.bram}"))
        self.assertNotContains(k.get(f"zwemmers/{self.bram}/"), "Ik geef toestemming")
        gebruiker("tr@test.be", rol="trainer")
        self.assertNotContains(Klant().login("tr@test.be").get(f"zwemmers/{self.lien}/"), "Ik geef toestemming")

    def test_download(self):
        k = self.gezin()
        r = k.get("privacy/mijn-gegevens.json")
        self.assertIn("attachment", r.headers["Content-Disposition"])
        d = r.json()
        self.assertEqual(d["account"]["e-mail"], "ouder@test.be")
        self.assertEqual(sorted(z["voornaam"] for z in d["zwemmers"]), ["Bram", "Lien"])
        lien = next(z for z in d["zwemmers"] if z["voornaam"] == "Lien")
        self.assertEqual(lien["beste_tijden"][0]["tijd"], "33.12")
        self.assertEqual(lien["accounts"], ["Ann", "Piet"])
        self.assertEqual(d["toestellen_met_pushmeldingen"][0]["toestel"], "Firefox")
        self.assertNotIn("p256dh", r.text)
        self.assertEqual(Klant().get("privacy/mijn-gegevens.json").status_code, 302)  # niet aangemeld

    def test_verwijderen_met_gedeelde_zwemmer(self):
        k = self.gezin()
        r = k.get("privacy/mijn-gegevens/")
        self.assertContains(r, "Deze zwemmers verdwijnen mee")
        self.assertContains(r, "Bram P")
        self.assertContains(r, "Deze zwemmers blijven")
        k.post("privacy/verwijderen/", {"bevestig": "VERWIJDER", "wachtwoord": "fout"})
        k.post("privacy/verwijderen/", {"bevestig": "nee", "wachtwoord": "geheim123!"})
        self.assertTrue(een(f"SELECT ID FROM wp_users WHERE ID={self.ouder}"))
        r = k.post("privacy/verwijderen/", {"bevestig": "VERWIJDER", "wachtwoord": "geheim123!"})
        self.assertTrue(r.headers["Location"].endswith("/leden/aanmelden/"))
        self.assertIsNone(een(f"SELECT ID FROM wp_users WHERE ID={self.ouder}"))
        self.assertIsNone(een(f"SELECT id FROM {{p}}zwemmers WHERE id={self.bram}"))
        self.assertEqual(int(een(f"SELECT account_id FROM {{p}}zwemmers WHERE id={self.lien}")), self.papa)
        self.assertEqual(int(een(f"SELECT COUNT(*) FROM {{p}}tijden WHERE zwemmer_id={self.lien}")), 1)
        self.assertTrue(een(f"SELECT id FROM {{p}}zwemmers WHERE id={self.anders}"))
        self.assertEqual(int(een("SELECT COUNT(*) FROM {p}push")), 0)
        self.assertEqual(k.get("zwemmers/").status_code, 302)  # afgemeld

    def test_trainer_wordt_afgesloten_en_later_gewist(self):
        uid = gebruiker("tr@test.be", rol="trainer", first_name="Tom")
        exe("UPDATE wp_usermeta SET meta_value='Peeters' WHERE meta_key='last_name' AND user_id=%d" % uid)
        exe(f"INSERT INTO {{p}}tr_uitbetaald (user_id, maand, trainingen, minuten, bedrag_cent, door_id, op) VALUES ({uid}, '2026-09', 8, 720, 12000, NULL, NOW())")
        exe(f"INSERT INTO {{p}}push (user_id, endpoint, endpoint_hash, p256dh, auth, toestel, aangemaakt) "
            f"VALUES ({uid}, 'https://push.example/t', SHA2('https://push.example/t', 256), 'x', 'y', '', NOW())")
        k = Klant().login("tr@test.be")
        self.assertContains(k.get("privacy/mijn-gegevens/"), "Bewaarplicht")
        self.assertIn("trainer", k.get("privacy/mijn-gegevens.json").json())
        k.post("privacy/verwijderen/", {"bevestig": "VERWIJDER", "wachtwoord": "geheim123!"})
        u = sql(f"SELECT user_login, user_email, display_name FROM wp_users WHERE ID={uid}")[0]
        self.assertEqual(u, {"user_login": f"afgesloten-{uid}", "user_email": f"afgesloten-{uid}@verwijderd.invalid", "display_name": "Tom Peeters"})
        self.assertEqual(hulp("meta", id=uid, key="khzs_bewaren_tot")["v"], "2037-01-01")
        self.assertEqual(int(een("SELECT COUNT(*) FROM {p}tr_uitbetaald")), 1)  # boekhouding blijft
        self.assertEqual(int(een("SELECT COUNT(*) FROM {p}push")), 0)
        r = Klant().s.post(APP + "aanmelden/", data={"username": f"afgesloten-{uid}", "password": "geheim123!"}, allow_redirects=False)
        self.assertEqual(r.status_code, 200)  # aanmelden kan niet meer
        gebruiker("bestuur@test.be", rol="bestuurder")
        self.assertNotContains(Klant().login("bestuur@test.be").get("gebruikers/"), "afgesloten-")
        # Na de bewaartermijn wist de opkuis het account en de bewaarde gegevens.
        exe(f"UPDATE wp_usermeta SET meta_value='2020-01-01' WHERE meta_key='khzs_bewaren_tot' AND user_id={uid}")
        self.assertEqual(hulp("eval", f="avg_opkuis")["v"]["afgesloten_accounts"], 1)
        self.assertIsNone(een(f"SELECT ID FROM wp_users WHERE ID={uid}"))
        self.assertEqual(int(een("SELECT COUNT(*) FROM {p}tr_uitbetaald")), 0)

    def test_beheerder_niet_zelf(self):
        gebruiker("baas@test.be", wprol="administrator")
        k = Klant().login("baas@test.be")
        self.assertContains(k.get("privacy/mijn-gegevens/"), "Vraag het aan een andere beheerder")
        k.post("privacy/verwijderen/", {"bevestig": "VERWIJDER", "wachtwoord": "geheim123!"})
        self.assertTrue(een("SELECT ID FROM wp_users WHERE user_login='baas@test.be'"))

    def test_opkuis(self):
        k = self.gezin()  # aanmelden zet khzs_laatst_aangemeld: het toestel van de ouder blijft
        exe("INSERT INTO {p}meldingen (zwemmer_id, wedstrijd_id, door_id, tekst, gelezen, aangemaakt) VALUES "
            f"({self.lien}, 1, NULL, 'oud', 1, NOW() - INTERVAL 400 DAY), ({self.lien}, 1, NULL, 'nieuw', 0, NOW())")
        exe("INSERT INTO {p}rolaanvragen (user_id, rol, status, behandeld_op, aangemaakt) VALUES "
            f"({self.ouder}, 'trainer', 'afgekeurd', NOW() - INTERVAL 400 DAY, NOW() - INTERVAL 401 DAY), "
            f"({self.ouder}, 'bestuurder', 'open', NULL, NOW() - INTERVAL 400 DAY)")
        exe(f"INSERT INTO {{p}}push (user_id, endpoint, endpoint_hash, p256dh, auth, toestel, aangemaakt) "
            f"VALUES ({self.vreemde}, 'https://push.example/oud', SHA2('https://push.example/oud', 256), 'x', 'y', '', NOW() - INTERVAL 400 DAY)")
        exe(f"UPDATE {{p}}zwemmers SET koppelcode='ABCDEFGH', koppelcode_tot=NOW() - INTERVAL 1 DAY WHERE id={self.lien}")
        n = hulp("eval", f="avg_opkuis")["v"]
        self.assertEqual((n["koppelcodes"], n["meldingen"], n["rolaanvragen"], n["pushabonnementen"]), (1, 1, 1, 1))
        self.assertEqual([r["tekst"] for r in sql("SELECT tekst FROM {p}meldingen")], ["nieuw"])
        self.assertEqual([r["status"] for r in sql("SELECT status FROM {p}rolaanvragen")], ["open"])
        self.assertEqual([r["endpoint"] for r in sql("SELECT endpoint FROM {p}push")], ["https://push.example/1"])
        self.assertEqual(een(f"SELECT koppelcode FROM {{p}}zwemmers WHERE id={self.lien}"), "")
        self.assertEqual(hulp("eval", f="avg_opkuis")["v"]["meldingen"], 0)

    def test_demo_toont_maar_verwijdert_niet(self):
        if requests.get(DEMO + "aanmelden/").status_code != 200:
            self.skipTest("Demo staat uit")
        d = DemoKlant().login()
        self.assertContains(d.get("privacy/"), "Dit is de demo met verzonnen gegevens")
        self.assertContains(d.get("privacy/mijn-gegevens/"), "In de demo kan je je account niet verwijderen")
        self.assertEqual(d.get("privacy/mijn-gegevens.json").json()["account"]["gebruikersnaam"], "demo")
        d.post("privacy/verwijderen/", {"bevestig": "VERWIJDER", "wachtwoord": "demo"})
        self.assertEqual(d.get("wedstrijden/").status_code, 200)  # nog altijd aangemeld


if __name__ == "__main__":
    unittest.main(verbosity=1)
