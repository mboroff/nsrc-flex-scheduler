<?php
// Created by Claude.AI
// For
// Marty Boroff - WD9GYM
/**
 * migrate_db.php
 * Run this ONCE on an existing installation to add the new last_login
 * column without deleting any existing accounts. Safe to run more than
 * once - it checks first and does nothing if the column is already there.
 *
 *   cd /var/www/html   (or wherever this site lives)
 *   sudo -u www-data php migrate_db.php
 */

$dbPath = __DIR__ . '/db/nsrc_flex.db';

if (!file_exists($dbPath)) {
    echo "No database found at $dbPath - nothing to migrate. Run init_db.php instead.\n";
    exit;
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$columns = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
$hasLastLogin = false;
foreach ($columns as $col) {
    if ($col['name'] === 'last_login') {
        $hasLastLogin = true;
        break;
    }
}

if ($hasLastLogin) {
    echo "users.last_login already exists - nothing to do.\n";
} else {
    $pdo->exec('ALTER TABLE users ADD COLUMN last_login TEXT');
    echo "Added last_login column to users. Existing accounts are untouched.\n";
}

$resColumns = $pdo->query("PRAGMA table_info(reservations)")->fetchAll(PDO::FETCH_ASSOC);
$hasSnapshot = false;
foreach ($resColumns as $col) {
    if ($col['name'] === 'call_sign_snapshot') {
        $hasSnapshot = true;
        break;
    }
}

if ($hasSnapshot) {
    echo "reservations.call_sign_snapshot already exists - nothing to do.\n";
} else {
    $pdo->exec("ALTER TABLE reservations ADD COLUMN call_sign_snapshot TEXT NOT NULL DEFAULT ''");
    // Backfill existing rows from whichever account currently holds each
    // reservation, so nothing already booked loses its Call Sign display.
    $pdo->exec(
        "UPDATE reservations
         SET call_sign_snapshot = (SELECT call_sign FROM users WHERE users.id = reservations.user_id)
         WHERE EXISTS (SELECT 1 FROM users WHERE users.id = reservations.user_id)"
    );
    echo "Added call_sign_snapshot column to reservations and backfilled it from current accounts.\n";
}

$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
if (in_array('radio_status', $tables, true)) {
    echo "radio_status table already exists - nothing to do.\n";
} else {
    $pdo->exec(
        "CREATE TABLE radio_status (
            radio_name   TEXT PRIMARY KEY,
            status       TEXT NOT NULL,
            activated_at TEXT
        )"
    );
    $pdo->exec("INSERT OR IGNORE INTO radio_status (radio_name, status) VALUES ('MunEndfed', 'Available for Activation')");
    $pdo->exec("INSERT OR IGNORE INTO radio_status (radio_name, status) VALUES ('MunAV640', 'Available for Activation')");
    echo "Created radio_status table for the radio activation feature.\n";
}

$userCols = array_column($pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC), 'name');
$newProfileCols = [
    'first_name' => "ALTER TABLE users ADD COLUMN first_name TEXT NOT NULL DEFAULT ''",
    'last_name' => "ALTER TABLE users ADD COLUMN last_name TEXT NOT NULL DEFAULT ''",
    'street_address' => "ALTER TABLE users ADD COLUMN street_address TEXT NOT NULL DEFAULT ''",
    'city' => "ALTER TABLE users ADD COLUMN city TEXT NOT NULL DEFAULT ''",
    'state' => "ALTER TABLE users ADD COLUMN state TEXT NOT NULL DEFAULT ''",
    'zip_code' => "ALTER TABLE users ADD COLUMN zip_code TEXT NOT NULL DEFAULT ''",
    'phone_number' => "ALTER TABLE users ADD COLUMN phone_number TEXT NOT NULL DEFAULT ''",
];
foreach ($newProfileCols as $col => $sql) {
    if (in_array($col, $userCols, true)) {
        echo "users.$col already exists - nothing to do.\n";
    } else {
        $pdo->exec($sql);
        echo "Added users.$col.\n";
    }
}

if (in_array('members', $tables, true)) {
    echo "members table already exists - nothing to do.\n";
} else {
    $pdo->exec(
        "CREATE TABLE members (
            call_sign   TEXT PRIMARY KEY,
            first_name  TEXT,
            last_name   TEXT,
            dues_column TEXT,
            dues_value  TEXT,
            is_current  INTEGER NOT NULL DEFAULT 0,
            overridden  INTEGER NOT NULL DEFAULT 0,
            comments    TEXT,
            imported_at TEXT NOT NULL
        )"
    );
    echo "Created members table - upload the club roster via Admin > Membership List.\n";
}

$memberCols = in_array('members', $tables, true)
    ? array_column($pdo->query("PRAGMA table_info(members)")->fetchAll(PDO::FETCH_ASSOC), 'name')
    : [];
if ($memberCols && !in_array('overridden', $memberCols, true)) {
    $pdo->exec("ALTER TABLE members ADD COLUMN overridden INTEGER NOT NULL DEFAULT 0");
    echo "Added members.overridden.\n";
}

if (in_array('is_admin', $userCols, true)) {
    echo "users.is_admin already exists - nothing to do.\n";
} else {
    $pdo->exec("ALTER TABLE users ADD COLUMN is_admin TEXT NOT NULL DEFAULT ''");
    echo "Added users.is_admin.\n";
}
