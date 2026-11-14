<?php namespace KHZS; defined('ABSPATH') || exit; ?>
<h1>Gebruikers & rollen</h1>
<form method="get" class="kaart" action="<?= esc_url(app_url('gebruikers/')) ?>"><?= pad_veld('gebruikers/') ?><input type="search" name="q" value="<?= esc_attr($q) ?>" placeholder="Zoek op naam of e-mail…"></form>
<div class="kaart tabelwrap"><table>
  <tr><th>Naam</th><th>E-mail</th><th>Rol</th><?php if (module_actief('trainers')): ?><th>Penningmeester</th><?php endif; ?></tr>
  <?php foreach ($users as $u): $r = rol($u); $wpbeheer = user_can($u, 'manage_options'); $vast = $wpbeheer || ($r === BEHEERDER && !$is_beheerder); ?>
  <tr><td><?= e(volle_naam($u) ?: '—') ?></td><td><?= e($u->user_email ?: $u->user_login) ?></td>
    <td><form method="post" class="rij" action="<?= esc_url(app_url('gebruikers/')) ?>"><?= nonce_veld() ?><input type="hidden" name="user" value="<?= (int) $u->ID ?>">
      <select name="rol" onchange="this.form.submit()" style="width:auto" <?= $vast ? 'disabled' : '' ?>><?php if ($vast): ?><option><?= $wpbeheer ? 'Beheerder (WordPress)' : 'Beheerder' ?></option><?php else: foreach ($rollen as $k => $l): ?><option value="<?= esc_attr($k) ?>" <?= $r === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; endif; ?></select>
    </form></td>
    <?php if (module_actief('trainers')): $pm = get_user_meta($u->ID, 'khzs_penningmeester', true) === '1'; ?><td>
      <?php if ($is_beheerder && !$wpbeheer): ?><form method="post" action="<?= esc_url(app_url('gebruikers/')) ?>"><?= nonce_veld() ?><input type="hidden" name="user" value="<?= (int) $u->ID ?>">
        <input type="hidden" name="penningmeester" value="<?= $pm ? '0' : '1' ?>"><label style="margin:0"><input type="checkbox" <?= $pm ? 'checked' : '' ?> onchange="this.form.submit()"> ja</label></form>
      <?php else: ?><?= ($pm || $wpbeheer) ? 'ja' : '—' ?><?php endif; ?></td><?php endif; ?></tr>
  <?php endforeach; ?>
</table></div>
<p class="klein">Accounts zijn de gewone WordPress-gebruikers. Wie WordPress-beheerder is, is in de app altijd beheerder.</p>
