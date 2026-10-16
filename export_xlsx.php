<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/xlsx.php';
require __DIR__ . '/includes/export.php';

/**
 * Ekspor XLSX: hanya data TERKONFIRMASI milik Posyandu yang login.
 *   ?sesi_id=N                       → satu sesi
 *   ?periode=YYYY-MM[&form=KUNCI]    → satu periode pengukuran
 * Tiga sheet: Laporan (siap cetak A4), Ringkasan, dan Data (kolom mesin untuk olah ulang).
 * Skema ini INTERNAL, bukan format impor resmi ASIK/e-PPGBM. Penanda BUKAN diagnosis.
 */
$p = require_login();
$pdo = db();
$pid = (int)$p['id'];

if (!SimpleXlsx::available()) {
    http_response_code(501);
    exit('Ekstensi PHP zip belum aktif, ekspor XLSX tidak tersedia. Gunakan CSV.');
}
if (isset($_GET['sesi_id'])) {
    $d = fetch_export_data($pdo, $pid, 'sesi', (int)$_GET['sesi_id']);
} elseif (isset($_GET['periode'])) {
    $d = fetch_export_data($pdo, $pid, 'periode', (string)$_GET['periode'], form_key((string)($_GET['form'] ?? '')));
} else {
    abort_404();
}

$def = $d['def'];
$cols = $d['columns'];
$att = $d['attention'];
$trend = !empty($def['trend']);
$nCols = count($cols);
$lastCol = SimpleXlsx::col($nCols - 1);
$title = ($d['form'] === FORM_BALITA ? 'Laporan Penimbangan dan Pengukuran Balita' : 'Laporan ' . $def['label']);
$stamp = date('d/m/Y H:i') . ' WIB';

$x = new SimpleXlsx();
$x->setProperties($title . ' ' . $d['label'] . ' ' . $p['name']);
$blue = '2B3FB0';
$navy = '1E2D87';
$S = [
    'title' => $x->style(['bold' => true, 'size' => 16, 'color' => 'FFFFFF', 'fill' => $blue, 'indent' => 1]),
    'sub' => $x->style(['size' => 10, 'color' => $navy, 'fill' => 'E6E9FA', 'indent' => 1]),
    'head' => $x->style(['bold' => true, 'size' => 10, 'color' => 'FFFFFF', 'fill' => $navy, 'border' => 'all', 'borderColor' => $navy, 'align' => 'center', 'wrap' => true]),
    'note' => $x->style(['size' => 9, 'color' => '4B5565', 'wrap' => true, 'valign' => 'top', 'indent' => 1]),
    'rule' => $x->style(['border' => 'top', 'borderColor' => 'C3CAD6']),
];
$cell = function (string $kind, int $band) use ($x): int {
    $fill = $band % 2 === 0 ? 'FFFFFF' : 'F6F8FB';
    $base = ['fill' => $fill, 'border' => 'all', 'borderColor' => 'DCE1E8'];
    return match ($kind) {
        'center' => $x->style($base + ['align' => 'center']),
        'text' => $x->style($base + ['align' => 'left', 'indent' => 1, 'wrap' => true]),
        'date' => $x->style($base + ['align' => 'center', 'numFmt' => 'dd/mm/yyyy']),
        'num' => $x->style($base + ['align' => 'right', 'numFmt' => '0.0#']),
        'int' => $x->style($base + ['align' => 'right', 'numFmt' => '0']),
        'delta' => $x->style($base + ['align' => 'right', 'numFmt' => '+0.0#;-0.0#;0.0']),
        'delta_down' => $x->style($base + ['align' => 'right', 'numFmt' => '+0.0#;-0.0#;0.0', 'bold' => true, 'color' => '8C1D17']),
        'prioritas' => $x->style(['fill' => 'FBE3E1', 'color' => '8C1D17', 'bold' => true, 'align' => 'center', 'border' => 'all', 'borderColor' => 'DCE1E8']),
        'cek' => $x->style(['fill' => 'FFEFC7', 'color' => '7A4B00', 'bold' => true, 'align' => 'center', 'border' => 'all', 'borderColor' => 'DCE1E8']),
        default => $x->style($base),
    };
};

