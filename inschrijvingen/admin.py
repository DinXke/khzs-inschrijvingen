from django.contrib import admin

from .models import BesteTijd, Bijlage, Inschrijving, Profiel, Wedstrijd, Zwemmer


class BesteTijdInline(admin.TabularInline):
    model = BesteTijd
    extra = 0


@admin.register(Zwemmer)
class ZwemmerAdmin(admin.ModelAdmin):
    list_display = ["naam", "voornaam", "geboortedatum", "geslacht", "licentie", "account"]
    search_fields = ["naam", "voornaam", "licentie", "account__username"]
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
admin.site.site_header = "KHZS inschrijvingen – beheer"

admin.site.register(Bijlage)
