<?php namespace KHZS; defined('ABSPATH') || exit;
$d = $deelname ?? null;
$vast = $d && !$staf && ($d->geweigerd || $d->gefinaliseerd);
?>
<h1><?= e($w->naam) ?></h1>
<div class="klein"><?= e($w->zwembad) ?>, <?= e($w->stad) ?> · <?= e(BAAN[$w->baan]) ?></div>
<div class="stappen">
  <div class="stap"><?= $w->status === W_CONCEPT ? 'Nog niet open · tot' : 'Inschrijven tot' ?><b><?= e(datum(deadline_zwemmer($w), 'D j M')) ?></b></div>
  <?php if ($staf): ?><div class="stap">Trainers tot<b><?= e(datum(deadline_trainer($w), 'D j M')) ?></b></div>
  <div class="stap">Organisator<b><?= e(datum($w->deadline, 'D j M')) ?></b></div><?php endif; ?>
</div>
<?php do_action('khzs_wedstrijd_knoppen', $w); // bv. de wedstrijdchat (module communicatie) ?>
<?php include __DIR__ . '/_info_bijlagen.php'; ?>
<?php if ($staf): ?><p><a href="<?= esc_url(app_url("wedstrijd/{$w->id}/nakijken/")) ?>">← Terug naar overzicht inschrijvingen</a></p><?php endif; ?>

<?php if ($staf): ?>
<form method="get" class="kaart" action="<?= esc_url(app_url("wedstrijd/{$w->id}/")) ?>">
  <?= pad_veld("wedstrijd/{$w->id}/") ?>
  <label for="zw">Zwemmer</label>
  <select id="zw" name="zwemmer" onchange="this.form.submit()">
    <option value="">— kies —</option>
    <?php foreach ($zwemmers as $z): ?><option value="<?= (int) $z->id ?>" <?= $gekozen && $z->id == $gekozen->id ? 'selected' : '' ?>><?= e("{$z->naam} {$z->voornaam}") ?> (<?= e(substr($z->geboortedatum, 0, 4)) ?>)</option><?php endforeach; ?>
  </select>
</form>
<?php elseif (count($zwemmers) > 1): ?>
<div class="kind-keuze">
  <?php foreach ($zwemmers as $z): ?><a class="kind<?= $gekozen && $z->id == $gekozen->id ? ' actief' : '' ?>" href="<?= esc_url(wedstrijd_url($w, $z)) ?>"><?= e($z->voornaam) ?><span><?= e(substr($z->geboortedatum, 0, 4)) ?></span></a><?php endforeach; ?>
</div>
<?php if (!$gekozen): ?><p class="klein">Kies hierboven voor welke zwemmer je wil inschrijven.</p><?php endif; ?>
<?php endif; ?>

