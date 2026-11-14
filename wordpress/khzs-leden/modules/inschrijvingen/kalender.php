<?php
/**
 * Module inschrijvingen: wedstrijden synchroon houden met de evenementenkalender van de website (The Events Calendar).
 *
 * - Werkt enkel als The Events Calendar actief is en de synchronisatie aanstaat; nooit in de demo.
 * - Enkel publieke informatie: naam, sessies (datum en uren), zwembad als locatie, badlengte, organisator, eventueel de
 *   info voor zwemmers, publieke bijlagen en een link naar de app. Nooit inschrijvingen, zwemmers, interne deadlines of kosten.
 * - Koppeling: post-meta `_khzs_wedstrijd_id` op het evenement (één bron van waarheid, geen extra kolom).
 * - Handmatige wijzigingen op de website: na elke synchronisatie bewaren we een vingerafdruk van de velden die de app beheert
 *   (`_khzs_vingerafdruk`). Verschilt die bij de volgende synchronisatie, dan overschrijven we niets: het evenement krijgt
 *   `_khzs_conflict` en verschijnt bij *Websitekalender* in de app, met de keuze ‘overschrijven’ of ‘niet meer bijwerken’ (`_khzs_vast`).
 * - Niet meer publiek of verwijderd: een gepubliceerd evenement dat nog moet komen, wordt ‘Geannuleerd’ (status van
 *   The Events Calendar), zodat bezoekers en agenda-abonnementen dat zien. Een evenement dat nooit gepubliceerd was, gaat naar
 *   de prullenbak. Een evenement dat al voorbij is, blijft staan (historiek).
 */

namespace KHZS;

defined('ABSPATH') || exit;

const KALENDER_STATUSSEN = [W_OPEN, W_GESLOTEN, W_DOORGESTUURD];
const KALENDER_TYPE = 'tribe_events';

add_filter('khzs_routes', fn($r) => array_merge($r, [
    ['kalender', 'kalender', 'v_kalender'],
    ['bijlage/(\d+)/publiek', 'bijlage_publiek', 'v_bijlage_publiek'],
]));

add_action('khzs_wedstrijd_bewaard', __NAMESPACE__ . '\\kalender_sync');
add_action('khzs_wedstrijd_verwijderd', __NAMESPACE__ . '\\kalender_sync');
add_action('khzs_bijlage_verwijderd', function (int $bijlage_id) {
    update_option('khzs_kalender_bijlagen', array_values(array_diff(publieke_bijlagen(), [$bijlage_id])), false);
});
add_action('khzs_admin_onder', __NAMESPACE__ . '\\kalender_admin');
add_action('admin_post_khzs_kalender_alles', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('khzs_kalender_alles')) {
        wp_die('Geen toegang.');
    }
    $n = kalender_alles();
    wp_safe_redirect(add_query_arg(['page' => 'khzs', 'khzs_kalender' => array_sum($n)], admin_url('options-general.php')));
    exit;
});

// ---------- instellingen ----------

/** Is The Events Calendar actief? Zonder die plugin doet de synchronisatie niets. */
function tec_actief(): bool
{
    return function_exists('tribe_events') && function_exists('tribe_create_venue') && post_type_exists(KALENDER_TYPE);
}

function kalender_aan(): bool
{
    return tec_actief() && !is_demo() && !demo_enkel() && !str_contains(t('wedstrijden'), 'khzsdemo') && get_option('khzs_kalender_aan');
}

/** Welke wedstrijdstatussen op de website komen. */
function kalender_statussen(): array
{
    return array_values(array_intersect((array) get_option('khzs_kalender_statussen', KALENDER_STATUSSEN), array_keys(W_STATUS)));
}

/** Id's van bijlagen die publiek mogen (zonder aanmelden te downloaden en op de website). */
function publieke_bijlagen(): array
{
    return array_map('intval', (array) get_option('khzs_kalender_bijlagen', []));
}

