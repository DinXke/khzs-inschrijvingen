from datetime import timedelta

from django.conf import settings
from django.db import models

GESLACHT = [("M", "Man"), ("F", "Vrouw")]
BAAN = [("SCM", "25 m"), ("LCM", "50 m")]
SLAG = [
    ("FREE", "Vrije slag"),
    ("BACK", "Rugslag"),
    ("BREAST", "Schoolslag"),
    ("FLY", "Vlinderslag"),
    ("MEDLEY", "Wisselslag"),
]
SLAG_NAAM = dict(SLAG)


class Profiel(models.Model):
    """Rol van een account. Elk nieuw account is een zwemmer(-ouder)."""

    ZWEMMER, TRAINER, SECRETARIS, BEHEERDER = "zwemmer", "trainer", "secretaris", "beheerder"
    ROLLEN = [(ZWEMMER, "Zwemmer"), (TRAINER, "Trainer"), (SECRETARIS, "Sportsecretaris"), (BEHEERDER, "Beheerder")]
    # Rollen met de rechten van het sportsecretariaat; de beheerder kan daarnaast backups en beheer.
    SECRETARIAAT = (SECRETARIS, BEHEERDER)
    STAF = (TRAINER, SECRETARIS, BEHEERDER)

    user = models.OneToOneField(settings.AUTH_USER_MODEL, on_delete=models.CASCADE, related_name="profiel")
    rol = models.CharField(max_length=20, choices=ROLLEN, default=ZWEMMER)
    # Pushmeldingen (per account in te stellen)
    push_nieuwe_wedstrijd = models.BooleanField("Nieuwe wedstrijd open voor inschrijving", default=True)
    push_deadline = models.BooleanField("Herinnering: 1 dag voor de deadline", default=True)
    push_wijziging = models.BooleanField("Trainer/secretariaat wijzigt mijn inschrijving", default=True)
    push_nieuwe_inschrijving = models.BooleanField("(staf) Nieuwe inschrijving om na te kijken", default=False)

    def __str__(self):
        return f"{self.user} ({self.get_rol_display()})"


class Zwemmer(models.Model):
    account = models.ForeignKey(settings.AUTH_USER_MODEL, on_delete=models.CASCADE, related_name="zwemmers")
    voornaam = models.CharField(max_length=60)
    naam = models.CharField(max_length=80)
    geboortedatum = models.DateField()
    geslacht = models.CharField(max_length=1, choices=GESLACHT)
    licentie = models.CharField("licentienummer", max_length=30, blank=True)
    swimrankings_id = models.CharField("Swimrankings-ID", max_length=20, blank=True,
                                       help_text="Het getal achter athleteId= in de link van je Swimrankings-pagina")

    class Meta:
        ordering = ["naam", "voornaam"]
        verbose_name_plural = "zwemmers"

    def __str__(self):
        return f"{self.voornaam} {self.naam}"

    @property
    def swimrankings_url(self):
        if self.swimrankings_id.isdigit():
            return f"https://www.swimrankings.net/index.php?page=athleteDetail&athleteId={self.swimrankings_id}"
        return ""


class BesteTijd(models.Model):
    zwemmer = models.ForeignKey(Zwemmer, on_delete=models.CASCADE, related_name="tijden")
    afstand = models.PositiveIntegerField()
    slag = models.CharField(max_length=10, choices=SLAG)
    baan = models.CharField(max_length=3, choices=BAAN)
    tijd = models.DurationField()
    datum = models.DateField()
    wedstrijd = models.CharField(max_length=120, blank=True)
    bron = models.CharField(max_length=20, default="manueel")  # manueel / uitslagen

    class Meta:
        ordering = ["slag", "afstand", "baan", "tijd"]
        verbose_name_plural = "beste tijden"

    def __str__(self):
        return f"{self.zwemmer} {self.afstand} {self.slag} {self.baan} {self.tijd}"


