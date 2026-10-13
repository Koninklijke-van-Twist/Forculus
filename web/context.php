<?php

require_once __DIR__ . '/sleutels_lib.php';
require_once __DIR__ . '/ui.php';

function forculus_user()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $emailRaw = isset($_SESSION['user']['email']) ? (string) $_SESSION['user']['email'] : '';
    $email = sleutels_normalize_email($emailRaw);
    if (!sleutels_geldig_email($email)) {
        return [
            'email' => 'debug@kvt.nl',
            'weergave' => 'DEBUG',
        ];
    }
    $weergave = $email;
    if (function_exists('nameForUser')) {
        try {
            $naam = nameForUser($emailRaw);
        } catch (Throwable $e) {
            $naam = '';
        }
        if (is_string($naam) && trim($naam) !== '') {
            $weergave = trim($naam);
        }
    }

    return [
        'email' => $email,
        'weergave' => $weergave,
    ];
}

function forculus_url($path, $token = null, array $query = [])
{
    if ($token !== null && $token !== '') {
        $query = ['lijst' => (string) $token] + $query;
    }
    $qs = http_build_query($query);

    return $qs === '' ? $path : $path . '?' . $qs;
}

function forculus_lijst_veld($token)
{
    echo '<input type="hidden" name="lijst" value="' . htmlspecialchars((string) $token, ENT_QUOTES, 'UTF-8') . '">';
}

function forculus_redirect($path, $token = null, array $query = [])
{
    header('Location: ' . forculus_url($path, $token, $query));
    exit;
}

/**
 * $mode pagina: onbekende lijst valt terug op een eigen lijst.
 * $mode actie: een meegegeven lijst moet toegankelijk zijn; ontbreekt hij, dan geldt de laatst gekozen lijst.
 */
function forculus_context($mode = 'pagina')
{
    $user = forculus_user();
    $lijsten = sleutels_lijsten_voor($user['email'], $user['weergave']);
    $requested = '';
    $expliciet = false;
    if (isset($_REQUEST['lijst']) && trim((string) $_REQUEST['lijst']) !== '') {
        $requested = trim((string) $_REQUEST['lijst']);
        $expliciet = true;
    } elseif (isset($_SESSION['forculus_lijst'])) {
        $requested = trim((string) $_SESSION['forculus_lijst']);
    }

    $lijst = null;
    if ($requested !== '') {
        foreach ($lijsten as $kandidaat) {
            if (sleutels_token_gelijk($kandidaat['token'], $requested)) {
                $lijst = $kandidaat;
                break;
            }
        }
    }

    $fout = null;
    if ($expliciet && $lijst === null) {
        $fout = 'geen_toegang';
    }
    // Een vervalst lijst-token op een sleutelactie mag niet stilletjes een andere lijst raken.
    if ($lijst === null && !($mode === 'actie' && $expliciet)) {
        foreach ($lijsten as $kandidaat) {
            if (!empty($kandidaat['is_eigenaar'])) {
                $lijst = $kandidaat;
                break;
            }
        }
        if ($lijst === null && $lijsten) {
            $lijst = $lijsten[0];
        }
    }

    if ($lijst) {
        $_SESSION['forculus_lijst'] = $lijst['token'];
    }

    return [
        'user' => $user,
        'lijsten' => $lijsten,
        'lijst' => $lijst,
        'fout' => $fout,
    ];
}

function forculus_geen_toegang($bericht = null)
{
    if ($bericht === null || $bericht === '') {
        $bericht = sleutels_lijst_error_message('geen_toegang');
    }
    http_response_code(403);
    $titel = 'Geen toegang';
    ?>
    <!DOCTYPE html>
    <html lang="nl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= htmlspecialchars($titel) ?></title>
        <?php forculus_assets(); ?>
    </head>
    <body>
        <div class="container container-narrow">
            <h1><?= htmlspecialchars($titel) ?></h1>
            <div class="error"><?= htmlspecialchars($bericht) ?></div>
            <p><a href="index.php">Terug naar jouw lijsten</a></p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

function forculus_stop_tenzij_lijst(array $ctx, $json = false)
{
    if (!empty($ctx['lijst'])) {
        return;
    }
    if ($json) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => sleutels_lijst_error_message('geen_toegang'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    forculus_geen_toegang();
}

function forculus_status(array $query)
{
    $status = (string) ($query['status'] ?? '');
    if ($status === '') {
        return null;
    }
    $class = 'status';
    $message = null;
    switch ($status) {
        case 'returned':
            $message = 'Sleutel is gemarkeerd als teruggebracht.';
            break;
        case 'lent':
            $message = 'Sleutel is succesvol uitgeleend.';
            break;
        case 'created':
            $message = 'Sleutel is succesvol aangemaakt.';
            break;
        case 'deleted':
            $message = 'Sleutel is verwijderd.';
            break;
        case 'updated':
            $message = 'Sleutel is bijgewerkt.';
            break;
        case 'imported':
            $message = sleutels_import_success_message($query['inserted'] ?? 0, $query['updated'] ?? 0);
            break;
        case 'import_error':
            $class = 'status status-error';
            $message = sleutels_import_error_message($query['code'] ?? '');
            break;
        case 'backup_missing':
            $class = 'status status-error';
            $message = 'Er is nog geen database om te downloaden.';
            break;
        case 'lijst_aangemaakt':
            $message = 'De lijst is aangemaakt.';
            break;
        case 'lijst_hernoemd':
            $message = 'De lijstnaam is gewijzigd.';
            break;
        case 'lijst_verwijderd':
            $message = 'De lijst is verwijderd.';
            break;
        case 'gedeeld':
            $message = 'De persoon heeft nu toegang tot de lijst.';
            break;
        case 'ingetrokken':
            $message = 'De toegang is ingetrokken.';
            break;
        case 'overgedragen':
            $message = 'Het eigenaarschap is overgedragen. Jij hebt nog steeds toegang tot de lijst.';
            break;
        case 'lijst_fout':
            $class = 'status status-error';
            $message = sleutels_lijst_error_message($query['code'] ?? '');
            break;
    }
    if ($message === null) {
        return null;
    }

    return ['class' => $class, 'message' => $message];
}

function forculus_gebruikers()
{
    $file = __DIR__ . '/getusers.php';
    if (!is_file($file) || !is_file(__DIR__ . '/auth.json')) {
        return [];
    }
    $users = include $file;

    return is_array($users) ? $users : [];
}

function forculus_user_index(array $users)
{
    $byId = [];
    foreach ($users as $user) {
        if (is_array($user) && !empty($user['Id'])) {
            $byId[$user['Id']] = $user;
        }
    }

    return $byId;
}
