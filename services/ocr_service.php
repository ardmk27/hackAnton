<?php
declare(strict_types=1);

/**
 * Ekstraksi tabel dari foto dengan Gemini (REST API).
 * API key hanya dibaca di server dari .env; tidak pernah dikirim ke browser.
 * Hasilnya hanya DRAF: tidak dipercaya dan selalu divalidasi ulang oleh backend.
 *
 * Exception RuntimeException membawa pesan yang aman ditampilkan ke pengguna.
 *
 * Ketahanan: error sementara (koneksi, 429, 5xx) dicoba ulang, lalu berpindah ke
 * GEMINI_FALLBACK_MODEL bila ada. Model yang tidak ditemukan (404) juga berpindah ke cadangan.
 *
 * @return array{0: array[], 1: string} [baris mentah, raw_result]
 */
function gemini_extract(string $imagePath, string $mime, ?string $activityDate = null, int $variant = 1, string $form = FORM_BALITA): array
{
    $apiKey = env('GEMINI_API_KEY');
    if (!$apiKey) {
        throw new RuntimeException('GEMINI_API_KEY belum diisi di file .env.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Ekstensi PHP cURL belum aktif.');
    }
    $bytes = @file_get_contents($imagePath);
    if ($bytes === false) {
        throw new RuntimeException('File foto tidak dapat dibaca.');
    }

    // Daftar model: utama dulu, lalu cadangan (opsional)
    $clean    = static fn($v): string => preg_replace('/[^a-zA-Z0-9._-]/', '', (string)$v);
    $primary  = $clean(env('GEMINI_MODEL', 'gemini-3.5-flash'));
    $fallback = $clean(env('GEMINI_FALLBACK_MODEL', ''));
    $models   = array_values(array_unique(array_filter([$primary, $fallback])));
    if (!$models) {
        $models = ['gemini-3.5-flash'];
    }

    $debug = in_array(strtolower((string)env('APP_DEBUG', 'false')), ['true', '1'], true);

    $hint = $activityDate
        ? "Petunjuk: tanggal kegiatan Posyandu adalah $activityDate. Gunakan HANYA untuk melengkapi tahun jika tabel hanya menulis bulan, dan jika itu dilakukan set needs_review=true dengan alasan."
        : 'Tanggal kegiatan tidak diketahui.';

    $def = form_def($form);
    $rules = form_measure_rules($form);
    $measureLines = '';
    $fieldNames = ['nomor_pendaftaran', 'nama_peserta', 'tanggal_lahir', 'jenis_kelamin', 'periode_pengukuran'];
    foreach ($rules as $key => $rule) {
        $measureLines .= "- {$key}: {$rule['label']}" . ($rule['unit'] !== '' ? " dalam {$rule['unit']}" : '') . "; tulis sebagai angka" . ((int)$rule['dec'] === 0 ? ' bulat' : ' (titik desimal)') . ".\n";
        $fieldNames[] = $key;
    }
    $formLabel = $def['label'];
    $prompt = "Anda membaca foto tabel dari \"{$formLabel}\" (Posyandu, Indonesia).\n"
        . "Ubah isi tabel menjadi data terstruktur sesuai skema JSON yang diminta.\n\n"
        . "Aturan WAJIB:\n"
        . "- Satu objek di \"rows\" = satu peserta pada SATU periode pengukuran. Jika tabel memuat beberapa kolom bulan untuk peserta yang sama, buat beberapa objek terpisah.\n"
        . "- JANGAN MENEBAK. Jika sel kosong, tidak terbaca, atau ragu, isi null (bukan 0) dan set needs_review=true dengan review_reason singkat.\n"
        . "- Jika dua angka mirip (mis. 1 dan 7) dan Anda ragu, tetap tulis pembacaan terbaik lalu set needs_review=true.\n"
        . "- Jangan membuat diagnosis, kesimpulan, atau komentar kesehatan.\n"
        . "- tanggal_lahir format YYYY-MM-DD; periode_pengukuran format YYYY-MM; jenis_kelamin \"L\" atau \"P\".\n"
        . $measureLines
        . "- Hanya isi kolom yang benar-benar ada pada foto; kolom yang tidak ada diisi null.\n"
        . "- Abaikan baris judul/header dan baris yang sama sekali kosong.\n"
        . "- Nama yang sama bisa dimiliki orang berbeda: salin nomor pendaftaran dan tanggal lahir persis jika tertulis, dan jangan menggabungkan dua baris hanya karena namanya sama.\n"
        . "- uncertain_fields: daftar nama field yang Anda RAGU pada baris itu (gunakan nama persis: " . implode(', ', $fieldNames) . "). Kosongkan jika yakin.\n"
        . $hint;
    if ($variant === 2) {
        $prompt .= "\nCara baca: baca ulang secara mandiri. Baca setiap angka digit demi digit, periksa letak tanda desimal (koma/titik), dan bedakan 1/7, 4/9, 5/6, 0/6 dengan teliti sebelum menjawab.";
    }

    $nullableString = ['type' => 'STRING', 'nullable' => true];
    $nullableNumber = ['type' => 'NUMBER', 'nullable' => true];
    $measureProps = [];
    foreach (array_keys($rules) as $key) {
        $measureProps[$key] = $nullableNumber;
    }
    $schema = [
        'type' => 'OBJECT',
        'properties' => [
            'rows' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => $measureProps + [
                        'nomor_pendaftaran'  => $nullableString,
                        'nama_peserta'       => $nullableString,
                        'tanggal_lahir'      => $nullableString,
                        'jenis_kelamin'      => $nullableString,
                        'periode_pengukuran' => $nullableString,
                        'keterangan'         => $nullableString,
                        'needs_review'       => ['type' => 'BOOLEAN'],
                        'review_reason'      => $nullableString,
                        'uncertain_fields'   => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'nullable' => true],
                    ],
                    'required' => ['nama_peserta', 'needs_review'],
                ],
            ],
        ],
        'required' => ['rows'],
    ];

    $payload = [
        'contents' => [[
            'parts' => [
                ['text' => $prompt],
                ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]],
            ],
        ]],
        'generationConfig' => [
            'temperature'      => 0,
            'responseMimeType' => 'application/json',
            'responseSchema'   => $schema,
        ],
    ];
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

    // Beberapa percobaan + model cadangan bisa memakan waktu lama
    @set_time_limit(500);

    $body = false;
    $errno = 0;
    $curlErr = '';
    $http = 0;
    $maxTries = 2;

    foreach ($models as $model) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';
        $transient = false;

        for ($try = 1; $try <= $maxTries; $try++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT        => 110,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'x-goog-api-key: ' . $apiKey,
                ],
                CURLOPT_POSTFIELDS     => $payloadJson,
            ]);
            $body    = curl_exec($ch);
            $errno   = curl_errno($ch);
            $curlErr = curl_error($ch);
            $http    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // Error sementara: koneksi gagal, kuota (429), atau masalah di sisi Google (5xx)
            $transient = $errno !== 0 || in_array($http, [429, 500, 502, 503, 504], true);
            if (!$transient) {
                break; // berhasil atau error permanen, tidak perlu mengulang model ini
            }
            if ($try < $maxTries) {
                sleep(3); // beri jeda sebelum mencoba lagi
            }
        }

        // Pindah ke model cadangan hanya untuk error sementara atau model tidak ditemukan
        $tryNextModel = $transient || $http === 404;
        if (!$tryNextModel) {
            break;
        }
    }

    if ($errno !== 0 || $body === false) {
        $msg = 'Tidak dapat terhubung ke layanan Gemini (periksa koneksi internet).';
        if ($debug) {
            $msg .= " [cURL $errno: $curlErr]";
        }
        throw new RuntimeException($msg);
    }
    if ($http !== 200) {
        $msg = match (true) {
            $http === 400 => 'Permintaan ditolak Gemini (400). Periksa nama model/format foto.',
            $http === 401, $http === 403 => 'API key Gemini ditolak (periksa GEMINI_API_KEY).',
            $http === 404 => 'Model Gemini tidak ditemukan (periksa GEMINI_MODEL).',
            $http === 429 => 'Kuota/batas permintaan Gemini terlampaui. Coba lagi nanti.',
            $http >= 500 => 'Layanan Gemini sedang bermasalah. Coba lagi nanti.',
            default => "Gemini mengembalikan kesalahan HTTP $http.",
        };

        // Hanya saat pengembangan: tampilkan pesan asli dari Google
        if ($debug) {
            $err = json_decode((string)$body, true);
            $msg .= ' [HTTP ' . $http . ': ' . ($err['error']['message'] ?? 'tanpa pesan') . ']';
        }

        throw new RuntimeException($msg);
    }

    $resp = json_decode((string)$body, true);
    if (!is_array($resp)) {
        throw new RuntimeException('Respons Gemini tidak dapat dibaca.');
    }
    if (!empty($resp['promptFeedback']['blockReason'])) {
        throw new RuntimeException('Foto ditolak oleh filter Gemini.');
    }
    $text = $resp['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!is_string($text) || $text === '') {
        throw new RuntimeException('Gemini tidak mengembalikan hasil. Coba foto yang lebih jelas.');
    }
    $data = json_decode($text, true);
    if (!is_array($data) || !isset($data['rows']) || !is_array($data['rows'])) {
        throw new RuntimeException('Format hasil Gemini tidak sesuai skema. Coba proses ulang.');
    }

    $rows = [];
    foreach (array_slice($data['rows'], 0, 200) as $r) {
        if (is_array($r)) {
            $rows[] = $r;
        }
    }
    return [$rows, $text];
}