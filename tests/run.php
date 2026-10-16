<?php
declare(strict_types=1);

/**
 * Tes unit tanpa dependensi.   Jalankan:  php tests/run.php
 * Tidak butuh database untuk bagian logika murni (validasi, saran nama, tren, ekspor).
 */
if (PHP_SAPI !== 'cli') {
    exit('Jalankan lewat terminal.');
}
$_SERVER['REQUEST_METHOD'] = 'CLI';
require __DIR__ . '/../config/env.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/validation.php';
require __DIR__ . '/../includes/suggest.php';
require __DIR__ . '/../includes/trends.php';
require __DIR__ . '/../includes/two_pass.php';
require __DIR__ . '/../includes/eval_metrics.php';
require __DIR__ . '/../includes/xlsx.php';
require __DIR__ . '/../includes/pdf.php';
require __DIR__ . '/../includes/charts.php';

$pass = 0;
$fail = 0;
$current = '';
function section(string $n): void { global $current; $current = $n; echo "\n== $n ==\n"; }
function check(string $label, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok   $label\n"; }
    else { $fail++; echo "  GAGAL $label" . ($detail !== '' ? "  → $detail" : '') . "\n"; }
}
function same(string $label, mixed $expected, mixed $actual): void
{
    check($label, $expected === $actual, 'diharapkan ' . var_export($expected, true) . ', dapat ' . var_export($actual, true));
}

// ------------------------------------------------------------------
section('Validasi angka');
[$v, $e] = parse_decimal('9,5', 60.0, 2);
same('koma desimal Indonesia dibaca 9.5', 9.5, $v);
[$v, $e] = parse_decimal('98', 60.0, 2);
check('BB 98 ditolak (di atas batas keras)', $v === null && $e !== null);
[$v, $e] = parse_decimal('', 60.0, 2);
check('kosong tetap null tanpa error', $v === null && $e === null);
[$v, $e] = parse_decimal('abc', 60.0, 2);
check('bukan angka → error', $v === null && $e !== null);
[$v, $e] = parse_decimal('0', 60.0, 2);
check('nol ditolak (kosong bukan 0)', $v === null && $e !== null);

section('Validasi tanggal & periode');
[$d, $e] = parse_date_ymd('2025-02-30');
check('tanggal tidak ada (30 Feb) ditolak', $d === null && $e !== null);
[$d, $e] = parse_date_ymd('2999-01-01');
check('tanggal masa depan ditolak', $d === null && $e !== null);
[$d, $e] = parse_period('2026-13');
check('periode bulan 13 ditolak', $d === null && $e !== null);
same('periode valid', '2026-10', parse_period('2026-10')[0]);

section('Normalisasi baris OCR');
$row = normalize_ocr_row(['nama_peserta' => 'Budi', 'periode_pengukuran' => '2026-10', 'bb_kg' => 98, 'tb_pb_cm' => 82, 'needs_review' => false, 'uncertain_fields' => ['tb_pb_cm', 'ngawur']]);
check('BB 98 dikosongkan, bukan ditebak', $row['weight_kg'] === null);
check('baris ditandai perlu diperiksa', $row['needs_review'] === 1);
check('kolom ragu mencakup bb_kg (tidak valid) dan tb_pb_cm (dari model)', str_contains((string)$row['flag_fields'], 'bb_kg') && str_contains((string)$row['flag_fields'], 'tb_pb_cm'));
check('nama kolom asing diabaikan', !str_contains((string)$row['flag_fields'], 'ngawur'));
check('snapshot OCR tersimpan sebagai JSON', is_array(json_decode((string)$row['ocr_snapshot'], true)));

