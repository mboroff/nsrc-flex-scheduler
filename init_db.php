<?php
// Created by Claude.AI
// For
// Marty Boroff - WD9GYM
/**
 * init_db.php
 * Run this ONCE from the command line (or a browser, then delete/rename it)
 * to create db/nsrc_flex.db and load the schema.
 *
 *   cd /var/www/html/nsrc-flex
 *   php init_db.php
 */

$dbDir = __DIR__ . '/db';
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0775, true);
}

$dbPath = $dbDir . '/nsrc_flex.db';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$schema = file_get_contents(__DIR__ . '/schema.sql');
$pdo->exec($schema);

echo "Database initialized at $dbPath\n";
