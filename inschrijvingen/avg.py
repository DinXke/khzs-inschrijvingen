"""AVG (GDPR): privacyverklaring, akkoord, mijn gegevens downloaden, account verwijderen en de automatische opkuis.

De tekst van de privacyverklaring staat in templates/privacy.html; de gegevens van de club komen uit settings.PRIVACY.
Zelfde werking als wordpress/khzs-leden/includes/avg.php.
"""
from datetime import timedelta

from django.conf import settings
from django.db import transaction
from django.db.models import Q
from django.utils import timezone

from .regels import toon_tijd, vandaag

# Bewaartermijnen voor de automatische opkuis (manage.py opkuis, dagelijks via de herinneringen).
# De rest (zwemmers, tijden, inschrijvingen…) blijft zolang het account of de zwemmer bestaat: zie de privacyverklaring.
MELDINGEN_DAGEN = 365         # wijzigingen door de club aan een inschrijving
AANVRAGEN_DAGEN = 365         # behandelde rolaanvragen en afgekeurde ploegaanvragen
PUSH_ONGEBRUIKT_DAGEN = 365   # pushabonnementen van accounts die zo lang niet aanmeldden
HERINNERINGEN_DAGEN = 365     # logboek van verstuurde deadline-herinneringen
MEERDERJARIG = 18


def instellingen():
    return settings.PRIVACY


def versie():
    return settings.PRIVACY["versie"]


def minderjarig(geboortedatum, op=None) -> bool:
    if not geboortedatum:
        return False
    d = op or vandaag()
    leeftijd = d.year - geboortedatum.year - ((d.month, d.day) < (geboortedatum.month, geboortedatum.day))
    return leeftijd < MEERDERJARIG


# ---------- akkoord ----------

def akkoord(user):
    """Bewaart dat het account de huidige versie van de privacyverklaring aanvaardt."""
    from .models import Profiel
    p, _ = Profiel.objects.get_or_create(user=user)
    p.privacy_akkoord, p.privacy_versie = timezone.now(), versie()
    p.save(update_fields=["privacy_akkoord", "privacy_versie"])


def moet_lezen(user) -> bool:
    """Heeft dit account de huidige versie nog niet aanvaard (nieuw sinds de AVG-functies, of een nieuwe versie)?"""
    if not user.is_authenticated:
        return False
    p = getattr(user, "profiel", None)
    return p is None or p.privacy_versie != versie()


def toestemming(zwemmer, user):
    """Toestemming van de ouder of voogd voor een minderjarige zwemmer bewaren."""
    zwemmer.toestemming_op, zwemmer.toestemming_door = timezone.now(), user
    zwemmer.save(update_fields=["toestemming_op", "toestemming_door"])


# ---------- inzage en overdraagbaarheid ----------

def _dt(d):
    return d.isoformat() if d else None


def mijn_gegevens(user) -> dict:
    """Alle persoonsgegevens van dit account en zijn zwemmers, als JSON-baar woordenboek (recht op inzage en overdraagbaarheid)."""
    from .models import Profiel, Zwemmer
    p = getattr(user, "profiel", None)
    voorkeuren = {f.verbose_name: getattr(p, f.name) for f in Profiel._meta.fields if f.name.startswith("push_")} if p else {}
    zwemmers = []
    for z in Zwemmer.objects.van(user).prefetch_related("extra_accounts", "tijden", "ploeglidmaatschappen__ploeg"):
        zwemmers.append({
            "voornaam": z.voornaam, "naam": z.naam, "geboortedatum": _dt(z.geboortedatum), "geslacht": z.get_geslacht_display(),
            "licentienummer": z.licentie, "swimrankings_id": z.swimrankings_id,
            "accounts": [u.get_full_name() or u.username for u in z.accounts],
            "toestemming_ouder_op": _dt(z.toestemming_op),
            "ploegen": [{"ploeg": l.ploeg.naam, "status": l.get_status_display(), "aangevraagd": _dt(l.aangemaakt), "reden": l.reden}
                        for l in z.ploeglidmaatschappen.all()],
            "beste_tijden": [{"nummer": f"{t.afstand} m {t.get_slag_display().lower()}", "baan": t.get_baan_display(),
                              "tijd": toon_tijd(t.tijd), "datum": _dt(t.datum), "wedstrijd": t.wedstrijd, "bron": t.bron}
                             for t in z.tijden.all()],
            "deelnames": [{"wedstrijd": d.wedstrijd.naam, "keuze": d.get_keuze_display(), "geweigerd": d.geweigerd,
                           "reden": d.weigerreden} for d in z.deelnames.select_related("wedstrijd")],
            "inschrijvingen": [{"wedstrijd": i.nummer.sessie.wedstrijd.naam, "nummer": str(i.nummer),
                                "inschrijftijd": toon_tijd(i.inschrijftijd) if i.inschrijftijd else None,
                                "status": i.get_status_display(), "opmerking": i.opmerking, "aangemaakt": _dt(i.aangemaakt)}
                               for i in z.inschrijvingen.select_related("nummer__sessie__wedstrijd")],
            "meldingen": [{"wedstrijd": m.wedstrijd.naam, "tekst": m.tekst, "door": m.door_naam, "op": _dt(m.aangemaakt)}
                          for m in z.meldingen.select_related("wedstrijd", "door")],
        })
    return {
        "uitgevoerd_op": timezone.now().isoformat(),
        "verwerkingsverantwoordelijke": instellingen()["verantwoordelijke"],
        "account": {"gebruikersnaam": user.username, "e-mail": user.email, "voornaam": user.first_name, "naam": user.last_name,
                    "aangemaakt": _dt(user.date_joined), "laatst_aangemeld": _dt(user.last_login)},
        "privacyverklaring": {"aanvaard_op": _dt(p.privacy_akkoord) if p else None, "versie": p.privacy_versie if p else ""},
        "rollen": [{"rol": r.get_rol_display(), "sinds": _dt(r.sinds)} for r in user.app_rollen.all()],
        "rolaanvragen": [{"rol": a.get_rol_display(), "motivatie": a.motivatie, "status": a.get_status_display(),
                          "reden": a.reden, "aangemaakt": _dt(a.aangemaakt)} for a in user.rolaanvragen.all()],
        "meldingsvoorkeuren": voorkeuren,
        "toestellen_met_pushmeldingen": [{"toestel": a.toestel, "aangemaakt": _dt(a.aangemaakt)} for a in user.push_abonnementen.all()],
        "trainer_van_ploegen": [pl.naam for pl in user.getrainde_ploegen.all()],
        "zwemmers": zwemmers,
    }


