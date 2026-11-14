"""Ploegen (A-groep, B-groep, masters…). Een zwemmer zit in één of meer ploegen. De zwemmer of ouder vraagt een ploeg
aan; een trainer van die ploeg, een bestuurder, sportsecretaris of beheerder keurt goed of af.

Dit module bepaalt ook wie bij een ploeg hoort (accounts_van, lezers_van), zodat een latere communicatiemodule
iedereen enkel de berichten van zijn eigen ploegen kan tonen. Trainers van de competitie, bestuurders,
sportsecretarissen en beheerders zien alle ploegen; een trainer van de zwemschool ziet de ploegen van de zwemschool en
de ploegen die hij of zij traint."""
from django.contrib.auth.models import User
from django.db.models import Q
from django.urls import reverse
from django.utils import timezone

from . import push
from .models import PloegLid, Ploeg, Profiel, Zwemmer
from .rollen import heeft_rol, naam


def mag_ploegen_beheren(user) -> bool:
    return heeft_rol(user, *Profiel.PLOEGBEHEER)


def ziet_alle_ploegen(user) -> bool:
    return heeft_rol(user, *Profiel.ALLE_PLOEGEN)


def ziet_zwemschoolploegen(user) -> bool:
    return heeft_rol(user, Profiel.TRAINER_ZWEMSCHOOL)


def ziet_ploegenmenu(user) -> bool:
    """Het menu Ploegen: wie meer ploegen ziet dan die van de eigen zwemmers."""
    return ziet_alle_ploegen(user) or ziet_zwemschoolploegen(user)


def mag_behandelen(user, ploeg) -> bool:
    """Aanvragen voor deze ploeg goedkeuren of afkeuren, en leden toevoegen of verwijderen."""
    return mag_ploegen_beheren(user) or ploeg.trainers.filter(pk=user.pk).exists()


# ---------- wie hoort waarbij ----------

def zwemmers_van(ploeg):
    return Zwemmer.objects.filter(ploeglidmaatschappen__ploeg=ploeg, ploeglidmaatschappen__status=PloegLid.GOEDGEKEURD)


def accounts_van(ploeg):
    """De accounts van de zwemmers van deze ploeg (ook gekoppelde ouders) en de trainers van de ploeg."""
    zids = zwemmers_van(ploeg).values("pk")
    return User.objects.filter(Q(zwemmers__in=zids) | Q(gedeelde_zwemmers__in=zids) | Q(getrainde_ploegen=ploeg),
                               is_active=True).distinct()


def lezers_van(ploeg):
    """Iedereen die deze ploeg (en later haar kanaal) mag zien: de accounts van de ploeg, plus trainers, bestuurders,
    sportsecretarissen en beheerders, die alle ploegen zien."""
    alle = Q(app_rollen__rol__in=Profiel.ALLE_PLOEGEN) | Q(is_superuser=True)
    if ploeg.soort == Ploeg.ZWEMSCHOOL:
        alle |= Q(app_rollen__rol=Profiel.TRAINER_ZWEMSCHOOL)
    return User.objects.filter(Q(pk__in=accounts_van(ploeg).values("pk")) | alle, is_active=True).distinct()


def eigen_ploegen(user):
    """Ploegen waar een zwemmer van dit account lid van is, of die dit account traint."""
    mijn = Zwemmer.objects.van(user).values("pk")
    return Ploeg.objects.filter(Q(lidmaatschappen__zwemmer__in=mijn, lidmaatschappen__status=PloegLid.GOEDGEKEURD)
                                | Q(trainers=user)).distinct()


def zichtbare_ploegen(user):
    if ziet_alle_ploegen(user):
        return Ploeg.objects.all()
    if ziet_zwemschoolploegen(user):
        return Ploeg.objects.filter(Q(pk__in=eigen_ploegen(user).values("pk")) | Q(soort=Ploeg.ZWEMSCHOOL))
    return eigen_ploegen(user)


def mag_zien(user, ploeg) -> bool:
    return (ziet_alle_ploegen(user) or (ziet_zwemschoolploegen(user) and ploeg.soort == Ploeg.ZWEMSCHOOL)
            or eigen_ploegen(user).filter(pk=ploeg.pk).exists())


def te_behandelen(user):
    """Open aanvragen die dit account mag behandelen."""
    qs = PloegLid.objects.filter(status=PloegLid.OPEN).select_related("zwemmer", "ploeg", "aangevraagd_door")
    return qs if mag_ploegen_beheren(user) else qs.filter(ploeg__trainers=user)


# ---------- aanvragen en beslissen ----------

