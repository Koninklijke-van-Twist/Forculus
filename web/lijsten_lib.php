<?php

/**
 * Lijsten, delen en autorisatie.
 * Sleutels blijven in het sqlite-bestand van de persoon bij wie de lijst is ontstaan.
 * Eigenaarschap en leden staan in datzelfde bestand, zodat delen geen tweede database nodig heeft.
 */

class SleutelsLijstException extends RuntimeException
{
    /** @var string */
    private $errorCode;

    public function __construct($errorCode, $message = '')
    {
        $this->errorCode = (string) $errorCode;
        parent::__construct($message !== '' ? (string) $message : (string) $errorCode);
    }

    public function errorCode()
    {
        return $this->errorCode;
    }
}

function sleutels_lijst_error_message($code)
{
    switch ((string) $code) {
        case 'geen_toegang':
            return 'Je hebt geen toegang tot deze lijst.';
        case 'alleen_eigenaar':
            return 'Alleen de eigenaar kan dit doen.';
        case 'niet_gevonden':
            return 'De lijst of sleutel is niet gevonden.';
        case 'niet_leeg':
            return 'Een lijst met sleutels kan niet verwijderd worden. Verwijder eerst de sleutels.';
        case 'naam':
            return 'Vul een naam in van maximaal 80 tekens.';
        case 'naam_bezet':
            return 'Je hebt al een lijst met deze naam.';
        case 'naam_ontvanger':
            return 'De nieuwe eigenaar heeft al een lijst met deze naam. Hernoem de lijst eerst.';
        case 'al_toegang':
            return 'Deze persoon heeft al toegang tot de lijst.';
        case 'geen_lid':
            return 'Je kunt het eigenaarschap alleen overdragen aan iemand die al toegang heeft.';
        case 'eigenaar':
            return 'De eigenaar heeft al toegang. Kies een andere persoon.';
        case 'email':
            return 'Kies een collega of vul een geldig e-mailadres in.';
        default:
            return 'De actie is mislukt.';
    }
}

function sleutels_normalize_email($email)
{
    return strtolower(trim((string) $email));
}

function sleutels_geldig_email($email)
{
    $email = sleutels_normalize_email($email);

    return $email !== '' && (bool) filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 254;
}

function sleutels_lijst_token()
{
    return bin2hex(random_bytes(16));
}

function sleutels_token_gelijk($links, $rechts)
{
    $links = (string) $links;
    $rechts = (string) $rechts;
    if ($links === '' || strlen($links) !== strlen($rechts)) {
        return false;
    }

    return hash_equals($links, $rechts);
}

function sleutels_meta_get(PDO $db, $sleutel)
{
    $stmt = $db->prepare('SELECT waarde FROM forculus_meta WHERE sleutel = :sleutel');
    $stmt->execute([':sleutel' => (string) $sleutel]);
    $waarde = $stmt->fetchColumn();

    return $waarde === false ? null : (string) $waarde;
}

