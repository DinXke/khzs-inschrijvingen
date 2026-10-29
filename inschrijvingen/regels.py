"""Bedrijfsregels: leeftijd, geschiktheid, limieten en deadlines."""
from dataclasses import dataclass
from datetime import date, timedelta

from django.utils import timezone

from .models import Profiel, Wedstrijd


def vandaag():
    return timezone.localdate()


def leeftijd(zwemmer, wedstrijd) -> int:
    """Leeftijd volgens de AGEDATE van de wedstrijd. Type YEAR = geboortejaar telt."""
    ref = wedstrijd.agedate or wedstrijd.startdatum or vandaag()
    gd = zwemmer.geboortedatum
    if wedstrijd.agedate_type == "YEAR":
        return ref.year - gd.year
    return ref.year - gd.year - ((ref.month, ref.day) < (gd.month, gd.day))


def past_in_nummer(zwemmer, nummer, jaren) -> bool:
    if nummer.geslacht not in ("X", zwemmer.geslacht):
        return False
    return any(g.bevat(jaren) for g in nummer.leeftijdsgroepen.all())


@dataclass
class Beoordeling:
    nummer: object
    limiet_nodig: bool
    limiet_gehaald: bool
    limieten: list            # [(lijstnaam, baan, tijd)]
    beste: object             # BesteTijd die als inschrijftijd dient, of None

    @property
    def mag_inschrijven(self):
        return not self.limiet_nodig or self.limiet_gehaald


def _geldige_tijden(zwemmer, wedstrijd, afstand, slag):
    tijden = [t for t in zwemmer.tijden.all() if t.afstand == afstand and t.slag == slag]
    if wedstrijd.kwalificatie_van:
        tijden = [t for t in tijden if t.datum >= wedstrijd.kwalificatie_van]
    if wedstrijd.kwalificatie_tot:
        tijden = [t for t in tijden if t.datum <= wedstrijd.kwalificatie_tot]
    return tijden


def beoordeel(zwemmer, nummer, wedstrijd, jaren, lijsten) -> Beoordeling:
    """lijsten = de door de secretaris gekozen limietlijsten (met limieten geprefetcht)."""
    tijden = _geldige_tijden(zwemmer, wedstrijd, nummer.afstand, nummer.slag)
    limieten = []
    for lst in lijsten:
        if lst.geslacht not in ("X", zwemmer.geslacht):
            continue
        if not ((lst.agemin == -1 or jaren >= lst.agemin) and (lst.agemax == -1 or jaren <= lst.agemax)):
            continue
        for l in lst.limieten.all():
            if l.afstand == nummer.afstand and l.slag == nummer.slag:
                limieten.append((lst.naam, lst.baan, l.tijd, lst.soort == "MINIMUM"))

    gehaald = any(
        t.baan == baan and (t.tijd >= grens if minimum else t.tijd <= grens)
        for _, baan, grens, minimum in limieten for t in tijden
    )

    # Inschrijftijd: beste tijd in de baan van de wedstrijd, anders beste tijd in de andere baan.
    zelfde = sorted((t for t in tijden if t.baan == wedstrijd.baan), key=lambda t: t.tijd)
    andere = sorted((t for t in tijden if t.baan != wedstrijd.baan), key=lambda t: t.tijd)
    beste = (zelfde or andere or [None])[0]
    return Beoordeling(nummer, bool(limieten), gehaald, limieten, beste)


def programma_voor(zwemmer, wedstrijd):
    """Alle inschrijfbare nummers waarvoor de zwemmer qua geslacht/leeftijd in aanmerking komt."""
    jaren = leeftijd(zwemmer, wedstrijd)
    lijsten = list(wedstrijd.limietlijsten.prefetch_related("limieten"))
    resultaat = []
    for sessie in wedstrijd.sessies.prefetch_related("nummers__leeftijdsgroepen"):
        rijen = [
            beoordeel(zwemmer, n, wedstrijd, jaren, lijsten)
            for n in sessie.nummers.all()
            if n.inschrijfbaar and n.aflossing == 1 and past_in_nummer(zwemmer, n, jaren)
        ]
        if rijen:
            resultaat.append((sessie, rijen))
    return jaren, resultaat


def rol(user):
    if not user.is_authenticated:
        return None
    if user.is_superuser:
        return Profiel.SECRETARIS
    p = getattr(user, "profiel", None)
    return p.rol if p else Profiel.ZWEMMER


def mag_wijzigen(user, wedstrijd, dag: date | None = None) -> bool:
    """Mag deze gebruiker nu nog inschrijvingen toevoegen/schrappen voor deze wedstrijd?"""
    dag = dag or vandaag()
    r = rol(user)
    if r == Profiel.SECRETARIS:
        return wedstrijd.status in (Wedstrijd.OPEN, Wedstrijd.GESLOTEN)
    if wedstrijd.status != Wedstrijd.OPEN:
        return False
    if r == Profiel.TRAINER:
        return dag <= wedstrijd.deadline_trainer
    return dag <= wedstrijd.deadline_zwemmer


def dagen_tot(d: date) -> int:
    return (d - vandaag()).days


def parse_invoer_tijd(s: str) -> timedelta:
    """Aanvaardt '1:02.86', '62.86', '1.02.86', '01:02,86'."""
    s = s.strip().replace(",", ".")
    delen = s.replace(":", ".").split(".")
    if not all(p.isdigit() for p in delen) or not 2 <= len(delen) <= 3:
        raise ValueError("Gebruik het formaat m:ss.hh, bv. 1:02.86")
    if len(delen) == 2:
        m, sec, hon = 0, int(delen[0]), delen[1]
    else:
        m, sec, hon = int(delen[0]), int(delen[1]), delen[2]
    if sec >= 60 and m:
        raise ValueError("Seconden moeten kleiner dan 60 zijn.")
    return timedelta(minutes=m, seconds=sec, milliseconds=int(hon.ljust(2, "0")[:2]) * 10)


def toon_tijd(td) -> str:
    if td is None:
        return "NT"
    hon = round(td.total_seconds() * 100)
    m, rest = divmod(hon, 6000)
    s, h = divmod(rest, 100)
    return f"{m}:{s:02d}.{h:02d}" if m else f"{s}.{h:02d}"
