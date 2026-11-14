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
  </div>
  <h2>2. Zwemmer(s)</h2>
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
})();
</script>
