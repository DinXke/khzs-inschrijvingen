<?php namespace KHZS; defined('ABSPATH') || exit; ?>
<h1>Gebruikers & rollen</h1>
<?php if ($aanvragen): ?>
<div class="kaart melding-lijst" id="aanvragen">
  <h2 style="margin-top:0">Rolaanvragen (<?= count($aanvragen) ?>)</h2>
  <?php foreach ($aanvragen as $a): $_u = get_userdata((int) $a->user_id); if (!$_u) { continue; } ?>
  <div class="melding-item">
    <div class="rij tussen"><span><strong><?= e(volle_naam($_u) ?: $_u->user_login) ?></strong> vraagt de rol <strong><?= e(rol_naam($a->rol)) ?></strong></span>
      <span class="klein"><?= e(datum($a->aangemaakt, 'j/m/Y')) ?> · <?= e($_u->user_email ?: $_u->user_login) ?></span></div>
    <?php if ($a->motivatie !== ''): ?><p class="klein" style="margin:.25rem 0">“<?= e($a->motivatie) ?>”</p><?php endif; ?>
    <form method="post" action="<?= esc_url(app_url("gebruikers/aanvraag/{$a->id}/")) ?>" class="rij" style="margin-top:.4rem;align-items:start"><?= nonce_veld() ?>
      <button name="actie" value="goedkeuren" class="mini">Goedkeuren</button>
      <details style="flex:1;min-width:240px"><summary class="klein">Afkeuren of een andere rol geven</summary>
        <div class="rij" style="margin-top:.4rem;align-items:end">
          <div style="flex:1;min-width:180px"><label for="reden-<?= (int) $a->id ?>">Reden of toelichting</label>
            <input type="text" id="reden-<?= (int) $a->id ?>" name="reden" maxlength="300" placeholder="Verplicht bij afkeuren"></div>
          <button name="actie" value="afkeuren" class="mini secundair">Afkeuren</button>
          <select name="rol" aria-label="Andere rol" style="width:auto"><?php foreach ($keuzes as $k => $l): if ($k !== $a->rol): ?><option value="<?= esc_attr($k) ?>"><?= e($l) ?></option><?php endif; endforeach; ?></select>
          <button name="actie" value="andere" class="mini secundair">Deze rol geven</button>
        </div></details>
    </form>
  </div><?php endforeach; ?>
</div>
<?php endif; ?>
<form method="get" class="kaart" action="<?= esc_url(app_url('gebruikers/')) ?>"><?= pad_veld('gebruikers/') ?><input type="search" name="q" value="<?= esc_attr($q) ?>" placeholder="Zoek op naam of e-mail…"></form>
<div class="kaart tabelwrap"><table>
  <tr><th>Naam</th><th>E-mail</th><th>Rollen</th><?php if (module_actief('trainers')): ?><th>Penningmeester</th><?php endif; ?></tr>
  <?php foreach ($users as $u):
    $eigen = rollen_van($u); $wpbeheer = user_can($u, 'manage_options'); $vast = in_array(BEHEERDER, $eigen, true) && !$is_beheerder;
    $actie = esc_url(app_url('gebruikers/', $q !== '' ? ['q' => $q] : [])); ?>
  <tr><td><?= e(volle_naam($u) ?: '—') ?></td><td><?= e($u->user_email ?: $u->user_login) ?></td>
    <td><div class="rollen"><?php foreach ($eigen as $k):
      $l = ROLLEN[$k]; $weg = !$vast && count($eigen) > 1 && !($k === BEHEERDER && $wpbeheer); ?>
      <?php if (!$weg): ?><span class="badge rol"><?= e($k === BEHEERDER && $wpbeheer ? 'Beheerder (WordPress)' : $l) ?></span><?php else: ?>
      <form method="post" action="<?= $actie ?>"><?= nonce_veld() ?><input type="hidden" name="user" value="<?= (int) $u->ID ?>"><input type="hidden" name="rol" value="<?= esc_attr($k) ?>">
        <button name="actie" value="afnemen" class="badge rol" title="Rol <?= esc_attr(rol_naam($k)) ?> afnemen" onclick="return confirm(<?= esc_attr(wp_json_encode('Rol ' . rol_naam($k) . ' afnemen van ' . (volle_naam($u) ?: $u->user_login) . '?')) ?>)"><?= e($l) ?> ✕</button></form><?php endif; ?>
      <?php endforeach; ?>
      <?php $erbij = array_diff_key($keuzes, array_flip($eigen)); if (!$vast && $erbij): ?><form method="post" action="<?= $actie ?>"><?= nonce_veld() ?><input type="hidden" name="user" value="<?= (int) $u->ID ?>"><input type="hidden" name="actie" value="toevoegen">
        <select name="rol" onchange="if(this.value)this.form.submit()" aria-label="Rol toevoegen voor <?= esc_attr(volle_naam($u) ?: $u->user_login) ?>"><option value="">+ rol</option><?php foreach ($erbij as $k => $l): ?><option value="<?= esc_attr($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></form><?php endif; ?>
    </div></td>
    <?php if (module_actief('trainers')): $pm = get_user_meta($u->ID, 'khzs_penningmeester', true) === '1'; ?><td>
      <?php if ($is_beheerder && !$wpbeheer): ?><form method="post" action="<?= esc_url(app_url('gebruikers/')) ?>"><?= nonce_veld() ?><input type="hidden" name="user" value="<?= (int) $u->ID ?>">
        <input type="hidden" name="penningmeester" value="<?= $pm ? '0' : '1' ?>"><label style="margin:0"><input type="checkbox" <?= $pm ? 'checked' : '' ?> onchange="this.form.submit()"> ja</label></form>
      <?php else: ?><?= ($pm || $wpbeheer) ? 'ja' : '—' ?><?php endif; ?></td><?php endif; ?></tr>
  <?php endforeach; ?>
</table></div>
<p class="help">Een account kan meerdere rollen hebben. Zwemmer en ouder geven toegang tot de eigen zwemmers; trainer, sportsecretaris en beheerder geven de rechten van de staf; een bestuurder beheert leden en rollen. Enkel een beheerder kan beheerders aanstellen of wijzigen.</p>
<p class="klein">Accounts zijn de gewone WordPress-gebruikers. Wie WordPress-beheerder is, is in de app altijd beheerder.</p>