section('Jejak koreksi');
$snap = json_encode(['nomor_pendaftaran' => '003', 'nama_peserta' => 'M. Rizky', 'tanggal_lahir' => null, 'jenis_kelamin' => null, 'measurement_period' => '2026-10', 'weight_kg' => 9.0, 'height_cm' => null, 'head_circumference_cm' => null, 'lila_cm' => null, 'keterangan' => null]);
$res = validate_row(['nomor_pendaftaran' => '003', 'nama_peserta' => 'Muhammad Rizki', 'periode_pengukuran' => '2026-10', 'bb_kg' => '9,0', 'tb_pb_cm' => '78', 'ocr_snapshot' => $snap], false);
same('kolom yang berubah tercatat (nama dan TB)', 'nama_peserta,tb_pb_cm', $res['clean']['corrected_fields']);
$res2 = validate_row(['nomor_pendaftaran' => '003', 'nama_peserta' => 'M. Rizky', 'periode_pengukuran' => '2026-10', 'bb_kg' => '9.00', 'ocr_snapshot' => $snap], false);
same('9.00 vs 9.0 dianggap sama (tidak dihitung koreksi)', null, $res2['clean']['corrected_fields']);
$res3 = validate_row(['nama_peserta' => 'X', 'ocr_snapshot' => '{bukan json'], false);
same('snapshot rusak dibuang', null, $res3['clean']['ocr_snapshot']);

section('Aturan selisih dari catatan sebelumnya');
$prev = ['period' => '2026-09', 'weight_kg' => 9.0, 'height_cm' => 76.5];
$w = row_warnings(['measurement_period' => '2026-10', 'weight_kg' => 17.4, 'height_cm' => 78.0], $prev);
check('BB 17,4 setelah 9,0 ditandai', count(array_filter($w, fn($x) => $x['field'] === 'bb_kg')) === 1);
$w = row_warnings(['measurement_period' => '2026-10', 'weight_kg' => 90.0], $prev);
check('BB 90 memberi petunjuk "mungkin 9"', str_contains(implode(' ', array_column($w, 'msg')), 'Mungkin maksudnya 9'));
$w = row_warnings(['measurement_period' => '2026-10', 'weight_kg' => 9.4, 'height_cm' => 77.5], $prev);
same('perubahan wajar tidak ditandai', [], $w);
$w = row_warnings(['measurement_period' => '2026-10', 'weight_kg' => 7.5], $prev);
check('BB turun 1,5 kg ditandai', count($w) === 1);
$w = row_warnings(['measurement_period' => '2026-10', 'height_cm' => 70.0], $prev);
check('TB turun 6,5 cm ditandai', count($w) === 1);

// ------------------------------------------------------------------
section('Saran nama (roster)');
same('"M. Rizky" ≈ "Muhammad Rizki"', 1.0, name_similarity('M. Rizky', 'Muhammad Rizki'));
check('nama berbeda jauh tidak mirip', name_similarity('Budi Santoso', 'Rina Wulandari') < 0.5);
check('urutan kata dibalik tetap mirip', name_similarity('Rizki Muhammad', 'Muhammad Rizki') >= 0.9);
$roster = [
    1 => ['id' => 1, 'name' => 'Muhammad Rizki', 'ref' => '003', 'dob' => '2025-03-12', 'sex' => 'L', 'points' => []],
    2 => ['id' => 2, 'name' => 'Rina Wulandari', 'ref' => '015', 'dob' => '2025-08-30', 'sex' => 'P', 'points' => []],
];
$sg = suggest_peserta($roster, ['nama_peserta' => 'Muhamad Riski', 'tanggal_lahir' => null, 'nomor_pendaftaran' => null]);
check('salah ketik disarankan ke Rizki', count($sg) === 1 && $sg[0]['id'] === 1);
same('yang sudah cocok persis tidak disarankan lagi', [], suggest_peserta($roster, ['nama_peserta' => 'Apa saja', 'nomor_pendaftaran' => '003', 'tanggal_lahir' => null]));
same('nama asing → tanpa saran', [], suggest_peserta($roster, ['nama_peserta' => 'Zulkifli Hasan', 'tanggal_lahir' => null, 'nomor_pendaftaran' => null]));
same('umur dalam bulan penuh (awal periode)', 17, age_months('2025-03-12', '2026-09'));
same('umur Okt 2026 untuk lahir 12 Mar 2025', 18, age_months('2025-03-12', '2026-10'));
same('lahir tanggal 1 dihitung pas', 19, age_months('2025-03-01', '2026-10'));
same('umur null jika lahir setelah periode', null, age_months('2026-12-01', '2026-09'));

