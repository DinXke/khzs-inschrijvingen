<?php
/** Beheer in wp-admin: instellingen (Instellingen → KHZS), modules, en de app-rol op het profiel van een gebruiker. */

namespace KHZS;

defined('ABSPATH') || exit;

const OPTIES = [
    'khzs_club_naam' => ['Clubnaam', 'Hasseltse Zwemvereniging Spartacus', 'Komt in de Lenex-export en op het overzicht.'],
    'khzs_club_code' => ['Clubcode', 'KHZS', 'Code van de club in de Lenex-export.'],
    'khzs_app_titel' => ['Titel in de app', 'KHZS Leden', 'Naast het logo bovenaan de app.'],
    'khzs_app_naam' => ['Naam van de app', 'KHZS Leden', 'Zoals de app heet op het beginscherm van de gsm.'],
    'khzs_app_kort' => ['Korte naam', 'KHZS Leden', 'Onder het icoon op de gsm en in de titel van het tabblad.'],
    'khzs_slug' => ['Adres van de app', 'leden', 'De app staat op jouwsite.be/<dit adres>/'],
    'khzs_webshop' => ['Webshop', 'https://www.robsport.nl/nl/796-hzs-hasselt', 'Link naar de webshop van de club: knop in de app. Leeg = geen knop.'],
];

/** Gegevens van de club in de privacyverklaring (AVG): sleutel => [label, voorbeeld, uitleg]. */
const PRIVACY_OPTIES = [
    'khzs_privacy_verantwoordelijke' => ['Verwerkingsverantwoordelijke', 'Hasseltse Zwemvereniging Spartacus vzw', 'De juridische naam van de club. Leeg = de clubnaam.'],
    'khzs_privacy_adres' => ['Adres van de club', 'Straat 1, 3500 Hasselt', 'Maatschappelijke zetel.'],
    'khzs_privacy_ondernemingsnummer' => ['Ondernemingsnummer', '0123.456.789', 'Optioneel.'],
    'khzs_privacy_email' => ['Contact voor privacyvragen', 'privacy@khzs.be', 'E-mailadres voor vragen en verzoeken over persoonsgegevens.'],
    'khzs_privacy_versie' => ['Versie van de privacyverklaring', PRIVACY_VERSIE, 'Verander dit (bv. naar de datum van vandaag) na een inhoudelijke wijziging: leden krijgen dan een melding om de nieuwe versie te lezen.'],
];

add_action('admin_menu', function () {
    add_options_page('KHZS Leden', 'KHZS Leden', 'manage_options', 'khzs', __NAMESPACE__ . '\\admin_pagina');
});

