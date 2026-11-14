from django import forms
from django.contrib.auth.forms import UserCreationForm
from django.contrib.auth.models import User

from .models import BesteTijd, Profiel, Wedstrijd, Zwemmer
from .regels import parse_invoer_tijd, toon_tijd


class DatumInput(forms.DateInput):
    input_type = "date"

    def __init__(self, **kw):
        super().__init__(format="%Y-%m-%d", **kw)


class RegistratieForm(UserCreationForm):
    """Account (bv. van een ouder). De zwemmers komen uit een aparte formset (ZwemmerFormSet)."""

    email = forms.EmailField(label="E-mail")
    voornaam = forms.CharField(label="Jouw voornaam", max_length=60)
    naam = forms.CharField(label="Jouw naam", max_length=80)
    koppelcode = forms.CharField(label="Koppelcode (optioneel)", max_length=12, required=False,
                                 help_text="Heeft de andere ouder je kind al ingeschreven? Vraag een koppelcode (bij de zwemmer → "
                                           "‘Koppelcode maken’) en vul ze hier in. Je hoeft je kind dan niet opnieuw toe te voegen.")
    rollen = forms.MultipleChoiceField(
        label="Ik ben (meerdere mogelijk)", required=False, widget=forms.CheckboxSelectMultiple,
        choices=[(Profiel.ZWEMMER, "Zwemmer"), (Profiel.OUDER, "Ouder van een zwemmer"), (Profiel.TRAINER, "Trainer"),
                 (Profiel.SECRETARIS, "Sportsecretaris"), (Profiel.BESTUURDER, "Bestuurder")],
        help_text="Zwemmer en ouder krijg je meteen. Trainer, sportsecretaris en bestuurder worden eerst goedgekeurd door "
                  "het bestuur. Later aanvragen kan bij Mijn zwemmers → Mijn rollen.")

    class Meta:
        model = User
        fields = ["email"]

    def clean_email(self):
        email = self.cleaned_data["email"].lower().strip()
        if User.objects.filter(username__iexact=email).exists():
            raise forms.ValidationError("Er bestaat al een account met dit e-mailadres.")
        return email

    def clean_koppelcode(self):
        from .koppelen import zoek
        code = self.cleaned_data.get("koppelcode", "").strip()
        self.cleaned_data["gekoppeld"] = None
        if code:
            try:
                z = zoek(code)
            except ValueError as e:
                raise forms.ValidationError(str(e))
            if z is None:
                raise forms.ValidationError("Deze koppelcode klopt niet of is verlopen.")
            self.cleaned_data["gekoppeld"] = z
        return code

    def clean(self):
        data = super().clean()
        data.setdefault("gekoppeld", None)
        return data

    def save(self, commit=True):
        user = super().save(commit=False)
        user.username = user.email = self.cleaned_data["email"]
        user.first_name = self.cleaned_data["voornaam"]
        user.last_name = self.cleaned_data["naam"]
        user.save()
        return user


class ZwemmerForm(forms.ModelForm):
    geslacht = forms.ChoiceField(choices=[("M", "Man"), ("F", "Vrouw")], widget=forms.RadioSelect)

    class Meta:
        model = Zwemmer
        fields = ["voornaam", "naam", "geboortedatum", "geslacht", "licentie", "swimrankings_id"]
        widgets = {"geboortedatum": DatumInput()}
        labels = {"licentie": "Licentienummer (optioneel)", "swimrankings_id": "Swimrankings-ID (optioneel)"}


# Eén leeg zwemmerblok; leeg laten mag als er een koppelcode is (dat controleert de view).
ZwemmerFormSet = forms.formset_factory(ZwemmerForm, extra=1, min_num=0, max_num=10)


class TijdVeld(forms.CharField):
    def prepare_value(self, value):
        return toon_tijd(value) if hasattr(value, "total_seconds") else value

    def to_python(self, value):
        value = super().to_python(value)
        if not value:
            return None
        try:
            return parse_invoer_tijd(value)
        except ValueError as e:
            raise forms.ValidationError(str(e))