// ------------------------------------------------------------------
section('Tren berat badan');
$pt = fn(string $per, ?float $w) => ['period' => $per, 'weight_kg' => $w, 'height_cm' => null, 'head_circumference_cm' => null, 'lila_cm' => null, 'sesi_foto_id' => 1];
$rizki = ['2026-08' => $pt('2026-08', 9.2), '2026-09' => $pt('2026-09', 9.0), '2026-10' => $pt('2026-10', 9.0)];
$s = weight_signal($rizki);
same('Rizki: tidak naik 2 kali → prioritas', 'prioritas', $s['level']);
same('streak 2', 2, $s['streak']);
check('alasan terbaca', str_contains($s['reason'], 'tidak naik 2 bulan berturut-turut'));
$dewi = ['2026-08' => $pt('2026-08', 7.5), '2026-09' => $pt('2026-09', 7.8), '2026-10' => $pt('2026-10', 7.8)];
$s = weight_signal($dewi);
same('Dewi: tidak naik 1 kali → cek', 'cek', $s['level']);
$naik = ['2026-08' => $pt('2026-08', 8.0), '2026-09' => $pt('2026-09', 8.3)];
same('naik → tanpa penanda', null, weight_signal($naik)['level']);
same('satu catatan → tidak ada sinyal', null, weight_signal(['2026-10' => $pt('2026-10', 9.0)]));
$turun = ['2026-09' => $pt('2026-09', 9.0), '2026-10' => $pt('2026-10', 8.6)];
$s = weight_signal($turun);
check('turun menyebut selisih', $s['level'] === 'cek' && str_contains($s['reason'], 'turun 0,4 kg'));
$gap = ['2026-07' => $pt('2026-07', 9.0), '2026-10' => $pt('2026-10', 9.0)];
check('jeda bulan disebut (bukan dianggap bulan berturut-turut)', str_contains(weight_signal($gap)['reason'], 'Juli 2026'));
$null = ['2026-08' => $pt('2026-08', 9.0), '2026-09' => $pt('2026-09', null), '2026-10' => $pt('2026-10', 9.5)];
same('catatan tanpa BB dilewati, bukan dianggap 0', null, weight_signal($null)['level']);
$trunc = weight_signal($rizki, '2026-09');
same('dihitung sampai periode yang dipilih', 'cek', $trunc['level']);

$rosterT = [
    1 => ['id' => 1, 'name' => 'Muhammad Rizki', 'ref' => '003', 'dob' => '2025-03-12', 'sex' => 'L', 'points' => $rizki],
    2 => ['id' => 2, 'name' => 'Dewi Lestari', 'ref' => '004', 'dob' => '2025-11-21', 'sex' => 'P', 'points' => $dewi],
    3 => ['id' => 3, 'name' => 'Siti Aminah', 'ref' => '001', 'dob' => '2025-02-14', 'sex' => 'P', 'points' => ['2026-08' => $pt('2026-08', 8.7), '2026-09' => $pt('2026-09', 9.0), '2026-10' => $pt('2026-10', 9.2)]],
    4 => ['id' => 4, 'name' => 'Rina Wulandari', 'ref' => '015', 'dob' => '2025-08-30', 'sex' => 'P', 'points' => ['2026-08' => $pt('2026-08', 8.4), '2026-09' => $pt('2026-09', 8.5)]],
];
$att = attention_for_period($rosterT, '2026-10');
same('periode Oktober: 3 anak ditimbang', 3, $att['measured']);
same('1 prioritas', 1, $att['counts']['prioritas']);
same('1 cek', 1, $att['counts']['cek']);
same('1 naik', 1, $att['rising']);
same('prioritas diurutkan paling atas', 'Muhammad Rizki', $att['items'][0]['peserta']['name']);
check('Rina (tanpa catatan Oktober) tidak dihitung dan tidak dianggap absen', $att['measured'] === 3);
same('periode kosong → hasil kosong', [], attention_for_period($rosterT, null)['items']);
$msg = parent_message_draft('Posyandu Demo', 'Muhammad Rizki', weight_signal($rizki));
check('draf pesan tanpa kata diagnosis', !preg_match('/stunting|gizi buruk|diagnosis/i', $msg));
check('draf pesan memuat placeholder untuk diisi petugas', str_contains($msg, '[hari dan jam]'));


