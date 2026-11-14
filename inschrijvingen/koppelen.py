"""Een zwemmer delen met een ander account (bv. gescheiden ouders) via een koppelcode."""
import secrets
from datetime import timedelta

from django.core.cache import cache
from django.utils import timezone

ALFABET = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789"  # zonder O/0 en I/1
GELDIG = timedelta(days=7)
MAX_POGINGEN = 10  # foute codes per account per uur


def normaliseer(code: str) -> str:
    return "".join(c for c in (code or "").upper() if c in ALFABET)


def toon(code: str) -> str:
    return f"{code[:4]}-{code[4:]}" if len(code) == 8 else code


def nieuwe_code(zwemmer) -> str:
    """Maakt een nieuwe code (de vorige vervalt), 7 dagen geldig, en meermaals te gebruiken binnen die periode."""
    from .models import Zwemmer
    while True:
        code = "".join(secrets.choice(ALFABET) for _ in range(8))
        if not Zwemmer.objects.filter(koppelcode=code).exists():
            break
    zwemmer.koppelcode, zwemmer.koppelcode_tot = code, timezone.now() + GELDIG
    zwemmer.save(update_fields=["koppelcode", "koppelcode_tot"])
    return code


def geldige_code(zwemmer):
    if zwemmer.koppelcode and zwemmer.koppelcode_tot and zwemmer.koppelcode_tot > timezone.now():
        return zwemmer.koppelcode
    return None


def zoek(code: str, user=None):
    """De zwemmer bij een geldige code, of None. Telt foute pogingen per account (tegen raden)."""
    from .models import Zwemmer
    sleutel = f"koppelpoging-{getattr(user, 'pk', None) or 'anoniem'}"
    if cache.get(sleutel, 0) >= MAX_POGINGEN:
        raise ValueError("Te veel foute codes. Probeer het over een uur opnieuw.")
    code = normaliseer(code)
    z = Zwemmer.objects.filter(koppelcode=code, koppelcode_tot__gt=timezone.now()).first() if len(code) == 8 else None
    if z is None:
        cache.set(sleutel, cache.get(sleutel, 0) + 1, 3600)
    return z


def koppel(zwemmer, user) -> bool:
    """Koppelt het account aan de zwemmer. False als het al gekoppeld was."""
    if zwemmer.heeft_account(user):
        return False
    zwemmer.extra_accounts.add(user)
    return True


def ontkoppel(zwemmer, user):
    """Haalt een account weg. Is het het eerste account, dan wordt een gekoppeld account het nieuwe eerste account.
    Het laatste account kan niet weg (verwijder dan de zwemmer)."""
    extra = [u for u in zwemmer.extra_accounts.all() if u.pk != zwemmer.account_id]
    if zwemmer.account_id == user.pk:
        if not extra:
            raise ValueError("Dit is het enige account van deze zwemmer.")
        zwemmer.account = extra[0]
        zwemmer.save(update_fields=["account"])
        zwemmer.extra_accounts.remove(extra[0])
    else:
        zwemmer.extra_accounts.remove(user)