function sleutels_meta_set(PDO $db, $sleutel, $waarde)
{
    $db->prepare('INSERT INTO forculus_meta (sleutel, waarde) VALUES (:sleutel, :waarde)
                  ON CONFLICT(sleutel) DO UPDATE SET waarde = excluded.waarde')
        ->execute([':sleutel' => (string) $sleutel, ':waarde' => (string) $waarde]);
}

function sleutels_lijst_naam($naam)
{
    $naam = trim((string) preg_replace('/\s+/u', ' ', (string) $naam));
    $lengte = function_exists('mb_strlen') ? mb_strlen($naam, 'UTF-8') : strlen($naam);
    if ($naam === '' || $lengte > 80 || preg_match('/[\x00-\x1F\x7F]/', $naam)) {
        throw new SleutelsLijstException('naam');
    }

    return $naam;
}

function sleutels_data_bestanden()
{
    $dir = sleutels_data_dir();
    if (!is_dir($dir)) {
        return [];
    }
    $files = glob($dir . DIRECTORY_SEPARATOR . 'sleutels_*.sqlite');
    if (!is_array($files)) {
        return [];
    }
    $out = [];
    foreach ($files as $file) {
        if (is_file($file)) {
            $out[] = $file;
        }
    }
    sort($out);

    return $out;
}

function sleutels_pad_in_datamap($path)
{
    $dir = realpath(sleutels_data_dir());
    $real = realpath($path);
    if ($dir === false || $real === false) {
        return false;
    }
    $prefix = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    return str_starts_with($real, $prefix);
}

function sleutels_lijst_db(array $lijst)
{
    $pad = (string) ($lijst['db_pad'] ?? '');
    if ($pad === '' || !sleutels_pad_in_datamap($pad)) {
        throw new SleutelsLijstException('niet_gevonden');
    }

    return sleutels_open_db($pad);
}

/**
 * Bestaande sleutels van deze persoon worden één lijst "Lijst", zonder rijen te wissen.
 */
function sleutels_migrate_user_db(PDO $db, $email, $weergave)
{
    sleutels_ensure_schema($db);
    $email = sleutels_normalize_email($email);
    if (!sleutels_geldig_email($email)) {
        throw new SleutelsLijstException('email');
    }
    $weergave = trim((string) $weergave);
    if ($weergave === '') {
        $weergave = $email;
    }

    if (sleutels_meta_get($db, 'lijsten_migratie') === '1') {
        sleutels_koppel_wees_sleutels($db, $email, $weergave);
        return;
    }

    $startNaam = sleutels_vrije_naam($email, 'Lijst');
    $db->exec('BEGIN IMMEDIATE');
    try {
        if (sleutels_meta_get($db, 'lijsten_migratie') === '1') {
            $db->exec('COMMIT');
            return;
        }
        $count = (int) $db->query('SELECT COUNT(*) FROM lijsten')->fetchColumn();
        if ($count === 0) {
            $db->prepare('INSERT INTO lijsten (token, naam, eigenaar_email, eigenaar_weergave, aangemaakt_op)
                          VALUES (:token, :naam, :email, :weergave, :nu)')
                ->execute([
                    ':token' => sleutels_lijst_token(),
                    ':naam' => $startNaam,
                    ':email' => $email,
                    ':weergave' => $weergave,
                    ':nu' => time(),
                ]);
            $id = (int) $db->lastInsertId();
        } else {
            $id = (int) $db->query('SELECT id FROM lijsten ORDER BY id ASC LIMIT 1')->fetchColumn();
        }
        if ($id > 0) {
            $db->prepare('UPDATE sleutels SET lijst_id = :id WHERE lijst_id IS NULL')
                ->execute([':id' => $id]);
        }
        sleutels_meta_set($db, 'lijsten_migratie', '1');
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            } else {
                $db->exec('ROLLBACK');
            }
        } catch (Throwable $ignored) {
        }
        throw $e;
    }
}