add_action('admin_init', function () {
    foreach (OPTIES as $k => $o) {
        register_setting('khzs', $k, ['type' => 'string', 'default' => $o[1], 'sanitize_callback' => $k === 'khzs_slug'
            ? fn($v) => sanitize_title($v) ?: 'leden' : ($k === 'khzs_webshop' ? 'esc_url_raw' : 'sanitize_text_field')]);
    }
    foreach (['khzs_registratie' => 1, 'khzs_pwa_melding' => 1, 'khzs_pwa_enkel_gsm' => 1, 'khzs_pwa_enkel_home' => 0] as $k => $std) {
        register_setting('khzs', $k, ['type' => 'integer', 'default' => $std, 'sanitize_callback' => fn($v) => $v ? 1 : 0]);
    }
    register_setting('khzs', 'khzs_modules', ['type' => 'array', 'default' => array_keys(modules()),
        'sanitize_callback' => fn($v) => array_values(array_intersect(array_keys(modules()), (array) $v))]);
    register_setting('khzs', 'khzs_pwa_tekst', ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field']);
    foreach (PRIVACY_OPTIES as $k => $o) {
        register_setting('khzs', $k, ['type' => 'string', 'default' => '', 'sanitize_callback' => $k === 'khzs_privacy_email' ? 'sanitize_email' : 'sanitize_text_field']);
    }
    register_setting('khzs', 'khzs_privacy_extra', ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field']);
});

// Nieuw adres → rewrite-regels opnieuw opbouwen.
add_action('update_option_khzs_slug', function () {
    registreer_routes();
    flush_rewrite_rules();
});

add_filter('plugin_action_links_khzs-leden/khzs-leden.php', function ($links) {
    array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=khzs')) . '">Instellingen</a>',
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
        foreach (rollen_van($u) as $r) {
            $tellers[$r]++;
        }
    }
    ?>
    <div class="wrap">
      <h1>KHZS Leden</h1>
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
          <tr><th scope="row">Modules</th>
            <td><input type="hidden" name="khzs_modules[]" value="">
            <?php foreach (modules() as $id => [$naam, $uitleg]): ?>
              <label style="display:block;margin-bottom:.4em"><input type="checkbox" name="khzs_modules[]" value="<?= esc_attr($id) ?>" <?php checked(module_actief($id)); ?>> <b><?= esc_html($naam) ?></b> — <?= esc_html($uitleg) ?></label>
            <?php endforeach; ?>
            <p class="description">Een uitgeschakelde module verdwijnt uit de app; haar gegevens blijven bewaard.</p></td></tr>
          <tr><th scope="row">Registreren</th>
            <td><label><input type="checkbox" name="khzs_registratie" value="1" <?php checked(get_option('khzs_registratie', 1)); ?>> Ouders en zwemmers mogen zelf een account aanmaken in de app</label>
            <p class="description">Nieuwe accounts krijgen de standaardrol van WordPress (<code><?= esc_html(get_option('default_role')) ?></code>) en de app-rollen die ze aanduiden: zwemmer en ouder meteen, de andere na goedkeuring (anders Zwemmer).</p></td></tr>
          <tr><th scope="row">App installeren</th>
            <td><label><input type="checkbox" name="khzs_pwa_melding" value="1" <?php checked(get_option('khzs_pwa_melding', 1)); ?>> Toon bezoekers een melding om de app op hun beginscherm te zetten</label><br>
            <label><input type="checkbox" name="khzs_pwa_enkel_gsm" value="1" <?php checked(get_option('khzs_pwa_enkel_gsm', 1)); ?>> Enkel op gsm en tablet</label><br>
            <label><input type="checkbox" name="khzs_pwa_enkel_home" value="1" <?php checked(get_option('khzs_pwa_enkel_home', 0)); ?>> Op de website enkel op de startpagina (in de app altijd)</label>
            <p><input name="khzs_pwa_tekst" class="large-text" value="<?= esc_attr(get_option('khzs_pwa_tekst', '')) ?>" placeholder="Schrijf je kinderen in voor wedstrijden en krijg meldingen, rechtstreeks vanaf je beginscherm."></p>
            <p class="description">Wie op ‘Niet nu’ tikt, ziet de melding 30 dagen niet meer. Vaste link om te delen (bv. in een nieuwsbrief): <code><?= esc_html(app_url('installeer/')) ?></code></p></td></tr>
          <tr><th scope="row" colspan="2"><h2 style="margin:0">Privacyverklaring (AVG)</h2>
            <p class="description" style="font-weight:normal">De app toont een privacyverklaring op <a href="<?= esc_url(app_url('privacy/')) ?>" target="_blank"><code><?= esc_html(app_url('privacy/')) ?></code></a>. Het is een sjabloon: laat de tekst nakijken door iemand met juridische kennis. Vul hieronder de gegevens van de club in.</p></th></tr>
          <?php foreach (PRIVACY_OPTIES as $k => [$label, $voorbeeld, $help]): ?>
          <tr><th scope="row"><label for="<?= esc_attr($k) ?>"><?= esc_html($label) ?></label></th>
            <td><input name="<?= esc_attr($k) ?>" id="<?= esc_attr($k) ?>" class="regular-text" value="<?= esc_attr(get_option($k, '')) ?>" placeholder="<?= esc_attr($voorbeeld) ?>">
            <p class="description"><?= esc_html($help) ?></p></td></tr>
          <?php endforeach; ?>
          <tr><th scope="row"><label for="khzs_privacy_extra">Aanvullende tekst</label></th>
            <td><textarea name="khzs_privacy_extra" id="khzs_privacy_extra" class="large-text" rows="4"><?= esc_textarea(get_option('khzs_privacy_extra', '')) ?></textarea>
            <p class="description">Komt onderaan de privacyverklaring, bv. de verzekeraar of de federatie, of een aanspreekpunt in de club.</p></td></tr>
        </table>
        <?php submit_button(); ?>
      </form>
      <h2>Rollen in de app</h2>
      <p>Rollen stel je in via <a href="<?= esc_url(app_url('gebruikers/')) ?>">Gebruikers in de app</a> of op het profiel van een gebruiker in WordPress.
        Een account kan meerdere rollen hebben; leden vragen rollen aan via <em>Mijn rollen</em>. WordPress-beheerders zijn altijd beheerder in de app.</p>
      <ul><?php foreach (ROLLEN as $r => $l): ?><li><?= esc_html($l) ?>: <?= (int) $tellers[$r] ?></li><?php endforeach; ?></ul>
      <h2>Geplande taken</h2>
      <ul>
        <li>Deadline-herinneringen: <?= esc_html(($t = wp_next_scheduled('khzs_herinneringen')) ? wp_date('j/m/Y H:i', $t) : 'niet gepland') ?></li>
        <li>Nachtelijke backup: <?= esc_html(($t = wp_next_scheduled('khzs_backup')) ? wp_date('j/m/Y H:i', $t) : 'niet gepland') ?></li>
        <li>Opkuis volgens de bewaartermijnen (AVG): <?= esc_html(($t = wp_next_scheduled('khzs_opkuis')) ? wp_date('j/m/Y H:i', $t) : 'niet gepland') ?></li>
      </ul>
      <p class="description">Deze taken gebruiken WP-Cron. Op een site met weinig bezoekers stel je best een echte cron in die <code>wp-cron.php</code> elke 5–15 minuten oproept.</p>
      <?php do_action('khzs_admin_onder'); ?>
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
    <h2>KHZS Leden</h2>
    <input type="hidden" name="khzs_rol_veld" value="1">
    <table class="form-table" role="presentation"><tr><th>Rollen in de app</th><td>
      <?php $eigen = bewaarde_rollen($u->ID); foreach (ROLLEN as $r => $l): ?>
        <label style="display:block"><input type="checkbox" name="khzs_rollen[]" value="<?= esc_attr($r) ?>" <?php checked(in_array($r, $eigen, true) || ($r === BEHEERDER && $wpbeheer)); ?> <?php disabled($r === BEHEERDER && $wpbeheer); ?>> <?= esc_html($l) ?></label>
      <?php endforeach; ?>
      <?php if ($wpbeheer): ?><p class="description">WordPress-beheerders zijn altijd beheerder in de app.</p><?php endif; ?>
    </td></tr>
    <?php if (current_user_can('manage_options')): ?>
    <tr><th>Penningmeester</th><td><label><input type="checkbox" name="khzs_penningmeester" value="1" <?php checked(get_user_meta($u->ID, 'khzs_penningmeester', true), '1'); ?>> Ziet tarieven en verloning van de trainers</label></td></tr>
    <?php endif; ?>
    </table>
    <?php
}

add_action('show_user_profile', __NAMESPACE__ . '\\profiel_veld');
add_action('edit_user_profile', __NAMESPACE__ . '\\profiel_veld');

function profiel_bewaar(int $uid): void
{
    if (current_user_can('manage_options') && isset($_POST['khzs_rol_veld'])) {
        update_user_meta($uid, 'khzs_penningmeester', empty($_POST['khzs_penningmeester']) ? '0' : '1');
    }
    if (!current_user_can('promote_users') || !isset($_POST['khzs_rol_veld'])) {
        return;
    }
    $rollen = array_intersect(array_keys(ROLLEN), array_map('sanitize_key', (array) wp_unslash($_POST['khzs_rollen'] ?? [])));
    zet_rollen($uid, $rollen ?: [ZWEMMER]);
}

add_action('personal_options_update', __NAMESPACE__ . '\\profiel_bewaar');
add_action('edit_user_profile_update', __NAMESPACE__ . '\\profiel_bewaar');

// Kolom "App-rollen" in de gebruikerslijst.
add_filter('manage_users_columns', fn($c) => $c + ['khzs_rol' => 'App-rollen']);
add_filter('manage_users_custom_column', function ($v, $kolom, $uid) {
    return $kolom === 'khzs_rol' ? esc_html(implode(', ', array_map(fn($r) => ROLLEN[$r], rollen_van((int) $uid)))) : $v;
}, 10, 3);
