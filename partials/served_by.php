<?php
/**
 * app/partials/served_by.php
 * Banner wajib: "Anda dilayani oleh laman <nama> (<NIM>)" di setiap halaman.
 */

if (!defined('MEMBER_ROUTE') || !defined('MEMBER_NAMA') || !defined('MEMBER_NIM')) {
    $identityCandidates = [
        dirname(__DIR__, 2) . '/identity.php',
        dirname(__DIR__) . '/identity.php',
    ];
    foreach ($identityCandidates as $identityFile) {
        if (is_file($identityFile)) {
            require_once $identityFile;
            break;
        }
    }
}

$servedRoute = defined('MEMBER_ROUTE') ? (string) MEMBER_ROUTE : 'm0';
$servedNama  = defined('MEMBER_NAMA')  ? (string) MEMBER_NAMA  : 'Mahasiswa Kelompok';
$servedNim   = defined('MEMBER_NIM')   ? (string) MEMBER_NIM   : '103012400000';
?>
<div class="served-by-banner" role="region" aria-label="Identitas Server Pelayan" style="background: #0f172a; color: #f8fafc; border-bottom: 1px solid #1e293b; font-family: system-ui, -apple-system, sans-serif; font-size: 0.875rem; line-height: 1.5; padding: 0.625rem 1.25rem;">
    <div style="max-width: 1120px; margin: 0 auto; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.5rem;">
        <div style="display: flex; align-items: center; gap: 0.625rem;">
            <span style="display: inline-block; width: 0.5rem; height: 0.5rem; border-radius: 9999px; background: #10b981; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);" aria-hidden="true"></span>
            <span>
                Anda dilayani oleh laman <strong><?= htmlspecialchars($servedNama, ENT_QUOTES, 'UTF-8') ?></strong> (<?= htmlspecialchars($servedNim, ENT_QUOTES, 'UTF-8') ?>)
            </span>
        </div>
        <div style="display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.75rem; color: #94a3b8;">
            <span>Node Route:</span>
            <code style="background: #1e293b; color: #38bdf8; padding: 0.125rem 0.5rem; border-radius: 0.25rem; font-weight: 600; border: 1px solid #334155;">
                <?= htmlspecialchars($servedRoute, ENT_QUOTES, 'UTF-8') ?>
            </code>
        </div>
    </div>
</div>