// ------------------------------------------------------------------
section('Dua kali baca (gabungan)');
$a = [['nomor_pendaftaran' => '001', 'nama_peserta' => 'Budi', 'bb_kg' => 11.4, 'tb_pb_cm' => 82], ['nomor_pendaftaran' => '002', 'nama_peserta' => 'Dewi', 'bb_kg' => 7.8, 'tb_pb_cm' => 68]];
$b = [['nomor_pendaftaran' => '001', 'nama_peserta' => 'Budi', 'bb_kg' => 17.4, 'tb_pb_cm' => 82], ['nomor_pendaftaran' => '002', 'nama_peserta' => 'Dewi', 'bb_kg' => 7.8, 'tb_pb_cm' => 68]];
$m = merge_two_readings($a, $b);
check('BB berbeda (11,4 vs 17,4) ditandai ragu', !empty($m[0]['needs_review']) && in_array('bb_kg', $m[0]['uncertain_fields'], true));
check('alasan menyebut kedua nilai', str_contains($m[0]['review_reason'], '11,4') && str_contains($m[0]['review_reason'], '17,4'));
same('nilai yang dipakai tetap bacaan pertama (tidak menebak)', 11.4, $m[0]['bb_kg']);
check('baris yang sama tidak ditandai', empty($m[1]['needs_review']));
$m2 = merge_two_readings($a, array_slice($b, 0, 1));
check('jumlah baris beda → baris tak terpasangkan ditandai', !empty($m2[1]['needs_review']));
$m3 = merge_two_readings([['nama_peserta' => 'M. Rizky', 'bb_kg' => 9.0]], [['nama_peserta' => 'Muhammad Rizki', 'bb_kg' => 9.0]]);
check('ejaan nama beda tapi mirip tidak dianggap konflik', empty($m3[0]['needs_review']));
$m4 = merge_two_readings([['nama_peserta' => 'Siti', 'tb_pb_cm' => null]], [['nama_peserta' => 'Siti', 'tb_pb_cm' => 73]]);
check('kosong vs terisi ditandai ragu', !empty($m4[0]['needs_review']));

section('Metrik evaluasi');
$truth = [
    ['nomor_pendaftaran' => '001', 'nama_peserta' => 'Siti Aminah', 'tanggal_lahir' => '2025-02-14', 'jenis_kelamin' => 'P', 'periode_pengukuran' => '2026-10', 'bb_kg' => '9.2', 'tb_pb_cm' => '73', 'lingkar_kepala_cm' => '', 'lila_cm' => ''],
    ['nomor_pendaftaran' => '002', 'nama_peserta' => 'Budi Santoso', 'tanggal_lahir' => '2024-06-03', 'jenis_kelamin' => 'L', 'periode_pengukuran' => '2026-10', 'bb_kg' => '11.4', 'tb_pb_cm' => '82', 'lingkar_kepala_cm' => '', 'lila_cm' => ''],
    ['nomor_pendaftaran' => '003', 'nama_peserta' => 'Rina', 'tanggal_lahir' => '2025-08-30', 'jenis_kelamin' => 'P', 'periode_pengukuran' => '2026-10', 'bb_kg' => '8.4', 'tb_pb_cm' => '70', 'lingkar_kepala_cm' => '', 'lila_cm' => ''],
];
$pred = [
    normalize_ocr_row(['nomor_pendaftaran' => '001', 'nama_peserta' => 'Siti Aminah', 'tanggal_lahir' => '2025-02-14', 'jenis_kelamin' => 'P', 'periode_pengukuran' => '2026-10', 'bb_kg' => 9.2, 'tb_pb_cm' => 73, 'needs_review' => false]),
    // BB salah (17,4) TANPA ditandai → kesalahan diam-diam
    normalize_ocr_row(['nomor_pendaftaran' => '002', 'nama_peserta' => 'Budi Santoso', 'tanggal_lahir' => '2024-06-03', 'jenis_kelamin' => 'L', 'periode_pengukuran' => '2026-10', 'bb_kg' => 17.4, 'tb_pb_cm' => 82, 'needs_review' => false]),
];
$sum = eval_compare($truth, $pred);
$o = eval_overall($sum);
same('27 sel dievaluasi (3 baris × 9 kolom)', 27, $o['cells']);
same('BB: 2 salah diam-diam (Budi salah baca tanpa tanda + baris Rina hilang)', 2, $sum['fields']['bb_kg']['wrong_silent']);
same('BB: hanya 1 dari 3 sel benar', 1, $sum['fields']['bb_kg']['ok']);
same('baris yang hilang tercatat', 1, $sum['rows_missed']);
check('baris hilang dihitung sebagai kesalahan diam-diam (tidak terlihat kader)', $o['silent'] === 1 + 9, (string)$o['silent']);
$pred[1] = normalize_ocr_row(['nomor_pendaftaran' => '002', 'nama_peserta' => 'Budi Santoso', 'tanggal_lahir' => '2024-06-03', 'jenis_kelamin' => 'L', 'periode_pengukuran' => '2026-10', 'bb_kg' => 17.4, 'tb_pb_cm' => 82, 'needs_review' => true]);
$sum2 = eval_compare($truth, $pred);
same('jika baris ditandai, salah dihitung "ditandai" bukan diam-diam', 1, $sum2['fields']['bb_kg']['wrong_caught']);
same('ejaan nama dinormalisasi (M. Rizky = Muhammad Rizki)', true, eval_same('name', 'Muhammad Rizki', 'M. Rizky'));
same('angka: 9.0 sama dengan 9', true, eval_same('number', '9.0', 9));
same('kosong sama dengan kosong', true, eval_same('number', '', null));
same('kosong vs nilai berbeda', false, eval_same('number', '', 9.0));
check('laporan markdown memuat angka kunci', str_contains(eval_markdown($sum2, ['mode' => 'uji', 'samples' => 1]), 'kesalahan diam-diam'));

