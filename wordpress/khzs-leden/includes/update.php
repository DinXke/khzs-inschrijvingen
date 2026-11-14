<?php
/**
 * Updates vanaf GitHub: WordPress toont een nieuwe versie van de plugin zoals bij plugins uit de WordPress-bibliotheek,
 * en kan ze automatisch installeren (Plugins → *Automatische updates inschakelen*).
 *
 * Een versie is een GitHub-release met een tag `plugin-v<versie>` en de bijlage `khzs-leden.zip`
 * (zie .github/workflows/plugin-release.yml). Andere releases van de repo worden genegeerd.
 */

namespace KHZS;

defined('ABSPATH') || exit;

const UPDATE_REPO = 'DinXke/khzs-inschrijvingen'; // GitHub verwijst door na het hernoemen naar khzs-leden
const UPDATE_TAG = 'plugin-v';
const UPDATE_ZIP = 'khzs-leden.zip';

// De demo volgt zelf een git-tak (wordpress/demo/); een update via WordPress zou die checkout overschrijven.
if (demo_aan()) {
    return;
}

function plugin_bestand(): string
{
    return plugin_basename(KHZS_PAD . 'khzs-leden.php');
}

/** De nieuwste plugin-release op GitHub, of null. Wordt 6 uur bewaard; $vers = true vraagt opnieuw op. */
function laatste_release(bool $vers = false): ?array
{
    $cache = get_site_transient('khzs_release');
    if (!$vers && is_array($cache)) {
        return $cache['versie'] ? $cache : null;
    }
    $leeg = ['versie' => ''];
    $antw = wp_remote_get('https://api.github.com/repos/' . UPDATE_REPO . '/releases?per_page=20', [
        'timeout' => 10,
        'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'KHZS-Leden/' . VERSIE],
    ]);
    if (is_wp_error($antw) || wp_remote_retrieve_response_code($antw) !== 200) {
        set_site_transient('khzs_release', $leeg, HOUR_IN_SECONDS); // GitHub onbereikbaar: binnen een uur opnieuw
        return null;
    }
    $release = null;
    foreach ((array) json_decode(wp_remote_retrieve_body($antw), true) as $r) {
        if (!empty($r['draft']) || !empty($r['prerelease']) || !str_starts_with($r['tag_name'] ?? '', UPDATE_TAG)) {
            continue;
        }
        $zip = array_values(array_filter($r['assets'] ?? [], fn($a) => ($a['name'] ?? '') === UPDATE_ZIP));
        if (!$zip) {
            continue;
        }
        $versie = substr($r['tag_name'], strlen(UPDATE_TAG));
        if (!$release || version_compare($versie, $release['versie'], '>')) {
            $release = ['versie' => $versie, 'zip' => $zip[0]['browser_download_url'], 'url' => $r['html_url'],
                'notities' => (string) ($r['body'] ?? ''), 'datum' => (string) ($r['published_at'] ?? '')];
        }
    }
    set_site_transient('khzs_release', $release ?? $leeg, 6 * HOUR_IN_SECONDS);
    return $release;
}

// Nieuwe versie melden aan WordPress (Dashboard → Updates, Plugins, en de automatische updates).
add_filter('pre_set_site_transient_update_plugins', function ($transient) {
    if (!is_object($transient)) {
        return $transient;
    }
    $r = laatste_release();
    $info = (object) ['id' => UPDATE_REPO, 'slug' => 'khzs-leden', 'plugin' => plugin_bestand(),
        'new_version' => $r['versie'] ?? VERSIE, 'url' => $r['url'] ?? 'https://github.com/' . UPDATE_REPO,
        'package' => $r['zip'] ?? '', 'requires_php' => '8.0'];
    if ($r && version_compare($r['versie'], VERSIE, '>')) {
        $transient->response[plugin_bestand()] = $info;
    } else {
        // Ook "geen update" melden: dan toont WordPress de knop voor automatische updates.
        unset($transient->response[plugin_bestand()]);
        $transient->no_update[plugin_bestand()] = $info;
    }
    return $transient;
});

// Venster "Details bekijken" bij de update.
add_filter('plugins_api', function ($res, $actie, $args) {
    if ($actie !== 'plugin_information' || ($args->slug ?? '') !== 'khzs-leden') {
        return $res;
    }
    $r = laatste_release();
    return (object) [
        'name' => 'KHZS Leden', 'slug' => 'khzs-leden', 'version' => $r['versie'] ?? VERSIE,
        'author' => 'Hasseltse Zwemvereniging Spartacus', 'homepage' => 'https://github.com/' . UPDATE_REPO,
        'requires' => '6.2', 'requires_php' => '8.0', 'last_updated' => $r['datum'] ?? '',
        'download_link' => $r['zip'] ?? '',
        'sections' => ['changelog' => $r ? wpautop(esc_html($r['notities'] ?: 'Geen notities.')) : 'Geen release gevonden op GitHub.'],
    ];
}, 10, 3);

// Na een update de bewaarde release vergeten, zodat de volgende controle vers is.
add_action('upgrader_process_complete', function ($upgrader, $opties) {
    if (($opties['type'] ?? '') === 'plugin' && in_array(plugin_bestand(), (array) ($opties['plugins'] ?? []), true)) {
        delete_site_transient('khzs_release');
    }
}, 10, 2);

// Link "Nu op updates controleren" in de pluginlijst.
add_filter('plugin_row_meta', function ($links, $bestand) {
    if ($bestand === plugin_bestand() && current_user_can('update_plugins')) {
        $links[] = '<a href="' . esc_url(wp_nonce_url(admin_url('plugins.php?khzs_controleer=1'), 'khzs_controleer')) . '">Op updates controleren</a>';
    }
    return $links;
}, 10, 2);

add_action('admin_init', function () {
    if (empty($_GET['khzs_controleer']) || !current_user_can('update_plugins')) {
        return;
    }
    check_admin_referer('khzs_controleer');
    laatste_release(true);
    delete_site_transient('update_plugins');
    wp_safe_redirect(admin_url('plugins.php'));
    exit;
});
