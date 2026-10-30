<?php
/**
 * app/index.php
 * Aplikasi Bersama Kelompok: Shoutbox
 * Fitur:
 * - Tulis pesan singkat (Form input + validasi klien & server + PDO prepared statement)
 * - Baca daftar pesan terbaru (JOIN tabel anggota untuk menampilkan route_id pengirim)
 * - Polling AJAX otomatis tiap 4 detik (menampilkan route_id server yang melayani polling)
 * - Fitur tambahan: Pencarian pesan (Search filter via prepared statement & live AJAX)
 * - Keamanan: Prepared statements (SQLi safe) & htmlspecialchars / textContent (XSS safe)
 */

declare(strict_types=1);

// Muat identitas lokal dari luar folder app/
$identityCandidates = [
    dirname(__DIR__) . '/identity.php',
    __DIR__ . '/identity.php',
];
foreach ($identityCandidates as $identityFile) {
    if (is_file($identityFile)) {
        require_once $identityFile;
        break;
    }
}

if (!defined('MEMBER_ROUTE')) {
    define('MEMBER_ROUTE', 'm4');
}
if (!defined('MEMBER_NAMA')) {
    define('MEMBER_NAMA', 'Muhammad Fazil Ataurrahman');
}
if (!defined('MEMBER_NIM')) {
    define('MEMBER_NIM', '103012400247');
}

require_once __DIR__ . '/lib/db.php';

/**
 * Helper escaping HTML aman
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Ambil daftar shout terbaru dengan dukungan pencarian (Search)
 *
 * @return array<int, array<string, mixed>>
 */
function fetch_shouts(PDO $pdo, string $searchQuery = '', int $limit = 50): array
{
    $searchQuery = trim($searchQuery);
    $limit = max(1, min(100, $limit));

    if ($searchQuery !== '') {
        $sql = 'SELECT s.id, s.route_id, s.nama, s.pesan, s.created_at,
                       a.nama AS anggota_nama, a.nim AS anggota_nim
                FROM shout s
                LEFT JOIN anggota a ON s.route_id = a.route_id
                WHERE s.nama LIKE :q_nama
                   OR s.pesan LIKE :q_pesan
                   OR s.route_id LIKE :q_route
                ORDER BY s.created_at DESC, s.id DESC
                LIMIT :lim';
        $stmt = $pdo->prepare($sql);
        $like = '%' . $searchQuery . '%';
        $stmt->bindValue(':q_nama', $like, PDO::PARAM_STR);
        $stmt->bindValue(':q_pesan', $like, PDO::PARAM_STR);
        $stmt->bindValue(':q_route', $like, PDO::PARAM_STR);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        $sql = 'SELECT s.id, s.route_id, s.nama, s.pesan, s.created_at,
                       a.nama AS anggota_nama, a.nim AS anggota_nim
                FROM shout s
                LEFT JOIN anggota a ON s.route_id = a.route_id
                ORDER BY s.created_at DESC, s.id DESC
                LIMIT :lim';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
    }

    return $stmt->fetchAll();
}

/**
 * Ambil daftar anggota kelompok untuk referensi node route
 *
 * @return array<int, array<string, mixed>>
 */
