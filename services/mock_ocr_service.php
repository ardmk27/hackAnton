<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/forms.php';

/**
 * MODE DEMO: mengembalikan data CONTOH SINTETIS, bukan hasil membaca foto.
 * Dipakai saat OCR_MODE=mock atau saat layanan online gagal. UI selalu memberi label jelas.
 * Sengaja memuat sel kosong dan nilai meragukan agar alur koreksi bisa didemokan.
 *
 * @return array{0: array[], 1: string} [baris mentah, raw_result]
 */
function mock_extract(?string $activityDate = null, string $form = FORM_BALITA): array
{
    $year = $activityDate ? substr($activityDate, 0, 4) : date('Y');
    $month = $activityDate ? substr($activityDate, 0, 7) : date('Y-m');

    if ($form === FORM_IBU_HAMIL) {
        $rows = [
            ['nomor_pendaftaran' => '001', 'nama_peserta' => 'Contoh Ibu A', 'tanggal_lahir' => ($year - 28) . '-05-10', 'periode_pengukuran' => $month,
             'usia_kehamilan_minggu' => 24, 'bb_kg' => 62.5, 'tb_pb_cm' => 155.0, 'lila_cm' => 26.0, 'td_sistolik' => 110, 'td_diastolik' => 70,
             'keterangan' => null, 'needs_review' => false, 'review_reason' => null],
            ['nomor_pendaftaran' => '002', 'nama_peserta' => 'Contoh Ibu B', 'tanggal_lahir' => null, 'periode_pengukuran' => $month,
             'usia_kehamilan_minggu' => 31, 'bb_kg' => 70.0, 'tb_pb_cm' => null, 'lila_cm' => 28.5, 'td_sistolik' => 120, 'td_diastolik' => 80,
             'keterangan' => null, 'needs_review' => true, 'review_reason' => 'tanggal lahir dan tinggi badan tidak terbaca', 'uncertain_fields' => ['tanggal_lahir', 'tb_pb_cm']],
            ['nomor_pendaftaran' => '003', 'nama_peserta' => 'Contoh Ibu C', 'tanggal_lahir' => ($year - 33) . '-11-02', 'periode_pengukuran' => $month,
             'usia_kehamilan_minggu' => 120, 'bb_kg' => 58.0, 'tb_pb_cm' => 150.0, 'lila_cm' => 25.0, 'td_sistolik' => 1100, 'td_diastolik' => 70,
             'keterangan' => 'Contoh: tulisan kurang jelas', 'needs_review' => true, 'review_reason' => 'angka tekanan darah dan usia kehamilan meragukan', 'uncertain_fields' => ['td_sistolik', 'usia_kehamilan_minggu']],
        ];
        return [$rows, json_encode(['mock' => true, 'form' => $form, 'rows' => $rows], JSON_UNESCAPED_UNICODE)];
    }
    if ($form === FORM_LANSIA) {
        $rows = [
            ['nomor_pendaftaran' => '001', 'nama_peserta' => 'Contoh Lansia A', 'tanggal_lahir' => ($year - 68) . '-03-12', 'jenis_kelamin' => 'L', 'periode_pengukuran' => $month,
             'bb_kg' => 60.0, 'tb_pb_cm' => 162.0, 'td_sistolik' => 130, 'td_diastolik' => 80, 'gula_darah_mgdl' => 110,
             'keterangan' => null, 'needs_review' => false, 'review_reason' => null],
            ['nomor_pendaftaran' => '002', 'nama_peserta' => 'Contoh Lansia B', 'tanggal_lahir' => ($year - 72) . '-07-30', 'jenis_kelamin' => 'P', 'periode_pengukuran' => $month,
             'bb_kg' => 52.5, 'tb_pb_cm' => 150.0, 'td_sistolik' => null, 'td_diastolik' => null, 'gula_darah_mgdl' => 95,
             'keterangan' => null, 'needs_review' => true, 'review_reason' => 'tekanan darah tidak terbaca', 'uncertain_fields' => ['td_sistolik', 'td_diastolik']],
            ['nomor_pendaftaran' => '003', 'nama_peserta' => 'Contoh Lansia C', 'tanggal_lahir' => ($year - 65) . '-01-20', 'jenis_kelamin' => 'P', 'periode_pengukuran' => $month,
             'bb_kg' => 480.0, 'tb_pb_cm' => 155.0, 'td_sistolik' => 140, 'td_diastolik' => 90, 'gula_darah_mgdl' => 130,
             'keterangan' => null, 'needs_review' => true, 'review_reason' => 'angka BB mungkin salah baca (48,0?)', 'uncertain_fields' => ['bb_kg']],
        ];
        return [$rows, json_encode(['mock' => true, 'form' => $form, 'rows' => $rows], JSON_UNESCAPED_UNICODE)];
    }

    $rows = [
        ['nomor_pendaftaran' => '001', 'nama_peserta' => 'Contoh Anak A', 'tanggal_lahir' => ($year - 1) . '-03-12', 'jenis_kelamin' => 'P',
         'periode_pengukuran' => $month, 'bb_kg' => 8.4, 'tb_pb_cm' => 72.1, 'lingkar_kepala_cm' => 44.5, 'lila_cm' => null,
         'keterangan' => null, 'needs_review' => false, 'review_reason' => null],
        ['nomor_pendaftaran' => '002', 'nama_peserta' => 'Contoh Anak B', 'tanggal_lahir' => ($year - 2) . '-07-30', 'jenis_kelamin' => 'L',
         'periode_pengukuran' => $month, 'bb_kg' => 11.2, 'tb_pb_cm' => null, 'lingkar_kepala_cm' => null, 'lila_cm' => null,
         'keterangan' => null, 'needs_review' => true, 'review_reason' => 'tinggi badan kosong/tidak terbaca', 'uncertain_fields' => ['tb_pb_cm']],
        ['nomor_pendaftaran' => '003', 'nama_peserta' => 'Contoh Anak C', 'tanggal_lahir' => null, 'jenis_kelamin' => 'P',
         'periode_pengukuran' => $month, 'bb_kg' => 9.8, 'tb_pb_cm' => 78.0, 'lingkar_kepala_cm' => 46.0, 'lila_cm' => null,
         'keterangan' => 'Contoh: tulisan kurang jelas', 'needs_review' => true, 'review_reason' => 'tanggal lahir tidak terbaca', 'uncertain_fields' => ['tanggal_lahir']],
        ['nomor_pendaftaran' => '004', 'nama_peserta' => 'Contoh Anak D', 'tanggal_lahir' => ($year - 1) . '-11-02', 'jenis_kelamin' => 'L',
         'periode_pengukuran' => $month, 'bb_kg' => 98.0, 'tb_pb_cm' => 70.0, 'lingkar_kepala_cm' => 43.0, 'lila_cm' => null,
         'keterangan' => null, 'needs_review' => true, 'review_reason' => 'angka BB mungkin salah baca (9,8?)', 'uncertain_fields' => ['bb_kg']],
    ];
    return [$rows, json_encode(['mock' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE)];
}
