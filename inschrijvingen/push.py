"""Web Push (VAPID). De sleutels worden bij eerste gebruik aangemaakt in settings.VAPID_FILE."""
import base64
import json
import logging
import threading
from pathlib import Path

from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import ec
from django.conf import settings

log = logging.getLogger(__name__)
_slot = threading.Lock()


def _pem_pad() -> Path:
    pad = Path(settings.VAPID_FILE)
    with _slot:
        if not pad.exists():
            pad.parent.mkdir(parents=True, exist_ok=True)
            sleutel = ec.generate_private_key(ec.SECP256R1())
            pad.write_bytes(sleutel.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8,
                                                  serialization.NoEncryption()))
            pad.chmod(0o600)
    return pad


def publieke_sleutel() -> str:
    sleutel = serialization.load_pem_private_key(_pem_pad().read_bytes(), None)
    punt = sleutel.public_key().public_bytes(serialization.Encoding.X962, serialization.PublicFormat.UncompressedPoint)
    return base64.urlsafe_b64encode(punt).rstrip(b"=").decode()


def _verstuur(abonnementen, bericht):
    from pywebpush import WebPushException, webpush

    pem = str(_pem_pad())
    for a in abonnementen:
        try:
            webpush(
                subscription_info={"endpoint": a.endpoint, "keys": {"p256dh": a.p256dh, "auth": a.auth}},
                data=json.dumps(bericht), vapid_private_key=pem,
                vapid_claims={"sub": f"mailto:{settings.DEFAULT_FROM_EMAIL}"}, ttl=86400,
            )
        except WebPushException as e:
            if e.response is not None and e.response.status_code in (404, 410):
                a.delete()  # abonnement bestaat niet meer
            else:
                log.warning("push mislukt: %s", e)
        except Exception:  # noqa: BLE001 – één kapot abonnement mag de rest niet tegenhouden
            log.exception("push mislukt")


def stuur(users, titel, tekst, url="/", achtergrond=True):
    """Stuurt een melding naar alle toestellen van deze gebruikers."""
    from .models import PushAbonnement

    abonnementen = list(PushAbonnement.objects.filter(user__in=users))
    if not abonnementen:
        return 0
    bericht = {"titel": titel, "tekst": tekst, "url": url}
    if achtergrond and not settings.PUSH_SYNCHROON:
        threading.Thread(target=_verstuur, args=(abonnementen, bericht), daemon=True).start()
    else:
        _verstuur(abonnementen, bericht)
    return len(abonnementen)


def users_met(voorkeur, rollen=None, deelname_aan=None):
    """Gebruikers met deze voorkeur aan en minstens één toestel. Met deelname_aan: enkel accounts met een
    zwemmer die voor die wedstrijd 'interesse' of 'ik doe mee' koos."""
    from django.contrib.auth.models import User

    from .models import Deelname

    qs = User.objects.filter(is_active=True, **{f"profiel__{voorkeur}": True}, push_abonnementen__isnull=False)
    if rollen:
        qs = qs.filter(profiel__rol__in=rollen)
    if deelname_aan is not None:
        qs = qs.filter(zwemmers__deelnames__wedstrijd=deelname_aan, zwemmers__deelnames__keuze__in=Deelname.ACTIEF)
    return qs.distinct()
