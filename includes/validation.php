<?php
declare(strict_types=1);

/**
 * Validasi & normalisasi data pengukuran.
 * Backend selalu memvalidasi ulang: hasil OCR/AI dan input pengguna tidak tepercaya.
 */

/** Batas keras (ditolak) dan rentang wajar (hanya peringatan, tidak mengubah nilai). */
const FIELD_RULES = [
    'bb_kg'             => ['label' => 'BB',            'col' => 'weight_kg',             'max' => 60.0,  'dec' => 2, 'warn' => [1.5, 30.0]],
    'tb_pb_cm'          => ['label' => 'TB/PB',         'col' => 'height_cm',             'max' => 200.0, 'dec' => 1, 'warn' => [35.0, 130.0]],
    'lingkar_kepala_cm' => ['label' => 'Lingkar kepala', 'col' => 'head_circumference_cm', 'max' => 80.0,  'dec' => 1, 'warn' => [25.0, 60.0]],
    'lila_cm'           => ['label' => 'LiLA',          'col' => 'lila_cm',               'max' => 60.0,  'dec' => 1, 'warn' => [5.0, 30.0]],
    // Kolom untuk formulir lain (rentang kewajaran sengaja kosong: tidak ada klaim medis; hanya batas keras salah ketik).
    'usia_kehamilan_minggu' => ['label' => 'Usia kehamilan', 'col' => 'gestational_weeks', 'max' => 45.0,  'dec' => 0, 'warn' => null],
    'td_sistolik'           => ['label' => 'Sistolik',       'col' => 'bp_systolic',       'max' => 300.0, 'dec' => 0, 'warn' => null],
    'td_diastolik'          => ['label' => 'Diastolik',      'col' => 'bp_diastolic',      'max' => 200.0, 'dec' => 0, 'warn' => null],
    'gula_darah_mgdl'       => ['label' => 'Gula darah',     'col' => 'blood_glucose',     'max' => 800.0, 'dec' => 0, 'warn' => null],
];

/** Nama field pada form/OCR → kolom database (dipakai untuk jejak koreksi). */
const FORM_FIELDS = [
    'nomor_pendaftaran'  => 'nomor_pendaftaran',
    'nama_peserta'       => 'nama_peserta',
    'tanggal_lahir'      => 'tanggal_lahir',
    'jenis_kelamin'      => 'jenis_kelamin',
    'periode_pengukuran' => 'measurement_period',
    'bb_kg'              => 'weight_kg',
    'tb_pb_cm'           => 'height_cm',
    'lingkar_kepala_cm'  => 'head_circumference_cm',
    'lila_cm'            => 'lila_cm',
    'usia_kehamilan_minggu' => 'gestational_weeks',
    'td_sistolik'        => 'bp_systolic',
    'td_diastolik'       => 'bp_diastolic',
    'gula_darah_mgdl'    => 'blood_glucose',
    'keterangan'         => 'keterangan',
];

require_once __DIR__ . '/forms.php';

/**
 * Aturan selisih terhadap catatan SEBELUMNYA anak yang sama.
 * HEURISTIK untuk memancing pemeriksaan ulang, bukan standar medis. Sesuaikan bersama bidan.
 * Nilai dihitung per bulan jarak antar catatan.
 */
const DELTA_RULES = [
    'weight_drop_kg'      => 1.0,   // BB turun lebih dari ini → periksa
    'weight_rise_per_mo'  => 1.5,   // BB naik lebih dari ini per bulan → periksa
    'height_drop_cm'      => 1.0,   // TB turun lebih dari ini → periksa
    'height_rise_per_mo'  => 5.0,   // TB naik lebih dari ini per bulan → periksa
];

/** @return array{0: ?float, 1: ?string} [nilai, pesan error] */
function parse_decimal(mixed $v, float $max, int $dec): array
{
    if ($v === null) {
        return [null, null];
    }
    if (is_float($v)) {
        $v = round($v, 2);
    }
    $s = str_replace(',', '.', trim((string)$v));
    if ($s === '') {
        return [null, null];
    }
    if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $s)) {
        return [null, 'bukan angka yang valid'];
    }
    $f = (float)$s;
    if ($f <= 0 || $f > $max) {
        return [null, 'harus lebih dari 0 dan paling besar ' . $max];
    }
    return [round($f, $dec), null];
}

/** @return array{0: ?string, 1: ?string} */
function parse_date_ymd(mixed $v, int $minYear = 2000): array
{
    $s = trim((string)($v ?? ''));
    if ($s === '') {
        return [null, null];
    }
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    if (!$d || $d->format('Y-m-d') !== $s) {
        return [null, 'tanggal tidak valid (format YYYY-MM-DD)'];
    }
    if ($d > new DateTime('today')) {
        return [null, 'tanggal tidak boleh di masa depan'];
    }
    if ((int)$d->format('Y') < $minYear) {
        return [null, 'tahun tidak wajar'];
    }
    return [$s, null];
}

