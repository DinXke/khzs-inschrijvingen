<?php namespace KHZS; defined('ABSPATH') || exit; ?>
<h1>Websitekalender</h1>
<?php if (!$tec): ?>
<div class="melding info">The Events Calendar is niet actief op deze site. Zodra die plugin aanstaat, kan de app de wedstrijden automatisch op de evenementenkalender van de website zetten.</div>
<?php else: ?>
<p class="klein">Wedstrijden komen automatisch op de evenementenkalender van de website en blijven daar bijgewerkt: bij een nieuwe of gewijzigde wedstrijd, een nieuwe Lenex-versie, een andere status of een publieke bijlage.
  Enkel publieke informatie gaat mee: naam, sessies met datum en uren, zwembad, badlengte, organisator en een link naar de app. Nooit inschrijvingen, zwemmers, interne deadlines of kosten.</p>
<?php if ($f = get_option('khzs_kalender_fout')): ?><div class="melding error">Laatste fout: <?= e($f) ?></div><?php endif; ?>
<form method="post" class="kaart" action="<?= esc_url(app_url('kalender/')) ?>"><?= nonce_veld() ?>
  <input type="hidden" name="actie" value="instellingen">
  <?= veld_vinkje('aan', 'Wedstrijden synchroniseren met de websitekalender', (bool) get_option('khzs_kalender_aan')) ?>
  <div class="veld"><label>Welke wedstrijden komen op de website?</label>
    <ul><?php foreach (W_STATUS as $s => $l): ?>
      <li><label><input type="checkbox" name="statussen[]" value="<?= esc_attr($s) ?>"<?= in_array($s, kalender_statussen(), true) ? ' checked' : '' ?>> <?= e($l) ?></label></li>
    <?php endforeach; ?></ul>
    <div class="help">Gaat een wedstrijd naar een status die niet aangevinkt is, of wordt ze verwijderd, dan wordt een gepubliceerd evenement ‘Geannuleerd’. Een evenement dat nooit gepubliceerd was, gaat naar de prullenbak. Voorbije evenementen blijven staan.</div>
  </div>
  <?= veld_tekst('categorie', 'Evenementcategorie', get_option('khzs_kalender_categorie', 'Wedstrijden'), null, ['maxlength' => 100], 'Bestaat die categorie nog niet, dan wordt ze aangemaakt. Andere categorieën op de website blijven staan.') ?>
  <?= veld_select('publiceren', 'Nieuwe evenementen', ['publish' => 'Meteen publiceren', 'draft' => 'Als concept (de webmaster publiceert)'], get_option('khzs_kalender_publiceren', 'publish')) ?>
  <?= veld_vinkje('info', '‘Info voor zwemmers’ ook op de website tonen', (bool) get_option('khzs_kalender_info'), 'Enkel aanzetten als die info nooit iets intern bevat.') ?>
  <p class="help">Bijlagen komen enkel op de website als je ze bij de wedstrijd als <b>publiek</b> aanduidt (🌐).</p>
  <p><button>Bewaren</button></p>
</form>
<?php if (get_option('khzs_kalender_aan')): ?>
<form method="post" class="kaart" action="<?= esc_url(app_url('kalender/')) ?>"><?= nonce_veld() ?>
  <input type="hidden" name="actie" value="alles">
  <p class="klein"><?= (int) $aantal ?> evenement(en) gekoppeld · laatst alles nagekeken: <?= e(get_option('khzs_kalender_laatst') ?: 'nooit') ?></p>
  <button>Nu alles synchroniseren</button>
</form>
<?php endif; ?>
<?php if ($speciaal): ?>
<h2>Met de hand gewijzigd op de website</h2>
<p class="klein">Iemand heeft deze evenementen op de website aangepast. De app overschrijft dat niet vanzelf. Kies per evenement.</p>
<?php foreach ($speciaal as $s): ?>
<div class="kaart">
  <div class="rij tussen"><strong><?= e($s->titel) ?></strong><?php if ($s->link): ?><a class="klein" href="<?= esc_url($s->link) ?>" target="_blank">Bekijken ↗</a><?php endif; ?></div>
  <div class="klein"><?= $s->vast ? 'Wordt niet meer bijgewerkt vanuit de app.' : 'Gewijzigd vastgesteld op ' . e($s->sinds) . '.' ?></div>
  <form method="post" class="rij" action="<?= esc_url(app_url('kalender/')) ?>"><?= nonce_veld() ?>
    <input type="hidden" name="evenement" value="<?= (int) $s->id ?>">
    <button name="actie" value="overschrijven" class="secundair" onclick="return confirm('De wijzigingen op de website gaan verloren. Doorgaan?')">Overschrijven met de app</button>
    <?php if (!$s->vast): ?><button name="actie" value="vast" class="secundair">Niet meer bijwerken</button><?php endif; ?>
  </form>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>
