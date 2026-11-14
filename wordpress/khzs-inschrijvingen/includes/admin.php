<?php
/** Beheer in wp-admin: instellingen (Instellingen → KHZS inschrijvingen) en de app-rol op het profiel van een gebruiker. */

namespace KHZS;

defined('ABSPATH') || exit;

const OPTIES = [
    'khzs_club_naam' => ['Clubnaam', 'Hasseltse Zwemvereniging Spartacus', 'Komt in de Lenex-export en op het overzicht.'],
    'khzs_club_code' => ['Clubcode', 'KHZS', 'Code van de club in de Lenex-export.'],
    'khzs_app_naam' => ['Naam van de app', 'HZS inschrijvingen', 'Zoals de app heet op het beginscherm van de gsm.'],
    'khzs_app_kort' => ['Korte naam', 'HZS', 'Onder het icoon op de gsm en in de titel van het tabblad.'],
    'khzs_slug' => ['Adres van de app', 'inschrijvingen', 'De app staat op jouwsite.be/<dit adres>/'],
];

add_action('admin_menu', function () {
    add_options_page('KHZS inschrijvingen', 'KHZS inschrijvingen', 'manage_options', 'khzs-inschrijvingen', __NAMESPACE__ . '\\admin_pagina');
});

add_action('admin_init', function () {
    foreach (OPTIES as $k => $o) {
        register_setting('khzs', $k, ['type' => 'string', 'default' => $o[1], 'sanitize_callback' => $k === 'khzs_slug'
            ? fn($v) => sanitize_title($v) ?: 'inschrijvingen' : 'sanitize_text_field']);
    }
    foreach (['khzs_registratie' => 1, 'khzs_pwa_melding' => 1, 'khzs_pwa_enkel_gsm' => 1, 'khzs_pwa_enkel_home' => 0] as $k => $std) {
        register_setting('khzs', $k, ['type' => 'integer', 'default' => $std, 'sanitize_callback' => fn($v) => $v ? 1 : 0]);
    }
    register_setting('khzs', 'khzs_pwa_tekst', ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field']);
});

// Nieuw adres → rewrite-regels opnieuw opbouwen.
add_action('update_option_khzs_slug', function () {
    registreer_routes();
    flush_rewrite_rules();
});

add_filter('plugin_action_links_khzs-inschrijvingen/khzs-inschrijvingen.php', function ($links) {
    array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=khzs-inschrijvingen')) . '">Instellingen</a>',
        '<a href="' . esc_url(app_url('')) . '">Open de app</a>');
    return $links;
});

