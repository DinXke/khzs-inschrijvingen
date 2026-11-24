# UX- en toegankelijkheidsreview KHZS Leden (november 2026)

Review van de WordPress-plugin **KHZS Leden** op de demo https://khzs.be/leden-demo/ (stand van `origin/main`, 15/11/2026), bekeken op gsm-formaat (375×812) en desktop (1366×820), in het donkere en het lichte thema, met de rollen ouder, trainer, bestuur en demo (alle rollen). Code nagekeken in `wordpress/khzs-leden/` (views, `assets/app.css`, `includes/velden.php`, `views/_layout.php`). Alleen bekeken en gerapporteerd; er is geen code gewijzigd.

## Algemene indruk

De app is helder opgebouwd: één consistente componentenset (kaarten, badges, knoppen van 44 px, zichtbare labels), korte natuurlijke Nederlandse teksten, een tabbalk met "Meer", licht/donker thema, een service worker met offlinepagina en geen consolefouten. De kerntaken werken: een ouder schrijft een kind in met kindkeuze-chips en een plakkende Bewaar-balk, een trainer bevestigt aanwezigheid en vinkt zwemmers af met "iedereen staat aangevinkt", de chat verstuurt zonder herladen. De grootste pijnpunten zijn (1) onleesbare witte tekst op groene/rode knoppen in het donkere thema, (2) drie banners (demo, privacy, installatie) die op een gsm het eerste scherm opslokken, (3) een tabbalk die per rol niet de belangrijkste taken bovenaan zet (bestuur vindt rolaanvragen en verloning enkel onder "Meer", zonder teller), en (4) enkele lange schermen en brede tabellen die op een gsm niet goed werken. Dat zijn allemaal gerichte, kleine ingrepen; de fundamenten zijn goed.

## Bevindingen (geprioriteerd)

### Hoog