section('Penulis XLSX');
if (SimpleXlsx::available()) {
    $x = new SimpleXlsx();
    $head = $x->style(['bold' => true, 'color' => 'FFFFFF', 'fill' => '1E2D87', 'border' => 'all', 'align' => 'center']);
    $num = $x->style(['numFmt' => '0.0#', 'align' => 'right']);
    same('gaya yang sama dipakai ulang (tidak duplikat)', $head, $x->style(['bold' => true, 'color' => 'FFFFFF', 'fill' => '1E2D87', 'border' => 'all', 'align' => 'center']));
    $x->addSheet('Data', [[['v' => 'Nama', 's' => $head], ['v' => 'BB', 's' => $head]], ['=HYPERLINK("http://jahat")', ['v' => 9.5, 's' => $num]], ['Budi & <Dewi>', null]],
        ['freezeRows' => 1, 'filter' => 'A1:B3', 'widths' => [20, 8], 'merges' => ['A5:B5'], 'landscape' => true, 'printTitleRows' => [1, 1], 'footer' => '&CHalaman &P dari &N', 'showGrid' => false]);
    $tmp = tempnam(sys_get_temp_dir(), 'kcf');
    check('berkas XLSX tertulis', $x->save($tmp) && filesize($tmp) > 500);
    $z = new ZipArchive();
    check('XLSX dapat dibuka sebagai zip', $z->open($tmp) === true);
    $sheet = (string)$z->getFromName('xl/worksheets/sheet1.xml');
    check('teks berawalan = disimpan sebagai teks (bukan rumus)', str_contains($sheet, 't="inlineStr"') && !str_contains($sheet, '<f>'));
    check('angka disimpan sebagai angka', str_contains($sheet, '<v>9.5</v>'));
    check('karakter XML di-escape', str_contains($sheet, 'Budi &amp; &lt;Dewi&gt;'));
    check('baris header membeku & filter aktif', str_contains($sheet, 'state="frozen"') && str_contains($sheet, '<autoFilter ref="A1:B3"/>'));
    check('sel gabungan dan pengaturan cetak ada', str_contains($sheet, '<mergeCell ref="A5:B5"/>') && str_contains($sheet, 'orientation="landscape"') && str_contains($sheet, 'fitToWidth="1"'));
    check('gridline dimatikan', str_contains($sheet, 'showGridLines="0"'));
    $wbx = (string)$z->getFromName('xl/workbook.xml');
    check('judul berulang saat cetak terdaftar', str_contains($wbx, '_xlnm.Print_Titles') && str_contains($wbx, '$1:$1'));
    $st = (string)$z->getFromName('xl/styles.xml');
    check('format angka kustom terdaftar', str_contains($st, 'formatCode="0.0#"'));
    check('XML sheet valid', @simplexml_load_string($sheet) !== false);
    foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/styles.xml', '_rels/.rels', 'xl/_rels/workbook.xml.rels', 'docProps/core.xml', 'docProps/app.xml'] as $part) {
        check("bagian $part ada dan valid", $z->getFromName($part) !== false && @simplexml_load_string((string)$z->getFromName($part)) !== false);
    }
    $z->close();
    @unlink($tmp);
    same('huruf kolom 0 → A', 'A', SimpleXlsx::col(0));
    same('huruf kolom 26 → AA', 'AA', SimpleXlsx::col(26));
    same('nomor seri tanggal 2025-03-12', 45728, SimpleXlsx::dateSerial('2025-03-12'));
    same('tanggal tidak valid → null', null, SimpleXlsx::dateSerial('bukan'));
} else {
    echo "  (lewati: ekstensi zip tidak aktif)\n";
}