function admin_pagina(): void
{
    $tellers = [];
    foreach (ROLLEN as $r => $l) {
        $tellers[$r] = 0;
    }
    foreach (get_users(['fields' => 'all']) as $u) {
        $tellers[rol($u)]++;
    }
    ?>
    <div class="wrap">
      <h1>KHZS inschrijvingen</h1>
      <p><a class="button button-primary" href="<?= esc_url(app_url('')) ?>" target="_blank">Open de app ↗</a>
        <span style="margin-left:1em">Adres: <code><?= esc_html(app_url('')) ?></code></span></p>
      <?php if (!get_option('permalink_structure')): ?>
        <div class="notice notice-warning inline"><p>Tip: zet <a href="<?= esc_url(admin_url('options-permalink.php')) ?>">permalinks</a> op iets anders dan ‘Eenvoudig’ voor een mooi adres (<code>/<?= esc_html(slug()) ?>/</code>).</p></div>
      <?php endif; ?>
      <form method="post" action="options.php">
        <?php settings_fields('khzs'); ?>
        <table class="form-table" role="presentation">
          <?php foreach (OPTIES as $k => [$label, $standaard, $help]): ?>
          <tr><th scope="row"><label for="<?= esc_attr($k) ?>"><?= esc_html($label) ?></label></th>
            <td><input name="<?= esc_attr($k) ?>" id="<?= esc_attr($k) ?>" class="regular-text" value="<?= esc_attr(get_option($k, $standaard)) ?>">
            <p class="description"><?= esc_html($help) ?></p></td></tr>
          <?php endforeach; ?>
          <tr><th scope="row">Registreren</th>
            <td><label><input type="checkbox" name="khzs_registratie" value="1" <?php checked(get_option('khzs_registratie', 1)); ?>> Ouders en zwemmers mogen zelf een account aanmaken in de app</label>
            <p class="description">Nieuwe accounts krijgen de standaardrol van WordPress (<code><?= esc_html(get_option('default_role')) ?></code>) en de app-rol Zwemmer.</p></td></tr>
          <tr><th scope="row">App installeren</th>
            <td><label><input type="checkbox" name="khzs_pwa_melding" value="1" <?php checked(get_option('khzs_pwa_melding', 1)); ?>> Toon bezoekers een melding om de app op hun beginscherm te zetten</label><br>
            <label><input type="checkbox" name="khzs_pwa_enkel_gsm" value="1" <?php checked(get_option('khzs_pwa_enkel_gsm', 1)); ?>> Enkel op gsm en tablet</label><br>
            <label><input type="checkbox" name="khzs_pwa_enkel_home" value="1" <?php checked(get_option('khzs_pwa_enkel_home', 0)); ?>> Op de website enkel op de startpagina (in de app altijd)</label>
            <p><input name="khzs_pwa_tekst" class="large-text" value="<?= esc_attr(get_option('khzs_pwa_tekst', '')) ?>" placeholder="Schrijf je kinderen in voor wedstrijden en krijg meldingen, rechtstreeks vanaf je beginscherm."></p>
            <p class="description">Wie op ‘Niet nu’ tikt, ziet de melding 30 dagen niet meer. Vaste link om te delen (bv. in een nieuwsbrief): <code><?= esc_html(app_url('installeer/')) ?></code></p></td></tr>
        </table>
        <?php submit_button(); ?>
      </form>
      <h2>Rollen in de app</h2>
      <p>Rollen stel je in via <a href="<?= esc_url(app_url('gebruikers/')) ?>">Gebruikers in de app</a> of op het profiel van een gebruiker in WordPress.
        WordPress-beheerders zijn altijd beheerder in de app.</p>
      <ul><?php foreach (ROLLEN as $r => $l): ?><li><?= esc_html($l) ?>: <?= (int) $tellers[$r] ?></li><?php endforeach; ?></ul>
      <h2>Geplande taken</h2>
      <ul>
        <li>Deadline-herinneringen: <?= esc_html(($t = wp_next_scheduled('khzs_herinneringen')) ? wp_date('j/m/Y H:i', $t) : 'niet gepland') ?></li>
        <li>Nachtelijke backup: <?= esc_html(($t = wp_next_scheduled('khzs_backup')) ? wp_date('j/m/Y H:i', $t) : 'niet gepland') ?></li>
      </ul>
      <p class="description">Deze taken gebruiken WP-Cron. Op een site met weinig bezoekers stel je best een echte cron in die <code>wp-cron.php</code> elke 5–15 minuten oproept.</p>
    </div>
    <?php
}

// ---------- rol op het gebruikersprofiel ----------

function profiel_veld(\WP_User $u): void
{
    if (!current_user_can('promote_users')) {
        return;
    }
    $wpbeheer = user_can($u, 'manage_options');
    ?>
    <h2>KHZS inschrijvingen</h2>
    <table class="form-table" role="presentation"><tr><th><label for="khzs_rol">Rol in de app</label></th><td>
      <?php if ($wpbeheer): ?>Beheerder (WordPress-beheerders zijn altijd beheerder)<?php else: ?>
      <select name="khzs_rol" id="khzs_rol"><?php foreach (ROLLEN as $r => $l): ?>
        <option value="<?= esc_attr($r) ?>" <?php selected(rol($u), $r); ?>><?= esc_html($l) ?></option><?php endforeach; ?></select>
      <?php endif; ?>
    </td></tr></table>
    <?php
}

add_action('show_user_profile', __NAMESPACE__ . '\\profiel_veld');
add_action('edit_user_profile', __NAMESPACE__ . '\\profiel_veld');

function profiel_bewaar(int $uid): void
{
    if (!current_user_can('promote_users') || !isset($_POST['khzs_rol'])) {
        return;
    }
    $r = sanitize_key(wp_unslash($_POST['khzs_rol']));
    if (isset(ROLLEN[$r])) {
        update_user_meta($uid, 'khzs_rol', $r);
    }
}

add_action('personal_options_update', __NAMESPACE__ . '\\profiel_bewaar');
add_action('edit_user_profile_update', __NAMESPACE__ . '\\profiel_bewaar');

// Kolom "App-rol" in de gebruikerslijst.
add_filter('manage_users_columns', fn($c) => $c + ['khzs_rol' => 'App-rol']);
add_filter('manage_users_custom_column', function ($v, $kolom, $uid) {
    return $kolom === 'khzs_rol' ? esc_html(ROLLEN[rol((int) $uid)] ?? '') : $v;
}, 10, 3);
