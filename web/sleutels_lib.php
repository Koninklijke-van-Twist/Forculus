<?php

/**
 * Gedeelde databasehulp voor het sleutelbeheer van de ingelogde gebruiker.
 * Elke gebruiker heeft een eigen bestand: sleutels_<naam>.sqlite
 */

class SleutelsImportException extends RuntimeException
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

function sleutels_db_filename($userName)
{
    $safe = str_replace([' ', '/', '\\', "\0"], '_', (string) $userName);
    if ($safe === '' || $safe === '.' || $safe === '..') {
        $safe = 'onbekend';
    }

    return 'sleutels_' . $safe . '.sqlite';
}

function sleutels_db_path($userName)
{
    return __DIR__ . DIRECTORY_SEPARATOR . sleutels_db_filename($userName);
}

function sleutels_ensure_schema(PDO $db)
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS sleutels (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            naam            TEXT NOT NULL,
            tapkey_id       TEXT,
            opslagplek      TEXT,
            toegang         TEXT,
            uitgeleend_op   INTEGER,
            uitgeleend_tot  INTEGER,
            uitgeleend_aan  TEXT
        )
    ");
}

function sleutels_open_db($path)
{
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout = 5000');
    sleutels_ensure_schema($db);

    return $db;
}

function sleutels_format_timestamp($ts, $includeTime)
{
    if ($ts === null || $ts === '' || $ts === false || !is_numeric($ts)) {
        return '';
    }

    $n = (int) $ts;
    if ($n === 0) {
        return '';
    }
    if ($n === -1) {
        return 'Onbeperkte tijd';
    }

    if ($includeTime) {
        return date('d-m-Y H:i', $n);
    }

    return date('d-m-Y', $n);
}

function sleutels_is_overdue($totTs)
{
    if ($totTs === null || $totTs === '' || $totTs === false || !is_numeric($totTs)) {
        return false;
    }

    $tot = (int) $totTs;
    if ($tot === -1 || $tot <= 0) {
        return false;
    }

    return date('Y-m-d', $tot) <= date('Y-m-d');
}

function sleutels_user_label(array $user)
{
    $naam = trim((string) ($user['Naam'] ?? ''));
    if ($naam === '') {
        $naam = '(naam onbekend)';
    }
    $email = trim((string) ($user['Email'] ?? ''));
    if ($email === '') {
        $email = '(email onbekend)';
    }

    return $naam . ' (' . $email . ')';
}

function sleutels_borrower_view($aanId, array $userById, $hasLoan, $totTs)
{
    if (!$hasLoan) {
        return [
            'text' => 'niet uitgeleend',
            'class' => 'tag',
        ];
    }

    $overdue = sleutels_is_overdue($totTs);
    $id = trim((string) $aanId);

    if ($id !== '' && isset($userById[$id])) {
        return [
            'text' => sleutels_user_label($userById[$id]),
            'class' => 'tag ' . ($overdue ? 'tag-red-kvt' : 'tag-blue'),
        ];
    }

    if ($id === '') {
        return [
            'text' => '(onbekende gebruiker)',
            'class' => 'tag tag-red',
        ];
    }

    return [
        'text' => $id . ' (Extern)',
        'class' => 'tag ' . ($overdue ? 'tag-red' : 'tag-green'),
    ];
}

function sleutels_borrower_html($aanId, array $userById, $hasLoan, $totTs)
{
    $view = sleutels_borrower_view($aanId, $userById, $hasLoan, $totTs);

    return '<span class="' . htmlspecialchars($view['class'], ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($view['text'], ENT_QUOTES, 'UTF-8')
        . '</span>';
}

function sleutels_borrower_input_label($idOrName, array $userById)
{
    $raw = trim((string) $idOrName);
    if ($raw !== '' && isset($userById[$raw])) {
        return sleutels_user_label($userById[$raw]);
    }

    return $raw;
}

function sleutels_resolve_borrower($raw, array $userById)
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '';
    }
    if (isset($userById[$raw])) {
        return (string) $raw;
    }

    $matches = [];
    foreach ($userById as $id => $user) {
        if (!is_array($user)) {
            continue;
        }
        $label = sleutels_user_label($user);
        $email = trim((string) ($user['Email'] ?? ''));
        if (strcasecmp($label, $raw) === 0 || ($email !== '' && strcasecmp($email, $raw) === 0)) {
            $matches[] = (string) $id;
        }
    }

    if (count($matches) === 1) {
        return $matches[0];
    }

    return $raw;
}