section('Penulis PDF');
$pd = new SimplePdf('Uji', true);
$pd->addPage();
$pd->text(14, 20, 'Halo (dunia) \\ é', ['size' => 12, 'bold' => true]);
$pd->addPage();
check('lebar teks bold lebih besar dari biasa', $pd->textWidth('Posyandu', 10, true) > $pd->textWidth('Posyandu', 10, false));
check('lebar teks sebanding ukuran huruf', abs($pd->textWidth('Abc', 20) - 2 * $pd->textWidth('Abc', 10)) < 0.01);
$wrapped = $pd->wrap('Ini kalimat panjang yang harus dibungkus ke beberapa baris karena lebarnya terbatas', 40, 10);
check('teks panjang dibungkus ke banyak baris', count($wrapped) >= 3);
check('tiap baris muat dalam lebar', max(array_map(fn($l) => $pd->textWidth($l, 10), $wrapped)) <= 40.01);
check('kata sangat panjang dipotong (tidak meluap)', max(array_map(fn($l) => $pd->textWidth($l, 10), $pd->wrap(str_repeat('W', 60), 30, 10))) <= 30.01);
$bin = $pd->output(function (SimplePdf $p, int $n, int $t) { $p->text(10, 200, "Halaman $n dari $t"); });
check('PDF berawalan %PDF dan berakhiran %%EOF', str_starts_with($bin, '%PDF-1.4') && str_ends_with(trim($bin), '%%EOF'));
same('dua halaman tercatat', 2, preg_match_all('/\/Type \/Page /', $bin));
check('xref menunjuk offset yang benar', (function () use ($bin) {
    preg_match('/startxref\n(\d+)/', $bin, $m);
    return substr($bin, (int)$m[1], 4) === 'xref';
})());

