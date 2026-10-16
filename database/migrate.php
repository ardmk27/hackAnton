<?php
declare(strict_types=1);

/**
 * Migrasi untuk database LAMA (dibuat sebelum versi 2).
 * Aman dijalankan berulang kali: hanya menambah yang belum ada. Tidak menghapus data.
 *
 *   php database/migrate.php
 *
 * Instalasi baru cukup menjalankan database/schema.sql.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Jalankan lewat terminal: php database/migrate.php');
}
require __DIR__ . '/../config/env.php';
require __DIR__ . '/../config/database.php';

$pdo = db();
$dbName = (string)env('DB_NAME', 'kader_cukup_foto');

$hasColumn = function (string $table, string $column) use ($pdo, $dbName): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$dbName, $table, $column]);
    return (int)$st->fetchColumn() > 0;
};
$hasIndex = function (string $table, string $index) use ($pdo, $dbName): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $st->execute([$dbName, $table, $index]);
    return (int)$st->fetchColumn() > 0;
};

$steps = [];

if (!$hasColumn('sesi_foto', 'reopened_at')) {
    $steps[] = ['sesi_foto.reopened_at', 'ALTER TABLE sesi_foto ADD COLUMN reopened_at DATETIME NULL AFTER confirmed_at'];
}
if (!$hasColumn('pengukuran', 'flag_fields')) {
    $steps[] = ['pengukuran.flag_fields', 'ALTER TABLE pengukuran ADD COLUMN flag_fields VARCHAR(120) NULL AFTER review_reason'];
}
if (!$hasColumn('pengukuran', 'ocr_snapshot')) {
    $steps[] = ['pengukuran.ocr_snapshot', 'ALTER TABLE pengukuran ADD COLUMN ocr_snapshot TEXT NULL AFTER flag_fields'];
}
if (!$hasColumn('pengukuran', 'corrected_fields')) {
    $steps[] = ['pengukuran.corrected_fields', 'ALTER TABLE pengukuran ADD COLUMN corrected_fields VARCHAR(160) NULL AFTER ocr_snapshot'];
}
if (!$hasColumn('pengukuran', 'human_checked')) {
    $steps[] = ['pengukuran.human_checked', 'ALTER TABLE pengukuran ADD COLUMN human_checked TINYINT(1) NOT NULL DEFAULT 0 AFTER corrected_fields'];
}
foreach (['gestational_weeks' => 'lila_cm', 'bp_systolic' => 'gestational_weeks', 'bp_diastolic' => 'bp_systolic', 'blood_glucose' => 'bp_diastolic'] as $col => $after) {
    if (!$hasColumn('pengukuran', $col)) {
        $steps[] = ["pengukuran.$col", "ALTER TABLE pengukuran ADD COLUMN $col SMALLINT UNSIGNED NULL AFTER $after"];
    }
}
if (!$hasColumn('peserta', 'form_type')) {
    $steps[] = ['peserta.form_type', "ALTER TABLE peserta ADD COLUMN form_type VARCHAR(50) NOT NULL DEFAULT 'buku_penimbangan_balita' AFTER sex"];
}
if (!$hasIndex('peserta', 'idx_peserta_form')) {
    $steps[] = ['index idx_peserta_form', 'ALTER TABLE peserta ADD INDEX idx_peserta_form (posyandu_id, form_type, local_reference)'];
}
if (!$hasIndex('pengukuran', 'idx_peserta_period')) {
    $steps[] = ['index idx_peserta_period', 'ALTER TABLE pengukuran ADD INDEX idx_peserta_period (posyandu_id, peserta_id, measurement_period)'];
}
$steps[] = ['tabel koreksi_log', "CREATE TABLE IF NOT EXISTS koreksi_log (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  posyandu_id  INT UNSIGNED NOT NULL,
  sesi_foto_id BIGINT UNSIGNED NOT NULL,
  action       ENUM('confirm','reopen') NOT NULL,
  detail       VARCHAR(500) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_log_sesi (sesi_foto_id),
  CONSTRAINT fk_log_posyandu FOREIGN KEY (posyandu_id) REFERENCES posyandu(id) ON DELETE CASCADE,
  CONSTRAINT fk_log_sesi FOREIGN KEY (sesi_foto_id) REFERENCES sesi_foto(id) ON DELETE CASCADE
) ENGINE=InnoDB"];

$addedHumanChecked = !$hasColumn('pengukuran', 'human_checked');
foreach ($steps as [$label, $sql]) {
    $pdo->exec($sql);
    echo "OK  $label\n";
}
if ($addedHumanChecked) {
    // Baris yang sudah terkonfirmasi sebelum versi 2 dianggap sudah diperiksa manusia.
    $pdo->exec("UPDATE pengukuran SET human_checked = 1 WHERE verification_status = 'confirmed'");
    echo "OK  baris terkonfirmasi lama ditandai sudah diperiksa\n";
}
echo "Migrasi selesai.\n";
