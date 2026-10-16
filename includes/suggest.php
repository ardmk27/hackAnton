<?php
declare(strict_types=1);

/**
 * Pencocokan nama anak ke daftar yang sudah ada (roster) di Posyandu yang sama.
 * Hanya MENYARANKAN; kader yang memutuskan. Tidak pernah mengganti nama otomatis.
 */

/** Normalisasi nama untuk perbandingan: huruf kecil, tanda baca dibuang, singkatan umum dikembangkan. */
function normalize_name(string $name): string
{
    $s = mb_strtolower(trim($name), 'UTF-8');
    $s = preg_replace('/[^a-z\s]/u', ' ', $s) ?? '';
    $tokens = preg_split('/\s+/', trim($s)) ?: [];
    $out = [];
    foreach ($tokens as $t) {
        if ($t === '') {
            continue;
        }
        if (in_array($t, ['m', 'muh', 'mhd', 'moh', 'mohd'], true)) {
            $t = 'muhammad';
        }
        // ejaan lama/variasi umum: y ≈ i, dj ≈ j, tj ≈ c, oe ≈ u
        $t = str_replace(['dj', 'tj', 'oe'], ['j', 'c', 'u'], $t);
        $t = str_replace('y', 'i', $t);
        $out[] = $t;
    }
    return implode(' ', $out);
}

/** Kemiripan 0..1 antara dua nama (sudah/akan dinormalisasi). */
function name_similarity(string $a, string $b): float
{
    $a = normalize_name($a);
    $b = normalize_name($b);
    if ($a === '' || $b === '') {
        return 0.0;
    }
    if ($a === $b) {
        return 1.0;
    }
    $max = max(mb_strlen($a), mb_strlen($b));
    $lev = 1.0 - (levenshtein(substr($a, 0, 200), substr($b, 0, 200)) / $max);
    similar_text($a, $b, $pct);
    // urutan kata dibalik (mis. "Rizki Muhammad") tetap dianggap mirip
    $ta = explode(' ', $a);
    $tb = explode(' ', $b);
    sort($ta);
    sort($tb);
    $sortedEq = $ta === $tb ? 0.95 : 0.0;
    return max($lev, $pct / 100.0, $sortedEq);
}

/**
 * Muat daftar anak + seri catatan terkonfirmasi (sekali per halaman).
 * @return array<int, array{id:int, name:string, ref:?string, dob:?string, sex:?string, points:array}>
 */
function load_roster(PDO $pdo, int $posyanduId, string $form = FORM_BALITA): array
{
    $st = $pdo->prepare('SELECT id, name, local_reference AS ref, date_of_birth AS dob, sex FROM peserta WHERE posyandu_id = ? AND form_type = ? ORDER BY name, id');
    $st->execute([$posyanduId, $form]);
    $roster = [];
    foreach ($st->fetchAll() as $p) {
        $p['id'] = (int)$p['id'];
        $p['points'] = [];
        $roster[$p['id']] = $p;
    }
    if (!$roster) {
        return [];
    }
    $st = $pdo->prepare(
        "SELECT m.peserta_id, m.measurement_period AS period, m.weight_kg, m.height_cm, m.head_circumference_cm, m.lila_cm, m.sesi_foto_id, m.id
         FROM pengukuran m JOIN sesi_foto s ON s.id = m.sesi_foto_id
         WHERE m.posyandu_id = ? AND s.form_type = ? AND m.verification_status = 'confirmed' AND m.peserta_id IS NOT NULL AND m.measurement_period IS NOT NULL
         ORDER BY m.peserta_id, m.measurement_period, m.id"
    );
    $st->execute([$posyanduId, $form]);
    foreach ($st->fetchAll() as $m) {
        $pid = (int)$m['peserta_id'];
        if (!isset($roster[$pid])) {
            continue;
        }
        // satu titik per periode: catatan terbaru menimpa yang lama
        $roster[$pid]['points'][$m['period']] = [
            'period' => $m['period'],
            'weight_kg' => $m['weight_kg'] !== null ? (float)$m['weight_kg'] : null,
            'height_cm' => $m['height_cm'] !== null ? (float)$m['height_cm'] : null,
            'head_circumference_cm' => $m['head_circumference_cm'] !== null ? (float)$m['head_circumference_cm'] : null,
            'lila_cm' => $m['lila_cm'] !== null ? (float)$m['lila_cm'] : null,
            'sesi_foto_id' => (int)$m['sesi_foto_id'],
        ];
    }
    foreach ($roster as &$p) {
        ksort($p['points']);
    }
    unset($p);
    return $roster;
}