**H1. Donker thema: witte tekst op groene, rode en grijze knoppen is onleesbaar** — alle schermen, alle rollen.
Wat: in het donkere thema staat `#fff` op `--ok` (#5fd39a), `--fout` (#ff8f84) en `--zacht` (#93a8ae). Gemeten contrast: 1,86:1, 2,21:1 en 2,48:1 (norm 4,5:1). Dat raakt `.knop.ok`/`.knop.gevaar` ("✓ Ja, aanwezig", "Afmelden", "Weigeren", "Verwijderen"), de gekozen segmentknoppen "Ik doe mee"/"Ik doe niet mee" en Ja/Nee bij RSVP, de rode teller op de tabbalk (`.tab-badge`) en `.com-teller`-achtige elementen. In het lichte thema is alles in orde (≥ 4,67:1).
Waarom: dit zijn precies de bevestigingsknoppen; wie 's avonds op de gsm werkt (standaard donker) leest ze slecht.
Voorstel: in `assets/app.css` in het donkere blok tekstkleuren voor die vlakken toevoegen, zoals al gebeurt voor `--primair-tekst`: bv. `--ok-tekst: #04201f`, `--fout-tekst: #2a0d0b`, `--zacht-tekst: #0c1315`, en die gebruiken in `.knop.ok`, `.knop.gevaar`, `.segment button.gekozen.k-doet_mee/.k-doet_niet_mee`, `.com-rsvp .r-ja/.r-nee` en `.tab-badge` (of de badge donkerrood maken met witte tekst).

**H2. Drie banners boven de inhoud op een gsm** — alle pagina's na aanmelden.
Wat: demo-balk (3 regels), privacy-melding "Lees hoe de club met je gegevens omgaat…" (3 regels, op elke pagina tot akkoord) en de installatiemelding (onderaan, over de inhoud heen, bv. over de kindkeuze op de wedstrijdpagina). Op Kalender en Mijn trainingen staat de eigenlijke inhoud daardoor volledig onder de vouw (zie schermafbeeldingen kalender).
Waarom: het eerste wat een nieuwe ouder ziet is drie keer "lees dit", niet de wedstrijden.
Voorstel: (a) de privacy-melding één regel maken met een knop ("Privacyverklaring lezen en akkoord ›") of ze tot een blokkerende stap maken bij de eerste aanmelding (zoals de welkom-stappen) in plaats van een eeuwige banner; (b) de demo-balk op de gsm tot één regel beperken ("Demo: verzonnen gegevens, elke nacht gereset") met de reset-knop enkel op desktop of onder Meer; (c) de installatiemelding pas tonen na de tweede sessie of op de startpagina, niet op formulierpagina's. Bestanden: `views/_layout.php`, `views/demo-balk.php`, `includes/pwa.php`.

**H3. Tabbalk: prioriteit per rol klopt niet; verborgen items hebben geen teller** — `views/_layout.php`, `includes/app.php` (`menu()`), `modules/*/module.php`.
Wat: de balk toont de eerste vier menu-items op volgorde (10, 15, 20, 30…), de rest komt onder "Meer". Gevolgen: bij een ouder staat **Uitleg** (in de demo leeg) in de balk en **Meldingen** onder Meer; bij bestuur en demo staan **Gebruikers** (rolaanvragen) en **Verloning** onder Meer, en de Meer-knop krijgt geen teller als een verborgen item er een heeft (`$_badges` wordt enkel in de balk gebruikt). Bij een ouder ziet de knop "Profiel" uit op een pagina met titel "Mijn zwemmers".
Waarom: een bestuurder opent de app om aanvragen goed te keuren en vindt die pas na twee tikken, zonder signaal dat er iets wacht.
Voorstel: een vaste prioriteit per rol (bv. Gebruikers vóór Trainingen voor bestuur; Uitleg altijd onder Meer), een teller op "Meer" = som van de tellers van de verborgen items (incl. ploeg- en rolaanvragen, die nu enkel in de desktop-nav verschijnen), en de tab "Profiel" dezelfde naam geven als de pagina.

**H4. "Doe je mee?" en de nummers zijn twee losse formulieren zonder duidelijke samenhang** — wedstrijdpagina, ouder (`modules/inschrijvingen/views/wedstrijd.php`).
Wat: de drie segmentknoppen (Interesse / Ik doe mee / Ik doe niet mee) zijn submit-knoppen die meteen bewaren, zonder dat dat zichtbaar is; "Interesse" staat gekleurd zodra een deelname bestaat en leest als een actieve keuze. Daaronder vink je nummers aan, maar "Huidig: 0 nummer(s)" in de Bewaar-balk verandert niet mee bij het aanvinken; pas na Bewaren. Het label "Interesse" is voor ouders niet vanzelfsprekend (bedoeld als "misschien, hou me op de hoogte").
Waarom: dit is dé kerntaak van de ouder; twijfel over "is mijn keuze nu bewaard?" leidt tot dubbele inschrijvingen of telefoontjes naar de trainer.
Voorstel: segment hernoemen naar "Misschien / Ja, ik doe mee / Nee" met een hulptekst "Je keuze wordt meteen bewaard", na een klik kort "Bewaard ✓" tonen; de teller in de Bewaar-balk live bijwerken via een `change`-listener ("2 nummers gekozen · nog niet bewaard") en de Bewaar-knop pas actief maken als er iets wijzigde.

**H5. Startpagina van de trainer is te lang** — `modules/trainers/views/mijn.php`, rol trainer.
Wat: "Was je aanwezig?" en "Zwemmers aanduiden" staan goed bovenaan, maar daarna volgen 20 trainingen van de komende 4 weken (elk met badge "Ik geef training" + "Kan niet") en daarna de volledige lijst "Welke trainingen geef ik normaal?" (eenmalige instelling). Op een gsm is dat meer dan acht schermen; de knoppenrij bovenaan (Kalender, Planning, Zwemmers, Overzicht, Beheren) neemt drie regels in.
Voorstel: standaard de komende 7 dagen tonen met "Toon 4 weken"; de vaste trainingen in een ingeklapte `<details>` ("Mijn vaste trainingen (5) ›"); de knoppenrij tot twee knoppen beperken en de rest in Kalender onderbrengen.

**H6. Brede tabellen op de gsm: Gebruikers & rollen, Verloning** — `views/gebruikers.php`, `modules/trainers/views/uren.php`, rol bestuur/penningmeester.
Wat: Verloning is een tabel van 628 px in een viewport van 375 px: de kolommen Status en de knop "✓ Uitbetaald" staan buiten beeld, zonder visuele hint dat je kan scrollen. Gebruikers & rollen toont per gebruiker twee selects en badge-knoppen in een cel; op de gsm wordt dat een lange smalle kolom met "+ rol"-selects die bij `onchange` meteen bewaren (geen bevestiging, foutgevoelig op touch).
Voorstel: onder 700 px elke rij als kaart tonen (naam, rollen als badges, één knop "Rol toevoegen" die een klein formulier opent); in Verloning per trainer een kaart met de cijfers en de knop "Uitbetaald" rechts; de tabel enkel op desktop. Een scroll-schaduw op `.tabelwrap` als minimale tussenstap.

**H7. Formulieren: keuzerondjes, foutmeldingen en groepslabels zijn niet toegankelijk gekoppeld** — `includes/velden.php` (`veld_radio`, `veld_html`).
Wat: het groepslabel "Geslacht" heeft `for` naar het eerste keuzerondje, zodat dat rondje de naam "Geslacht" krijgt in plaats van "Man" (te zien in de toegankelijkheidsboom); er is geen `fieldset`/`legend`. Foutmeldingen (`.errorlist`) staan onder het veld zonder `aria-describedby`/`aria-invalid`, en bij een fout springt de focus niet naar het eerste foute veld.
Voorstel: keuzerondjes in `<fieldset><legend>Geslacht</legend>`; in `veld_html` bij een fout `aria-invalid="true" aria-describedby="id_x-fout"` toevoegen en bovenaan het formulier een samenvatting met links naar de velden (of `autofocus` op het eerste foute veld).

### Middel

**M1. Datumnotatie inconsistent en half Engels** — `includes/kern.php` (`MAANDEN_KORT`), alle lijsten.
Wat: "20 Nov 2026", "za 5 Dec", "11 Okt" (hoofdletter, Engelse spelling "Jan/Feb/Mrt/Dec") naast "vrijdag 20 november", "22/04/2014", "7/11" en "do 10 Dec". Voorstel: Nederlandse afkortingen in kleine letters ("5 dec."), en per context één stijl: lijsten "za 5 dec", detail "zaterdag 5 december".

**M2. Statusbadges op de wedstrijdkaart zijn moeilijk te lezen** — `wedstrijden.php`, ouder.
Wat: "geweigerd", "3 ingeschreven", "🔔 gewijzigd door de club", "Ik doe niet mee" staan naast elkaar; "geweigerd" (deelname geweigerd door de club) leest als "afgekeurd nummer"; bij één kind staat de naam er niet bij. Voorstel: één regel per kind ("Finn: doet mee · 3 nummers · gewijzigd door de club"), "Deelname geweigerd" voluit.

**M3. Lege tab "Uitleg"** — rol ouder/zwemmer.
Wat: "Er zijn nog geen filmpjes of handleidingen geïnstalleerd" is een beheerdersboodschap in een ledenscherm. Voorstel: de tab verbergen zolang er niets staat, of vullen met de korte stappen die al in de app bestaan (inschrijven, koppelcode, meldingen aanzetten op iPhone).

**M4. RSVP bij "Vraag naar hulp": knoppen staan vóór de velden** — `modules/communicatie/views/_aankondiging.php`.
Wat: Ja/Misschien/Nee zijn submit-knoppen; het aantal personen en de opmerking staan eronder. Wie eerst op "Ja" tikt, stuurt "1 persoon" zonder opmerking. "Met [1] persoon/personen" is onhandig. Voorstel: velden boven de knoppen of knoppen als radio + "Bevestigen"; label "Aantal personen (jij inbegrepen)".

**M5. Welkom-stappen: eerste vraag is "Zwem je zelf?" met als primaire knop "Ja, voeg mij toe"** — `modules/inschrijvingen/views/welkom.php`.
Wat: de meeste nieuwe accounts zijn ouders; de eerste stap stuurt hen naar de uitzondering (masters). Voorstel: stap 1 "Heb je kinderen die zwemmen?", stap 2 "Zwem je zelf?" met "Nee" als primaire knop; of één stap met twee gelijkwaardige keuzes.

**M6. Mijn zwemmers: koppelcode-formulier permanent zichtbaar** — `zwemmers.php`.
Wat: onder de kinderen staat altijd het invoerveld "Zwemmer koppelen met een code" plus drie alinea's uitleg; voor de meeste ouders ruis, en de uitleg over "+ Mezelf" staat los van de knop. Voorstel: in een `<details>` "Mijn kind staat bij de andere ouder ›" (zoals in de welkom-stap); bij de koppelcode op de zwemmerpagina een knop "Code delen" (`navigator.share`/kopiëren).

**M7. Emoji als functionele iconen en icoonknoppen zonder naam** — overal (`ℹ️ 📎 🔔 🙋 🏊 📅 ⚙ ✕ ➤`).
Wat: emoji verschillen per toestel (op Windows vaak zwart-wit), schermlezers lezen "informatiebron", "paperclip"; knoppen "✕" (uit de ploeg, tijd verwijderen) en "⚙" hebben enkel een `title`, wat op touch niets doet. Voorstel: dezelfde SVG-iconen als de tabbalk, met `aria-hidden` op decoratie en `aria-label` op icoonknoppen; bij "✕" liefst tekst ("Verwijderen").

**M8. Desktop-kopbalk overvol bij meerdere rollen** — `views/_layout.php`.
Wat: met alle rollen staan er 12 items (Wedstrijden … Backup, Beheer, Afmelden) en breekt de app-naam in drie lijnen op 1366 px; Meldingen is enkel een 🔔 zonder tekst, Webshop en Afmelden staan tussen de pagina's. Voorstel: secundaire items (Meldingen, Uitleg, Webshop, Backup, Beheer, Afmelden) in een account-menu rechts met de naam van de gebruiker; de app-naam `white-space: nowrap`.

**M9. Trainers in een trainingsrij: "Bram?, Tom?, Jens?"** — `modules/trainers/views/_training.php`.
Wat: "?", "✓" en "✗" achter namen betekenen "niet bevestigd/aanwezig/afwezig", enkel via `title`; op een gsm is er geen hover. Voorstel: korte tekst ("3 nog te bevestigen") of een legende bovenaan de kaart, zoals de kalender er al een heeft.

**M10. Terminologie "Meldingen"** — `views/meldingen.php`.
Wat: de pagina Meldingen bevat push-instellingen én de knoppen "Mijn rollen" en "Mijn gegevens": het is eigenlijk "Instellingen/Mijn account". Een gebruiker die "Meldingen" opent, verwacht de meldingen zelf. Voorstel: hernoemen naar "Instellingen" (met onderdelen Meldingen, Rollen, Gegevens, Thema, Afmelden); de lijst "Wijzigingen door de club" is dan de plek voor echte meldingen.

**M11. Bevestigingen via `confirm()`** — veel formulieren (`onsubmit="return confirm(...)"`).
Wat: werkt, maar de browser toont "OK/Annuleren" in een eigen stijl, niet herkenbaar als app, en in een geïnstalleerde PWA op iOS soms onverwacht. Voorstel: één `<dialog>`-component met de tekst van de actie en knoppen "Annuleren" / "Ja, verwijderen" (destructieve knop rechts, rood).

**M12. Aanmeldpagina: verouderde ondertitel en inconsistente foutmelding** — `views/aanmelden.php`.
Wat: "Wedstrijdinschrijvingen van de Hasseltse Zwemvereniging Spartacus" terwijl de app nu ook berichten en trainingen bevat; het veld heet "E-mail of gebruikersnaam", de fout zegt "E-mail of wachtwoord klopt niet". Voorstel: "De ledenapp van de …"; fout: "Gebruikersnaam/e-mail of wachtwoord klopt niet."

### Laag

**L1. Ploegkeuze is een lijst van 15 opties zonder groepering** — `zwemmer_form.php`/`welkom.php`. Voorstel: `<optgroup>` Competitie / Zwemschool; de kleur van de zwemschoolgroep als bolletje.

**L2. Focus en toetsenbord** — `app.css`. Enkel `input`/`select` hebben een eigen focusstijl; knoppen, links en de tabbalk vertrouwen op de browserstandaard (meestal zichtbaar, maar dun op gekleurde knoppen). Geen "Ga naar inhoud"-link. Het Meer-paneel zet focus en sluit met Escape (goed), maar houdt de focus niet vast. Voorstel: `:focus-visible { outline: 3px solid var(--accent); outline-offset: 2px }` voor alles; skip-link.

**L3. Kleine toetsdoelen** — `.knop.mini` is 34 px (bv. "Demo terugzetten", "Dempen", "✕"), de thema-knop 38 px. Voorstel: mini op 40 px en minstens 44 px aanraakoppervlak via padding.

**L4. Chatpagina** — `kanaal.php`. De kop (breadcrumb, titel, beschrijving, vastgepind, threads) staat boven de berichten; het invoerveld plakt onderaan (goed). De paperclip-knop (`📎`) is enkel voor moderators, de knop "➤" heeft wel een aria-label. Voorstel: alleen de breadcrumb boven, de rest in een "Info"-uitklapper; "Dempen" als icoonknop met tekst in de uitklapper.

**L5. Flash "Bewaard: 1 toegevoegd, 0 geschrapt."** — `acties.php`. Technisch jargon; voorstel: "Bewaard: 100 m vrije slag toegevoegd." / "Bewaard: niets gewijzigd."

**L6. Wachtwoord vergeten** gaat naar de WordPress-loginpagina (`wp_lostpassword_url`) in een andere stijl. Acceptabel; vermeld het in de tekst ("Je komt op de website van de club").

## Vijf quick wins

1. **Tekstkleur op ok/gevaar/zacht-vlakken in het donkere thema** (H1): drie CSS-variabelen en vijf selectors in `assets/app.css`. Grootste toegankelijkheidswinst voor het minste werk.
2. **Privacy-melding tot één regel met knop en demo-balk tot één regel** (H2): twee sjablonen, geen logica. Geeft op de gsm meteen een half scherm terug.
3. **Tabbalk: Uitleg naar Meer, Gebruikers vooraan voor bestuur, teller op "Meer"** (H3): volgorde-getallen in de `khzs_menu`-filters aanpassen en in `_layout.php` de som van `$_badges` van de verborgen items op de Meer-knop zetten.
4. **Live teller en duidelijke labels bij "Doe je mee?" en de Bewaar-balk** (H4): één `change`-listener en drie labels.
5. **Nederlandse maandafkortingen in kleine letters** (M1): één constante in `includes/kern.php`.

## Wat al goed werkt

- Eén consistente stijl: kaarten, badges, 44 px-knoppen, zichtbare labels, hulptekst bij velden, foutmelding bij het veld; licht en donker thema (licht haalt overal ≥ 4,5:1).
- Inschrijven: kindkeuze-chips, enkel de nummers waarvoor het kind in aanmerking komt, beste tijden en limieten bij het nummer, plakkende Bewaar-balk, wijzigingen door de club als duidelijke lijst.
- Trainers: "Was je aanwezig?" bovenaan met "Ik was overal aanwezig", zwemmers aanduiden met "iedereen staat aangevinkt, vink af wie er niet was" en een teller op de knop, legende met vijf statussen, maandkalender als stipjes op de gsm.
- Communicatie: secties → kanalen → threads met ongelezen-tellers, aankondigingen bovenaan (max. drie), RSVP met voortgangsbalk, versturen zonder herladen, "In dit kanaal posten enkel trainers en bestuur" als lege toestand.
- Onboarding in drie stappen met "Overslaan", koppelcode-flow met duidelijke uitleg, "+ Mezelf" voor masters.
- Techniek: geen consolefouten, geen horizontaal scrollen op paginaniveau, service worker met offlinepagina, lichte pagina's zonder frameworks, Meer-paneel met Escape en focus, `aria-live` op de chat.

## Testomgeving en schermafbeeldingen

- Browser: ingebouwde browser (Chromium), viewports 375×812 en 1366×820, donker (systeem) en licht (emulatie). Rollen: ouder, trainer, bestuur, demo. Geen consolefouten op de bezochte pagina's.
- Contrast gemeten met de WCAG-formule op de CSS-variabelen uit `assets/app.css`.
- Schermafbeeldingen van deze sessie staan in `C:\Users\BjörnScheepers\.claude\projects\C--Users-Bj-rnScheepers-khzs-inschrijvingen\148f7877-de68-4597-8a1e-68b6865ce3a8\tool-results\` (bestanden `mcp-Claude_Browser-blob-17946986…` t/m `…17946991…`): aanmelden, wedstrijdenlijst ouder, wedstrijdpagina met kindkeuze en Bewaar-balk, Mijn zwemmers, Berichten, kanaal, Meldingen, Mijn rollen, Zwemmer toevoegen, Uitleg (leeg), Mijn trainingen, trainingspagina, Aankondigingen, Verloning (gsm), Kalender week/maand (gsm), Wedstrijden (desktop), wedstrijdpagina in het lichte thema.
