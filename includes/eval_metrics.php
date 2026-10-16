<?php
declare(strict_types=1);

require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/suggest.php';

/**
 * Metrik evaluasi pembacaan foto: bandingkan baris hasil baca (sudah dinormalisasi) dengan kebenaran dasar.
 * Dipakai oleh eval/run.php dan tes unit.
 *
 * "Kesalahan diam-diam" = sel yang salah pada baris yang TIDAK ditandai perlu diperiksa
 * (kader tidak akan melihatnya). Ini angka terpenting.
 */

const EVAL_FIELDS = [
    'nomor_pendaftaran'  => ['col' => 'nomor_pendaftaran',  'type' => 'text',    'label' => 'No. daftar'],
    'nama_peserta'       => ['col' => 'nama_peserta',       'type' => 'name',    'label' => 'Nama'],
    'tanggal_lahir'      => ['col' => 'tanggal_lahir',      'type' => 'text',    'label' => 'Tgl lahir'],
    'jenis_kelamin'      => ['col' => 'jenis_kelamin',      'type' => 'text',    'label' => 'JK'],
    'periode_pengukuran' => ['col' => 'measurement_period', 'type' => 'text',    'label' => 'Periode'],
    'bb_kg'              => ['col' => 'weight_kg',          'type' => 'number',  'label' => 'BB'],
    'tb_pb_cm'           => ['col' => 'height_cm',          'type' => 'number',  'label' => 'TB/PB'],
    'lingkar_kepala_cm'  => ['col' => 'head_circumference_cm', 'type' => 'number', 'label' => 'L. kepala'],
    'lila_cm'            => ['col' => 'lila_cm',            'type' => 'number',  'label' => 'LiLA'],
];

function eval_same(string $type, mixed $truth, mixed $pred): bool
{
    $t = ($truth === null || trim((string)$truth) === '') ? null : trim((string)$truth);
    $p = ($pred === null || trim((string)$pred) === '') ? null : trim((string)$pred);
    if ($t === null || $p === null) {
        return $t === null && $p === null;
    }
    return match ($type) {
        'number' => abs((float)str_replace(',', '.', $t) - (float)str_replace(',', '.', $p)) < 0.001,
        'name' => normalize_name($t) === normalize_name($p),
        default => $t === $p,
    };
}

/**
 * @param array<int, array> $truthRows baris kebenaran dasar (kunci = nama field form)
 * @param array<int, array> $predRows  baris hasil normalize_ocr_row (kunci = kolom DB + needs_review + flag_fields)
 * @return array
 */
function eval_compare(array $truthRows, array $predRows): array
{
    $sum = ['fields' => [], 'rows_truth' => count($truthRows), 'rows_pred' => count($predRows), 'rows_missed' => 0, 'rows_extra' => 0, 'rows_flagged' => 0];
    foreach (EVAL_FIELDS as $k => $_) {
        $sum['fields'][$k] = ['n' => 0, 'ok' => 0, 'wrong_caught' => 0, 'wrong_silent' => 0];
    }
    foreach ($predRows as $r) {
        if (!empty($r['needs_review'])) {
            $sum['rows_flagged']++;
        }
    }

    // Sejajarkan: pakai nomor pendaftaran bila unik di kedua sisi, jika tidak pakai urutan.
    $nom = fn(array $r, string $k) => trim((string)($r[$k] ?? ''));
    $tn = array_map(fn($r) => $nom($r, 'nomor_pendaftaran'), $truthRows);
    $pn = array_map(fn($r) => $nom($r, 'nomor_pendaftaran'), $predRows);
    $uniq = fn(array $k) => !in_array('', $k, true) && count($k) === count(array_unique($k));
    $byNomor = $uniq($tn) && $uniq($pn);
    $predIdx = [];
    foreach ($predRows as $i => $r) {
        $predIdx[$byNomor ? $nom($r, 'nomor_pendaftaran') : (string)$i] = $i;
    }
    $used = [];

    foreach ($truthRows as $i => $t) {
        $pk = $byNomor ? $nom($t, 'nomor_pendaftaran') : (string)$i;
        $pred = isset($predIdx[$pk]) ? $predRows[$predIdx[$pk]] : null;
        if ($pred === null) {
            $sum['rows_missed']++;
            foreach (EVAL_FIELDS as $f => $def) {
                $sum['fields'][$f]['n']++;
                $sum['fields'][$f]['wrong_silent']++;   // baris hilang tanpa penanda = tidak terlihat
            }
            continue;
        }
        $used[$predIdx[$pk]] = true;
        $rowFlagged = !empty($pred['needs_review']);
        $flagSet = array_filter(array_map('trim', explode(',', (string)($pred['flag_fields'] ?? ''))));
        foreach (EVAL_FIELDS as $f => $def) {
            $sum['fields'][$f]['n']++;
            if (eval_same($def['type'], $t[$f] ?? null, $pred[$def['col']] ?? null)) {
                $sum['fields'][$f]['ok']++;
            } elseif ($rowFlagged || in_array($f, $flagSet, true)) {
                $sum['fields'][$f]['wrong_caught']++;
            } else {
                $sum['fields'][$f]['wrong_silent']++;
            }
        }
    }
    $sum['rows_extra'] = count($predRows) - count($used);
    return $sum;
}

