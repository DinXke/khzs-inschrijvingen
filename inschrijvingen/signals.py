from django.contrib.auth.models import User
from django.db.models.signals import post_save
from django.dispatch import receiver

from .models import Profiel


@receiver(post_save, sender=User)
def maak_profiel(sender, instance, created, **kwargs):
    if created:
        # Het allereerste account op een lege installatie wordt sportsecretaris.
        eerste = not Profiel.objects.filter(rol=Profiel.SECRETARIS).exists() and not User.objects.exclude(pk=instance.pk).exists()
        Profiel.objects.get_or_create(
            user=instance,
            defaults={"rol": Profiel.SECRETARIS if instance.is_superuser or eerste else Profiel.ZWEMMER},
        )
