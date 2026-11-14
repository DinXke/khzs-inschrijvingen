<?php
/**
 * Backup en terugzetten van alle gegevens van de plugin: tabellen, rollen/voorkeuren (user meta) en de opgeladen bestanden.
 * De WordPress-gebruikers zelf horen bij WordPress en zitten niet in deze backup; bij het terugzetten worden accounts
 * gekoppeld via de gebruikersnaam.
 */

namespace KHZS;

defined('ABSPATH') || exit;

function backup_map(): string
{
    return prive_map('backups');
}

function backup_lijst(): array
{
    $l = glob(backup_map() . '/hzs-backup-*.zip') ?: [];
    usort($l, fn($a, $b) => filemtime($b) <=> filemtime($a) ?: strcmp($b, $a));
    return $l;
}

/** Zoekt een backup op naam; enkel bestanden uit de backupmap (geen ../). */
function backup_zoek(string $naam): string
{
    if (!preg_match('/^hzs-backup-[A-Za-z0-9_-]+\.zip$/', $naam) || !is_file(backup_map() . "/$naam")) {
        throw new \RuntimeException('Backup niet gevonden.');
    }
    return backup_map() . "/$naam";
}

function backup_maak(string $achtervoegsel = ''): string
{
    $data = ['versie' => VERSIE, 'gemaakt' => nu_sql(), 'tabellen' => [], 'meta' => []];
    foreach (alle_tabellen() as $tab) {
        $data['tabellen'][$tab] = db()->get_results("SELECT * FROM " . t($tab), ARRAY_A);
    }
    foreach (db()->get_results("SELECT m.user_id, u.user_login, m.meta_key, m.meta_value FROM " . db()->usermeta . " m
        JOIN " . db()->users . " u ON u.ID=m.user_id WHERE m.meta_key LIKE 'khzs\\_%' AND m.meta_key NOT IN ('khzs_django_hash')", ARRAY_A) as $m) {
        $data['meta'][] = $m;
    }
    $naam = 'hzs-backup-' . current_datetime()->format('Ymd-His') . ($achtervoegsel ? "-$achtervoegsel" : '') . '.zip';
    $pad = backup_map() . "/$naam";
    $zip = new \ZipArchive();
    $zip->open($pad, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $zip->addFromString('data.json', wp_json_encode($data));
    $basis = prive_map();
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($basis, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($basis) + 1);
        if ($f->isFile() && !str_starts_with($rel, 'backups/') && !in_array($rel, ['.htaccess', 'index.php'], true)) {
            $zip->addFile($f->getPathname(), "bestanden/$rel");
        }
    }
    $zip->close();
    return $pad;
}

/** Zet een backup terug. Maakt eerst een veiligheidsbackup. Geeft [aantal rijen, pad veiligheidsbackup]. */
function backup_herstel(string $bron): array
{
    $zip = new \ZipArchive();
    if ($zip->open($bron) !== true || ($json = $zip->getFromName('data.json')) === false) {
        throw new \RuntimeException('Geen geldige backup (data.json ontbreekt).');
    }
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['tabellen'])) {
        throw new \RuntimeException('Geen geldige backup.');
    }
    $veilig = backup_maak('voor-herstel');

    // Accounts koppelen via gebruikersnaam (ID's kunnen verschillen op een andere site).
    $user_map = [];
    foreach ($data['meta'] ?? [] as $m) {
        $u = get_user_by('login', $m['user_login']);
        $user_map[(int) $m['user_id']] = $u ? $u->ID : (int) $m['user_id'];
    }
    // Export uit de Django-versie: ontbrekende accounts aanmaken; het oude wachtwoord blijft werken.
    foreach ($data['gebruikers'] ?? [] as $g) {
        $u = get_user_by('login', $g['login']) ?: (is_email($g['email']) ? get_user_by('email', $g['email']) : false);
        if (!$u) {
            $id = wp_insert_user(['user_login' => $g['login'], 'user_email' => is_email($g['email']) ? $g['email'] : '',
                'user_pass' => wp_generate_password(24), 'first_name' => $g['first_name'], 'last_name' => $g['last_name'],
                'display_name' => trim($g['first_name'] . ' ' . $g['last_name']) ?: $g['login'], 'role' => get_option('default_role', 'subscriber')]);
            if (is_wp_error($id)) {
                continue;
            }
            if (str_starts_with((string) $g['wachtwoord'], 'pbkdf2_sha256$')) {
                update_user_meta($id, 'khzs_django_hash', $g['wachtwoord']);
            }
            $u = get_userdata($id);
        }
        $user_map[(int) $g['id']] = $u->ID;
    }
    $n = 0;
    db()->query('START TRANSACTION');
    foreach (alle_tabellen() as $tab) {
        db()->query("DELETE FROM " . t($tab));
        foreach ($data['tabellen'][$tab] ?? [] as $rij) {
            foreach (['account_id', 'user_id', 'door_id', 'gefinaliseerd_door', 'behandeld_door', 'aangevraagd_door'] as $k) {
                if (isset($rij[$k]) && $rij[$k] !== '') {
                    $rij[$k] = $user_map[(int) $rij[$k]] ?? $rij[$k];
                }
            }
            db()->insert(t($tab), $rij);
            $n++;
        }
    }
    db()->query("DELETE FROM " . db()->usermeta . " WHERE meta_key LIKE 'khzs\\_%' AND meta_key <> 'khzs_django_hash'");
    foreach ($data['meta'] ?? [] as $m) {
        update_user_meta($user_map[(int) $m['user_id']], $m['meta_key'], $m['meta_value']);
        $n++;
    }
    db()->query('COMMIT');

    $basis = prive_map();
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $naam = $zip->getNameIndex($i);
        if (!str_starts_with($naam, 'bestanden/') || str_ends_with($naam, '/') || str_contains($naam, '..')) {
            continue;
        }
        $doel = $basis . '/' . substr($naam, strlen('bestanden/'));
        wp_mkdir_p(dirname($doel));
        file_put_contents($doel, $zip->getFromIndex($i));
    }
    $zip->close();
    return [$n, $veilig];
}

/** Nachtelijke backup (WP-Cron); backups ouder dan 30 dagen worden opgeruimd. */
function nachtelijke_backup(): void
{
    backup_maak();
    foreach (backup_lijst() as $f) {
        if (filemtime($f) < time() - 30 * DAY_IN_SECONDS) {
            @unlink($f);
        }
    }
}

/**
 * Aanmelden met een wachtwoord uit de Django-versie (pbkdf2_sha256). Bij succes wordt het omgezet naar een
 * gewoon WordPress-wachtwoord en verdwijnt de oude hash.
 */
add_filter('authenticate', function ($user, $login, $wachtwoord) {
    if ($user instanceof \WP_User || !$login || !$wachtwoord) {
        return $user;
    }
    $u = get_user_by('login', $login) ?: get_user_by('email', $login);
    $hash = $u ? get_user_meta($u->ID, 'khzs_django_hash', true) : '';
    if (!$hash) {
        return $user;
    }
    $delen = explode('$', $hash);
    if (count($delen) !== 4 || $delen[0] !== 'pbkdf2_sha256') {
        return $user;
    }
    [, $iter, $zout, $verwacht] = $delen;
    $bereken = base64_encode(hash_pbkdf2('sha256', $wachtwoord, $zout, (int) $iter, 32, true));
    if (!hash_equals($verwacht, $bereken)) {
        return $user;
    }
    wp_set_password($wachtwoord, $u->ID);
    delete_user_meta($u->ID, 'khzs_django_hash');
    return get_userdata($u->ID);
}, 30, 3);