/** Jumlahkan beberapa ringkasan (satu per foto). */
function eval_merge(array $list): array
{
    $tot = ['fields' => [], 'rows_truth' => 0, 'rows_pred' => 0, 'rows_missed' => 0, 'rows_extra' => 0, 'rows_flagged' => 0];
    foreach (EVAL_FIELDS as $k => $_) {
        $tot['fields'][$k] = ['n' => 0, 'ok' => 0, 'wrong_caught' => 0, 'wrong_silent' => 0];
    }
    foreach ($list as $s) {
        foreach (['rows_truth', 'rows_pred', 'rows_missed', 'rows_extra', 'rows_flagged'] as $k) {
            $tot[$k] += $s[$k];
        }
        foreach ($s['fields'] as $f => $c) {
            foreach ($c as $k => $v) {
                $tot['fields'][$f][$k] += $v;
            }
        }
    }
    return $tot;
}

function eval_overall(array $sum): array
{
    $n = $ok = $caught = $silent = 0;
    foreach ($sum['fields'] as $c) {
        $n += $c['n'];
        $ok += $c['ok'];
        $caught += $c['wrong_caught'];
        $silent += $c['wrong_silent'];
    }
    $wrong = $caught + $silent;
    $measure = ['bb_kg', 'tb_pb_cm'];
    $mn = $mok = $msilent = 0;
    foreach ($measure as $f) {
        $mn += $sum['fields'][$f]['n'];
        $mok += $sum['fields'][$f]['ok'];
        $msilent += $sum['fields'][$f]['wrong_silent'];
    }
    return [
        'cells' => $n,
        'accuracy' => $n ? $ok / $n : null,
        'wrong' => $wrong,
        'caught' => $caught,
        'silent' => $silent,
        'silent_rate' => $n ? $silent / $n : null,
        'flag_recall' => $wrong ? $caught / $wrong : null,
        'bbtb_accuracy' => $mn ? $mok / $mn : null,
        'bbtb_silent' => $msilent,
        'burden' => $sum['rows_pred'] ? $sum['rows_flagged'] / $sum['rows_pred'] : null,
    ];
}

function eval_pct(?float $v): string
{
    return $v === null ? '—' : number_format($v * 100, 1, ',', '') . '%';
}

/** Laporan Markdown siap tempel ke pitch deck/proposal. */
function eval_markdown(array $sum, array $meta): string
{
    $o = eval_overall($sum);
    $md = "# Hasil evaluasi pembacaan foto\n\n";
    $md .= "- Tanggal: " . ($meta['date'] ?? date('d/m/Y H:i')) . "\n";
    $md .= "- Mode: **" . ($meta['mode'] ?? '?') . "**" . (!empty($meta['two_pass']) ? ' (dua kali baca)' : '') . "\n";
    $md .= "- Jumlah foto/berkas: " . ($meta['samples'] ?? '?') . "; baris kebenaran dasar: " . $sum['rows_truth'] . "; baris terbaca: " . $sum['rows_pred'] . "\n";
    if (!empty($meta['note'])) {
        $md .= "- Catatan: " . $meta['note'] . "\n";
    }
    $md .= "\n## Ringkasan\n\n| Metrik | Nilai |\n|---|---|\n";
    $md .= "| Akurasi seluruh sel | " . eval_pct($o['accuracy']) . " (" . $o['cells'] . " sel) |\n";
    $md .= "| Akurasi BB dan TB | " . eval_pct($o['bbtb_accuracy']) . " |\n";
    $md .= "| Sel salah | " . $o['wrong'] . " |\n";
    $md .= "| Salah dan ditandai ragu (kader akan melihat) | " . $o['caught'] . " |\n";
    $md .= "| **Salah tanpa tanda (kesalahan diam-diam)** | **" . $o['silent'] . "** (" . eval_pct($o['silent_rate']) . " dari sel) |\n";
    $md .= "| Recall tanda ragu | " . eval_pct($o['flag_recall']) . " |\n";
    $md .= "| Beban review (baris ditandai) | " . $sum['rows_flagged'] . " dari " . $sum['rows_pred'] . " (" . eval_pct($o['burden']) . ") |\n";
    $md .= "| Baris kebenaran dasar tidak terbaca | " . $sum['rows_missed'] . " |\n";
    $md .= "| Baris tambahan (tidak ada di kebenaran dasar) | " . $sum['rows_extra'] . " |\n";
    $md .= "\n## Per kolom\n\n| Kolom | Sel | Benar | Akurasi | Salah ditandai | Salah diam-diam |\n|---|---|---|---|---|---|\n";
    foreach ($sum['fields'] as $f => $c) {
        $md .= '| ' . EVAL_FIELDS[$f]['label'] . ' | ' . $c['n'] . ' | ' . $c['ok'] . ' | ' . eval_pct($c['n'] ? $c['ok'] / $c['n'] : null) . ' | ' . $c['wrong_caught'] . ' | ' . $c['wrong_silent'] . " |\n";
    }
    $md .= "\n## Cara membaca\n\n";
    $md .= "- Kesalahan diam-diam adalah sel salah pada baris yang tidak ditandai, sehingga kader tidak akan memeriksanya. Angka ini yang paling perlu ditekan.\n";
    $md .= "- Beban review tinggi berarti banyak baris yang harus diperiksa kader; bandingkan dengan waktu mengetik manual.\n";
    $md .= "- Hasil hanya berlaku untuk foto dan penulis yang diuji. Laporkan jumlah penulis, kondisi foto, dan data sintetis vs asli secara jujur.\n";
    return $md;
}
