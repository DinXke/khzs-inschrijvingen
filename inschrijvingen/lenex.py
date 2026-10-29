"""Lezen van Lenex-uitnodigingen (.lxf/.lef) en schrijven van een inschrijvingsbestand."""
import io
import re
import zipfile
from datetime import date, datetime, time, timedelta
from xml.etree import ElementTree as ET

import defusedxml.ElementTree as SafeET
from defusedxml import DefusedXmlException

from django.conf import settings
from django.db import transaction


class LenexFout(ValueError):
    pass


def lees_xml(data: bytes) -> ET.Element:
    """Geeft de LENEX-root terug; aanvaardt zowel zip (.lxf) als kale XML (.lef)."""
    if data[:2] == b"PK":
        with zipfile.ZipFile(io.BytesIO(data)) as z:
            namen = [n for n in z.namelist() if n.lower().endswith((".lef", ".xml"))]
            if not namen:
                raise LenexFout("Geen .lef-bestand gevonden in het .lxf-archief.")
            data = z.read(namen[0])
    try:
        root = SafeET.fromstring(data)
    except (ET.ParseError, DefusedXmlException) as e:
        raise LenexFout(f"Geen geldige Lenex-XML: {e}") from e
    if root.tag != "LENEX":
        raise LenexFout("Dit is geen Lenex-bestand.")
    return root


def parse_tijd(s):
    """'00:01:02.86' → timedelta. 'NT' of leeg → None."""
    if not s or s.upper() == "NT":
        return None
    m = re.fullmatch(r"(\d+):(\d+):(\d+)\.(\d+)", s)
    if not m:
        return None
    h, mi, se, hu = m.groups()
    return timedelta(hours=int(h), minutes=int(mi), seconds=int(se), milliseconds=int(hu.ljust(2, "0")[:2]) * 10)


def format_tijd(td):
    """timedelta → Lenex-swimtime 'HH:MM:SS.hh'."""
    if td is None:
        return "NT"
    hon = round(td.total_seconds() * 100)
    h, rest = divmod(hon, 360000)
    m, rest = divmod(rest, 6000)
    s, hu = divmod(rest, 100)
    return f"{h:02d}:{m:02d}:{s:02d}.{hu:02d}"


def _datum(s):
    try:
        return date.fromisoformat(s[:10]) if s else None
    except ValueError:
        return None


def _tijd(s):
    try:
        return time.fromisoformat(s[:8]) if s else None
    except ValueError:
        return None


def _int(s, standaard=0):
    try:
        return int(s)
    except (TypeError, ValueError):
        return standaard


def _baan(course):
    """Lenex kent SCM, LCM, SCY, SCM16, SCM20, SCM33, OPEN. Wij rekenen met 25 m / 50 m."""
    return "LCM" if (course or "").upper() == "LCM" else "SCM"


def _geslacht(g):
    g = (g or "").upper()
    return g if g in ("M", "F") else "X"


SLAGEN = {"FREE", "BACK", "BREAST", "FLY", "MEDLEY"}
# Rondes waarvoor je niet rechtstreeks inschrijft: finales, halve finales, swim-offs.
VERVOLGRONDES = {"FIN", "SEM", "QUA", "SOP", "SOS", "SOQ", "SOF"}


def _fee(el):
    """Kost in cent uit een FEE-element (Lenex 3: value in cent; Lenex 2: soms decimaal)."""
    if el is None:
        return None
    v = el.get("value", "")
    if "." in v or "," in v:
        try:
            return round(float(v.replace(",", ".")) * 100)
        except ValueError:
            return None
    return _int(v, None)


def _kies_meet(root):
    meets = root.findall("MEETS/MEET")
    if not meets:
        raise LenexFout("Geen wedstrijd (MEET) gevonden in het bestand.")
    # Bij meerdere MEETs: de eerste met een programma.
    return next((m for m in meets if m.find("SESSIONS/SESSION/EVENTS/EVENT") is not None), meets[0])


