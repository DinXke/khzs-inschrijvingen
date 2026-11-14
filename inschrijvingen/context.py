from . import avg
from .models import Profiel, Rolaanvraag
from .regels import rol
from . import ploegen
from .rollen import heeft_rol, mag_rollen_beheren


def rollen(request):
    u = request.user
    ctx = {
        "rol": rol(u),
        "is_staf": heeft_rol(u, *Profiel.STAF),
        "is_secretaris": heeft_rol(u, *Profiel.SECRETARIAAT),
        "is_beheerder": heeft_rol(u, Profiel.BEHEERDER),
        "is_rolbeheerder": mag_rollen_beheren(u),
        "open_rolaanvragen": 0,
        "rolbeslissingen": [],
        "ziet_ploegen": ploegen.ziet_alle_ploegen(u),
        "open_ploegaanvragen": 0,
        "ploegbeslissingen": [],
        "privacy_lezen": avg.moet_lezen(u),
    }
    if u.is_authenticated:
        if ctx["is_rolbeheerder"]:
            ctx["open_rolaanvragen"] = Rolaanvraag.objects.filter(status=Rolaanvraag.OPEN).count()
        # Melding in de app: beslissingen over eigen aanvragen die het lid nog niet gezien heeft.
        ctx["rolbeslissingen"] = list(u.rolaanvragen.exclude(status=Rolaanvraag.OPEN).filter(gelezen=False))
        ctx["ploegbeslissingen"] = ploegen.ongelezen_beslissingen(u)
        if ctx["ziet_ploegen"]:
            ctx["open_ploegaanvragen"] = ploegen.te_behandelen(u).count()
    return ctx