function sleutels_koppel_wees_sleutels(PDO $db, $email, $weergave)
{
    $orphans = (int) $db->query('SELECT COUNT(*) FROM sleutels WHERE lijst_id IS NULL')->fetchColumn();
    if ($orphans === 0) {
        return;
    }
    $weesNaam = sleutels_vrije_naam(sleutels_normalize_email($email), 'Lijst');
    $ownTx = !$db->inTransaction();
    if ($ownTx) {
        $db->exec('BEGIN IMMEDIATE');
    }
    try {
        $id = (int) $db->query('SELECT id FROM lijsten ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($id <= 0) {
            $db->prepare('INSERT INTO lijsten (token, naam, eigenaar_email, eigenaar_weergave, aangemaakt_op)
                          VALUES (:token, :naam, :email, :weergave, :nu)')
                ->execute([
                    ':token' => sleutels_lijst_token(),
                    ':naam' => $weesNaam,
                    ':email' => sleutels_normalize_email($email),
                    ':weergave' => trim((string) $weergave) !== '' ? trim((string) $weergave) : sleutels_normalize_email($email),
                    ':nu' => time(),
                ]);
            $id = (int) $db->lastInsertId();
        }
        $db->prepare('UPDATE sleutels SET lijst_id = :id WHERE lijst_id IS NULL')
            ->execute([':id' => $id]);
        if ($ownTx) {
            $db->exec('COMMIT');
        }
    } catch (Throwable $e) {
        if ($ownTx) {
            try {
                if ($db->inTransaction()) {
                    $db->rollBack();
                } else {
                    $db->exec('ROLLBACK');
                }
            } catch (Throwable $ignored) {
            }
        }
        throw $e;
    }
}

function sleutels_lijst_record($path, array $row, array $leden)
{
    $schoneLeden = [];
    foreach ($leden as $lid) {
        $email = sleutels_normalize_email($lid['email'] ?? '');
        if ($email === '') {
            continue;
        }
        $weergave = trim((string) ($lid['weergave'] ?? ''));
        $schoneLeden[] = [
            'email' => $email,
            'weergave' => $weergave !== '' ? $weergave : $email,
        ];
    }

    return [
        'token' => (string) $row['token'],
        'id' => (int) $row['id'],
        'naam' => (string) $row['naam'],
        'eigenaar_email' => sleutels_normalize_email($row['eigenaar_email'] ?? ''),
        'eigenaar_weergave' => trim((string) ($row['eigenaar_weergave'] ?? '')) !== ''
            ? trim((string) $row['eigenaar_weergave'])
            : sleutels_normalize_email($row['eigenaar_email'] ?? ''),
        'db_pad' => (string) $path,
        'db_bestand' => basename((string) $path),
        'aangemaakt_op' => (int) ($row['aangemaakt_op'] ?? 0),
        'leden' => $schoneLeden,
    ];
}

function sleutels_open_readonly($path)
{
    $db = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('PRAGMA query_only = ON');

    return $db;
}

function sleutels_scan_lijsten()
{
    $out = [];
    foreach (sleutels_data_bestanden() as $path) {
        try {
            if (!sleutels_pad_in_datamap($path)) {
                continue;
            }
            $db = sleutels_open_readonly($path);
            $rows = $db->query('SELECT id, token, naam, eigenaar_email, eigenaar_weergave, aangemaakt_op FROM lijsten')
                ->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) {
                continue;
            }
            $ledenStmt = $db->prepare('SELECT email, weergave FROM lijst_leden WHERE lijst_id = :id ORDER BY weergave COLLATE NOCASE');
            foreach ($rows as $row) {
                if (!is_array($row) || trim((string) ($row['token'] ?? '')) === '') {
                    continue;
                }
                $ledenStmt->execute([':id' => (int) $row['id']]);
                $leden = $ledenStmt->fetchAll(PDO::FETCH_ASSOC);
                $out[] = sleutels_lijst_record($path, $row, is_array($leden) ? $leden : []);
            }
        } catch (Throwable $e) {
            continue;
        }
    }

    return $out;
}

function sleutels_rol(array $lijst, $email)
{
    $email = sleutels_normalize_email($email);
    if ($email === '' || !sleutels_geldig_email($email)) {
        return null;
    }
    if ($email === ($lijst['eigenaar_email'] ?? '')) {
        return 'eigenaar';
    }
    foreach ($lijst['leden'] ?? [] as $lid) {
        if (($lid['email'] ?? '') === $email) {
            return 'lid';
        }
    }

    return null;
}

function sleutels_lijst_voor_kijker(array $lijst, $email)
{
    $rol = sleutels_rol($lijst, $email);
    if ($rol === null) {
        return null;
    }
    $lijst['rol'] = $rol;
    $lijst['is_eigenaar'] = $rol === 'eigenaar';
    $lijst['gedeeld'] = $rol !== 'eigenaar';

    return $lijst;
}

function sleutels_prepare_user($email, $weergave)
{
    $email = sleutels_normalize_email($email);
    $db = sleutels_open_db(sleutels_db_path($weergave));
    sleutels_migrate_user_db($db, $email, $weergave);

    return $db;
}

function sleutels_lijsten_voor($email, $weergave)
{
    sleutels_prepare_user($email, $weergave);
    $email = sleutels_normalize_email($email);
    $gezien = [];
    foreach (sleutels_scan_lijsten() as $lijst) {
        $zichtbaar = sleutels_lijst_voor_kijker($lijst, $email);
        if ($zichtbaar !== null) {
            $gezien[] = $zichtbaar;
        }
    }
    usort($gezien, function ($a, $b) {
        if ($a['is_eigenaar'] !== $b['is_eigenaar']) {
            return $a['is_eigenaar'] ? -1 : 1;
        }

        return strcasecmp($a['naam'], $b['naam']);
    });

    return $gezien;
}

function sleutels_lijst_ophalen($token)
{
    $token = trim((string) $token);
    if ($token === '') {
        return null;
    }
    foreach (sleutels_scan_lijsten() as $lijst) {
        if (sleutels_token_gelijk($lijst['token'], $token)) {
            return $lijst;
        }
    }

    return null;
}

