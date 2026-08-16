<?php
// Created by Claude.AI
// For
// Marty Boroff - WD9GYM
/**
 * diagnose.php
 * Visit http://<pi-ip>/nsrc-flex/diagnose.php in a browser to see what's
 * missing. Safe to leave in place, or delete once things are working.
 */

header('Content-Type: text/html; charset=utf-8');

function row(string $label, bool $ok, string $detail = ''): void {
    $color = $ok ? '#1f7a4d' : '#b02a37';
    $mark  = $ok ? 'OK' : 'PROBLEM';
    echo "<tr><td style='padding:6px 12px;border:1px solid #ccc;'>{$label}</td>";
    echo "<td style='padding:6px 12px;border:1px solid #ccc;color:{$color};font-weight:bold;'>{$mark}</td>";
    echo "<td style='padding:6px 12px;border:1px solid #ccc;'>" . htmlspecialchars($detail) . "</td></tr>";
}
?>
<!DOCTYPE html>
<html>
<head><title>North Shore Radio Club Flex-Cadre Diagnostics</title></head>
<body style="font-family:sans-serif;max-width:800px;margin:30px auto;">
<h2>North Shore Radio Club Flex-Cadre Diagnostics</h2>
<table style="border-collapse:collapse;width:100%;">
<tr><th style="padding:6px 12px;border:1px solid #ccc;text-align:left;">Check</th>
    <th style="padding:6px 12px;border:1px solid #ccc;text-align:left;">Status</th>
    <th style="padding:6px 12px;border:1px solid #ccc;text-align:left;">Detail</th></tr>
<?php
row('PHP version', version_compare(PHP_VERSION, '7.4', '>='), PHP_VERSION);
row('pdo extension', extension_loaded('pdo'), extension_loaded('pdo') ? 'loaded' : 'MISSING - run: sudo apt install php-sqlite3');
row('pdo_sqlite extension', extension_loaded('pdo_sqlite'), extension_loaded('pdo_sqlite') ? 'loaded' : 'MISSING - run: sudo apt install php-sqlite3');

$dbDir = __DIR__ . '/db';
$dbPath = $dbDir . '/nsrc_flex.db';

row('db/ directory exists', is_dir($dbDir), $dbDir);
row('db/ directory writable by PHP', is_dir($dbDir) && is_writable($dbDir),
    is_writable($dbDir) ? 'writable' : 'run: sudo chown -R www-data:www-data ' . $dbDir . ' && sudo chmod 775 ' . $dbDir);
row('database file exists', file_exists($dbPath),
    file_exists($dbPath) ? $dbPath : 'run: sudo -u www-data php ' . __DIR__ . '/init_db.php');

if (file_exists($dbPath)) {
    $owner = function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($dbPath))['name'] ?? fileowner($dbPath)) : fileowner($dbPath);
    row('database file writable by PHP', is_writable($dbPath),
        is_writable($dbPath) ? "writable (owned by {$owner})" : "NOT writable - owned by {$owner}, needs to be www-data. Run: sudo chown www-data:www-data {$dbPath} && sudo chmod 664 {$dbPath}");
}

if (file_exists($dbPath) && extension_loaded('pdo_sqlite')) {
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        row('can open database', true, 'tables: ' . implode(', ', $tables));
        row('users table present', in_array('users', $tables, true));
        $count = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        row('user count', true, (string) $count);
    } catch (Exception $e) {
        row('can open database', false, $e->getMessage());
    }
}

$runningUser = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : (getenv('USER') ?: 'unknown');
row('PHP process running as', true, $runningUser);
?>
</table>
<p style="color:#666;">Delete this file once everything shows OK.</p>
</body>
</html>
