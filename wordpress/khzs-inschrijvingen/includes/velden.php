<?php
/** Formuliervelden in dezelfde opmaak als de Django-versie (div.veld, label, help, errorlist). */

namespace KHZS;

defined('ABSPATH') || exit;

function fouten_html(?string $fout): string
{
    return $fout ? '<ul class="errorlist"><li>' . e($fout) . '</li></ul>' : '';
}

function veld_html(string $id, string $label, string $invoer, ?string $fout = null, string $help = ''): string
{
    return '<div class="veld"><label for="' . esc_attr($id) . '">' . e($label) . '</label>' . $invoer
        . ($help ? '<div class="help">' . e($help) . '</div>' : '') . fouten_html($fout) . '</div>';
}

function attrs(array $a): string
{
    $s = '';
    foreach ($a as $k => $v) {
        if ($v === true) {
            $s .= ' ' . $k;
        } elseif ($v !== false && $v !== null) {
            $s .= ' ' . $k . '="' . esc_attr((string) $v) . '"';
        }
    }
    return $s;
}

function veld_tekst(string $naam, string $label, $waarde, ?string $fout = null, array $a = [], string $help = ''): string
{
    $id = 'id_' . $naam;
    $a = ['type' => 'text', 'name' => $naam, 'id' => $id, 'value' => (string) $waarde] + $a;
    return veld_html($id, $label, '<input' . attrs($a) . '>', $fout, $help);
}

function veld_textarea(string $naam, string $label, $waarde, ?string $fout = null, int $rijen = 3, string $help = ''): string
{
    $id = 'id_' . $naam;
    return veld_html($id, $label, '<textarea name="' . esc_attr($naam) . '" id="' . esc_attr($id) . '" rows="' . $rijen . '" cols="40">'
        . esc_textarea((string) $waarde) . '</textarea>', $fout, $help);
}

function veld_select(string $naam, string $label, array $keuzes, $waarde, ?string $fout = null, string $help = ''): string
{
    $id = 'id_' . $naam;
    $o = '';
    foreach ($keuzes as $k => $l) {
        $o .= '<option value="' . esc_attr((string) $k) . '"' . ((string) $k === (string) $waarde ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    return veld_html($id, $label, '<select name="' . esc_attr($naam) . '" id="' . esc_attr($id) . '">' . $o . '</select>', $fout, $help);
}

function veld_radio(string $naam, string $label, array $keuzes, $waarde, ?string $fout = null): string
{
    $id = 'id_' . $naam;
    $o = '';
    $i = 0;
    foreach ($keuzes as $k => $l) {
        $o .= '<li><label for="' . esc_attr("{$id}_$i") . '"><input type="radio" name="' . esc_attr($naam) . '" value="' . esc_attr($k)
            . '" id="' . esc_attr("{$id}_$i") . '" required' . ((string) $k === (string) $waarde ? ' checked' : '') . '> ' . e($l) . '</label></li>';
        $i++;
    }
    return veld_html("{$id}_0", $label, '<ul id="' . esc_attr($id) . '">' . $o . '</ul>', $fout);
}

function veld_vinkje(string $naam, string $label, bool $aan, string $help = ''): string
{
    return '<div class="veld"><label><input type="checkbox" name="' . esc_attr($naam) . '" id="id_' . esc_attr($naam) . '"' . ($aan ? ' checked' : '')
        . '> ' . e($label) . '</label>' . ($help ? '<div class="help">' . e($help) . '</div>' : '') . '</div>';
}

/** De velden van een zwemmer (registratie, toevoegen, gegevens). $p = prefix van de namen. */
function zwemmer_velden(string $p, array $v, array $f): string
{
    return veld_tekst("{$p}voornaam", 'Voornaam', $v['voornaam'] ?? '', $f['voornaam'] ?? null, ['maxlength' => 60, 'required' => true])
        . veld_tekst("{$p}naam", 'Naam', $v['naam'] ?? '', $f['naam'] ?? null, ['maxlength' => 80, 'required' => true])
        . veld_tekst("{$p}geboortedatum", 'Geboortedatum', $v['geboortedatum'] ?? '', $f['geboortedatum'] ?? null, ['type' => 'date', 'required' => true])
        . veld_radio("{$p}geslacht", 'Geslacht', GESLACHT, $v['geslacht'] ?? '', $f['geslacht'] ?? null)
        . veld_tekst("{$p}licentie", 'Licentienummer (optioneel)', $v['licentie'] ?? '', null, ['maxlength' => 30])
        . veld_tekst("{$p}swimrankings_id", 'Swimrankings-ID (optioneel)', $v['swimrankings_id'] ?? '', null, ['maxlength' => 20],
            'Het getal achter athleteId= in de link van je Swimrankings-pagina');
}