function sleutels_lijst_voor($email, $weergave, $token)
{
    $email = sleutels_normalize_email($email);
    sleutels_prepare_user($email, $weergave);
    $lijst = sleutels_lijst_ophalen($token);
    if ($lijst === null) {
        return null;
    }

    return sleutels_lijst_voor_kijker($lijst, $email);
}

function sleutels_eis_eigenaar(array $lijst, $email)
{
    $rol = sleutels_rol($lijst, $email);
    if ($rol === null) {
        throw new SleutelsLijstException('geen_toegang');
    }
    if ($rol !== 'eigenaar') {
        throw new SleutelsLijstException('alleen_eigenaar');
    }
}

function sleutels_eis_toegang(array $lijst, $email)
{
    if (sleutels_rol($lijst, $email) === null) {
        throw new SleutelsLijstException('geen_toegang');
    }
}

function sleutels_vrije_naam($email, $basis)
{
    $basis = sleutels_lijst_naam($basis);
    if (!sleutels_eigenaar_heeft_naam($email, $basis)) {
        return $basis;
    }
    for ($i = 2; $i < 50; $i++) {
        $kandidaat = $basis . ' ' . $i;
        if (!sleutels_eigenaar_heeft_naam($email, $kandidaat)) {
            return $kandidaat;
        }
    }

    return $basis . ' ' . time();
}

function sleutels_eigenaar_heeft_naam($email, $naam, $behalveToken = null)
{
    $email = sleutels_normalize_email($email);
    foreach (sleutels_scan_lijsten() as $lijst) {
        if ($behalveToken !== null && sleutels_token_gelijk($lijst['token'], $behalveToken)) {
            continue;
        }
        if ($lijst['eigenaar_email'] === $email && strcasecmp($lijst['naam'], $naam) === 0) {
            return true;
        }
    }

    return false;
}

