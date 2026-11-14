"""Exporteert alle gegevens naar een zip voor de WordPress-plugin (wordpress/khzs-leden).

    python manage.py export_wordpress [--uit pad.zip]

In WordPress: app → Backup → ‘Backup terugzetten’ met dit bestand. Ontbrekende accounts worden daar aangemaakt
(gebruikersnaam = e-mail); hun huidige wachtwoord blijft werken.
"""
import json
import zipfile
from datetime import datetime
from pathlib import Path

from django.conf import settings
from django.contrib.auth.models import User
from django.core.management.base import BaseCommand

from inschrijvingen.models import (BesteTijd, Bijlage, Deelname, Inschrijving, Leeftijdsgroep, Limiet, Limietlijst, Melding,
                                   Nummer, Ploeg, PloegLid, PushAbonnement, Rolaanvraag, Sessie, VerstuurdeHerinnering, Wedstrijd, Zwemmer)
from inschrijvingen.rollen import gesorteerd, rollen_van


def hon(td):
    return None if td is None else round(td.total_seconds() * 100)


def d(x):
    return x.isoformat() if x else None


def dt(x):
    from django.utils import timezone
    return timezone.localtime(x).strftime("%Y-%m-%d %H:%M:%S") if x else None


def t(x):
    return x.strftime("%H:%M:%S") if x else None