def parse_meet(root: ET.Element) -> dict:
    """Haalt de wedstrijd op als gewone dict-structuur (los van de database).
    Werkt voor Lenex 2.x en 3.0, ook als optionele velden ontbreken."""
    meet = _kies_meet(root)
    pool = meet.find("POOL")
    facility = meet.find("FACILITY")
    agedate = meet.find("AGEDATE")
    qualify = meet.find("QUALIFY")
    waarschuwingen = []

    # Standaardkost op wedstrijdniveau (MEET/FEES/FEE of MEET/FEE), zonder type of voor individuele zwemmers.
    standaard_fee = None
    for f in meet.findall("FEES/FEE") + meet.findall("FEE"):
        if f.get("type") in (None, "", "ATHLETE.INDIVIDUAL", "INDIVIDUAL"):
            standaard_fee = _fee(f)
            break

    sessies = []
    gebruikte_refs = set()
    for s in meet.findall("SESSIONS/SESSION"):
        sessie_fee = None
        for f in s.findall("FEES/FEE") + s.findall("FEE"):
            if f.get("type") in (None, "", "ATHLETE.INDIVIDUAL", "INDIVIDUAL"):
                sessie_fee = _fee(f)
        nummers = []
        for e in s.findall("EVENTS/EVENT"):
            st = e.find("SWIMSTYLE")
            if st is None:
                continue
            stroke = (st.get("stroke") or "UNKNOWN").upper()
            ronde = (e.get("round") or "TIM").upper()
            vorige = e.get("preveventid")
            heeft_vorige = vorige not in (None, "", "-1", "0")
            kost = _fee(e.find("FEE"))
            if kost is None:
                kost = sessie_fee if sessie_fee is not None else (standaard_fee or 0)
            for r in e.findall("TIMESTANDARDREFS/TIMESTANDARDREF"):
                gebruikte_refs.add(r.get("timestandardlistid"))
            eventid = e.get("eventid") or f"{s.get('number')}-{e.get('number')}"
            nummers.append({
                "eventid": eventid,
                "nummer": _int(e.get("number")),
                "volgorde": _int(e.get("order"), _int(e.get("number"))),
                "geslacht": _geslacht(e.get("gender")),
                "afstand": _int(st.get("distance")),
                "slag": stroke,
                "naam": st.get("name", "") if stroke not in SLAGEN else "",
                "aflossing": max(_int(st.get("relaycount"), 1), 1),
                "ronde": ronde,
                "inschrijfbaar": not heeft_vorige and ronde not in VERVOLGRONDES and stroke in SLAGEN,
                "kost_cent": kost,
                "leeftijdsgroepen": [
                    (_int(a.get("agemin"), -1), _int(a.get("agemax"), -1))
                    for a in e.findall("AGEGROUPS/AGEGROUP")
                ] or [(-1, -1)],
            })
        datum = _datum(s.get("date"))
        if not datum:
            waarschuwingen.append(f"Sessie {s.get('number')} heeft geen datum.")
        sessies.append({
            "nummer": _int(s.get("number"), len(sessies) + 1),
            "datum": datum,
            "starttijd": _tijd(s.get("daytime")),
            "opwarmen": _tijd(s.get("warmupfrom")),
            "nummers": nummers,
        })
    if not any(s["nummers"] for s in sessies):
        raise LenexFout("Het bestand bevat geen programma (geen sessies of nummers).")

    eerste_dag = min((s["datum"] for s in sessies if s["datum"]), default=None)
    for s in sessies:
        s["datum"] = s["datum"] or eerste_dag or date.today()

    deadline = _datum(meet.get("deadline"))
    if not deadline:
        deadline = (eerste_dag or date.today()) - timedelta(days=7)
        waarschuwingen.append("Geen laatste inschrijfdatum in het bestand: voorlopig 7 dagen voor de wedstrijd gezet. Pas dit aan.")

    limietlijsten = []
    # Limieten staan soms onder MEET, soms (SPLASH) rechtstreeks onder LENEX.
    for lst in meet.findall("TIMESTANDARDLISTS/TIMESTANDARDLIST") + root.findall("TIMESTANDARDLISTS/TIMESTANDARDLIST"):
        ag = lst.find("AGEGROUP")
        limieten = []
        for t in lst.findall("TIMESTANDARDS/TIMESTANDARD"):
            sst, tijd = t.find("SWIMSTYLE"), parse_tijd(t.get("swimtime"))
            if sst is not None and tijd and _int(sst.get("relaycount"), 1) <= 1:
                limieten.append({"afstand": _int(sst.get("distance")), "slag": (sst.get("stroke") or "").upper(), "tijd": tijd})
        if not limieten:
            continue
        lid = lst.get("timestandardlistid")
        limietlijsten.append({
            "lenex_id": lid or "",
            "naam": lst.get("name") or f"Limiet {lst.get('code') or lid}",
            "code": lst.get("code", ""),
            "soort": (lst.get("type") or "DEFAULT").upper(),
            "baan": _baan(lst.get("course")),
            "geslacht": _geslacht(lst.get("gender")),
            "agemin": _int(ag.get("agemin"), -1) if ag is not None else -1,
            "agemax": _int(ag.get("agemax"), -1) if ag is not None else -1,
            "voorgeselecteerd": lid in gebruikte_refs,
            "limieten": limieten,
        })

    return {
        "naam": meet.get("name") or "Wedstrijd",
        "stad": meet.get("city") or (facility.get("city", "") if facility is not None else ""),
        "zwembad": (pool.get("name") if pool is not None else None) or (facility.get("name", "") if facility is not None else ""),
        "baan": _baan(meet.get("course")),
        "deadline": deadline,
        "agedate": _datum(agedate.get("value")) if agedate is not None else None,
        "agedate_type": (agedate.get("type") or "YEAR").upper() if agedate is not None else "YEAR",
        "kwalificatie_van": _datum(qualify.get("from")) if qualify is not None else None,
        "kwalificatie_tot": _datum(qualify.get("until")) if qualify is not None else None,
        "sessies": sessies,
        "limietlijsten": limietlijsten,
        "waarschuwingen": waarschuwingen,
    }


