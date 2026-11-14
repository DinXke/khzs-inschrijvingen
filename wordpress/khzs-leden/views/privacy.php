<?php namespace KHZS; defined('ABSPATH') || exit;
/*
 * Privacyverklaring (AVG). Sjabloon: de club laat de tekst juridisch nakijken. De gegevens van de club komen uit
 * Instellingen → KHZS Leden. Zelfde tekst als templates/privacy.html in Django (plus de module Trainers).
 */
$_mail = $gegevens['email'] ? '<a href="mailto:' . esc_attr($gegevens['email']) . '">' . e($gegevens['email']) . '</a>' : '';
$_trainers = module_actief('trainers'); ?>
<h1>Privacy en cookies</h1>
<p class="klein" style="margin-top:-.6rem">Privacyverklaring van <?= e(app_naam()) ?> · versie <?= e($gegevens['versie']) ?></p>
<?php if (is_demo()): ?><div class="melding info">Dit is de demo met verzonnen gegevens. Deze verklaring geldt voor de echte app van de club.</div><?php endif; ?>
<?php if ($moet_lezen): ?><form method="post" class="kaart" action="<?= esc_url(app_url('privacy/')) ?>"><?= nonce_veld() ?>
  <p style="margin-top:0"><b>Lees hieronder hoe de club met je gegevens omgaat.</b> Klik daarna op de knop.</p>
  <button class="vol">Ik heb de privacyverklaring gelezen en ga akkoord</button>
</form><?php endif; ?>

<div class="kaart">
<h2 style="margin-top:0">1. Wie is verantwoordelijk?</h2>
<p>De app <?= e(app_naam()) ?> is van <b><?= e($gegevens['verantwoordelijke']) ?></b><?= $gegevens['adres'] ? ', ' . e($gegevens['adres']) : '' ?><?= $gegevens['ondernemingsnummer'] ? ' (ondernemingsnummer ' . e($gegevens['ondernemingsnummer']) . ')' : '' ?>.
  De club is de <i>verwerkingsverantwoordelijke</i>: zij beslist welke gegevens de app bijhoudt en waarvoor.</p>
<p>Vragen over je gegevens of over deze verklaring? Mail naar <?= $_mail ?: 'het secretariaat van de club (zie de website)' ?>.</p>

<h2>2. Welke gegevens?</h2>
<ul>
  <li><b>Je account</b>: voornaam, naam, e-mailadres en je wachtwoord (enkel versleuteld bewaard, niemand kan het lezen). Ook je rollen in de club (zwemmer, ouder, trainer, bestuurder…), je aanvragen voor een rol, en wanneer je de privacyverklaring aanvaardde en het laatst aanmeldde.</li>
  <li><b>Zwemmers</b>: voornaam, naam, geboortedatum, geslacht, en als je ze invult je licentienummer en je Swimrankings-ID. Veel zwemmers zijn minderjarig: hun gegevens worden ingevuld door een ouder of voogd, die daarvoor toestemming geeft.</li>
  <li><b>Ploegen</b>: in welke ploeg een zwemmer zit of wil zitten, en wie de ploeg traint.</li>
  <li><b>Wedstrijden</b>: of een zwemmer wil deelnemen, de inschrijvingen met inschrijftijden, de beste tijden en uitslagen, en de wijzigingen die een trainer of het secretariaat daaraan deed.</li>
  <?php if ($_trainers): ?><li><b>Trainers</b>: welke trainingen je geeft, afmeldingen en vervangingen, de gepresteerde uren, je vergoeding (tarief) en de uitbetalingen. Voor zwemmers: op welke trainingen ze aanwezig waren (en of ze te laat kwamen, vroeger weggingen of ziek waren), zoals een trainer dat aanduidt.</li><?php endif; ?>
  <li><b>Pushmeldingen</b>: zet je meldingen aan, dan bewaren we per toestel het adres van de pushdienst van je browser, de sleutels om berichten te versleutelen en het type browser. Je voorkeuren (welke meldingen je wil) horen bij je account.</li>
  <li><b>Technische gegevens</b>: om je aangemeld te houden, gebruikt de app cookies (zie punt 7). De server houdt een logboek bij met o.a. IP-adressen, om fouten en misbruik op te sporen.</li>
</ul>

