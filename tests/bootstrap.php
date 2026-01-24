<?php

/**
 * PHPUnit Bootstrap for MCP Plugin Tests
 */

// Define GRAV_ROOT for standalone testing
if (!defined('GRAV_ROOT')) {
    define('GRAV_ROOT', dirname(__DIR__, 4));
}

// Load Grav's autoloader first (contains Grav classes)
$gravAutoloader = GRAV_ROOT . '/vendor/autoload.php';
if (file_exists($gravAutoloader)) {
    require_once $gravAutoloader;
}

// Load plugin's Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';
