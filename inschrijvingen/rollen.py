"""Rollen van accounts. Een account kan meerdere rollen hebben (bv. ouder én trainer).
Een lid vraagt een rol aan; een bestuurder, sportsecretaris of beheerder keurt goed, keurt af of geeft een andere rol.
De rechten hangen af van de rollen samen: wie trainer én ouder is, heeft de rechten van allebei."""
from django.db import transaction
from django.urls import reverse
from django.utils import timezone

from . import push
from .models import ROL_NAAM, Profiel, Rol, Rolaanvraag

VOLGORDE = [r for r, _ in Profiel.ROLLEN]  # van hoog naar laag


def gesorteerd(rollen):
    return [r for r in VOLGORDE if r in rollen]


def _opgeslagen(user) -> set:
    """De bewaarde rollen. Een account zonder rollen (bv. uit een oude backup) houdt de hoofdrol uit het profiel.
    Een lege hoofdrol betekent: nog geen rol (nieuw account dat het stappenplan nog niet doorliep)."""
    rollen = {r.rol for r in user.app_rollen.all()}
    if not rollen:
        p = getattr(user, "profiel", None)
        rollen = {p.rol} if p and p.rol else set() if p else {Profiel.ZWEMMER}
    return rollen


def rollen_van(user) -> set:
    if user is None or not user.is_authenticated:
        return set()
    cache = getattr(user, "_khzs_rollen", None)
    if cache is None:
        cache = _opgeslagen(user)
        if user.is_superuser:  # superuser in /beheer/ is altijd beheerder
            cache.add(Profiel.BEHEERDER)
        user._khzs_rollen = cache
    return cache


def heeft_rol(user, *rollen) -> bool:
    return bool(rollen_van(user) & set(rollen))


def hoofdrol(user):
    r = rollen_van(user)
    return gesorteerd(r)[0] if r else None


def _wis_cache(user):
    try:
        del user._khzs_rollen  # via delattr, zodat het ook werkt voor request.user (SimpleLazyObject)
    except AttributeError:
        pass
    if hasattr(user, "_prefetched_objects_cache"):
        user._prefetched_objects_cache.pop("app_rollen", None)


def zet_rollen(user, rollen, door=None):
    """Geeft het account precies deze rollen (ook geen) en houdt de hoofdrol in het profiel bij."""
    rollen = set(rollen) & set(VOLGORDE)
    with transaction.atomic():
        user.app_rollen.exclude(rol__in=rollen).delete()
        bestaand = set(user.app_rollen.values_list("rol", flat=True))
        Rol.objects.bulk_create([Rol(user=user, rol=r, door=door) for r in gesorteerd(rollen - bestaand)])
        p, _ = Profiel.objects.get_or_create(user=user)
        p.rol = gesorteerd(rollen)[0] if rollen else ""
        p.save(update_fields=["rol"])
    if "profiel" in user._state.fields_cache:
        user.profiel.rol = p.rol
    _wis_cache(user)


def geef_rol(user, rol, door=None):
    _wis_cache(user)
    zet_rollen(user, _opgeslagen(user) | {rol}, door)


def zorg_gezinsrol(user, zelf=False):
    """Wie een zwemmer toevoegt of koppelt, krijgt de rol zwemmer (zichzelf) of ouder, als die er nog niet is."""
    rol = Profiel.ZWEMMER if zelf else Profiel.OUDER
    if not heeft_rol(user, rol):
        geef_rol(user, rol)


def vul_ontbrekende():
    """Accounts zonder rollen (bv. na het terugzetten van een backup van vóór de rollen) krijgen hun hoofdrol."""
    zonder = Profiel.objects.exclude(user__app_rollen__isnull=False).exclude(rol="")
    Rol.objects.bulk_create([Rol(user_id=p.user_id, rol=p.rol) for p in zonder])


# ---------- rechten om rollen te beheren ----------

def mag_rollen_beheren(user) -> bool:
    return heeft_rol(user, *Profiel.ROLBEHEER)


def fout_bij_beheer(door, user, rol=None):
    """Waarom `door` de rollen van `user` (of deze rol) niet mag wijzigen, of None als het mag."""
    if not mag_rollen_beheren(door):
        return "Je mag geen rollen beheren."
    if rol is not None and rol not in VOLGORDE:
        return "Onbekende rol."
    if not heeft_rol(door, Profiel.BEHEERDER) and (rol == Profiel.BEHEERDER or heeft_rol(user, Profiel.BEHEERDER)):
        return "Enkel een beheerder kan beheerders aanstellen of wijzigen."
    return None


def ken_toe(door, user, rol):
    """Rol rechtstreeks toekennen. Een openstaande aanvraag voor die rol wordt meteen goedgekeurd."""
    fout = fout_bij_beheer(door, user, rol)
    if fout:
        raise ValueError(fout)
    open_ = user.rolaanvragen.filter(rol=rol, status=Rolaanvraag.OPEN).first()
    if open_:
        behandel(open_, door, "goedkeuren")
    else:
        geef_rol(user, rol, door)


