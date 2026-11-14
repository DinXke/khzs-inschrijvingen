<?php namespace KHZS; defined('ABSPATH') || exit;
/** Eén aankondiging (kaart). Krijgt $_a en $_uid; een vraag naar hulp toont de knoppen ja / misschien / nee. */
$_afgelopen = $_a->tot && $_a->tot < vandaag()->format('Y-m-d');
$_url = app_url("berichten/aankondiging/{$_a->id}/");
?>
<div class="kaart com-aank com-aank-<?= esc_attr($_a->soort) ?><?= $_afgelopen ? ' afgelopen' : '' ?>" id="a<?= (int) $_a->id ?>">
  <div class="rij tussen"><span class="com-aank-soort"><?= COM_AANK_ICOON[$_a->soort] ?> <?= e(COM_AANK_SOORT[$_a->soort]) ?><?= $_afgelopen ? ' · afgelopen' : '' ?></span>
    <span class="klein"><?= e(com_aank_doel($_a)) ?> · <?= e(com_naam((int) $_a->door_id)) ?> · <?= e(com_wanneer($_a->aangemaakt)) ?></span></div>
  <h3><a href="<?= esc_url($_url) ?>"><?= e($_a->titel) ?></a></h3>
  <?php if ($_a->wanneer !== ''): ?><p class="klein" style="margin:.2rem 0">📅 <?= e($_a->wanneer) ?></p><?php endif; ?>
  <?php if (trim($_a->tekst) !== ''): ?><div class="com-aank-tekst"><?= com_opmaak($_a->tekst) ?></div><?php endif; ?>
  <?php if ($_a->soort === 'hulp'):
    $_t = com_rsvp_totalen((int) $_a->id);
    $_mijn = db()->get_row(db()->prepare("SELECT * FROM " . t('com_rsvp') . " WHERE aankondiging_id=%d AND user_id=%d", $_a->id, $_uid)); ?>
  <div class="com-rsvp">
    <div class="klein"><strong><?= (int) $_t['ja']['personen'] ?></strong><?= (int) $_a->nodig ? ' van de ' . (int) $_a->nodig . ' nodige' : '' ?> <?= (int) $_t['ja']['personen'] === 1 ? 'persoon komt' : 'personen komen' ?><?= $_t['misschien']['personen'] ? ' · ' . (int) $_t['misschien']['personen'] . ' misschien' : '' ?>
      <?php if (com_ziet_rsvp_lijst($_a, $_uid)): ?> · <a href="<?= esc_url($_url) ?>#lijst">Lijst bekijken</a><?php endif; ?></div>
    <?php if ((int) $_a->nodig): ?><div class="voortgang"><span style="width:<?= min(100, (int) round(100 * $_t['ja']['personen'] / (int) $_a->nodig)) ?>%"></span></div><?php endif; ?>
    <?php if (!$_afgelopen): ?>
    <form method="post" action="<?= esc_url($_url) ?>"><?= nonce_veld() ?><input type="hidden" name="actie" value="rsvp"><input type="hidden" name="terug" value="<?= esc_attr(huidige_url()) ?>#a<?= (int) $_a->id ?>">
      <div class="segment" role="group" aria-label="Kan je helpen?">
        <?php foreach (COM_RSVP as $_k => $_l): ?><button name="antwoord" value="<?= esc_attr($_k) ?>" class="r-<?= esc_attr($_k) ?><?= $_mijn && $_mijn->antwoord === $_k ? ' gekozen' : '' ?>"><?= e($_l) ?></button><?php endforeach; ?>
      </div>
      <div class="rij">
        <label>Met <input type="number" name="personen" min="1" max="50" value="<?= (int) ($_mijn->personen ?? 0) ?: 1 ?>" aria-label="Met hoeveel personen"> persoon/personen</label>
        <input type="text" name="opmerking" maxlength="200" value="<?= esc_attr($_mijn->opmerking ?? '') ?>" placeholder="Opmerking (bv. enkel in de voormiddag)" aria-label="Opmerking">
      </div>
      <?php if ($_mijn): ?><p class="help">Jouw antwoord: <strong><?= e(COM_RSVP[$_mijn->antwoord]) ?></strong><?= $_mijn->antwoord !== 'nee' ? ' met ' . (int) $_mijn->personen . ((int) $_mijn->personen === 1 ? ' persoon' : ' personen') : '' ?>. Kies opnieuw om te wijzigen.</p><?php endif; ?>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
