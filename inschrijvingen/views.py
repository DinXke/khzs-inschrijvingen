from collections import defaultdict
from functools import wraps

from django.contrib import messages
from django.contrib.auth import login
from django.contrib.auth.decorators import login_required
from django.contrib.auth.models import User
from django.core.exceptions import PermissionDenied
from django.db.models import Count, Min, Q
from django.http import HttpResponse
from django.shortcuts import get_object_or_404, redirect, render
from django.utils import timezone
from django.views.decorators.http import require_POST

import json
import zipfile
from datetime import datetime

from django.http import FileResponse, Http404

from django.http import JsonResponse
from django.urls import reverse

from . import lenex, push
from .export import excel_overzicht
from .forms import (BesteTijdForm, BijlageForm, MeldingenForm, ZwemmerFormSet, RegistratieForm, RolForm, SecretarisInstellingenForm,
                    TrainerInstellingenForm, UploadForm, ZwemmerForm)
from .models import BesteTijd, Bijlage, Deelname, Inschrijving, Melding, Nummer, Profiel, PushAbonnement, Wedstrijd, Zwemmer
from .regels import (vandaag, beoordeel, leeftijd, mag_wijzigen, parse_invoer_tijd, past_in_nummer, programma_voor, rol,
                     toon_tijd)

STAF = Profiel.STAF


def vereist_rol(*rollen):
    def deco(view):
        @wraps(view)
        @login_required
        def inner(request, *a, **kw):
            if rol(request.user) not in rollen:
                raise PermissionDenied
            return view(request, *a, **kw)
        return inner
    return deco


def is_staf(user):
    return rol(user) in STAF


def meld(zwemmer, wedstrijd, door, regels):
    Melding.objects.create(zwemmer=zwemmer, wedstrijd=wedstrijd, door=door, tekst="\n".join(regels))
    if getattr(getattr(zwemmer.account, "profiel", None), "push_wijziging", False):
        push.stuur([zwemmer.account], f"Inschrijving {zwemmer.voornaam} gewijzigd",
                   f"{wedstrijd.naam}: " + "; ".join(regels)[:180],
                   f"{reverse('wedstrijd', args=[wedstrijd.pk])}?zwemmer={zwemmer.pk}")


def markeer_gewijzigd(zwemmer, wedstrijd):
    """Na een wijziging: was de zwemmer al ingegeven door het secretariaat, dan moet die het opnieuw nakijken."""
    Deelname.objects.filter(zwemmer=zwemmer, wedstrijd=wedstrijd, ingegeven=True).update(
        ingegeven=False, gewijzigd_na_ingave=True)


def zwemmer_voor(request, pk):
    """Een zwemmer die deze gebruiker mag zien: eigen zwemmers, of alle voor staf."""
    qs = Zwemmer.objects.all() if is_staf(request.user) else request.user.zwemmers.all()
    return get_object_or_404(qs, pk=pk)


# ---------- algemeen ----------

def registreren(request):
    if request.user.is_authenticated:
        return redirect("start")
    form = RegistratieForm(request.POST or None)
    zwemmers = ZwemmerFormSet(request.POST or None, prefix="zw")
    if request.method == "POST" and form.is_valid() and zwemmers.is_valid():
        user = form.save()
        for zf in zwemmers:
            if zf.has_changed():
                z = zf.save(commit=False)
                z.account = user
                z.save()
        login(request, user, backend="django.contrib.auth.backends.ModelBackend")
        n = user.zwemmers.count()
        messages.success(request, f"Account aangemaakt met {n} zwemmer{'s' if n != 1 else ''}. Kies een wedstrijd om in te schrijven.")
        return redirect("wedstrijden")
    return render(request, "registration/registreren.html", {"form": form, "zwemmers": zwemmers})


@login_required
def start(request):
    return redirect("wedstrijden")


@login_required
def wedstrijden(request):
    r = rol(request.user)
    qs = Wedstrijd.objects.all()
    if r not in STAF:
        # Zwemmers zien een wedstrijd zodra hij opgeladen is (interesse opgeven), tot de laatste wedstrijddag.
        qs = qs.exclude(sessies__datum__lt=vandaag()).exclude(sessies__isnull=True).distinct()
    qs = qs.annotate(
        aantal=Count("sessies__nummers__inschrijvingen", distinct=True),
        te_keuren=Count("sessies__nummers__inschrijvingen",
                        filter=Q(sessies__nummers__inschrijvingen__status=Inschrijving.INGEDIEND), distinct=True),
    )
    eigen, nieuw, keuzes = defaultdict(int), defaultdict(int), defaultdict(list)
    if r not in STAF:
        for d in Deelname.objects.filter(zwemmer__account=request.user).select_related("zwemmer"):
            keuzes[d.wedstrijd_id].append(d)
        for i in Inschrijving.objects.filter(zwemmer__account=request.user).select_related("nummer__sessie"):
            eigen[i.nummer.sessie.wedstrijd_id] += 1
        for m in Melding.objects.filter(zwemmer__account=request.user, gelezen=False):
            nieuw[m.wedstrijd_id] += 1
    qs = qs.annotate(start=Min("sessies__datum"))
    nu = vandaag()
    # Komende wedstrijden eerst (vroegste bovenaan), daarna de voorbije (meest recente bovenaan).
    gesorteerd = sorted(qs, key=lambda w: (0, w.start.toordinal()) if w.start and w.start >= nu
                        else (1, -(w.start or nu).toordinal()))
    lijst = [(w, eigen.get(w.id, 0), nieuw.get(w.id, 0), keuzes.get(w.id, [])) for w in gesorteerd]
    return render(request, "wedstrijden.html", {"lijst": lijst})


