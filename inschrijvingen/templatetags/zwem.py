from django import template

from ..regels import toon_tijd

register = template.Library()


@register.filter
def tijd(td):
    return toon_tijd(td)


@register.filter
def get(d, key):
    return d.get(key) if d else None


@register.filter
def euro(v):
    return f"€ {v:.2f}".replace(".", ",")


PDF_NAMEN = {
    "HZS-inschrijvingen-documentatie": "Volledige documentatie", "onepager-zwemmer": "Onepager zwemmers",
    "onepager-trainer": "Onepager trainers", "onepager-sportsecretaris": "Onepager sportsecretaris",
    "handleiding-zwemmer": "Handleiding zwemmers & ouders", "handleiding-trainer": "Handleiding trainers",
    "handleiding-sportsecretaris": "Handleiding sportsecretaris", "handleiding-beheerder": "Handleiding beheerder",
    "technisch": "Technische documentatie",
}


@register.filter
def pdfnaam(slug):
    return PDF_NAMEN.get(slug, slug)
