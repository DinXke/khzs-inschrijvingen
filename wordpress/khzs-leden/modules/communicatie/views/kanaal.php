<?php namespace KHZS; defined('ABSPATH') || exit;
include __DIR__ . '/_stijl.php';
$_uid = get_current_user_id();
$_url = app_url("berichten/kanaal/{$k->id}/");
$_ongelezen = com_ongelezen($_uid);
?>
<div class="com-pad klein"><a href="<?= esc_url(app_url('berichten/')) ?>">← Berichten</a><?= $sectie ? ' · ' . e($sectie->naam) : '' ?><?php if ($ouder): ?> › <a href="<?= esc_url(app_url("berichten/kanaal/{$ouder->id}/")) ?>"><?= e($ouder->naam) ?></a><?php endif; ?></div>
<div class="rij tussen com-kop">
  <h1><?= com_icoon($k) ?> <?= e($k->naam) ?></h1>
  <div class="rij">
    <form method="post" action="<?= esc_url($_url) ?>"><?= nonce_veld() ?><input type="hidden" name="actie" value="meldingen">
      <?php if ($meldingen): ?><button class="mini secundair" name="aan" value="0" title="Geen meldingen meer voor nieuwe berichten in dit kanaal">🔕 Dempen</button>
      <?php else: ?><button class="mini secundair" name="aan" value="1" title="Een melding bij elk nieuw bericht in dit kanaal">🔔 Meldingen aan</button><?php endif; ?></form>
    <?php if ($beheren): ?><a class="knop mini secundair" href="<?= esc_url(app_url("berichten/kanaal/{$k->id}/instellingen/")) ?>" title="Instellingen van het kanaal">⚙</a><?php endif; ?>
  </div>
</div>
<?php if ($k->beschrijving !== ''): ?><p class="klein" style="margin-top:0"><?= e($k->beschrijving) ?></p><?php endif; ?>
<?php if ((int) $k->gearchiveerd): ?><div class="melding info">Dit kanaal is gearchiveerd: je kan de berichten nog lezen, maar er kan niemand meer posten.</div><?php endif; ?>

<?php foreach ($aankondigingen as $_a) { include __DIR__ . '/_aankondiging.php'; } ?>

<?php if ($vastgepind): ?>
<details class="kaart com-vast" <?= count($vastgepind) <= 2 ? 'open' : '' ?>><summary>📌 Vastgepind (<?= count($vastgepind) ?>)</summary>
  <?php foreach ($vastgepind as $_v): ?><a class="com-vast-item" href="#b<?= (int) $_v->id ?>">
    <span class="klein"><?= e(com_naam((int) $_v->user_id)) ?> · <?= e(com_wanneer($_v->aangemaakt)) ?></span>
    <div><?= $_v->tekst !== '' ? com_opmaak(mb_strimwidth($_v->tekst, 0, 400, '…'), $vastgepind_vermeld[(int) $_v->id] ?? []) : '📎 ' . e($_v->bijlage_naam) ?></div></a>
  <?php endforeach; ?>
</details>
<?php endif; ?>

<?php if ($threads || ($modereren && !$ouder && !(int) $k->gearchiveerd)): ?>
<div class="com-threads" aria-label="Threads">
  <?php foreach ($threads as $_t): if ((int) $_t->gearchiveerd) { continue; } ?><a href="<?= esc_url(app_url("berichten/kanaal/{$_t->id}/")) ?>"><?= com_icoon($_t) ?> <?= e($_t->naam) ?><?php if (!empty($_ongelezen[(int) $_t->id])): ?><span class="com-teller"><?= (int) $_ongelezen[(int) $_t->id] ?></span><?php endif; ?></a><?php endforeach; ?>
  <?php if ($modereren && !$ouder && !(int) $k->gearchiveerd): ?><a href="#thread-nieuw" onclick="document.getElementById('thread-nieuw').open=true">+ Thread</a><?php endif; ?>
</div>
<?php $_archief = array_filter($threads, fn($t) => (int) $t->gearchiveerd); if ($_archief): ?>
<details class="klein" style="margin-bottom:.6rem"><summary>Archief (<?= count($_archief) ?>)</summary>
  <?php foreach ($_archief as $_t): ?><a href="<?= esc_url(app_url("berichten/kanaal/{$_t->id}/")) ?>"><?= e($_t->naam) ?></a><br><?php endforeach; ?></details>
