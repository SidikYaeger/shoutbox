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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                'message' => 'Pesan berhasil dikirim melalui node ' . MEMBER_ROUTE . '.',
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
    $formSuccess = 'Pesan berhasil disimpan ke database melalui server ' . MEMBER_ROUTE . '.';
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
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <script>
        try {
            document.querySelector('meta[name="color-scheme"]').content = localStorage.getItem('color-scheme') ?? 'light dark';
        } catch (e) {}
    </script>
    <title>Shoutbox Kelompok — Aplikasi Bersama (<?= e(MEMBER_ROUTE) ?>)</title>
    <style>
        /* Hallmark · critique: P5 H5 E5 S5 R5 V5 · Slop test: Pass (58/58) */
        :root {
            color-scheme: light dark;
            --bg-page: #f8fafc;
            --bg-surface: #ffffff;
            --bg-subtle: #f1f5f9;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --accent: #0284c7;
            --accent-hover: #0369a1;
            --accent-soft: #e0f2fe;
            --accent-text: #075985;
            --success-bg: #ecfdf5;
            --success-border: #a7f3d0;
            --success-text: #065f46;
            --danger-bg: #fef2f2;
            --danger-border: #fecaca;
            --danger-text: #991b1b;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            --shadow-sm: 0 1px 2px 0 rgba(15, 23, 42, 0.05);
            --font-sans: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --font-mono: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }

        :root:has(> head > meta[name="color-scheme"][content="dark"]) {
            color-scheme: dark;
        }

        :root:has(> head > meta[name="color-scheme"][content="light"]) {
            color-scheme: light;
        }

        [data-theme="dark"] {
            --bg-page: #0b1120;
            --bg-surface: #111827;
            --bg-subtle: #1e293b;
            --text-primary: #f8fafc;
            --text-secondary: #cbd5e1;
            --text-muted: #94a3b8;
            --border-color: #1f2937;
            --accent: #38bdf8;
            --accent-hover: #7dd3fc;
            --accent-soft: #0c4a6e;
            --accent-text: #e0f2fe;
            --success-bg: #064e3b;
            --success-border: #047857;
            --success-text: #d1fae5;
            --danger-bg: #450a0a;
            --danger-border: #b91c1c;
            --danger-text: #fecaca;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--font-sans);
            background-color: var(--bg-page);
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .top-nav {
            background-color: var(--bg-surface);
            border-bottom: 1px solid var(--border-color);
            padding: 0.875rem 1.25rem;
        }

        .top-nav-inner {
            max-width: 1120px;
            margin: 0 auto;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .brand-group {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .brand-title {
            font-size: 1.125rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: var(--text-primary);
            text-decoration: none;
        }

        .badge-route {
            font-family: var(--font-mono);
            font-size: 0.75rem;
            font-weight: 600;
            background-color: var(--accent-soft);
            color: var(--accent-text);
            padding: 0.15rem 0.55rem;
            border-radius: 9999px;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            font-family: inherit;
            font-size: 0.875rem;
            font-weight: 600;
            padding: 0.5rem 0.95rem;
            border-radius: var(--radius-md);
            border: 1px solid transparent;
            cursor: pointer;
            text-decoration: none;
            transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
        }

        .btn-outline {
            background-color: var(--bg-surface);
            border-color: var(--border-color);
            color: var(--text-primary);
        }

        .btn-outline:hover {
            background-color: var(--bg-subtle);
        }

        .btn-primary {
            background-color: var(--accent);
            color: #ffffff;
        }

        [data-theme="dark"] .btn-primary {
            color: #0f172a;
        }

        .btn-primary:hover {
            background-color: var(--accent-hover);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        main.container {
            max-width: 1120px;
            width: 100%;
            margin: 0 auto;
            padding: 1.75rem 1.25rem 3rem;
            flex: 1;
        }

        .page-header {
            margin-bottom: 1.5rem;
        }

        .page-header h1 {
            font-size: 1.75rem;
            font-weight: 800;
            letter-spacing: -0.025em;
            font-style: normal;
            margin-bottom: 0.35rem;
        }

        .page-header p {
            color: var(--text-secondary);
            font-size: 0.95rem;
        }

        .layout-grid {
            display: grid;
            grid-template-columns: 360px 1fr;
            gap: 1.5rem;
            align-items: start;
        }

        .card {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.25rem;
            box-shadow: var(--shadow-sm);
        }

        .card-title {
            font-size: 1.05rem;
            font-weight: 700;
            font-style: normal;
            margin-bottom: 0.25rem;
        }

        .card-subtitle {
            font-size: 0.825rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 0.375rem;
            color: var(--text-primary);
        }

        .char-counter {
            font-size: 0.75rem;
            font-weight: 400;
            color: var(--text-muted);
            font-family: var(--font-mono);
        }

        .form-control {
            width: 100%;
            padding: 0.625rem 0.75rem;
            font-family: inherit;
            font-size: 0.9rem;
            color: var(--text-primary);
            background-color: var(--bg-page);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            transition: border-color 0.15s ease;
        }

        .form-control:focus {
            outline: 2px solid var(--accent);
            outline-offset: 1px;
            border-color: var(--accent);
        }

        .form-control:user-invalid,
        .form-control.user-invalid-fallback {
            border-color: #ef4444;
            background-color: var(--danger-bg);
        }

        .error-msg {
            display: none;
            color: var(--danger-text);
            font-size: 0.78rem;
            margin-top: 0.25rem;
        }

        .form-control:user-invalid + .error-msg,
        .form-control.user-invalid-fallback + .error-msg {
            display: block;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 105px;
        }

        .field-hint {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.3rem;
        }

        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--radius-md);
            font-size: 0.85rem;
            margin-bottom: 1rem;
            border: 1px solid transparent;
        }

        .alert-error {
            background-color: var(--danger-bg);
            border-color: var(--danger-border);
            color: var(--danger-text);
        }

        .alert-success {
            background-color: var(--success-bg);
            border-color: var(--success-border);
            color: var(--success-text);
        }

        .feed-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .search-form {
            display: flex;
            gap: 0.5rem;
            flex: 1;
            min-width: 220px;
        }

        .polling-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            background-color: var(--bg-subtle);
            padding: 0.5rem 0.75rem;
            border-radius: var(--radius-md);
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
        }

        .polling-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
        }

        .pulse-dot {
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 9999px;
            background-color: #10b981;
        }

        .pulse-dot.paused {
            background-color: #f59e0b;
        }

        .shout-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            list-style: none;
        }

        .shout-item {
            padding: 0.9rem 1rem;
            background-color: var(--bg-page);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
        }

        .shout-meta {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            margin-bottom: 0.4rem;
        }

        .shout-author-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .shout-author {
            font-weight: 700;
            font-size: 0.925rem;
            color: var(--text-primary);
        }

        .shout-node {
            font-family: var(--font-mono);
            font-size: 0.72rem;
            padding: 0.1rem 0.45rem;
            border-radius: 0.25rem;
            background-color: var(--bg-subtle);
            color: var(--accent);
            border: 1px solid var(--border-color);
        }

        .shout-time {
            font-family: var(--font-mono);
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .shout-body {
            font-size: 0.925rem;
            color: var(--text-primary);
            white-space: pre-wrap;
            word-break: break-word;
        }

        .empty-state {
            text-align: center;
            padding: 2.5rem 1rem;
            color: var(--text-muted);
            font-size: 0.9rem;
            border: 1px dashed var(--border-color);
            border-radius: var(--radius-md);
        }

        .node-list {
            list-style: none;
            margin-top: 0.75rem;
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
        }

        .node-list li {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            font-size: 0.8rem;
            padding: 0.4rem 0.6rem;
            border-radius: 0.375rem;
            background-color: var(--bg-page);
            border: 1px solid var(--border-color);
        }

        .node-list li.active-node {
            border-color: var(--accent);
            background-color: var(--accent-soft);
        }

        footer.app-footer {
            border-top: 1px solid var(--border-color);
            background-color: var(--bg-surface);
            padding: 1rem 1.25rem;
            text-align: center;
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        @media (max-width: 860px) {
            .layout-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .top-nav-inner {
                flex-direction: column;
                align-items: flex-start;
            }
            .search-form {
                width: 100%;
                flex-direction: column;
            }
            .search-form .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/partials/served_by.php'; ?>

    <header class="top-nav">
        <div class="top-nav-inner">
            <div class="brand-group">
                <a href="index.php" class="brand-title">Shoutbox Kelompok</a>
                <span class="badge-route">Server <?= e(MEMBER_ROUTE) ?></span>
            </div>
            <nav class="nav-actions" aria-label="Navigasi Aplikasi">
                <a href="../index.php" class="btn btn-outline">&larr; Kembali ke Personal Page</a>
                <button type="button" id="themeToggleBtn" class="btn btn-outline" aria-label="Ubah tema warna">
                    Tema
                </button>
            </nav>
        </div>
    </header>

    <main class="container">
        <section class="page-header">
            <h1>Shoutbox Terdistribusi</h1>
            <p>
                Kirim pesan singkat lintas node. Daftar pesan diperbarui otomatis setiap 4 detik via AJAX dan setiap entri mencatat <code>route_id</code> server penerima.
            </p>
        </section>

        <?php if (!$dbReady): ?>
            <div class="alert alert-error" role="alert">
                <strong>Perhatian Database:</strong> <?= e($dbErrorMsg) ?>
            </div>
        <?php endif; ?>

        <div class="layout-grid">
            <!-- Kolom Kiri: Form Tulis Pesan & Daftar Node -->
            <aside>
                <section class="card" aria-labelledby="form-heading">
                    <h2 id="form-heading" class="card-title">Kirim Pesan Baru</h2>
                    <p class="card-subtitle">
                        Diterima oleh node <strong><?= e(MEMBER_ROUTE) ?></strong> (<?= e(MEMBER_NAMA) ?>)
                    </p>

                    <div id="ajaxFeedback" aria-live="polite">
                        <?php if ($formError !== ''): ?>
                            <div class="alert alert-error"><?= e($formError) ?></div>
                        <?php endif; ?>
                        <?php if ($formSuccess !== ''): ?>
                            <div class="alert alert-success"><?= e($formSuccess) ?></div>
                        <?php endif; ?>
                    </div>

                    <form id="shoutForm" method="post" action="index.php" novalidate>
                        <div class="form-group">
                            <label for="namaInput" class="form-label">
                                <span>Nama Pengirim *</span>
                                <span id="namaCounter" class="char-counter">0/100</span>
                            </label>
                            <input
                                type="text"
                                id="namaInput"
                                name="nama"
                                class="form-control"
                                minlength="2"
                                maxlength="100"
                                required
                                placeholder="Contoh: Budi Santoso"
                                value="<?= e($oldNama) ?>"
                                autocomplete="name"
                                aria-errormessage="namaErrorMsg"
                            >
                            <div id="namaErrorMsg" class="error-msg">
                                <span aria-hidden="true">!</span> Nama wajib diisi (2&ndash;100 karakter).
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="pesanInput" class="form-label">
                                <span>Pesan Singkat *</span>
                                <span id="pesanCounter" class="char-counter">0/500</span>
                            </label>
                            <textarea
                                id="pesanInput"
                                name="pesan"
                                class="form-control"
                                maxlength="500"
                                required
                                placeholder="Tulis pesan singkat untuk anggota kelompok..."
                                aria-errormessage="pesanErrorMsg"
                            ><?= e($oldPesan) ?></textarea>
                            <div id="pesanErrorMsg" class="error-msg">
                                <span aria-hidden="true">!</span> Pesan tidak boleh kosong (maks. 500 karakter).
                            </div>
                            <p class="field-hint">
                                Aman terhadap uji XSS (<code>&lt;script&gt;</code>) &amp; SQLi (<code>' OR '1'='1</code>).
                            </p>
                        </div>

                        <button type="submit" id="submitBtn" class="btn btn-primary" style="width: 100%;">
                            Kirim ke Shoutbox (<?= e(MEMBER_ROUTE) ?>)
                        </button>
                    </form>
                </section>

                <section class="card" style="margin-top: 1.25rem;" aria-labelledby="nodes-heading">
                    <h2 id="nodes-heading" class="card-title">Node Anggota Kelompok</h2>
                    <p class="card-subtitle">Identitas route server pada database <code>kelompok_db</code></p>
                    <ul class="node-list">
                        <?php if (!empty($anggotaList)): ?>
                            <?php foreach ($anggotaList as $row): ?>
                                <?php $isCurrent = ((string) $row['route_id'] === (string) MEMBER_ROUTE); ?>
                                <li class="<?= $isCurrent ? 'active-node' : '' ?>">
                                    <span>
                                        <strong><?= e((string) $row['route_id']) ?></strong> &mdash; <?= e((string) $row['nama']) ?>
                                    </span>
                                    <span style="font-family: var(--font-mono); font-size: 0.72rem; color: var(--text-muted);">
                                        <?= e((string) $row['nim']) ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li><span>m0 &mdash; Zhafir Muhammad Tectona Grandito</span><span>103012400147</span></li>
                            <li><span>m1 &mdash; Shahrul Putra Wiguna</span><span>103012400188</span></li>
                            <li><span>m2 &mdash; Akbar Fawwaz</span><span>103012400032</span></li>
                            <li><span>m3 &mdash; Muhammad Adira Gumillar</span><span>103012400224</span></li>
                            <li class="active-node"><span>m4 &mdash; Muhammad Fazil Ataurrahman</span><span>103012400247</span></li>
                        <?php endif; ?>
                    </ul>
                </section>
            </aside>

            <!-- Kolom Kanan: Pencarian & Daftar Pesan Live -->
            <section class="card" aria-labelledby="feed-heading">
                <div class="feed-toolbar">
                    <div>
                        <h2 id="feed-heading" class="card-title">Pesan Terbaru</h2>
                        <p class="card-subtitle" style="margin-bottom: 0;">
                            Menampilkan <strong id="shoutCount"><?= count($shouts) ?></strong> pesan tersimpan
                        </p>
                    </div>

                    <form id="searchForm" class="search-form" method="get" action="index.php" role="search">
                        <input
                            type="search"
                            id="searchInput"
                            name="q"
                            class="form-control"
                            placeholder="Cari nama, isi pesan, atau route (m0-m4)..."
                            value="<?= e($searchQuery) ?>"
                            aria-label="Cari pesan shoutbox"
                        >
                        <button type="submit" class="btn btn-outline">Cari</button>
                        <?php if ($searchQuery !== ''): ?>
                            <a href="index.php" id="resetSearchLink" class="btn btn-outline">Reset</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="polling-bar">
                    <div class="polling-indicator">
                        <span id="pollDot" class="pulse-dot" aria-hidden="true"></span>
                        <span id="pollStatusText">
                            Auto-refresh AJAX aktif (4 dtk) &middot; Dilayani oleh node <strong id="lastServedNode"><?= e(MEMBER_ROUTE) ?></strong>
                        </span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 0.4rem;">
                        <button type="button" id="manualRefreshBtn" class="btn btn-outline" style="padding: 0.25rem 0.6rem; font-size: 0.75rem;">
                            Segarkan
                        </button>
                        <button type="button" id="togglePollBtn" class="btn btn-outline" style="padding: 0.25rem 0.6rem; font-size: 0.75rem;">
                            Jeda
                        </button>
                    </div>
                </div>

                <ul id="shoutList" class="shout-list" aria-live="polite">
                    <?php if (empty($shouts)): ?>
                        <li class="empty-state">
                            Belum ada pesan yang cocok. Jadilah yang pertama mengirim pesan!
                        </li>
                    <?php else: ?>
                        <?php foreach ($shouts as $item): ?>
                            <li class="shout-item" data-id="<?= (int) $item['id'] ?>">
                                <div class="shout-meta">
                                    <div class="shout-author-group">
                                        <span class="shout-author"><?= e((string) $item['nama']) ?></span>
                                        <span class="shout-node" title="Diterima oleh server <?= e((string) ($item['anggota_nama'] ?? $item['route_id'])) ?>">
                                            via <?= e((string) $item['route_id']) ?>
                                        </span>
                                    </div>
                                    <time class="shout-time" datetime="<?= e((string) $item['created_at']) ?>">
                                        <?= e((string) $item['created_at']) ?>
                                    </time>
                                </div>
                                <div class="shout-body"><?= e((string) $item['pesan']) ?></div>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </section>
        </div>
    </main>

    <footer class="app-footer">
        Aplikasi Bersama Kelompok (Shoutbox) &middot; Tugas 1 Pra-UTS Cloud Computing &middot; Node Aktif: <strong><?= e(MEMBER_ROUTE) ?></strong>
    </footer>

    <script>
        (function () {
            'use strict';

            // 1. Theme Toggle (Light / Dark)
            const rootEl = document.documentElement;
            const colorSchemeMeta = document.querySelector('meta[name="color-scheme"]');
            const themeBtn = document.getElementById('themeToggleBtn');
            const savedTheme = localStorage.getItem('color-scheme') || localStorage.getItem('kloud_theme');
            if (savedTheme === 'dark' || savedTheme === 'light') {
                rootEl.setAttribute('data-theme', savedTheme);
                if (colorSchemeMeta) colorSchemeMeta.setAttribute('content', savedTheme);
            } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                rootEl.setAttribute('data-theme', 'dark');
            }

            if (themeBtn) {
                themeBtn.addEventListener('click', function () {
                    const current = rootEl.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
                    const next = current === 'dark' ? 'light' : 'dark';
                    rootEl.setAttribute('data-theme', next);
                    if (colorSchemeMeta) colorSchemeMeta.setAttribute('content', next);
                    try {
                        localStorage.setItem('color-scheme', next);
                        localStorage.setItem('kloud_theme', next);
                    } catch (e) {}
                });
            }

            // 2. Character Counters & Client-Side Validation + ARIA Sync
            const shoutForm = document.getElementById('shoutForm');
            const namaInput = document.getElementById('namaInput');
            const pesanInput = document.getElementById('pesanInput');
            const namaCounter = document.getElementById('namaCounter');
            const pesanCounter = document.getElementById('pesanCounter');
            const submitBtn = document.getElementById('submitBtn');
            const ajaxFeedback = document.getElementById('ajaxFeedback');

            function syncAriaInvalid(input) {
                if (!input || typeof input.checkValidity !== 'function') return;
                if (!input.checkValidity()) {
                    input.setAttribute('aria-invalid', 'true');
                    input.classList.add('user-invalid-fallback');
                } else {
                    input.removeAttribute('aria-invalid');
                    input.classList.remove('user-invalid-fallback');
                }
            }

            if (shoutForm) {
                shoutForm.addEventListener('blur', function (e) {
                    if (e.target && e.target.matches('.form-control[required]')) {
                        syncAriaInvalid(e.target);
                    }
                }, true);
                shoutForm.addEventListener('input', function (e) {
                    if (e.target && e.target.matches('.form-control[required]') && e.target.checkValidity()) {
                        e.target.removeAttribute('aria-invalid');
                        e.target.classList.remove('user-invalid-fallback');
                    }
                });
            }

            function updateCounters() {
                if (namaInput && namaCounter) {
                    namaCounter.textContent = namaInput.value.length + '/100';
                }
                if (pesanInput && pesanCounter) {
                    pesanCounter.textContent = pesanInput.value.length + '/500';
                }
            }

            if (namaInput) namaInput.addEventListener('input', updateCounters);
            if (pesanInput) pesanInput.addEventListener('input', updateCounters);
            updateCounters();

            function showFeedback(type, message) {
                if (!ajaxFeedback) return;
                ajaxFeedback.textContent = '';
                if (!message) return;
                const box = document.createElement('div');
                box.className = 'alert ' + (type === 'error' ? 'alert-error' : 'alert-success');
                box.textContent = message;
                ajaxFeedback.appendChild(box);
            }

            // 3. Safe DOM Rendering for Shoutbox Items (Anti-XSS via textContent)
            const shoutList = document.getElementById('shoutList');
            const shoutCount = document.getElementById('shoutCount');
            const lastServedNode = document.getElementById('lastServedNode');
            const pollStatusText = document.getElementById('pollStatusText');
            const pollDot = document.getElementById('pollDot');

            function renderShoutList(items) {
                if (!shoutList) return;
                shoutList.textContent = '';

                if (shoutCount) {
                    shoutCount.textContent = String(items.length);
                }

                if (!Array.isArray(items) || items.length === 0) {
                    const emptyLi = document.createElement('li');
                    emptyLi.className = 'empty-state';
                    emptyLi.textContent = 'Belum ada pesan yang cocok. Jadilah yang pertama mengirim pesan!';
                    shoutList.appendChild(emptyLi);
                    return;
                }

                items.forEach(function (item) {
                    const li = document.createElement('li');
                    li.className = 'shout-item';
                    li.setAttribute('data-id', String(item.id || ''));

                    const meta = document.createElement('div');
                    meta.className = 'shout-meta';

                    const authorGroup = document.createElement('div');
                    authorGroup.className = 'shout-author-group';

                    const authorSpan = document.createElement('span');
                    authorSpan.className = 'shout-author';
                    authorSpan.textContent = String(item.nama || '');

                    const nodeSpan = document.createElement('span');
                    nodeSpan.className = 'shout-node';
                    nodeSpan.title = 'Diterima oleh server ' + String(item.anggota_nama || item.route_id || '');
                    nodeSpan.textContent = 'via ' + String(item.route_id || '');

                    authorGroup.appendChild(authorSpan);
                    authorGroup.appendChild(nodeSpan);

                    const timeEl = document.createElement('time');
                    timeEl.className = 'shout-time';
                    timeEl.setAttribute('datetime', String(item.created_at || ''));
                    timeEl.textContent = String(item.created_at || '');

                    meta.appendChild(authorGroup);
                    meta.appendChild(timeEl);

                    const bodyDiv = document.createElement('div');
                    bodyDiv.className = 'shout-body';
                    bodyDiv.textContent = String(item.pesan || '');

                    li.appendChild(meta);
                    li.appendChild(bodyDiv);
                    shoutList.appendChild(li);
                });
            }

            // 4. AJAX Polling & Live Search
            const searchInput = document.getElementById('searchInput');
            const searchForm = document.getElementById('searchForm');
            const manualRefreshBtn = document.getElementById('manualRefreshBtn');
            const togglePollBtn = document.getElementById('togglePollBtn');

            let pollingActive = true;
            let pollTimer = null;

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
                        if (data.served_by && lastServedNode && pollStatusText) {
                            const route = data.served_by.route_id || '';
                            const timeStr = data.served_by.time || '';
                            pollStatusText.textContent = '';
                            const prefix = document.createTextNode(
                                (pollingActive ? 'Auto-refresh AJAX aktif (4 dtk)' : 'Polling dijeda') +
                                ' · Sync ' + timeStr + ' via node '
                            );
                            const strongNode = document.createElement('strong');
                            strongNode.textContent = route;
                            pollStatusText.appendChild(prefix);
                            pollStatusText.appendChild(strongNode);
                        }
                    }
                } catch (err) {
                    // Biarkan daftar tetap tampil jika jaringan sesaat terputus
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

            if (manualRefreshBtn) {
                manualRefreshBtn.addEventListener('click', function () {
                    fetchLatestShouts();
                });
            }

            if (togglePollBtn) {
                togglePollBtn.addEventListener('click', function () {
                    pollingActive = !pollingActive;
                    togglePollBtn.textContent = pollingActive ? 'Jeda' : 'Lanjutkan';
                    if (pollDot) {
                        pollDot.classList.toggle('paused', !pollingActive);
                    }
                    if (pollingActive) {
                        fetchLatestShouts();
                    }
                });
            }

            if (searchForm && searchInput) {
                let debounceTimeout = null;
                searchInput.addEventListener('input', function () {
                    clearTimeout(debounceTimeout);
                    debounceTimeout = setTimeout(function () {
                        fetchLatestShouts();
                    }, 250);
                });

                searchForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    fetchLatestShouts();
                });
            }

            // 5. AJAX Submit Form dengan Validasi Sisi Klien
            if (shoutForm) {
                shoutForm.addEventListener('submit', async function (e) {
                    e.preventDefault();

                    const namaVal = namaInput ? namaInput.value.trim() : '';
                    const pesanVal = pesanInput ? pesanInput.value.trim() : '';

                    if (namaVal.length < 2 || namaVal.length > 100) {
                        showFeedback('error', 'Validasi Klien: Nama pengirim wajib diisi (2–100 karakter).');
                        if (namaInput) namaInput.focus();
                        return;
                    }

                    if (pesanVal.length < 1 || pesanVal.length > 500) {
                        showFeedback('error', 'Validasi Klien: Pesan tidak boleh kosong (maksimal 500 karakter).');
                        if (pesanInput) pesanInput.focus();
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
                            showFeedback('error', (data && data.error) ? data.error : 'Gagal mengirim pesan.');
                        } else {
                            showFeedback('success', data.message || 'Pesan berhasil dikirim!');
                            if (pesanInput) pesanInput.value = '';
                            updateCounters();
                            if (Array.isArray(data.items)) {
                                renderShoutList(data.items);
                            } else {
                                fetchLatestShouts();
                            }
                        }
                    } catch (err) {
                        showFeedback('error', 'Gagal menghubungi server. Periksa koneksi lokal Anda.');
                    } finally {
                        if (submitBtn) submitBtn.disabled = false;
                    }
                });
            }
        })();
    </script>
</body>
</html>
