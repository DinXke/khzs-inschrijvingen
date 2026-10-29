from django.conf import settings
from django.conf.urls.static import static
from django.contrib import admin
from django.urls import include, path

from inschrijvingen import views as v

urlpatterns = [
    path("", v.start, name="start"),
    path("registreren/", v.registreren, name="registreren"),
    path("account/", include("django.contrib.auth.urls")),
    path("wedstrijden/", v.wedstrijden, name="wedstrijden"),
    path("wedstrijd/nieuw/", v.upload, name="upload"),
    path("wedstrijd/<int:pk>/", v.wedstrijd, name="wedstrijd"),
    path("wedstrijd/<int:pk>/inschrijven/", v.inschrijven, name="inschrijven"),
    path("wedstrijd/<int:pk>/nakijken/", v.nakijken, name="nakijken"),
    path("wedstrijd/<int:pk>/deelname/", v.deelname, name="deelname"),
    path("wedstrijd/<int:pk>/deelname/beoordelen/", v.deelname_beoordelen, name="deelname_beoordelen"),
    path("wedstrijd/<int:pk>/beoordelen/", v.beoordelen, name="beoordelen"),
    path("wedstrijd/<int:pk>/instellingen/", v.instellingen, name="instellingen"),
    path("wedstrijd/<int:pk>/opnieuw/", v.upload, name="herupload"),
    path("wedstrijd/<int:pk>/verwijderen/", v.verwijderen, name="verwijderen"),
    path("wedstrijd/<int:pk>/export.lxf", v.export_lxf, name="export_lxf"),
    path("wedstrijd/<int:pk>/export.xlsx", v.export_xlsx, name="export_xlsx"),
    path("zwemmers/", v.mijn_zwemmers, name="zwemmers"),
    path("zwemmers/uitslagen/", v.uitslagen, name="uitslagen"),
    path("zwemmers/nieuw/", v.zwemmer_nieuw, name="zwemmer_nieuw"),
    path("zwemmers/<int:pk>/", v.zwemmer, name="zwemmer"),
    path("tijd/<int:pk>/verwijderen/", v.tijd_verwijderen, name="tijd_verwijderen"),
    path("gebruikers/", v.gebruikers, name="gebruikers"),
    path("meldingen/", v.meldingen_instellingen, name="meldingen"),
    path("backup/", v.backups, name="backups"),
    path("backup/<str:naam>", v.backup_download, name="backup_download"),
    path("meldingen/abonneer/", v.push_abonneer, name="push_abonneer"),
    path("meldingen/afmelden/", v.push_afmelden, name="push_afmelden"),
    path("meldingen/test/", v.push_test, name="push_test"),
    path("manifest.webmanifest", v.manifest, name="manifest"),
    path("sw.js", v.service_worker, name="sw"),
    path("offline/", v.offline, name="offline"),
    path("beheer/", admin.site.urls),
]
if settings.DEBUG:
    urlpatterns += static(settings.MEDIA_URL, document_root=settings.MEDIA_ROOT)
