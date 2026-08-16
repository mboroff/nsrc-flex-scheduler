<?php
// Created by Claude.AI
// For
// Marty Boroff - WD9GYM
require_once __DIR__ . '/config.php';
$_SESSION = [];
session_destroy();
header('Location: index.php');
exit;
