<?php namespace KHZS; defined('ABSPATH') || exit;
/**
 * Aanwezigheid van de zwemmers op een training. Verwacht $b, $m, $datum, $zw_ploegen en $zw_lijst (zie v_tr_training).
 * Nog niet aangeduid: iedereen staat aangevinkt, de trainer vinkt af wie er niet was en bewaart (het snelst op de gsm).
 */
if (!$zw_ploegen) {
    return;
}
$_aangeduid = array_filter($zw_lijst, fn($l) => $l['laatst']);
$_totaal = array_sum(array_map(fn($l) => count($l['zwemmers']), $zw_lijst));
?>
<div class="kaart" id="zwemmers">
  <h2 style="margin-top:0">Zwemmers</h2>
  <?php if ($b->afgelast): ?><p class="klein">De training is afgelast: er valt niets aan te duiden.</p>
  <?php elseif (!tr_zw_kan($b)): ?><p class="klein">Je kunt vanaf de dag van de training aanduiden welke zwemmers er waren.</p>
  <?php elseif (!$_totaal): ?><p class="klein">Er zitten nog geen zwemmers in <?= e(implode(', ', array_map(fn($p) => $p->naam, $zw_ploegen))) ?>.</p>
  <?php else: ?>
  <form method="post" action="<?= esc_url(app_url('trainers/zwemmers/actie/')) ?>" class="tr-zw-form"><?= nonce_veld() ?>
    <input type="hidden" name="moment" value="<?= (int) $m->id ?>"><input type="hidden" name="datum" value="<?= esc_attr($datum) ?>">
    <p class="klein" style="margin-top:0"><?php if ($_aangeduid): $_l = array_reduce($_aangeduid, fn($c, $l) => !$c || $l['laatst']->op > $c->op ? $l['laatst'] : $c); ?>
      Aangeduid door <?= e(tr_voornaam((int) $_l->door_id)) ?> op <?= e(datum($_l->op, 'j/m H:i')) ?>. Pas aan en bewaar opnieuw als het niet klopt.
    <?php else: ?>Nog niet aangeduid. Iedereen staat aangevinkt: vink af wie er niet was en bewaar.<?php endif; ?></p>
    <div class="rij tr-zw-knoppen">
      <button type="button" class="mini secundair" data-zw="alle">✓ Iedereen aanwezig</button>
      <button type="button" class="mini secundair" data-zw="geen">Niemand</button>
    </div>
    <?php foreach ($zw_lijst as $_l): if (!$_l['zwemmers']) { continue; } ?>
      <?php if (count($zw_lijst) > 1): ?><h3 class="tr-zw-ploeg"><?= e($_l['ploeg']->naam) ?> <span class="klein">(<?= count($_l['zwemmers']) ?>)</span></h3><?php endif; ?>
      <div class="tr-zw-lijst">
      <?php foreach ($_l['zwemmers'] as $_z): ?>
        <label class="tr-zw"><input type="checkbox" name="zwemmer[]" value="<?= (int) $_z->id ?>"<?= $_z->aanwezig !== 0 ? ' checked' : '' ?>>
          <span><?= e(trim("$_z->voornaam $_z->naam")) ?></span></label>
      <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    <div class="tr-zw-bewaar"><button class="ok">Bewaar <span data-zw-teller><?= $_aangeduid ? '' : "($_totaal/$_totaal aanwezig)" ?></span></button></div>
  </form>
  <?php if ($_aangeduid): ?>
  <form method="post" action="<?= esc_url(app_url('trainers/zwemmers/actie/')) ?>" style="margin-top:.5rem"><?= nonce_veld() ?>
    <input type="hidden" name="moment" value="<?= (int) $m->id ?>"><input type="hidden" name="datum" value="<?= esc_attr($datum) ?>"><input type="hidden" name="actie" value="wis">
    <button class="mini secundair" onclick="return confirm('De aanduiding van de zwemmers wissen?')">Aanduiding wissen</button></form>
  <?php endif; ?>
  <script>
  (function () {
    var f = document.querySelector('.tr-zw-form'); if (!f) return;
    var vakjes = f.querySelectorAll('input[name="zwemmer[]"]'), teller = f.querySelector('[data-zw-teller]');
    function tel() { var n = 0; vakjes.forEach(function (v) { if (v.checked) n++; }); teller.textContent = '(' + n + '/' + vakjes.length + ' aanwezig)'; }
    f.querySelectorAll('[data-zw]').forEach(function (k) {
      k.addEventListener('click', function () { var aan = k.dataset.zw === 'alle'; vakjes.forEach(function (v) { v.checked = aan; }); tel(); });
    });
    f.addEventListener('change', tel); tel();
  })();
  </script>
  <?php endif; ?>
</div>