function sleutels_is_sqlite_file($path)
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    $header = fread($handle, 16);
    fclose($handle);

    return $header === "SQLite format 3\0";
}

function sleutels_import_error_message($code)
{
    switch ((string) $code) {
        case 'geen_bestand':
            return 'Kies een .sqlite-bestand om te importeren.';
        case 'te_groot':
            return 'Het bestand is te groot. De maximale grootte is 20 MB.';
        case 'geen_sqlite':
            return 'Dit bestand is geen geldige SQLite-database.';
        case 'geen_tabel':
            return 'De database bevat geen tabel "sleutels".';
        case 'kolommen':
            return 'De tabel "sleutels" mist verplichte kolommen.';
        case 'ongeldige_rijen':
            return 'Import afgebroken: een sleutel heeft geen geldig id of geen naam. Er is niets gewijzigd.';
        case 'te_veel':
            return 'Het bestand bevat te veel sleutels om in één keer te importeren.';
        case 'upload':
            return 'Het uploaden is mislukt. Probeer het opnieuw.';
        default:
            return 'Importeren is mislukt. Controleer het bestand en probeer het opnieuw.';
    }
}

function sleutels_import_success_message($inserted, $updated)
{
    $inserted = (int) $inserted;
    $updated = (int) $updated;
    if ($inserted === 0 && $updated === 0) {
        return 'Import afgerond: het bestand bevatte geen sleutels. Er is niets gewijzigd.';
    }

    return 'Import gelukt: '
        . sleutels_count_word($inserted, 'sleutel toegevoegd', 'sleutels toegevoegd')
        . ', '
        . sleutels_count_word($updated, 'sleutel overschreven', 'sleutels overschreven')
        . '.';
}

function sleutels_count_word($count, $singular, $plural)
{
    $count = (int) $count;

    return $count . ' ' . ($count === 1 ? $singular : $plural);
}

function sleutels_required_columns()
{
    return ['id', 'naam', 'tapkey_id', 'opslagplek', 'toegang', 'uitgeleend_op', 'uitgeleend_tot', 'uitgeleend_aan'];
}

function sleutels_assert_import_source(PDO $source)
{
    $stmt = $source->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sleutels'");
    if (!$stmt || !$stmt->fetchColumn()) {
        throw new SleutelsImportException('geen_tabel');
    }

    $present = [];
    $info = $source->query('PRAGMA table_info(sleutels)');
    if ($info) {
        foreach ($info as $column) {
            $present[] = strtolower((string) ($column['name'] ?? ''));
        }
    }

    foreach (sleutels_required_columns() as $column) {
        if (!in_array($column, $present, true)) {
            throw new SleutelsImportException('kolommen');
        }
    }
}

function sleutels_import_id($value)
{
    if (is_int($value) && $value > 0) {
        return $value;
    }
    if ((is_string($value) || is_float($value)) && preg_match('/^[1-9][0-9]*$/', (string) $value)) {
        return (int) $value;
    }

    throw new SleutelsImportException('ongeldige_rijen');
}

function sleutels_import_text($value)
{
    if ($value === null) {
        return null;
    }

    return (string) $value;
}

function sleutels_import_int($value)
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value) && preg_match('/^-?[0-9]+$/', $value)) {
        return (int) $value;
    }
    if (is_float($value) && floor($value) == $value) {
        return (int) $value;
    }

    throw new SleutelsImportException('ongeldige_rijen');
}

/**
 * Voegt rijen uit $source samen in $target.
 * Zelfde id wordt overschreven, een nieuw id wordt toegevoegd.
 *
 * @return array{inserted:int,updated:int}
 */