<h2>3. Waarom?</h2>
<ul>
  <li><b>Ledenwerking en wedstrijden</b>: zwemmers inschrijven voor wedstrijden, ploegen indelen, de inschrijvingen nakijken en doorgeven. Dat is nodig om je lidmaatschap uit te voeren (rechtsgrond: overeenkomst, en het gerechtvaardigd belang van de club).</li>
  <li><b>Inschrijvingen doorgeven</b> aan de organisator van een wedstrijd en de zwemfederatie: zonder die gegevens kan een zwemmer niet deelnemen (overeenkomst).</li>
  <?php if ($_trainers): ?><li><b>Trainers</b>: de trainingen plannen, bijhouden welke zwemmers op de trainingen komen, en de vergoedingen berekenen en uitbetalen. De uitbetalingen moet de club bewaren voor de boekhouding (wettelijke verplichting).</li><?php endif; ?>
  <li><b>Meldingen</b> over nieuwe wedstrijden, deadlines en wijzigingen: enkel als je ze zelf aanzet (toestemming). Je kan ze altijd weer uitzetten onder <i>Meldingen</i>.</li>
  <li><b>Veiligheid</b>: aanmelden, misbruik voorkomen en backups maken (gerechtvaardigd belang).</li>
</ul>
<p>De club gebruikt je gegevens niet voor reclame, verkoopt ze niet en maakt geen profielen.</p>

<h2>4. Wie ziet je gegevens?</h2>
<ul>
  <li><b>Jij</b>, en de andere accounts die aan dezelfde zwemmer gekoppeld zijn (bv. de andere ouder).</li>
  <li><b>Trainers en het sportsecretariaat</b>: de zwemmers, hun ploegen, tijden en inschrijvingen, om de wedstrijden te regelen.</li>
  <li><b>Bestuurders</b>: de accounts en hun rollen, en de ploegen.</li>
  <?php if ($_trainers): ?><li><b>Trainers en bestuur</b>: de aanwezigheid van zwemmers op de trainingen (de trainers van de competitie voor de competitie, die van de zwemschool voor de zwemschool). Een zwemmer ziet enkel de eigen aanwezigheden, een ouder die van de eigen kinderen.</li><?php endif; ?>
  <?php if ($_trainers): ?><li><b>De penningmeester</b>: de uren, tarieven en uitbetalingen van de trainers.</li><?php endif; ?>
  <li><b>Beheerders</b> van de app en de website: alle gegevens, om de app te onderhouden en backups te maken.</li>
  <li><b>Buiten de club</b>:
    <ul>
      <li>de organisator van een wedstrijd en de zwemfederatie krijgen de inschrijvingen (naam, geboortedatum, geslacht, licentienummer, nummers en inschrijftijden);</li>
      <li>dienstverleners die de club helpt om de app te laten werken: de hosting van de website, eventueel Cloudflare (beveiligde verbinding) en, als je meldingen aanzet, de pushdienst van je browser (Google, Mozilla, Apple of Microsoft). De inhoud van een pushmelding is versleuteld.</li>
    </ul>
    Sommige van die diensten kunnen gegevens buiten de Europese Unie verwerken. Ze doen dat met de waarborgen van de AVG (bv. de standaardcontractbepalingen van de Europese Commissie).</li>
</ul>

<h2>5. Hoe lang?</h2>
<div class="tabelwrap"><table>
  <tr><th>Gegevens</th><th>Bewaard</th></tr>
  <tr><td>Account, rollen, zwemmers, ploegen, tijden en inschrijvingen</td><td>Zolang je account bestaat. Verwijder je je account of een zwemmer, dan verdwijnen die gegevens.</td></tr>
  <?php if ($_trainers): ?><tr><td>Uren, tarieven en uitbetalingen van trainers</td><td><?= BOEKHOUDING_JAREN ?> jaar na het einde van het boekjaar (bewaarplicht voor de boekhouding), ook als je je account verwijdert</td></tr><?php endif; ?>
  <tr><td>Wijzigingen door de club aan een inschrijving</td><td><?= MELDINGEN_DAGEN ?> dagen</td></tr>
  <tr><td>Behandelde aanvragen voor een rol of ploeg</td><td><?= AANVRAGEN_DAGEN ?> dagen na de beslissing</td></tr>
  <tr><td>Toestellen voor pushmeldingen</td><td>Tot je meldingen uitzet, of na <?= PUSH_ONGEBRUIKT_DAGEN ?> dagen zonder aanmelden</td></tr>
  <tr><td>Koppelcode voor de andere ouder</td><td>7 dagen</td></tr>
  <tr><td>Backups</td><td>30 dagen</td></tr>
  <tr><td>Inschrijvingen die al doorgegeven zijn aan een organisator</td><td>Bij de organisator en de federatie, volgens hun eigen regels</td></tr>
