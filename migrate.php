<?php

// Autoload class helper
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/app/';
    $len = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

use App\Config\Database;
use App\Database\Migrations;
use App\Database\Seeders;

try {
    echo "Starting edvora.chat database migrations...\n";
    $db = Database::getConnection();

    $migrations = new Migrations($db);
    $migrations->run();
    echo "✓ Database schema created / verified successfully.\n";

    echo "Running database seeders...\n";
    $seeders = new Seeders($db);
    $seeders->run();
    echo "✓ Initial seed data populated successfully.\n";

    echo "Migration complete!\n";
} catch (Throwable $e) {
    echo "❌ Migration failed: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
