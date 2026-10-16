<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/export.php';

/**
 * CSV: HANYA cadangan untuk server yang belum mengaktifkan ekstensi zip (XLSX tidak tersedia).
 * Data TERKONFIRMASI milik Posyandu yang login. Skema internal, bukan format impor resmi ASIK/e-PPGBM.
 */
$p = require_login();
$pdo = db();
$pid = (int)$p['id'];

if (isset($_GET['sesi_id'])) {
    $d = fetch_export_data($pdo, $pid, 'sesi', (int)$_GET['sesi_id']);
} elseif (isset($_GET['periode'])) {
    $d = fetch_export_data($pdo, $pid, 'periode', (string)$_GET['periode'], form_key((string)($_GET['form'] ?? '')));
} else {
    abort_404();
}

/** Cegah formula injection saat dibuka di Excel/Sheets. */
$safe = function (string $v): string {
    return ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) ? "'" . $v : $v;
};

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="kader-cukup-foto_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $d['name']) . '.csv"');
header('Cache-Control: private, no-store');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
fputcsv($out, array_merge(['sesi_id', 'periode'], array_map(fn($c) => $c['l'], $d['columns'])), ',', '"', '');
foreach ($d['rows'] as $r) {
    $line = [(int)$r['sesi_foto_id'], (string)$r['measurement_period']];
    foreach ($d['columns'] as $c) {
        $line[] = $safe(export_cell_text($c, $r));
    }
    fputcsv($out, $line, ',', '"', '');
}
fclose($out);
