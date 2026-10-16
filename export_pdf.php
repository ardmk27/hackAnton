<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/pdf.php';
require __DIR__ . '/includes/export.php';

/**
 * Unduh laporan PDF (A4 lanskap): kop, ringkasan, tabel dengan baris judul berulang, catatan, dan kolom tanda tangan.
 * Hanya data TERKONFIRMASI milik Posyandu yang login. Dibuat langsung di server tanpa pustaka PDF.
 *   ?sesi_id=N | ?periode=YYYY-MM[&form=KUNCI]
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

$def = $d['def'];
$cols = $d['columns'];
$att = $d['attention'];
$trend = !empty($def['trend']);
$title = $d['form'] === FORM_BALITA ? 'Laporan Penimbangan dan Pengukuran Balita' : 'Laporan ' . $def['label'];
$stamp = date('d/m/Y H:i') . ' WIB';

$pdf = new SimplePdf($title . ' ' . $d['label']);
$L = 14.0;
$W = $pdf->w - 2 * $L;          // lebar isi
$bottom = $pdf->h - 20.0;       // batas bawah isi (ruang untuk footer)
$INK = '151A26';
$MUTED = '4B5565';
$BLUE = '2B3FB0';
$NAVY = '1E2D87';

$brand = function (float $x, float $y) use ($pdf, $BLUE): void {
    $pdf->rect($x, $y, 10, 11.5, $BLUE, null, 0.2, 2.2);
    $pdf->line($x + 2.6, $y + 4.2, $x + 7.4, $y + 4.2, 'FFFFFF', 0.35);
    $pdf->polyline([[$x + 2.8, $y + 7.6], [$x + 4.6, $y + 9.4], [$x + 7.6, $y + 6.0]], 'FFFFFF', 0.7);
};

// ---------- halaman 1: kop ----------
$pdf->addPage();
$brand($L, 14);
$pdf->text($L + 14, 21.5, $title, ['size' => 16, 'bold' => true]);
$pdf->text($L + 14, 27.5, 'Periode ' . $d['label'] . '   |   ' . $def['label'], ['size' => 9.5, 'color' => $MUTED]);
$pdf->text($L + $W, 20.5, $p['name'], ['size' => 10.5, 'bold' => true, 'align' => 'R']);
$pdf->text($L + $W, 25.5, 'ID ' . $p['posyandu_code'], ['size' => 8.5, 'color' => $MUTED, 'align' => 'R']);
$pdf->text($L + $W, 30, 'Dicetak ' . $stamp, ['size' => 8.5, 'color' => $MUTED, 'align' => 'R']);
$pdf->line($L, 34, $L + $W, 34, $INK, 0.7);

// ---------- ringkasan ----------
$kpis = [[(string)count($d['rows']), 'catatan terkonfirmasi'], [(string)$d['unique_children'], $def['subject'] . ' (identitas pasti)']];
if ($trend) {
    $kpis[] = [(string)(int)$att['rising'], 'BB naik dari catatan sebelumnya'];
    $kpis[] = [(string)(int)$att['not_rising'], 'BB tidak naik (' . (int)$att['counts']['prioritas'] . ' prioritas, ' . (int)$att['counts']['cek'] . ' cek)'];
}
$gap = 4.0;
$bw = ($W - $gap * (count($kpis) - 1)) / count($kpis);
foreach ($kpis as $i => [$val, $lab]) {
    $bx = $L + $i * ($bw + $gap);
    $pdf->rect($bx, 38, $bw, 16, 'FFFFFF', 'C3CAD6', 0.25, 2);
    $pdf->text($bx + 4, 46.5, $val, ['size' => 16, 'bold' => true]);
    $pdf->text($bx + 4, 51.5, $lab, ['size' => 8, 'color' => $MUTED]);
}

// ---------- tabel ----------
$sumW = array_sum(array_column($cols, 'w'));
$cw = array_map(fn($c) => $c['w'] / $sumW * $W, $cols);
$size = 8.5;
$lh = 4.0;
$pad = 1.8;
$headH = 9.0;

$drawHeader = function (float $y) use ($pdf, $cols, $cw, $L, $W, $NAVY): float {
    // Tinggi pita mengikuti judul kolom terpanjang agar tidak meluap.
    $wrapped = [];
    $maxL = 1;
    foreach ($cols as $i => $c) {
        $wrapped[$i] = $pdf->wrap($c['l'], $cw[$i] - 3, 8, true);
        $maxL = max($maxL, count($wrapped[$i]));
    }
    $hh = max(9.0, $maxL * 3.6 + 3.6);
    $pdf->rect($L, $y, $W, $hh, $NAVY);
    $x = $L;
    foreach ($cols as $i => $c) {
        $n = count($wrapped[$i]);
        foreach ($wrapped[$i] as $li => $ln) {
            $by = $y + ($hh - $n * 3.6) / 2 + 2.9 + $li * 3.6;
            $pdf->text($x, $by, $ln, ['size' => 8, 'bold' => true, 'color' => 'FFFFFF', 'align' => $c['k'] === 'nama_peserta' ? 'L' : 'C', 'w' => $cw[$i]]);
        }
        $x += $cw[$i];
    }
    return $y + $hh;
};

$y = $drawHeader(60);
if (!$d['rows']) {
    $pdf->text($L + 2, $y + 8, 'Tidak ada data terkonfirmasi untuk cakupan ini.', ['size' => 9, 'color' => $MUTED]);
    $y += 14;
}
foreach ($d['rows'] as $ri => $r) {
    // hitung tinggi baris dari jumlah baris teks terpanjang
    $cells = [];
    $maxLines = 1;
    foreach ($cols as $i => $c) {
        $txt = export_cell_text($c, $r);
        $isText = $c['t'] === 'text';
        $lines = $isText ? $pdf->wrap($txt, $cw[$i] - 2 * $pad, $size) : [$txt];
        $cells[$i] = $lines;
        $maxLines = max($maxLines, count($lines));
    }
    $rowH = max(6.6, $maxLines * $lh + 2.6);
    if ($y + $rowH > $bottom) {
        $pdf->addPage();
        $brand($L, 12);
        $pdf->text($L + 14, 19, $title . ' (lanjutan)', ['size' => 11, 'bold' => true]);
        $pdf->text($L + $W, 19, 'Periode ' . $d['label'], ['size' => 9, 'color' => $MUTED, 'align' => 'R']);
        $y = $drawHeader(26);
    }
    if ($ri % 2 === 1) {
        $pdf->rect($L, $y, $W, $rowH, 'F6F8FB');
    }
    $x = $L;
    foreach ($cols as $i => $c) {
        $lines = $cells[$i];
        $baseY = $y + ($rowH - count($lines) * $lh) / 2 + 3.0;
        $o = ['size' => $size];
        $align = 'L';
        if (in_array($c['t'], ['num', 'int', 'delta'], true)) {
            $align = 'R';
        } elseif (in_array($c['t'], ['date', 'level'], true) || in_array($c['k'], ['nomor_pendaftaran', 'jenis_kelamin'], true)) {
            $align = 'C';
        }
        if ($c['t'] === 'delta' && $r['_delta'] !== null && $r['_delta'] <= 0) {
            $o['bold'] = true;
            $o['color'] = '8C1D17';
        }
        if ($c['t'] === 'level' && $lines[0] !== '') {
            $tint = $lines[0] === 'Prioritas' ? 'FBE3E1' : 'FFEFC7';
            $ink = $lines[0] === 'Prioritas' ? '8C1D17' : '7A4B00';
            $tw = $pdf->textWidth($lines[0], 7.8, true) + 4;
            $pdf->rect($x + ($cw[$i] - $tw) / 2, $y + ($rowH - 4.6) / 2, $tw, 4.6, $tint, null, 0.2, 2.3);
            $pdf->text($x, $y + $rowH / 2 + 1.1, $lines[0], ['size' => 7.8, 'bold' => true, 'color' => $ink, 'align' => 'C', 'w' => $cw[$i]]);
        } else {
            foreach ($lines as $li => $ln) {
                $o += ['align' => $align, 'w' => $cw[$i] - ($align === 'C' ? 0 : $pad)];
                $px = $align === 'L' ? $x + $pad : $x;
                $pdf->text($px, $baseY + $li * $lh, $ln, $o);
            }
        }
        $x += $cw[$i];
    }
    $pdf->line($L, $y + $rowH, $L + $W, $y + $rowH, 'DCE1E8', 0.2);
    $y += $rowH;
}

// ---------- catatan ----------
$y += 5;
$notes = [];
if ($trend) {
    $notes[] = 'Penanda "tidak naik" membandingkan berat badan dengan catatan sebelumnya anak yang sama untuk membantu memilih kunjungan. Ini bukan diagnosis dan perlu ditinjau tenaga kesehatan. Anak yang tidak tercantum pada periode ini tidak otomatis berarti tidak hadir.';
}
if (!$def['verified']) {
    $notes[] = 'Kolom formulir ini adalah contoh awal. Cocokkan dengan buku di Posyandu Anda sebelum dipakai untuk data nyata.';
}
$notes[] = 'Data berasal dari foto buku pencatatan yang telah diperiksa dan dikonfirmasi kader. Dihasilkan oleh Rakit.';
$noteLines = [];
foreach ($notes as $n) {
    foreach ($pdf->wrap($n, $W, 8) as $ln) {
        $noteLines[] = $ln;
    }
    $noteLines[] = null;
}
$needed = count($noteLines) * 3.8 + 44;
if ($y + $needed > $bottom) {
    $pdf->addPage();
    $y = 20;
}
foreach ($noteLines as $ln) {
    if ($ln !== null) {
        $pdf->text($L, $y, $ln, ['size' => 8, 'color' => $MUTED]);
    }
    $y += 3.8;
}

// ---------- tanda tangan ----------
$y += 6;
foreach ([['Kader Posyandu', $L + 8], ['Bidan / Petugas Puskesmas', $L + $W - 88]] as [$lab, $sx]) {
    $pdf->text($sx, $y, $lab, ['size' => 9, 'color' => $INK, 'align' => 'L']);
    $pdf->line($sx, $y + 24, $sx + 80, $y + 24, $INK, 0.3);
    $pdf->text($sx, $y + 28, 'Nama dan tanda tangan', ['size' => 7.5, 'color' => $MUTED]);
}

$footer = function (SimplePdf $pdf, int $n, int $total) use ($L, $W, $p, $def, $stamp, $MUTED): void {
    $fy = $pdf->h - 11;
    $pdf->line($L, $fy - 4, $L + $W, $fy - 4, 'C3CAD6', 0.25);
    $pdf->text($L, $fy, 'Rakit   |   ' . $p['name'] . '   |   ' . $def['short'], ['size' => 7.5, 'color' => $MUTED]);
    $pdf->text($L, $fy, 'Halaman ' . $n . ' dari ' . $total, ['size' => 7.5, 'color' => $MUTED, 'align' => 'C', 'w' => $W]);
    $pdf->text($L + $W, $fy, $stamp, ['size' => 7.5, 'color' => $MUTED, 'align' => 'R']);
};
$pdf->download('kader-cukup-foto_' . $d['name'] . '.pdf', $footer);