<?php endif; ?>
<?php if ($modereren && !$ouder && !(int) $k->gearchiveerd): ?>
<details class="kaart com-thread-nieuw" id="thread-nieuw"><summary>+ Nieuwe thread in <?= e($k->naam) ?></summary>
  <form method="post" action="<?= esc_url($_url) ?>"><?= nonce_veld() ?><input type="hidden" name="actie" value="thread">
    <?= veld_tekst('naam', 'Onderwerp', '', null, ['maxlength' => 80, 'required' => true, 'placeholder' => 'bv. Stage Pasen']) ?>
    <?= veld_textarea('tekst', 'Eerste bericht (mag leeg)', '', null, 2) ?>
    <p class="help">Een thread volgt de instellingen van <?= e($k->naam) ?>: dezelfde mensen zien en schrijven er.</p>
    <p><button>Thread starten</button></p></form>
</details>
<?php endif; ?>
<?php endif; ?>

<div class="com-chat" id="com-chat" aria-live="polite">
  <?php if ($meer): ?><p style="text-align:center"><a class="knop mini secundair" href="<?= esc_url(add_query_arg('voor', (int) $eerste, $_url)) ?>">↑ Oudere berichten</a></p><?php endif; ?>
  <?php if ($voor): ?><p style="text-align:center"><a class="knop mini secundair" href="<?= esc_url($_url) ?>">↓ Naar de nieuwste berichten</a></p><?php endif; ?>
  <?= $berichten_html /* HTML uit _bericht.php, daar ge-escaped */ ?>
  <?php if (!$berichten_html): ?><div class="leeg" id="com-leeg">Nog geen berichten in <?= e($k->naam) ?>.<?= $mag_posten ? ' Schrijf het eerste!' : '' ?></div><?php endif; ?>
</div>

<?php if ($mag_posten && !$voor): ?>
<form class="com-schrijf" id="com-form" method="post" action="<?= esc_url($_url) ?>" enctype="multipart/form-data"><?= nonce_veld() ?>
  <input type="hidden" name="actie" value="plaats"><input type="hidden" name="na" id="com-na" value="<?= (int) $laatste ?>">
  <div class="com-suggesties" id="com-suggesties" hidden></div>
  <div class="com-schrijf-rij">
    <?php if ($modereren): ?><label class="com-bijlage-knop" title="Foto of pdf toevoegen (max. 5 MB)">📎<input type="file" name="bijlage" id="com-bijlage" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf"></label><?php endif; ?>
    <textarea name="tekst" id="com-tekst" rows="1" maxlength="4000" placeholder="Bericht in <?= esc_attr($k->naam) ?>…" aria-label="Bericht"></textarea>
    <button title="Versturen" aria-label="Versturen">➤</button>
  </div>
  <div class="com-bijlage-naam" id="com-bijlage-naam"></div>
  <div class="com-fout" id="com-fout" hidden></div>
</form>
<?php elseif (!$voor): ?>
<div class="kaart com-alleen-lezen"><?= (int) $k->gearchiveerd ? 'Dit kanaal is gearchiveerd.' : 'In dit kanaal posten enkel trainers en bestuur.' ?></div>
<?php endif; ?>

