<?php
declare(strict_types=1);

/**
 * Penulis XLSX tanpa dependensi (butuh ekstensi PHP zip / ZipArchive), dengan registri gaya:
 * font, warna isi, border, format angka, perataan, sel gabungan, baris beku, filter, dan pengaturan cetak A4.
 * Teks ditulis sebagai inline string, sehingga isi sel yang diawali = + - @ TIDAK dijalankan sebagai rumus.
 */
final class SimpleXlsx
{
    private array $fonts = [];
    private array $fills = [];
    private array $borders = [];
    private array $numFmts = [];     // kode format → id
    private array $xfs = [];
    private array $styleCache = [];
    /** @var array<int, array{name:string, rows:array, opts:array}> */
    private array $sheets = [];
    private string $title = '';
    private string $creator = 'Rakit';

    public function __construct()
    {
        $this->fonts[] = '<font><sz val="11"/><color rgb="FF151A26"/><name val="Calibri"/><family val="2"/></font>';
        $this->fills[] = '<fill><patternFill patternType="none"/></fill>';
        $this->fills[] = '<fill><patternFill patternType="gray125"/></fill>';
        $this->borders[] = '<border><left/><right/><top/><bottom/><diagonal/></border>';
        $this->xfs[] = '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>';
    }

    public static function available(): bool
    {
        return class_exists('ZipArchive');
    }

    public function setProperties(string $title, string $creator = 'Rakit'): void
    {
        $this->title = $title;
        $this->creator = $creator;
    }

    /**
     * Daftarkan gaya sel dan kembalikan indeksnya (gaya yang sama dipakai ulang).
     * @param array{bold?:bool, italic?:bool, size?:float, color?:string, fill?:string, border?:string, borderColor?:string,
     *              align?:string, valign?:string, wrap?:bool, numFmt?:string, indent?:int} $o
     */
    public function style(array $o): int
    {
        $key = json_encode($o);
        if (isset($this->styleCache[$key])) {
            return $this->styleCache[$key];
        }
        $font = '<font>' . (!empty($o['bold']) ? '<b/>' : '') . (!empty($o['italic']) ? '<i/>' : '')
            . '<sz val="' . ($o['size'] ?? 11) . '"/><color rgb="FF' . strtoupper($o['color'] ?? '151A26') . '"/><name val="Calibri"/><family val="2"/></font>';
        $fontId = $this->intern($this->fonts, $font);

        $fillId = 0;
        if (!empty($o['fill'])) {
            $fillId = $this->intern($this->fills, '<fill><patternFill patternType="solid"><fgColor rgb="FF' . strtoupper($o['fill']) . '"/><bgColor indexed="64"/></patternFill></fill>');
        }

        $borderId = 0;
        if (!empty($o['border']) && $o['border'] !== 'none') {
            $c = '<color rgb="FF' . strtoupper($o['borderColor'] ?? 'DCE1E8') . '"/>';
            $b = ['left' => '', 'right' => '', 'top' => '', 'bottom' => ''];
            foreach (($o['border'] === 'all' ? array_keys($b) : explode(',', $o['border'])) as $side) {
                if (isset($b[$side])) {
                    $b[$side] = ' style="thin">' . $c;
                }
            }
            $xml = '<border>';
            foreach ($b as $side => $v) {
                $xml .= $v !== '' ? "<$side$v</$side>" : "<$side/>";
            }
            $borderId = $this->intern($this->borders, $xml . '<diagonal/></border>');
        }

        $numFmtId = 0;
        if (!empty($o['numFmt'])) {
            $builtin = ['0' => 1, '0.00' => 2, '0%' => 9];
            if (isset($builtin[$o['numFmt']])) {
                $numFmtId = $builtin[$o['numFmt']];
            } else {
                $this->numFmts[$o['numFmt']] ??= 164 + count($this->numFmts);
                $numFmtId = $this->numFmts[$o['numFmt']];
            }
        }

        $align = '';
        if (!empty($o['align']) || !empty($o['valign']) || !empty($o['wrap']) || !empty($o['indent'])) {
            $align = '<alignment' . (!empty($o['align']) ? ' horizontal="' . $o['align'] . '"' : '')
                . ' vertical="' . ($o['valign'] ?? 'center') . '"' . (!empty($o['wrap']) ? ' wrapText="1"' : '')
                . (!empty($o['indent']) ? ' indent="' . (int)$o['indent'] . '"' : '') . '/>';
        }
        $xf = '<xf numFmtId="' . $numFmtId . '" fontId="' . $fontId . '" fillId="' . $fillId . '" borderId="' . $borderId . '" xfId="0"'
            . ($numFmtId ? ' applyNumberFormat="1"' : '') . ' applyFont="1"' . ($fillId ? ' applyFill="1"' : '') . ($borderId ? ' applyBorder="1"' : '')
            . ($align !== '' ? ' applyAlignment="1">' . $align . '</xf>' : '/>');
        $this->xfs[] = $xf;
        return $this->styleCache[$key] = count($this->xfs) - 1;
    }

