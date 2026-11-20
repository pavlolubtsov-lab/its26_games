<?php
/**
 * leaderboard.php — game leaderboard API (PHP + SQLite via PDO)
 *
 *   GET  leaderboard.php?limit=10   -> {"rows":[{"nimi","skoor","tegelane","aeg"}, ...]}
 *   POST leaderboard.php  (JSON)    -> {"ok":true,"rank":N}
 *        {"nimi":"Name","skoor":1234,"tegelane":0,"aeg":95,"tapetud":12,"voit":1}
 *
 * The table mirrors the console example: skoorid(id, nimi, skoor) + extra columns.
 * Requires PHP 7.4+ with the pdo_sqlite extension (enabled on almost every host).
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const DB_DIR   = __DIR__ . '/data';   // the database lives in a folder closed to the web
const MAX_ROWS = 50;                  // maximum rows returned
const COOLDOWN = 10;                  // seconds between submissions from one IP
const ENEMIES  = 16;                  // number of enemies on the level

function out(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- database ---------------------------------------------------------------
try {
    if (!is_dir(DB_DIR)) {
        mkdir(DB_DIR, 0750, true);
        file_put_contents(DB_DIR . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    $db = new PDO('sqlite:' . DB_DIR . '/skoorid.db', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec("CREATE TABLE IF NOT EXISTS skoorid (
        id       INTEGER PRIMARY KEY AUTOINCREMENT,
        nimi     TEXT    NOT NULL,
        skoor    INTEGER NOT NULL,
        tegelane INTEGER NOT NULL DEFAULT 0,
        aeg      INTEGER NOT NULL DEFAULT 0,
        tapetud  INTEGER NOT NULL DEFAULT 0,
        voit     INTEGER NOT NULL DEFAULT 0,
        ip       TEXT,
        loodud   TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_skoor ON skoorid (skoor DESC, aeg ASC)");
} catch (Throwable $e) {
    out(500, ['error' => 'database error']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ---- GET: leaderboard (like SELECT ... ORDER BY skoor DESC) --------------------
if ($method === 'GET') {
    $limit = max(1, min(MAX_ROWS, (int)($_GET['limit'] ?? 10)));
    $st = $db->prepare('SELECT nimi, skoor, tegelane, aeg FROM skoorid
                        ORDER BY skoor DESC, aeg ASC, id ASC LIMIT :l');
    $st->bindValue(':l', $limit, PDO::PARAM_INT);
    $st->execute();
    out(200, ['rows' => $st->fetchAll()]);
}

// ---- POST: add score (like INSERT INTO skoorid ...) ---------------------------
if ($method === 'POST') {
    $in = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($in)) out(400, ['error' => 'invalid json']);

    // name: strip odd characters, 1..16 chars
    $nimi = trim((string)($in['nimi'] ?? ''));
    $nimi = preg_replace('/[^\p{L}\p{N} _\-\.]/u', '', $nimi) ?? '';
    $nimi = function_exists('mb_substr') ? mb_substr($nimi, 0, 16, 'UTF-8') : substr($nimi, 0, 16);
    if ($nimi === '') out(400, ['error' => 'name required']);

    // numbers: must be integers inside sane ranges
    $num = function (string $k, int $min, int $max) use ($in): int {
        $v = $in[$k] ?? null;
        if (!is_int($v) && !(is_string($v) && ctype_digit($v))) out(400, ['error' => "bad $k"]);
        $v = (int)$v;
        if ($v < $min || $v > $max) out(400, ['error' => "bad $k"]);
        return $v;
    };
    $skoor    = $num('skoor', 0, 100000);
    $tegelane = $num('tegelane', 0, 2);
    $aeg      = $num('aeg', 0, 36000);
    $tapetud  = $num('tapetud', 0, ENEMIES);
    $voit     = $num('voit', 0, 1);

    // plausibility: a score cannot exceed what the game can award
    if ($skoor > $tapetud * 100 + 3250) out(400, ['error' => 'implausible score']);

    // simple rate limit per IP
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $st = $db->prepare("SELECT COUNT(*) FROM skoorid
                        WHERE ip = :ip AND loodud > datetime('now', :w)");
    $st->execute([':ip' => $ip, ':w' => '-' . COOLDOWN . ' seconds']);
    if ((int)$st->fetchColumn() > 0) out(429, ['error' => 'too many submissions']);

    $st = $db->prepare('INSERT INTO skoorid (nimi, skoor, tegelane, aeg, tapetud, voit, ip)
                        VALUES (:n, :s, :t, :a, :k, :v, :ip)');
    $st->execute([':n' => $nimi, ':s' => $skoor, ':t' => $tegelane, ':a' => $aeg,
                  ':k' => $tapetud, ':v' => $voit, ':ip' => $ip]);

    $st = $db->prepare('SELECT COUNT(*) + 1 FROM skoorid WHERE skoor > :s');
    $st->execute([':s' => $skoor]);
    out(200, ['ok' => true, 'rank' => (int)$st->fetchColumn()]);
}

out(405, ['error' => 'method not allowed']);
