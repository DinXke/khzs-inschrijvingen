from .models import Profiel
from .regels import rol


def rollen(request):
    r = rol(request.user)
    return {
        "rol": r,
        "is_staf": r in (Profiel.TRAINER, Profiel.SECRETARIS),
        "is_secretaris": r == Profiel.SECRETARIS,
        "is_beheerder": request.user.is_authenticated and request.user.is_superuser,
    }