# ---------- zwemmers & tijden ----------

@login_required
def mijn_zwemmers(request):
    if is_staf(request.user):
        q = request.GET.get("q", "").strip()
        zwemmers = Zwemmer.objects.select_related("account")
        if q:
            zwemmers = zwemmers.filter(Q(naam__icontains=q) | Q(voornaam__icontains=q))
        return render(request, "zwemmers.html", {"zwemmers": zwemmers, "q": q, "alle": True})
    return render(request, "zwemmers.html", {"zwemmers": request.user.zwemmers.all()})


@login_required
def zwemmer_nieuw(request):
    form = ZwemmerForm(request.POST or None)
    if request.method == "POST" and form.is_valid():
        z = form.save(commit=False)
        z.account = request.user
        z.save()
        messages.success(request, f"{z} toegevoegd.")
        return redirect("zwemmer", pk=z.pk)
    return render(request, "zwemmer_form.html", {"form": form, "titel": "Zwemmer toevoegen"})


@login_required
def zwemmer(request, pk):
    z = zwemmer_voor(request, pk)
    form = ZwemmerForm(request.POST or None, instance=z, prefix="z")
    tijdform = BesteTijdForm(prefix="t", initial={"baan": "SCM"})
    if request.method == "POST":
        if "z-voornaam" in request.POST and form.is_valid():
            form.save()
            messages.success(request, "Gegevens bewaard.")
            return redirect("zwemmer", pk=z.pk)
        if "t-tijd" in request.POST and is_staf(request.user):
            tijdform = BesteTijdForm(request.POST, prefix="t")
            if tijdform.is_valid():
                t = tijdform.save(commit=False)
                t.zwemmer = z
                t.save()
                messages.success(request, "Tijd toegevoegd.")
                return redirect("zwemmer", pk=z.pk)
    inschrijvingen = z.inschrijvingen.select_related("nummer__sessie__wedstrijd").order_by("-nummer__sessie__datum")
    meldingen = z.meldingen.select_related("wedstrijd", "door__profiel")[:10]
    return render(request, "zwemmer.html", {"z": z, "form": form, "tijdform": tijdform, "meldingen": meldingen,
                                            "tijden": z.tijden.all(), "inschrijvingen": inschrijvingen})


@login_required
@require_POST
def tijd_verwijderen(request, pk):
    t = get_object_or_404(BesteTijd, pk=pk)
    if not is_staf(request.user):
        raise PermissionDenied
    t.delete()
    messages.success(request, "Tijd verwijderd.")
    return redirect("zwemmer", pk=t.zwemmer_id)


# ---------- inschrijven ----------

@login_required
def wedstrijd(request, pk):
    w = get_object_or_404(Wedstrijd, pk=pk)
    staf = is_staf(request.user)

    zwemmers = list(Zwemmer.objects.all() if staf else request.user.zwemmers.all())
    gekozen = None
    if request.GET.get("zwemmer"):
        gekozen = zwemmer_voor(request, request.GET["zwemmer"])
    elif len(zwemmers) == 1 and not staf:
        gekozen = zwemmers[0]

    ctx = {"w": w, "zwemmers": zwemmers, "gekozen": gekozen, "staf": staf,
           "mag_wijzigen": mag_wijzigen(request.user, w)}
    if gekozen:
        jaren, programma = programma_voor(gekozen, w)
        bestaande = {i.nummer_id: i for i in gekozen.inschrijvingen.filter(nummer__sessie__wedstrijd=w)}
        meldingen = list(gekozen.meldingen.filter(wedstrijd=w).select_related("door__profiel")[:10])
        deelname = gekozen.deelnames.filter(wedstrijd=w).first()
        ctx.update(deelname=deelname, keuzes=Deelname.KEUZES)
        ctx.update(jaren=jaren, programma=programma, bestaande=bestaande, meldingen=meldingen,
                   ongelezen=sum(not m.gelezen for m in meldingen),
                   totaal=sum(i.nummer.kost for i in bestaande.values()))
        if gekozen.account_id == request.user.id:
            gekozen.meldingen.filter(wedstrijd=w, gelezen=False).update(gelezen=True)
    return render(request, "wedstrijd.html", ctx)


