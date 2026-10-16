<?php
declare(strict_types=1);

require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/suggest.php';

/**
 * Gabungkan DUA pembacaan independen atas foto yang sama.
 * Nilai yang dipakai tetap bacaan pertama; kolom yang berbeda ditandai ragu (tidak ada yang ditebak).
 * Tujuannya menangkap kesalahan diam-diam: angka yang salah baca tetapi tampak wajar.
 *
 * @param array<int, array> $a baris mentah bacaan pertama
 * @param array<int, array> $b baris mentah bacaan kedua
 * @return array<int, array> baris bacaan pertama + needs_review/review_reason/uncertain_fields tambahan
 */
function merge_two_readings(array $a, array $b): array
{
    $numeric = array_map(fn($r) => $r['label'], FIELD_RULES);
    $textual = ['tanggal_lahir' => 'tanggal lahir', 'jenis_kelamin' => 'jenis kelamin', 'periode_pengukuran' => 'periode'];

    // Sejajarkan baris: pakai nomor pendaftaran jika unik di kedua bacaan, jika tidak pakai urutan.
    $key = fn(array $r) => trim((string)($r['nomor_pendaftaran'] ?? ''));
    $keysA = array_map($key, $a);
    $keysB = array_map($key, $b);
    $uniq = fn(array $k) => !in_array('', $k, true) && count($k) === count(array_unique($k));
    $byNomor = $uniq($keysA) && $uniq($keysB);
    $mapB = [];
    foreach ($b as $i => $r) {
        $mapB[$byNomor ? $key($r) : (string)$i] = $r;
    }

    $countDiffers = count($a) !== count($b);
    $out = [];
    foreach ($a as $i => $ra) {
        $rb = $mapB[$byNomor ? $key($ra) : (string)$i] ?? null;
        $reasons = [];
        $fields = [];
        if ($rb === null || ($countDiffers && !$byNomor)) {
            $reasons[] = 'jumlah/urutan baris berbeda antar dua bacaan';
            $fields = ['nama_peserta'];
        } else {
            if (name_similarity((string)($ra['nama_peserta'] ?? ''), (string)($rb['nama_peserta'] ?? '')) < 0.9
                && (($ra['nama_peserta'] ?? '') !== '' || ($rb['nama_peserta'] ?? '') !== '')) {
                $reasons[] = 'nama berbeda antar dua bacaan (' . trim((string)($ra['nama_peserta'] ?? '')) . ' / ' . trim((string)($rb['nama_peserta'] ?? '')) . ')';
                $fields[] = 'nama_peserta';
            }
            foreach ($textual as $f => $label) {
                if (trim((string)($ra[$f] ?? '')) !== trim((string)($rb[$f] ?? ''))) {
                    $reasons[] = $label . ' berbeda antar dua bacaan';
                    $fields[] = $f;
                }
            }
            foreach ($numeric as $f => $label) {
                $x = num_or_null($ra[$f] ?? null);
                $y = num_or_null($rb[$f] ?? null);
                $same = ($x === null && $y === null) || ($x !== null && $y !== null && abs($x - $y) < 0.001);
                if (!$same) {
                    $reasons[] = $label . ' berbeda antar dua bacaan (' . ($x === null ? 'kosong' : fmt_num($x)) . ' / ' . ($y === null ? 'kosong' : fmt_num($y)) . ')';
                    $fields[] = $f;
                }
            }
        }
        if ($reasons) {
            $ra['needs_review'] = true;
            $existing = trim((string)($ra['review_reason'] ?? ''));
            $ra['review_reason'] = trim(($existing !== '' ? $existing . '; ' : '') . implode('; ', $reasons));
            $ra['uncertain_fields'] = array_values(array_unique(array_merge((array)($ra['uncertain_fields'] ?? []), $fields)));
        }
        $out[] = $ra;
    }
    return $out;
}
