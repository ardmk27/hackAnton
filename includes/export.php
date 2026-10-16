<?php
declare(strict_types=1);

require_once __DIR__ . '/trends.php';

/**
 * Kolom tabel ekspor/laporan untuk sebuah jenis formulir. Dipakai bersama oleh rekap, XLSX, dan PDF
 * supaya ketiganya selalu menampilkan kolom yang sama.
 *
 * t: text | date | num | int | delta | level
 *
 * @return array<int, array{k:string, l:string, t:string, w:float}>
 */
function export_columns(string $form): array
{
    $def = form_def($form);
    $cols = [
        ['k' => 'nomor_pendaftaran', 'l' => 'No.', 't' => 'text', 'w' => 8],
        ['k' => 'nama_peserta', 'l' => 'Nama', 't' => 'text', 'w' => 34],
        ['k' => 'tanggal_lahir', 'l' => 'Tgl lahir', 't' => 'date', 'w' => 13],
    ];
    if (form_shows($form, 'jenis_kelamin')) {
        $cols[] = ['k' => 'jenis_kelamin', 'l' => 'JK', 't' => 'text', 'w' => 6];
    }
    foreach (form_measure_rules($form) as $key => $rule) {
        $cols[] = ['k' => $rule['col'], 'l' => $rule['label'] . ($rule['unit'] !== '' ? ' (' . $rule['unit'] . ')' : ''), 't' => (int)$rule['dec'] === 0 ? 'int' : 'num', 'w' => 12, 'measure' => $key];
        if ($key === 'bb_kg' && !empty($def['trend'])) {
            $cols[] = ['k' => '_delta', 'l' => 'Selisih BB (kg)', 't' => 'delta', 'w' => 13];
        }
    }
    if (!empty($def['trend'])) {
        $cols[] = ['k' => '_level', 'l' => 'Penanda', 't' => 'level', 'w' => 12];
    }
    $cols[] = ['k' => 'keterangan', 'l' => 'Keterangan', 't' => 'text', 'w' => 24];
    return $cols;
}

/** Teks sel untuk tampilan (HTML dan PDF). */
function export_cell_text(array $col, array $row): string
{
    $v = $row[$col['k']] ?? null;
    return match ($col['t']) {
        'date' => $v ? format_date((string)$v) : '',
        'num' => $v === null ? '' : show_value($v, 1),
        'int' => $v === null ? '' : show_value($v, 0),
        'delta' => $v === null ? '' : (($v > 0 ? '+' : '') . fmt_kg((float)$v)),
        'level' => $v === 'prioritas' ? 'Prioritas' : ($v === 'cek' ? 'Cek' : ''),
        default => (string)($v ?? ''),
    };
}

/**
 * Data ekspor/laporan: HANYA catatan terkonfirmasi milik Posyandu yang login.
 * mode 'periode' (nilai 'YYYY-MM', untuk $form) atau 'sesi' (nilai id sesi; formulir mengikuti sesi).
 *
 * @return array{rows: array, label: string, period: ?string, name: string, attention: array, unique_children: int, form: string, def: array, columns: array}
 */
function fetch_export_data(PDO $pdo, int $posyanduId, string $mode, string|int $value, string $form = FORM_BALITA): array
{
    $sql = "SELECT m.*, po.posyandu_code FROM pengukuran m
            JOIN posyandu po ON po.id = m.posyandu_id
            JOIN sesi_foto s ON s.id = m.sesi_foto_id
            WHERE m.posyandu_id = ? AND m.verification_status = 'confirmed'";
    $params = [$posyanduId];
    $period = null;
    if ($mode === 'sesi') {
        $sesi = find_session($pdo, (int)$value, $posyanduId) ?: abort_404();
        $form = form_key($sesi['form_type'] ?? null);
        $sql .= ' AND m.sesi_foto_id = ?';
        $params[] = (int)$sesi['id'];
        $label = 'Sesi #' . (int)$sesi['id'];
        $name = 'sesi-' . (int)$sesi['id'];
    } else {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$value)) {
            abort_404();
        }
        $form = form_key($form);
        $sql .= ' AND s.form_type = ? AND m.measurement_period = ?';
        $params[] = $form;
        $params[] = (string)$value;
        $period = (string)$value;
        $label = format_period($period);
        $name = ($form === FORM_BALITA ? '' : $form . '-') . 'periode-' . $period;
    }
    $sql .= ' ORDER BY m.measurement_period, CAST(m.nomor_pendaftaran AS UNSIGNED), m.nama_peserta, m.id';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    $def = form_def($form);
    $roster = !empty($def['trend']) ? load_roster($pdo, $posyanduId, $form) : [];
    $unique = [];
    foreach ($rows as &$r) {
        $r['_delta'] = null;
        $r['_level'] = null;
        $pidk = $r['peserta_id'] !== null ? (int)$r['peserta_id'] : null;
        if ($pidk !== null) {
            $unique[$pidk] = true;
        }
        if ($pidk !== null && isset($roster[$pidk]) && $r['measurement_period']) {
            $sig = weight_signal($roster[$pidk]['points'], (string)$r['measurement_period']);
            if ($sig && $sig['latest']['period'] === $r['measurement_period']) {
                $r['_delta'] = $sig['delta'];
                $r['_level'] = $sig['level'];
            }
        }
    }
    unset($r);
    $emptyAtt = ['counts' => ['prioritas' => 0, 'cek' => 0], 'rising' => 0, 'not_rising' => 0, 'no_prev' => 0, 'measured' => 0, 'items' => [], 'period' => null];
    return [
        'rows' => $rows,
        'label' => $label,
        'period' => $period,
        'name' => $name,
        'attention' => ($period && !empty($def['trend'])) ? attention_for_period($roster, $period) : $emptyAtt,
        'unique_children' => count($unique),
        'form' => $form,
        'def' => $def,
        'columns' => export_columns($form),
    ];
}