@login_required
@require_POST
def inschrijven(request, pk):
    """Zet de inschrijvingen van één zwemmer voor deze wedstrijd gelijk aan de aangevinkte nummers."""
    w = get_object_or_404(Wedstrijd, pk=pk)
    z = zwemmer_voor(request, request.POST.get("zwemmer"))
    if not mag_wijzigen(request.user, w):
        messages.error(request, "De inschrijvingen voor deze wedstrijd zijn afgesloten.")
        return redirect(f"/wedstrijd/{w.pk}/?zwemmer={z.pk}")

    staf = is_staf(request.user)
    if not staf and Deelname.objects.filter(zwemmer=z, wedstrijd=w, geweigerd=True).exists():
        messages.error(request, "Je deelname aan deze wedstrijd werd geweigerd door de club.")
        return redirect(f"/wedstrijd/{w.pk}/?zwemmer={z.pk}")
    if not staf and Deelname.objects.filter(zwemmer=z, wedstrijd=w, gefinaliseerd=True).exists():
        messages.error(request, "Je trainer heeft je inschrijving gefinaliseerd. Wijzigen kan enkel nog via je trainer.")
        return redirect(f"/wedstrijd/{w.pk}/?zwemmer={z.pk}")
    jaren = leeftijd(z, w)
    lijsten = list(w.limietlijsten.prefetch_related("limieten"))
    gevraagd = set(map(int, request.POST.getlist("nummer")))
    bestaande = {i.nummer_id: i for i in z.inschrijvingen.filter(nummer__sessie__wedstrijd=w)}
    nummers = Nummer.objects.filter(sessie__wedstrijd=w, pk__in=gevraagd).prefetch_related("leeftijdsgroepen")

    toegevoegd, geweigerd, log = 0, [], []

    # Staf kan per inschrijving de inschrijftijd aanpassen (veld tijd_<nummer>).
    if staf:
        for nid, i in bestaande.items():
            invoer = request.POST.get(f"tijd_{nid}")
            if invoer is None or nid not in gevraagd:
                continue
            try:
                nieuw = parse_invoer_tijd(invoer) if invoer.strip() and invoer.strip().upper() != "NT" else None
            except ValueError:
                geweigerd.append(f"#{i.nummer.nummer} (ongeldige tijd '{invoer}')")
                continue
            if nieuw != i.inschrijftijd:
                log.append(f"inschrijftijd #{i.nummer.nummer} {i.nummer.omschrijving}: {toon_tijd(i.inschrijftijd)} → {toon_tijd(nieuw)}")
                i.inschrijftijd = nieuw
                i.inschrijfbaan = i.inschrijfbaan or w.baan
                i.save(update_fields=["inschrijftijd", "inschrijfbaan"])

    for n in nummers:
        if n.pk in bestaande:
            continue
        if not (n.inschrijfbaar and n.aflossing == 1 and past_in_nummer(z, n, jaren)):
            geweigerd.append(f"#{n.nummer}")
            continue
        b = beoordeel(z, n, w, jaren, lijsten)
        if not b.mag_inschrijven and not staf:
            geweigerd.append(f"#{n.nummer} (limiet niet gehaald)")
            continue
        Inschrijving.objects.create(
            zwemmer=z, nummer=n, door=request.user,
            inschrijftijd=b.beste.tijd if b.beste else None,
            inschrijfbaan=b.beste.baan if b.beste else "",
            status=Inschrijving.GOEDGEKEURD if staf else Inschrijving.INGEDIEND,
        )
        toegevoegd += 1
        log.append(f"toegevoegd: #{n.nummer} {n.omschrijving}")

    # Tot de eigen deadline mag een zwemmer alles schrappen, ook goedgekeurde inschrijvingen.
    verwijderd = 0
    for nid, i in bestaande.items():
        if nid not in gevraagd:
            log.append(f"geschrapt: #{i.nummer.nummer} {i.nummer.omschrijving}")
            i.delete()
            verwijderd += 1

    if toegevoegd:
        Deelname.objects.update_or_create(zwemmer=z, wedstrijd=w, defaults={"keuze": Deelname.DOET_MEE})
    if log:
        markeer_gewijzigd(z, w)
    if staf and log:
        meld(z, w, request.user, log)
    if not staf and toegevoegd:
        push.stuur(push.users_met("push_nieuwe_inschrijving", STAF), "Nieuwe inschrijving",
                   f"{z} – {w.naam}: {toegevoegd} nummer(s)", reverse("nakijken", args=[w.pk]))
    if geweigerd:
        messages.error(request, "Niet ingeschreven: " + ", ".join(geweigerd))
    messages.success(request, f"Bewaard: {toegevoegd} toegevoegd, {verwijderd} geschrapt.")
    return redirect(f"/wedstrijd/{w.pk}/?zwemmer={z.pk}")


