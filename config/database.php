<?php

declare(strict_types=1);

/**
 * Blackthorne Academy
 * Database Connection
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/secrets.php';

/*
|--------------------------------------------------------------------------
| Database Configuration
|--------------------------------------------------------------------------
|
| Database credentials are stored in config/secrets.php.
|
| secrets.php is intentionally excluded from source control and must never
| be committed to a public repository.
|
*/

/*
|--------------------------------------------------------------------------
| PDO Database Connection
|--------------------------------------------------------------------------
*/

$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    DB_HOST,
    DB_NAME,
    DB_CHARSET
);

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO(
        $dsn,
        DB_USER,
        DB_PASSWORD,
        $options
    );
} catch (PDOException $e) {

    /*
    |--------------------------------------------------------------------------
    | Development Error Handling
    |--------------------------------------------------------------------------
    */

    if (APP_ENV === 'development') {
        die(
            '<h1>Database Connection Error</h1>' .
            '<p>Blackthorne Academy could not connect to the database.</p>' .
            '<p><strong>Error:</strong> ' .
            htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') .
            '</p>'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Production Error Handling
    |--------------------------------------------------------------------------
    */

    error_log(
        'Blackthorne Academy database connection failed: ' .
        $e->getMessage()
    );

    http_response_code(500);

    die(
        '<h1>Something went wrong.</h1>' .
        '<p>Blackthorne Academy is temporarily unavailable. Please try again later.</p>'
    );
}