// ---------- Sheet 1: Laporan ----------
$fill = fn(int $style) => array_fill(0, $nCols, ['v' => null, 's' => $style]);
$rowsL = [];
$rowsL[] = array_replace($fill($S['title']), [0 => ['v' => $title, 's' => $S['title']]]);
$rowsL[] = array_replace($fill($S['sub']), [0 => ['v' => $p['name'] . '   |   ID ' . $p['posyandu_code'] . '   |   Periode ' . $d['label'] . '   |   Dibuat ' . $stamp, 's' => $S['sub']]]);
$rowsL[] = [];
$rowsL[] = array_map(fn($c) => ['v' => $c['l'], 's' => $S['head']], $cols);
$first = 5;   // baris data pertama (1-based)
foreach ($d['rows'] as $i => $r) {
    $line = [];
    foreach ($cols as $c) {
        $v = $r[$c['k']] ?? null;
        switch ($c['t']) {
            case 'date':
                $ser = SimpleXlsx::dateSerial($v ? (string)$v : null);
                $line[] = ['v' => $ser, 's' => $cell('date', $i)];
                break;
            case 'num':
                $line[] = ['v' => $v === null ? null : (float)$v, 's' => $cell('num', $i)];
                break;
            case 'int':
                $line[] = ['v' => $v === null ? null : (int)$v, 's' => $cell('int', $i)];
                break;
            case 'delta':
                $line[] = ['v' => $v === null ? null : (float)$v, 's' => $cell($v !== null && $v <= 0 ? 'delta_down' : 'delta', $i)];
                break;
            case 'level':
                $line[] = ['v' => $v === 'prioritas' ? 'Prioritas' : ($v === 'cek' ? 'Cek' : null), 's' => $cell($v === 'prioritas' ? 'prioritas' : ($v === 'cek' ? 'cek' : 'center'), $i)];
                break;
            default:
                $line[] = ['v' => $v === null ? null : (string)$v, 's' => $cell(in_array($c['k'], ['nomor_pendaftaran', 'jenis_kelamin'], true) ? 'center' : 'text', $i)];
        }
    }
    $rowsL[] = $line;
}
$lastDataRow = 4 + count($d['rows']);
if (!$d['rows']) {
    $rowsL[] = array_replace($fill($cell('text', 0)), [0 => ['v' => 'Tidak ada data terkonfirmasi untuk cakupan ini.', 's' => $cell('text', 0)]]);
    $lastDataRow = 5;
}
$merges = ['A1:' . $lastCol . '1', 'A2:' . $lastCol . '2'];
$heights = [0 => 34, 1 => 20, 2 => 8, 3 => 36];
$rowsL[] = [];
$notes = [];
if ($trend) {
    $notes[] = 'Penanda "tidak naik" membandingkan berat badan dengan catatan sebelumnya anak yang sama untuk membantu petugas memilih kunjungan. Ini BUKAN diagnosis dan perlu ditinjau tenaga kesehatan. Anak yang tidak punya catatan pada periode ini tidak otomatis berarti tidak hadir.';
}
if (!$def['verified']) {
    $notes[] = 'Kolom formulir ini adalah contoh awal. Cocokkan dengan buku di Posyandu Anda sebelum dipakai untuk data nyata.';
}
$notes[] = 'Data berasal dari foto buku pencatatan yang telah diperiksa dan dikonfirmasi kader. Skema kolom pada sheet Data adalah skema internal, bukan format impor ASIK atau e-PPGBM.';
foreach ($notes as $n) {
    $r = $fill($S['note']);
    $r[0] = ['v' => $n, 's' => $S['note']];
    $idx = count($rowsL);
    $rowsL[] = $r;
    $merges[] = 'A' . ($idx + 1) . ':' . $lastCol . ($idx + 1);
    $heights[$idx] = 30;
}
$widths = array_map(fn($c) => (float)$c['w'], $cols);
$x->addSheet('Laporan', $rowsL, [
    'widths' => $widths, 'freezeRows' => 4, 'filter' => 'A4:' . $lastCol . $lastDataRow, 'merges' => $merges, 'rowHeights' => $heights,
    'landscape' => true, 'printTitleRows' => [4, 4], 'showGrid' => false, 'tabColor' => $blue,
    'footer' => '&L&8Rakit&C&8Halaman &P dari &N&R&8' . $p['name'],
]);

// ---------- Sheet 2: Ringkasan ----------
$kv = ['label' => $x->style(['bold' => true, 'color' => '4B5565', 'border' => 'bottom', 'borderColor' => 'DCE1E8', 'indent' => 1]),
       'value' => $x->style(['bold' => true, 'size' => 13, 'border' => 'bottom', 'borderColor' => 'DCE1E8', 'align' => 'left', 'indent' => 1]),
       'info' => $x->style(['border' => 'bottom', 'borderColor' => 'DCE1E8', 'align' => 'left', 'indent' => 1, 'wrap' => true]),
       'h2' => $x->style(['bold' => true, 'size' => 11, 'color' => $navy, 'indent' => 1])];
