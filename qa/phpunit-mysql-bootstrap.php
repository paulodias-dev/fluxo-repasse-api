<?php

declare(strict_types=1);

require_once __DIR__.'/mysql-safety.php';

$variables = [];
foreach (['APP_ENV', 'DB_CONNECTION', 'DB_DATABASE', 'DB_HOST', 'DB_PORT', 'DB_URL', 'DB_PASSWORD'] as $name) {
    $variables[$name] = getenv($name);
}

$errors = mysqlQaEnvironmentErrors($variables);
if ($errors !== []) {
    fwrite(STDERR, "BLOQUEADO: PHPUnit/MySQL fora de ambiente isolado:\n".implode("\n", $errors)."\n");
    exit(2);
}

require dirname(__DIR__).'/vendor/autoload.php';