/** @return array{0: ?string, 1: ?string} */
function parse_period(mixed $v): array
{
    $s = trim((string)($v ?? ''));
    if ($s === '') {
        return [null, null];
    }
    if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $s, $m) || (int)$m[1] < 2000 || (int)$m[1] > ((int)date('Y') + 1)) {
        return [null, 'periode tidak valid (format YYYY-MM)'];
    }
    return [$s, null];
}

function parse_sex(mixed $v): ?string
{
    $s = strtoupper(trim((string)($v ?? '')));
    return match (true) {
        in_array($s, ['L', 'LAKI-LAKI', 'LAKI', 'LK'], true) => 'L',
        in_array($s, ['P', 'PEREMPUAN', 'PR', 'WANITA'], true) => 'P',
        default => null,
    };
}

function clean_text(mixed $v, int $max): ?string
{
    $s = trim(preg_replace('/\s+/u', ' ', (string)($v ?? '')) ?? '');
    if ($s === '') {
        return null;
    }
    return mb_substr($s, 0, $max);
}

/** Selisih bulan antara dua periode 'YYYY-MM' (b - a). */
function months_between(string $a, string $b): int
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $a, $x) || !preg_match('/^(\d{4})-(\d{2})$/', $b, $y)) {
        return 0;
    }
    return ((int)$y[1] - (int)$x[1]) * 12 + ((int)$y[2] - (int)$x[2]);
}

/** Ubah nilai apa pun menjadi float, atau null jika kosong/bukan angka. */
function num_or_null(mixed $v): ?float
{
    if ($v === null) {
        return null;
    }
    $s = str_replace(',', '.', trim((string)$v));
    return ($s === '' || !is_numeric($s)) ? null : (float)$s;
}

function fmt_num(float $f): string
{
    return rtrim(rtrim(number_format($f, 2, ',', ''), '0'), ',');
}

/** Berat badan untuk tampilan: selalu minimal satu desimal (9,0 bukan 9). */
function fmt_kg(float $f): string
{
    $s = fmt_num($f);
    return str_contains($s, ',') ? $s : $s . ',0';
}

/**
 * Peringatan terstruktur untuk satu baris. Hanya menandai; nilai TIDAK diubah.
 * $r memakai nama kolom DB (weight_kg, height_cm, ...). $prev = catatan sebelumnya anak yang sama:
 * ['period' => 'YYYY-MM', 'weight_kg' => ?float, 'height_cm' => ?float].
 *
 * @return array<int, array{field: string, msg: string}>
 */
function row_warnings(array $r, ?array $prev = null, string $form = FORM_BALITA): array
{
    $out = [];
    foreach (form_measure_rules($form) as $key => $rule) {
        $f = num_or_null($r[$rule['col']] ?? null);
        if ($f === null || empty($rule['warn'])) {
            continue;
        }
        if ($f < $rule['warn'][0] || $f > $rule['warn'][1]) {
            $out[] = ['field' => $key, 'msg' => $rule['label'] . ' ' . fmt_num($f) . ' tampak di luar kisaran umum, mohon periksa'];
        }
    }

    if ($prev && !empty(form_def($form)['trend'])) {
        $period = (string)($r['measurement_period'] ?? $r['periode_pengukuran'] ?? '');
        $gap = ($period !== '' && !empty($prev['period'])) ? max(1, months_between((string)$prev['period'], $period)) : 1;

        $w = num_or_null($r['weight_kg'] ?? null);
        $pw = num_or_null($prev['weight_kg'] ?? null);
        if ($w !== null && $pw !== null) {
            $d = $w - $pw;
            if ($d < -DELTA_RULES['weight_drop_kg']) {
                $out[] = ['field' => 'bb_kg', 'msg' => 'BB turun ' . fmt_num(abs($d)) . ' kg dari catatan sebelumnya (' . fmt_num($pw) . ' kg), mohon periksa'];
            } elseif ($d > DELTA_RULES['weight_rise_per_mo'] * $gap) {
                $hint = '';
                if ($w >= 30 && abs($w / 10 - $pw) <= 1.5) {
                    $hint = ' Mungkin maksudnya ' . fmt_num($w / 10) . '?';
                }
                $out[] = ['field' => 'bb_kg', 'msg' => 'BB naik ' . fmt_num($d) . ' kg dari catatan sebelumnya (' . fmt_num($pw) . ' kg), mohon periksa.' . $hint];
            }
        }
        $h = num_or_null($r['height_cm'] ?? null);
        $ph = num_or_null($prev['height_cm'] ?? null);
        if ($h !== null && $ph !== null) {
            $d = $h - $ph;
            if ($d < -DELTA_RULES['height_drop_cm']) {
                $out[] = ['field' => 'tb_pb_cm', 'msg' => 'TB turun ' . fmt_num(abs($d)) . ' cm dari catatan sebelumnya (' . fmt_num($ph) . ' cm), mohon periksa'];
            } elseif ($d > DELTA_RULES['height_rise_per_mo'] * $gap) {
                $out[] = ['field' => 'tb_pb_cm', 'msg' => 'TB naik ' . fmt_num($d) . ' cm dari catatan sebelumnya (' . fmt_num($ph) . ' cm), mohon periksa'];
            }
        }
    }
    return $out;
}