section('Jenis formulir');
check('tiga jenis formulir terdaftar', count(form_registry()) === 3);
same('kunci asing jatuh ke buku balita', FORM_BALITA, form_key('sembarang'));
check('hanya buku balita yang mendukung tren', form_def(FORM_BALITA)['trend'] && !form_def(FORM_IBU_HAMIL)['trend'] && !form_def(FORM_LANSIA)['trend']);
check('formulir contoh ditandai belum terverifikasi', form_def(FORM_BALITA)['verified'] && !form_def(FORM_IBU_HAMIL)['verified']);
$r = validate_row(['nama_peserta' => 'Ibu A', 'periode_pengukuran' => '2026-10', 'usia_kehamilan_minggu' => '24', 'bb_kg' => '62,5', 'td_sistolik' => '110', 'td_diastolik' => '70'], true, FORM_IBU_HAMIL);
check('ibu hamil: nilai wajar diterima', $r['errors'] === [] && $r['clean']['gestational_weeks'] == 24 && $r['clean']['bp_systolic'] == 110 && abs($r['clean']['weight_kg'] - 62.5) < 0.001);
$r = validate_row(['nama_peserta' => 'Ibu B', 'periode_pengukuran' => '2026-10', 'td_sistolik' => '1100', 'usia_kehamilan_minggu' => '120'], true, FORM_IBU_HAMIL);
check('ibu hamil: salah ketik tekanan darah dan usia kehamilan ditolak', count($r['errors']) === 2 && $r['clean']['bp_systolic'] === null);
$r = validate_row(['nama_peserta' => 'X', 'periode_pengukuran' => '2026-10', 'bb_kg' => '70'], true, FORM_BALITA);
check('balita: BB 70 kg ditolak (batas keras balita)', count($r['errors']) === 1);
$r = validate_row(['nama_peserta' => 'X', 'periode_pengukuran' => '2026-10', 'bb_kg' => '70'], true, FORM_LANSIA);
check('lansia: BB 70 kg diterima tanpa peringatan kisaran balita', $r['errors'] === [] && row_warnings(['weight_kg' => 70, 'measurement_period' => '2026-10'], null, FORM_LANSIA) === []);
check('balita: BB 40 kg memang di luar kisaran umum balita', count(row_warnings(['weight_kg' => 40.0], null, FORM_BALITA)) === 1);
$o = normalize_ocr_row(['nama_peserta' => 'Lansia A', 'periode_pengukuran' => '2026-10', 'bb_kg' => 480, 'td_sistolik' => 130, 'gula_darah_mgdl' => 110, 'usia_kehamilan_minggu' => 20], FORM_LANSIA);
check('lansia: BB 480 dikosongkan dan ditandai', $o['weight_kg'] === null && str_contains((string)$o['flag_fields'], 'bb_kg'));
check('lansia: kolom milik formulir lain diabaikan (usia kehamilan)', $o['gestational_weeks'] === null && $o['blood_glucose'] == 110 && $o['bp_systolic'] == 130);
check('kolom ibu hamil tidak ikut pada balita', normalize_ocr_row(['nama_peserta' => 'A', 'periode_pengukuran' => '2026-10', 'td_sistolik' => 120])['bp_systolic'] === null);

section('Tanggal lahir per jenis formulir');
check('balita: lahir 1990 ditolak (tahun tidak wajar)', validate_row(['nama_peserta' => 'A', 'tanggal_lahir' => '1990-05-10', 'periode_pengukuran' => '2026-10'], true, FORM_BALITA)['errors'] !== []);
check('ibu hamil: lahir 1990 diterima', validate_row(['nama_peserta' => 'A', 'tanggal_lahir' => '1990-05-10', 'periode_pengukuran' => '2026-10'], true, FORM_IBU_HAMIL)['errors'] === []);
check('ibu hamil: lahir 1920 ditolak', validate_row(['nama_peserta' => 'A', 'tanggal_lahir' => '1920-05-10', 'periode_pengukuran' => '2026-10'], true, FORM_IBU_HAMIL)['errors'] !== []);
check('lansia: lahir 1952 diterima', validate_row(['nama_peserta' => 'A', 'tanggal_lahir' => '1952-05-10', 'periode_pengukuran' => '2026-10'], true, FORM_LANSIA)['errors'] === []);
check('OCR lansia: tanggal lahir 1958 tidak dikosongkan', normalize_ocr_row(['nama_peserta' => 'A', 'tanggal_lahir' => '1958-03-12', 'periode_pengukuran' => '2026-10'], FORM_LANSIA)['tanggal_lahir'] === '1958-03-12');

