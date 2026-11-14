<?php namespace KHZS; defined('ABSPATH') || exit;
/**
 * Tabel met tellingen per rij (ploeg, maand, training, weekdag of zwemmer): balk, cijfers per soort en percentages.
 * Verwacht $_kop (titel van de eerste kolom) en $_rijen = [[label (HTML), telling, extra (HTML, optioneel)], …].
 * De balk is de grafiek, de cijfers ernaast zijn de tabelweergave (identiteit nooit enkel via kleur).
 */
?>
<div class="kaart tabelwrap"><table class="tr-zw-tabel">
  <thead><tr><th><?= e($_kop) ?></th><th class="tr-zw-balkkol">Verdeling</th><th title="aanwezig, te laat of vroeger weg">%</th>
    <th class="klein" title="zonder de trainingen waarop de zwemmer ziek was">% zonder ziek</th>
    <?php foreach (TR_ZW_SOORT as $_n): ?><th class="klein tr-zw-num"><?= e(ucfirst($_n)) ?></th><?php endforeach; ?></tr></thead>
  <tbody><?php foreach ($_rijen as [$_label, $_t]): $_p = tr_zw_pct($_t); ?>
    <tr><td><?= $_label ?></td><td class="tr-zw-balkkol"><?= tr_zw_balk($_t) ?></td>
      <td><strong class="tr-zw-pct<?= $_p === null ? '' : ($_p >= 75 ? ' hoog' : ($_p >= 50 ? ' midden' : ' laag')) ?>"><?= e(tr_zw_procent($_t)) ?></strong></td>
      <td class="klein"><?= e(tr_zw_procent($_t, true)) ?></td>
      <?php foreach (array_keys(TR_ZW_SOORT) as $_k): ?><td class="tr-zw-num"><?= (int) $_t[$_k] ?: '<span class="klein">·</span>' ?></td><?php endforeach; ?></tr>
  <?php endforeach; ?></tbody></table></div>