class Wedstrijd(models.Model):
    CONCEPT, OPEN, GESLOTEN, DOORGESTUURD = "concept", "open", "gesloten", "doorgestuurd"
    STATUS = [(CONCEPT, "Concept"), (OPEN, "Open"), (GESLOTEN, "Gesloten"), (DOORGESTUURD, "Doorgestuurd")]

    naam = models.CharField(max_length=200)
    stad = models.CharField(max_length=100, blank=True)
    zwembad = models.CharField(max_length=150, blank=True)
    baan = models.CharField(max_length=3, choices=BAAN)
    deadline = models.DateField("laatste inschrijfdatum organisator")
    agedate = models.DateField(null=True, blank=True)
    agedate_type = models.CharField(max_length=10, default="YEAR")
    kwalificatie_van = models.DateField(null=True, blank=True)
    kwalificatie_tot = models.DateField(null=True, blank=True)
    lenex_bestand = models.FileField(upload_to="lenex/")
    dagen_secretaris = models.PositiveIntegerField(default=3)
    dagen_trainer = models.PositiveIntegerField(default=2)
    status = models.CharField(max_length=20, choices=STATUS, default=CONCEPT)
    info = models.TextField("info voor zwemmers", blank=True,
                            help_text="Bv. ‘Deze wedstrijd is enkel voor zwemmers van de A-groep.’ Zichtbaar voor iedereen.")
    limietlijsten = models.ManyToManyField("Limietlijst", blank=True, related_name="+",
                                           help_text="Limieten die gehaald moeten zijn om in te schrijven")
    aangemaakt = models.DateTimeField(auto_now_add=True)

    class Meta:
        ordering = ["-deadline"]
        verbose_name_plural = "wedstrijden"

    def __str__(self):
        return self.naam

    @property
    def deadline_trainer(self):
        return self.deadline - timedelta(days=self.dagen_secretaris)

    @property
    def deadline_zwemmer(self):
        return self.deadline_trainer - timedelta(days=self.dagen_trainer)

    @property
    def startdatum(self):
        s = self.sessies.order_by("datum").first()
        return s.datum if s else None


class Sessie(models.Model):
    wedstrijd = models.ForeignKey(Wedstrijd, on_delete=models.CASCADE, related_name="sessies")
    nummer = models.PositiveIntegerField()
    datum = models.DateField()
    starttijd = models.TimeField(null=True, blank=True)
    opwarmen = models.TimeField(null=True, blank=True)

    class Meta:
        ordering = ["nummer"]

    def __str__(self):
        return f"Sessie {self.nummer}"


class Nummer(models.Model):
    """Een EVENT uit de lenex."""

    sessie = models.ForeignKey(Sessie, on_delete=models.CASCADE, related_name="nummers")
    eventid = models.CharField(max_length=20)
    nummer = models.PositiveIntegerField()
    volgorde = models.PositiveIntegerField(default=0)
    geslacht = models.CharField(max_length=1, choices=GESLACHT + [("X", "Gemengd")])
    afstand = models.PositiveIntegerField()
    slag = models.CharField(max_length=10, choices=SLAG)
    naam = models.CharField(max_length=100, blank=True, help_text="Naam uit de lenex bij een speciaal nummer")
    aflossing = models.PositiveIntegerField(default=1)
    ronde = models.CharField(max_length=5, default="TIM")
    inschrijfbaar = models.BooleanField(default=True)
    kost_cent = models.PositiveIntegerField(default=0)

    class Meta:
        ordering = ["sessie__nummer", "volgorde", "nummer"]

    def __str__(self):
        return f"#{self.nummer} {self.omschrijving}"

    @property
    def omschrijving(self):
        afst = f"{self.aflossing}x{self.afstand}" if self.aflossing > 1 else f"{self.afstand}"
        if self.naam:
            return self.naam
        return f"{afst} m {SLAG_NAAM.get(self.slag, self.slag).lower()}"

    @property
    def kost(self):
        return self.kost_cent / 100


