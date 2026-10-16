<?php
declare(strict_types=1);

/**
 * Skrip evaluasi pembacaan foto (CLI).
 *
 *   php eval/run.php --mode=gemini [--dir=eval/samples] [--two-pass] [--out=eval/report.md]
 *   php eval/run.php --mode=mock                      (uji mandiri skrip; tanpa foto/API)
 *
 * Isi folder samples: pasangan  NAMA.jpg (atau .png)  +  NAMA.csv  (kebenaran dasar).
 * Lihat eval/README.md untuk format dan cara menyiapkan data uji.
 * Tidak mengubah database.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Jalankan lewat terminal.');
}
$_SERVER['REQUEST_METHOD'] = 'CLI';
require __DIR__ . '/../config/env.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/validation.php';
require __DIR__ . '/../includes/two_pass.php';
require __DIR__ . '/../includes/eval_metrics.php';
require __DIR__ . '/../services/ocr_service.php';
require __DIR__ . '/../services/mock_ocr_service.php';

$opts = getopt('', ['mode::', 'dir::', 'two-pass', 'out::', 'note::']);
$mode = (string)($opts['mode'] ?? 'gemini');
$dir = rtrim((string)($opts['dir'] ?? __DIR__ . '/samples'), '/\\');
$out = (string)($opts['out'] ?? __DIR__ . '/report.md');
$twoPass = isset($opts['two-pass']);
if (!in_array($mode, ['gemini', 'mock'], true)) {
    fwrite(STDERR, "Mode harus gemini atau mock.\n");
    exit(2);
}
if ($mode === 'gemini' && !env('GEMINI_API_KEY')) {
    fwrite(STDERR, "GEMINI_API_KEY belum diisi di .env. Gunakan --mode=mock untuk menguji skrip.\n");
    exit(2);
}

function read_truth(string $csv): array
{
    $h = fopen($csv, 'r');
    if (!$h) {
        return [];
    }
    $head = fgetcsv($h, 0, ',', '"', '');
    if ($head && isset($head[0])) {
        $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$head[0]);
    }
    $rows = [];
    while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
        if ($r === [null] || !$head) {
            continue;
        }
        $rows[] = array_combine($head, array_pad($r, count($head), null)) ?: [];
    }
    fclose($h);
    return $rows;
}

$files = glob($dir . '/*.csv') ?: [];
sort($files);
$files = array_values(array_filter($files, fn($f) => !str_starts_with(basename($f), '_') || $mode === 'mock'));
if (!$files) {
    fwrite(STDERR, "Tidak ada berkas .csv kebenaran dasar di $dir\n");
    exit(2);
}

$all = [];
$n = 0;
foreach ($files as $csv) {
    $base = substr($csv, 0, -4);
    $truth = read_truth($csv);
    if ($mode === 'mock') {
        [$raw] = mock_extract('2026-10-05');
    } else {
        $img = null;
        foreach (['jpg', 'jpeg', 'png', 'JPG', 'JPEG', 'PNG'] as $ext) {
            if (is_file("$base.$ext")) {
                $img = "$base.$ext";
                break;
            }
        }
        if (!$img) {
            fwrite(STDERR, "Lewati " . basename($csv) . ": foto pasangannya tidak ada.\n");
            continue;
        }
        $mime = str_ends_with(strtolower($img), 'png') ? 'image/png' : 'image/jpeg';
        try {
            [$raw] = gemini_extract($img, $mime, null);
            if ($twoPass) {
                [$raw2] = gemini_extract($img, $mime, null, 2);
                $raw = merge_two_readings($raw, $raw2);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, "Gagal membaca " . basename($img) . ": " . $e->getMessage() . "\n");
            continue;
        }
    }
    $pred = array_map('normalize_ocr_row', $raw);
    $s = eval_compare($truth, $pred);
    $all[] = $s;
    $n++;
    $o = eval_overall($s);
    printf("%-28s sel=%-4d akurasi=%-7s diam-diam=%d ditandai=%d/%d\n", basename($csv), $o['cells'], eval_pct($o['accuracy']), $o['silent'], $s['rows_flagged'], $s['rows_pred']);
}
if (!$all) {
    fwrite(STDERR, "Tidak ada sampel yang berhasil dievaluasi.\n");
    exit(1);
}

$sum = eval_merge($all);
$md = eval_markdown($sum, ['mode' => $mode, 'two_pass' => $twoPass, 'samples' => $n, 'note' => (string)($opts['note'] ?? ($mode === 'mock' ? 'UJI MANDIRI skrip dengan data contoh sintetis. BUKAN hasil membaca foto.' : ''))]);
file_put_contents($out, $md);
echo "\n" . $md . "\nLaporan ditulis ke $out\n";