<?php if ($gekozen): ?>
  <form method="post" action="<?= esc_url(app_url("wedstrijd/{$w->id}/deelname/")) ?>" class="kaart deelname"><?= nonce_veld() ?>
    <input type="hidden" name="zwemmer" value="<?= (int) $gekozen->id ?>">
    <strong><?= $staf ? 'Keuze van ' . e($gekozen->voornaam) : 'Doe je mee?' ?></strong>
    <div class="segment">
      <?php foreach (KEUZES as $k => $label): ?><button name="keuze" value="<?= esc_attr($k) ?>" class="<?= $d && $d->keuze === $k ? 'gekozen' : '' ?> k-<?= esc_attr($k) ?>" <?= $vast ? 'disabled' : '' ?>><?= e($label) ?></button><?php endforeach; ?>
    </div>
    <?php if ($d && $d->gefinaliseerd && !$d->geweigerd): ?><div class="melding success" style="margin:.6rem 0 0">🔒 Je inschrijving is gefinaliseerd door je trainer<?= $staf ? '' : '. Wijzigen kan enkel nog via je trainer' ?>.</div><?php endif; ?>
    <?php if ($d && $d->geweigerd): ?><div class="melding error" style="margin:.6rem 0 0">⛔ Deelname geweigerd door de club<?= $d->weigerreden ? ': ' . e($d->weigerreden) : '' ?></div><?php endif; ?>
    <?php if (!$vast): ?><p class="help" style="margin:.4rem 0 0">Je keuze wordt meteen bewaard. ‘Interesse’ betekent: misschien, hou me op de hoogte.</p><?php endif; ?>
    <?php if ($w->status === W_CONCEPT): ?><p class="help">Inschrijven op nummers kan zodra het sportsecretariaat de inschrijvingen opent. Met ‘Interesse’ of ‘Ik doe mee’ krijg je dan een melding.</p><?php endif; ?>
  </form>
  <?php if ($staf): ?>
  <form method="post" action="<?= esc_url(app_url("wedstrijd/{$w->id}/deelname/beoordelen/")) ?>" class="kaart rij" style="align-items:end"><?= nonce_veld() ?>
    <input type="hidden" name="zwemmer" value="<?= (int) $gekozen->id ?>">
    <?php if ($d && $d->geweigerd): ?>
      <span style="flex:1">Deelname van <?= e($gekozen->voornaam) ?> is <b>geweigerd</b>.</span>
      <button name="actie" value="toestaan" class="ok">Opnieuw toestaan</button>
    <?php else: ?>
      <div style="flex:1;min-width:200px"><label for="reden">Deelname weigeren</label>
        <input id="reden" type="text" name="reden" placeholder="Reden (zichtbaar voor de zwemmer)"></div>
      <button name="actie" value="weigeren" class="gevaar" onclick="return confirm(<?= esc_attr(wp_json_encode("Deelname van {$gekozen->voornaam} weigeren? Openstaande inschrijvingen worden afgekeurd.")) ?>)">Weigeren</button>
    <?php endif; ?>
  </form>
  <?php endif; ?>
  <?php if ($meldingen): ?>
  <div class="kaart melding-lijst">
    <strong><?= $ongelezen && !$staf ? '🔔 Nieuw: ' : '' ?>Wijzigingen door de club</strong>
    <?php foreach ($meldingen as $m): ?>
    <div class="melding-item<?= $m->gelezen ? '' : ' nieuw' ?>">
      <div class="klein"><?= e($m->door_naam) ?> heeft <?= $staf ? 'de inschrijving' : 'je inschrijving' ?> gewijzigd · <?= e(datum($m->aangemaakt, 'j M H:i')) ?></div>
      <ul><?php foreach ($m->regels as $r): ?><li><?= e($r) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if (!$mag_wijzigen): ?><div class="melding info"><?= $w->status === W_CONCEPT ? 'De inschrijvingen zijn nog niet open. Je ziet hieronder al het programma.' : 'De inschrijvingen zijn afgesloten.' ?></div><?php endif; ?>
  <?php if ($staf): ?><p class="klein">Als trainer kan je nummers aan- of uitvinken en de inschrijftijd aanpassen. De zwemmer krijgt hiervan een melding.</p><?php endif; ?>
  <p class="klein"><?= e("{$gekozen->voornaam} {$gekozen->naam}") ?> – categorie <b><?= (int) $jaren ?> jaar</b>. Je ziet enkel de nummers waarvoor je in aanmerking komt.</p>
  <?php if (!$heeft_tijden): ?><div class="melding info">Nog geen beste tijden gekend voor deze zwemmer. De club werkt ze bij op basis van de wedstrijduitslagen.</div><?php endif; ?>
  <form method="post" action="<?= esc_url(app_url("wedstrijd/{$w->id}/inschrijven/")) ?>" autocomplete="off"><?= nonce_veld() ?>
    <input type="hidden" name="zwemmer" value="<?= (int) $gekozen->id ?>">
    <?php foreach ($programma as [$sessie, $rijen]): ?>
    <h2>Sessie <?= (int) $sessie->nummer ?> · <?= e(datum($sessie->datum, 'l j F')) ?><?= $sessie->starttijd ? ' · ' . e(substr($sessie->starttijd, 0, 5)) : '' ?></h2>
    <div class="kaart" style="padding:4px 12px">
      <?php foreach ($rijen as $b): $n = $b->nummer; $i = $bestaande[(int) $n->id] ?? null;
        $uit = !$b->mag_inschrijven && !$staf && !$i;
        $uitgeschakeld = !$mag_wijzigen || ($d && $d->gefinaliseerd && !$staf) || $uit; ?>
      <label class="nummer <?= $uit ? 'uit' : '' ?>">
        <input type="checkbox" name="nummer[]" value="<?= (int) $n->id ?>" <?= $i ? 'checked' : '' ?> <?= $uitgeschakeld ? 'disabled' : '' ?>>
        <span class="info">
          <span class="titel">#<?= (int) $n->nummer ?> <?= e(omschrijving($n)) ?></span>
          <span class="klein"><?= $n->ronde === 'PRE' ? 'reeksen + finale' : 'tijdfinale' ?><?= $staf ? ' · ' . e(euro($n->kost_cent)) : '' ?>
            <?php if ($b->beste): ?> · beste <?= e(toon_tijd($b->beste->tijd)) ?> (<?= e(BAAN[$b->beste->baan]) ?>)<?php endif; ?></span>
          <?php if ($b->limiet_nodig): ?><span class="klein" style="display:block">Limiet: <?= e(implode(' of ', array_map(fn($l) => ($l['minimum'] ? 'trager dan ' : '') . toon_tijd($l['tijd']) . ' (' . BAAN[$l['baan']] . ')', $b->limieten))) ?></span><?php endif; ?>
        </span>
        <?php if ($i && $staf && $mag_wijzigen): ?><input type="text" name="tijd_<?= (int) $n->id ?>" value="<?= esc_attr(toon_tijd($i->inschrijftijd)) ?>" class="tijd-invoer" inputmode="decimal" title="Inschrijftijd" onclick="event.stopPropagation()"><?php endif; ?>
        <?php if ($i): ?><span class="badge <?= esc_attr($i->status) ?>"><?= e(I_STATUS[$i->status]) ?></span>
        <?php elseif ($b->limiet_nodig): ?><?= $b->limiet_gehaald ? '<span class="badge ok">limiet ✓</span>' : '<span class="badge fout">geen limiet</span>' ?><?php endif; ?>
      </label>
      <?php if ($i && $i->opmerking): ?><div class="klein" style="padding:0 0 .5rem 2.25rem">💬 <?= e($i->opmerking) ?></div><?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <?php if (!$programma): ?><div class="leeg">Geen nummers voor deze leeftijd/geslacht in deze wedstrijd.</div><?php endif; ?>
    <?php if ($mag_wijzigen && $programma && (!($d && $d->gefinaliseerd) || $staf)): ?>
    <div class="vaste-actie"><span class="klein" style="flex:1" aria-live="polite">Gekozen: <b data-aantal><?= count($bestaande) ?></b> nummer(s)<?= $staf ? ' · ' . e(euro($totaal)) : '' ?> <b data-niet-bewaard hidden>· nog niet bewaard</b></span><button>Bewaren</button></div>
    <?php endif; ?>
  </form>
<script>
// Teller in de Bewaar-balk telt mee bij het aanvinken, en toont of er nog iets te bewaren is.
document.querySelectorAll(".vaste-actie [data-aantal]").forEach(function (b) {
  const f = b.closest("form"), vinkjes = f.querySelectorAll('input[name="nummer[]"]'), rest = f.querySelector("[data-niet-bewaard]");
  const stand = () => Array.from(vinkjes, v => v.checked ? 1 : 0).join("");
  const begin = stand();
  f.addEventListener("change", function () {
    b.textContent = f.querySelectorAll('input[name="nummer[]"]:checked').length;
    rest.hidden = stand() === begin;
  });
});
</script>
<?php elseif (!$zwemmers): ?>
  <div class="leeg">Nog geen zwemmer gekoppeld. <a href="<?= esc_url(app_url('zwemmers/nieuw/')) ?>">Voeg een zwemmer toe</a>.</div>
<?php endif; ?>
