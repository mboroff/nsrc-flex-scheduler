<?php
// Created by Claude.AI
// For
// Marty Boroff - WD9GYM
/**
 * config.php
 * Shared database connection for the North Shore Radio Club Flex-Cadre site.
 * Uses SQLite via PDO (bundled with PHP - no extra service needed).
 */

// Without this, PHP falls back to UTC regardless of the Pi's own system
// clock/timezone setting - which is why "today" could read as tomorrow
// once it passes midnight UTC (7pm/8pm Central, depending on daylight
// saving). Every date/time calculation on the site depends on this being
// set correctly.
date_default_timezone_set('America/Chicago');

session_start();

define('DB_PATH', __DIR__ . '/db/nsrc_flex.db');

// The one Call Sign allowed onto admin.php. Compared uppercase so login
// is not case-sensitive.
define('ADMIN_CALL_SIGN', 'WD9GYM');

// The Node-RED dashboard that actually controls the radios. Opened in a
// new tab from radio_control.php once the reservation check clears.
// The host is worked out dynamically from the server's own address on
// each request (SERVER_ADDR - the IP the web server is actually
// listening on for this request), so relocating the Pi to a new
// network address doesn't need a code change. Only the port and
// dashboard path prefix are fixed here.
define('NODE_RED_PORT', 1880);
define('NODE_RED_PATH_PREFIX', '/dashboard/');

/** Build the Node-RED dashboard URL for a given page slug (e.g. "MunEndfed", "radio-activation"). */
function node_red_url(string $pageSlug): string {
    $host = $_SERVER['SERVER_ADDR'] ?? $_SERVER['SERVER_NAME'] ?? gethostbyname(gethostname());
    return 'http://' . $host . ':' . NODE_RED_PORT . NODE_RED_PATH_PREFIX . $pageSlug;
}

/**
 * Call signs are stored and compared in uppercase, trimmed of stray
 * whitespace, so "w9abc" and "W9ABC" are treated as the same account
 * (and so the "already in use" check in create_account.php actually
 * catches every case variant).
 */
function normalize_call_sign(string $callSign): string {
    return strtoupper(trim($callSign));
}

/**
 * Is the currently logged-in user an admin? WD9GYM is always a
 * super-admin (the hardcoded constant above never changes). Anyone else
 * can be delegated the admin role via users.is_admin = 'Y', which is
 * looked up once at login time and cached in the server-side PHP
 * session (not a cookie - the session data itself lives on the server;
 * it goes away on logout or when the session ends). A change to
 * someone else's is_admin flag while they're already logged in takes
 * effect the next time they log in.
 */
function session_is_admin(): bool {
    if (!isset($_SESSION['call_sign'])) {
        return false;
    }
    if (strtoupper($_SESSION['call_sign']) === ADMIN_CALL_SIGN) {
        return true;
    }
    return !empty($_SESSION['is_admin']);
}

/**
 * Show a plain-English error instead of a bare HTTP 500 when something
 * environmental is wrong (missing extension, missing db file, bad
 * permissions), and log the real reason to Apache's error log either way.
 */