# ---------- account verwijderen ----------

def kan_niet_verwijderen(user):
    """Reden waarom dit account zichzelf niet kan verwijderen, of None."""
    if user.is_superuser or user.is_staff:
        return "Een account met toegang tot /beheer/ verwijder je niet zelf. Vraag het aan een andere beheerder."
    return None


def gevolgen(user):
    """(zwemmers die mee verdwijnen, zwemmers die bij een ander account blijven)."""
    from .models import Zwemmer
    weg, blijft = [], []
    for z in Zwemmer.objects.van(user).prefetch_related("extra_accounts"):
        (blijft if len(z.accounts) > 1 else weg).append(z)
    return weg, blijft


def verwijder_account(user):
    """Verwijdert het account met alles wat eraan hangt. Gedeelde zwemmers blijven bij het andere account
    (Zwemmer.account: on_delete=account_verwijderd); wijzigingen en beslissingen van dit account blijven zonder naam."""
    reden = kan_niet_verwijderen(user)
    if reden:
        raise ValueError(reden)
    with transaction.atomic():
        user.delete()


# ---------- opkuis ----------

def opkuis(nu=None) -> dict:
    """Wist gegevens waarvan de bewaartermijn voorbij is. Geeft per soort het aantal gewiste rijen."""
    from django.contrib.sessions.models import Session

    from .models import Melding, PloegLid, PushAbonnement, Rolaanvraag, VerstuurdeHerinnering, Zwemmer
    nu = nu or timezone.now()
    dagen = lambda n: nu - timedelta(days=n)  # noqa: E731
    n = {}
    n["koppelcodes"] = Zwemmer.objects.exclude(koppelcode="").filter(koppelcode_tot__lt=nu).update(koppelcode="", koppelcode_tot=None)
    n["meldingen"] = Melding.objects.filter(aangemaakt__lt=dagen(MELDINGEN_DAGEN)).delete()[0]
    grens = dagen(AANVRAGEN_DAGEN)
    n["rolaanvragen"] = Rolaanvraag.objects.exclude(status=Rolaanvraag.OPEN).filter(
        Q(behandeld_op__lt=grens) | Q(behandeld_op__isnull=True, aangemaakt__lt=grens)).delete()[0]
    n["ploegaanvragen"] = PloegLid.objects.filter(status=PloegLid.AFGEKEURD).filter(
        Q(behandeld_op__lt=grens) | Q(behandeld_op__isnull=True, aangemaakt__lt=grens)).delete()[0]
    grens = dagen(PUSH_ONGEBRUIKT_DAGEN)
    n["pushabonnementen"] = PushAbonnement.objects.filter(
        Q(user__last_login__lt=grens) | Q(user__last_login__isnull=True, aangemaakt__lt=grens)).delete()[0]
    n["herinneringen"] = VerstuurdeHerinnering.objects.filter(datum__lt=dagen(HERINNERINGEN_DAGEN).date()).delete()[0]
    n["sessies"] = Session.objects.filter(expire_date__lt=nu).delete()[0]
    return n
