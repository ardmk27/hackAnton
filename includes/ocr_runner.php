<?php
declare(strict_types=1);

require_once __DIR__ . '/forms.php';
require_once __DIR__ . '/measurements.php';
require_once __DIR__ . '/two_pass.php';
require_once __DIR__ . '/../services/ocr_service.php';
require_once __DIR__ . '/../services/mock_ocr_service.php';

/** Mode yang diizinkan untuk ekstraksi. */
function available_ocr_modes(): array
{
    $modes = ['mock' => 'Mode demo (data contoh sintetis)', 'manual' => 'Input manual (tanpa OCR)'];
    if (env('GEMINI_API_KEY')) {
        $modes = ['gemini' => 'Gemini (baca foto)'] + $modes;
    }
    return $modes;
}

function default_ocr_mode(): string
{
    $m = env('OCR_MODE', 'mock');
    if ($m === 'gemini' && env('GEMINI_API_KEY')) {
        return 'gemini';
    }
    return 'mock';
}

/**
 * Jalankan ekstraksi untuk satu sesi dan simpan sebagai DRAF.
 * Gagal tidak menghilangkan foto; draf lama hanya diganti jika ekstraksi baru berhasil.
 *
 * @return array{0: bool, 1: string} [berhasil, pesan]
 */
function run_extraction(PDO $pdo, array $sesi, string $mode): array
{
    $sesiId = (int)$sesi['id'];
    $posyId = (int)$sesi['posyandu_id'];
    $form = form_key($sesi['form_type'] ?? null);
    if ($sesi['status'] === 'confirmed') {
        return [false, 'Sesi sudah terkonfirmasi dan tidak dapat diproses ulang.'];
    }
    @set_time_limit(150);

    $pdo->prepare("UPDATE sesi_foto SET status = 'processing', error_message = NULL WHERE id = ? AND posyandu_id = ?")
        ->execute([$sesiId, $posyId]);

    try {
        if ($mode === 'manual') {
            $rows = [[]];
            $raw = null;
        } elseif ($mode === 'mock') {
            [$rows, $raw] = mock_extract($sesi['activity_date'], $form);
        } else {
            $path = $sesi['photo_path'] ? upload_dir() . '/' . basename($sesi['photo_path']) : '';
            if ($path === '' || !is_file($path)) {
                throw new RuntimeException('Foto sumber tidak tersedia (mungkin sudah dihapus).');
            }
            [$rows, $raw] = gemini_extract($path, (string)$sesi['photo_mime'], $sesi['activity_date'], 1, $form);
            if (env('OCR_TWO_PASS', 'false') === 'true') {
                // Bacaan kedua yang independen; kolom yang berbeda ditandai ragu (biaya API 2x).
                [$rows2, $raw2] = gemini_extract($path, (string)$sesi['photo_mime'], $sesi['activity_date'], 2, $form);
                $rows = merge_two_readings($rows, $rows2);
                $raw = json_encode(['pass1' => json_decode((string)$raw, true), 'pass2' => json_decode((string)$raw2, true)], JSON_UNESCAPED_UNICODE);
            }
        }

        $normalized = array_map(
            fn($r) => $mode === 'manual'
                ? ['needs_review' => 1, 'review_reason' => 'input manual']
                : normalize_ocr_row($r, $form),
            $rows
        );

        $pdo->beginTransaction();
        // Hapus HANYA draf sesi ini (data terkonfirmasi tidak pernah tersentuh) → tidak ada hitung ganda.
        $pdo->prepare("DELETE FROM pengukuran WHERE sesi_foto_id = ? AND posyandu_id = ? AND verification_status = 'draft'")
            ->execute([$sesiId, $posyId]);
        foreach ($normalized as $r) {
            insert_measurement($pdo, $posyId, $sesiId, $r, 'draft');
        }
        $pdo->prepare('INSERT INTO ekstraksi (sesi_foto_id, engine_name, raw_result, status) VALUES (?, ?, ?, ?)')
            ->execute([$sesiId, $mode, $raw, 'ok']);
        $pdo->prepare("UPDATE sesi_foto SET status = 'review', ocr_engine = ?, error_message = NULL WHERE id = ? AND posyandu_id = ?")
            ->execute([$mode, $sesiId, $posyId]);
        $pdo->commit();

        $n = $mode === 'manual' ? 0 : count($normalized);
        return [true, $mode === 'manual'
            ? 'Sesi siap diisi manual.'
            : "Ekstraksi selesai: $n baris draf. Mohon periksa sebelum dikonfirmasi."];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $msg = $e instanceof RuntimeException ? $e->getMessage() : 'Terjadi kesalahan saat memproses foto.';
        if (!($e instanceof RuntimeException)) {
            error_log('[kader-cukup-foto] ' . $e->getMessage());
        }
        $pdo->prepare("UPDATE sesi_foto SET status = 'failed', error_message = ? WHERE id = ? AND posyandu_id = ?")
            ->execute([mb_substr($msg, 0, 500), $sesiId, $posyId]);
        try {
            $pdo->prepare('INSERT INTO ekstraksi (sesi_foto_id, engine_name, raw_result, status) VALUES (?, ?, ?, ?)')
                ->execute([$sesiId, $mode, mb_substr($msg, 0, 500), 'failed']);
        } catch (Throwable) {
        }
        return [false, $msg];
    }
}
