# Handleiding sportsecretaris

Als sportsecretaris laad je wedstrijden op, bepaal je de deadlines en selectiecriteria en stuur je de goedgekeurde inschrijvingen door naar de organisator.

## 1. Eerste keer

Het **eerste account** dat op een nieuwe installatie wordt aangemaakt, krijgt automatisch de rol *Sportsecretaris*.
Andere rollen ken je toe onder **Gebruikers**.

![pc gebruikers](../screenshots/secretaris-gebruikers.png)

## 2. Wedstrijd opladen

1. **Wedstrijden → + Lenex uploaden**
2. Kies het `.lxf`- of `.lef`-bestand van de organisator (bv. uit SPLASH Meet Manager).
3. **Inlezen**: de app haalt automatisch op:
   - programma, sessies en nummers (met leeftijdsgroepen en kost);
   - de laatste inschrijfdatum en de leeftijdsdatum;
   - de limietlijsten (bv. *Haai*, *Orka*, *Loodsvisje*) en de kwalificatieperiode.

De wedstrijd staat eerst op **Concept**: zwemmers zien hem al en kunnen interesse opgeven, maar nog niet inschrijven. Zwemmers met meldingen aan krijgen een pushmelding.

![pc upload](../screenshots/secretaris-upload.png)

> Ontbreekt de laatste inschrijfdatum in het bestand? Dan zet de app voorlopig 7 dagen voor de wedstrijd en krijg je een waarschuwing. Pas de datum aan bij de instellingen.

## 3. Instellingen per wedstrijd

| Veld | Uitleg |
|---|---|
| Laatste inschrijfdatum | Deadline van de organisator (uit de Lenex) |
| Dagen secretaris | Hoeveel dagen vóór die deadline alles bij jou moet zijn |
| Status | *Concept* → *Open* → *Gesloten* → *Doorgestuurd* |
| Info voor zwemmers | Vrije tekst bovenaan de wedstrijd, bv. voor wie de wedstrijd is |
| Selectiecriteria | Welke limietlijsten gehaald moeten zijn. Zonder vinkje is er geen limiet |

Zet de status op **Open** om de inschrijvingen te openen. Zwemmers met *Interesse* of *Ik doe mee* krijgen dan een melding.

![pc instellingen](../screenshots/secretaris-instellingen.png)

## 4. Nieuwe versie van de uitnodiging

Stuurt de organisator een aangepaste Lenex? Klik bij de instellingen op **Lenex opnieuw uploaden**. Bestaande inschrijvingen en je gekozen limieten blijven behouden.

## 5. Doorsturen

1. Zet na de trainersdeadline de status op **Gesloten**.
2. Klik **⬇ Lenex (.lxf)**: een inschrijvingsbestand met enkel de **goedgekeurde** inschrijvingen (zwemmers, licenties, inschrijftijden). Dat bestand importeert de organisator rechtstreeks in SPLASH.
3. Klik **⬇ Excel** voor een overzicht per nummer en per zwemmer, met de kosten.
4. Stuur het bestand naar de organisator en zet de status op **Doorgestuurd**.

![pc wedstrijden](../screenshots/secretaris-wedstrijden.png)

## 6. Beste tijden bijhouden

Na elke wedstrijd: **Zwemmers → Uitslagen importeren** met de uitslagen-Lenex. Zo kloppen de beste tijden en de limietcontrole. Zwemmers kunnen zelf geen tijden invoeren.

## 7. Backup (beheerder)

Het account **beheerder** ziet een extra menu **Backup**:

- **Nu backup maken**: een zip met alle gegevens en opgeladen Lenex-bestanden.
- Elke nacht om 02:30 volgt automatisch een backup (30 dagen bewaard), plus een aparte databasedump om 03:15.
- **Downloaden**: klik op de bestandsnaam.
- **Terugzetten**: kies een bewaarde backup of upload een zip, en typ `HERSTEL`. Alle huidige gegevens worden vervangen. Daarvoor maakt de app eerst nog een veiligheidsbackup.

![pc backup](../screenshots/beheerder-backup.png)

## 8. Donker thema

![pc donker](../screenshots/secretaris-donker.png)