def neem_af(door, user, rol):
    fout = fout_bij_beheer(door, user, rol)
    if fout:
        raise ValueError(fout)
    if rol == Profiel.BEHEERDER and user.is_superuser:
        raise ValueError("Dit account is superuser in /beheer/ en blijft altijd beheerder.")
    _wis_cache(user)
    over = _opgeslagen(user) - {rol}
    if not over:
        raise ValueError("Een account houdt minstens één rol. Geef eerst een andere rol.")
    zet_rollen(user, over, door)


# ---------- aanvragen ----------

def vraag_aan(user, rol, motivatie=""):
    """Zwemmer en ouder krijg je meteen (geeft None terug); de andere rollen worden een aanvraag."""
    if rol not in Profiel.AANVRAAGBAAR:
        raise ValueError("Deze rol kan je niet aanvragen.")
    if heeft_rol(user, rol):
        raise ValueError(f"Je hebt de rol {ROL_NAAM[rol].lower()} al.")
    if rol in Profiel.ZONDER_GOEDKEURING:
        geef_rol(user, rol)
        return None
    if user.rolaanvragen.filter(rol=rol, status=Rolaanvraag.OPEN).exists():
        raise ValueError(f"Je hebt de rol {ROL_NAAM[rol].lower()} al aangevraagd.")
    a = Rolaanvraag.objects.create(user=user, rol=rol, motivatie=motivatie.strip()[:300])
    ontvangers = push.users_met("push_rolaanvraag", Profiel.ROLBEHEER).exclude(pk=user.pk)
    push.stuur(ontvangers, "Nieuwe rolaanvraag", f"{naam(user)} vraagt de rol {ROL_NAAM[rol].lower()}.", reverse("gebruikers"))
    return a


def trek_in(user, aanvraag):
    if aanvraag.user_id != user.pk or aanvraag.status != Rolaanvraag.OPEN:
        raise ValueError("Deze aanvraag kan je niet meer intrekken.")
    aanvraag.status = Rolaanvraag.INGETROKKEN
    aanvraag.gelezen = True
    aanvraag.save(update_fields=["status", "gelezen"])


def behandel(aanvraag, door, actie, rol=None, reden=""):
    """actie: 'goedkeuren', 'afkeuren' (met reden) of 'andere' (een andere rol in de plaats)."""
    reden = (reden or "").strip()[:300]
    if aanvraag.status != Rolaanvraag.OPEN:
        raise ValueError("Deze aanvraag is al behandeld.")
    if actie == "goedkeuren":
        status, toegekend = Rolaanvraag.GOEDGEKEURD, aanvraag.rol
    elif actie == "afkeuren":
        if not reden:
            raise ValueError("Geef een reden waarom je de aanvraag afkeurt.")
        status, toegekend = Rolaanvraag.AFGEKEURD, ""
    elif actie == "andere":
        if not rol:
            raise ValueError("Kies welke rol je in de plaats geeft.")
        status, toegekend = (Rolaanvraag.GOEDGEKEURD if rol == aanvraag.rol else Rolaanvraag.ANDERE_ROL), rol
    else:
        raise ValueError("Onbekende actie.")
    fout = fout_bij_beheer(door, aanvraag.user, toegekend or aanvraag.rol)
    if fout:
        raise ValueError(fout)
    with transaction.atomic():
        if toegekend:
            geef_rol(aanvraag.user, toegekend, door)
        aanvraag.status, aanvraag.toegekende_rol, aanvraag.reden = status, toegekend, reden
        aanvraag.behandeld_door, aanvraag.behandeld_op, aanvraag.gelezen = door, timezone.now(), False
        aanvraag.save()
    if getattr(getattr(aanvraag.user, "profiel", None), "push_beslissing", True):
        push.stuur([aanvraag.user], f"Rolaanvraag {aanvraag.get_status_display().lower()}", beslissing(aanvraag),
                   reverse("mijn_rollen"))
    return aanvraag


def beslissing(a) -> str:
    """De tekst van de melding voor het lid."""
    gevraagd = ROL_NAAM.get(a.rol, a.rol).lower()
    if a.status == Rolaanvraag.GOEDGEKEURD:
        tekst = f"Je aanvraag voor de rol {gevraagd} is goedgekeurd."
    elif a.status == Rolaanvraag.AFGEKEURD:
        tekst = f"Je aanvraag voor de rol {gevraagd} is afgekeurd."
    elif a.status == Rolaanvraag.ANDERE_ROL:
        tekst = f"Je vroeg de rol {gevraagd} en kreeg de rol {ROL_NAAM.get(a.toegekende_rol, '').lower()}."
    else:
        return a.get_status_display()
    return f"{tekst} {a.reden}".strip() if a.reden else tekst


def naam(user):
    return user.get_full_name() or user.username
