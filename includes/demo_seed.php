<?php
declare(strict_types=1);

require_once __DIR__ . '/measurements.php';
require_once __DIR__ . '/validation.php';

/**
 * DATA DEMO SINTETIS. Semua nama, tanggal lahir, dan angka adalah karangan untuk demonstrasi.
 * Bukan data anak sungguhan dan tidak boleh dipakai sebagai contoh nilai medis.
 */

/** [no. daftar, nama, tgl lahir, JK, [BB Agu, Sep, Okt], [TB Agu, Sep, Okt]]. null = tidak ada catatan. */
function demo_children(): array
{
    return [
        ['001', 'Siti Aminah',      '2025-02-14', 'P', [8.7, 9.0, 9.2],  [71.5, 72.5, 73.0]],
        ['002', 'Budi Santoso',     '2024-06-03', 'L', [11.0, 11.2, 11.4], [80.5, 81.5, 82.0]],
        ['003', 'Muhammad Rizki',   '2025-03-12', 'L', [9.2, 9.0, 9.0],  [75.0, 76.5, 78.0]],
        ['004', 'Dewi Lestari',     '2025-11-21', 'P', [7.5, 7.8, 7.8],  [66.0, 67.0, 68.0]],
        ['005', 'Andi Pratama',     '2024-01-09', 'L', [11.8, 12.0, 12.1], [83.5, 84.5, 85.0]],
        ['006', 'Nur Aisyah',       '2025-09-02', 'P', [8.0, 8.3, 8.6],  [69.0, 70.0, 71.0]],
        ['007', 'Fajar Ramadhan',   '2024-04-18', 'L', [11.5, 11.7, 11.9], [82.0, 83.0, 84.0]],
        ['008', 'Putri Maharani',   '2024-10-25', 'P', [9.8, 10.0, 10.2], [75.5, 76.5, 77.5]],
        ['009', 'Raka Wijaya',      '2024-02-11', 'L', [12.4, 12.6, 12.8], [86.0, 87.0, 88.0]],
        ['010', 'Salsa Anindita',   '2025-05-06', 'P', [9.3, 9.5, 9.7],  [73.0, 74.0, 75.0]],
        ['011', 'Bagas Saputra',    '2023-12-20', 'L', [13.0, 13.2, 13.4], [89.0, 90.0, 90.5]],
        ['012', 'Lia Permata',      '2025-08-15', 'P', [8.5, 8.7, 8.9],  [70.0, 71.0, 72.0]],
        ['013', 'Yoga Firmansyah',  '2024-08-02', 'L', [10.5, 10.7, 10.9], [77.5, 78.5, 79.0]],
        ['014', 'Tika Rahayu',      '2025-04-09', 'P', [9.0, 9.2, 9.4],  [72.0, 73.0, 74.0]],
        ['015', 'Rina Wulandari',   '2025-08-30', 'P', [8.4, 8.5, null], [70.5, 71.0, null]],
    ];
}

const DEMO_PERIODS = [
    ['2026-08', '2026-08-03'],
    ['2026-09', '2026-09-07'],
    ['2026-10', '2026-10-05'],
];

/**
 * Muat data demo ke Posyandu yang sedang login.
 *  mode 'story' : Agustus & September terkonfirmasi, Oktober berstatus DRAF dengan sel bermasalah
 *                 (untuk didemokan: periksa → konfirmasi → daftar perhatian muncul).
 *  mode 'full'  : ketiga bulan terkonfirmasi.
 *
 * @return array{children:int, confirmed_sessions:int, draft_session_id:?int}
 */
