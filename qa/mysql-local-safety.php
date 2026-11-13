<?php
declare(strict_types=1);

/** @param array<string, string|false> $environment
 * @return list<string>
 */
function localMysqlQaErrors(array $environment): array
{
    $errors = [];
    if (($environment['APP_ENV'] ?? '') !== 'testing') $errors[] = 'APP_ENV precisa ser testing.';
    if (($environment['DB_CONNECTION'] ?? '') !== 'mysql') $errors[] = 'DB_CONNECTION precisa ser mysql.';
    if (!in_array($environment['DB_HOST'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)) {
        $errors[] = 'DB_HOST deve ser loopback local.';
    }
    $port = $environment['DB_PORT'] ?? '';
    if (!is_string($port) || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        $errors[] = 'DB_PORT deve ser porta TCP válida.';
    }
    $database = $environment['DB_DATABASE'] ?? '';
    if (!is_string($database) || !preg_match('/\A[a-zA-Z0-9_]+_qa\z/D', $database)) {
        $errors[] = 'DB_DATABASE deve ser schema exclusivo terminado em _qa.';
    }
    $username = $environment['DB_USERNAME'] ?? '';
    if (!is_string($username) || $username === '' || in_array(strtolower($username), ['root', 'admin'], true)) {
        $errors[] = 'DB_USERNAME deve ser conta própria de QA, não root/admin.';
    }
    if (empty($environment['DB_PASSWORD'])) $errors[] = 'DB_PASSWORD de QA obrigatório.';
    if (!empty($environment['DB_URL'])) $errors[] = 'DB_URL deve estar vazio.';
    return $errors;
}