class BesteTijdForm(forms.ModelForm):
    tijd = TijdVeld(help_text="bv. 1:02.86 of 34.12",
                    widget=forms.TextInput(attrs={"inputmode": "decimal", "placeholder": "m:ss.hh"}))
    afstand = forms.TypedChoiceField(coerce=int, choices=[(d, f"{d} m") for d in (25, 50, 100, 200, 400, 800, 1500)])

    class Meta:
        model = BesteTijd
        fields = ["slag", "afstand", "baan", "tijd", "datum", "wedstrijd"]
        widgets = {"datum": DatumInput()}


class UploadForm(forms.Form):
    bestand = forms.FileField(label="Lenex-bestand (.lxf of .lef)")

    def clean_bestand(self):
        f = self.cleaned_data["bestand"]
        if not f.name.lower().endswith((".lxf", ".lef", ".xml")):
            raise forms.ValidationError("Kies een .lxf- of .lef-bestand.")
        if f.size > 20 * 1024 * 1024:
            raise forms.ValidationError("Bestand is te groot (max 20 MB).")
        return f


class SecretarisInstellingenForm(forms.ModelForm):
    limietgroepen = forms.MultipleChoiceField(
        label="Selectiecriteria (limieten die gehaald moeten zijn)",
        required=False, widget=forms.CheckboxSelectMultiple,
    )

    class Meta:
        model = Wedstrijd
        fields = ["naam", "deadline", "dagen_secretaris", "status", "info"]
        widgets = {"deadline": DatumInput(), "info": forms.Textarea(attrs={"rows": 3})}

    def __init__(self, *a, **kw):
        super().__init__(*a, **kw)
        groepen = sorted({(l.naam, l.baan) for l in self.instance.alle_limietlijsten.all()})
        self.fields["limietgroepen"].choices = [
            (f"{n}|{b}", f"{n} ({'25 m' if b == 'SCM' else '50 m'})") for n, b in groepen
        ]
        self.initial["limietgroepen"] = sorted({f"{l.naam}|{l.baan}" for l in self.instance.limietlijsten.all()})
        self.fields["dagen_secretaris"].help_text = "Aantal dagen vóór de deadline van de organisator dat alles bij het sportsecretariaat moet zijn."

    def save(self, commit=True):
        w = super().save(commit)
        gekozen = {tuple(g.split("|", 1)) for g in self.cleaned_data["limietgroepen"]}
        w.limietlijsten.set(l for l in w.alle_limietlijsten.all() if (l.naam, l.baan) in gekozen)
        return w


class TrainerInstellingenForm(forms.ModelForm):
    class Meta:
        model = Wedstrijd
        fields = ["dagen_trainer", "info"]
        widgets = {"info": forms.Textarea(attrs={"rows": 3})}
        help_texts = {"dagen_trainer": "Extra dagen vóór de deadline van het sportsecretariaat dat zwemmers moeten ingeschreven zijn."}


class MeldingenForm(forms.ModelForm):
    class Meta:
        model = Profiel
        fields = ["push_nieuwe_wedstrijd", "push_deadline", "push_wijziging", "push_nieuwe_inschrijving", "push_rolaanvraag"]

    def __init__(self, *a, staf=False, rolbeheer=False, **kw):
        super().__init__(*a, **kw)
        if not rolbeheer:
            del self.fields["push_rolaanvraag"]
        if not staf:
            del self.fields["push_nieuwe_inschrijving"]
        else:
            self.fields["push_deadline"].label = "Herinnering: 1 dag voor de deadline (zwemmers én trainers)"


TOEGELATEN_BIJLAGEN = {"pdf", "jpg", "jpeg", "png", "doc", "docx", "xls", "xlsx"}


class BijlageForm(forms.Form):
    bestand = forms.FileField(label="Bijlage toevoegen", help_text="PDF, afbeelding, Word of Excel, max. 10 MB")
    naam = forms.CharField(label="Naam (optioneel)", max_length=150, required=False)

    def clean_bestand(self):
        f = self.cleaned_data["bestand"]
        ext = f.name.rsplit(".", 1)[-1].lower() if "." in f.name else ""
        if ext not in TOEGELATEN_BIJLAGEN:
            raise forms.ValidationError("Enkel PDF, afbeeldingen, Word- of Excelbestanden.")
        if f.size > 10 * 1024 * 1024:
            raise forms.ValidationError("Bestand is te groot (max. 10 MB).")
        return f