/** Een bijlage is publiek als ze zo aangeduid is én de wedstrijd zelf publiek is. */
function bijlage_publiek(object $b): bool
{
    if (!in_array((int) $b->id, publieke_bijlagen(), true)) {
        return false;
    }
    $w = wedstrijd((int) $b->wedstrijd_id);
    return $w && in_array($w->status, kalender_statussen(), true);
}

// ---------- synchroniseren ----------

/** Het gekoppelde evenement (ook in de prullenbak), of null. */
function kalender_evenement(int $wid): ?\WP_Post
{
    $ids = get_posts(['post_type' => KALENDER_TYPE, 'post_status' => ['publish', 'future', 'draft', 'pending', 'private', 'trash'],
        'meta_key' => '_khzs_wedstrijd_id', 'meta_value' => (string) $wid, 'numberposts' => 1, 'fields' => 'ids',
        'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true, 'tribe_suppress_query_filters' => true]);
    return $ids ? get_post($ids[0]) : null;
}

/** Alle gekoppelde evenementen (id => wedstrijd-id). */
function kalender_koppelingen(): array
{
    $uit = [];
    foreach (get_posts(['post_type' => KALENDER_TYPE, 'post_status' => ['publish', 'future', 'draft', 'pending', 'private', 'trash'],
        'meta_key' => '_khzs_wedstrijd_id', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true,
        'tribe_suppress_query_filters' => true]) as $id) {
        $uit[$id] = (int) get_post_meta($id, '_khzs_wedstrijd_id', true);
    }
    return $uit;
}

/**
 * Brengt het evenement van één wedstrijd in orde. Geeft terug wat er gebeurde: aangemaakt, bijgewerkt, ongewijzigd,
 * geannuleerd, prullenbak, voorbij, conflict, vast, niets, uit of fout. Gooit nooit een fout: de app mag hier niet op stuklopen.
 */
function kalender_sync($wid, bool $forceer = false): string
{
    if (!kalender_aan()) {
        return 'uit';
    }
    $wid = (int) $wid;
    try {
        $ev = kalender_evenement($wid);
        if ($ev && $forceer) {
            delete_post_meta($ev->ID, '_khzs_vast');
            delete_post_meta($ev->ID, '_khzs_conflict');
        }
        if ($ev && get_post_meta($ev->ID, '_khzs_vast', true)) {
            return 'vast';
        }
        $w = wedstrijd($wid);
        $sessies = $w ? kalender_sessies($wid) : [];
        if (!$w || !$sessies || !in_array($w->status, kalender_statussen(), true)) {
            return kalender_weg($ev, !$w);
        }
        if ($ev && $ev->post_status === 'trash') {
            // Door iemand op de website weggegooid: niet terughalen. Door de app weggezet: terughalen.
            if (!get_post_meta($ev->ID, '_khzs_weg_door_app', true)) {
                return 'vast';
            }
            $vorige = get_post_meta($ev->ID, '_wp_trash_meta_status', true) ?: 'draft';
            wp_untrash_post($ev->ID);
            wp_update_post(['ID' => $ev->ID, 'post_status' => $vorige]);
            delete_post_meta($ev->ID, '_khzs_weg_door_app');
            $ev = get_post($ev->ID);
        }
        if ($ev && get_post_meta($ev->ID, '_khzs_geannuleerd', true)) {
            update_post_meta($ev->ID, '_tribe_events_status', 'scheduled');
            delete_post_meta($ev->ID, '_tribe_events_status_reason');
            delete_post_meta($ev->ID, '_khzs_geannuleerd');
        }

        $args = kalender_velden($w, $sessies);
        $bron = md5(wp_json_encode($args));
        if (!$ev) {
            $args['status'] = get_option('khzs_kalender_publiceren', 'publish') === 'draft' ? 'draft' : 'publish';
            $post = tribe_events()->set_args($args)->create();
            if (!$post instanceof \WP_Post) {
                throw new \RuntimeException('Het evenement kon niet aangemaakt worden.');
            }
            update_post_meta($post->ID, '_khzs_wedstrijd_id', (string) $wid);
            $id = $post->ID;
            $uitkomst = 'aangemaakt';
        } else {
            $id = $ev->ID;
            if (!$forceer && kalender_vingerafdruk($id) !== get_post_meta($id, '_khzs_vingerafdruk', true)) {
                update_post_meta($id, '_khzs_conflict', current_time('mysql'));
                return 'conflict';
            }
            if (!$forceer && $bron === get_post_meta($id, '_khzs_bron', true)) {
                return 'ongewijzigd';
            }
            tribe_events()->where('id', $id)->set_args($args)->save();
            delete_post_meta($id, '_khzs_conflict');
            $uitkomst = 'bijgewerkt';
        }
        kalender_categorie($id);
        clean_post_cache($id);
        update_post_meta($id, '_khzs_bron', $bron);
        update_post_meta($id, '_khzs_vingerafdruk', kalender_vingerafdruk($id));
        update_post_meta($id, '_khzs_gesynchroniseerd', current_time('mysql'));
        return $uitkomst;
    } catch (\Throwable $e) {
        update_option('khzs_kalender_fout', current_time('mysql') . " · wedstrijd $wid · " . $e->getMessage(), false);
        error_log("KHZS kalender: wedstrijd $wid: " . $e->getMessage());
        return 'fout';
    }
}

/** De wedstrijd is niet (meer) publiek of verwijderd. Zie de uitleg bovenaan dit bestand. */
function kalender_weg(?\WP_Post $ev, bool $verwijderd): string
{
    if (!$ev || $ev->post_status === 'trash') {
        return 'niets';
    }
    $ontkoppel = function () use ($ev, $verwijderd) {
        if ($verwijderd) {
            update_post_meta($ev->ID, '_khzs_was_wedstrijd_id', get_post_meta($ev->ID, '_khzs_wedstrijd_id', true));
            delete_post_meta($ev->ID, '_khzs_wedstrijd_id');
        }
    };
    if (in_array($ev->post_status, ['publish', 'future', 'private'], true)) {
        $einde = substr((string) get_post_meta($ev->ID, '_EventEndDate', true), 0, 10);
        if ($einde !== '' && $einde < vandaag()->format('Y-m-d')) {
            $ontkoppel();
            return 'voorbij';
        }
        if (get_post_meta($ev->ID, '_tribe_events_status', true) !== 'canceled') {
            update_post_meta($ev->ID, '_tribe_events_status', 'canceled');
            update_post_meta($ev->ID, '_khzs_geannuleerd', '1');
        }
        $ontkoppel();
        return 'geannuleerd';
    }
    update_post_meta($ev->ID, '_khzs_weg_door_app', '1');
    wp_trash_post($ev->ID);
    $ontkoppel();
    return 'prullenbak';
}

/** Alle wedstrijden en gekoppelde evenementen nakijken. Geeft het aantal per uitkomst. */
function kalender_alles(): array
{
    $tel = [];
    if (!kalender_aan()) {
        return $tel;
    }
    $wids = array_map('intval', db()->get_col("SELECT id FROM " . t('wedstrijden')));
    // Ook evenementen van wedstrijden die niet meer bestaan (bv. na een teruggezette backup).
    $wids = array_unique(array_merge($wids, array_values(kalender_koppelingen())));
    foreach ($wids as $wid) {
        $u = kalender_sync($wid);
        $tel[$u] = ($tel[$u] ?? 0) + 1;
    }
    update_option('khzs_kalender_laatst', current_time('mysql'), false);
    return $tel;
}

function kalender_sessies(int $wid): array
{
    return db()->get_results(db()->prepare("SELECT * FROM " . t('sessies') . " WHERE wedstrijd_id=%d ORDER BY datum, starttijd, nummer", $wid));
}

/** Publieke gegevens uit het bewaarde Lenex-bestand die niet in de database staan: organisator, adres, einduren. */
function kalender_lenex(object $w): array
{
    $uit = ['organisator' => '', 'organisator_url' => '', 'adres' => '', 'postcode' => '', 'land' => '', 'einde' => []];
    $pad = $w->lenex_bestand ? prive_map() . '/' . $w->lenex_bestand : '';
    if (!$pad || !is_file($pad)) {
        return $uit;
    }
    try {
        $meet = _kies_meet(lees_xml(file_get_contents($pad)));
    } catch (\Throwable $e) {
        return $uit;
    }
    $f = _el($meet, 'FACILITY');
    $url = (string) _a($meet, 'organizer.url');
    $uit['organisator'] = trim((string) _a($meet, 'organizer'));
    $uit['organisator_url'] = preg_match('#^https?://#i', $url) ? $url : '';
    $uit['adres'] = trim(implode(' ', array_filter([_a($f, 'street'), _a($f, 'street2')])));
    $uit['postcode'] = (string) _a($f, 'zip');
    $uit['land'] = ['BEL' => 'Belgium', 'NED' => 'Netherlands', 'FRA' => 'France', 'GER' => 'Germany', 'LUX' => 'Luxembourg'][strtoupper((string) _a($f, 'nation'))] ?? '';
    foreach (_els($meet, 'SESSIONS/SESSION') as $s) {
        if ($t = _tijd(_a($s, 'endtime'))) {
            $uit['einde'][(int) _a($s, 'number')] = $t;
        }
    }
    return $uit;
}

/** Argumenten voor tribe_events(): enkel publieke informatie. */
function kalender_velden(object $w, array $sessies): array
{
    $lx = kalender_lenex($w);
    $eerste = $sessies[0];
    $laatste = end($sessies);
    $args = ['title' => $w->naam, 'content' => kalender_tekst($w, $sessies, $lx), 'timezone' => wp_timezone_string(),
        'url' => app_url("wedstrijd/{$w->id}/")];
    if ($eerste->starttijd) {
        // Einde: het einduur uit Lenex, anders een schatting (start van de laatste sessie + 3 uur, ten laatste 23:59).
        $einde = $lx['einde'][(int) $laatste->nummer] ?? null;
        if (!$einde) {
            $start = max(array_map(fn($s) => (string) $s->starttijd, array_filter($sessies, fn($s) => $s->datum === $laatste->datum)));
            $t = strtotime("$laatste->datum " . ($start ?: '00:00:00')) + 3 * HOUR_IN_SECONDS;
            $einde = $start && gmdate('Y-m-d', $t) === $laatste->datum ? gmdate('H:i:s', $t) : '23:59:00';
        }
        $args += ['all_day' => false, 'start_date' => "$eerste->datum $eerste->starttijd", 'end_date' => "$laatste->datum $einde"];
        if ($args['end_date'] <= $args['start_date']) {
            $args['end_date'] = gmdate('Y-m-d H:i:s',strtotime($args['start_date']) + 3 * HOUR_IN_SECONDS);
        }
    } else {
        $args += ['all_day' => true, 'start_date' => "$eerste->datum 00:00:00", 'end_date' => "$laatste->datum 23:59:59"];
    }
    if ($venue = kalender_locatie($w, $lx)) {
        $args['venue'] = $venue;
    }
    if ($org = kalender_organisator($lx)) {
        $args['organizer'] = $org;
    }
    return $args;
}

function kalender_tekst(object $w, array $sessies, array $lx): string
{
    $h = '<p>Zwemwedstrijd' . ($w->zwembad ? ' in ' . esc_html($w->zwembad) : '') . ($w->stad ? ', ' . esc_html($w->stad) : '')
        . ' · ' . esc_html(BAAN[$w->baan] ?? $w->baan) . '-bad.</p>';
    if ($lx['organisator']) {
        $naam = esc_html($lx['organisator']);
        $h .= '<p>Organisator: ' . ($lx['organisator_url'] ? '<a href="' . esc_url($lx['organisator_url']) . '">' . $naam . '</a>' : $naam) . '</p>';
    }
    $h .= '<h3>Programma</h3><ul>';
    foreach ($sessies as $s) {
        $d = ['Sessie ' . (int) $s->nummer, datum($s->datum, 'l j F Y')];
        if ($s->opwarmen) {
            $d[] = 'inzwemmen ' . substr($s->opwarmen, 0, 5);
        }
        if ($s->starttijd) {
            $d[] = 'start ' . substr($s->starttijd, 0, 5);
        }
        $h .= '<li>' . esc_html(implode(' · ', $d)) . '</li>';
    }
    $h .= '</ul>';
    if (get_option('khzs_kalender_info') && trim((string) $w->info) !== '') {
        $h .= '<p>' . nl2br(esc_html(trim($w->info))) . '</p>';
    }
    $bijlagen = array_filter(bijlagen_van((int) $w->id), fn($b) => in_array((int) $b->id, publieke_bijlagen(), true));
    if ($bijlagen) {
        $h .= '<h3>Documenten</h3><ul>';
        foreach ($bijlagen as $b) {
            $h .= '<li><a href="' . esc_url(app_url("bijlage/{$b->id}/")) . '">' . esc_html($b->naam) . '</a></li>';
        }
        $h .= '</ul>';
    }
    return $h . '<p><a href="' . esc_url(app_url("wedstrijd/{$w->id}/")) . '">Inschrijven via de ledenapp</a></p>';
}

/** Zoekt een locatie op naam (bestaande locaties worden nooit gewijzigd) of maakt ze aan. */
function kalender_locatie(object $w, array $lx): int
{
    $naam = trim($w->zwembad ?: $w->stad);
    if ($naam === '') {
        return 0;
    }
    $id = kalender_zoek_titel('tribe_venue', $naam);
    return $id ?: (int) tribe_create_venue(['Venue' => $naam, 'City' => $w->stad, 'Address' => $lx['adres'], 'Zip' => $lx['postcode'],
        'Country' => $lx['land'], 'post_status' => 'publish']);
}

function kalender_organisator(array $lx): int
{
    if ($lx['organisator'] === '') {
        return 0;
    }
    $id = kalender_zoek_titel('tribe_organizer', $lx['organisator']);
    return $id ?: (int) tribe_create_organizer(['Organizer' => $lx['organisator'], 'Website' => $lx['organisator_url'], 'post_status' => 'publish']);
}

function kalender_zoek_titel(string $type, string $titel): int
{
    return (int) db()->get_var(db()->prepare("SELECT ID FROM " . db()->posts . " WHERE post_type=%s AND post_title=%s
        AND post_status NOT IN ('trash','auto-draft') ORDER BY ID LIMIT 1", $type, $titel));
}

/** Voegt de ingestelde categorie toe (andere categorieën van de website blijven staan). */
function kalender_categorie(int $id): void
{
    $naam = trim((string) get_option('khzs_kalender_categorie', 'Wedstrijden'));
    if ($naam === '' || !taxonomy_exists('tribe_events_cat')) {
        return;
    }
    $term = term_exists($naam, 'tribe_events_cat') ?: wp_insert_term($naam, 'tribe_events_cat');
    if (!is_wp_error($term)) {
        wp_set_object_terms($id, (int) $term['term_id'], 'tribe_events_cat', true);
    }
}

/** Vingerafdruk van de velden die de app beheert, zoals ze nu in WordPress staan. */
function kalender_vingerafdruk(int $id): string
{
    clean_post_cache($id);
    $p = get_post($id);
    $m = fn($k) => get_post_meta($id, $k, false);
    $org = array_map('intval', $m('_EventOrganizerID'));
    sort($org);
    return md5(wp_json_encode([$p->post_title, $p->post_content, $m('_EventStartDate'), $m('_EventEndDate'), $m('_EventAllDay'),
        $m('_EventVenueID'), $m('_EventURL'), $org]));
}

/** Korte stand van het evenement van een wedstrijd, voor de instellingen van die wedstrijd. */
function kalender_stand(object $w): ?array
{
    if (!kalender_aan()) {
        return null;
    }
    $ev = kalender_evenement((int) $w->id);
    if (!$ev) {
        return ['tekst' => in_array($w->status, kalender_statussen(), true) ? 'Nog niet op de websitekalender.'
            : 'Niet op de websitekalender (status ' . W_STATUS[$w->status] . ').', 'link' => ''];
    }
    $t = ['publish' => 'gepubliceerd', 'future' => 'gepland', 'draft' => 'concept', 'pending' => 'ter beoordeling', 'private' => 'privé',
        'trash' => 'in de prullenbak'][$ev->post_status] ?? $ev->post_status;
    if (get_post_meta($ev->ID, '_tribe_events_status', true) === 'canceled') {
        $t .= ', geannuleerd';
    }
    if (get_post_meta($ev->ID, '_khzs_conflict', true)) {
        $t .= ', met de hand gewijzigd op de website (niet overschreven)';
    }
    if (get_post_meta($ev->ID, '_khzs_vast', true)) {
        $t .= ', wordt niet meer bijgewerkt';
    }
    return ['tekst' => "Op de websitekalender: $t.", 'link' => $ev->post_status === 'trash' ? '' : (string) get_permalink($ev)];
}

// ---------- pagina's ----------

function v_kalender(): void
{
    vereist_rol(SECRETARIAAT);
    if (is_demo() || !tec_actief()) {
        toon('inschrijvingen/kalender', ['tec' => false], 'Websitekalender', 'smal');
    }
    if (is_post()) {
        $actie = post('actie');
        if ($actie === 'instellingen') {
            update_option('khzs_kalender_aan', post('aan') ? 1 : 0, false);
            update_option('khzs_kalender_statussen', array_values(array_intersect(post_lijst('statussen'), array_keys(W_STATUS))), false);
            update_option('khzs_kalender_categorie', mb_substr(sanitize_text_field(post('categorie')), 0, 100), false);
            update_option('khzs_kalender_publiceren', post('publiceren') === 'draft' ? 'draft' : 'publish', false);
            update_option('khzs_kalender_info', post('info') ? 1 : 0, false);
            flash('Instellingen bewaard.' . (get_option('khzs_kalender_aan') ? ' Klik op ‘Nu alles synchroniseren’ om de kalender meteen bij te werken.' : ''));
        } elseif ($actie === 'alles') {
            $tel = kalender_alles();
            $namen = ['aangemaakt' => 'aangemaakt', 'bijgewerkt' => 'bijgewerkt', 'ongewijzigd' => 'ongewijzigd', 'geannuleerd' => 'geannuleerd',
                'prullenbak' => 'naar de prullenbak', 'conflict' => 'met de hand gewijzigd (niet overschreven)', 'vast' => 'niet meer bijgewerkt', 'fout' => 'mislukt'];
            $delen = [];
            foreach ($namen as $k => $l) {
                if (!empty($tel[$k])) {
                    $delen[] = "{$tel[$k]} $l";
                }
            }
            flash($delen ? 'Gesynchroniseerd: ' . implode(', ', $delen) . '.' : 'Niets te synchroniseren.', empty($tel['fout']) ? 'success' : 'error');
        } elseif (in_array($actie, ['overschrijven', 'vast'], true)) {
            $ev = get_post((int) post('evenement'));
            $wid = $ev ? (int) get_post_meta($ev->ID, '_khzs_wedstrijd_id', true) : 0;
            if ($wid) {
                if ($actie === 'vast') {
                    update_post_meta($ev->ID, '_khzs_vast', '1');
                    delete_post_meta($ev->ID, '_khzs_conflict');
                    flash("‘{$ev->post_title}’ wordt niet meer bijgewerkt vanuit de app.");
                } else {
                    $u = kalender_sync($wid, true);
                    flash($u === 'fout' ? 'Synchroniseren mislukt.' : "‘{$ev->post_title}’ opnieuw gelijkgezet met de app.", $u === 'fout' ? 'error' : 'success');
                }
            }
        }
        naar(app_url('kalender/'));
    }
    $speciaal = [];
    foreach (kalender_koppelingen() as $id => $wid) {
        if (get_post_meta($id, '_khzs_conflict', true) || get_post_meta($id, '_khzs_vast', true)) {
            $speciaal[] = (object) ['id' => $id, 'titel' => get_the_title($id), 'link' => get_edit_post_link($id, 'url') ?: get_permalink($id),
                'wedstrijd' => $wid, 'vast' => (bool) get_post_meta($id, '_khzs_vast', true), 'sinds' => get_post_meta($id, '_khzs_conflict', true)];
        }
    }
    toon('inschrijvingen/kalender', ['tec' => true, 'speciaal' => $speciaal, 'aantal' => count(kalender_koppelingen())], 'Websitekalender', 'smal');
}

/** Bijlage publiek of privé maken (secretariaat). */
function v_bijlage_publiek($id): void
{
    vereist_rol(SECRETARIAAT);
    vereist_post();
    $b = rij('bijlagen', (int) $id) ?: niet_gevonden();
    $lijst = publieke_bijlagen();
    if (post('publiek')) {
        $lijst[] = (int) $b->id;
        flash("‘{$b->naam}’ is publiek: ze komt op de websitekalender en kan zonder aanmelden gedownload worden.");
    } else {
        $lijst = array_diff($lijst, [(int) $b->id]);
        flash("‘{$b->naam}’ is niet meer publiek.");
    }
    update_option('khzs_kalender_bijlagen', array_values(array_unique($lijst)), false);
    do_action('khzs_wedstrijd_bewaard', (int) $b->wedstrijd_id);
    naar(terug(app_url("wedstrijd/{$b->wedstrijd_id}/instellingen/")));
}

/** Blok onder Instellingen → KHZS Leden in wp-admin. */
function kalender_admin(): void
{
    if (is_demo() || demo_enkel()) {
        return;
    }
    ?>
    <h2>Websitekalender (The Events Calendar)</h2>
    <?php if (!tec_actief()): ?>
      <p>The Events Calendar is niet actief. Zodra die plugin aanstaat, kan de app de wedstrijden automatisch op de kalender zetten.</p>
    <?php else: ?>
      <?php if (isset($_GET['khzs_kalender'])): ?><div class="notice notice-success inline"><p><?= (int) $_GET['khzs_kalender'] ?> wedstrijden nagekeken.</p></div><?php endif; ?>
      <p>Synchronisatie: <b><?= get_option('khzs_kalender_aan') ? 'aan' : 'uit' ?></b>
        · statussen: <?= esc_html(implode(', ', array_map(fn($s) => W_STATUS[$s], kalender_statussen())) ?: 'geen') ?>
        · categorie: <?= esc_html(get_option('khzs_kalender_categorie', 'Wedstrijden')) ?>
        · laatst alles nagekeken: <?= esc_html(get_option('khzs_kalender_laatst') ?: 'nooit') ?></p>
      <?php if ($f = get_option('khzs_kalender_fout')): ?><p>Laatste fout: <code><?= esc_html($f) ?></code></p><?php endif; ?>
      <p><a class="button" href="<?= esc_url(app_url('kalender/')) ?>" target="_blank">Instellen in de app ↗</a>
      <?php if (get_option('khzs_kalender_aan')): ?>
        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="display:inline"><?php wp_nonce_field('khzs_kalender_alles'); ?>
          <input type="hidden" name="action" value="khzs_kalender_alles"><button class="button button-primary">Nu alles synchroniseren</button></form>
      <?php endif; ?></p>
    <?php endif;
}