function fail_with(string $message): void {
    http_response_code(500);
    error_log('NSRC Flex-Cadre: ' . $message);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;max-width:600px;margin:60px auto;">';
    echo '<h2>Site configuration problem</h2><p>' . htmlspecialchars($message) . '</p>';
    echo '<p>Run <code>diagnose.php</code> in this same folder from a browser for details, ';
    echo 'or check <code>/var/log/apache2/error.log</code> on the Pi.</p>';
    echo '</body></html>';
    exit;
}

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (!extension_loaded('pdo_sqlite')) {
            fail_with('The pdo_sqlite PHP extension is not installed. Run: sudo apt install php-sqlite3 && sudo systemctl restart apache2');
        }
        if (!file_exists(DB_PATH)) {
            fail_with('Database file not found at ' . DB_PATH . '. Run: sudo -u www-data php ' . __DIR__ . '/init_db.php');
        }
        if (!is_writable(dirname(DB_PATH))) {
            fail_with('The db/ folder is not writable by the web server. Run: sudo chown -R www-data:www-data ' . dirname(DB_PATH) . ' && sudo chmod 775 ' . dirname(DB_PATH));
        }
        if (!is_writable(DB_PATH)) {
            fail_with('The database file exists but is not writable by the web server (it was probably created by a different user, e.g. "pi"). Run: sudo chown www-data:www-data ' . DB_PATH . ' && sudo chmod 664 ' . DB_PATH);
        }
        try {
            $pdo = new PDO('sqlite:' . DB_PATH);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // Deliberately NOT enforcing foreign keys: reservations are
            // meant to outlive the account that made them (see schema.sql),
            // so a deleted user must never block or cascade-delete their
            // existing reservations.
            $pdo->exec('PRAGMA foreign_keys = OFF;');
        } catch (PDOException $e) {
            fail_with('Could not open the database: ' . $e->getMessage());
        }

        // Catch an out-of-date database (this site was updated but
        // migrate_db.php was never run) before it causes a raw SQL error
        // on some other page.
        $columns = $pdo->query("PRAGMA table_info(reservations)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('call_sign_snapshot', $columns, true)) {
            fail_with('This site was updated but the database was not. Run: sudo -u www-data php ' . __DIR__ . '/migrate_db.php');
        }
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('members', $tables, true)) {
            fail_with('This site was updated but the database was not. Run: sudo -u www-data php ' . __DIR__ . '/migrate_db.php');
        }
        $memberColumns = $pdo->query("PRAGMA table_info(members)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('overridden', $memberColumns, true)) {
            fail_with('This site was updated but the database was not. Run: sudo -u www-data php ' . __DIR__ . '/migrate_db.php');
        }
        $userColumns = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('is_admin', $userColumns, true)) {
            fail_with('This site was updated but the database was not. Run: sudo -u www-data php ' . __DIR__ . '/migrate_db.php');
        }
    }
    return $pdo;
}

/** Small helper so every page escapes output the same way. */
function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * The five stations: which radio model lives where, and what antennas
 * it's on. Shown in the site header. Update this list if a radio,
 * antenna, or location changes.
 */
function get_stations(): array {
    return [
        ['location' => 'Northfield', 'model' => 'Flex-8400', 'antennas' => ['AV640 Vertical', '6 M Moxon (Summer)', '40-160 Inverted L (Winter)']],
        ['location' => 'Northbrook', 'model' => 'Flex-8600', 'antennas' => ['R9 Vertical']],
        ['location' => 'Skokie',     'model' => 'Flex-6400', 'antennas' => ['Folded Dipole']],
        ['location' => 'MunAV640',   'model' => 'Flex-8600', 'antennas' => ['AV640 Vertical']],
        ['location' => 'MunEndfed',  'model' => 'Flex-6600', 'antennas' => ['10-40 Endfed']],
    ];
}

/** Which photo file represents a given radio model. */
function station_photo_filename(string $model): string {
    $map = [
        'Flex-6400' => 'flex6400.png',
        'Flex-8400' => 'flex8400.png',
        'Flex-8600' => 'flex8600.png',
        // No distinct Flex-6600 photo exists, so it reuses the 8600 photo.
        'Flex-6600' => 'flex6600.png',
    ];
    return $map[$model] ?? 'flex8600.png';
}

/**
 * Is the given radio's CURRENT hour reserved by someone other than the
 * given Call Sign? Used to gate the Activate buttons on radio_control.php
 * so nobody can power on / open the control dashboard for a radio during
 * someone else's reserved time slot.
 *
 * Returns the reserving Call Sign if there's a conflict, or null if the
 * slot is open (or reserved by the same Call Sign, which is fine).
 */
function get_current_hour_conflict(PDO $db, string $radioName, string $myCallSign): ?string {
    $radioRow = $db->prepare('SELECT id FROM radios WHERE name = ?');
    $radioRow->execute([$radioName]);
    $radioId = $radioRow->fetchColumn();
    if (!$radioId) {
        return null;
    }

    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare(
        "SELECT COALESCE(users.call_sign, reservations.call_sign_snapshot) AS call_sign
         FROM reservations
         LEFT JOIN users ON users.id = reservations.user_id
         WHERE reservations.radio_id = ? AND reservations.start_time <= ? AND reservations.end_time > ?"
    );
    $stmt->execute([$radioId, $now, $now]);
    $reservedBy = $stmt->fetchColumn();

    if ($reservedBy && normalize_call_sign($reservedBy) !== normalize_call_sign($myCallSign)) {
        return $reservedBy;
    }
    return null;
}
