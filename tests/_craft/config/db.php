<?php

use craft\helpers\App;

// Test database connection. Defaults target the bundled ddev environment
// (host "db", database "db", user/password "db"), but each value can be
// overridden with the matching CRAFT_DB_* environment variable.
return [
    'dsn' => App::env('CRAFT_DB_DSN') ?: 'mysql:host=db;port=3306;dbname=db',
    'user' => App::env('CRAFT_DB_USER') ?: 'db',
    'password' => App::env('CRAFT_DB_PASSWORD') ?: 'db',
    'schema' => App::env('CRAFT_DB_SCHEMA') ?: null,
    'tablePrefix' => App::env('CRAFT_DB_TABLE_PREFIX') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];
