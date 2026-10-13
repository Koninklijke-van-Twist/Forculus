<?php
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/context.php';

$ctx = forculus_context('actie');
forculus_stop_tenzij_lijst($ctx);
$lijst = $ctx['lijst'];
$path = $lijst['db_pad'];

if (!is_file($path)) {
    forculus_redirect('index.php', $lijst['token'], ['status' => 'backup_missing']);
}

$veiligeNaam = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $lijst['naam']);
if ($veiligeNaam === null || $veiligeNaam === '' || $veiligeNaam === '_') {
    $veiligeNaam = 'lijst';
}
$filename = 'sleutels_' . $veiligeNaam . '.sqlite';
$tmp = tempnam(sys_get_temp_dir(), 'forculus_bak_');
if ($tmp === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'De backup kon niet worden gemaakt.';
    exit;
}

$sqliteTmp = $tmp . '.sqlite';

try {
    sleutels_exporteer_lijst($lijst, $sqliteTmp);
    $size = filesize($sqliteTmp);
    if ($size === false) {
        throw new RuntimeException('Backupbestand ontbreekt.');
    }

    header('Content-Type: application/vnd.sqlite3');
    header(
        'Content-Disposition: attachment; filename="'
        . str_replace(['"', "\r", "\n"], '', $filename)
        . '"; filename*=UTF-8\'\''
        . rawurlencode($filename)
    );
    header('Content-Length: ' . $size);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($sqliteTmp);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'De backup kon niet worden gemaakt.';
} finally {
    @unlink($tmp);
    @unlink($sqliteTmp);
}