class Command(BaseCommand):
    help = "Exporteert alles naar een zip die de WordPress-plugin kan terugzetten."

    def add_arguments(self, parser):
        parser.add_argument("--uit", default=f"hzs-backup-{datetime.now():%Y%m%d-%H%M%S}-django.zip")

    def handle(self, *args, uit, **opts):
        import hashlib
        tab = {}
        tab["zwemmers"] = [dict(id=z.pk, account_id=z.account_id, voornaam=z.voornaam, naam=z.naam, geboortedatum=d(z.geboortedatum),
                                geslacht=z.geslacht, licentie=z.licentie, swimrankings_id=z.swimrankings_id, koppelcode=z.koppelcode,
                                koppelcode_tot=dt(z.koppelcode_tot), toestemming_op=dt(z.toestemming_op),
                                toestemming_door=z.toestemming_door_id) for z in Zwemmer.objects.all()]
        tab["zwemmer_accounts"] = [dict(zwemmer_id=r.zwemmer_id, user_id=r.user_id, aangemaakt=dt(datetime.now()))
                                   for r in Zwemmer.extra_accounts.through.objects.all()]
        tab["tijden"] = [dict(id=x.pk, zwemmer_id=x.zwemmer_id, afstand=x.afstand, slag=x.slag, baan=x.baan, tijd=hon(x.tijd),
                              datum=d(x.datum), wedstrijd=x.wedstrijd, bron=x.bron) for x in BesteTijd.objects.all()]
        tab["wedstrijden"] = [dict(id=w.pk, naam=w.naam, stad=w.stad, zwembad=w.zwembad, baan=w.baan, deadline=d(w.deadline),
                                   agedate=d(w.agedate), agedate_type=w.agedate_type, kwalificatie_van=d(w.kwalificatie_van),
                                   kwalificatie_tot=d(w.kwalificatie_tot), lenex_bestand=w.lenex_bestand.name or "",
                                   dagen_secretaris=w.dagen_secretaris, dagen_trainer=w.dagen_trainer, status=w.status, info=w.info,
                                   aangemaakt=dt(w.aangemaakt)) for w in Wedstrijd.objects.all()]
        tab["sessies"] = [dict(id=s.pk, wedstrijd_id=s.wedstrijd_id, nummer=s.nummer, datum=d(s.datum), starttijd=t(s.starttijd),
                               opwarmen=t(s.opwarmen)) for s in Sessie.objects.all()]
        tab["nummers"] = [dict(id=n.pk, sessie_id=n.sessie_id, eventid=n.eventid, nummer=n.nummer, volgorde=n.volgorde, geslacht=n.geslacht,
                               afstand=n.afstand, slag=n.slag, naam=n.naam, aflossing=n.aflossing, ronde=n.ronde,
                               inschrijfbaar=int(n.inschrijfbaar), kost_cent=n.kost_cent) for n in Nummer.objects.all()]
        tab["leeftijdsgroepen"] = [dict(id=g.pk, nummer_id=g.nummer_id, agemin=g.agemin, agemax=g.agemax) for g in Leeftijdsgroep.objects.all()]
        gekozen = set(Wedstrijd.limietlijsten.through.objects.values_list("limietlijst_id", flat=True))
        tab["limietlijsten"] = [dict(id=l.pk, wedstrijd_id=l.wedstrijd_id, naam=l.naam, code=l.code, soort=l.soort, baan=l.baan,
                                     geslacht=l.geslacht, agemin=l.agemin, agemax=l.agemax, gekozen=int(l.pk in gekozen))
                                for l in Limietlijst.objects.all()]
        tab["limieten"] = [dict(id=l.pk, lijst_id=l.lijst_id, afstand=l.afstand, slag=l.slag, tijd=hon(l.tijd)) for l in Limiet.objects.all()]
        tab["inschrijvingen"] = [dict(id=i.pk, zwemmer_id=i.zwemmer_id, nummer_id=i.nummer_id, inschrijftijd=hon(i.inschrijftijd),
                                      inschrijfbaan=i.inschrijfbaan, status=i.status, opmerking=i.opmerking, aangemaakt=dt(i.aangemaakt),
                                      door_id=i.door_id) for i in Inschrijving.objects.all()]
        tab["meldingen"] = [dict(id=m.pk, zwemmer_id=m.zwemmer_id, wedstrijd_id=m.wedstrijd_id, door_id=m.door_id, tekst=m.tekst,
                                 gelezen=int(m.gelezen), aangemaakt=dt(m.aangemaakt)) for m in Melding.objects.all()]
        tab["push"] = [dict(id=p.pk, user_id=p.user_id, endpoint=p.endpoint, endpoint_hash=hashlib.sha256(p.endpoint.encode()).hexdigest(),
                            p256dh=p.p256dh, auth=p.auth, toestel=p.toestel, aangemaakt=dt(p.aangemaakt)) for p in PushAbonnement.objects.all()]
        tab["herinneringen"] = [dict(id=h.pk, wedstrijd_id=h.wedstrijd_id, soort=h.soort, datum=d(h.datum)) for h in VerstuurdeHerinnering.objects.all()]
        tab["deelnames"] = [dict(id=x.pk, zwemmer_id=x.zwemmer_id, wedstrijd_id=x.wedstrijd_id, keuze=x.keuze, geweigerd=int(x.geweigerd),
                                 weigerreden=x.weigerreden, gefinaliseerd=int(x.gefinaliseerd), gefinaliseerd_door=x.gefinaliseerd_door_id,
                                 gefinaliseerd_op=dt(x.gefinaliseerd_op), ingegeven=int(x.ingegeven),
                                 gewijzigd_na_ingave=int(x.gewijzigd_na_ingave), gewijzigd=dt(x.gewijzigd)) for x in Deelname.objects.all()]
        tab["bijlagen"] = [dict(id=b.pk, wedstrijd_id=b.wedstrijd_id, bestand=b.bestand.name, naam=b.naam, door_id=b.door_id,
                                toegevoegd=dt(b.toegevoegd)) for b in Bijlage.objects.all()]
        tab["rolaanvragen"] = [dict(id=a.pk, user_id=a.user_id, rol=a.rol, motivatie=a.motivatie, status=a.status,
                                    toegekende_rol=a.toegekende_rol, reden=a.reden, behandeld_door=a.behandeld_door_id,
                                    behandeld_op=dt(a.behandeld_op), gelezen=int(a.gelezen), aangemaakt=dt(a.aangemaakt))
                               for a in Rolaanvraag.objects.all()]
        tab["ploegen"] = [dict(id=x.pk, naam=x.naam, beschrijving=x.beschrijving, soort=x.soort, actief=int(x.actief), aangemaakt=dt(x.aangemaakt))
                          for x in Ploeg.objects.all()]
        tab["ploeg_trainers"] = [dict(ploeg_id=x.ploeg_id, user_id=x.user_id) for x in Ploeg.trainers.through.objects.all()]
        tab["ploegleden"] = [dict(id=x.pk, zwemmer_id=x.zwemmer_id, ploeg_id=x.ploeg_id, status=x.status, aangevraagd_door=x.aangevraagd_door_id,
                                  reden=x.reden, behandeld_door=x.behandeld_door_id, behandeld_op=dt(x.behandeld_op),
                                  gelezen=int(x.gelezen), aangemaakt=dt(x.aangemaakt)) for x in PloegLid.objects.all()]

        gebruikers, meta = [], []
        for u in User.objects.select_related("profiel"):
            gebruikers.append(dict(id=u.pk, login=u.username, email=u.email or u.username, first_name=u.first_name,
                                   last_name=u.last_name, wachtwoord=u.password, actief=u.is_active))
            p = getattr(u, "profiel", None)
            eigen = gesorteerd(rollen_van(u))
            meta.append(dict(user_id=u.pk, user_login=u.username, meta_key="khzs_rol", meta_value=eigen[0] if eigen else ""))
            # Alle rollen, komma-gescheiden (de plugin leest ze met rollen_van()).
            meta.append(dict(user_id=u.pk, user_login=u.username, meta_key="khzs_rollen", meta_value=",".join(eigen)))
            if p:
                for k in ("push_nieuwe_wedstrijd", "push_deadline", "push_wijziging", "push_nieuwe_inschrijving",
                          "push_rolaanvraag", "push_ploegaanvraag", "push_beslissing"):
                    meta.append(dict(user_id=u.pk, user_login=u.username, meta_key=f"khzs_{k}", meta_value="1" if getattr(p, k) else "0"))
                if p.privacy_akkoord:  # AVG: aanvaarde privacyverklaring
                    meta.append(dict(user_id=u.pk, user_login=u.username, meta_key="khzs_privacy_akkoord", meta_value=dt(p.privacy_akkoord)))
                    meta.append(dict(user_id=u.pk, user_login=u.username, meta_key="khzs_privacy_versie", meta_value=p.privacy_versie))

        data = {"versie": "django", "gemaakt": datetime.now().isoformat(), "tabellen": tab, "meta": meta, "gebruikers": gebruikers}
        with zipfile.ZipFile(uit, "w", zipfile.ZIP_DEFLATED) as z:
            z.writestr("data.json", json.dumps(data))
            media = Path(settings.MEDIA_ROOT)
            for map_ in ("lenex", "bijlagen"):
                for f in (media / map_).rglob("*") if (media / map_).exists() else []:
                    if f.is_file():
                        z.write(f, f"bestanden/{f.relative_to(media)}")
        self.stdout.write(self.style.SUCCESS(f"Geschreven: {uit} ({len(gebruikers)} accounts, {len(tab['zwemmers'])} zwemmers, "
                                             f"{len(tab['wedstrijden'])} wedstrijden, {len(tab['inschrijvingen'])} inschrijvingen)"))
