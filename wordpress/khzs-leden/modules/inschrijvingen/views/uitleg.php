<?php namespace KHZS; defined('ABSPATH') || exit; ?>
<h1>Uitleg</h1>
<?php if (!$filmpjes && !$pdfs): ?>
<div class="kaart"><p class="klein">Er zijn nog geen filmpjes of handleidingen geïnstalleerd.<?php if ($is_beheerder): ?> Bouw de plugin met <code>bouw.sh --met-uitleg</code> om de filmpjes en PDF's mee te leveren.<?php endif; ?></p></div>
<?php else: ?>
<p class="klein">Korte instructiefilmpjes met uitleg, en de handleidingen als PDF. Zet het geluid aan, of gebruik de ondertitels.</p>
<div class="grid twee">
  <?php foreach ($filmpjes as [$code, $titel, $pdf]): ?>
  <div class="kaart filmpje">
    <h2 style="margin-top:0"><?= e($titel) ?></h2>
    <video controls preload="metadata" playsinline src="<?= esc_url(KHZS_URL . "assets/docs/video/$code.mp4") ?>">
      <track kind="subtitles" srclang="nl" label="Nederlands" src="<?= esc_url(KHZS_URL . "assets/docs/video/$code.vtt") ?>" default>
    </video>
    <?php if (is_file(KHZS_PAD . "assets/docs/pdf/$pdf.pdf")): ?><p class="klein"><a href="<?= esc_url(KHZS_URL . "assets/docs/pdf/$pdf.pdf") ?>" target="_blank">📄 Handleiding (PDF)</a></p><?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php if ($pdfs): ?>
<h2>Documentatie</h2>
<div class="kaart"><ul style="margin:0">
  <?php foreach ($pdfs as $pdf): ?><li><a href="<?= esc_url(KHZS_URL . "assets/docs/pdf/$pdf.pdf") ?>" target="_blank"><?= e(PDF_NAMEN[$pdf] ?? $pdf) ?></a></li><?php endforeach; ?>
</ul></div>
<?php endif; endif; ?>
