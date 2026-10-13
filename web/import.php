<?php
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/context.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: index.php');
    exit;
}

$ctx = forculus_context('actie');
forculus_stop_tenzij_lijst($ctx);
$lijst = $ctx['lijst'];

try {
    $result = sleutels_import_upload(
        $lijst['db_pad'],
        isset($_FILES['sqlite']) && is_array($_FILES['sqlite']) ? $_FILES['sqlite'] : [],
        $lijst['id']
    );
    forculus_redirect('index.php', $lijst['token'], [
        'status' => 'imported',
        'inserted' => (int) $result['inserted'],
        'updated' => (int) $result['updated'],
    ]);
} catch (SleutelsImportException $e) {
    forculus_redirect('index.php', $lijst['token'], [
        'status' => 'import_error',
        'code' => $e->errorCode(),
    ]);
} catch (Throwable $e) {
    forculus_redirect('index.php', $lijst['token'], [
        'status' => 'import_error',
        'code' => 'opslaan',
    ]);
}
