-- Rakit - skema database (MySQL / MariaDB)
-- Jalankan sekali:  mysql -u root < database/schema.sql
-- (atau impor lewat phpMyAdmin / HeidiSQL di Laragon)

CREATE DATABASE IF NOT EXISTS kader_cukup_foto
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE kader_cukup_foto;

-- Identitas Posyandu. posyandu_code = identitas (bukan rahasia),
-- credential_hash = hash kode akses acak (rahasia, tidak disimpan polos).
CREATE TABLE IF NOT EXISTS posyandu (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  posyandu_code   VARCHAR(20)  NOT NULL UNIQUE,
  name            VARCHAR(100) NOT NULL,
  credential_hash VARCHAR(255) NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  code_rotated_at DATETIME NULL
) ENGINE=InnoDB;

-- Pencatatan percobaan login gagal (rate limiting).
CREATE TABLE IF NOT EXISTS login_attempts (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip            VARCHAR(45) NOT NULL,
  posyandu_code VARCHAR(20) NOT NULL,
  attempted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip_time (ip, attempted_at),
  INDEX idx_code_time (posyandu_code, attempted_at)
) ENGINE=InnoDB;

-- Satu sesi digitalisasi = satu foto tabel.
CREATE TABLE IF NOT EXISTS sesi_foto (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  posyandu_id       INT UNSIGNED NOT NULL,
  activity_date     DATE NULL,                       -- tanggal kegiatan Posyandu (jika diketahui)
  form_type         VARCHAR(50) NOT NULL DEFAULT 'buku_penimbangan_balita',
  photo_path        VARCHAR(255) NULL,               -- nama file internal (privat)
  original_filename VARCHAR(255) NULL,
  photo_mime        VARCHAR(50) NULL,
  notes             VARCHAR(500) NULL,
  status            ENUM('uploaded','processing','review','confirmed','failed') NOT NULL DEFAULT 'uploaded',
  ocr_engine        VARCHAR(30) NULL,                -- gemini | mock | manual
  error_message     VARCHAR(500) NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,   -- waktu unggah
  confirmed_at      DATETIME NULL,
  reopened_at       DATETIME NULL,                   -- terakhir dibuka kembali untuk revisi
  INDEX idx_posyandu_status (posyandu_id, status),
  INDEX idx_posyandu_created (posyandu_id, created_at),
  CONSTRAINT fk_sesi_posyandu FOREIGN KEY (posyandu_id) REFERENCES posyandu(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Data induk peserta (hanya dibuat saat konfirmasi, dan hanya jika identitas jelas).
CREATE TABLE IF NOT EXISTS peserta (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  posyandu_id     INT UNSIGNED NOT NULL,
  local_reference VARCHAR(30) NULL,                  -- nomor pendaftaran/urut lokal
  name            VARCHAR(100) NOT NULL,
  date_of_birth   DATE NULL,
  sex             ENUM('L','P') NULL,
  form_type       VARCHAR(50) NOT NULL DEFAULT 'buku_penimbangan_balita',  -- jenis formulir asal; daftar tiap formulir terpisah
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_peserta_form (posyandu_id, form_type, local_reference),
  INDEX idx_peserta_ref (posyandu_id, local_reference),
  INDEX idx_peserta_name (posyandu_id, name, date_of_birth),
  CONSTRAINT fk_peserta_posyandu FOREIGN KEY (posyandu_id) REFERENCES posyandu(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Satu baris = satu peserta pada satu periode pengukuran.
-- Nilai tidak terbaca/kosong disimpan NULL (bukan 0).
CREATE TABLE IF NOT EXISTS pengukuran (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  posyandu_id           INT UNSIGNED NOT NULL,
  sesi_foto_id          BIGINT UNSIGNED NOT NULL,
  peserta_id            INT UNSIGNED NULL,
  nomor_pendaftaran     VARCHAR(30) NULL,
  nama_peserta          VARCHAR(100) NULL,
  tanggal_lahir         DATE NULL,
  jenis_kelamin         ENUM('L','P') NULL,
  measurement_period    CHAR(7) NULL,                -- YYYY-MM, periode di tabel (bukan tanggal unggah)
  weight_kg             DECIMAL(5,2) NULL,
  height_cm             DECIMAL(5,1) NULL,
  head_circumference_cm DECIMAL(5,1) NULL,
  lila_cm               DECIMAL(5,1) NULL,
  gestational_weeks     SMALLINT UNSIGNED NULL,       -- usia kehamilan (minggu), register ibu hamil
  bp_systolic           SMALLINT UNSIGNED NULL,       -- tekanan darah sistolik (mmHg)
  bp_diastolic          SMALLINT UNSIGNED NULL,       -- tekanan darah diastolik (mmHg)
  blood_glucose         SMALLINT UNSIGNED NULL,       -- gula darah (mg/dL), register lansia
  keterangan            VARCHAR(255) NULL,
  needs_review          TINYINT(1) NOT NULL DEFAULT 0,
  review_reason         VARCHAR(255) NULL,
  flag_fields           VARCHAR(120) NULL,           -- kolom yang ragu (dipisah koma), untuk penanda di layar review
  ocr_snapshot          TEXT NULL,                   -- nilai hasil baca OCR sebelum dikoreksi (JSON)
  corrected_fields      VARCHAR(160) NULL,           -- kolom yang diubah kader dibanding hasil OCR
  human_checked         TINYINT(1) NOT NULL DEFAULT 0, -- kader menandai baris ini sudah diperiksa
  verification_status   ENUM('draft','confirmed') NOT NULL DEFAULT 'draft',
  verified_at           DATETIME NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sesi (sesi_foto_id),
  INDEX idx_recap (posyandu_id, verification_status, measurement_period),
  INDEX idx_peserta_period (posyandu_id, peserta_id, measurement_period),
  CONSTRAINT fk_ukur_posyandu FOREIGN KEY (posyandu_id) REFERENCES posyandu(id) ON DELETE CASCADE,
  CONSTRAINT fk_ukur_sesi FOREIGN KEY (sesi_foto_id) REFERENCES sesi_foto(id) ON DELETE CASCADE,
  CONSTRAINT fk_ukur_peserta FOREIGN KEY (peserta_id) REFERENCES peserta(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Hasil mentah dari OCR/AI, dipisah dari data yang sudah dikoreksi.
CREATE TABLE IF NOT EXISTS ekstraksi (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sesi_foto_id BIGINT UNSIGNED NOT NULL,
  engine_name  VARCHAR(30) NOT NULL,
  raw_result   MEDIUMTEXT NULL,
  status       ENUM('ok','failed') NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ekstraksi_sesi (sesi_foto_id),
  CONSTRAINT fk_ekstraksi_sesi FOREIGN KEY (sesi_foto_id) REFERENCES sesi_foto(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Jejak aktivitas penting pada sesi (konfirmasi, buka kembali). Kode akses dipakai bersama,
-- jadi log ini mencatat APA dan KAPAN, bukan SIAPA.
CREATE TABLE IF NOT EXISTS koreksi_log (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  posyandu_id  INT UNSIGNED NOT NULL,
  sesi_foto_id BIGINT UNSIGNED NOT NULL,
  action       ENUM('confirm','reopen') NOT NULL,
  detail       VARCHAR(500) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_log_sesi (sesi_foto_id),
  CONSTRAINT fk_log_posyandu FOREIGN KEY (posyandu_id) REFERENCES posyandu(id) ON DELETE CASCADE,
  CONSTRAINT fk_log_sesi FOREIGN KEY (sesi_foto_id) REFERENCES sesi_foto(id) ON DELETE CASCADE
) ENGINE=InnoDB;
