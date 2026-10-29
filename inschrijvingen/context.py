from .models import Profiel
from .regels import rol


def rollen(request):
    r = rol(request.user)
    return {
        "rol": r,
        "is_staf": r in Profiel.STAF,
        "is_secretaris": r in Profiel.SECRETARIAAT,
        "is_beheerder": r == Profiel.BEHEERDER,
    }