<?php if (!$voor): ?>
<script>
(function () {
  const chat = document.getElementById("com-chat"), form = document.getElementById("com-form");
  const NIEUW = <?= wp_json_encode(app_url("berichten/kanaal/{$k->id}/nieuw/"), JSON_UNESCAPED_SLASHES) ?>;
  const NAMEN = <?= wp_json_encode($vermeldbaar, JSON_UNESCAPED_UNICODE) ?>;
  let laatste = <?= (int) $laatste ?>, bezig = false;
  const onderaan = () => window.innerHeight + window.scrollY >= document.body.scrollHeight - 160;
  const naarOnder = () => window.scrollTo(0, document.body.scrollHeight);
  if (!location.hash) naarOnder();
  function voegToe(html, scroll) {
    if (!html) return;
    const leeg = document.getElementById("com-leeg"); if (leeg) leeg.remove();
    const t = document.createElement("template"); t.innerHTML = html;
    t.content.querySelectorAll(".com-b").forEach(b => { const oud = document.getElementById(b.id); if (oud) oud.remove(); });
    chat.appendChild(t.content);
    if (scroll) naarOnder();
  }
  // Nieuwe berichten ophalen (elke 8 seconden zolang de pagina zichtbaar is).
  async function vernieuw() {
    if (bezig || document.visibilityState !== "visible") return;
    bezig = true;
    try {
      const r = await fetch(NIEUW + "?na=" + laatste, {credentials: "same-origin"});
      const j = await r.json();
      if (j.laatste > laatste) { const s = onderaan(); voegToe(j.html, s); laatste = j.laatste; if (form) form.na.value = laatste; }
    } catch (e) {} finally { bezig = false; }
  }
  setInterval(vernieuw, 8000);
  document.addEventListener("visibilitychange", vernieuw);
  if (!form) return;
  const tekst = document.getElementById("com-tekst"), fout = document.getElementById("com-fout"), bijlage = document.getElementById("com-bijlage");
  const groei = () => { tekst.style.height = "auto"; tekst.style.height = Math.min(tekst.scrollHeight, 160) + "px"; };
  tekst.addEventListener("input", groei);
  if (bijlage) bijlage.addEventListener("change", () => { document.getElementById("com-bijlage-naam").textContent = bijlage.files[0] ? "📎 " + bijlage.files[0].name : ""; });
  // Versturen zonder de pagina te herladen; zonder JavaScript werkt het formulier gewoon.
  form.addEventListener("submit", async e => {
    e.preventDefault();
    if (!tekst.value.trim() && !(bijlage && bijlage.files.length)) return;
    bezig = true; fout.hidden = true;
    form.querySelector("button[aria-label=Versturen]").disabled = true;
    try {
      const r = await fetch(form.action, {method: "POST", credentials: "same-origin", body: new FormData(form), headers: {"X-KHZS-Ajax": "1"}});
      const j = await r.json();
      if (!j.ok) throw new Error(j.fout || "Versturen lukte niet.");
      voegToe(j.html, true); laatste = Math.max(laatste, j.laatste); form.na.value = laatste;
      tekst.value = ""; groei(); if (bijlage) { bijlage.value = ""; document.getElementById("com-bijlage-naam").textContent = ""; }
    } catch (err) { fout.textContent = err.message || "Versturen lukte niet."; fout.hidden = false; }
    finally { bezig = false; form.querySelector("button[aria-label=Versturen]").disabled = false; tekst.focus(); }
  });
  // Op een computer verstuurt Enter; Shift+Enter is een nieuwe regel. Op de gsm is Enter gewoon een nieuwe regel.
  const computer = window.matchMedia("(pointer: fine)").matches;
  // @naam: suggesties uit de mensen die het kanaal zien (enkel trainers, bestuur, schrijvers en leden van de ploeg of wedstrijd).
  const lijst = document.getElementById("com-suggesties");
  let keuze = 0;
  function zoek() {
    const voor = tekst.value.slice(0, tekst.selectionStart);
    const m = voor.match(/(^|\s)@([^\s@]{0,20}(?: [^\s@]{0,20})?)$/);
    if (!m || !NAMEN.length) { lijst.hidden = true; return null; }
    const q = m[2].toLowerCase();
    const treffers = NAMEN.filter(n => n.toLowerCase().startsWith(q) || n.toLowerCase().split(" ").some(d => d.startsWith(q))).slice(0, 6);
    if (!treffers.length) { lijst.hidden = true; return null; }
    keuze = Math.min(keuze, treffers.length - 1);
    lijst.innerHTML = "";
    treffers.forEach((n, i) => { const b = document.createElement("button"); b.type = "button"; b.textContent = n; if (i === keuze) b.className = "actief";
      b.addEventListener("mousedown", ev => { ev.preventDefault(); kies(n); }); lijst.appendChild(b); });
    lijst.hidden = false;
    return {start: voor.length - m[2].length - 1, treffers};
  }
  function kies(naam) {
    const pos = tekst.selectionStart, voor = tekst.value.slice(0, pos), na = tekst.value.slice(pos);
    const start = voor.lastIndexOf("@");
    tekst.value = voor.slice(0, start) + "@" + naam + " " + na;
    const p = start + naam.length + 2; tekst.setSelectionRange(p, p); lijst.hidden = true; tekst.focus();
  }
  tekst.addEventListener("input", () => { keuze = 0; zoek(); });
  tekst.addEventListener("blur", () => setTimeout(() => lijst.hidden = true, 150));
  tekst.addEventListener("keydown", e => {
    if (!lijst.hidden) {
      const z = zoek();
      if (z && (e.key === "ArrowDown" || e.key === "ArrowUp")) { e.preventDefault(); keuze = (keuze + (e.key === "ArrowDown" ? 1 : z.treffers.length - 1)) % z.treffers.length; zoek(); return; }
      if (z && (e.key === "Enter" || e.key === "Tab")) { e.preventDefault(); kies(z.treffers[keuze]); return; }
      if (e.key === "Escape") { lijst.hidden = true; return; }
    }
    if (computer && e.key === "Enter" && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
  });
})();
</script>
<?php endif; ?>
