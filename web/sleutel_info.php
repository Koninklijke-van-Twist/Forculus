<?php
/**
 * JSON-endpoint voor de sleutel-infomodal: uitgiftehistorie en toegang van één sleutel.
 */
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/sleutels_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sleutel_info_json(array $payload, $status = 200)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$userName = isset($_SESSION['user']) ? nameForUser($_SESSION['user']['email']) : "DEBUG";
$sleutelId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($sleutelId <= 0) {
    sleutel_info_json(['ok' => false, 'error' => 'Ongeldig sleutelnr.'], 400);
}

try {
    $db = sleutels_open_db(sleutels_db_path($userName));
    $stmt = $db->prepare('SELECT * FROM sleutels WHERE id = :id');
    $stmt->execute([':id' => $sleutelId]);
    $sleutel = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sleutel) {
        sleutel_info_json(['ok' => false, 'error' => 'Sleutel niet gevonden.'], 404);
    }
    $stmt = $db->prepare('SELECT * FROM sleutel_historie WHERE sleutel_id = :id
                          ORDER BY COALESCE(uitgeleend_op, teruggebracht_op) DESC, id DESC');
    $stmt->execute([':id' => $sleutelId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    sleutel_info_json(['ok' => false, 'error' => 'Databasefout.'], 500);
}

$userById = [];
$getUsersFile = __DIR__ . '/getusers.php';
$users = file_exists($getUsersFile) ? include $getUsersFile : [];
if (is_array($users)) {
    foreach ($users as $u) {
        if (!empty($u['Id'])) {
            $userById[$u['Id']] = $u;
        }
    }
}

$label = function ($raw) use ($userById) {
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '(onbekend)';
    }
    if (isset($userById[$raw])) {
        return sleutels_user_label($userById[$raw]);
    }
    return $raw;
};

$historie = [];
$heeftOpen = false;
foreach ($rows as $r) {
    $open = $r['teruggebracht_op'] === null;
    $heeftOpen = $heeftOpen || $open;
    $historie[] = [
        'aan' => $label($r['uitgeleend_aan']),
        'uitgegeven_op' => sleutels_format_nl($r['uitgeleend_op']),
        'uitgeleend_tot' => sleutels_format_nl($r['uitgeleend_tot']),
        'uitgegeven_door' => $r['uitgegeven_door'] !== null ? (string) $r['uitgegeven_door'] : '(onbekend)',
        'teruggebracht_op' => $open ? '' : sleutels_format_nl($r['teruggebracht_op']),
        'teruggebracht_door' => $open ? '' : (string) $r['teruggebracht_door'],
        'open' => $open,
    ];
}

// Huidige uitlening van vóór de historie: tonen als lopend, uitgever onbekend.
$heeftLoan = !empty($sleutel['uitgeleend_op']) || !empty($sleutel['uitgeleend_tot']);
if ($heeftLoan && !$heeftOpen) {
    array_unshift($historie, [
        'aan' => $label($sleutel['uitgeleend_aan']),
        'uitgegeven_op' => sleutels_format_nl($sleutel['uitgeleend_op']),
        'uitgeleend_tot' => sleutels_format_nl($sleutel['uitgeleend_tot']),
        'uitgegeven_door' => '(onbekend)',
        'teruggebracht_op' => '',
        'teruggebracht_door' => '',
        'open' => true,
    ]);
}

$toegang = [];
foreach (preg_split('/[\r\n,;]+/', (string) ($sleutel['toegang'] ?? '')) as $deel) {
    $deel = trim($deel);
    if ($deel !== '') {
        $toegang[] = $deel;
    }
}

sleutel_info_json([
    'ok' => true,
    'sleutel' => [
        'id' => (int) $sleutel['id'],
        'naam' => (string) $sleutel['naam'],
        'tapkey_id' => (string) ($sleutel['tapkey_id'] ?? ''),
        'opslagplek' => (string) ($sleutel['opslagplek'] ?? ''),
    ],
    'toegang' => $toegang,
    'historie' => $historie,
]);