function sleutels_lijst_aanmaken($email, $weergave, $naam)
{
    $email = sleutels_normalize_email($email);
    $naam = sleutels_lijst_naam($naam);
    $db = sleutels_prepare_user($email, $weergave);
    if (sleutels_eigenaar_heeft_naam($email, $naam)) {
        throw new SleutelsLijstException('naam_bezet');
    }
    $weergave = trim((string) $weergave);
    if ($weergave === '') {
        $weergave = $email;
    }
    $token = sleutels_lijst_token();
    $db->prepare('INSERT INTO lijsten (token, naam, eigenaar_email, eigenaar_weergave, aangemaakt_op)
                  VALUES (:token, :naam, :email, :weergave, :nu)')
        ->execute([
            ':token' => $token,
            ':naam' => $naam,
            ':email' => $email,
            ':weergave' => $weergave,
            ':nu' => time(),
        ]);
    $lijst = sleutels_lijst_voor($email, $weergave, $token);
    if ($lijst === null) {
        throw new SleutelsLijstException('niet_gevonden');
    }

    return $lijst;
}

function sleutels_lijst_hernoemen($email, $token, $naam)
{
    $email = sleutels_normalize_email($email);
    $naam = sleutels_lijst_naam($naam);
    $lijst = sleutels_lijst_ophalen($token);
    if ($lijst === null || sleutels_rol($lijst, $email) === null) {
        throw new SleutelsLijstException('geen_toegang');
    }
    sleutels_eis_eigenaar($lijst, $email);
    if (strcasecmp($lijst['naam'], $naam) !== 0 && sleutels_eigenaar_heeft_naam($email, $naam, $lijst['token'])) {
        throw new SleutelsLijstException('naam_bezet');
    }
    $db = sleutels_lijst_db($lijst);
    $db->prepare('UPDATE lijsten SET naam = :naam WHERE id = :id')
        ->execute([':naam' => $naam, ':id' => $lijst['id']]);
    $vers = sleutels_lijst_ophalen($token);
    $zichtbaar = $vers ? sleutels_lijst_voor_kijker($vers, $email) : null;
    if ($zichtbaar === null) {
        throw new SleutelsLijstException('niet_gevonden');
    }

    return $zichtbaar;
}

function sleutels_lijst_aantal(array $lijst)
{
    $db = sleutels_lijst_db($lijst);
    $stmt = $db->prepare('SELECT COUNT(*) FROM sleutels WHERE lijst_id = :id');
    $stmt->execute([':id' => (int) $lijst['id']]);

    return (int) $stmt->fetchColumn();
}

function sleutels_lijst_verwijderen($email, $token)
{
    $email = sleutels_normalize_email($email);
    $lijst = sleutels_lijst_ophalen($token);
    if ($lijst === null || sleutels_rol($lijst, $email) === null) {
        throw new SleutelsLijstException('geen_toegang');
    }
    sleutels_eis_eigenaar($lijst, $email);
    $db = sleutels_lijst_db($lijst);
    $db->beginTransaction();
    try {
        $aantal = $db->prepare('SELECT COUNT(*) FROM sleutels WHERE lijst_id = :id');
        $aantal->execute([':id' => $lijst['id']]);
        if ((int) $aantal->fetchColumn() > 0) {
            $db->rollBack();
            throw new SleutelsLijstException('niet_leeg');
        }
        $db->prepare('DELETE FROM lijst_leden WHERE lijst_id = :id')->execute([':id' => $lijst['id']]);
        $db->prepare('DELETE FROM lijsten WHERE id = :id')->execute([':id' => $lijst['id']]);
        $db->commit();
    } catch (SleutelsLijstException $e) {
        throw $e;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function sleutels_deelnemer($email, $weergave)
{
    $email = sleutels_normalize_email($email);
    if (!sleutels_geldig_email($email)) {
        throw new SleutelsLijstException('email');
    }
    $weergave = trim((string) $weergave);

    return [
        'email' => $email,
        'weergave' => $weergave !== '' ? $weergave : $email,
    ];
}

function sleutels_lijst_delen($email, $token, $lidEmail, $lidWeergave)
{
    $email = sleutels_normalize_email($email);
    $lid = sleutels_deelnemer($lidEmail, $lidWeergave);
    $lijst = sleutels_lijst_ophalen($token);
    if ($lijst === null || sleutels_rol($lijst, $email) === null) {
        throw new SleutelsLijstException('geen_toegang');
    }
    sleutels_eis_eigenaar($lijst, $email);
    if ($lid['email'] === $lijst['eigenaar_email']) {
        throw new SleutelsLijstException('eigenaar');
    }
    foreach ($lijst['leden'] as $bestaand) {
        if ($bestaand['email'] === $lid['email']) {
            throw new SleutelsLijstException('al_toegang');
        }
    }
    $db = sleutels_lijst_db($lijst);
    $db->prepare('INSERT INTO lijst_leden (lijst_id, email, weergave, toegevoegd_op)
                  VALUES (:lijst, :email, :weergave, :nu)')
        ->execute([
            ':lijst' => $lijst['id'],
            ':email' => $lid['email'],
            ':weergave' => $lid['weergave'],
            ':nu' => time(),
        ]);

    return sleutels_lijst_voor_kijker(sleutels_lijst_ophalen($token), $email);
}

function sleutels_lijst_intrekken($email, $token, $lidEmail)
{
    $email = sleutels_normalize_email($email);
    $lidEmail = sleutels_normalize_email($lidEmail);
    $lijst = sleutels_lijst_ophalen($token);
    if ($lijst === null || sleutels_rol($lijst, $email) === null) {
        throw new SleutelsLijstException('geen_toegang');
    }
    sleutels_eis_eigenaar($lijst, $email);
    if ($lidEmail === $lijst['eigenaar_email']) {
        throw new SleutelsLijstException('eigenaar');
    }
    $gevonden = false;
    foreach ($lijst['leden'] as $lid) {
        if ($lid['email'] === $lidEmail) {
            $gevonden = true;
            break;
        }
    }
    if (!$gevonden) {
        throw new SleutelsLijstException('geen_lid');
    }
    $db = sleutels_lijst_db($lijst);
    $db->prepare('DELETE FROM lijst_leden WHERE lijst_id = :lijst AND email = :email')
        ->execute([':lijst' => $lijst['id'], ':email' => $lidEmail]);

    return sleutels_lijst_voor_kijker(sleutels_lijst_ophalen($token), $email);
}

function sleutels_lijst_overdragen($email, $token, $nieuwEmail, $nieuwWeergave)
{
    $email = sleutels_normalize_email($email);
    $nieuw = sleutels_deelnemer($nieuwEmail, $nieuwWeergave);
    $lijst = sleutels_lijst_ophalen($token);
    if ($lijst === null || sleutels_rol($lijst, $email) === null) {
        throw new SleutelsLijstException('geen_toegang');
    }
    sleutels_eis_eigenaar($lijst, $email);
    if ($nieuw['email'] === $lijst['eigenaar_email']) {
        throw new SleutelsLijstException('eigenaar');
    }
    $isLid = false;
    foreach ($lijst['leden'] as $lid) {
        if ($lid['email'] === $nieuw['email']) {
            $isLid = true;
            if (trim((string) $nieuwWeergave) === '') {
                $nieuw['weergave'] = $lid['weergave'];
            }
            break;
        }
    }
    if (!$isLid) {
        throw new SleutelsLijstException('geen_lid');
    }
    if (sleutels_eigenaar_heeft_naam($nieuw['email'], $lijst['naam'], $lijst['token'])) {
        throw new SleutelsLijstException('naam_ontvanger');
    }

    $db = sleutels_lijst_db($lijst);
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE lijsten SET eigenaar_email = :email, eigenaar_weergave = :weergave WHERE id = :id')
            ->execute([
                ':email' => $nieuw['email'],
                ':weergave' => $nieuw['weergave'],
                ':id' => $lijst['id'],
            ]);
        $db->prepare('DELETE FROM lijst_leden WHERE lijst_id = :lijst AND email = :email')
            ->execute([':lijst' => $lijst['id'], ':email' => $nieuw['email']]);
        $db->prepare('INSERT INTO lijst_leden (lijst_id, email, weergave, toegevoegd_op)
                      VALUES (:lijst, :email, :weergave, :nu)')
            ->execute([
                ':lijst' => $lijst['id'],
                ':email' => $email,
                ':weergave' => $lijst['eigenaar_weergave'],
                ':nu' => time(),
            ]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    $vers = sleutels_lijst_ophalen($token);

    return $vers ? sleutels_lijst_voor_kijker($vers, $email) : null;
}

function sleutels_resolve_deelnemer($raw, array $users)
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    $userById = [];
    foreach ($users as $user) {
        if (is_array($user) && !empty($user['Id'])) {
            $userById[(string) $user['Id']] = $user;
        }
    }
    $id = sleutels_resolve_borrower($raw, $userById);
    if ($id !== '' && isset($userById[$id])) {
        $email = sleutels_normalize_email($userById[$id]['Email'] ?? '');
        if (!sleutels_geldig_email($email)) {
            return null;
        }
        $naam = trim((string) ($userById[$id]['Naam'] ?? ''));

        return [
            'email' => $email,
            'weergave' => $naam !== '' ? $naam : $email,
        ];
    }
    if (sleutels_geldig_email($raw)) {
        $email = sleutels_normalize_email($raw);

        return ['email' => $email, 'weergave' => $email];
    }

    return null;
}

function sleutels_actor_label($actor, array $userById)
{
    $actor = trim((string) $actor);
    if ($actor === '' || strcasecmp($actor, 'onbekend') === 0) {
        return '';
    }
    foreach ($userById as $user) {
        if (!is_array($user)) {
            continue;
        }
        $email = trim((string) ($user['Email'] ?? ''));
        if ($email !== '' && strcasecmp($email, $actor) === 0) {
            $naam = trim((string) ($user['Naam'] ?? ''));

            return $naam !== '' ? $naam : $email;
        }
    }

    return $actor;
}

function sleutels_sleutel_in_lijst(PDO $db, $lijstId, $sleutelId)
{
    $stmt = $db->prepare('SELECT * FROM sleutels WHERE id = :id AND lijst_id = :lijst');
    $stmt->execute([':id' => (int) $sleutelId, ':lijst' => (int) $lijstId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

function sleutels_wijs_toe(PDO $db, $lijstId, $sleutelId, $aan, $opTs, $totTs, $actor)
{
    $sleutel = sleutels_sleutel_in_lijst($db, $lijstId, $sleutelId);
    if ($sleutel === null) {
        throw new SleutelsLijstException('niet_gevonden');
    }
    $actor = trim((string) $actor);
    if ($actor === '') {
        $actor = sleutels_actor();
    }
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE sleutels
                      SET uitgeleend_op = :op,
                          uitgeleend_tot = :tot,
                          uitgeleend_aan = :aan,
                          uitgegeven_door = :door
                      WHERE id = :id AND lijst_id = :lijst')
            ->execute([
                ':op' => (int) $opTs,
                ':tot' => (int) $totTs,
                ':aan' => (string) $aan,
                ':door' => $actor,
                ':id' => (int) $sleutelId,
                ':lijst' => (int) $lijstId,
            ]);
        sleutels_history_log_issue($db, $sleutelId, $aan, $opTs, $totTs, $actor);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function sleutels_breng_terug(PDO $db, $lijstId, $sleutelId, $actor = null)
{
    $sleutel = sleutels_sleutel_in_lijst($db, $lijstId, $sleutelId);
    if ($sleutel === null) {
        throw new SleutelsLijstException('niet_gevonden');
    }
    $door = sleutels_history_actor($actor);
    $db->beginTransaction();
    try {
        sleutels_history_log_return($db, $sleutelId, $sleutel, $door);
        $db->prepare('UPDATE sleutels
                      SET uitgeleend_op = NULL,
                          uitgeleend_tot = NULL,
                          uitgeleend_aan = NULL,
                          uitgegeven_door = NULL
                      WHERE id = :id AND lijst_id = :lijst')
            ->execute([':id' => (int) $sleutelId, ':lijst' => (int) $lijstId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function sleutels_exporteer_lijst(array $lijst, $doelPad)
{
    $src = sleutels_lijst_db($lijst);
    if (is_file($doelPad)) {
        unlink($doelPad);
    }
    $dest = sleutels_open_db($doelPad);
    $stmt = $src->prepare('SELECT id, naam, tapkey_id, opslagplek, toegang, uitgeleend_op, uitgeleend_tot, uitgeleend_aan, uitgegeven_door
                           FROM sleutels WHERE lijst_id = :lijst ORDER BY id');
    $stmt->execute([':lijst' => (int) $lijst['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        $rows = [];
    }
    $hist = $src->prepare('SELECT h.sleutel_id, h.uitgeleend_aan, h.uitgeleend_op, h.uitgeleend_tot, h.uitgegeven_door, h.teruggebracht_op, h.teruggebracht_door
                           FROM sleutel_historie h
                           INNER JOIN sleutels s ON s.id = h.sleutel_id
                           WHERE s.lijst_id = :lijst
                           ORDER BY h.id');
    $hist->execute([':lijst' => (int) $lijst['id']]);
    $historie = $hist->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($historie)) {
        $historie = [];
    }

    $insert = $dest->prepare('INSERT INTO sleutels (id, naam, tapkey_id, opslagplek, toegang, uitgeleend_op, uitgeleend_tot, uitgeleend_aan, uitgegeven_door)
                              VALUES (:id, :naam, :tapkey_id, :opslagplek, :toegang, :uitgeleend_op, :uitgeleend_tot, :uitgeleend_aan, :uitgegeven_door)');
    $insertHist = $dest->prepare('INSERT INTO sleutel_historie (sleutel_id, uitgeleend_aan, uitgeleend_op, uitgeleend_tot, uitgegeven_door, teruggebracht_op, teruggebracht_door)
                                  VALUES (:sleutel_id, :uitgeleend_aan, :uitgeleend_op, :uitgeleend_tot, :uitgegeven_door, :teruggebracht_op, :teruggebracht_door)');
    $dest->beginTransaction();
    try {
        foreach ($rows as $row) {
            $insert->execute([
                ':id' => (int) $row['id'],
                ':naam' => $row['naam'],
                ':tapkey_id' => $row['tapkey_id'],
                ':opslagplek' => $row['opslagplek'],
                ':toegang' => $row['toegang'],
                ':uitgeleend_op' => $row['uitgeleend_op'],
                ':uitgeleend_tot' => $row['uitgeleend_tot'],
                ':uitgeleend_aan' => $row['uitgeleend_aan'],
                ':uitgegeven_door' => $row['uitgegeven_door'],
            ]);
        }
        foreach ($historie as $row) {
            $insertHist->execute([
                ':sleutel_id' => $row['sleutel_id'],
                ':uitgeleend_aan' => $row['uitgeleend_aan'],
                ':uitgeleend_op' => $row['uitgeleend_op'],
                ':uitgeleend_tot' => $row['uitgeleend_tot'],
                ':uitgegeven_door' => $row['uitgegeven_door'],
                ':teruggebracht_op' => $row['teruggebracht_op'],
                ':teruggebracht_door' => $row['teruggebracht_door'],
            ]);
        }
        $dest->commit();
    } catch (Throwable $e) {
        if ($dest->inTransaction()) {
            $dest->rollBack();
        }
        throw $e;
    }

    return count($rows);
}
