<?php namespace KHZS; defined('ABSPATH') || exit; ?>
<h1>Aanmelden</h1>
<p class="klein" style="margin-top:-.6rem">Wedstrijdinschrijvingen van de <?= e(club()['naam']) ?></p>
<form method="post" class="kaart" action="<?= esc_url(app_url('aanmelden/')) ?>"><?= nonce_veld() ?>
  <?php if ($fout): ?><div class="melding error">E-mail of wachtwoord klopt niet.</div><?php endif; ?>
  <label for="id_username">E-mail of gebruikersnaam</label>
  <input type="text" name="username" id="id_username" autocomplete="username" autocapitalize="none" inputmode="email" required autofocus value="<?= esc_attr($gebruiker) ?>">
  <label for="id_password">Wachtwoord</label>
  <input type="password" name="password" id="id_password" autocomplete="current-password" required>
  <input type="hidden" name="next" value="<?= esc_attr($next) ?>">
  <p><button class="vol">Aanmelden</button></p>
  <p class="klein"><a href="<?= esc_url(wp_lostpassword_url(app_url('aanmelden/'))) ?>">Wachtwoord vergeten?</a></p>
</form>
<?php if (registratie_open()): ?><p class="leeg">Nog geen account? <a href="<?= esc_url(app_url('registreren/')) ?>">Maak een profiel aan</a></p><?php endif; ?>
<p class="leeg"><a href="<?= esc_url(app_url('installeer/')) ?>">📲 Installeer als app</a></p>
