<?php



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
<div class="served-strip" role="region" aria-label="Identitas Server Pelayan">
    <div class="served-strip__inner">
        <p class="served-strip__identity">
            <span class="status-led" aria-hidden="true"></span>
            <span>Anda dilayani oleh laman <strong><?= htmlspecialchars($servedNama, ENT_QUOTES, 'UTF-8') ?></strong> (<span class="tabular"><?= htmlspecialchars($servedNim, ENT_QUOTES, 'UTF-8') ?></span>)</span>
        </p>
        <div class="served-strip__meta">
            <span>Node</span>
            <code class="route-tag"><?= htmlspecialchars($servedRoute, ENT_QUOTES, 'UTF-8') ?></code>
        </div>
    </div>
</div>
