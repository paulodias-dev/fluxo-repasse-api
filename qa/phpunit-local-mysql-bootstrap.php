<?php
declare(strict_types=1);
require_once __DIR__.'/mysql-local-safety.php';
$vars = [];
foreach (['APP_ENV', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL'] as $key) {
    $vars[$key] = getenv($key);
}
$errors = localMysqlQaErrors($vars);
if ($errors !== []) {
    fwrite(STDERR, "BLOQUEADO: PHPUnit MySQL local fora de QA:\n".implode("\n", $errors)."\n");
    exit(2);
}
require dirname(__DIR__).'/vendor/autoload.php';
