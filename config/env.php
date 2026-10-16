<?php
declare(strict_types=1);

/**
 * Membaca konfigurasi dari file .env (di root proyek) atau environment server.
 * File .env TIDAK boleh di-commit ke GitHub.
 */
function env(string $key, ?string $default = null): ?string
{
    static $vars = null;
    if ($vars === null) {
        $vars = [];
        $file = __DIR__ . '/../.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $vars[trim($k)] = trim(trim($v), "\"'");
            }
        }
    }
    $val = $vars[$key] ?? getenv($key);
    if ($val === false || $val === null || $val === '') {
        return $default;
    }
    return (string)$val;
}
