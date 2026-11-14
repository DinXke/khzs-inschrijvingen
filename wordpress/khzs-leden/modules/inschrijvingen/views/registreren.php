<?php namespace KHZS; defined('ABSPATH') || exit; ?>
<h1>Profiel aanmaken</h1>
<form method="post" id="registratie" action="<?= esc_url(app_url('registreren/')) ?>"><?= nonce_veld() ?>
  <div class="kaart">
    <h2 style="margin-top:0">1. Account</h2>
    <p class="klein">Eén account per gezin: de ouder maakt het account aan en voegt daaronder al zijn of haar zwemmende kinderen toe. Zwem je zelf? Voeg jezelf dan toe als zwemmer.</p>
    <?= veld_tekst('email', 'E-mail', $form['email'], $fouten['email'] ?? null, ['type' => 'email', 'required' => true, 'autocomplete' => 'email']) ?>
    <?= veld_tekst('voornaam', 'Jouw voornaam', $form['voornaam'], $fouten['voornaam'] ?? null, ['maxlength' => 60, 'required' => true]) ?>
    <?= veld_tekst('naam', 'Jouw naam', $form['naam'], $fouten['naam'] ?? null, ['maxlength' => 80, 'required' => true]) ?>
    <?= veld_tekst('password1', 'Wachtwoord', '', $fouten['password1'] ?? null, ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password'], 'Minstens 8 tekens, niet enkel cijfers.') ?>
    <?= veld_tekst('password2', 'Wachtwoord bevestigen', '', $fouten['password2'] ?? null, ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password'], 'Geef hetzelfde wachtwoord nogmaals in, ter controle.') ?>
    <div class="veld"><label>Ik ben (meerdere mogelijk)</label><div>
      <?php $_gekozen = (array) ($_POST['rollen'] ?? []); foreach ([ZWEMMER => 'Zwemmer', OUDER => 'Ouder van een zwemmer', TRAINER => 'Trainer', SECRETARIS => 'Sportsecretaris', BESTUURDER => 'Bestuurder'] as $_r => $_l): ?>
      <div><label><input type="checkbox" name="rollen[]" value="<?= esc_attr($_r) ?>" <?= in_array($_r, $_gekozen, true) ? 'checked' : '' ?>> <?= e($_l) ?></label></div><?php endforeach; ?></div>
      <div class="help">Zwemmer en ouder krijg je meteen. Trainer, sportsecretaris en bestuurder worden eerst goedgekeurd door het bestuur. Later aanvragen kan bij Mijn zwemmers → Mijn rollen.</div></div>
  </div>
  <h2>2. Zwemmer(s)</h2>
  <label class="kaart" style="display:flex;gap:.6rem;align-items:center;cursor:pointer;margin-bottom:12px"><input type="checkbox" id="zelf" name="zelf" <?= post('zelf') ? 'checked' : '' ?> style="width:auto"> <span><b>Ik zwem zelf</b> <span class="klein">(bv. masters) — je eigen gegevens komen in het eerste zwemmerblok</span></span></label>
  <div class="kaart"><?= veld_tekst('koppelcode', 'Koppelcode (optioneel)', $form['koppelcode'], $fouten['koppelcode'] ?? null, ['autocomplete' => 'off', 'autocapitalize' => 'characters', 'placeholder' => 'bv. K7QM-2PXA'],
    'Heeft de andere ouder je kind al ingeschreven? Vraag een koppelcode (bij de zwemmer → ‘Koppelcode maken’) en vul ze hier in. Je hoeft je kind dan niet opnieuw toe te voegen.') ?></div>
  <input type="hidden" name="zw-TOTAL_FORMS" id="id_zw-TOTAL_FORMS" value="<?= count($zwemmers) ?>">
  <?= fouten_html($fouten['__zwemmers'] ?? null) ?>
  <div id="zwemmers">
    <?php foreach ($zwemmers as $i => [$zv, $zf]): ?>
    <div class="kaart zwemmer-blok"><div class="rij tussen"><strong>Zwemmer <span class="nr"><?= $i + 1 ?></span></strong>
      <button type="button" class="mini secundair weg" <?= $i === 0 ? 'hidden' : '' ?>>✕ Verwijder</button></div>
      <?= zwemmer_velden("zw-$i-", $zv, $zf) ?></div>
    <?php endforeach; ?>
  </div>
  <template id="leeg-zwemmer"><div class="kaart zwemmer-blok"><div class="rij tussen"><strong>Zwemmer <span class="nr"></span></strong>
    <button type="button" class="mini secundair weg">✕ Verwijder</button></div>
    <?= zwemmer_velden('zw-__prefix__-', [], []) ?></div></template>
  <p><button type="button" class="secundair vol" id="nog-zwemmer">+ Nog een zwemmer (broer/zus)</button></p>
  <p><button class="vol">Profiel aanmaken</button></p>
</form>
<p class="leeg">Al een account? <a href="<?= esc_url(app_url('aanmelden/')) ?>">Aanmelden</a></p>
<script>
(function () {
  const lijst = document.getElementById("zwemmers");
  const totaal = document.getElementById("id_zw-TOTAL_FORMS");
  const sjabloon = document.getElementById("leeg-zwemmer").innerHTML;
  // Velden na een verwijdering opnieuw nummeren zodat de lijst aaneensluitend blijft.
  function hernummer() {
    const blokken = lijst.querySelectorAll(".zwemmer-blok");
    blokken.forEach((b, i) => {
      b.querySelector(".nr").textContent = i + 1;
      b.querySelectorAll("[name], [id], [for]").forEach(el => {
        for (const a of ["name", "id", "for"]) if (el.hasAttribute(a)) el.setAttribute(a, el.getAttribute(a).replace(/zw-(\d+|__prefix__)-/, `zw-${i}-`));
      });
      b.querySelector(".weg").hidden = blokken.length === 1;
    });
    totaal.value = blokken.length;
  }
  document.getElementById("nog-zwemmer").addEventListener("click", () => {
    lijst.insertAdjacentHTML("beforeend", sjabloon);
    hernummer();
    const naam = document.getElementById("id_naam").value;
    const nieuw = lijst.lastElementChild;
    const nm = nieuw.querySelector("[name$='-naam']");
    if (nm && !nm.value) nm.value = naam;
    nieuw.querySelector("input[type=text]")?.focus();
  });
  lijst.addEventListener("click", e => { if (e.target.closest(".weg")) { e.target.closest(".zwemmer-blok").remove(); hernummer(); } });
  // "Ik zwem zelf": eigen voor- en achternaam in het eerste zwemmerblok, en mee laten veranderen.
  const zelf = document.getElementById("zelf");
  const eigen = () => [document.getElementById("id_voornaam").value, document.getElementById("id_naam").value];
  function vulZelf() {
    const b = lijst.querySelector(".zwemmer-blok");
    if (!b) return;
    const vn = b.querySelector("[name$='-voornaam']"), nm = b.querySelector("[name$='-naam']");
    let label = b.querySelector(".zelf-label");
    if (!label) { label = document.createElement("span"); label.className = "zelf-label klein"; b.querySelector("strong").append(label); }
    label.textContent = zelf.checked ? " (jijzelf)" : "";
    if (zelf.checked) { [vn.value, nm.value] = eigen(); }
  }
  zelf.addEventListener("change", () => {
    const b = lijst.querySelector(".zwemmer-blok");
    if (!zelf.checked && b) {
      const vn = b.querySelector("[name$='-voornaam']"), nm = b.querySelector("[name$='-naam']");
      if (vn.value === eigen()[0] && nm.value === eigen()[1]) { vn.value = ""; nm.value = ""; }
    }
    vulZelf();
    if (zelf.checked) lijst.querySelector(".zwemmer-blok [name$='-geboortedatum']")?.focus();
  });
  ["id_voornaam", "id_naam"].forEach(id => document.getElementById(id).addEventListener("input", () => { if (zelf.checked) vulZelf(); }));
  if (zelf.checked) vulZelf();
  // Met een koppelcode mag het zwemmerblok leeg blijven.
  const code = document.getElementById("id_koppelcode");
  const verplicht = () => lijst.querySelectorAll("input, select").forEach(el => {
    if (el.dataset.req === undefined) el.dataset.req = el.required ? "1" : "";
    el.required = !code.value.trim() && el.dataset.req === "1";
  });
  code.addEventListener("input", verplicht);
  document.getElementById("nog-zwemmer").addEventListener("click", () => setTimeout(verplicht));
  verplicht();
})();
</script>