class Leeftijdsgroep(models.Model):
    nummer = models.ForeignKey(Nummer, on_delete=models.CASCADE, related_name="leeftijdsgroepen")
    agemin = models.IntegerField(default=-1)
    agemax = models.IntegerField(default=-1)

    def __str__(self):
        if self.agemin == -1 and self.agemax == -1:
            return "open"
        if self.agemax == -1:
            return f"{self.agemin}+"
        if self.agemin == -1:
            return f"t/m {self.agemax}"
        return f"{self.agemin}-{self.agemax}" if self.agemin != self.agemax else f"{self.agemin}"

    def bevat(self, leeftijd):
        return (self.agemin == -1 or leeftijd >= self.agemin) and (self.agemax == -1 or leeftijd <= self.agemax)


class Limietlijst(models.Model):
    wedstrijd = models.ForeignKey(Wedstrijd, on_delete=models.CASCADE, related_name="alle_limietlijsten")
    naam = models.CharField(max_length=100)
    code = models.CharField(max_length=20, blank=True)
    soort = models.CharField(max_length=20, default="DEFAULT", help_text="MINIMUM = je moet trager zijn dan de tijd")
    baan = models.CharField(max_length=3, choices=BAAN)
    geslacht = models.CharField(max_length=1, choices=GESLACHT)
    agemin = models.IntegerField(default=-1)
    agemax = models.IntegerField(default=-1)

    class Meta:
        ordering = ["naam", "baan", "geslacht", "agemin"]

    def __str__(self):
        return f"{self.naam} {self.baan} {self.geslacht} {self.agemin}-{self.agemax}"


class Limiet(models.Model):
    lijst = models.ForeignKey(Limietlijst, on_delete=models.CASCADE, related_name="limieten")
    afstand = models.PositiveIntegerField()
    slag = models.CharField(max_length=10, choices=SLAG)
    tijd = models.DurationField()


class Inschrijving(models.Model):
    INGEDIEND, GOEDGEKEURD, AFGEKEURD = "ingediend", "goedgekeurd", "afgekeurd"
    STATUS = [(INGEDIEND, "Ingediend"), (GOEDGEKEURD, "Goedgekeurd"), (AFGEKEURD, "Afgekeurd")]

    zwemmer = models.ForeignKey(Zwemmer, on_delete=models.CASCADE, related_name="inschrijvingen")
    nummer = models.ForeignKey(Nummer, on_delete=models.CASCADE, related_name="inschrijvingen")
    inschrijftijd = models.DurationField(null=True, blank=True)
    inschrijfbaan = models.CharField(max_length=3, choices=BAAN, blank=True)
    status = models.CharField(max_length=20, choices=STATUS, default=INGEDIEND)
    opmerking = models.CharField("opmerking trainer", max_length=200, blank=True)
    aangemaakt = models.DateTimeField(auto_now_add=True)
    door = models.ForeignKey(settings.AUTH_USER_MODEL, null=True, blank=True, on_delete=models.SET_NULL, related_name="+")

    class Meta:
        unique_together = [("zwemmer", "nummer")]
        ordering = ["nummer__sessie__nummer", "nummer__volgorde"]
        verbose_name_plural = "inschrijvingen"


class Melding(models.Model):
    """Wijziging door een trainer/secretaris aan de inschrijvingen van een zwemmer."""

    zwemmer = models.ForeignKey(Zwemmer, on_delete=models.CASCADE, related_name="meldingen")
    wedstrijd = models.ForeignKey(Wedstrijd, on_delete=models.CASCADE, related_name="meldingen")
    door = models.ForeignKey(settings.AUTH_USER_MODEL, null=True, on_delete=models.SET_NULL, related_name="+")
    tekst = models.TextField()
    gelezen = models.BooleanField(default=False)
    aangemaakt = models.DateTimeField(auto_now_add=True)

    class Meta:
        ordering = ["-aangemaakt"]
        verbose_name_plural = "meldingen"

    @property
    def regels(self):
        return self.tekst.splitlines()

    @property
    def door_naam(self):
        if not self.door:
            return "De club"
        rol = getattr(getattr(self.door, "profiel", None), "get_rol_display", lambda: "")()
        naam = self.door.first_name or self.door.username
        return f"{rol} {naam}".strip()


