<?php
declare(strict_types=1);

/**
 * Fixture uji: tambahkan anak ke-2 bernama "Siti Aminah" (no. 016) ke Posyandu bertanda kode, lalu buat satu sesi DRAF
 * berisi baris-baris yang memicu penanganan nama kembar. Mencetak id sesi draf.
 *   php tests/fixtures/make_dupes.php PSY-XXXXXXXX
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI saja.');
}
$_SESSION = [];
require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/helpers.php';
require __DIR__ . '/../../includes/validation.php';
require __DIR__ . '/../../includes/measurements.php';

$pdo = db();
$st = $pdo->prepare('SELECT id FROM posyandu WHERE posyandu_code = ?');
$st->execute([$argv[1] ?? '']);
$pid = (int)$st->fetchColumn();
if (!$pid) {
    fwrite(STDERR, "Posyandu tidak ditemukan\n");
    exit(1);
}
$pdo->beginTransaction();
$pdo->prepare("INSERT INTO peserta (posyandu_id, local_reference, name, date_of_birth, sex, form_type) VALUES (?, '016', 'Siti Aminah', '2025-09-09', 'P', 'buku_penimbangan_balita')")->execute([$pid]);
$kembarId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO sesi_foto (posyandu_id, activity_date, form_type, status, ocr_engine, confirmed_at, notes) VALUES (?, '2026-09-07', 'buku_penimbangan_balita', 'confirmed', 'demo', '2026-09-07 11:00:00', 'fixture')")->execute([$pid]);
$old = (int)$pdo->lastInsertId();
insert_measurement($pdo, $pid, $old, ['nomor_pendaftaran' => '016', 'nama_peserta' => 'Siti Aminah', 'tanggal_lahir' => '2025-09-09', 'jenis_kelamin' => 'P', 'measurement_period' => '2026-09', 'weight_kg' => 7.0, 'height_cm' => 66.0], 'confirmed', $kembarId);

$pdo->prepare("INSERT INTO sesi_foto (posyandu_id, activity_date, form_type, status, ocr_engine, notes) VALUES (?, '2026-11-02', 'buku_penimbangan_balita', 'review', 'demo', 'fixture nama kembar')")->execute([$pid]);
$sid = (int)$pdo->lastInsertId();
$raw = [
    ['nama_peserta' => 'Siti Aminah', 'periode_pengukuran' => '2026-11', 'bb_kg' => 9.4, 'tb_pb_cm' => 74],      // 2 anak bernama ini, tanpa no./tgl lahir
    ['nama_peserta' => 'Rudi Hartono', 'periode_pengukuran' => '2026-11', 'bb_kg' => 10.0, 'tb_pb_cm' => 80],     // baris kembar dalam halaman
    ['nama_peserta' => 'Rudi Hartono', 'periode_pengukuran' => '2026-11', 'bb_kg' => 11.0, 'tb_pb_cm' => 82],
];
foreach ($raw as $r) {
    insert_measurement($pdo, $pid, $sid, normalize_ocr_row($r), 'draft');
}
$pdo->commit();
echo $sid;
