<?php namespace KHZS; defined('ABSPATH') || exit; include __DIR__ . '/_stijl.php';
$basis = fn(array $q = []) => app_url('trainers/overzicht/', ['maand' => $maand] + $q);
$vandaag = vandaag()->format('Y-m-d'); ?>
<div class="rij tussen"><h1>Overzicht trainers</h1>
  <div class="rij"><a class="knop secundair" href="<?= esc_url(app_url('trainers/kalender/')) ?>">🗓️ Kalender</a><a class="knop secundair" href="<?= esc_url(app_url('trainers/planning/')) ?>">📅 Planning</a>
  <?php if (tr_zw_ploegen()): ?><a class="knop secundair" href="<?= esc_url(app_url('trainers/zwemmers/')) ?>">🏊 Zwemmers</a><?php endif; ?>
  <?php if (is_penningmeester()): ?><a class="knop secundair" href="<?= esc_url(app_url('trainers/uren/', ['maand' => $maand])) ?>">Verloning</a><?php endif; ?>
  <?php if (tr_mag_beheren()): ?><a class="knop secundair" href="<?= esc_url(app_url('trainers/beheer/')) ?>">Trainingen beheren</a><?php endif; ?></div></div>
<div class="kaart tr-week">
  <a class="knop mini secundair" href="<?= esc_url(app_url('trainers/overzicht/', ['maand' => $vorige] + ($detail ? ['trainer' => $detail['uid']] : []))) ?>">← Vorige</a>
  <strong style="text-transform:capitalize"><?= e(tr_maandnaam($maand)) ?></strong>
  <a class="knop mini secundair" href="<?= esc_url(app_url('trainers/overzicht/', ['maand' => $volgende] + ($detail ? ['trainer' => $detail['uid']] : []))) ?>">Volgende →</a>
</div>
<p class="klein">Enkel trainingen die als <b>aanwezig</b> bevestigd zijn, tellen mee voor de uren. Niet bevestigde trainingen tellen pas mee als de trainer of het bestuur ze bevestigt.</p>

<?php if (!$detail): ?>
<div class="kaart tabelwrap"><table>
  <tr><th>Trainer</th><th>Vaste trainingen</th><th>Aanwezig</th><th>Niet aanwezig</th><th>Niet bevestigd</th><th>Uren</th></tr>
  <?php foreach ($rijen as $uid => $r): ?>
  <tr>
    <td><a href="<?= esc_url($basis(['trainer' => $uid])) ?>"><strong><?= e(tr_naam($r['user'] ?: $uid)) ?></strong></a>
      <?php if ($r['gepland']): ?><div class="klein"><?= (int) $r['gepland'] ?> nog gepland</div><?php endif; ?></td>
    <td class="klein"><?php $_namen = [];
      foreach ($vast[$uid] ?? [] as $v) {
          $_mm = tr_moment((int) $v->moment_id);
          $_namen[] = $_mm ? $_mm->naam . ' (' . substr(TR_DAGEN[$_mm->weekdag], 0, 2) . ')' : '?';
      }
      echo e(implode(', ', array_unique($_namen)) ?: '—'); ?></td>
    <td><?= (int) $r['trainingen'] ?></td>
    <td><?= (int) $r['nee'] ?: '' ?></td>
    <td><?php if ($r['open']): ?><a class="badge wacht" href="<?= esc_url($basis(['trainer' => $uid])) ?>"><?= (int) $r['open'] ?> niet bevestigd</a><?php endif; ?></td>
    <td><?= e(tr_uren($r['minuten'])) ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rijen): ?><tr><td colspan="6" class="klein">Geen trainers met trainingen in deze maand.</td></tr><?php endif; ?>
</table></div>

<?php else: $uid = $detail['uid']; $r = $detail['r']; ?>
<p><a href="<?= esc_url($basis()) ?>">← Alle trainers</a></p>
<h2><?= e(tr_naam($detail['user'])) ?></h2>
<div class="stappen">
  <div class="stap">Aanwezig<b><?= (int) $r['trainingen'] ?></b></div>
  <div class="stap">Uren<b><?= e(tr_uren($r['minuten'])) ?></b></div>
  <div class="stap">Niet aanwezig<b><?= (int) $r['nee'] ?></b></div>
  <div class="stap">Niet bevestigd<b><?= (int) $r['open'] ?></b></div>
