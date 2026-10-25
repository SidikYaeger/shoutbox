<?php
/**
 * app/lib/db.php
 * Koneksi database menggunakan PDO (Prepared Statements, utf8mb4).
 * Selalu membaca kredensial dari config/db.php di luar folder app/.
 */

declare(strict_types=1);

function get_db_connection(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Cari config/db.php di luar folder app/ (htdocs/personal/config/db.php)
    $configCandidates = [
        dirname(__DIR__, 2) . '/config/db.php',
        dirname(__DIR__) . '/config/db.php',
        dirname(__DIR__, 2) . '/config/db.example.php',
    ];

    $configPath = null;
    foreach ($configCandidates as $candidate) {
        if (is_file($candidate)) {
            $configPath = $candidate;
            break;
        }
    }

    if ($configPath === null) {
        throw new RuntimeException('File konfigurasi config/db.php tidak ditemukan.');
    }

    $config = require $configPath;
    if (!is_array($config)) {
        throw new RuntimeException('Format config/db.php tidak valid.');
    }

    $host = (string) ($config['host'] ?? '127.0.0.1');
    $name = (string) ($config['name'] ?? 'kelompok_db');
    $user = (string) ($config['user'] ?? 'app_local');
    $pass = (string) ($config['pass'] ?? '');
    $port = (string) ($config['port'] ?? '3306');

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO($dsn, $user, $pass, $options);
    return $pdo;
}