function sleutels_merge_import(PDO $target, PDO $source)
{
    sleutels_assert_import_source($source);
    sleutels_ensure_schema($target);

    $rows = $source->query('SELECT id, naam, tapkey_id, opslagplek, toegang, uitgeleend_op, uitgeleend_tot, uitgeleend_aan FROM sleutels')->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        $rows = [];
    }
    if (count($rows) > 20000) {
        throw new SleutelsImportException('te_veel');
    }

    $existing = [];
    $have = $target->query('SELECT id FROM sleutels');
    if ($have) {
        foreach ($have as $row) {
            $existing[(int) $row['id']] = true;
        }
    }

    $inserted = 0;
    $updated = 0;
    $target->beginTransaction();
    try {
        $update = $target->prepare('
            UPDATE sleutels
            SET naam = :naam,
                tapkey_id = :tapkey_id,
                opslagplek = :opslagplek,
                toegang = :toegang,
                uitgeleend_op = :uitgeleend_op,
                uitgeleend_tot = :uitgeleend_tot,
                uitgeleend_aan = :uitgeleend_aan
            WHERE id = :id
        ');
        $insert = $target->prepare('
            INSERT INTO sleutels (id, naam, tapkey_id, opslagplek, toegang, uitgeleend_op, uitgeleend_tot, uitgeleend_aan)
            VALUES (:id, :naam, :tapkey_id, :opslagplek, :toegang, :uitgeleend_op, :uitgeleend_tot, :uitgeleend_aan)
        ');

        foreach ($rows as $row) {
            $id = sleutels_import_id($row['id'] ?? null);
            $naam = trim((string) ($row['naam'] ?? ''));
            if ($naam === '') {
                throw new SleutelsImportException('ongeldige_rijen');
            }

            $params = [
                ':id' => $id,
                ':naam' => $naam,
                ':tapkey_id' => sleutels_import_text($row['tapkey_id'] ?? null),
                ':opslagplek' => sleutels_import_text($row['opslagplek'] ?? null),
                ':toegang' => sleutels_import_text($row['toegang'] ?? null),
                ':uitgeleend_op' => sleutels_import_int($row['uitgeleend_op'] ?? null),
                ':uitgeleend_tot' => sleutels_import_int($row['uitgeleend_tot'] ?? null),
                ':uitgeleend_aan' => sleutels_import_text($row['uitgeleend_aan'] ?? null),
            ];

            if (isset($existing[$id])) {
                $update->execute($params);
                $updated++;
            } else {
                $insert->execute($params);
                $existing[$id] = true;
                $inserted++;
            }
        }

        $target->commit();
    } catch (Throwable $e) {
        if ($target->inTransaction()) {
            $target->rollBack();
        }
        throw $e;
    }

    return [
        'inserted' => $inserted,
        'updated' => $updated,
    ];
}

function sleutels_import_file($targetPath, $sourcePath)
{
    if (!is_file($sourcePath) || !sleutels_is_sqlite_file($sourcePath)) {
        throw new SleutelsImportException('geen_sqlite');
    }

    $size = filesize($sourcePath);
    if ($size === false || $size > 20 * 1024 * 1024) {
        throw new SleutelsImportException('te_groot');
    }

    $sourceReal = realpath($sourcePath);
    $targetReal = realpath($targetPath);
    if ($sourceReal !== false && $targetReal !== false && $sourceReal === $targetReal) {
        throw new SleutelsImportException('upload');
    }

    $source = new PDO('sqlite:' . $sourcePath);
    $source->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $source->exec('PRAGMA query_only = ON');

    $target = sleutels_open_db($targetPath);

    return sleutels_merge_import($target, $source);
}

function sleutels_import_upload($targetPath, array $file)
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new SleutelsImportException('geen_bestand');
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new SleutelsImportException('te_groot');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new SleutelsImportException('upload');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new SleutelsImportException('geen_bestand');
    }

    return sleutels_import_file($targetPath, $tmp);
}

function sleutels_copy_consistent($sourcePath, $destPath)
{
    if (!is_file($sourcePath)) {
        throw new RuntimeException('Bronbestand ontbreekt.');
    }

    if (class_exists('SQLite3')) {
        $src = new SQLite3($sourcePath, SQLITE3_OPEN_READONLY);
        try {
            $dest = new SQLite3($destPath);
            try {
                $ok = $src->backup($dest);
            } finally {
                $dest->close();
            }
        } finally {
            $src->close();
        }
        if (!$ok) {
            throw new RuntimeException('SQLite-backup mislukt.');
        }
        return;
    }

    if (!copy($sourcePath, $destPath)) {
        throw new RuntimeException('Backup kopiëren mislukt.');
    }
}