section('Nama kembar');
$mk = fn(int $id, string $name, ?string $ref, ?string $dob) => ['id' => $id, 'name' => $name, 'ref' => $ref, 'dob' => $dob, 'sex' => null, 'points' => []];
$ros = [1 => $mk(1, 'Siti Aminah', '001', '2025-02-14'), 2 => $mk(2, 'Siti Aminah', '016', '2025-09-09'), 3 => $mk(3, 'Budi Santoso', '002', '2024-06-03')];
same('dua anak bernama sama terdeteksi', 2, count(same_name_peserta($ros, 'siti aminah')));
same('ejaan lain tetap dianggap nama sama', 2, count(same_name_peserta($ros, 'Siti Aminah.')));
same('himpunan nama kembar memuat Siti saja', [normalize_name('Siti Aminah') => 2], duplicate_name_set($ros));
same('tanpa no. dan tgl lahir → ambigu', 'ambiguous', name_ambiguity($ros, ['nama_peserta' => 'Siti Aminah', 'nomor_pendaftaran' => null, 'tanggal_lahir' => null])['status']);
same('dengan no. daftar yang cocok → dikenali', 'known', name_ambiguity($ros, ['nama_peserta' => 'Siti Aminah', 'nomor_pendaftaran' => '016', 'tanggal_lahir' => null])['status']);
same('dengan tgl lahir yang cocok (tanpa no.) → dikenali', 'known', name_ambiguity($ros, ['nama_peserta' => 'Siti Aminah', 'nomor_pendaftaran' => null, 'tanggal_lahir' => '2025-02-14'])['status']);
same('identitas baru dengan nama sama → anak berbeda', 'other_child', name_ambiguity($ros, ['nama_peserta' => 'Siti Aminah', 'nomor_pendaftaran' => '099', 'tanggal_lahir' => null])['status']);
same('nama asing → none', 'none', name_ambiguity($ros, ['nama_peserta' => 'Zulkifli', 'nomor_pendaftaran' => null, 'tanggal_lahir' => null])['status']);
check('label menambah pembeda hanya untuk nama kembar', str_contains(person_label($ros[1], duplicate_name_set($ros)), 'no. 001') && person_label($ros[3], duplicate_name_set($ros)) === 'Budi Santoso');
$rowsC = [
    'a' => ['nama_peserta' => 'Rudi', 'nomor_pendaftaran' => null, 'tanggal_lahir' => null, 'measurement_period' => '2026-11'],
    'b' => ['nama_peserta' => 'Rudi', 'nomor_pendaftaran' => null, 'tanggal_lahir' => null, 'measurement_period' => '2026-11'],
    'c' => ['nama_peserta' => 'Sari', 'nomor_pendaftaran' => '5', 'tanggal_lahir' => null, 'measurement_period' => '2026-11'],
    'd' => ['nama_peserta' => 'Sari', 'nomor_pendaftaran' => '6', 'tanggal_lahir' => null, 'measurement_period' => '2026-11'],
    'e' => ['nama_peserta' => 'Tia', 'nomor_pendaftaran' => '7', 'tanggal_lahir' => '2025-01-01', 'measurement_period' => '2026-11'],
    'f' => ['nama_peserta' => 'Tia', 'nomor_pendaftaran' => '7', 'tanggal_lahir' => '2025-01-01', 'measurement_period' => '2026-11'],
    'g' => ['nama_peserta' => 'Rudi', 'nomor_pendaftaran' => null, 'tanggal_lahir' => null, 'measurement_period' => '2026-12'],
];
$dups = session_duplicate_errors($rowsC);
check('dua baris nama sama tanpa pembeda ditandai (a, b)', isset($dups['a'], $dups['b']) && str_contains($dups['a'][0], 'baris 2'));
check('anak kembar dengan no. daftar berbeda lolos (c, d)', !isset($dups['c']) && !isset($dups['d']));
check('baris ganda persis ditandai (e, f)', isset($dups['e'], $dups['f']) && str_contains($dups['e'][0], 'tampak ganda'));
check('periode berbeda bukan duplikat (g)', !isset($dups['g']));

section('Grafik');
$svg = weight_chart_svg($rizki);
check('grafik berisi SVG dengan label aksesibel', str_contains($svg, 'role="img"') && str_contains($svg, 'tidak naik'));
check('grafik memakai persegi (bukan warna saja) untuk titik tidak naik', substr_count($svg, '<rect') === 2);
same('tanpa data BB → kosong', '', weight_chart_svg(['2026-10' => $pt('2026-10', null)]));
check('satu titik tetap digambar', str_contains(weight_chart_svg(['2026-10' => $pt('2026-10', 9.0)]), '<circle'));

echo "\n----------------------------------------\n";
echo "Lulus: $pass   Gagal: $fail\n";
exit($fail > 0 ? 1 : 0);
