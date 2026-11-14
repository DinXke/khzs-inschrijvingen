<?php namespace KHZS; defined('ABSPATH') || exit; ?>
<h1>Mijn rollen</h1>
<div class="kaart">
  <div class="rollen"><?php foreach ($eigen as $k): ?><span class="badge rol"><?= e(ROLLEN[$k]) ?></span><?php endforeach; ?></div>
</div>
<?php if ($aanvraagbaar): ?>
<form method="post" class="kaart" action="<?= esc_url(app_url('rollen/')) ?>"><?= nonce_veld() ?>
  <h2 style="margin-top:0">Rol aanvragen</h2>
  <div class="veld"><label for="rol">Rol</label>
    <select id="rol" name="rol" required><?php foreach ($aanvraagbaar as $k): ?><option value="<?= esc_attr($k) ?>"><?= e(ROLLEN[$k]) ?><?= in_array($k, ZONDER_GOEDKEURING, true) ? ' (meteen)' : '' ?></option><?php endforeach; ?></select></div>
  <div class="veld"><label for="motivatie">Toelichting (optioneel)</label>
    <input type="text" id="motivatie" name="motivatie" maxlength="300" placeholder="bv. trainer van de B-groep sinds september"></div>
  <p class="help">Zwemmer en ouder krijg je meteen. De andere rollen keurt een bestuurder, de sportsecretaris of een beheerder eerst goed; je krijgt een melding zodra dat gebeurd is.</p>
  <p><button>Aanvragen</button></p>
</form>
<?php endif; ?>
<?php if ($aanvragen): ?>
<div class="kaart melding-lijst">
  <h2 style="margin-top:0">Aanvragen</h2>
  <?php foreach ($aanvragen as $a):
    $_nieuw = !$a->gelezen && !in_array($a->status, [RA_OPEN, RA_INGETROKKEN], true);
    $_badge = [RA_OPEN => 'wacht', RA_AFGEKEURD => 'fout', RA_INGETROKKEN => 'doorgestuurd'][$a->status] ?? 'ok';
    $_door = $a->behandeld_door ? get_userdata((int) $a->behandeld_door) : null; ?>
  <div class="melding-item<?= $_nieuw ? ' nieuw' : '' ?>">
    <div class="rij tussen"><strong><?= e(ROLLEN[$a->rol] ?? $a->rol) ?></strong><span class="badge <?= $_badge ?>"><?= e(RA_STATUS[$a->status] ?? '') ?></span></div>
    <div class="klein">Aangevraagd op <?= e(datum($a->aangemaakt, 'j/m/Y')) ?><?php if ($a->behandeld_op): ?> · behandeld op <?= e(datum($a->behandeld_op, 'j/m/Y')) ?><?php if ($_door): ?> door <?= e(volle_naam($_door) ?: $_door->user_login) ?><?php endif; endif; ?></div>
    <?php if ($a->status === RA_ANDERE_ROL): ?><div>Je kreeg in de plaats de rol <strong><?= e(rol_naam($a->toegekende_rol)) ?></strong>.</div><?php endif; ?>
    <?php if ($a->reden !== ''): ?><div><?= e($a->reden) ?></div><?php endif; ?>
    <?php if ($a->status === RA_OPEN): ?><form method="post" action="<?= esc_url(app_url('rollen/')) ?>" style="margin-top:.3rem"><?= nonce_veld() ?><input type="hidden" name="aanvraag" value="<?= (int) $a->id ?>">
      <button name="actie" value="intrekken" class="mini secundair">Intrekken</button></form><?php endif; ?>
  </div><?php endforeach; ?>
</div>
<?php endif; ?>
