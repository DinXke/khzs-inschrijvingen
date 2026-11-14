<?php namespace KHZS; defined('ABSPATH') || exit;
$basis = "wedstrijd/{$w->id}/nakijken/";
$fin = "wedstrijd/{$w->id}/finaliseren/";
?>
<div class="rij tussen"><h1><?= e($w->naam) ?></h1>
  <div class="rij"><?php if ($is_secretaris): ?><a class="knop secundair" href="<?= esc_url(app_url("wedstrijd/{$w->id}/instellingen/")) ?>">Instellingen & export</a><?php endif; ?>
  <a class="knop secundair" href="<?= esc_url(app_url("wedstrijd/{$w->id}/overzicht/")) ?>">📄 Overzicht / afdrukken</a>
  <a class="knop" href="<?= esc_url(app_url("wedstrijd/{$w->id}/")) ?>">+ Inschrijving toevoegen</a></div></div>
<div class="stappen">
  <div class="stap">Zwemmers tot<b><?= e(datum(deadline_zwemmer($w), 'D j M')) ?></b></div>
  <div class="stap">Trainers tot<b><?= e(datum(deadline_trainer($w), 'D j M')) ?></b></div>
  <div class="stap">Organisator<b><?= e(datum($w->deadline, 'D j M')) ?></b></div>
  <div class="stap">Status<b><?= e(W_STATUS[$w->status]) ?></b></div>
</div>
<?php include __DIR__ . '/_info_bijlagen.php'; ?>
<div class="kaart finaal">
  <div class="rij tussen">
    <div><strong>Finaliseren</strong>
      <div class="klein"><?= (int) $finaal['gefinaliseerd'] ?> van <?= (int) $finaal['zwemmers'] ?> zwemmers gefinaliseerd · <?= (int) $finaal['ingegeven'] ?> ingegeven door het secretariaat
      <?php if ($finaal['gewijzigd']): ?> · <span class="badge afgekeurd">⚠ <?= (int) $finaal['gewijzigd'] ?> gewijzigd na ingave</span><?php endif; ?></div>
      <div class="voortgang"><span style="width:<?= (int) round($finaal['gefinaliseerd'] / ($finaal['zwemmers'] ?: 1) * 100) ?>%"></span></div>
    </div>
    <form method="post" action="<?= esc_url(app_url($fin)) ?>" class="rij"><?= nonce_veld() ?>
      <input type="hidden" name="zwemmer" value="alle"><input type="hidden" name="terug" value="<?= esc_attr(huidige_url()) ?>">
      <button name="finaliseer" value="finaliseer" class="mini" onclick="return confirm('Alle zwemmers met inschrijvingen finaliseren? Openstaande inschrijvingen worden goedgekeurd.')">🔒 Alles finaliseren</button>
    </form>
  </div>
  <?php if ($is_secretaris): ?><p class="klein" style="margin:.5rem 0 0">Gefinaliseerde zwemmers kan je al ingeven. Export van enkel de gefinaliseerde:
    <a href="<?= esc_url(app_url("wedstrijd/{$w->id}/export.lxf", ['enkel' => 'gefinaliseerd'])) ?>">Lenex</a> · <a href="<?= esc_url(app_url("wedstrijd/{$w->id}/export.xlsx", ['enkel' => 'gefinaliseerd'])) ?>">Excel</a></p><?php endif; ?>
</div>
<div class="kaart">
  <strong>Deelname</strong>
  <div class="grid drie" style="margin-top:.5rem">
    <?php foreach ($overzicht as [$k, $label, $lijst]): ?><div><span class="badge k-<?= esc_attr($k) ?>"><?= e($label) ?> · <?= count($lijst) ?></span>
      <div class="klein" style="margin-top:.35rem"><?php if ($lijst): echo implode(', ', array_map(fn($d) => '<a href="' . esc_url(app_url("wedstrijd/{$w->id}/", ['zwemmer' => $d->zwemmer_id])) . '">' . e("{$d->voornaam} {$d->naam}") . '</a>', $lijst)); else: ?>—<?php endif; ?></div></div><?php endforeach; ?>
  </div>
</div>
<details class="kaart"<?= $instfout ? ' open' : '' ?>>
  <summary><strong>Deadline zwemmers & info voor zwemmers</strong></summary>
  <form method="post" class="rij" style="align-items:end" action="<?= esc_url(app_url($basis)) ?>"><?= nonce_veld() ?>
    <div style="flex:1;min-width:240px">
      <?= veld_tekst('dagen_trainer', 'Dagen trainer', $w->dagen_trainer, $instfout['dagen_trainer'] ?? null, ['type' => 'number', 'min' => 0, 'required' => true],
        'Extra dagen vóór de deadline van het sportsecretariaat dat zwemmers moeten ingeschreven zijn.') ?>
      <?= veld_textarea('info', 'Info voor zwemmers', $w->info, null, 3, 'Bv. ‘Deze wedstrijd is enkel voor zwemmers van de A-groep.’ Zichtbaar voor iedereen.') ?>
    </div>
    <button>Bewaren</button>
  </form>
</details>

