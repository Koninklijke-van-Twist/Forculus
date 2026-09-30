<?php
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/sleutels_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: index.php');
    exit;
}

$userName = isset($_SESSION['user']) ? nameForUser($_SESSION['user']['email']) : 'DEBUG';
$targetPath = sleutels_db_path($userName);

try {
    $result = sleutels_import_upload($targetPath, isset($_FILES['sqlite']) && is_array($_FILES['sqlite']) ? $_FILES['sqlite'] : []);
    header(
        'Location: index.php?status=imported&inserted='
        . (int) $result['inserted']
        . '&updated='
        . (int) $result['updated']
    );
    exit;
} catch (SleutelsImportException $e) {
    header('Location: index.php?status=import_error&code=' . rawurlencode($e->errorCode()));
    exit;
} catch (Throwable $e) {
    header('Location: index.php?status=import_error&code=opslaan');
    exit;
}
