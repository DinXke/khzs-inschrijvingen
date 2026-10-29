from django.apps import AppConfig


class InschrijvingenConfig(AppConfig):
    default_auto_field = "django.db.models.BigAutoField"
    name = "inschrijvingen"
    verbose_name = "Inschrijvingen"

    def ready(self):
        from . import signals  # noqa: F401