/** Cocokkan baris ke anak yang sudah ada (aturan SAMA dengan link_peserta: nomor lokal, atau nama + tgl lahir persis). */
function find_exact_peserta(array $roster, array $row): ?array
{
    $ref = $row['nomor_pendaftaran'] ?? null;
    $name = $row['nama_peserta'] ?? null;
    $dob = $row['tanggal_lahir'] ?? null;
    foreach ($roster as $p) {
        if ($ref !== null && $ref !== '' && $p['ref'] === $ref) {
            return $p;
        }
    }
    if (($ref === null || $ref === '') && $name && $dob) {
        foreach ($roster as $p) {
            if ($p['dob'] === $dob && normalize_name($p['name']) === normalize_name((string)$name)) {
                return $p;
            }
        }
    }
    return null;
}

/**
 * Saran anak yang mungkin sama dengan baris ini (hanya jika BELUM cocok persis).
 * @return array<int, array{id:int, name:string, ref:?string, dob:?string, sex:?string, score:float}>
 */
function suggest_peserta(array $roster, array $row, int $limit = 2): array
{
    $name = (string)($row['nama_peserta'] ?? '');
    if ($name === '' || find_exact_peserta($roster, $row)) {
        return [];
    }
    $dob = $row['tanggal_lahir'] ?? null;
    $ref = $row['nomor_pendaftaran'] ?? null;
    $cands = [];
    foreach ($roster as $p) {
        $score = name_similarity($name, $p['name']);
        if ($dob && $p['dob'] && $dob === $p['dob']) {
            $score = min(1.0, $score + 0.12);
        }
        if ($ref && $p['ref'] && $ref === $p['ref']) {
            $score = min(1.0, $score + 0.12);
        }
        if ($score >= 0.72) {
            $cands[] = ['id' => $p['id'], 'name' => $p['name'], 'ref' => $p['ref'], 'dob' => $p['dob'], 'sex' => $p['sex'], 'score' => round($score, 2)];
        }
    }
    usort($cands, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($cands, 0, $limit);
}

/** Catatan terkonfirmasi SEBELUM periode tertentu (atau yang terakhir bila periode kosong). */
function previous_point(array $peserta, ?string $period): ?array
{
    $prev = null;
    foreach ($peserta['points'] as $per => $pt) {
        if ($period !== null && $period !== '' && strcmp((string)$per, $period) >= 0) {
            continue;
        }
        $prev = $pt;
    }
    return $prev;
}

/**
 * Umur dalam bulan PENUH pada awal bulan periode (YYYY-MM). Hari penimbangan tidak diketahui,
 * jadi ini perkiraan bawah: lahir tgl 12 Mar 2025, periode Okt 2026 → 18 bulan.
 */
function age_months(?string $dob, ?string $period): ?int
{
    if (!$dob || !$period || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dob, $d) || !preg_match('/^(\d{4})-(\d{2})$/', $period, $p)) {
        return null;
    }
    $m = ((int)$p[1] - (int)$d[1]) * 12 + ((int)$p[2] - (int)$d[2]);
    if ((int)$d[3] > 1) {
        $m -= 1;
    }
    return $m >= 0 ? $m : null;
}

// ---------------------------------------------------------------------------------------------
// Nama kembar: dua anak bisa bernama sama. Identitas SELALU ditentukan oleh nomor pendaftaran atau
// nama + tanggal lahir. Nama saja tidak pernah dipakai untuk menggabungkan riwayat.
// ---------------------------------------------------------------------------------------------

/** Anak di daftar yang namanya sama setelah normalisasi (abaikan huruf besar, titik, ejaan y/i). */
function same_name_peserta(array $roster, string $name): array
{
    $n = normalize_name($name);
    if ($n === '') {
        return [];
    }
    $out = [];
    foreach ($roster as $p) {
        if (normalize_name($p['name']) === $n) {
            $out[] = $p;
        }
    }
    return $out;
}

/** Nama (ter-normalisasi) yang dipakai lebih dari satu anak. @return array<string, int> */
function duplicate_name_set(array $roster): array
{
    $count = [];
    foreach ($roster as $p) {
        $n = normalize_name($p['name']);
        $count[$n] = ($count[$n] ?? 0) + 1;
    }
    return array_filter($count, fn($c) => $c > 1);
}