    private function intern(array &$list, string $xml): int
    {
        $i = array_search($xml, $list, true);
        if ($i !== false) {
            return (int)$i;
        }
        $list[] = $xml;
        return count($list) - 1;
    }

    /**
     * @param array<int, array<int, mixed>> $rows sel = null | int | float | string | ['v' => nilai, 's' => indeks gaya]
     * @param array{widths?: float[], freezeRows?: int, filter?: string, merges?: string[], rowHeights?: array<int,float>,
     *              landscape?: bool, fitWidth?: bool, printTitleRows?: array{0:int,1:int}, showGrid?: bool, tabColor?: string,
     *              footer?: string, zoom?: int} $opts
     */
    public function addSheet(string $name, array $rows, array $opts = []): void
    {
        $name = preg_replace('/[\\\\\/\?\*\[\]:\']/', ' ', $name) ?? 'Sheet';
        $this->sheets[] = ['name' => mb_substr(trim($name) ?: 'Sheet', 0, 31), 'rows' => $rows, 'opts' => $opts];
    }

    public static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    /** Tanggal 'YYYY-MM-DD' → nomor seri Excel (hari sejak 1899-12-30), atau null. */
    public static function dateSerial(?string $ymd): ?int
    {
        if (!$ymd || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) {
            return null;
        }
        return (int)floor(gmmktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]) / 86400) + 25569;
    }

    private static function esc(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function sheetXml(array $rows, array $o, bool $first): string
    {
        $maxCols = 0;
        foreach ($rows as $r) {
            $maxCols = max($maxCols, count($r));
        }
        $lastCol = self::col(max(0, $maxCols - 1));
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $x .= '<sheetPr>' . (!empty($o['tabColor']) ? '<tabColor rgb="FF' . strtoupper($o['tabColor']) . '"/>' : '') . '<pageSetUpPr fitToPage="1"/></sheetPr>';
        $x .= '<dimension ref="A1:' . $lastCol . max(1, count($rows)) . '"/>';
        $x .= '<sheetViews><sheetView workbookViewId="0"' . ($first ? ' tabSelected="1"' : '') . (isset($o['showGrid']) && !$o['showGrid'] ? ' showGridLines="0"' : '') . (!empty($o['zoom']) ? ' zoomScale="' . (int)$o['zoom'] . '"' : '') . '>';
        if (!empty($o['freezeRows'])) {
            $fr = (int)$o['freezeRows'];
            $x .= '<pane ySplit="' . $fr . '" topLeftCell="A' . ($fr + 1) . '" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A' . ($fr + 1) . '" sqref="A' . ($fr + 1) . '"/>';
        }
        $x .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>';
        if (!empty($o['widths'])) {
            $x .= '<cols>';
            foreach ($o['widths'] as $i => $w) {
                $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float)$w . '" customWidth="1"/>';
            }
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        $heights = $o['rowHeights'] ?? [];
        foreach ($rows as $ri => $row) {
            $ht = isset($heights[$ri]) ? ' ht="' . (float)$heights[$ri] . '" customHeight="1"' : '';
            $x .= '<row r="' . ($ri + 1) . '"' . $ht . '>';
            foreach ($row as $ci => $cell) {
                $style = 0;
                $v = $cell;
                if (is_array($cell)) {
                    $v = $cell['v'] ?? null;
                    $style = (int)($cell['s'] ?? 0);
                }
                $ref = self::col($ci) . ($ri + 1);
                $s = $style > 0 ? ' s="' . $style . '"' : '';
                if ($v === null || $v === '') {
                    if ($style > 0) {
                        $x .= '<c r="' . $ref . '"' . $s . '/>';   // sel kosong tetap membawa gaya (isi/border)
                    }
                    continue;
                }
                if (is_int($v) || is_float($v)) {
                    $x .= '<c r="' . $ref . '"' . $s . '><v>' . (is_float($v) ? rtrim(rtrim(sprintf('%.6F', $v), '0'), '.') : $v) . '</v></c>';
                } else {
                    $x .= '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . self::esc((string)$v) . '</t></is></c>';
                }
            }
            $x .= '</row>';
        }
        $x .= '</sheetData>';
        if (!empty($o['filter'])) {
            $x .= '<autoFilter ref="' . $o['filter'] . '"/>';
        }
        if (!empty($o['merges'])) {
            $x .= '<mergeCells count="' . count($o['merges']) . '">';
            foreach ($o['merges'] as $m) {
                $x .= '<mergeCell ref="' . $m . '"/>';
            }
            $x .= '</mergeCells>';
        }
        $x .= '<printOptions horizontalCentered="1"/><pageMargins left="0.4" right="0.4" top="0.6" bottom="0.7" header="0.3" footer="0.3"/>';
        $x .= '<pageSetup paperSize="9" orientation="' . (!empty($o['landscape']) ? 'landscape' : 'portrait') . '" fitToWidth="1" fitToHeight="0"/>';
        if (!empty($o['footer'])) {
            $x .= '<headerFooter><oddFooter>' . self::esc($o['footer']) . '</oddFooter></headerFooter>';
        }
        return $x . '</worksheet>';
    }

    private function stylesXml(): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($this->numFmts) {
            $x .= '<numFmts count="' . count($this->numFmts) . '">';
            foreach ($this->numFmts as $code => $id) {
                $x .= '<numFmt numFmtId="' . $id . '" formatCode="' . self::esc($code) . '"/>';
            }
            $x .= '</numFmts>';
        }
        $x .= '<fonts count="' . count($this->fonts) . '">' . implode('', $this->fonts) . '</fonts>';
        $x .= '<fills count="' . count($this->fills) . '">' . implode('', $this->fills) . '</fills>';
        $x .= '<borders count="' . count($this->borders) . '">' . implode('', $this->borders) . '</borders>';
        $x .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
        $x .= '<cellXfs count="' . count($this->xfs) . '">' . implode('', $this->xfs) . '</cellXfs>';
        return $x . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    public function save(string $path): bool
    {
        if (!self::available() || !$this->sheets) {
            return false;
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $defined = '';
        foreach ($this->sheets as $i => $sh) {
            $n = $i + 1;
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wb .= '<sheet name="' . self::esc($sh['name']) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', $this->sheetXml($sh['rows'], $sh['opts'], $i === 0));
            if (!empty($sh['opts']['printTitleRows'])) {
                [$a, $b] = $sh['opts']['printTitleRows'];
                $defined .= '<definedName name="_xlnm.Print_Titles" localSheetId="' . $i . '">\'' . str_replace("'", "''", $sh['name']) . '\'!$' . $a . ':$' . $b . '</definedName>';
            }
        }
        $rels .= '<Relationship Id="rId' . (count($this->sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $ct .= '</Types>';
        $wb .= '</sheets>' . ($defined !== '' ? '<definedNames>' . $defined . '</definedNames>' : '') . '</workbook>';
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $zip->addFromString('[Content_Types].xml', $ct);
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . self::esc($this->title) . '</dc:title><dc:creator>' . self::esc($this->creator) . '</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified></cp:coreProperties>');
        $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Rakit</Application></Properties>');
        $zip->addFromString('xl/workbook.xml', $wb);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        return $zip->close();
    }

    /** Kirim sebagai unduhan lalu hentikan skrip. */
    public function download(string $filename): never
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kcf');
        if ($tmp === false || !$this->save($tmp)) {
            http_response_code(500);
            exit('Ekstensi zip PHP belum aktif, ekspor XLSX tidak tersedia.');
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: private, no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
}
