<?php
/** Minimale XLSX-schrijver (geen externe bibliotheek). */

namespace KHZS;

defined('ABSPATH') || exit;

/**
 * $bladen = [['naam' => ..., 'rijen' => [[cel, ...], ...], 'datumkolommen' => [index, ...]], ...]
 * Cellen: string, int/float, of \DateTimeInterface. Rij 1 wordt vet en vastgezet.
 */
function maak_xlsx(array $bladen): string
{
    $x = fn($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $kol = function (int $i): string {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    };
    $tmp = tijdelijk();
    $zip = new \ZipArchive();
    $zip->open($tmp, \ZipArchive::OVERWRITE);
    $n = count($bladen);
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    for ($i = 1; $i <= $n; $i++) {
        $ct .= "<Override PartName=\"/xl/worksheets/sheet$i.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        $wb .= '<sheet name="' . $x($bladen[$i - 1]['naam']) . "\" sheetId=\"$i\" r:id=\"rId$i\"/>";
        $rels .= "<Relationship Id=\"rId$i\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet$i.xml\"/>";
    }
    $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $zip->addFromString('[Content_Types].xml', $ct . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
    // Stijl 0 = standaard, 1 = vet, 2 = datum DD/MM/YYYY.
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="DD/MM/YYYY"/></numFmts>'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs></styleSheet>');

    foreach ($bladen as $b => $blad) {
        $breedte = [];
        $data = '';
        foreach ($blad['rijen'] as $r => $rij) {
            $data .= '<row r="' . ($r + 1) . '">';
            foreach (array_values($rij) as $c => $waarde) {
                $ref = $kol($c) . ($r + 1);
                $stijl = $r === 0 ? ' s="1"' : '';
                if ($waarde instanceof \DateTimeInterface) {
                    $serieel = ($waarde->getTimestamp() + $waarde->getOffset()) / 86400 + 25569;
                    $data .= "<c r=\"$ref\" s=\"2\"><v>" . round($serieel, 6) . '</v></c>';
                    $tekst = $waarde->format('d/m/Y');
                } elseif (is_int($waarde) || is_float($waarde)) {
                    $data .= "<c r=\"$ref\"$stijl><v>$waarde</v></c>";
                    $tekst = (string) $waarde;
                } elseif ($waarde === null || $waarde === '') {
                    $tekst = '';
                } else {
                    $data .= "<c r=\"$ref\" t=\"inlineStr\"$stijl><is><t xml:space=\"preserve\">" . $x($waarde) . '</t></is></c>';
                    $tekst = (string) $waarde;
                }
                $breedte[$c] = max($breedte[$c] ?? 0, mb_strlen($tekst));
            }
            $data .= '</row>';
        }
        $cols = '';
        foreach ($breedte as $c => $w) {
            $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . ($w + 2) . '" customWidth="1"/>';
        }
        $zip->addFromString('xl/worksheets/sheet' . ($b + 1) . '.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . ($cols ? "<cols>$cols</cols>" : '') . "<sheetData>$data</sheetData></worksheet>");
    }
    $zip->close();
    $bytes = file_get_contents($tmp);
    @unlink($tmp);
    return $bytes;
}
