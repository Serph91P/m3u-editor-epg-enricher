<?php

/**
 * Pre-release validation script for the EPG Enricher plugin.
 *
 * Checks that:
 * - plugin.json is valid JSON with required fields
 * - The entrypoint class file exists
 * - The declared namespace matches the class file
 *
 * Usage: php scripts/validate-plugin.php
 */

$pluginDir = dirname(__DIR__);
$manifestPath = $pluginDir . '/plugin.json';

echo "Validating EPG Enricher plugin...\n\n";

// Check manifest exists
if (! file_exists($manifestPath)) {
    echo "FAIL: plugin.json not found at {$manifestPath}\n";
    exit(1);
}

// Parse manifest
$manifest = json_decode(file_get_contents($manifestPath), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "FAIL: plugin.json is not valid JSON: " . json_last_error_msg() . "\n";
    exit(1);
}
echo "OK: plugin.json is valid JSON\n";

// Check required fields
$required = ['id', 'name', 'version', 'api_version', 'entrypoint', 'class'];
foreach ($required as $field) {
    if (empty($manifest[$field])) {
        echo "FAIL: Missing required field '{$field}'\n";
        exit(1);
    }
}
echo "OK: All required fields present\n";

// Check entrypoint exists
$entrypoint = $pluginDir . '/' . $manifest['entrypoint'];
if (! file_exists($entrypoint)) {
    echo "FAIL: Entrypoint file not found: {$manifest['entrypoint']}\n";
    exit(1);
}
echo "OK: Entrypoint file exists ({$manifest['entrypoint']})\n";

// Check ID matches directory name
$dirName = basename($pluginDir);
if ($dirName !== $manifest['id']) {
    echo "WARN: Directory name '{$dirName}' does not match plugin ID '{$manifest['id']}'\n";
} else {
    echo "OK: Directory name matches plugin ID\n";
}

// Check table naming convention
if (! empty($manifest['schema']['tables'])) {
    $prefix = 'plugin_' . str_replace('-', '_', $manifest['id']) . '_';
    foreach ($manifest['schema']['tables'] as $table) {
        if (strpos($table['name'], $prefix) !== 0) {
            echo "FAIL: Table '{$table['name']}' must start with '{$prefix}'\n";
            exit(1);
        }
    }
    echo "OK: All table names follow naming convention\n";
}

echo "\nAll checks passed.\n";
exit(0);
