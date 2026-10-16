<?php
declare(strict_types=1);

require_once __DIR__ . '/suggest.php';

/**
 * Penanda tren berat badan dari data TERKONFIRMASI.
 *
 * Aturan (dapat disesuaikan Puskesmas, wajib divalidasi dengan tenaga kesehatan):
 *   "tidak naik" = BB pada catatan terbaru SAMA atau LEBIH RENDAH dari catatan sebelumnya.
 *   - 1 kali  → level 'cek'
 *   - 2+ kali berturut-turut → level 'prioritas'
 * Ini PENANDA untuk ditinjau petugas, bukan diagnosis.
 *
 * Tidak ada catatan pada suatu periode TIDAK berarti anak tidak hadir (tidak disimpulkan di sini).
 */

const ATTENTION_RULE_TEXT = 'Berat badan dianggap tidak naik jika sama atau lebih rendah dari catatan sebelumnya. Satu kali ditandai Cek; dua kali atau lebih berturut-turut ditandai Prioritas.';

function level_label(?string $level): string
{
    return match ($level) {
        'prioritas' => 'Prioritas',
        'cek' => 'Cek',
        default => '—',
    };
}

/**
 * Sinyal BB dari titik-titik catatan (urut periode). Mengembalikan null jika < 2 catatan ber-BB.
 *
 * @param array<string, array> $points periode → titik
 * @return array{level:?string, streak:int, delta:float, latest:array, previous:array, gap_months:int, reason:string, chain:array}|null
 */
function weight_signal(array $points, ?string $upToPeriod = null): ?array
{
    $w = [];
    foreach ($points as $per => $pt) {
        if ($upToPeriod !== null && strcmp((string)$per, $upToPeriod) > 0) {
            continue;
        }
        if ($pt['weight_kg'] !== null) {
            $w[] = ['period' => (string)$per, 'w' => (float)$pt['weight_kg']];
        }
    }
    $n = count($w);
    if ($n < 2) {
        return null;
    }
    $latest = $w[$n - 1];
    $prev = $w[$n - 2];
    $delta = round($latest['w'] - $prev['w'], 2);

    $streak = 0;
    for ($i = $n - 1; $i >= 1; $i--) {
        if ($w[$i]['w'] <= $w[$i - 1]['w']) {
            $streak++;
        } else {
            break;
        }
    }
    $level = $streak >= 2 ? 'prioritas' : ($streak === 1 ? 'cek' : null);
    $chain = array_slice($w, $n - 1 - $streak, $streak + 1);

    $allMonthly = true;
    for ($i = 1; $i < count($chain); $i++) {
        if (months_between($chain[$i - 1]['period'], $chain[$i]['period']) !== 1) {
            $allMonthly = false;
        }
    }
    $gap = months_between($prev['period'], $latest['period']);

    if ($streak >= 2) {
        $reason = 'Berat badan tidak naik ' . $streak . ' ' . ($allMonthly ? 'bulan' : 'catatan') . ' berturut-turut';
    } elseif ($streak === 1) {
        $ref = $gap > 1 ? ' (dibanding ' . format_period($prev['period']) . ')' : '';
        $reason = ($delta < 0 ? 'Berat badan turun ' . fmt_num(abs($delta)) . ' kg dari catatan sebelumnya' : 'Berat badan sama dengan catatan sebelumnya') . $ref;
    } else {
        $reason = 'Berat badan naik ' . fmt_num($delta) . ' kg dari catatan sebelumnya';
    }

    return [
        'level' => $level,
        'streak' => $streak,
        'delta' => $delta,
        'latest' => $latest,
        'previous' => $prev,
        'gap_months' => $gap,
        'reason' => $reason,
        'chain' => $chain,
    ];
}

/** Periode terkonfirmasi terbaru milik Posyandu, atau null. */
function latest_confirmed_period(PDO $pdo, int $posyanduId, string $form = FORM_BALITA): ?string
{
    $st = $pdo->prepare("SELECT MAX(m.measurement_period) FROM pengukuran m JOIN sesi_foto s ON s.id = m.sesi_foto_id WHERE m.posyandu_id = ? AND s.form_type = ? AND m.verification_status = 'confirmed' AND m.measurement_period IS NOT NULL");
    $st->execute([$posyanduId, $form]);
    $v = $st->fetchColumn();
    return $v ? (string)$v : null;
}

/** Semua periode terkonfirmasi (terbaru dulu). */
function confirmed_periods(PDO $pdo, int $posyanduId, string $form = FORM_BALITA): array
{
    $st = $pdo->prepare("SELECT DISTINCT m.measurement_period FROM pengukuran m JOIN sesi_foto s ON s.id = m.sesi_foto_id WHERE m.posyandu_id = ? AND s.form_type = ? AND m.verification_status = 'confirmed' AND m.measurement_period IS NOT NULL ORDER BY m.measurement_period DESC");
    $st->execute([$posyanduId, $form]);
    return array_column($st->fetchAll(), 'measurement_period');
}

/**
 * Daftar anak yang perlu perhatian pada satu periode + ringkasan perubahan BB.
 *
 * @param array $roster hasil load_roster()
 * @return array{period:?string, items:array, counts:array{prioritas:int,cek:int}, measured:int, rising:int, not_rising:int, no_prev:int}
 */
function attention_for_period(array $roster, ?string $period): array
{
    $res = ['period' => $period, 'items' => [], 'counts' => ['prioritas' => 0, 'cek' => 0], 'measured' => 0, 'rising' => 0, 'not_rising' => 0, 'no_prev' => 0];
    if ($period === null) {
        return $res;
    }
    foreach ($roster as $p) {
        if (!isset($p['points'][$period])) {
            continue;
        }
        $res['measured']++;
        $sig = weight_signal($p['points'], $period);
        if ($sig === null) {
            $res['no_prev']++;
            continue;
        }
        if ($sig['level'] === null) {
            $res['rising']++;
            continue;
        }
        $res['not_rising']++;
        $res['counts'][$sig['level']]++;
        $res['items'][] = ['peserta' => $p, 'signal' => $sig, 'age' => age_months($p['dob'], $period)];
    }
    usort($res['items'], function ($a, $b) {
        $la = $a['signal']['level'] === 'prioritas' ? 0 : 1;
        $lb = $b['signal']['level'] === 'prioritas' ? 0 : 1;
        return [$la, -$a['signal']['streak'], $a['peserta']['name']] <=> [$lb, -$b['signal']['streak'], $b['peserta']['name']];
    });
    return $res;
}

/** Teks draf pesan untuk orang tua. Nada menenangkan, tanpa diagnosis; bagian [..] diisi petugas. */
function parent_message_draft(string $posyanduName, string $childName, array $signal): string
{
    $first = trim($childName);
    $now = fmt_kg((float)$signal['latest']['w']);
    $rel = $signal['delta'] < 0 ? 'lebih rendah dari' : 'sama dengan';
    $prev = fmt_kg((float)$signal['previous']['w']);
    return "Selamat siang, Ibu. Kami dari {$posyanduName}.\n\n"
        . "Berat badan {$first} pada penimbangan terakhir {$now} kg, {$rel} catatan sebelumnya ({$prev} kg). "
        . "Agar kondisinya bisa dilihat langsung oleh Bidan, mohon Ibu membawa {$first} ke [Posyandu atau Puskesmas] pada [hari dan jam].\n\n"
        . "Jika ada pertanyaan, silakan hubungi [nama petugas dan nomor]. Terima kasih.";
}