function fetch_anggota(PDO $pdo): array
{
    $stmt = $pdo->prepare('SELECT route_id, nim, nama FROM anggota ORDER BY route_id ASC');
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Validasi dan simpan pesan baru ke tabel shout
 *
 * @return array{ok: bool, error?: string, id?: int}
 */
function create_shout(PDO $pdo, string $rawNama, string $rawPesan): array
{
    $nama  = trim($rawNama);
    $pesan = trim($rawPesan);

    if ($nama === '') {
        return ['ok' => false, 'error' => 'Nama pengirim wajib diisi.'];
    }
    $namaLen = function_exists('mb_strlen') ? mb_strlen($nama, 'UTF-8') : strlen($nama);
    if ($namaLen < 2 || $namaLen > 100) {
        return ['ok' => false, 'error' => 'Nama pengirim harus antara 2 hingga 100 karakter.'];
    }

    if ($pesan === '') {
        return ['ok' => false, 'error' => 'Pesan shoutbox tidak boleh kosong.'];
    }
    $pesanLen = function_exists('mb_strlen') ? mb_strlen($pesan, 'UTF-8') : strlen($pesan);
    if ($pesanLen > 500) {
        return ['ok' => false, 'error' => 'Pesan maksimal 500 karakter.'];
    }

    $routeId = (string) MEMBER_ROUTE;

    $stmt = $pdo->prepare(
        'INSERT INTO shout (route_id, nama, pesan) VALUES (:route_id, :nama, :pesan)'
    );
    $stmt->execute([
        ':route_id' => $routeId,
        ':nama'     => $nama,
        ':pesan'    => $pesan,
    ]);

    return [
        'ok' => true,
        'id' => (int) $pdo->lastInsertId(),
    ];
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : '';
$searchQuery = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

// ============================================================================
// 1. Endpoint AJAX: Ambil daftar pesan terbaru (GET ?action=fetch)
// ============================================================================
if ($action === 'fetch') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    try {
        $pdo = get_db_connection();
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
        $items = fetch_shouts($pdo, $searchQuery, $limit);

        echo json_encode([
            'ok' => true,
            'served_by' => [
                'route_id' => (string) MEMBER_ROUTE,
                'nama'     => (string) MEMBER_NAMA,
                'nim'      => (string) MEMBER_NIM,
                'time'     => date('H:i:s'),
            ],
            'query' => $searchQuery,
            'count' => count($items),
            'items' => $items,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'ok'    => false,
            'error' => 'Gagal memuat data dari database. Pastikan skema sql/schema.sql sudah diimpor.',
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ============================================================================
// 2. Endpoint Kirim Pesan: AJAX (POST ?action=post) maupun Form Reguler
// ============================================================================
$formError = '';
$formSuccess = '';
$oldNama = '';
$oldPesan = '';

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = ($action === 'post')
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos((string) $_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    $rawInput = file_get_contents('php://input');
    $jsonBody = is_string($rawInput) && $rawInput !== '' ? json_decode($rawInput, true) : null;

    $inputNama  = is_array($jsonBody) && isset($jsonBody['nama'])  ? (string) $jsonBody['nama']  : (string) ($_POST['nama'] ?? '');
    $inputPesan = is_array($jsonBody) && isset($jsonBody['pesan']) ? (string) $jsonBody['pesan'] : (string) ($_POST['pesan'] ?? '');

    try {
        $pdo = get_db_connection();
        $result = create_shout($pdo, $inputNama, $inputPesan);

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            if (!$result['ok']) {
                http_response_code(422);
                echo json_encode([
                    'ok'    => false,
                    'error' => $result['error'] ?? 'Input tidak valid.',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $items = fetch_shouts($pdo, $searchQuery, 50);
            echo json_encode([
                'ok'      => true,
                'message' => 'Terkirim via ' . MEMBER_ROUTE . '.',
                'served_by' => [
                    'route_id' => (string) MEMBER_ROUTE,
                    'nama'     => (string) MEMBER_NAMA,
                    'nim'      => (string) MEMBER_NIM,
                    'time'     => date('H:i:s'),
                ],
                'items' => $items,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($result['ok']) {
            header('Location: index.php?status=sent');
            exit;
        }

        $formError = $result['error'] ?? 'Gagal menyimpan pesan.';
        $oldNama = $inputNama;
        $oldPesan = $inputPesan;
    } catch (Throwable $e) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            echo json_encode([
                'ok'    => false,
                'error' => 'Koneksi database gagal saat menyimpan pesan.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $formError = 'Koneksi database gagal. Pastikan database kelompok_db dan tabel shout sudah siap.';
        $oldNama = $inputNama;
        $oldPesan = $inputPesan;
    }
}

if (isset($_GET['status']) && $_GET['status'] === 'sent') {
    $formSuccess = 'Pesan disimpan melalui node ' . MEMBER_ROUTE . '.';
}

// ============================================================================
// 3. Muat Data Awal untuk Render Halaman HTML
// ============================================================================
$shouts = [];
$anggotaList = [];
$dbReady = true;
$dbErrorMsg = '';

try {
    $pdo = get_db_connection();
    $shouts = fetch_shouts($pdo, $searchQuery, 50);
    $anggotaList = fetch_anggota($pdo);
} catch (Throwable $e) {
    $dbReady = false;
    $dbErrorMsg = 'Database belum terhubung atau skema belum diimpor. Impor file app/sql/schema.sql ke MySQL/MariaDB dan periksa config/db.php.';
}

if (empty($anggotaList)) {
    $anggotaList = [
        ['route_id' => 'm0', 'nim' => '103012400147', 'nama' => 'Zhafir Muhammad Tectona Grandito'],
        ['route_id' => 'm1', 'nim' => '103012400188', 'nama' => 'Shahrul Putra Wiguna'],
        ['route_id' => 'm2', 'nim' => '103012400032', 'nama' => 'Akbar Fawwaz'],
        ['route_id' => 'm3', 'nim' => '103012400224', 'nama' => 'Muhammad Adira Gumillar'],
        ['route_id' => 'm4', 'nim' => '103012400247', 'nama' => 'Muhammad Fazil Ataurrahman'],
    ];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <script>
        try {
            document.querySelector('meta[name="color-scheme"]').content = localStorage.getItem('color-scheme') ?? 'light dark';
        } catch (e) {}
    </script>
    <title>SHOUTBOX // <?= e(strtoupper((string) MEMBER_ROUTE)) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        /* Hallmark · pre-emit critique: P5 H5 E5 S5 R5 V5 · macrostructure: editorial-dispatch-ledger · nav: N9 · footer: Ft2 · contrast: pass (40-41) · slop: pass (42-45) · honest: pass (46) · chrome: pass (47) · tokens: pass (48) · responsive: pass (49) · icons: pass (30) · mobile: pass (34, 49, 50-57) · Slop test: Pass (58/58) */

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html, body {
            overflow-x: clip;
        }

        :root {
            color-scheme: light dark;

            /* 4px-base architectural spacing scale */
            --space-3xs: 0.125rem;
            --space-2xs: 0.25rem;
            --space-xs: 0.5rem;
            --space-sm: 0.75rem;
            --space-md: 1rem;
            --space-lg: 1.5rem;
            --space-xl: 2rem;
            --space-2xl: 2.5rem;
            --space-3xl: 3.5rem;

            /* 2+1 Typography (Gate 37): Syne Display + Plus Jakarta Sans Body + JetBrains Mono */
            --font-display: 'Syne', 'Trebuchet MS', sans-serif;
            --font-body: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            --font-mono: 'JetBrains Mono', ui-monospace, SFMono-Regular, Consolas, monospace;

            /* Light Palette — Archival Telegraph Broadsheet (Warm Limestone & Vermilion) */
            --paper-light: #f3efe6;
            --paper-elevated-light: #ebe5d8;
            --paper-inset-light: #e2dac9;
            --ink-light: #161513;
            --ink-secondary-light: #3d3a34;
            --ink-muted-light: #6b665c;
            --rule-light: #cdc5b4;
            --rule-strong-light: #9e9583;

            --accent-light: #c93b14;
            --accent-hover-light: #a82e0d;
            --accent-active-light: #8a2308;
            --accent-ink-light: #faf7f2;
            --accent-tint-light: #f2dfd8;

            --status-ok-light: #176b41;
            --status-warn-light: #9c5708;
            --status-err-light: #a61c1c;
            --status-err-bg-light: #f7e4e4;
            --status-err-rule-light: #d69898;

            /* Dark Palette — Carbon Telemetry Ledger (Warm Obsidian & Signal Amber-Orange) */
            --paper-dark: #11100e;
            --paper-elevated-dark: #191714;
            --paper-inset-dark: #221f1b;
            --ink-dark: #efeae1;
            --ink-secondary-dark: #bab3a6;
            --ink-muted-dark: #857e71;
            --rule-dark: #2b2722;
            --rule-strong-dark: #453f37;

            --accent-dark: #f2683e;
            --accent-hover-dark: #ff8159;
            --accent-active-dark: #d9532b;
            --accent-ink-dark: #11100e;
            --accent-tint-dark: #291812;

            --status-ok-dark: #42bd7f;
            --status-warn-dark: #eb9838;
            --status-err-dark: #f57171;
            --status-err-bg-dark: #291313;
            --status-err-rule-dark: #6e2828;

            /* Resolved Tokens */
            --color-paper: var(--paper-light);
            --color-paper-2: var(--paper-elevated-light);
            --color-paper-3: var(--paper-inset-light);
            --color-ink: var(--ink-light);
            --color-ink-2: var(--ink-secondary-light);
            --color-muted: var(--ink-muted-light);
            --color-rule: var(--rule-light);
            --color-rule-strong: var(--rule-strong-light);
            --color-accent: var(--accent-light);
            --color-accent-hover: var(--accent-hover-light);
            --color-accent-active: var(--accent-active-light);
            --color-accent-ink: var(--accent-ink-light);
            --color-accent-tint: var(--accent-tint-light);
            --color-ok: var(--status-ok-light);
            --color-warn: var(--status-warn-light);
            --color-err: var(--status-err-light);
            --color-err-bg: var(--status-err-bg-light);
            --color-err-rule: var(--status-err-rule-light);
            --color-focus: var(--accent-light);

            @media (prefers-color-scheme: dark) {
                --color-paper: var(--paper-dark);
                --color-paper-2: var(--paper-elevated-dark);
                --color-paper-3: var(--paper-inset-dark);
                --color-ink: var(--ink-dark);
                --color-ink-2: var(--ink-secondary-dark);
                --color-muted: var(--ink-muted-dark);
                --color-rule: var(--rule-dark);
                --color-rule-strong: var(--rule-strong-dark);
                --color-accent: var(--accent-dark);
                --color-accent-hover: var(--accent-hover-dark);
                --color-accent-active: var(--accent-active-dark);
                --color-accent-ink: var(--accent-ink-dark);
                --color-accent-tint: var(--accent-tint-dark);
                --color-ok: var(--status-ok-dark);
                --color-warn: var(--status-warn-dark);
                --color-err: var(--status-err-dark);
                --color-err-bg: var(--status-err-bg-dark);
                --color-err-rule: var(--status-err-rule-dark);
                --color-focus: var(--accent-dark);
            }

            @supports (color: light-dark(white, black)) {
                --color-paper: light-dark(var(--paper-light), var(--paper-dark));
                --color-paper-2: light-dark(var(--paper-elevated-light), var(--paper-elevated-dark));
                --color-paper-3: light-dark(var(--paper-inset-light), var(--paper-inset-dark));
                --color-ink: light-dark(var(--ink-light), var(--ink-dark));
                --color-ink-2: light-dark(var(--ink-secondary-light), var(--ink-secondary-dark));
                --color-muted: light-dark(var(--ink-muted-light), var(--ink-muted-dark));
                --color-rule: light-dark(var(--rule-light), var(--rule-dark));
                --color-rule-strong: light-dark(var(--rule-strong-light), var(--rule-strong-dark));
                --color-accent: light-dark(var(--accent-light), var(--accent-dark));
                --color-accent-hover: light-dark(var(--accent-hover-light), var(--accent-hover-dark));
                --color-accent-active: light-dark(var(--accent-active-light), var(--accent-active-dark));
                --color-accent-ink: light-dark(var(--accent-ink-light), var(--accent-ink-dark));
                --color-accent-tint: light-dark(var(--accent-tint-light), var(--accent-tint-dark));
                --color-ok: light-dark(var(--status-ok-light), var(--status-ok-dark));
                --color-warn: light-dark(var(--status-warn-light), var(--status-warn-dark));
                --color-err: light-dark(var(--status-err-light), var(--status-err-dark));
                --color-err-bg: light-dark(var(--status-err-bg-light), var(--status-err-bg-dark));
                --color-err-rule: light-dark(var(--status-err-rule-light), var(--status-err-rule-dark));
                --color-focus: light-dark(var(--accent-light), var(--accent-dark));
            }

            accent-color: var(--color-accent);
        }

        :root:has(> head > meta[name="color-scheme"][content="dark"]) {
            color-scheme: dark;
            --color-paper: var(--paper-dark);
            --color-paper-2: var(--paper-elevated-dark);
            --color-paper-3: var(--paper-inset-dark);
            --color-ink: var(--ink-dark);
            --color-ink-2: var(--ink-secondary-dark);
            --color-muted: var(--ink-muted-dark);
            --color-rule: var(--rule-dark);
            --color-rule-strong: var(--rule-strong-dark);
            --color-accent: var(--accent-dark);
            --color-accent-hover: var(--accent-hover-dark);
            --color-accent-active: var(--accent-active-dark);
            --color-accent-ink: var(--accent-ink-dark);
            --color-accent-tint: var(--accent-tint-dark);
            --color-ok: var(--status-ok-dark);
            --color-warn: var(--status-warn-dark);
            --color-err: var(--status-err-dark);
            --color-err-bg: var(--status-err-bg-dark);
            --color-err-rule: var(--status-err-rule-dark);
            --color-focus: var(--accent-dark);
        }

        :root:has(> head > meta[name="color-scheme"][content="light"]) {
            color-scheme: light;
            --color-paper: var(--paper-light);
            --color-paper-2: var(--paper-elevated-light);
            --color-paper-3: var(--paper-inset-light);
            --color-ink: var(--ink-light);
            --color-ink-2: var(--ink-secondary-light);
            --color-muted: var(--ink-muted-light);
            --color-rule: var(--rule-light);
            --color-rule-strong: var(--rule-strong-light);
            --color-accent: var(--accent-light);
            --color-accent-hover: var(--accent-hover-light);
            --color-accent-active: var(--accent-active-light);
            --color-accent-ink: var(--accent-ink-light);
            --color-accent-tint: var(--accent-tint-light);
            --color-ok: var(--status-ok-light);
            --color-warn: var(--status-warn-light);
            --color-err: var(--status-err-light);
            --color-err-bg: var(--status-err-bg-light);
            --color-err-rule: var(--status-err-rule-light);
            --color-focus: var(--accent-light);
        }

        body {
            font-family: var(--font-body);
            background-color: var(--color-paper);
            color: var(--color-ink);
            line-height: 1.5;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            position: relative;
            -webkit-font-smoothing: antialiased;
        }

        /* Subtle tactile archival grain (Hallmark E7 recipe, < 0.04 opacity) */
        .paper-grain {
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 10;
            opacity: 0.032;
        }

        .tabular {
            font-variant-numeric: tabular-nums;
        }

        /* ── 1. Mandatory Server Identity Strip (partials/served_by.php) ── */
        .served-strip {
            background-color: var(--color-paper-2);
            border-block-end: 1px solid var(--color-rule);
            padding-block: var(--space-xs);
            padding-inline: clamp(var(--space-md), 3vw, var(--space-xl));
            font-size: 0.8125rem;
            color: var(--color-ink-2);
        }

        .served-strip__inner {
            max-width: 88rem;
            margin-inline: auto;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: var(--space-xs) var(--space-md);
        }

        .served-strip__identity {
            display: inline-flex;
            align-items: center;
            gap: var(--space-xs);
            min-width: 0;
        }

        .served-strip__identity strong {
            color: var(--color-ink);
            font-weight: 600;
        }

        .served-strip__meta {
            display: inline-flex;
            align-items: center;
            gap: var(--space-xs);
            line-height: 1;
            font-family: var(--font-mono);
            font-size: 0.75rem;
        }

        .status-led {
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 50%;
            background-color: var(--color-ok);
            flex-shrink: 0;
        }

        .status-led.is-paused {
            background-color: var(--color-warn);
        }

        .route-tag {
            font-family: var(--font-mono);
            font-size: 0.75rem;
            font-weight: 600;
            line-height: 1;
            color: var(--color-ink);
            background-color: var(--color-paper-3);
            border: 1px solid var(--color-rule-strong);
            padding: var(--space-3xs) var(--space-xs);
            white-space: nowrap;
        }

        /* ── 2. Broadsheet Masthead (N9 Edge-Aligned Architectural Header) ── */
        .masthead {
            border-block-end: 2px solid var(--color-ink);
            padding-block: var(--space-md) var(--space-lg);
            padding-inline: clamp(var(--space-md), 3vw, var(--space-xl));
        }

        .masthead__inner {
            max-width: 88rem;
            margin-inline: auto;
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            justify-content: space-between;
            gap: var(--space-md);
        }

        .masthead__left {
            display: flex;
            flex-direction: column;
            gap: var(--space-2xs);
            min-width: 0;
        }

        .masthead__title-row {
            display: flex;
            align-items: baseline;
            flex-wrap: wrap;
            gap: var(--space-sm);
        }

        .masthead__title {
            font-family: var(--font-display);
            font-size: clamp(1.85rem, 3.5vw + 0.75rem, 3rem);
            font-weight: 800;
            font-style: normal;
            line-height: 1.02;
            letter-spacing: -0.03em;
            color: var(--color-ink);
            text-decoration: none;
            overflow-wrap: anywhere;
            min-width: 0;
            outline: 2px solid transparent;
            outline-offset: 2px;
        }

        .masthead__title:hover {
            color: var(--color-accent);
        }

        .masthead__title:focus-visible {
            outline-color: var(--color-focus);
        }

        .masthead__coords {
            font-family: var(--font-mono);
            font-size: 0.75rem;
            color: var(--color-muted);
            font-variant-numeric: tabular-nums;
        }

        .masthead__controls {
            display: flex;
            align-items: center;
            gap: var(--space-xs);
        }

        /* ── Interactive Buttons (Gate 26, 36, 39, 49) ── */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: var(--space-xs);
            min-height: 2.75rem;
            padding-block: var(--space-xs);
            padding-inline: var(--space-md);
            font-family: var(--font-body);
            font-size: 0.8125rem;
            font-weight: 600;
            line-height: 1;
            white-space: nowrap;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid var(--color-rule-strong);
            background-color: var(--color-paper);
            color: var(--color-ink);
            outline: 2px solid transparent;
            outline-offset: 1px;
            transition: background-color 120ms ease-out, border-color 120ms ease-out, color 120ms ease-out;
        }

        .btn:hover {
            background-color: var(--color-paper-2);
            border-color: var(--color-ink);
        }

        .btn:focus-visible {
            outline: 2px solid var(--color-focus);
            outline-offset: 1px;
        }

        .btn:active {
            background-color: var(--color-paper-3);
        }

        .btn:disabled,
        .btn[aria-disabled="true"] {
            opacity: 0.55;
            cursor: not-allowed;
        }

        .btn--primary {
            background-color: var(--color-accent);
            border-color: var(--color-accent);
            color: var(--color-accent-ink);
            font-family: var(--font-mono);
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        .btn--primary:hover {
            background-color: var(--color-accent-hover);
            border-color: var(--color-accent-hover);
        }

        .btn--primary:active {
            background-color: var(--color-accent-active);
            border-color: var(--color-accent-active);
        }

        .btn--compact {
            padding-inline: var(--space-sm);
        }

        .icon-svg {
            width: 1rem;
            height: 1rem;
            stroke: currentColor;
            stroke-width: 2;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
            flex-shrink: 0;
        }

        /* ── 3. Interactive 5-Node Topology Patchbay (m0..m4) ── */
        .topology-strip {
            border-block-end: 1px solid var(--color-rule-strong);
            background-color: var(--color-paper-2);
        }

        .topology-strip__inner {
            max-width: 88rem;
            margin-inline: auto;
            border-inline: 1px solid var(--color-rule);
        }

        .topology-matrix {
            list-style: none;
            display: grid;
            grid-template-columns: minmax(0, 1fr);
        }

        @media (min-width: 36rem) {
            .topology-matrix {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 62rem) {
            .topology-matrix {
                grid-template-columns: repeat(5, minmax(0, 1fr));
            }
        }

        .topology-cell {
            border-block-end: 1px solid var(--color-rule);
        }

        @media (min-width: 36rem) {
            .topology-cell {
                border-inline-end: 1px solid var(--color-rule);
            }
        }

        @media (min-width: 62rem) {
            .topology-cell {
                border-block-end: none;
            }
            .topology-cell:last-child {
                border-inline-end: none;
            }
        }

        .topology-btn {
            width: 100%;
            min-height: 4.25rem;
            padding: var(--space-sm) var(--space-md);
            background-color: transparent;
            border: none;
            color: var(--color-ink);
            font-family: inherit;
            text-align: left;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: var(--space-2xs);
            outline: 2px solid transparent;
            outline-offset: -2px;
            transition: background-color 120ms ease-out;
        }

        .topology-btn:hover {
            background-color: var(--color-paper-3);
        }

        .topology-btn:focus-visible {
            outline: 2px solid var(--color-focus);
            outline-offset: -2px;
        }

        .topology-btn:active {
            background-color: var(--color-accent-tint);
        }

        .topology-btn[aria-pressed="true"] {
            background-color: var(--color-accent-tint);
        }

        .topology-cell--current .topology-btn {
            background-color: var(--color-paper);
        }

        .topology-cell--current .topology-btn:hover {
            background-color: var(--color-paper-3);
        }

        .topology-btn__top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--space-xs);
            width: 100%;
            line-height: 1;
        }

        .topology-btn__route {
            font-family: var(--font-mono);
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--color-accent);
            display: inline-flex;
            align-items: center;
            gap: var(--space-2xs);
            white-space: nowrap;
        }

        .topology-btn__nim {
            font-family: var(--font-mono);
            font-size: 0.6875rem;
            color: var(--color-muted);
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .topology-btn__name {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--color-ink);
            line-height: 1.25;
            overflow-wrap: anywhere;
            min-width: 0;
        }

        /* ── 4. Main Architectural Ledger Split-Pane (Zero Floating Cards) ── */
        .ledger-workspace {
            flex: 1;
            width: 100%;
            max-width: 88rem;
            margin-inline: auto;
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            border-inline: 1px solid var(--color-rule);
        }

        @media (min-width: 58rem) {
            .ledger-workspace {
                grid-template-columns: 24rem minmax(0, 1fr);
            }
        }

        /* Left Rail: Dispatch Composer */
        .dispatch-desk {
            border-block-end: 1px solid var(--color-rule-strong);
            padding-block: var(--space-lg);
            padding-inline: clamp(var(--space-md), 2.5vw, var(--space-lg));
            display: flex;
            flex-direction: column;
            gap: var(--space-md);
        }

        @media (min-width: 58rem) {
            .dispatch-desk {
                border-block-end: none;
                border-inline-end: 1px solid var(--color-rule-strong);
            }
        }

        .desk-header {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: var(--space-xs);
            padding-block-end: var(--space-sm);
            border-block-end: 1px solid var(--color-rule);
        }

        .desk-title {
            font-family: var(--font-display);
            font-size: 1.25rem;
            font-weight: 700;
            font-style: normal;
            line-height: 1.1;
            color: var(--color-ink);
            overflow-wrap: anywhere;
            min-width: 0;
        }

        .desk-stamp {
            font-family: var(--font-mono);
            font-size: 0.75rem;
            color: var(--color-muted);
            white-space: nowrap;
        }

        /* Form Fields & Meter Bar (Gate 39) */
        .field {
            margin-block-end: var(--space-xs);
        }

        .field__header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--space-xs);
            margin-block-end: var(--space-2xs);
        }

        .field__label {
            font-family: var(--font-mono);
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--color-ink-2);
            line-height: 1.2;
        }

        .field__counter {
            font-family: var(--font-mono);
            font-size: 0.75rem;
            color: var(--color-muted);
            font-variant-numeric: tabular-nums;
            line-height: 1;
        }

        .form-control {
            display: block;
            width: 100%;
            min-height: 2.75rem;
            padding-block: var(--space-xs);
            padding-inline: var(--space-sm);
            font-family: var(--font-body);
            font-size: 0.9375rem;
            line-height: 1.5;
            color: var(--color-ink);
            background-color: var(--color-paper-2);
            border: 1px solid var(--color-rule-strong);
            outline: 2px solid transparent;
            outline-offset: 1px;
            transition: border-color 120ms ease-out, background-color 120ms ease-out;
        }

        .form-control::placeholder {
            color: var(--color-muted);
        }

        .form-control:hover {
            border-color: var(--color-ink-2);
        }

        .form-control:focus-visible {
            background-color: var(--color-paper);
            border-color: var(--color-accent);
            outline: 2px solid var(--color-focus);
            outline-offset: 1px;
        }

        .form-control:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }

        textarea.form-control {
            min-height: 7.25rem;
            resize: vertical;
        }

        /* Tactile 2px proportional meter rule under textarea */
        .meter-track {
            width: 100%;
            height: 2px;
            background-color: var(--color-rule);
            overflow: hidden;
        }

        .meter-fill {
            height: 100%;
            width: 100%;
            background-color: var(--color-accent);
            transform-origin: left center;
            transform: scaleX(var(--meter-scale, 0));
            transition: transform 80ms ease-out;
        }

        /* Reserved 1lh error slot — never collapses when empty (Gate 39) */
        .error-msg {
            min-height: 1.25rem;
            margin-block-start: var(--space-3xs);
            font-family: var(--font-mono);
            font-size: 0.75rem;
            line-height: 1.25rem;
            color: var(--color-err);
            display: flex;
            align-items: center;
            gap: var(--space-2xs);
            visibility: hidden;
        }

        .form-control:not([data-pristine="true"]):user-invalid,
        .form-control.user-invalid-fallback {
            border-color: var(--color-err);
            background-color: var(--color-err-bg);
        }

        .form-control:not([data-pristine="true"]):user-invalid ~ .error-msg,
        .form-control.user-invalid-fallback ~ .error-msg {
            visibility: visible;
        }

        .compose-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--space-sm);
            padding-block-start: var(--space-2xs);
        }

        .kbd-hint {
            font-family: var(--font-mono);
            font-size: 0.6875rem;
            color: var(--color-muted);
            line-height: 1.2;
        }

        .notice {
            padding: var(--space-xs) var(--space-sm);
            border: 1px solid var(--color-rule-strong);
            font-size: 0.8125rem;
            line-height: 1.4;
        }

        .notice--error {
            background-color: var(--color-err-bg);
            border-color: var(--color-err-rule);
            color: var(--color-err);
        }

        /* ── Right Column: Chronological Dispatch Stream ── */
        .stream-column {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .stream-toolbar {
            padding-block: var(--space-md);
            padding-inline: clamp(var(--space-md), 2.5vw, var(--space-lg));
            border-block-end: 1px solid var(--color-rule-strong);
            background-color: var(--color-paper);
            display: flex;
            flex-direction: column;
            gap: var(--space-sm);
        }

        .stream-toolbar__top {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: var(--space-sm);
        }

        .stream-toolbar__heading-group {
            display: flex;
            align-items: baseline;
            flex-wrap: wrap;
            gap: var(--space-sm);
        }

        .stream-toolbar__title {
            font-family: var(--font-display);
            font-size: 1.25rem;
            font-weight: 700;
            font-style: normal;
            line-height: 1.1;
            color: var(--color-ink);
            overflow-wrap: anywhere;
            min-width: 0;
        }

        .stream-toolbar__stat {
            font-family: var(--font-mono);
            font-size: 0.75rem;
            color: var(--color-muted);
            font-variant-numeric: tabular-nums;
        }

        .stream-toolbar__telemetry {
            display: inline-flex;
            align-items: center;
            gap: var(--space-xs);
            font-family: var(--font-mono);
            font-size: 0.75rem;
            color: var(--color-ink-2);
            line-height: 1;
        }

        .stream-toolbar__controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: var(--space-xs);
        }

        .search-form {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: var(--space-xs);
            flex: 1;
            min-width: 0;
        }

        .search-form__input {
            flex: 1;
            min-width: 10rem;
        }

        /* ── Indexed Ledger Stream Rows (Zero Floating Boxes) ── */
        .dispatch-ledger {
            list-style: none;
            flex: 1;
        }

        .dispatch-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: var(--space-2xs) var(--space-md);
            padding-block: var(--space-md);
            padding-inline: clamp(var(--space-md), 2.5vw, var(--space-lg));
            border-block-end: 1px solid var(--color-rule);
            transition: background-color 120ms ease-out;
        }

        @media (min-width: 44rem) {
            .dispatch-row {
                grid-template-columns: 9.5rem minmax(0, 1fr);
                align-items: baseline;
            }
        }

        .dispatch-row:hover {
            background-color: var(--color-paper-2);
        }

        .dispatch-row__stamp {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--space-xs);
            font-family: var(--font-mono);
            font-size: 0.75rem;
            color: var(--color-muted);
            font-variant-numeric: tabular-nums;
            line-height: 1.3;
        }

        @media (min-width: 44rem) {
            .dispatch-row__stamp {
                flex-direction: column;
                align-items: flex-start;
                gap: var(--space-3xs);
            }
        }

        .dispatch-row__id {
            color: var(--color-ink-2);
            font-weight: 600;
        }

        .dispatch-row__main {
            min-width: 0;
        }

        .dispatch-row__header {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: var(--space-xs);
            margin-block-end: var(--space-2xs);
            line-height: 1.2;
        }

        .dispatch-row__author {
            font-weight: 700;
            font-size: 0.9375rem;
            color: var(--color-ink);
            overflow-wrap: anywhere;
            min-width: 0;
        }

        .dispatch-row__body {
            font-size: 0.9375rem;
            line-height: 1.6;
            color: var(--color-ink-2);
            max-width: 65ch;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            min-width: 0;
        }

        .dispatch-empty {
            padding-block: var(--space-3xl);
            padding-inline: var(--space-lg);
            font-family: var(--font-mono);
            font-size: 0.8125rem;
            color: var(--color-muted);
            border-block-end: 1px solid var(--color-rule);
        }

        /* ── 5. Footer Colophon (Ft2 Single-Line Architectural Strip) ── */
        .colophon {
            border-block-start: 1px solid var(--color-rule-strong);
            background-color: var(--color-paper-2);
            padding-block: var(--space-md);
            padding-inline: clamp(var(--space-md), 3vw, var(--space-xl));
            font-family: var(--font-mono);
            font-size: 0.75rem;
            color: var(--color-muted);
        }

        .colophon__inner {
            max-width: 88rem;
            margin-inline: auto;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: var(--space-xs) var(--space-md);
        }

        @media (forced-colors: active) {
            .btn, .form-control, .route-tag {
                border: 1px solid ButtonText;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                transition-duration: 0.01ms !important;
                animation-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body>
    <!-- Subtle tactile paper grain overlay (Gate 33 aria-hidden="true") -->
    <svg class="paper-grain" aria-hidden="true">
        <filter id="archival-grain">
            <feTurbulence type="fractalNoise" baseFrequency="0.8" numOctaves="3" stitchTiles="stitch"></feTurbulence>
        </filter>
        <rect width="100%" height="100%" filter="url(#archival-grain)"></rect>
    </svg>

    <?php include __DIR__ . '/partials/served_by.php'; ?>

    <header class="masthead">
        <div class="masthead__inner">
            <div class="masthead__left">
                <div class="masthead__title-row">
                    <a href="index.php" class="masthead__title">SHOUTBOX</a>
                    <span class="route-tag">NODE <?= e(strtoupper((string) MEMBER_ROUTE)) ?></span>
                </div>
                <p class="masthead__coords">DB: kelompok_db · CLUSTER ROUTE m0–m4 · AUTO-SYNC 4S</p>
            </div>

            <nav class="masthead__controls" aria-label="Navigasi Aplikasi">
                <a href="../index.php" class="btn btn--compact">Personal Page</a>
                <button type="button" id="themeToggleBtn" class="btn btn--compact" aria-label="Ganti skema warna">
                    <svg class="icon-svg" viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="12" cy="12" r="4"></circle>
                        <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"></path>
                    </svg>
                    <span>Tema</span>
                </button>
            </nav>
        </div>
    </header>

    <!-- Interactive 5-Node Topology Matrix (Click any node to filter stream) -->
    <section class="topology-strip" aria-label="Topologi Node Kelompok">
        <div class="topology-strip__inner">
            <ul class="topology-matrix" id="nodeMatrix">
                <?php foreach ($anggotaList as $row): ?>
                    <?php
                        $rId = (string) $row['route_id'];
                        $isCurrent = ($rId === (string) MEMBER_ROUTE);
                        $isFiltered = (strtolower($searchQuery) === strtolower($rId));
                    ?>
                    <li class="topology-cell <?= $isCurrent ? 'topology-cell--current' : '' ?>">
                        <button
                            type="button"
                            class="topology-btn"
                            data-route="<?= e($rId) ?>"
                            aria-pressed="<?= $isFiltered ? 'true' : 'false' ?>"
                            title="Filter pesan dari node <?= e($rId) ?>"
                        >
                            <span class="topology-btn__top">
                                <span class="topology-btn__route">
                                    <?php if ($isCurrent): ?>
                                        <span class="status-led" aria-hidden="true"></span>
                                    <?php endif; ?>
                                    <span><?= e($rId) ?></span>
                                </span>
                                <span class="topology-btn__nim"><?= e((string) $row['nim']) ?></span>
                            </span>
                            <span class="topology-btn__name"><?= e((string) $row['nama']) ?></span>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>

    <main class="ledger-workspace">
        <!-- Left Rail: Dispatch Composer -->
        <aside class="dispatch-desk" aria-labelledby="form-heading">
            <div class="desk-header">
                <h1 id="form-heading" class="desk-title">Transmisi Baru</h1>
                <span class="desk-stamp">ORIGIN // <?= e(strtoupper((string) MEMBER_ROUTE)) ?></span>
            </div>

            <?php if (!$dbReady): ?>
                <div class="notice notice--error" role="alert">
                    <strong>Database:</strong> <?= e($dbErrorMsg) ?>
                </div>
            <?php endif; ?>

            <div id="ajaxFeedback" aria-live="polite">
                <?php if ($formError !== ''): ?>
                    <div class="notice notice--error"><?= e($formError) ?></div>
                <?php endif; ?>
            </div>

            <form id="shoutForm" method="post" action="index.php" novalidate>
                <div class="field">
                    <div class="field__header">
                        <label for="namaInput" class="field__label">PENGIRIM *</label>
                        <span id="namaCounter" class="field__counter">0/100</span>
                    </div>
                    <input
                        type="text"
                        id="namaInput"
                        name="nama"
                        class="form-control"
                        minlength="2"
                        maxlength="100"
                        required
                        placeholder="Nama lengkap atau panggilan"
                        value="<?= e($oldNama) ?>"
                        autocomplete="name"
                        aria-errormessage="namaErrorMsg"
                    >
                    <div id="namaErrorMsg" class="error-msg">
                        <span aria-hidden="true">!</span>
                        <span>Nama wajib diisi (2–100 karakter).</span>
                    </div>
                </div>

                <div class="field">
                    <div class="field__header">
                        <label for="pesanInput" class="field__label">PESAN SINGKAT *</label>
                        <span id="pesanCounter" class="field__counter">0/500</span>
                    </div>
                    <textarea
                        id="pesanInput"
                        name="pesan"
                        class="form-control"
                        maxlength="500"
                        required
                        placeholder="Ketik pesan transmisi..."
                        aria-errormessage="pesanErrorMsg"
                    ><?= e($oldPesan) ?></textarea>
                    <div class="meter-track" aria-hidden="true">
                        <div id="pesanMeter" class="meter-fill"></div>
                    </div>
                    <div id="pesanErrorMsg" class="error-msg">
                        <span aria-hidden="true">!</span>
                        <span>Pesan tidak boleh kosong (maks. 500 karakter).</span>
                    </div>
                </div>

                <div class="compose-footer">
                    <span class="kbd-hint">Enter ↵ kirim · Shift+Enter baris baru</span>
                    <button type="submit" id="submitBtn" class="btn btn--primary">
                        <span>KIRIM</span>
                    </button>
                </div>
            </form>
        </aside>

        <!-- Right Main Column: Chronological Dispatch Stream -->
        <section class="stream-column" aria-labelledby="feed-heading">
            <div class="stream-toolbar">
                <div class="stream-toolbar__top">
                    <div class="stream-toolbar__heading-group">
                        <h2 id="feed-heading" class="stream-toolbar__title">Log Transmisi</h2>
                        <span class="stream-toolbar__stat"><strong id="shoutCount"><?= count($shouts) ?></strong> rekaman</span>
                    </div>

                    <div class="stream-toolbar__telemetry">
                        <span id="pollDot" class="status-led" aria-hidden="true"></span>
                        <span id="pollStatusText">LIVE · <strong id="lastServedNode"><?= e(MEMBER_ROUTE) ?></strong></span>
                    </div>
                </div>

                <div class="stream-toolbar__controls">
                    <form id="searchForm" class="search-form" method="get" action="index.php" role="search">
                        <input
                            type="search"
                            id="searchInput"
                            name="q"
                            class="form-control search-form__input"
                            placeholder="Cari pengirim, isi pesan, atau route (m0–m4)..."
                            value="<?= e($searchQuery) ?>"
                            aria-label="Filter log transmisi"
                        >
                        <button type="submit" class="btn btn--compact">Filter</button>
                        <button type="button" id="clearFilterBtn" class="btn btn--compact" <?= $searchQuery === '' ? 'hidden' : '' ?>>Reset</button>
                    </form>

                    <button type="button" id="manualRefreshBtn" class="btn btn--compact">Segarkan</button>
                    <button type="button" id="togglePollBtn" class="btn btn--compact">Jeda</button>
                </div>
            </div>

            <ul id="shoutList" class="dispatch-ledger" aria-live="polite">
                <?php if (empty($shouts)): ?>
                    <li class="dispatch-empty">0 rekaman ditemukan pada log transmisi.</li>
                <?php else: ?>
                    <?php foreach ($shouts as $item): ?>
                        <?php $itemId = (int) $item['id']; ?>
                        <li class="dispatch-row" data-id="<?= $itemId ?>">
                            <div class="dispatch-row__stamp">
                                <span class="dispatch-row__id">#<?= str_pad((string) $itemId, 4, '0', STR_PAD_LEFT) ?></span>
                                <time datetime="<?= e((string) $item['created_at']) ?>"><?= e((string) $item['created_at']) ?></time>
                            </div>
                            <div class="dispatch-row__main">
                                <div class="dispatch-row__header">
                                    <span class="dispatch-row__author"><?= e((string) $item['nama']) ?></span>
                                    <span class="route-tag" title="Diterima oleh server <?= e((string) ($item['anggota_nama'] ?? $item['route_id'])) ?>">
                                        <?= e((string) $item['route_id']) ?>
                                    </span>
                                </div>
                                <div class="dispatch-row__body"><?= e((string) $item['pesan']) ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </section>
    </main>

    <footer class="colophon">
        <div class="colophon__inner">
            <span>SHOUTBOX // TUGAS 1 PRA-UTS CLOUD COMPUTING</span>
            <span class="tabular">SERVER PELAYAN: <strong><?= e(strtoupper((string) MEMBER_ROUTE)) ?></strong> · <?= e((string) MEMBER_NIM) ?></span>
        </div>
    </footer>

    <script>
        (function () {
            'use strict';

            // 1. Two-State Color Scheme Toggle (modern-web-guidance dark-mode-toggle)
            const colorSchemeMeta = document.querySelector('meta[name="color-scheme"]');
            const themeBtn = document.getElementById('themeToggleBtn');

            function getSystemScheme() {
                return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }

            function getResolvedScheme() {
                const content = colorSchemeMeta ? colorSchemeMeta.getAttribute('content') : 'light dark';
                if (content === 'dark' || content === 'light') return content;
                return getSystemScheme();
            }

            if (themeBtn && colorSchemeMeta) {
                themeBtn.addEventListener('click', function () {
                    const currentRendered = getResolvedScheme();
                    const target = currentRendered === 'dark' ? 'light' : 'dark';
                    const sys = getSystemScheme();

                    if (target === sys) {
                        try { localStorage.removeItem('color-scheme'); } catch (e) {}
                        colorSchemeMeta.setAttribute('content', 'light dark');
                    } else {
                        try { localStorage.setItem('color-scheme', target); } catch (e) {}
                        colorSchemeMeta.setAttribute('content', target);
                    }
                });

                window.addEventListener('storage', function (e) {
                    if (e.key === 'color-scheme') {
                        colorSchemeMeta.setAttribute('content', e.newValue ?? 'light dark');
                    }
                });
            }

            // 2. Character Counters, Meter Bar & Accessible Validation State
            const shoutForm = document.getElementById('shoutForm');
            const namaInput = document.getElementById('namaInput');
            const pesanInput = document.getElementById('pesanInput');
            const namaCounter = document.getElementById('namaCounter');
            const pesanCounter = document.getElementById('pesanCounter');
            const pesanMeter = document.getElementById('pesanMeter');
            const submitBtn = document.getElementById('submitBtn');
            const ajaxFeedback = document.getElementById('ajaxFeedback');

            const dirtyState = new WeakMap();

            function isFieldValid(input) {
                if (!input) return true;
                const val = input.value.trim();
                if (input === namaInput) {
                    return val.length >= 2 && val.length <= 100;
                }
                if (input === pesanInput) {
                    return val.length >= 1 && val.length <= 500;
                }
                return input.checkValidity();
            }

            function clearFieldError(input) {
                if (!input) return;
                dirtyState.delete(input);
                input.setAttribute('data-pristine', 'true');
                input.classList.remove('user-invalid-fallback');
                input.removeAttribute('aria-invalid');
            }

            function applyFieldError(input) {
                if (!input) return;
                input.removeAttribute('data-pristine');
                input.classList.add('user-invalid-fallback');
                input.setAttribute('aria-invalid', 'true');
            }

            function syncFieldState(input) {
                if (!input || !input.matches('input[required], textarea[required]')) return;
                if (isFieldValid(input)) {
                    input.classList.remove('user-invalid-fallback');
                    input.removeAttribute('aria-invalid');
                } else {
                    const state = dirtyState.get(input);
                    if (state && state.hasInteracted && state.hasBlurred) {
                        applyFieldError(input);
                    }
                }
            }

            if (shoutForm) {
                shoutForm.addEventListener('input', function (e) {
                    const target = e.target;
                    if (!target || !target.matches('input[required], textarea[required]')) return;
                    target.removeAttribute('data-pristine');
                    const state = dirtyState.get(target) || { hasInteracted: false, hasBlurred: false };
                    state.hasInteracted = true;
                    dirtyState.set(target, state);

                    if (isFieldValid(target)) {
                        target.classList.remove('user-invalid-fallback');
                        target.removeAttribute('aria-invalid');
                    } else if (state.hasBlurred) {
                        applyFieldError(target);
                    }
                });

                shoutForm.addEventListener('blur', function (e) {
                    const target = e.target;
                    if (!target || !target.matches('input[required], textarea[required]')) return;
                    const state = dirtyState.get(target);
                    if (state && state.hasInteracted) {
                        state.hasBlurred = true;
                        dirtyState.set(target, state);
                        syncFieldState(target);
                    }
                }, true);

                shoutForm.addEventListener('reset', function () {
                    [namaInput, pesanInput].forEach(clearFieldError);
                });
            }

            // Enter (without Shift) inside pesanInput submits the form cleanly
            if (pesanInput && shoutForm) {
                pesanInput.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        if (typeof shoutForm.requestSubmit === 'function') {
                            shoutForm.requestSubmit();
                        } else {
                            shoutForm.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                        }
                    }
                });
            }

            function updateCounters() {
                if (namaInput && namaCounter) {
                    namaCounter.textContent = namaInput.value.length + '/100';
                }
                if (pesanInput && pesanCounter) {
                    const len = pesanInput.value.length;
                    pesanCounter.textContent = len + '/500';
                    if (pesanMeter) {
                        const scale = Math.min(1, len / 500);
                        pesanMeter.style.setProperty('--meter-scale', String(scale));
                    }
                }
            }

            if (namaInput) namaInput.addEventListener('input', updateCounters);
            if (pesanInput) pesanInput.addEventListener('input', updateCounters);
            updateCounters();

            function showErrorBanner(message) {
                if (!ajaxFeedback) return;
                ajaxFeedback.textContent = '';
                if (!message) return;
                const box = document.createElement('div');
                box.className = 'notice notice--error';
                box.textContent = message;
                ajaxFeedback.appendChild(box);
            }

            // 3. Safe DOM Rendering for Shoutbox Items (Anti-XSS via textContent)
            const shoutList = document.getElementById('shoutList');
            const shoutCount = document.getElementById('shoutCount');
            const pollStatusText = document.getElementById('pollStatusText');
            const pollDot = document.getElementById('pollDot');

            function padId(num) {
                const s = String(num || 0);
                return '#' + s.padStart(4, '0');
            }

            function renderShoutList(items) {
                if (!shoutList) return;
                shoutList.textContent = '';

                if (shoutCount) {
                    shoutCount.textContent = String(items.length);
                }

                if (!Array.isArray(items) || items.length === 0) {
                    const emptyLi = document.createElement('li');
                    emptyLi.className = 'dispatch-empty';
                    emptyLi.textContent = '0 rekaman ditemukan pada log transmisi.';
                    shoutList.appendChild(emptyLi);
                    return;
                }

                items.forEach(function (item) {
                    const li = document.createElement('li');
                    li.className = 'dispatch-row';
                    li.setAttribute('data-id', String(item.id || ''));

                    const stampDiv = document.createElement('div');
                    stampDiv.className = 'dispatch-row__stamp';

                    const idSpan = document.createElement('span');
                    idSpan.className = 'dispatch-row__id';
                    idSpan.textContent = padId(item.id);

                    const timeEl = document.createElement('time');
                    timeEl.setAttribute('datetime', String(item.created_at || ''));
                    timeEl.textContent = String(item.created_at || '');

                    stampDiv.appendChild(idSpan);
                    stampDiv.appendChild(timeEl);

                    const mainDiv = document.createElement('div');
                    mainDiv.className = 'dispatch-row__main';

                    const headerDiv = document.createElement('div');
                    headerDiv.className = 'dispatch-row__header';

                    const authorSpan = document.createElement('span');
                    authorSpan.className = 'dispatch-row__author';
                    authorSpan.textContent = String(item.nama || '').trim();

                    const nodeSpan = document.createElement('span');
                    nodeSpan.className = 'route-tag';
                    nodeSpan.title = 'Diterima oleh server ' + String(item.anggota_nama || item.route_id || '');
                    nodeSpan.textContent = String(item.route_id || '');

                    headerDiv.appendChild(authorSpan);
                    headerDiv.appendChild(nodeSpan);

                    const bodyDiv = document.createElement('div');
                    bodyDiv.className = 'dispatch-row__body';
                    bodyDiv.textContent = String(item.pesan || '');

                    mainDiv.appendChild(headerDiv);
                    mainDiv.appendChild(bodyDiv);

                    li.appendChild(stampDiv);
                    li.appendChild(mainDiv);
                    shoutList.appendChild(li);
                });
            }

            // 4. AJAX Polling, Live Search & Interactive Topology Filter
            const searchInput = document.getElementById('searchInput');
            const searchForm = document.getElementById('searchForm');
            const clearFilterBtn = document.getElementById('clearFilterBtn');
            const manualRefreshBtn = document.getElementById('manualRefreshBtn');
            const togglePollBtn = document.getElementById('togglePollBtn');
            const nodeMatrix = document.getElementById('nodeMatrix');

            let pollingActive = true;
            let pollTimer = null;

            function syncTopologyButtons() {
                if (!nodeMatrix || !searchInput) return;
                const q = searchInput.value.trim().toLowerCase();
                const btns = nodeMatrix.querySelectorAll('.topology-btn');
                btns.forEach(function (btn) {
                    const r = (btn.getAttribute('data-route') || '').toLowerCase();
                    btn.setAttribute('aria-pressed', (q !== '' && q === r) ? 'true' : 'false');
                });
                if (clearFilterBtn) {
                    clearFilterBtn.hidden = (q === '');
                }
            }

            function updateSyncLabel(route, timeStr) {
                if (!pollStatusText) return;
                pollStatusText.textContent = '';
                const label = (pollingActive ? 'SYNC ' + (timeStr || '4S') + ' · ' : 'PAUSED · ');
                pollStatusText.appendChild(document.createTextNode(label));
                const strongNode = document.createElement('strong');
                strongNode.textContent = route || '<?= e(MEMBER_ROUTE) ?>';
                pollStatusText.appendChild(strongNode);
            }

            async function fetchLatestShouts() {
                const q = searchInput ? searchInput.value.trim() : '';
                const url = 'index.php?action=fetch&q=' + encodeURIComponent(q);

                try {
                    const response = await fetch(url, {
                        method: 'GET',
                        headers: { 'Accept': 'application/json' },
                        cache: 'no-store'
                    });
                    const data = await response.json();
                    if (data && data.ok && Array.isArray(data.items)) {
                        renderShoutList(data.items);
                        if (data.served_by) {
                            updateSyncLabel(data.served_by.route_id || '', data.served_by.time || '');
                        }
                    }
                } catch (err) {
                    // Pertahankan isi stream saat koneksi jaringan terputus sementara
                }
            }

            function startPolling() {
                if (pollTimer) clearInterval(pollTimer);
                pollTimer = setInterval(function () {
                    if (pollingActive) {
                        fetchLatestShouts();
                    }
                }, 4000);
            }

            startPolling();

            if (nodeMatrix && searchInput) {
                nodeMatrix.addEventListener('click', function (e) {
                    const btn = e.target.closest('.topology-btn');
                    if (!btn) return;
                    const route = btn.getAttribute('data-route') || '';
                    if (searchInput.value.trim().toLowerCase() === route.toLowerCase()) {
                        searchInput.value = '';
                    } else {
                        searchInput.value = route;
                    }
                    syncTopologyButtons();
                    fetchLatestShouts();
                });
            }

            if (clearFilterBtn && searchInput) {
                clearFilterBtn.addEventListener('click', function () {
                    searchInput.value = '';
                    syncTopologyButtons();
                    fetchLatestShouts();
                });
            }

            if (manualRefreshBtn) {
                manualRefreshBtn.addEventListener('click', function () {
                    fetchLatestShouts();
                });
            }

            if (togglePollBtn) {
                togglePollBtn.addEventListener('click', function () {
                    pollingActive = !pollingActive;
                    togglePollBtn.textContent = pollingActive ? 'Jeda' : 'Lanjut';
                    if (pollDot) {
                        pollDot.classList.toggle('is-paused', !pollingActive);
                    }
                    if (pollingActive) {
                        fetchLatestShouts();
                    } else {
                        updateSyncLabel('<?= e(MEMBER_ROUTE) ?>', '');
                    }
                });
            }

            if (searchForm && searchInput) {
                let debounceTimeout = null;
                searchInput.addEventListener('input', function () {
                    syncTopologyButtons();
                    clearTimeout(debounceTimeout);
                    debounceTimeout = setTimeout(function () {
                        fetchLatestShouts();
                    }, 250);
                });

                searchForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    syncTopologyButtons();
                    fetchLatestShouts();
                });
            }

            // 5. AJAX Submit Form
            if (shoutForm) {
                shoutForm.addEventListener('submit', async function (e) {
                    e.preventDefault();
                    showErrorBanner('');

                    const namaVal = namaInput ? namaInput.value.trim() : '';
                    const pesanVal = pesanInput ? pesanInput.value.trim() : '';

                    let hasValidationError = false;
                    if (namaVal.length < 2 || namaVal.length > 100) {
                        applyFieldError(namaInput);
                        hasValidationError = true;
                    }
                    if (pesanVal.length < 1 || pesanVal.length > 500) {
                        applyFieldError(pesanInput);
                        hasValidationError = true;
                    }

                    if (hasValidationError) {
                        if (!isFieldValid(namaInput) && namaInput) {
                            namaInput.focus();
                        } else if (pesanInput) {
                            pesanInput.focus();
                        }
                        return;
                    }

                    if (submitBtn) submitBtn.disabled = true;

                    try {
                        const q = searchInput ? searchInput.value.trim() : '';
                        const response = await fetch('index.php?action=post&q=' + encodeURIComponent(q), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: JSON.stringify({
                                nama: namaVal,
                                pesan: pesanVal
                            })
                        });

                        const data = await response.json();
                        if (!response.ok || !data.ok) {
                            showErrorBanner((data && data.error) ? data.error : 'Gagal mengirim pesan.');
                        } else {
                            if (namaInput) {
                                namaInput.defaultValue = namaVal;
                            }
                            if (pesanInput) {
                                pesanInput.defaultValue = '';
                            }
                            shoutForm.reset();
                            if (namaInput) {
                                namaInput.value = namaVal;
                            }
                            clearFieldError(namaInput);
                            clearFieldError(pesanInput);
                            updateCounters();

                            if (Array.isArray(data.items)) {
                                renderShoutList(data.items);
                            } else {
                                fetchLatestShouts();
                            }
                            if (data.served_by) {
                                updateSyncLabel(data.served_by.route_id || '', data.served_by.time || '');
                            }
                        }
                    } catch (err) {
                        showErrorBanner('Gagal menghubungi server. Periksa koneksi lokal Anda.');
                    } finally {
                        if (submitBtn) submitBtn.disabled = false;
                    }
                });
            }
        })();
    </script>
</body>
</html>