@login_required
@require_POST
def deelname(request, pk):
    w = get_object_or_404(Wedstrijd, pk=pk)
    z = zwemmer_voor(request, request.POST.get("zwemmer"))
    keuze = request.POST.get("keuze")
    if keuze not in dict(Deelname.KEUZES):
        return redirect(f"/wedstrijd/{w.pk}/?zwemmer={z.pk}")
    if not is_staf(request.user) and Deelname.objects.filter(zwemmer=z, wedstrijd=w).filter(
            Q(geweigerd=True) | Q(gefinaliseerd=True)).exists():
        messages.error(request, "Je deelname is vastgelegd door de club. Wijzigen kan enkel nog via je trainer.")
        return redirect(f"/wedstrijd/{w.pk}/?zwemmer={z.pk}")
    Deelname.objects.update_or_create(zwemmer=z, wedstrijd=w, defaults={"keuze": keuze})
    messages.success(request, f"{z.voornaam}: {dict(Deelname.KEUZES)[keuze].lower()} – bewaard.")
    return redirect(f"/wedstrijd/{w.pk}/?zwemmer={z.pk}")


@vereist_rol(*STAF)
@require_POST
def deelname_beoordelen(request, pk):
    """Staf weigert de deelname van een zwemmer (met reden) of staat ze opnieuw toe."""
    w = get_object_or_404(Wedstrijd, pk=pk)
    z = zwemmer_voor(request, request.POST.get("zwemmer"))
    reden = request.POST.get("reden", "").strip()[:200]
    d, _ = Deelname.objects.get_or_create(zwemmer=z, wedstrijd=w, defaults={"keuze": Deelname.INTERESSE})
    if request.POST.get("actie") == "weigeren":
        d.geweigerd, d.weigerreden = True, reden
        d.save()
        n = (Inschrijving.objects.filter(zwemmer=z, nummer__sessie__wedstrijd=w)
             .exclude(status=Inschrijving.AFGEKEURD)
             .update(status=Inschrijving.AFGEKEURD, opmerking=reden or "deelname geweigerd"))
        meld(z, w, request.user, ["deelname geweigerd" + (f": {reden}" if reden else "")]
             + ([f"{n} inschrijving(en) afgekeurd"] if n else []))
        markeer_gewijzigd(z, w)
        messages.success(request, f"Deelname van {z} geweigerd; {z.voornaam} kreeg een melding.")
    else:
        d.geweigerd, d.weigerreden = False, ""
        d.save()
        meld(z, w, request.user, ["deelname opnieuw toegestaan"])
        messages.success(request, f"Deelname van {z} opnieuw toegestaan.")
    return redirect(f"/wedstrijd/{w.pk}/?zwemmer={z.pk}")


@vereist_rol(*STAF)
@require_POST
def bijlage_toevoegen(request, pk):
    w = get_object_or_404(Wedstrijd, pk=pk)
    form = BijlageForm(request.POST, request.FILES)
    if form.is_valid():
        f = form.cleaned_data["bestand"]
        naam = form.cleaned_data["naam"].strip() or f.name.rsplit(".", 1)[0]
        Bijlage.objects.create(wedstrijd=w, bestand=f, naam=naam[:150], door=request.user)
        messages.success(request, f"Bijlage ‘{naam}’ toegevoegd.")
    else:
        messages.error(request, " ".join(e for fouten in form.errors.values() for e in fouten))
    return redirect(request.POST.get("terug") or f"/wedstrijd/{w.pk}/nakijken/")


@vereist_rol(*STAF)
@require_POST
def bijlage_verwijderen(request, pk):
    b = get_object_or_404(Bijlage, pk=pk)
    b.bestand.delete(save=False)
    b.delete()
    messages.success(request, f"Bijlage ‘{b.naam}’ verwijderd.")
    return redirect(request.POST.get("terug") or f"/wedstrijd/{b.wedstrijd_id}/nakijken/")


@login_required
def bijlage(request, pk):
    """Download via de app (media wordt in productie niet publiek geserveerd)."""
    b = get_object_or_404(Bijlage, pk=pk)
    try:
        bestand = b.bestand.open("rb")
    except FileNotFoundError:
        raise Http404
    inline = b.extensie in ("pdf", "jpg", "jpeg", "png")
    return FileResponse(bestand, as_attachment=not inline, filename=f"{b.naam}.{b.extensie}")


# ---------- trainer ----------

