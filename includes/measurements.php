<?php
declare(strict_types=1);

require_once __DIR__ . '/suggest.php';

const MEASUREMENT_COLS = [
    'nomor_pendaftaran', 'nama_peserta', 'tanggal_lahir', 'jenis_kelamin', 'measurement_period',
    'weight_kg', 'height_cm', 'head_circumference_cm', 'lila_cm', 'gestational_weeks', 'bp_systolic', 'bp_diastolic', 'blood_glucose',
    'keterangan', 'needs_review', 'review_reason',
    'flag_fields', 'ocr_snapshot', 'corrected_fields', 'human_checked',
];

/** Sisipkan baris pengukuran (draf atau terkonfirmasi). Harus dipanggil di dalam transaksi. */
function insert_measurement(PDO $pdo, int $posyanduId, int $sesiId, array $row, string $status, ?int $pesertaId = null): void
{
    $cols = MEASUREMENT_COLS;
    $sql = 'INSERT INTO pengukuran (posyandu_id, sesi_foto_id, peserta_id, ' . implode(',', $cols)
         . ', verification_status, verified_at) VALUES (?, ?, ?, ' . implode(',', array_fill(0, count($cols), '?'))
         . ', ?, ' . ($status === 'confirmed' ? 'NOW()' : 'NULL') . ')';
    $params = [$posyanduId, $sesiId, $pesertaId];
    foreach ($cols as $c) {
        if ($c === 'needs_review' || $c === 'human_checked') {
            // kolom NOT NULL; baris terkonfirmasi dianggap sudah diperiksa manusia
            $params[] = ($c === 'human_checked' && $status === 'confirmed') ? 1 : (int)($row[$c] ?? 0);
            continue;
        }
        $params[] = $row[$c] ?? null;
    }
    $params[] = $status;
    $pdo->prepare($sql)->execute($params);
}

/**
 * Hubungkan baris ke data induk peserta HANYA jika identitas jelas:
 *  - nomor pendaftaran lokal ada → cocokkan persis dengan nomor itu
 *  - atau nama + tanggal lahir keduanya ada → cocokkan persis
 * Selain itu peserta_id dibiarkan NULL (tidak menebak berdasarkan nama saja).
 */
function link_peserta(PDO $pdo, int $posyanduId, array $row, string $form = FORM_BALITA): ?int
{
    $ref = $row['nomor_pendaftaran'] ?? null;
    $name = $row['nama_peserta'] ?? null;
    $dob = $row['tanggal_lahir'] ?? null;

    if ($ref !== null) {
        $st = $pdo->prepare('SELECT id FROM peserta WHERE posyandu_id = ? AND form_type = ? AND local_reference = ? LIMIT 1');
        $st->execute([$posyanduId, $form, $ref]);
        $id = $st->fetchColumn();
        if ($id) {
            return (int)$id;
        }
    } elseif ($name !== null && $dob !== null) {
        // Nama + tanggal lahir: bandingkan nama dengan aturan yang SAMA seperti layar review (normalize_name),
        // supaya "M. Rizky" dan "Muhammad Rizki" dengan tanggal lahir sama dianggap orang yang sama.
        $st = $pdo->prepare('SELECT id, name FROM peserta WHERE posyandu_id = ? AND form_type = ? AND date_of_birth = ? ORDER BY local_reference IS NULL DESC, id');
        $st->execute([$posyanduId, $form, $dob]);
        foreach ($st->fetchAll() as $cand) {
            if (normalize_name((string)$cand['name']) === normalize_name($name)) {
                return (int)$cand['id'];
            }
        }
    } else {
        return null;
    }

    if ($name === null) {
        $name = 'Peserta #' . $ref;   // identitas hanya dari nomor lokal
    }
    $pdo->prepare('INSERT INTO peserta (posyandu_id, local_reference, name, date_of_birth, sex, form_type) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$posyanduId, $ref, $name, $dob, $row['jenis_kelamin'] ?? null, $form]);
    return (int)$pdo->lastInsertId();
}
