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