</table></div>
<p class="klein">De club kijkt elk seizoen na welke accounts niet meer gebruikt worden, en verwijdert ze.</p>

<h2>6. Je rechten</h2>
<p>Je hebt het recht om je gegevens in te kijken, te laten verbeteren of te laten wissen, om de verwerking te laten beperken, om bezwaar te maken, en om je gegevens mee te nemen (overdraagbaarheid). Gaf je toestemming, dan kan je die altijd intrekken. Voor een minderjarige zwemmer oefent de ouder of voogd die rechten uit.</p>
<ul>
  <li><b>Zelf in de app</b>: je gegevens en die van je zwemmers aanpassen, meldingen uitzetten, en onder <a href="<?= esc_url(app_url('privacy/mijn-gegevens/')) ?>">Mijn gegevens</a> al je gegevens downloaden of je account verwijderen.</li>
  <li><b>Via de club</b>: mail naar <?= $_mail ?: 'het secretariaat' ?>. Je krijgt binnen een maand antwoord.</li>
  <li><b>Klacht</b>: ben je niet tevreden, dan kan je terecht bij de Gegevensbeschermingsautoriteit, Drukpersstraat 35, 1000 Brussel, <a href="https://www.gegevensbeschermingsautoriteit.be" target="_blank" rel="noopener">www.gegevensbeschermingsautoriteit.be</a>.</li>
</ul>

<h2>7. Cookies en opslag op je toestel</h2>
<p>De app gebruikt <b>enkel wat strikt nodig is</b> om te werken. Er zijn geen cookies voor statistieken, reclame of sociale media, en geen trackers van derden. Daarom vragen we geen toestemming voor cookies.</p>
<div class="tabelwrap"><table>
  <tr><th>Naam</th><th>Waarvoor</th><th>Hoe lang</th></tr>
  <?php if (is_demo()): ?><tr><td><code><?= e(DEMO_COOKIE) ?></code></td><td>Je blijft aangemeld in de demo</td><td>14 dagen, of tot je afmeldt</td></tr>
  <?php else: ?><tr><td><code>wordpress_logged_in_…</code>, <code>wordpress_sec_…</code></td><td>Je blijft aangemeld (cookies van WordPress)</td><td>14 dagen, of tot je afmeldt</td></tr>
  <tr><td><code>wordpress_test_cookie</code></td><td>Controle of je browser cookies toelaat, bij het aanmelden</td><td>Tot je de browser sluit</td></tr><?php endif; ?>
  <tr><td><code>khzs_f</code></td><td>Een korte melding op de volgende pagina, voor wie niet aangemeld is (bv. na afmelden)</td><td>Tot je de browser sluit</td></tr>
  <tr><td><code>thema</code> (lokale opslag)</td><td>Je keuze voor een licht of donker thema</td><td>Tot je het thema terugzet op automatisch</td></tr>
  <tr><td><code>khzs_pwa_weg</code> (lokale opslag)</td><td>Je tikte ‘Niet nu’ bij de melding om de app te installeren</td><td>30 dagen</td></tr>
  <tr><td>App-cache (service worker)</td><td>Opmaak en logo, zodat de app snel opent en een melding toont zonder internet</td><td>Tot een nieuwe versie van de app</td></tr>
</table></div>
<p class="klein">Deze lijst gaat over de app. De rest van de website kan andere cookies gebruiken (bv. van het thema of andere plugins); die staan in de cookieverklaring van de website.</p>

<h2>8. Beveiliging</h2>
<p>De app werkt via een beveiligde verbinding (https). Wachtwoorden worden versleuteld bewaard. Iedereen ziet enkel wat nodig is voor de eigen rol. Er wordt elke nacht een backup gemaakt.</p>

<h2>9. Wijzigingen</h2>
<p>Verandert deze verklaring inhoudelijk, dan krijgt de versie een nieuwe datum en vragen we je in de app om ze opnieuw te lezen. Huidige versie: <b><?= e($gegevens['versie']) ?></b>.</p>
<?php if ($gegevens['extra']): ?><h2>Aanvullend</h2><p><?= nl2br(e($gegevens['extra'])) ?></p><?php endif; ?>
</div>
<p class="leeg"><?php if (is_user_logged_in()): ?><a href="<?= esc_url(app_url('privacy/mijn-gegevens/')) ?>">Mijn gegevens</a><?php else: ?><a href="<?= esc_url(app_url('aanmelden/')) ?>">Aanmelden</a><?php if (registratie_open()): ?> · <a href="<?= esc_url(app_url('registreren/')) ?>">Profiel aanmaken</a><?php endif; endif; ?></p>
