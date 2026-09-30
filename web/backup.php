<?php
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/sleutels_lib.php';

$userName = isset($_SESSION['user']) ? nameForUser($_SESSION['user']['email']) : 'DEBUG';
$path = sleutels_db_path($userName);

if (!is_file($path)) {
    header('Location: index.php?status=backup_missing');
    exit;
}

$filename = sleutels_db_filename($userName);
$tmp = tempnam(sys_get_temp_dir(), 'forculus_bak_');
if ($tmp === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'De backup kon niet worden gemaakt.';
    exit;
}

$sqliteTmp = $tmp . '.sqlite';

try {
    sleutels_copy_consistent($path, $sqliteTmp);
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