@transaction.atomic
def importeer(meet: dict, bestand, wedstrijd=None):
    """Schrijft een geparste meet naar de database. Bij een nieuwe versie van dezelfde
    wedstrijd blijven bestaande inschrijvingen behouden (gekoppeld via eventid)."""
    from .models import Leeftijdsgroep, Limiet, Limietlijst, Nummer, Sessie, Wedstrijd

    velden = {k: meet[k] for k in ("naam", "stad", "zwembad", "baan", "deadline", "agedate",
                                   "agedate_type", "kwalificatie_van", "kwalificatie_tot")}
    if wedstrijd is None:
        wedstrijd = Wedstrijd(**velden)
    else:
        for k, v in velden.items():
            setattr(wedstrijd, k, v)
    wedstrijd.lenex_bestand = bestand
    wedstrijd.save()

    gekozen = set(wedstrijd.limietlijsten.values_list("naam", "baan"))
    oude_nummers = {n.eventid: n for n in Nummer.objects.filter(sessie__wedstrijd=wedstrijd)}
    behouden = set()

    for s in meet["sessies"]:
        sessie, _ = Sessie.objects.update_or_create(
            wedstrijd=wedstrijd, nummer=s["nummer"],
            defaults={"datum": s["datum"], "starttijd": s["starttijd"], "opwarmen": s["opwarmen"]},
        )
        for n in s["nummers"]:
            data = {k: n[k] for k in ("nummer", "volgorde", "geslacht", "afstand", "slag", "naam", "aflossing", "ronde", "inschrijfbaar", "kost_cent")}
            obj = oude_nummers.get(n["eventid"])
            if obj:
                for k, v in data.items():
                    setattr(obj, k, v)
                obj.sessie = sessie
                obj.save()
                obj.leeftijdsgroepen.all().delete()
            else:
                obj = Nummer.objects.create(sessie=sessie, eventid=n["eventid"], **data)
            behouden.add(n["eventid"])
            Leeftijdsgroep.objects.bulk_create(
                Leeftijdsgroep(nummer=obj, agemin=a, agemax=b) for a, b in n["leeftijdsgroepen"]
            )

    Nummer.objects.filter(sessie__wedstrijd=wedstrijd).exclude(eventid__in=behouden).delete()
    wedstrijd.sessies.filter(nummers__isnull=True).delete()

    wedstrijd.alle_limietlijsten.all().delete()
    for lst in meet["limietlijsten"]:
        obj = Limietlijst.objects.create(
            wedstrijd=wedstrijd, **{k: lst[k] for k in ("naam", "code", "soort", "baan", "geslacht", "agemin", "agemax")}
        )
        if lst["voorgeselecteerd"]:
            gekozen.add((obj.naam, obj.baan))
        Limiet.objects.bulk_create(Limiet(lijst=obj, **l) for l in lst["limieten"])
    # Gekozen selectiecriteria opnieuw koppelen na een update.
    if gekozen:
        wedstrijd.limietlijsten.set(
            l for l in wedstrijd.alle_limietlijsten.all() if (l.naam, l.baan) in gekozen
        )
    return wedstrijd