<div class="rij" style="margin:1rem 0">
  <a class="badge <?= $status === '' ? 'ok' : '' ?>" href="<?= esc_url(app_url($basis)) ?>">Alle</a>
  <a class="badge ingediend" href="<?= esc_url(app_url($basis, ['status' => INGEDIEND])) ?>">Na te kijken (<?= (int) ($tellers[INGEDIEND] ?? 0) ?>)</a>
  <a class="badge goedgekeurd" href="<?= esc_url(app_url($basis, ['status' => GOEDGEKEURD])) ?>">Goedgekeurd (<?= (int) ($tellers[GOEDGEKEURD] ?? 0) ?>)</a>
  <a class="badge afgekeurd" href="<?= esc_url(app_url($basis, ['status' => AFGEKEURD])) ?>">Afgekeurd (<?= (int) ($tellers[AFGEKEURD] ?? 0) ?>)</a>
</div>

<form method="post" action="<?= esc_url(app_url("wedstrijd/{$w->id}/beoordelen/")) ?>" id="beoordeel"><?= nonce_veld() ?>
<input type="hidden" name="terug" value="<?= esc_attr(huidige_url()) ?>">
<?php foreach ($per_zwemmer as $zid => [$z, $lijst]): $d = $deelname_van[(int) $zid] ?? null; ?>
<div class="kaart<?= $d && $d->gefinaliseerd ? ' gefinaliseerd' : '' ?>">
  <div class="rij tussen"><label style="margin:0"><input type="checkbox" onclick="this.closest('.kaart').querySelectorAll('input[name=&quot;inschrijving[]&quot;]').forEach(c=>c.checked=this.checked)"> <?= e("{$z->naam} {$z->voornaam}") ?> <span class="klein">(<?= e(substr($z->geboortedatum, 0, 4)) ?>)</span></label>
    <span class="rij" style="gap:.4rem">
      <?php if ($d && $d->gefinaliseerd):
        $door = $d->gefinaliseerd_door ? get_userdata((int) $d->gefinaliseerd_door) : null; ?>
        <span class="badge goedgekeurd" title="door <?= esc_attr($door ? ($door->first_name ?: $door->user_login) : '') ?> <?= esc_attr(datum($d->gefinaliseerd_op, 'j M H:i')) ?>">🔒 Gefinaliseerd</span><?php endif; ?>
      <?php if ($d && $d->ingegeven): ?><span class="badge doorgestuurd">✓ Ingegeven</span><?php endif; ?>
      <?php if ($d && $d->gewijzigd_na_ingave): ?><span class="badge afgekeurd">⚠ Gewijzigd na ingave</span><?php endif; ?>
      <?php if ($d && $d->gefinaliseerd): ?>
        <button class="mini secundair" formaction="<?= esc_url(app_url($fin, ['actie' => 'heropen'])) ?>" name="zwemmer" value="<?= (int) $z->id ?>">Heropenen</button>
        <?php if ($is_secretaris): if ($d->ingegeven): ?><button class="mini secundair" formaction="<?= esc_url(app_url($fin, ['actie' => 'niet_ingegeven'])) ?>" name="zwemmer" value="<?= (int) $z->id ?>">Niet ingegeven</button>
        <?php else: ?><button class="mini ok" formaction="<?= esc_url(app_url($fin, ['actie' => 'ingegeven'])) ?>" name="zwemmer" value="<?= (int) $z->id ?>">✓ Ingegeven</button><?php endif; endif; ?>
      <?php else: ?>
        <button class="mini" formaction="<?= esc_url(app_url($fin, ['actie' => 'finaliseer'])) ?>" name="zwemmer" value="<?= (int) $z->id ?>">🔒 Finaliseren</button>
      <?php endif; ?>
      <a class="klein" href="<?= esc_url(app_url("wedstrijd/{$w->id}/", ['zwemmer' => $z->id])) ?>">wijzigen</a></span></div>
  <div class="tabelwrap"><table>
    <?php foreach ($lijst as $i): ?><tr>
      <td style="width:32px"><input type="checkbox" name="inschrijving[]" value="<?= (int) $i->id ?>"></td>
      <td>#<?= (int) $i->nummer->nummer ?> <?= e(omschrijving($i->nummer)) ?><div class="klein">sessie <?= (int) $i->nummer->sessie->nummer ?></div></td>
      <td><?= e(toon_tijd($i->inschrijftijd)) ?> <span class="klein"><?= e($i->inschrijfbaan) ?></span></td>
      <td><span class="badge <?= esc_attr($i->status) ?>"><?= e(I_STATUS[$i->status]) ?></span><?php if ($i->opmerking): ?><div class="klein"><?= e($i->opmerking) ?></div><?php endif; ?></td>
    </tr><?php endforeach; ?>
  </table></div>
</div>
<?php endforeach; ?>
<?php if (!$per_zwemmer): ?><div class="leeg">Geen inschrijvingen<?= $status ? ' met deze status' : '' ?>.</div><?php endif; ?>
<?php if ($per_zwemmer): ?>
<div class="vaste-actie" style="flex-wrap:wrap">
  <input type="text" name="opmerking" placeholder="Opmerking (optioneel)" style="flex:1;min-width:160px">
  <button name="actie" value="goedgekeurd" class="ok">Goedkeuren</button>
  <button name="actie" value="afgekeurd" class="gevaar">Afkeuren</button>
</div>
<?php endif; ?>
</form>