/** Versi lama (daftar pesan saja), dipertahankan agar kompatibel. */
function plausibility_warnings(array $r): array
{
    return array_column(row_warnings($r, null), 'msg');
}

/**
 * Validasi satu baris dari form review.
 * @param bool $strict true saat konfirmasi (periode + identitas wajib)
 * @return array{empty: bool, clean: array, errors: string[]}
 */
function validate_row(array $in, bool $strict, string $form = FORM_BALITA): array
{
    $rules = form_measure_rules($form);
    $textKeys = array_merge(['nomor_pendaftaran', 'nama_peserta', 'tanggal_lahir', 'periode_pengukuran', 'keterangan'], array_keys($rules));
    $empty = true;
    foreach ($textKeys as $k) {
        if (trim((string)($in[$k] ?? '')) !== '') {
            $empty = false;
            break;
        }
    }
    if ($empty) {
        return ['empty' => true, 'clean' => [], 'errors' => []];
    }

    $errors = [];
    $clean = [
        'nomor_pendaftaran'     => clean_text($in['nomor_pendaftaran'] ?? null, 30),
        'nama_peserta'          => clean_text($in['nama_peserta'] ?? null, 100),
        'jenis_kelamin'         => parse_sex($in['jenis_kelamin'] ?? null),
        'keterangan'            => clean_text($in['keterangan'] ?? null, 255),
        'needs_review'          => !empty($in['needs_review']) ? 1 : 0,
        'review_reason'         => clean_text($in['review_reason'] ?? null, 255),
        'flag_fields'           => clean_flag_fields($in['flag_fields'] ?? null),
        'ocr_snapshot'          => clean_snapshot($in['ocr_snapshot'] ?? null),
        'human_checked'         => !empty($in['human_checked']) ? 1 : 0,
    ];

    [$clean['tanggal_lahir'], $err] = parse_date_ymd($in['tanggal_lahir'] ?? null, (int)form_def($form)['dob_min_year']);
    if ($err) {
        $errors[] = 'Tanggal lahir: ' . $err;
    }
    [$clean['measurement_period'], $err] = parse_period($in['periode_pengukuran'] ?? null);
    if ($err) {
        $errors[] = 'Periode pengukuran: ' . $err;
    }
    foreach (FIELD_RULES as $key => $_) {
        $clean[FIELD_RULES[$key]['col']] = null;   // kolom milik formulir lain tetap kosong
    }
    foreach ($rules as $key => $rule) {
        [$val, $err] = parse_decimal($in[$key] ?? null, (float)$rule['max'], (int)$rule['dec']);
        $clean[$rule['col']] = $val;
        if ($err) {
            $errors[] = $rule['label'] . ': ' . $err;
        }
    }

    $clean['corrected_fields'] = corrected_fields($clean);

    if ($strict) {
        if ($clean['nama_peserta'] === null && $clean['nomor_pendaftaran'] === null) {
            $errors[] = 'Nama atau nomor pendaftaran wajib diisi';
        }
        if ($clean['measurement_period'] === null && trim((string)($in['periode_pengukuran'] ?? '')) === '') {
            $errors[] = 'Periode pengukuran wajib diisi agar masuk rekap';
        }
    }
    return ['empty' => false, 'clean' => $clean, 'errors' => $errors];
}

/**
 * Normalisasi satu baris hasil OCR/AI. Nilai yang tidak valid dijadikan NULL
 * dan baris ditandai perlu diperiksa (tidak pernah ditebak/diperbaiki otomatis).
 */
