<?php
$userName = $_SERVER['PHP_AUTH_USER'] ?? "UNAUTHORISED";

// ====== CONFIG ======
$authFile = __DIR__ . '/auth.json';

if (!file_exists($authFile)) {
    die("auth.json niet gevonden!");
}

$authData = json_decode(file_get_contents($authFile), true);

if (!$authData) {
    die("auth.json kon niet worden ingelezen of bevat ongeldige JSON!");
}

$tenantId     = $authData['tenantId'];
$clientId     = $authData['clientId'];
$clientSecret = $authData['clientSecret'];


$cacheFile = __DIR__ . '/users_cache_' . date('Y-m-d') . '.json';
if (file_exists($cacheFile)) {
    $json = file_get_contents($cacheFile);
    $data = json_decode($json, true);

    // Alleen gebruiken als het echt een array is
    if (is_array($data)) {
        return $data; // <-- klaar, we zijn uit de cache
    }
    // Als de cache corrupt is, gaan we gewoon opnieuw fetchen
}

// ====== 1. Token ophalen ======
$tokenUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";

$tokenPostFields = http_build_query([
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'scope'         => 'https://graph.microsoft.com/.default',
    'grant_type'    => 'client_credentials',
]);

$ch = curl_init($tokenUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $tokenPostFields,
    CURLOPT_RETURNTRANSFER => true,
]);

$tokenResponse = curl_exec($ch);

if ($tokenResponse === false) {
    die('Fout bij ophalen token: ' . curl_error($ch));
}

$tokenData = json_decode($tokenResponse, true);

if (!isset($tokenData['access_token'])) {
    die('Geen access_token in token response: ' . $tokenResponse);
}

$accessToken = $tokenData['access_token'];
curl_close($ch);

// ====== 2. Alle gebruikers ophalen met pagination ======

// Je kunt eventueel $top toevoegen, maar pagination blijft nodig als er meer zijn:
// $graphUrl = 'https://graph.microsoft.com/v1.0/users?$select=id,displayName,mail,businessPhones&$top=999';
$graphUrl = 'https://graph.microsoft.com/v1.0/users?$select=id,accountEnabled,displayName,mail,jobTitle,userType,businessPhones&$filter=accountEnabled%20eq%20true';

$allUsers = [];

do {
    $ch = curl_init($graphUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
    ]);

    $usersResponse = curl_exec($ch);

    if ($usersResponse === false) {
        die('Fout bij ophalen users: ' . curl_error($ch));
    }

    $usersData = json_decode($usersResponse, true);
    curl_close($ch);

    if (!isset($usersData['value']) || !is_array($usersData['value'])) {
        die('Onverwachte users response: ' . $usersResponse);
    }

    // Voeg deze batch toe aan de volledige lijst
    $allUsers = array_merge($allUsers, $usersData['value']);

    // Controleer of er nog een volgende pagina is
    if (isset($usersData['@odata.nextLink'])) {
        $graphUrl = $usersData['@odata.nextLink'];
    } else {
        $graphUrl = null;
    }

} while ($graphUrl !== null);

// ====== 3. Mappen naar jouw JSON-structuur ======
$result = [];

foreach ($allUsers as $user) {
    if (trim($user['jobTitle'] ?? '') === '') {
        continue; // skip lege jobtitles als backup
    }
    $id             = $user['id'] ?? null;
    $naam           = $user['displayName'] ?? null;
    $email          = $user['mail'] ?? null;
    $businessPhones = $user['businessPhones'] ?? [];
    $telefoonnummer = null;

    if (is_array($businessPhones) && count($businessPhones) > 0) {
        $telefoonnummer = $businessPhones[0];
    }

    $result[] = [
        'Id'             => $id,
        'Naam'           => $naam,
        'Email'          => $email,
        'Telefoonnummer' => $telefoonnummer,
        'Titel' => $user['jobTitle']
    ];
}

// ====== 4. JSON uitgeven ======
file_put_contents(
    $cacheFile,
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    LOCK_EX
);

return $result;