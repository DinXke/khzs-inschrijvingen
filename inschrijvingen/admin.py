from django.contrib import admin

from .models import BesteTijd, Bijlage, Inschrijving, Profiel, Rol, Rolaanvraag, Wedstrijd, Zwemmer


class BesteTijdInline(admin.TabularInline):
    model = BesteTijd
    extra = 0


@admin.register(Zwemmer)
class ZwemmerAdmin(admin.ModelAdmin):
    list_display = ["naam", "voornaam", "geboortedatum", "geslacht", "licentie", "account"]
    search_fields = ["naam", "voornaam", "licentie", "account__username", "extra_accounts__username"]
    filter_horizontal = ["extra_accounts"]
    inlines = [BesteTijdInline]


@admin.register(Wedstrijd)
class WedstrijdAdmin(admin.ModelAdmin):
    list_display = ["naam", "stad", "deadline", "status", "dagen_secretaris", "dagen_trainer"]
    list_filter = ["status"]


@admin.register(Inschrijving)
class InschrijvingAdmin(admin.ModelAdmin):
    list_display = ["zwemmer", "nummer", "inschrijftijd", "status"]
    list_filter = ["status", "nummer__sessie__wedstrijd"]


admin.site.register(Profiel)
admin.site.site_header = "KHZS Leden – beheer"

admin.site.register(Bijlage)


@admin.register(Rol)
class RolAdmin(admin.ModelAdmin):
    list_display = ["user", "rol", "sinds", "door"]
    list_filter = ["rol"]
    search_fields = ["user__username", "user__first_name", "user__last_name"]


@admin.register(Rolaanvraag)
class RolaanvraagAdmin(admin.ModelAdmin):
    list_display = ["user", "rol", "status", "toegekende_rol", "aangemaakt", "behandeld_door"]
    list_filter = ["status", "rol"]