@vereist_rol(*STAF)
def nakijken(request, pk):
    w = get_object_or_404(Wedstrijd, pk=pk)
    instform = TrainerInstellingenForm(request.POST or None, instance=w)
    if request.method == "POST" and "dagen_trainer" in request.POST and instform.is_valid():
        instform.save()
        messages.success(request, f"Bewaard. Deadline zwemmers is nu {w.deadline_zwemmer:%d/%m/%Y}.")
        return redirect("nakijken", pk=w.pk)

    status = request.GET.get("status", "")
    qs = (Inschrijving.objects.filter(nummer__sessie__wedstrijd=w)
          .select_related("zwemmer", "nummer__sessie").order_by("zwemmer__naam", "zwemmer__voornaam", "nummer__volgorde"))
    if status:
        qs = qs.filter(status=status)
    per_zwemmer = defaultdict(list)
    for i in qs:
        per_zwemmer[i.zwemmer].append(i)
    tellers = dict(Inschrijving.objects.filter(nummer__sessie__wedstrijd=w)
                   .values_list("status").annotate(c=Count("id")))
    deelnames = defaultdict(list)
    for d in w.deelnames.select_related("zwemmer").order_by("zwemmer__naam", "zwemmer__voornaam"):
        deelnames["geweigerd" if d.geweigerd else d.keuze].append(d.zwemmer)
    overzicht = [(k, label, deelnames.get(k, [])) for k, label in Deelname.KEUZES + [("geweigerd", "Geweigerd")]]
    status_deelname = {d.zwemmer_id: d for d in w.deelnames.select_related("gefinaliseerd_door")}
    met_inschrijving = {z.pk for z in Zwemmer.objects.filter(inschrijvingen__nummer__sessie__wedstrijd=w)}
    finaal = {
        "zwemmers": len(met_inschrijving),
        "gefinaliseerd": sum(1 for zid in met_inschrijving if getattr(status_deelname.get(zid), "gefinaliseerd", False)),
        "ingegeven": sum(1 for zid in met_inschrijving if getattr(status_deelname.get(zid), "ingegeven", False)),
        "gewijzigd": sum(1 for d in status_deelname.values() if d.gewijzigd_na_ingave),
    }
    return render(request, "nakijken.html", {"w": w, "per_zwemmer": dict(per_zwemmer), "instform": instform,
                                             "status": status, "tellers": tellers, "overzicht": overzicht,
                                             "deelname_van": status_deelname, "finaal": finaal})


@vereist_rol(*STAF)
@require_POST
def finaliseren(request, pk):
    """Trainer: inschrijving van een zwemmer vastzetten (of heropenen). Secretariaat: als 'ingegeven' markeren."""
    w = get_object_or_404(Wedstrijd, pk=pk)
    actie = request.POST.get("finaliseer") or request.GET.get("actie")
    secretariaat = rol(request.user) in Profiel.SECRETARIAAT
    if request.POST.get("zwemmer") == "alle":
        zwemmers = Zwemmer.objects.filter(inschrijvingen__nummer__sessie__wedstrijd=w).distinct()
        zwemmers = zwemmers.exclude(deelnames__wedstrijd=w, deelnames__geweigerd=True)
        if actie == "finaliseer":
            zwemmers = zwemmers.exclude(deelnames__wedstrijd=w, deelnames__gefinaliseerd=True)
    else:
        zwemmers = [zwemmer_voor(request, request.POST.get("zwemmer"))]
    n = 0
    for z in zwemmers:
        d, _ = Deelname.objects.get_or_create(zwemmer=z, wedstrijd=w, defaults={"keuze": Deelname.DOET_MEE})
        if actie == "finaliseer":
            open_ = Inschrijving.objects.filter(zwemmer=z, nummer__sessie__wedstrijd=w, status=Inschrijving.INGEDIEND)
            goedgekeurd = open_.update(status=Inschrijving.GOEDGEKEURD)
            d.gefinaliseerd, d.gefinaliseerd_door, d.gefinaliseerd_op = True, request.user, timezone.now()
            d.save()
            aantal = Inschrijving.objects.filter(zwemmer=z, nummer__sessie__wedstrijd=w,
                                                 status=Inschrijving.GOEDGEKEURD).count()
            meld(z, w, request.user, [f"inschrijving gefinaliseerd: {aantal} nummer(s)"]
                 + ([f"{goedgekeurd} openstaande inschrijving(en) goedgekeurd"] if goedgekeurd else []))
        elif actie == "heropen":
            d.gefinaliseerd = False
            d.save()
            meld(z, w, request.user, ["inschrijving heropend: je kan opnieuw wijzigen tot je deadline"])
            markeer_gewijzigd(z, w)
        elif actie in ("ingegeven", "niet_ingegeven") and secretariaat:
            d.ingegeven, d.gewijzigd_na_ingave = actie == "ingegeven", False
            d.save()
        else:
            continue
        n += 1
    labels = {"finaliseer": "gefinaliseerd", "heropen": "heropend", "ingegeven": "als ingegeven gemarkeerd",
              "niet_ingegeven": "niet meer als ingegeven gemarkeerd"}
    if n:
        messages.success(request, f"{n} zwemmer(s) {labels.get(actie, '')}.")
    return redirect(request.POST.get("terug") or f"/wedstrijd/{w.pk}/nakijken/")