def vraag_aan(user, zwemmer, ploeg):
    """Vraagt een ploeg aan voor deze zwemmer. Wie de ploeg mag behandelen (staf), zet de zwemmer er meteen in.
    Geeft het lidmaatschap terug."""
    if not zwemmer.heeft_account(user) and not mag_behandelen(user, ploeg):
        raise ValueError("Je kan enkel voor je eigen zwemmers een ploeg kiezen.")
    if not ploeg.actief:
        raise ValueError("Deze ploeg is gearchiveerd.")
    lid = PloegLid.objects.filter(zwemmer=zwemmer, ploeg=ploeg).first()
    if lid and lid.status == PloegLid.GOEDGEKEURD:
        raise ValueError(f"{zwemmer.voornaam} zit al in {ploeg}.")
    if lid and lid.status == PloegLid.OPEN:
        raise ValueError(f"{zwemmer.voornaam} wacht al op goedkeuring voor {ploeg}.")
    lid = lid or PloegLid(zwemmer=zwemmer, ploeg=ploeg)
    lid.aangevraagd_door, lid.reden, lid.gelezen = user, "", False
    if mag_behandelen(user, ploeg):
        lid.status, lid.behandeld_door, lid.behandeld_op = PloegLid.GOEDGEKEURD, user, timezone.now()
        lid.gelezen = zwemmer.heeft_account(user)  # eigen zwemmer: geen melding nodig
        lid.save()
        return lid
    lid.status, lid.behandeld_door, lid.behandeld_op = PloegLid.OPEN, None, None
    lid.save()
    ontvangers = push.users_met("push_ploegaanvraag").filter(
        Q(app_rollen__rol__in=Profiel.PLOEGBEHEER) | Q(is_superuser=True) | Q(getrainde_ploegen=ploeg)).exclude(pk=user.pk)
    push.stuur(ontvangers.distinct(), "Nieuwe ploegaanvraag", f"{naam(user)} vraagt {ploeg} voor {zwemmer}.",
               reverse("ploeg", args=[ploeg.pk]))
    return lid


def behandel(lid, door, actie, reden=""):
    """actie: 'goedkeuren' of 'afkeuren' (met reden)."""
    reden = (reden or "").strip()[:300]
    if not mag_behandelen(door, lid.ploeg):
        raise ValueError("Je mag aanvragen voor deze ploeg niet behandelen.")
    if lid.status != PloegLid.OPEN:
        raise ValueError("Deze aanvraag is al behandeld.")
    if actie == "goedkeuren":
        lid.status = PloegLid.GOEDGEKEURD
    elif actie == "afkeuren":
        if not reden:
            raise ValueError("Geef een reden waarom je de aanvraag afkeurt.")
        lid.status = PloegLid.AFGEKEURD
    else:
        raise ValueError("Onbekende actie.")
    lid.reden, lid.behandeld_door, lid.behandeld_op, lid.gelezen = reden, door, timezone.now(), False
    lid.save()
    ontvangers = [u for u in lid.zwemmer.accounts if getattr(getattr(u, "profiel", None), "push_beslissing", True)]
    push.stuur(ontvangers, f"Ploeg {lid.ploeg}", beslissing(lid), reverse("zwemmer", args=[lid.zwemmer_id]))
    return lid


def verwijder(lid, door):
    """Uit de ploeg halen of een aanvraag intrekken: door een account van de zwemmer of door wie de ploeg behandelt."""
    if not lid.zwemmer.heeft_account(door) and not mag_behandelen(door, lid.ploeg):
        raise ValueError("Je mag dit lidmaatschap niet wijzigen.")
    lid.delete()


def beslissing(lid) -> str:
    """De tekst van de melding voor de accounts van de zwemmer."""
    if lid.status == PloegLid.GOEDGEKEURD:
        return f"{lid.zwemmer.voornaam} zit nu in de ploeg {lid.ploeg}."
    if lid.status == PloegLid.AFGEKEURD:
        tekst = f"De aanvraag van {lid.zwemmer.voornaam} voor de ploeg {lid.ploeg} is afgekeurd."
        return f"{tekst} {lid.reden}" if lid.reden else tekst
    return f"{lid.zwemmer.voornaam} wacht op goedkeuring voor de ploeg {lid.ploeg}."


def ongelezen_beslissingen(user):
    """Beslissingen over ploegen van de eigen zwemmers die nog niet gezien zijn (melding bovenaan in de app)."""
    return list(PloegLid.objects.filter(zwemmer__in=Zwemmer.objects.van(user).values("pk"), gelezen=False)
                .exclude(status=PloegLid.OPEN).select_related("zwemmer", "ploeg"))
