<?php namespace KHZS; defined('ABSPATH') || exit; ?>
<h1>Profiel aanmaken</h1>
<form method="post" id="registratie" class="kaart" action="<?= esc_url(app_url('registreren/')) ?>"><?= nonce_veld() ?>
  <p class="klein">Eén account per gezin is genoeg. Na het aanmaken vragen we in een paar korte stappen of je zelf zwemt, of je kinderen hebt die zwemmen, en in welke ploeg.</p>
  <?= veld_tekst('email', 'E-mail', $form['email'], $fouten['email'] ?? null, ['type' => 'email', 'required' => true, 'autocomplete' => 'email']) ?>
  <?= veld_tekst('voornaam', 'Jouw voornaam', $form['voornaam'], $fouten['voornaam'] ?? null, ['maxlength' => 60, 'required' => true]) ?>
  <?= veld_tekst('naam', 'Jouw naam', $form['naam'], $fouten['naam'] ?? null, ['maxlength' => 80, 'required' => true]) ?>
  <?= veld_tekst('password1', 'Wachtwoord', '', $fouten['password1'] ?? null, ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password'], 'Minstens 8 tekens, niet enkel cijfers.') ?>
  <?= veld_tekst('password2', 'Wachtwoord bevestigen', '', $fouten['password2'] ?? null, ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password'], 'Geef hetzelfde wachtwoord nogmaals in, ter controle.') ?>
  <p><button class="vol">Profiel aanmaken</button></p>
</form>
<p class="leeg">Al een account? <a href="<?= esc_url(app_url('aanmelden/')) ?>">Aanmelden</a></p>
