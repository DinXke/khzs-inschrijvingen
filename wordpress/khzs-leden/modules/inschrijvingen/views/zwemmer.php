<?php namespace KHZS; defined('ABSPATH') || exit;
$url = app_url("zwemmers/{$z->id}/"); ?>
<h1><?= e("{$z->voornaam} {$z->naam}") ?></h1>
<?php if ($toestemming_nodig): ?><form method="post" class="kaart" action="<?= esc_url($url) ?>"><?= nonce_veld() ?><input type="hidden" name="actie" value="toestemming">
  <p style="margin-top:0"><b>Toestemming van een ouder of voogd</b>: <?= e($z->voornaam) ?> is jonger dan 18 jaar. Bevestig dat je ouder of voogd bent (of hun toestemming hebt) en akkoord gaat dat de club deze gegevens verwerkt zoals beschreven in de <a href="<?= esc_url(app_url('privacy/')) ?>">privacyverklaring</a>.</p>
  <button class="mini">Ik geef toestemming</button></form><?php endif; ?>
<div class="grid twee">
  <div>
    <?php if ($meldingen): ?><h2>Wijzigingen door de club</h2>
    <div class="kaart melding-lijst"><?php foreach ($meldingen as $m): ?>
      <div class="melding-item<?= $m->gelezen ? '' : ' nieuw' ?>"><div class="klein"><?= e($m->door_naam) ?> · <?= e($m->wedstrijd_naam) ?> · <?= e(datum($m->aangemaakt, 'j M H:i')) ?></div>
      <ul><?php foreach ($m->regels as $r): ?><li><?= e($r) ?></li><?php endforeach; ?></ul></div><?php endforeach; ?>
    </div><?php endif; ?>
    <h2>Ploegen</h2>
    <div class="kaart">
      <?php foreach ($lidmaatschappen as $l): ?><div class="rij tussen" style="padding:.3rem 0;border-bottom:1px solid var(--lijn)">
        <span><?php if ($l->status === PL_GOEDGEKEURD): ?><a href="<?= esc_url(app_url("ploegen/{$l->ploeg_id}/")) ?>"><?= e($l->ploegnaam) ?></a><?php else: ?><?= e($l->ploegnaam) ?><?php endif; ?>
          <?php if ($l->status === PL_AFGEKEURD && $l->reden !== ''): ?><br><span class="klein"><?= e($l->reden) ?></span><?php endif; ?></span>
        <span class="rij"><span class="badge <?= $l->status === PL_GOEDGEKEURD ? 'ok' : ($l->status === PL_OPEN ? 'wacht' : 'fout') ?>"><?= e(PL_STATUS[$l->status]) ?></span>
          <?php if ($l->status !== PL_AFGEKEURD): ?><form method="post" action="<?= esc_url(app_url("ploegen/lid/{$l->id}/")) ?>" onsubmit="return confirm(<?= esc_attr(wp_json_encode($l->status === PL_OPEN ? 'Aanvraag intrekken?' : "{$z->voornaam} uit {$l->ploegnaam} halen?")) ?>)"><?= nonce_veld() ?>
            <input type="hidden" name="terug" value="<?= esc_attr(app_url("zwemmers/{$z->id}/")) ?>"><button name="actie" value="verwijderen" class="mini secundair" title="<?= $l->status === PL_OPEN ? 'Intrekken' : 'Uit de ploeg' ?>">✕</button></form><?php endif; ?></span>
      </div><?php endforeach; ?>
      <?php if (!$lidmaatschappen): ?><p class="klein"><?= e($z->voornaam) ?> zit nog in geen enkele ploeg.</p><?php endif; ?>
      <?php if ($kies_ploegen): ?><form method="post" action="<?= esc_url(app_url("zwemmers/{$z->id}/ploeg/")) ?>" class="rij" style="margin-top:.6rem;align-items:end"><?= nonce_veld() ?>
        <div style="flex:1;min-width:180px"><label for="ploegkeuze">Ploeg kiezen</label>
          <select id="ploegkeuze" name="ploeg" required><option value="">Kies…</option><?php foreach ($kies_ploegen as $p): ?><option value="<?= (int) $p->id ?>"><?= e($p->naam) ?></option><?php endforeach; ?></select></div>
        <button class="mini"><?= $is_staf ? 'Toevoegen' : 'Aanvragen' ?></button></form>
        <p class="help">De trainer of het bestuur keurt de ploeg goed. Je krijgt een melding.</p><?php endif; ?>
    </div>
    <h2>Inschrijvingen</h2>
    <div class="kaart">
      <?php foreach ($inschrijvingen as $i): ?><div class="rij tussen" style="padding:.35rem 0;border-bottom:1px solid var(--lijn)">
        <span><a href="<?= esc_url(app_url("wedstrijd/{$i->wid}/", ['zwemmer' => $z->id])) ?>"><?= e($i->wnaam) ?></a><br><span class="klein">#<?= (int) $i->n_nummer ?> <?= e(omschrijving((object) ['naam' => $i->n_naam, 'afstand' => $i->afstand, 'slag' => $i->slag, 'aflossing' => $i->aflossing])) ?> · <?= e(toon_tijd($i->inschrijftijd)) ?></span></span>
        <span class="badge <?= esc_attr($i->status) ?>"><?= e(I_STATUS[$i->status]) ?></span></div>
      <?php endforeach; ?>
      <?php if (!$inschrijvingen): ?><p class="klein">Nog geen inschrijvingen.</p><?php endif; ?>
    </div>
    <h2>Ouders &amp; accounts</h2>
    <div class="kaart">
      <?php $ik = get_current_user_id(); foreach ($accounts as $u): ?><div class="rij tussen" style="padding:.3rem 0;border-bottom:1px solid var(--lijn)">
        <span><?= e(volle_naam($u) ?: $u->user_login) ?><?= $u->ID === $ik ? ' <span class="klein">(jij)</span>' : '' ?><br><span class="klein"><?= e($u->user_email ?: $u->user_login) ?></span></span>
        <?php if (count($accounts) > 1 && ($u->ID === $ik || (int) $z->account_id === $ik || $is_staf)): ?>
          <form method="post" action="<?= esc_url(app_url("zwemmers/{$z->id}/ontkoppelen/")) ?>" onsubmit="return confirm(<?= esc_attr(wp_json_encode($u->ID === $ik ? "Jezelf loskoppelen van {$z->voornaam}?" : (volle_naam($u) ?: $u->user_login) . ' loskoppelen?')) ?>)"><?= nonce_veld() ?>
          <input type="hidden" name="user" value="<?= (int) $u->ID ?>"><button class="mini secundair"><?= $u->ID === $ik ? 'Mij loskoppelen' : 'Loskoppelen' ?></button></form><?php endif; ?>
      </div><?php endforeach; ?>
      <p class="klein" style="margin-top:.6rem"><b>Delen met een andere ouder</b> (bv. bij gescheiden ouders): maak een koppelcode en geef ze door. De andere ouder vult ze in bij <i>Mijn zwemmers → Zwemmer koppelen</i> of bij het aanmaken van een profiel. Jullie zien dan allebei dezelfde inschrijvingen en meldingen.</p>
      <?php if ($koppelcode): ?><div class="melding success" style="margin:.4rem 0">Koppelcode: <b style="font-size:1.3rem;letter-spacing:.08em"><?= e($koppelcode) ?></b><br><span class="klein">Geldig tot <?= e(datum($z->koppelcode_tot, 'j M H:i')) ?></span></div><?php endif; ?>
      <form method="post" action="<?= esc_url(app_url("zwemmers/{$z->id}/delen/")) ?>"><?= nonce_veld() ?><button class="mini <?= $koppelcode ? 'secundair' : '' ?>"><?= $koppelcode ? 'Nieuwe code maken' : '🔗 Koppelcode maken' ?></button></form>
      <?php if ($is_staf): ?><form method="post" action="<?= esc_url(app_url('zwemmers/account/')) ?>" class="rij" style="margin-top:.6rem;align-items:end"><?= nonce_veld() ?>
        <input type="hidden" name="zwemmer" value="<?= (int) $z->id ?>">
        <div style="flex:1;min-width:180px"><label for="acc-email">Account koppelen (staf)</label><input id="acc-email" type="email" name="email" placeholder="e-mail van het account" required></div>
        <button class="mini secundair">Koppelen</button></form><?php endif; ?>
    </div>
  </div>
  <div>
    <h2>Gegevens</h2>
    <form method="post" class="kaart" action="<?= esc_url($url) ?>"><?= nonce_veld() ?><?= zwemmer_velden('z-', $v, $f) ?><p><button>Bewaren</button></p></form>
    <details class="kaart beste-tijden"<?= $tf ? ' open' : '' ?>>
      <summary><strong>Beste tijden</strong> <span class="klein">(<?= count($tijden) ?> · bijgehouden door de club)</span></summary>
      <?php if ($tijden): ?><div class="tabelwrap"><table>
        <tr><th>Nummer</th><th>Tijd</th><th>Datum</th><th></th></tr>
        <?php foreach ($tijden as $t): ?><tr><td><?= (int) $t->afstand ?> m <?= e(mb_strtolower(SLAG[$t->slag] ?? $t->slag)) ?> <span class="klein"><?= e(BAAN[$t->baan]) ?></span></td>
          <td><b><?= e(toon_tijd($t->tijd)) ?></b></td><td class="klein"><?= e(datum($t->datum, 'j/m/y')) ?></td>
          <td><?php if ($is_staf): ?><form method="post" action="<?= esc_url(app_url("tijd/{$t->id}/verwijderen/")) ?>"><?= nonce_veld() ?><button class="mini secundair" title="Verwijderen">✕</button></form><?php endif; ?></td></tr><?php endforeach; ?>
      </table></div><?php else: ?><p class="klein">Nog geen tijden. Je beste tijden worden door de club bijgehouden op basis van de wedstrijduitslagen.</p><?php endif; ?>
      <?php if (swimrankings_url($z)): ?><p><a class="knop secundair mini" href="<?= esc_url(swimrankings_url($z)) ?>" target="_blank" rel="noopener">Bekijk op Swimrankings ↗</a></p>
      <?php else: ?><p class="help">Vul je Swimrankings-ID in bij je gegevens voor een directe link naar je tijden.</p><?php endif; ?>
    </details>
    <?php if ($is_staf): ?><details class="kaart"<?= $tf ? ' open' : '' ?>>
      <summary><strong>+ Tijd toevoegen (staf)</strong></summary>
      <form method="post" action="<?= esc_url($url) ?>"><?= nonce_veld() ?>
        <?= veld_select('t-slag', 'Slag', SLAG, $tijd['slag'], $tf['slag'] ?? null) ?>
        <?= veld_select('t-afstand', 'Afstand', array_combine(AFSTANDEN, array_map(fn($d) => "$d m", AFSTANDEN)), $tijd['afstand'], $tf['afstand'] ?? null) ?>
        <?= veld_select('t-baan', 'Baan', BAAN, $tijd['baan'], $tf['baan'] ?? null) ?>
        <?= veld_tekst('t-tijd', 'Tijd', $tijd['tijd'], $tf['tijd'] ?? null, ['inputmode' => 'decimal', 'placeholder' => 'm:ss.hh', 'required' => true], 'bv. 1:02.86 of 34.12') ?>
        <?= veld_tekst('t-datum', 'Datum', $tijd['datum'], $tf['datum'] ?? null, ['type' => 'date', 'required' => true]) ?>
        <?= veld_tekst('t-wedstrijd', 'Wedstrijd', $tijd['wedstrijd'], null, ['maxlength' => 120]) ?>
        <p><button class="vol">Toevoegen</button></p></form>
    </details><?php endif; ?>
  </div>
</div>
