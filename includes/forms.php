<?php
declare(strict_types=1);

/**
 * Registri jenis formulir. Setiap jenis menentukan kolom apa yang dibaca, dilabeli, dan divalidasi.
 * Menambah jenis baru = menambah satu entri di sini (kolom yang dipakai harus ada di FIELD_RULES).
 *
 * 'verified' = kolom sudah dicocokkan dengan formulir asli. Entri bernilai false adalah CONTOH awal
 * yang wajib dicocokkan dengan buku di Posyandu Anda sebelum dipakai.
 * 'trend' = mendukung tren BB dan daftar perhatian (hanya buku balita).
 * Rentang kewajaran ('warn') sengaja kosong untuk formulir selain balita: tidak ada angka medis yang kami klaim.
 */
const FORM_BALITA = 'buku_penimbangan_balita';
const FORM_IBU_HAMIL = 'register_ibu_hamil';
const FORM_LANSIA = 'register_lansia';

function form_registry(): array
{
    static $reg = null;
    if ($reg !== null) {
        return $reg;
    }
    $reg = [
        FORM_BALITA => [
            'label' => 'Buku penimbangan dan pengukuran bayi balita',
            'short' => 'Balita',
            'subject' => 'anak',
            'desc' => 'Berat, tinggi atau panjang badan, lingkar kepala, LiLA. Mendukung tren berat badan dan daftar perhatian.',
            'verified' => true,
            'trend' => true,
            'dob_min_year' => 2000,
            'show' => ['nomor_pendaftaran', 'nama_peserta', 'tanggal_lahir', 'jenis_kelamin', 'periode_pengukuran', 'keterangan'],
            'measures' => [
                'bb_kg' => ['label' => 'BB', 'unit' => 'kg'],
                'tb_pb_cm' => ['label' => 'TB/PB', 'unit' => 'cm'],
                'lingkar_kepala_cm' => ['label' => 'Lingkar kepala', 'unit' => 'cm'],
                'lila_cm' => ['label' => 'LiLA', 'unit' => 'cm'],
            ],
        ],
        FORM_IBU_HAMIL => [
            'label' => 'Register ibu hamil',
            'short' => 'Ibu hamil',
            'subject' => 'ibu',
            'desc' => 'Usia kehamilan, berat dan tinggi badan, LiLA, tekanan darah. Hanya dibaca dan diekspor; tanpa tren.',
            'verified' => false,
            'trend' => false,
            'dob_min_year' => 1950,
            'show' => ['nomor_pendaftaran', 'nama_peserta', 'tanggal_lahir', 'periode_pengukuran', 'keterangan'],
            'measures' => [
                'usia_kehamilan_minggu' => ['label' => 'Usia kehamilan', 'unit' => 'minggu'],
                'bb_kg' => ['label' => 'BB', 'unit' => 'kg', 'max' => 250.0, 'warn' => null],
                'tb_pb_cm' => ['label' => 'TB', 'unit' => 'cm', 'max' => 250.0, 'warn' => null],
                'lila_cm' => ['label' => 'LiLA', 'unit' => 'cm', 'warn' => null],
                'td_sistolik' => ['label' => 'Sistolik', 'unit' => 'mmHg'],
                'td_diastolik' => ['label' => 'Diastolik', 'unit' => 'mmHg'],
            ],
        ],
        FORM_LANSIA => [
            'label' => 'Register Posyandu lansia',
            'short' => 'Lansia',
            'subject' => 'lansia',
            'desc' => 'Berat dan tinggi badan, tekanan darah, gula darah. Hanya dibaca dan diekspor; tanpa tren.',
            'verified' => false,
            'trend' => false,
            'dob_min_year' => 1900,
            'show' => ['nomor_pendaftaran', 'nama_peserta', 'tanggal_lahir', 'jenis_kelamin', 'periode_pengukuran', 'keterangan'],
            'measures' => [
                'bb_kg' => ['label' => 'BB', 'unit' => 'kg', 'max' => 250.0, 'warn' => null],
                'tb_pb_cm' => ['label' => 'TB', 'unit' => 'cm', 'max' => 250.0, 'warn' => null],
                'td_sistolik' => ['label' => 'Sistolik', 'unit' => 'mmHg'],
                'td_diastolik' => ['label' => 'Diastolik', 'unit' => 'mmHg'],
                'gula_darah_mgdl' => ['label' => 'Gula darah', 'unit' => 'mg/dL'],
            ],
        ],
    ];
    return $reg;
}

/** Kunci formulir yang valid; nilai asing/kosong jatuh ke buku balita. */
function form_key(?string $key): string
{
    return ($key !== null && isset(form_registry()[$key])) ? $key : FORM_BALITA;
}

function form_def(?string $key): array
{
    return form_registry()[form_key($key)];
}

function form_label(?string $key): string
{
    return form_def($key)['label'];
}

function form_shows(string $form, string $field): bool
{
    return in_array($field, form_def($form)['show'], true);
}

/** Aturan sebuah kolom ukur untuk formulir tertentu = aturan dasar (FIELD_RULES) + penimpaan dari registri. */
function form_rule(string $form, string $field): array
{
    $base = FIELD_RULES[$field] ?? [];
    $over = form_def($form)['measures'][$field] ?? [];
    $rule = $base;
    foreach (['label', 'max', 'dec'] as $k) {
        if (array_key_exists($k, $over)) {
            $rule[$k] = $over[$k];
        }
    }
    if (array_key_exists('warn', $over)) {
        $rule['warn'] = $over['warn'];
    }
    $rule['unit'] = $over['unit'] ?? '';
    return $rule;
}

/** Aturan semua kolom ukur sebuah formulir, berurutan sesuai registri. @return array<string, array> */
function form_measure_rules(string $form): array
{
    $out = [];
    foreach (array_keys(form_def($form)['measures']) as $k) {
        $out[$k] = form_rule($form, $k);
    }
    return $out;
}