class PushAbonnement(models.Model):
    """Eén browser/toestel dat pushmeldingen ontvangt."""

    user = models.ForeignKey(settings.AUTH_USER_MODEL, on_delete=models.CASCADE, related_name="push_abonnementen")
    endpoint = models.URLField(max_length=1000, unique=True)
    p256dh = models.CharField(max_length=200)
    auth = models.CharField(max_length=100)
    toestel = models.CharField(max_length=200, blank=True)
    aangemaakt = models.DateTimeField(auto_now_add=True)


class VerstuurdeHerinnering(models.Model):
    """Voorkomt dat een deadline-herinnering twee keer vertrekt."""

    wedstrijd = models.ForeignKey(Wedstrijd, on_delete=models.CASCADE)
    soort = models.CharField(max_length=30)
    datum = models.DateField()

    class Meta:
        unique_together = [("wedstrijd", "soort", "datum")]


class Deelname(models.Model):
    """Voorlopige keuze van een zwemmer, al mogelijk zodra de wedstrijd opgeladen is."""

    INTERESSE, DOET_MEE, DOET_NIET_MEE = "interesse", "doet_mee", "doet_niet_mee"
    KEUZES = [(INTERESSE, "Interesse"), (DOET_MEE, "Ik doe mee"), (DOET_NIET_MEE, "Ik doe niet mee")]
    ACTIEF = (INTERESSE, DOET_MEE)

    zwemmer = models.ForeignKey(Zwemmer, on_delete=models.CASCADE, related_name="deelnames")
    wedstrijd = models.ForeignKey(Wedstrijd, on_delete=models.CASCADE, related_name="deelnames")
    keuze = models.CharField(max_length=20, choices=KEUZES)
    geweigerd = models.BooleanField(default=False)
    weigerreden = models.CharField(max_length=200, blank=True)
    # Trainer zet de inschrijving vast; het secretariaat kan ze dan al ingeven (bv. in SPLASH).
    gefinaliseerd = models.BooleanField(default=False)
    gefinaliseerd_door = models.ForeignKey(settings.AUTH_USER_MODEL, null=True, blank=True, on_delete=models.SET_NULL,
                                           related_name="+")
    gefinaliseerd_op = models.DateTimeField(null=True, blank=True)
    ingegeven = models.BooleanField(default=False)
    gewijzigd_na_ingave = models.BooleanField(default=False)
    gewijzigd = models.DateTimeField(auto_now=True)

    class Meta:
        unique_together = [("zwemmer", "wedstrijd")]


class Bijlage(models.Model):
    """Bestand bij de info van een wedstrijd (bv. reglement, busregeling). Enkel voor aangemelde gebruikers."""

    wedstrijd = models.ForeignKey(Wedstrijd, on_delete=models.CASCADE, related_name="bijlagen")
    bestand = models.FileField(upload_to="bijlagen/%Y/")
    naam = models.CharField(max_length=150)
    door = models.ForeignKey(settings.AUTH_USER_MODEL, null=True, on_delete=models.SET_NULL, related_name="+")
    toegevoegd = models.DateTimeField(auto_now_add=True)

    class Meta:
        ordering = ["toegevoegd"]
        verbose_name_plural = "bijlagen"

    def __str__(self):
        return self.naam

    @property
    def extensie(self):
        return self.bestand.name.rsplit(".", 1)[-1].lower() if "." in self.bestand.name else ""

    @property
    def icoon(self):
        return {"pdf": "📄", "jpg": "🖼️", "jpeg": "🖼️", "png": "🖼️", "doc": "📝", "docx": "📝",
                "xls": "📊", "xlsx": "📊"}.get(self.extensie, "📎")