$rowsR = [];
$rowsR[] = [['v' => 'Ringkasan', 's' => $S['title']], ['v' => null, 's' => $S['title']]];
$rowsR[] = [['v' => $title . '   |   ' . $d['label'], 's' => $S['sub']], ['v' => null, 's' => $S['sub']]];
$rowsR[] = [];
$rowsR[] = [['v' => 'Angka utama', 's' => $kv['h2']]];
$rowsR[] = [['v' => 'Catatan terkonfirmasi', 's' => $kv['label']], ['v' => count($d['rows']), 's' => $kv['value']]];
$rowsR[] = [['v' => ucfirst($def['subject']) . ' (identitas pasti)', 's' => $kv['label']], ['v' => $d['unique_children'], 's' => $kv['value']]];
if ($trend && $d['period']) {
    $rowsR[] = [['v' => 'BB naik dari catatan sebelumnya', 's' => $kv['label']], ['v' => (int)$att['rising'], 's' => $kv['value']]];
    $rowsR[] = [['v' => 'BB tidak naik (penanda)', 's' => $kv['label']], ['v' => (int)$att['not_rising'], 's' => $kv['value']]];
    $rowsR[] = [['v' => '   dari itu Prioritas', 's' => $kv['label']], ['v' => (int)$att['counts']['prioritas'], 's' => $kv['value']]];
    $rowsR[] = [['v' => '   dari itu Cek', 's' => $kv['label']], ['v' => (int)$att['counts']['cek'], 's' => $kv['value']]];
    $rowsR[] = [['v' => 'Belum punya catatan pembanding', 's' => $kv['label']], ['v' => (int)$att['no_prev'], 's' => $kv['value']]];
}
$rowsR[] = [];
$rowsR[] = [['v' => 'Informasi', 's' => $kv['h2']]];
foreach ([['Posyandu', $p['name']], ['ID Posyandu', $p['posyandu_code']], ['Formulir', $def['label']], ['Cakupan', $d['label']], ['Dibuat pada', $stamp]] as [$k, $v]) {
    $rowsR[] = [['v' => $k, 's' => $kv['label']], ['v' => $v, 's' => $kv['info']]];
}
$x->addSheet('Ringkasan', $rowsR, [
    'widths' => [38, 52], 'merges' => ['A1:B1', 'A2:B2'], 'rowHeights' => [0 => 34, 1 => 20], 'showGrid' => false, 'tabColor' => '17754D', 'landscape' => false,
]);

// ---------- Sheet 3: Data (kolom mesin) ----------
$mk = array_keys(form_measure_rules($d['form']));
$head = array_merge(['posyandu_id', 'sesi_id', 'nomor_pendaftaran', 'nama_peserta', 'tanggal_lahir', 'jenis_kelamin', 'periode_pengukuran'], $mk);
if ($trend) {
    array_push($head, 'selisih_bb_kg', 'penanda_untuk_ditinjau');
}
array_push($head, 'keterangan', 'status_verifikasi');
$hs = $x->style(['bold' => true, 'fill' => 'E9EDF2', 'border' => 'all', 'borderColor' => 'C3CAD6']);
$rowsD = [array_map(fn($h) => ['v' => $h, 's' => $hs], $head)];
$rules = form_measure_rules($d['form']);
foreach ($d['rows'] as $r) {
    $line = [$r['posyandu_code'], (int)$r['sesi_foto_id'], $r['nomor_pendaftaran'], $r['nama_peserta'], $r['tanggal_lahir'], $r['jenis_kelamin'], $r['measurement_period']];
    foreach ($mk as $k) {
        $v = $r[$rules[$k]['col']] ?? null;
        $line[] = $v === null ? null : ((int)$rules[$k]['dec'] === 0 ? (int)$v : (float)$v);
    }
    if ($trend) {
        $line[] = $r['_delta'] === null ? null : (float)$r['_delta'];
        $line[] = $r['_level'] === 'prioritas' ? 'Prioritas' : ($r['_level'] === 'cek' ? 'Cek' : null);
    }
    $line[] = $r['keterangan'];
    $line[] = 'terverifikasi';
    $rowsD[] = $line;
}
$wD = array_fill(0, count($head), 16.0);
$wD[3] = 28.0;
$x->addSheet('Data', $rowsD, ['widths' => $wD, 'freezeRows' => 1, 'filter' => 'A1:' . SimpleXlsx::col(count($head) - 1) . max(1, count($rowsD)), 'tabColor' => '8A94A6']);

$x->download('kader-cukup-foto_' . $d['name'] . '.xlsx');