function normalize_ocr_row(array $o, string $form = FORM_BALITA): array
{
    $reasons = [];
    $flags = [];
    foreach ((array)($o['uncertain_fields'] ?? []) as $f) {
        if (is_string($f) && isset(FORM_FIELDS[$f])) {
            $flags[$f] = true;
        }
    }
    $r = [
        'nomor_pendaftaran' => clean_text($o['nomor_pendaftaran'] ?? null, 30),
        'nama_peserta'      => clean_text($o['nama_peserta'] ?? null, 100),
        'jenis_kelamin'     => parse_sex($o['jenis_kelamin'] ?? null),
        'keterangan'        => clean_text($o['keterangan'] ?? null, 255),
    ];
    [$r['tanggal_lahir'], $err] = parse_date_ymd($o['tanggal_lahir'] ?? null, (int)form_def($form)['dob_min_year']);
    if ($err) {
        $reasons[] = 'tanggal lahir tidak valid';
        $flags['tanggal_lahir'] = true;
    }
    [$r['measurement_period'], $err] = parse_period($o['periode_pengukuran'] ?? null);
    if ($err) {
        $reasons[] = 'periode tidak valid';
        $flags['periode_pengukuran'] = true;
    }
    foreach (FIELD_RULES as $key => $_) {
        $r[FIELD_RULES[$key]['col']] = null;
    }
    foreach (form_measure_rules($form) as $key => $rule) {
        [$val, $err] = parse_decimal($o[$key] ?? null, (float)$rule['max'], (int)$rule['dec']);
        $r[$rule['col']] = $val;
        if ($err) {
            $reasons[] = $rule['label'] . ' tidak valid';
            $flags[$key] = true;
        }
    }

    $aiReason = clean_text($o['review_reason'] ?? null, 200);
    if ($aiReason) {
        array_unshift($reasons, $aiReason);
    }
    $needs = !empty($o['needs_review']) || $reasons !== [];
    if ($r['nama_peserta'] === null && $r['nomor_pendaftaran'] === null) {
        $needs = true;
        $reasons[] = 'identitas peserta tidak terbaca';
        $flags['nama_peserta'] = true;
    }
    if ($r['measurement_period'] === null) {
        $needs = true;
        $reasons[] = 'periode pengukuran kosong';
        $flags['periode_pengukuran'] = true;
    }
    // Snapshot = apa yang dibaca OCR (sudah dinormalisasi), sebelum kader mengoreksi.
    $snap = [];
    foreach (FORM_FIELDS as $col) {
        $snap[$col] = $r[$col] ?? null;
    }
    $r['ocr_snapshot']  = json_encode($snap, JSON_UNESCAPED_UNICODE);
    $r['flag_fields']   = $flags ? implode(',', array_keys($flags)) : null;
    $r['needs_review']  = $needs ? 1 : 0;
    $r['review_reason'] = $reasons ? mb_substr(implode('; ', array_unique($reasons)), 0, 255) : null;
    return $r;
}

/** Daftar kolom ragu: hanya nama field yang dikenal, dipisah koma. */
function clean_flag_fields(mixed $v): ?string
{
    $out = [];
    foreach (explode(',', (string)($v ?? '')) as $f) {
        $f = trim($f);
        if (isset(FORM_FIELDS[$f])) {
            $out[$f] = true;
        }
    }
    return $out ? implode(',', array_keys($out)) : null;
}

/** Snapshot OCR dari form: harus JSON objek kecil dengan kunci yang dikenal; selain itu dibuang. */
function clean_snapshot(mixed $v): ?string
{
    $s = trim((string)($v ?? ''));
    if ($s === '' || strlen($s) > 4000) {
        return null;
    }
    $d = json_decode($s, true);
    if (!is_array($d)) {
        return null;
    }
    $allowed = array_values(FORM_FIELDS);
    $out = [];
    foreach ($allowed as $col) {
        $x = $d[$col] ?? null;
        $out[$col] = (is_scalar($x) || $x === null) ? $x : null;
    }
    return json_encode($out, JSON_UNESCAPED_UNICODE);
}

/**
 * Kolom yang diubah kader dibanding hasil OCR (nama field form, dipisah koma).
 * Null jika tidak ada snapshot (baris manual) atau tidak ada yang berubah.
 */
function corrected_fields(array $clean): ?string
{
    $snap = json_decode((string)($clean['ocr_snapshot'] ?? ''), true);
    if (!is_array($snap)) {
        return null;
    }
    $changed = [];
    foreach (FORM_FIELDS as $formKey => $col) {
        $a = $snap[$col] ?? null;
        $b = $clean[$col] ?? null;
        $na = num_or_null($a);
        $nb = num_or_null($b);
        $isMeasure = in_array($col, array_column(FIELD_RULES, 'col'), true);
        $same = $isMeasure
            ? (($na === null && $nb === null) || ($na !== null && $nb !== null && abs($na - $nb) < 0.0001))
            : ((string)($a ?? '') === (string)($b ?? ''));
        if (!$same) {
            $changed[] = $formKey;
        }
    }
    return $changed ? implode(',', $changed) : null;
}