def bouw_inschrijvingen(wedstrijd, inschrijvingen) -> bytes:
    """Maakt een Lenex 3.0-inschrijvingsbestand (.lxf) voor de organisator."""
    club = settings.CLUB
    root = ET.Element("LENEX", version="3.0")
    ET.SubElement(root, "CONSTRUCTOR", name="KHZS inschrijvingen", registration=club["naam"], version="1.0")
    meet = ET.SubElement(ET.SubElement(root, "MEETS"), "MEET", name=wedstrijd.naam, city=wedstrijd.stad,
                         course=wedstrijd.baan, deadline=wedstrijd.deadline.isoformat(), nation="BEL")
    c = ET.SubElement(ET.SubElement(meet, "CLUBS"), "CLUB", name=club["naam"], code=club["code"], nation=club["nation"])
    atleten = ET.SubElement(c, "ATHLETES")

    per_zwemmer = {}
    for i in inschrijvingen:
        per_zwemmer.setdefault(i.zwemmer, []).append(i)
    for nr, (z, lijst) in enumerate(sorted(per_zwemmer.items(), key=lambda kv: (kv[0].naam, kv[0].voornaam)), start=1):
        a = ET.SubElement(atleten, "ATHLETE", athleteid=str(nr), firstname=z.voornaam, lastname=z.naam,
                          birthdate=z.geboortedatum.isoformat(), gender=z.geslacht, nation="BEL")
        if z.licentie:
            a.set("license", z.licentie)
        entries = ET.SubElement(a, "ENTRIES")
        for i in lijst:
            e = ET.SubElement(entries, "ENTRY", eventid=i.nummer.eventid, entrytime=format_tijd(i.inschrijftijd))
            if i.inschrijftijd is not None:
                e.set("entrycourse", i.inschrijfbaan or wedstrijd.baan)

    ET.indent(root)
    xml = b'<?xml version="1.0" encoding="UTF-8"?>\n' + ET.tostring(root, encoding="utf-8")
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr(f"Inschrijvingen {club['naam']} - {wedstrijd.naam}.lef", xml)
    return buf.getvalue()


def bestandsnaam(wedstrijd, ext):
    veilig = re.sub(r"[^A-Za-z0-9]+", "_", wedstrijd.naam).strip("_")
    return f"inschrijvingen_{veilig}_{datetime.now():%Y%m%d}.{ext}"