@vereist_rol(*STAF)
def overzicht(request, pk):
    """Afdrukbaar overzicht (A4 / PDF via de browser) per zwemmer of per nummer, voor een laatste controle."""
    w = get_object_or_404(Wedstrijd, pk=pk)
    filter_ = request.GET.get("filter", "actief")  # actief = zonder afgekeurde, goedgekeurd, alle
    weergave = request.GET.get("weergave", "zwemmer")
    qs = (Inschrijving.objects.filter(nummer__sessie__wedstrijd=w)
          .select_related("zwemmer", "nummer__sessie").order_by("nummer__sessie__nummer", "nummer__volgorde"))
    if filter_ == "goedgekeurd":
        qs = qs.filter(status=Inschrijving.GOEDGEKEURD)
    elif filter_ == "actief":
        qs = qs.exclude(status=Inschrijving.AFGEKEURD)
    keuzes = {d.zwemmer_id: d for d in w.deelnames.select_related("zwemmer")}

    per_zwemmer = defaultdict(list)
    per_nummer = defaultdict(list)
    for i in qs:
        per_zwemmer[i.zwemmer].append(i)
        per_nummer[i.nummer].append(i)
    zwemmers = []
    for z, lijst in sorted(per_zwemmer.items(), key=lambda kv: (kv[0].naam, kv[0].voornaam)):
        zwemmers.append({"z": z, "lijst": lijst, "jaren": leeftijd(z, w), "deelname": keuzes.get(z.pk),
                         "kost": sum(i.nummer.kost for i in lijst)})
    nummers = [(n, sorted(l, key=lambda i: (i.inschrijftijd is None, i.inschrijftijd or 0)))
               for n, l in sorted(per_nummer.items(), key=lambda kv: (kv[0].sessie.nummer, kv[0].volgorde))]
    zonder = sorted((d.zwemmer for d in keuzes.values()
                     if d.keuze in Deelname.ACTIEF and not d.geweigerd and d.zwemmer not in per_zwemmer),
                    key=lambda z: (z.naam, z.voornaam))
    return render(request, "overzicht.html", {
        "w": w, "zwemmers": zwemmers, "nummers": nummers, "zonder": zonder, "filter": filter_, "weergave": weergave,
        "aantal": qs.count(), "totaal": sum(x["kost"] for x in zwemmers), "nu": timezone.localtime(),
    })


@vereist_rol(*STAF)
@require_POST
def beoordelen(request, pk):
    w = get_object_or_404(Wedstrijd, pk=pk)
    actie = request.POST.get("actie")
    ids = request.POST.getlist("inschrijving")
    qs = Inschrijving.objects.filter(nummer__sessie__wedstrijd=w, pk__in=ids)
    if actie in (Inschrijving.GOEDGEKEURD, Inschrijving.AFGEKEURD, Inschrijving.INGEDIEND):
        opmerking = request.POST.get("opmerking", "")[:200]
        label = dict(Inschrijving.STATUS)[actie].lower()
        per_zwemmer = defaultdict(list)
        for i in qs.select_related("zwemmer", "nummer"):
            if i.status != actie or i.opmerking != opmerking:
                per_zwemmer[i.zwemmer].append(f"{label}: #{i.nummer.nummer} {i.nummer.omschrijving}"
                                              + (f" ({opmerking})" if opmerking else ""))
        n = qs.update(status=actie, opmerking=opmerking)
        for z, regels in per_zwemmer.items():
            meld(z, w, request.user, regels)
            markeer_gewijzigd(z, w)
        messages.success(request, f"{n} inschrijving(en) {label}.")
    return redirect(request.POST.get("terug") or f"/wedstrijd/{w.pk}/nakijken/")


# ---------- sportsecretaris ----------

@vereist_rol(*Profiel.SECRETARIAAT)
def upload(request, pk=None):
    bestaand = get_object_or_404(Wedstrijd, pk=pk) if pk else None
    form = UploadForm(request.POST or None, request.FILES or None)
    if request.method == "POST" and form.is_valid():
        f = form.cleaned_data["bestand"]
        try:
            meet = lenex.parse_meet(lenex.lees_xml(f.read()))
        except lenex.LenexFout as e:
            form.add_error("bestand", str(e))
        else:
            f.seek(0)
            w = lenex.importeer(meet, f, bestaand)
            if not bestaand:
                push.stuur(push.users_met("push_nieuwe_wedstrijd", [Profiel.ZWEMMER]), f"Nieuwe wedstrijd: {w.naam}",
                           "Laat weten of je interesse hebt of meedoet.", reverse("wedstrijd", args=[w.pk]))
            aantal = sum(len(s["nummers"]) for s in meet["sessies"])
            messages.success(request, f"'{w.naam}' ingelezen: {len(meet['sessies'])} sessies, {aantal} nummers, "
                                      f"{len(meet['limietlijsten'])} limietlijsten.")
            for wtekst in meet["waarschuwingen"]:
                messages.info(request, wtekst)
            return redirect("instellingen", pk=w.pk)
    return render(request, "upload.html", {"form": form, "bestaand": bestaand})


