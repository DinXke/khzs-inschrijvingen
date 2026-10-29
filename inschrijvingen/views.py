from collections import defaultdict
from functools import wraps

from django.contrib import messages
from django.contrib.auth import login
from django.contrib.auth.decorators import login_required
from django.contrib.auth.models import User
from django.core.exceptions import PermissionDenied
from django.db.models import Count, Q
from django.http import HttpResponse
from django.shortcuts import get_object_or_404, redirect, render
from django.views.decorators.http import require_POST

import json

from django.http import JsonResponse
from django.urls import reverse

from . import lenex, push
from .export import excel_overzicht
from .forms import (BesteTijdForm, MeldingenForm, RegistratieForm, RolForm, SecretarisInstellingenForm,
                    TrainerInstellingenForm, UploadForm, ZwemmerForm)
from .models import BesteTijd, Deelname, Inschrijving, Melding, Nummer, Profiel, PushAbonnement, Wedstrijd, Zwemmer
from .regels import (vandaag, beoordeel, leeftijd, mag_wijzigen, parse_invoer_tijd, past_in_nummer, programma_voor, rol,
                     toon_tijd)

STAF = (Profiel.TRAINER, Profiel.SECRETARIS)


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


def zwemmer_voor(request, pk):
    """Een zwemmer die deze gebruiker mag zien: eigen zwemmers, of alle voor staf."""
    qs = Zwemmer.objects.all() if is_staf(request.user) else request.user.zwemmers.all()
    return get_object_or_404(qs, pk=pk)


# ---------- algemeen ----------

def registreren(request):
    if request.user.is_authenticated:
        return redirect("start")
    form = RegistratieForm(request.POST or None)
    if request.method == "POST" and form.is_valid():
        user = form.save()
        login(request, user)
        messages.success(request, "Account aangemaakt. Vul je beste tijden in om te kunnen inschrijven.")
        return redirect("zwemmer", pk=user.zwemmers.first().pk)
    return render(request, "registration/registreren.html", {"form": form})


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
    lijst = [(w, eigen.get(w.id, 0), nieuw.get(w.id, 0), keuzes.get(w.id, [])) for w in qs]
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
        if "t-tijd" in request.POST:
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
    zwemmer_voor(request, t.zwemmer_id)
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
    Deelname.objects.update_or_create(zwemmer=z, wedstrijd=w, defaults={"keuze": keuze})
    messages.success(request, f"{z.voornaam}: {dict(Deelname.KEUZES)[keuze].lower()} – bewaard.")
    return redirect(f"/wedstrijd/{w.pk}/?zwemmer={z.pk}")


# ---------- trainer ----------

@vereist_rol(*STAF)
def nakijken(request, pk):
    w = get_object_or_404(Wedstrijd, pk=pk)
    instform = TrainerInstellingenForm(request.POST or None, instance=w)
    if request.method == "POST" and "dagen_trainer" in request.POST and instform.is_valid():
        instform.save()
        messages.success(request, f"Deadline zwemmers is nu {w.deadline_zwemmer:%d/%m/%Y}.")
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
        deelnames[d.keuze].append(d.zwemmer)
    overzicht = [(k, label, deelnames.get(k, [])) for k, label in Deelname.KEUZES]
    return render(request, "nakijken.html", {"w": w, "per_zwemmer": dict(per_zwemmer), "instform": instform,
                                             "status": status, "tellers": tellers, "overzicht": overzicht})


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
        messages.success(request, f"{n} inschrijving(en) {label}.")
    return redirect(request.POST.get("terug") or f"/wedstrijd/{w.pk}/nakijken/")


# ---------- sportsecretaris ----------

@vereist_rol(Profiel.SECRETARIS)
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


@vereist_rol(Profiel.SECRETARIS)
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


@vereist_rol(Profiel.SECRETARIS)
@require_POST
def verwijderen(request, pk):
    w = get_object_or_404(Wedstrijd, pk=pk)
    w.delete()
    messages.success(request, f"'{w.naam}' verwijderd.")
    return redirect("wedstrijden")


def _goedgekeurd(w):
    return (Inschrijving.objects.filter(nummer__sessie__wedstrijd=w, status=Inschrijving.GOEDGEKEURD)
            .select_related("zwemmer", "nummer__sessie"))


@vereist_rol(Profiel.SECRETARIS)
def export_lxf(request, pk):
    w = get_object_or_404(Wedstrijd, pk=pk)
    resp = HttpResponse(lenex.bouw_inschrijvingen(w, _goedgekeurd(w)), content_type="application/zip")
    resp["Content-Disposition"] = f'attachment; filename="{lenex.bestandsnaam(w, "lxf")}"'
    return resp


@vereist_rol(Profiel.SECRETARIS)
def export_xlsx(request, pk):
    w = get_object_or_404(Wedstrijd, pk=pk)
    resp = HttpResponse(excel_overzicht(w, _goedgekeurd(w)),
                        content_type="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
    resp["Content-Disposition"] = f'attachment; filename="{lenex.bestandsnaam(w, "xlsx")}"'
    return resp


@vereist_rol(Profiel.SECRETARIS)
def gebruikers(request):
    if request.method == "POST":
        u = get_object_or_404(User, pk=request.POST.get("user"))
        p, _ = Profiel.objects.get_or_create(user=u)
        form = RolForm(request.POST, instance=p)
        if form.is_valid():
            form.save()
            messages.success(request, f"{u.get_full_name() or u.username} is nu {p.get_rol_display().lower()}.")
        return redirect("gebruikers")
    q = request.GET.get("q", "").strip()
    users = User.objects.select_related("profiel").order_by("last_name", "first_name")
    if q:
        users = users.filter(Q(username__icontains=q) | Q(first_name__icontains=q) | Q(last_name__icontains=q))
    return render(request, "gebruikers.html", {"users": users, "q": q, "rollen": Profiel.ROLLEN})


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