/** "no. 002, lahir 03/06/2024": pembeda singkat sebuah anak. */
function person_hint(array $p): string
{
    $bits = [];
    if (!empty($p['ref'])) {
        $bits[] = 'no. ' . $p['ref'];
    }
    if (!empty($p['dob']) && ($t = strtotime($p['dob']))) {
        $bits[] = 'lahir ' . date('d/m/Y', $t);
    }
    return $bits ? implode(', ', $bits) : 'tanpa no. dan tanggal lahir';
}

/** Nama, ditambah pembeda hanya jika ada nama kembar. */
function person_label(array $p, array $dupSet): string
{
    return isset($dupSet[normalize_name($p['name'])]) ? $p['name'] . ' (' . person_hint($p) . ')' : $p['name'];
}

/**
 * Bagaimana baris ini terhadap daftar anak yang sudah ada?
 *  known       : cocok persis (no. daftar, atau nama + tgl lahir)
 *  none        : tidak ada anak bernama sama
 *  ambiguous   : ada anak bernama sama, dan baris TIDAK punya no. daftar maupun tgl lahir (tidak bisa dipastikan siapa)
 *  other_child : ada anak bernama sama, tetapi baris punya identitas sendiri yang berbeda (dihitung anak baru)
 *
 * @return array{status:string, candidates:array}
 */
function name_ambiguity(array $roster, array $row): array
{
    if (find_exact_peserta($roster, $row)) {
        return ['status' => 'known', 'candidates' => []];
    }
    $cands = same_name_peserta($roster, (string)($row['nama_peserta'] ?? ''));
    if (!$cands) {
        return ['status' => 'none', 'candidates' => []];
    }
    $ref = trim((string)($row['nomor_pendaftaran'] ?? ''));
    $dob = $row['tanggal_lahir'] ?? null;
    if ($ref === '' && !$dob) {
        return ['status' => 'ambiguous', 'candidates' => $cands];
    }
    return ['status' => 'other_child', 'candidates' => $cands];
}

/**
 * Baris kembar DALAM satu halaman: nama dan periode sama.
 *  - dua baris atau lebih tanpa no. daftar dan tgl lahir  → tidak bisa dibedakan
 *  - no. daftar dan tgl lahir sama persis                  → kemungkinan baris ganda
 * Dua anak kembar sungguhan lolos selama no. daftar atau tgl lahirnya berbeda.
 *
 * @param array<string, array> $clean baris bersih menurut urutan form (kunci = kunci baris)
 * @return array<string, string[]> kunci baris → pesan
 */
function session_duplicate_errors(array $clean): array
{
    $pos = [];
    $i = 0;
    foreach ($clean as $k => $_) {
        $pos[$k] = ++$i;
    }
    $groups = [];
    foreach ($clean as $k => $r) {
        $n = normalize_name((string)($r['nama_peserta'] ?? ''));
        if ($n === '') {
            continue;
        }
        $groups[$n . '|' . (string)($r['measurement_period'] ?? '')][] = $k;
    }
    $errors = [];
    foreach ($groups as $keys) {
        if (count($keys) < 2) {
            continue;
        }
        $noId = [];
        $byId = [];
        foreach ($keys as $k) {
            $ref = trim((string)($clean[$k]['nomor_pendaftaran'] ?? ''));
            $dob = (string)($clean[$k]['tanggal_lahir'] ?? '');
            if ($ref === '' && $dob === '') {
                $noId[] = $k;
            } else {
                $byId[$ref . '|' . $dob][] = $k;
            }
        }
        if (count($noId) >= 2) {
            foreach ($noId as $k) {
                $others = implode(', ', array_map(fn($o) => (string)$pos[$o], array_diff($noId, [$k])));
                $errors[$k][] = 'Nama sama dengan baris ' . $others . ' pada halaman ini, dan tidak ada no. daftar atau tanggal lahir yang membedakan. Isi salah satunya agar tidak tertukar.';
            }
        }
        foreach ($byId as $same) {
            if (count($same) >= 2) {
                foreach ($same as $k) {
                    $others = implode(', ', array_map(fn($o) => (string)$pos[$o], array_diff($same, [$k])));
                    $errors[$k][] = 'Baris tampak ganda dengan baris ' . $others . ': nama, no. daftar, dan tanggal lahirnya sama. Hapus salah satu, atau perbaiki jika ini dua anak berbeda.';
                }
            }
        }
    }
    return $errors;
}