@vereist_rol(*STAF)
def uitslagen(request):
    form = UploadForm(request.POST or None, request.FILES or None)
    stats = None
    if request.method == "POST" and form.is_valid():
        try:
            resultaten = lenex.parse_uitslagen(lenex.lees_xml(form.cleaned_data["bestand"].read()))
        except lenex.LenexFout as e:
            form.add_error("bestand", str(e))
        else:
            if not resultaten:
                form.add_error("bestand", "Geen individuele uitslagen gevonden in dit bestand.")
            else:
                stats = lenex.importeer_uitslagen(resultaten)
                stats["onbekend"] = sorted(stats["onbekend"])
                messages.success(request, f"{stats['gekoppeld']} resultaten van clubzwemmers verwerkt: "
                                          f"{stats['nieuw']} nieuwe en {stats['verbeterd']} verbeterde beste tijden.")
    return render(request, "uitslagen.html", {"form": form, "stats": stats})


@vereist_rol(*Profiel.SECRETARIAAT)
def instellingen(request, pk):
    w = get_object_or_404(Wedstrijd, pk=pk)
    vorige_status = w.status
    form = SecretarisInstellingenForm(request.POST or None, instance=w)
    if request.method == "POST" and form.is_valid():
        form.save()
        if vorige_status != Wedstrijd.OPEN and w.status == Wedstrijd.OPEN:
            n = push.stuur(push.users_met("push_nieuwe_wedstrijd", deelname_aan=w), f"Inschrijvingen open: {w.naam}",
                           f"Inschrijven kan tot {w.deadline_zwemmer:%d/%m}.", reverse("wedstrijd", args=[w.pk]))
            if n:
                messages.info(request, f"Pushmelding verstuurd naar {n} toestel(len).")
        messages.success(request, "Instellingen bewaard.")
        return redirect("instellingen", pk=w.pk)
    sessies = w.sessies.prefetch_related("nummers__leeftijdsgroepen")
    return render(request, "instellingen.html", {"w": w, "form": form, "sessies": sessies})


@vereist_rol(*Profiel.SECRETARIAAT)
@require_POST
def verwijderen(request, pk):
    w = get_object_or_404(Wedstrijd, pk=pk)
    w.delete()
    messages.success(request, f"'{w.naam}' verwijderd.")
    return redirect("wedstrijden")


def _goedgekeurd(w, request=None):
    qs = (Inschrijving.objects.filter(nummer__sessie__wedstrijd=w, status=Inschrijving.GOEDGEKEURD)
          .select_related("zwemmer", "nummer__sessie"))
    if request is not None and request.GET.get("enkel") == "gefinaliseerd":
        qs = qs.filter(zwemmer__deelnames__wedstrijd=w, zwemmer__deelnames__gefinaliseerd=True)
    return qs


@vereist_rol(*Profiel.SECRETARIAAT)
def export_lxf(request, pk):
    w = get_object_or_404(Wedstrijd, pk=pk)
    resp = HttpResponse(lenex.bouw_inschrijvingen(w, _goedgekeurd(w, request)), content_type="application/zip")
    resp["Content-Disposition"] = f'attachment; filename="{lenex.bestandsnaam(w, "lxf")}"'
    return resp