</div>
<?php if ($detail['uitbetaald']): ?><div class="melding info">Deze maand is voor <?= e(tr_voornaam($uid)) ?> al uitbetaald. Wijzigingen verschijnen in de verloning als ‘gewijzigd na uitbetaling’.</div><?php endif; ?>

<h3>Trainingen in <?= e(tr_maandnaam($maand)) ?></h3>
<div class="kaart">
  <?php $_naam = false; foreach ($detail['trainingen'] as $b) { $_wie = $uid; include __DIR__ . '/_bevestiging.php'; } ?>
  <?php if (!$detail['trainingen']): ?><p class="klein">Geen trainingen deze maand.</p><?php endif; ?>
  <?php if ($r['open']): ?>
  <form method="post" action="<?= esc_url($hier) ?>" style="margin-top:.6rem" onsubmit="return confirm('Alle niet bevestigde trainingen van deze maand als aanwezig bevestigen?')"><?= nonce_veld() ?>
    <input type="hidden" name="actie" value="alles_ja"><input type="hidden" name="user" value="<?= (int) $uid ?>">
    <button class="mini ok">✓ Alle <?= (int) $r['open'] ?> niet bevestigde als aanwezig bevestigen</button></form>
  <?php endif; ?>
  <p class="help">Wijzig je iets, dan krijgt <?= e(tr_voornaam($uid)) ?> een melding en ziet die in de app wat er veranderd is, door wie en wanneer. Een trainer toevoegen of afmelden voor één training doe je op de training zelf.</p>
</div>

<h3>Vaste trainingen (schema)</h3>
<div class="kaart">
  <?php foreach ($detail['momenten'] as $m): $v = $detail['vast'][(int) $m->id] ?? null; ?>
  <div class="tr-rij">
    <div class="tr-info"><strong><?= e($m->naam) ?></strong>
      <span class="klein"><?= e(ucfirst(TR_DAGEN[$m->weekdag])) ?> <?= e(tr_tijd($m)) ?><?= $v ? ' · vanaf ' . e(datum($v->van, 'j/m/Y')) . ($v->tot ? ' tot ' . e(datum($v->tot, 'j/m/Y')) : '') : '' ?></span></div>
    <form method="post" action="<?= esc_url(app_url('trainers/vast/')) ?>" class="tr-status"><?= nonce_veld() ?>
      <input type="hidden" name="moment" value="<?= (int) $m->id ?>"><input type="hidden" name="user" value="<?= (int) $uid ?>">
      <input type="hidden" name="aan" value="<?= $v ? '0' : '1' ?>"><input type="hidden" name="terug" value="<?= esc_attr($hier) ?>">
      <?php if ($v): ?><span class="badge goedgekeurd">✓ geeft deze training</span><?php endif; ?>
      <input type="date" name="vanaf" value="<?= esc_attr(max($vandaag, $m->van)) ?>" style="width:auto" title="<?= $v ? 'Stopt vanaf' : 'Vanaf' ?>" aria-label="<?= $v ? 'Stopt vanaf' : 'Vanaf' ?>">
      <button class="mini <?= $v ? 'secundair' : '' ?>"><?= $v ? 'Stoppen' : 'Toevoegen' ?></button>
    </form>
  </div>
  <?php endforeach; ?>
  <?php if (!$detail['momenten']): ?><p class="klein">Er zijn geen trainingen ingesteld.</p><?php endif; ?>
</div>

<h3>Wijzigingen door het bestuur en het secretariaat</h3>
<div class="kaart">
  <?php $_log = $detail['log']; include __DIR__ . '/_log.php'; ?>
  <?php if (!$detail['log']): ?><p class="klein">Nog geen wijzigingen door anderen.</p><?php endif; ?>
</div>
<?php endif; ?>