function seed_demo_data(PDO $pdo, int $posyanduId, string $mode = 'story'): array
{
    $mode = $mode === 'full' ? 'full' : 'story';
    $pdo->beginTransaction();
    try {
        $kids = demo_children();
        $pesertaIds = [];
        $insP = $pdo->prepare('INSERT INTO peserta (posyandu_id, local_reference, name, date_of_birth, sex) VALUES (?, ?, ?, ?, ?)');
        foreach ($kids as $k) {
            $insP->execute([$posyanduId, $k[0], $k[1], $k[2], $k[3]]);
            $pesertaIds[$k[0]] = (int)$pdo->lastInsertId();
        }

        $confirmedSessions = 0;
        $draftId = null;
        $insS = $pdo->prepare("INSERT INTO sesi_foto (posyandu_id, activity_date, form_type, photo_path, original_filename, photo_mime, notes, status, ocr_engine, confirmed_at)
                               VALUES (?, ?, 'buku_penimbangan_balita', NULL, NULL, NULL, 'Data demo sintetis', ?, 'demo', ?)");

        foreach (DEMO_PERIODS as $i => [$period, $date]) {
            $isDraft = ($mode === 'story' && $i === 2);
            $insS->execute([$posyanduId, $date, $isDraft ? 'review' : 'confirmed', $isDraft ? null : $date . ' 11:00:00']);
            $sesiId = (int)$pdo->lastInsertId();

            if ($isDraft) {
                $draftId = $sesiId;
                $raw = [];
                foreach ($kids as $k) {
                    if ($k[4][$i] === null) {
                        continue;   // Rina tidak punya catatan Oktober (tidak berarti absen)
                    }
                    $raw[] = [
                        'nomor_pendaftaran' => $k[0], 'nama_peserta' => $k[1], 'tanggal_lahir' => $k[2], 'jenis_kelamin' => $k[3],
                        'periode_pengukuran' => $period, 'bb_kg' => $k[4][$i], 'tb_pb_cm' => $k[5][$i],
                        'lingkar_kepala_cm' => null, 'lila_cm' => null, 'keterangan' => null, 'needs_review' => false, 'review_reason' => null,
                    ];
                }
                // Skenario koreksi: nama salah eja & nomor tidak terbaca, TB tidak terbaca, BB janggal.
                foreach ($raw as &$r) {
                    if ($r['nama_peserta'] === 'Muhammad Rizki') {
                        $r['nama_peserta'] = 'M. Rizky';
                        $r['nomor_pendaftaran'] = null;
                        $r['tanggal_lahir'] = null;
                        $r['jenis_kelamin'] = null;
                        $r['tb_pb_cm'] = null;
                        $r['needs_review'] = true;
                        $r['review_reason'] = 'nama tidak jelas; tinggi badan tidak terbaca';
                        $r['uncertain_fields'] = ['nama_peserta', 'tb_pb_cm'];
                    }
                    if ($r['nama_peserta'] === 'Budi Santoso') {
                        $r['bb_kg'] = 17.4;
                        $r['needs_review'] = true;
                        $r['review_reason'] = 'angka BB ragu (11,4 atau 17,4?)';
                        $r['uncertain_fields'] = ['bb_kg'];
                    }
                }
                unset($r);
                foreach ($raw as $r) {
                    insert_measurement($pdo, $posyanduId, $sesiId, normalize_ocr_row($r), 'draft');
                }
                $pdo->prepare("INSERT INTO ekstraksi (sesi_foto_id, engine_name, raw_result, status) VALUES (?, 'demo', ?, 'ok')")
                    ->execute([$sesiId, json_encode(['demo' => true, 'rows' => $raw], JSON_UNESCAPED_UNICODE)]);
                continue;
            }

            foreach ($kids as $k) {
                if ($k[4][$i] === null) {
                    continue;
                }
                insert_measurement($pdo, $posyanduId, $sesiId, [
                    'nomor_pendaftaran' => $k[0], 'nama_peserta' => $k[1], 'tanggal_lahir' => $k[2], 'jenis_kelamin' => $k[3],
                    'measurement_period' => $period, 'weight_kg' => $k[4][$i], 'height_cm' => $k[5][$i],
                    'needs_review' => 0,
                ], 'confirmed', $pesertaIds[$k[0]]);
            }
            $confirmedSessions++;
        }
        $pdo->commit();
        return ['children' => count($kids), 'confirmed_sessions' => $confirmedSessions, 'draft_session_id' => $draftId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