@vereist_rol(*Profiel.SECRETARIAAT)
def export_xlsx(request, pk):
    w = get_object_or_404(Wedstrijd, pk=pk)
    resp = HttpResponse(excel_overzicht(w, _goedgekeurd(w, request)),
                        content_type="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
    resp["Content-Disposition"] = f'attachment; filename="{lenex.bestandsnaam(w, "xlsx")}"'
    return resp


@vereist_rol(*Profiel.SECRETARIAAT)
def gebruikers(request):
    if request.method == "POST":
        u = get_object_or_404(User, pk=request.POST.get("user"))
        p, _ = Profiel.objects.get_or_create(user=u)
        beheerder = rol(request.user) == Profiel.BEHEERDER
        if not beheerder and (Profiel.BEHEERDER in (p.rol, request.POST.get("rol")) or u.is_superuser):
            messages.error(request, "Enkel een beheerder kan beheerders aanstellen of wijzigen.")
            return redirect("gebruikers")
        form = RolForm(request.POST, instance=p)
        if form.is_valid():
            form.save()
            messages.success(request, f"{u.get_full_name() or u.username} is nu {p.get_rol_display().lower()}.")
        return redirect("gebruikers")
    q = request.GET.get("q", "").strip()
    users = User.objects.select_related("profiel").order_by("last_name", "first_name")
    if q:
        users = users.filter(Q(username__icontains=q) | Q(first_name__icontains=q) | Q(last_name__icontains=q))
    rollen = Profiel.ROLLEN if rol(request.user) == Profiel.BEHEERDER else [
        r for r in Profiel.ROLLEN if r[0] != Profiel.BEHEERDER]
    return render(request, "gebruikers.html", {"users": users, "q": q, "rollen": rollen})


# ---------- backup (beheerder) ----------

def vereist_beheerder(view):
    @wraps(view)
    @login_required
    def inner(request, *a, **kw):
        if rol(request.user) != Profiel.BEHEERDER:
            raise PermissionDenied
        return view(request, *a, **kw)
    return inner


@vereist_beheerder
def backups(request):
    from . import backup
    if request.method == "POST":
        actie = request.POST.get("actie")
        try:
            if actie == "maak":
                pad = backup.maak()
                messages.success(request, f"Backup gemaakt: {pad.name}")
            elif actie == "herstel":
                if request.POST.get("bevestig") != "HERSTEL":
                    messages.error(request, "Typ HERSTEL om te bevestigen.")
                    return redirect("backups")
                bron = request.FILES.get("bestand") or backup.zoek(request.POST.get("naam", ""))
                n, veilig = backup.herstel(bron)
                messages.success(request, f"Backup teruggezet ({n} objecten). Veiligheidsbackup van daarvoor: {veilig.name}")
                return redirect("login")
            elif actie == "verwijder":
                backup.zoek(request.POST.get("naam", "")).unlink()
                messages.success(request, "Backup verwijderd.")
        except (FileNotFoundError, ValueError, zipfile.BadZipFile) as e:
            messages.error(request, f"Mislukt: {e}")
        return redirect("backups")
    bestanden = [(f.name, f.stat().st_size, datetime.fromtimestamp(f.stat().st_mtime)) for f in backup.lijst()]
    return render(request, "backups.html", {"bestanden": bestanden})


@vereist_beheerder
def backup_download(request, naam):
    from . import backup
    try:
        pad = backup.zoek(naam)
    except FileNotFoundError:
        raise Http404
    return FileResponse(pad.open("rb"), as_attachment=True, filename=pad.name)


# ---------- uitleg ----------

FILMPJES = [
    ("zwemmer", "Voor zwemmers en ouders", "handleiding-zwemmer"),
    ("trainer", "Voor trainers", "handleiding-trainer"),
    ("sportsecretaris", "Voor de sportsecretaris", "handleiding-sportsecretaris"),
]


@login_required
def uitleg(request):
    r = rol(request.user)
    zichtbaar = {Profiel.ZWEMMER: ["zwemmer"], Profiel.TRAINER: ["trainer", "zwemmer"]}.get(r, [f[0] for f in FILMPJES])
    filmpjes = [f for f in FILMPJES if f[0] in zichtbaar]
    pdfs = ["onepager-zwemmer", "handleiding-zwemmer"] if r == Profiel.ZWEMMER else (
        ["onepager-trainer", "handleiding-trainer", "handleiding-zwemmer"] if r == Profiel.TRAINER else
        ["HZS-inschrijvingen-documentatie", "onepager-sportsecretaris", "handleiding-sportsecretaris",
         "handleiding-trainer", "handleiding-zwemmer"] + (["handleiding-beheerder", "technisch"] if r == Profiel.BEHEERDER else []))
    return render(request, "uitleg.html", {"filmpjes": filmpjes, "pdfs": pdfs})


# ---------- meldingen ----------

@login_required
def meldingen_instellingen(request):
    profiel, _ = Profiel.objects.get_or_create(user=request.user)
    form = MeldingenForm(request.POST or None, instance=profiel, staf=is_staf(request.user))
    if request.method == "POST" and form.is_valid():
        form.save()
        messages.success(request, "Voorkeuren bewaard.")
        return redirect("meldingen")
    return render(request, "meldingen.html", {"form": form, "vapid": push.publieke_sleutel(),
                                              "toestellen": request.user.push_abonnementen.count()})


@login_required
@require_POST
def push_abonneer(request):
    try:
        data = json.loads(request.body)
        endpoint, keys = data["endpoint"], data["keys"]
        if not endpoint.startswith("https://"):
            raise ValueError
    except (ValueError, KeyError, TypeError):
        return JsonResponse({"ok": False}, status=400)
    PushAbonnement.objects.update_or_create(
        endpoint=endpoint,
        defaults={"user": request.user, "p256dh": keys["p256dh"][:200], "auth": keys["auth"][:100],
                  "toestel": request.headers.get("User-Agent", "")[:200]},
    )
    return JsonResponse({"ok": True})


@login_required
@require_POST
def push_afmelden(request):
    try:
        endpoint = json.loads(request.body)["endpoint"]
    except (ValueError, KeyError, TypeError):
        return JsonResponse({"ok": False}, status=400)
    request.user.push_abonnementen.filter(endpoint=endpoint).delete()
    return JsonResponse({"ok": True})


@login_required
@require_POST
def push_test(request):
    n = push.stuur([request.user], "Testmelding", "Pushmeldingen werken op dit toestel 👍", reverse("meldingen"),
                   achtergrond=False)
    return JsonResponse({"ok": bool(n), "toestellen": n})


# ---------- PWA ----------

def manifest(request):
    return render(request, "pwa/manifest.webmanifest", content_type="application/manifest+json")


def service_worker(request):
    resp = render(request, "pwa/sw.js", content_type="application/javascript")
    resp["Service-Worker-Allowed"] = "/"
    resp["Cache-Control"] = "no-cache"
    return resp


def offline(request):
    return render(request, "pwa/offline.html")
