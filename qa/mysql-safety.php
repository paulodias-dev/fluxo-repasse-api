<?php

declare(strict_types=1);

/**
 * @param array<string, string|false> $env
 * @return list<string>
 */
function mysqlQaEnvironmentErrors(array $env): array
{
    $errors = [];
    if (($env['APP_ENV'] ?? '') !== 'testing') {
        $errors[] = 'APP_ENV deve ser testing.';
    }
    if (($env['DB_CONNECTION'] ?? '') !== 'mysql') {
        $errors[] = 'DB_CONNECTION deve ser mysql.';
    }
    $database = $env['DB_DATABASE'] ?? '';
    if (!is_string($database) || !preg_match('/\A[a-zA-Z0-9_]+_qa\z/D', $database)) {
        $errors[] = 'DB_DATABASE deve identificar um banco exclusivo terminado em _qa.';
    }
    if (($env['DB_HOST'] ?? '') !== 'mysql') {
        $errors[] = 'DB_HOST deve apontar para o serviço MySQL isolado do Compose (mysql).';
    }
    if (($env['DB_PORT'] ?? '') !== '3306') {
        $errors[] = 'DB_PORT deve ser 3306 no ambiente isolado.';
    }
    if (!empty($env['DB_URL'])) {
        $errors[] = 'DB_URL deve estar vazio para evitar sobrescrever a conexão isolada.';
    }
    if (empty($env['DB_PASSWORD'])) {
        $errors[] = 'DB_PASSWORD de QA não pode estar vazio.';
    }
    return $errors;
}
