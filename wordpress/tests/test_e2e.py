"""End-to-end-tests van de WordPress-plugin, dezelfde scenario's als inschrijvingen/tests/test_alles.py.

Vereist een testsite met de plugin en tests/khzs-test-helper.php (als mu-plugin), bv. op http://localhost:8080:
    KHZS_URL=http://localhost:8080 python3 wordpress/tests/test_e2e.py
"""
import base64
import io
import os
import re
import sys
import unittest
import zipfile
from pathlib import Path
from xml.etree import ElementTree as ET

import requests

BASIS = os.environ.get("KHZS_URL", "http://localhost:8080").rstrip("/")
APP = BASIS + "/inschrijvingen/"
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


def gebruiker(login, rol=None, first_name="", wprol="subscriber"):
    return hulp("user", login=login, rol=rol, first_name=first_name, wprol=wprol)["id"]


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


class Klant:
    def __init__(self):
        self.s = requests.Session()
        self.nonce = None
        self.vandaag = None

    def _h(self):
        return {"X-Vandaag": self.vandaag} if self.vandaag else {}

    def login(self, login, pw="geheim123!"):
        self.s.cookies.clear()
        r = self.s.post(APP + "aanmelden/", data={"username": login, "password": pw}, allow_redirects=False)
        assert r.status_code == 302, r.text[:500]
        self.nonce = None
        return self

    def logout(self):
        self.s.cookies.clear()
        self.nonce = None

    def get(self, pad, **params):
        return self.s.get(APP + pad, params=params, allow_redirects=False, headers=self._h())

    def _nonce(self):
        if not self.nonce:
            html = self.s.get(APP + "uitleg/", headers=self._h()).text
            m = re.search(r'name="_khzs" value="([^"]+)"', html)
            self.nonce = m.group(1) if m else ""
        return self.nonce

    def post(self, pad, data=None, files=None, query=None):
        data = dict(data or {})
        data.setdefault("_khzs", self._nonce())
        return self.s.post(APP + pad, data=data, files=files, params=query, allow_redirects=False, headers=self._h())

    def json(self, pad, body):
        return self.s.post(APP + pad, json=body, headers={"X-KHZS-Nonce": self._nonce(), **self._h()}, allow_redirects=False)


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
        r = self.c.post("registreren/", {
            "email": "ouder@test.be", "password1": "Zwemmen-2026!", "password2": "Zwemmen-2026!",
            "voornaam": "Ann", "naam": "Peeters", "zw-TOTAL_FORMS": 1,
            "zw-0-voornaam": "Lien", "zw-0-naam": "Peeters", "zw-0-geboortedatum": "2013-03-02", "zw-0-geslacht": "F",
        })
        self.assertEqual(r.status_code, 302, r.text[:3000])
        self.c.nonce = None
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
        r = self.c.post("registreren/", {
            "email": "ann@test.be", "password1": "Zwemmen-2026!", "password2": "Zwemmen-2026!", "voornaam": "Ann", "naam": "Peeters",
            "zw-TOTAL_FORMS": 2,
            "zw-0-voornaam": "Lien", "zw-0-naam": "Peeters", "zw-0-geboortedatum": "2013-03-02", "zw-0-geslacht": "F",
            "zw-1-voornaam": "Bram", "zw-1-naam": "Peeters", "zw-1-geboortedatum": "2015-06-01", "zw-1-geslacht": "M",
        })
        self.assertEqual(r.status_code, 302, r.text[:2000])
        self.assertEqual(sorted(x["voornaam"] for x in sql("SELECT voornaam FROM {p}zwemmers")), ["Bram", "Lien"])
        self.assertEqual(een("SELECT meta_value FROM wp_usermeta m JOIN wp_users u ON u.ID=m.user_id WHERE u.user_login='ann@test.be' AND meta_key='first_name'"), "Ann")
        # Ingelogd na registratie.
        self.assertEqual(self.c.get("wedstrijden/").status_code, 200)

    def test_minstens_een_zwemmer(self):
        r = self.c.post("registreren/", {"email": "leeg@test.be", "password1": "Zwemmen-2026!", "password2": "Zwemmen-2026!",
                                         "voornaam": "A", "naam": "B", "zw-TOTAL_FORMS": 0})
        self.assertEqual(r.status_code, 200)
        self.assertContains(r, "minstens één zwemmer")
        self.assertFalse(sql("SELECT ID FROM wp_users WHERE user_login='leeg@test.be'"))

    def test_bestaand_email(self):
        gebruiker("dubbel@test.be")
        r = self.c.post("registreren/", {"email": "dubbel@test.be", "password1": "Zwemmen-2026!", "password2": "Zwemmen-2026!",
                                         "voornaam": "A", "naam": "B", "zw-TOTAL_FORMS": 1, "zw-0-voornaam": "X", "zw-0-naam": "Y",
                                         "zw-0-geboortedatum": "2013-03-02", "zw-0-geslacht": "F"})
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


if __name__ == "__main__":
    unittest.main(verbosity=1)
