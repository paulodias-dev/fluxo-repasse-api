<?php
declare(strict_types=1);
require_once __DIR__.'/mysql-local-safety.php';
$valid = [
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306', 'DB_DATABASE' => 'fluxo_repasse_qa',
    'DB_USERNAME' => 'fluxo_repasse_qa', 'DB_PASSWORD' => 'apenas-fixture', 'DB_URL' => '',
];
$cases = [
    'ambiente seguro' => [$valid, true],
    'produção bloqueada' => [array_replace($valid, ['APP_ENV' => 'production']), false],
    'SQLite bloqueado' => [array_replace($valid, ['DB_CONNECTION' => 'sqlite']), false],
    'host remoto bloqueado' => [array_replace($valid, ['DB_HOST' => 'db.example.org']), false],
    'localhost aceito' => [array_replace($valid, ['DB_HOST' => 'localhost']), true],
    'porta alternativa aceita' => [array_replace($valid, ['DB_PORT' => '8889']), true],
    'porta inválida bloqueada' => [array_replace($valid, ['DB_PORT' => '0']), false],
    'schema não QA bloqueado' => [array_replace($valid, ['DB_DATABASE' => 'fluxo_repasse']), false],
    'SQL malformado bloqueado' => [array_replace($valid, ['DB_DATABASE' => 'fluxo_qa;DROP']), false],
    'root bloqueado' => [array_replace($valid, ['DB_USERNAME' => 'root']), false],
    'admin bloqueado' => [array_replace($valid, ['DB_USERNAME' => 'admin']), false],
    'senha vazia bloqueada' => [array_replace($valid, ['DB_PASSWORD' => '']), false],
    'DB_URL externa bloqueada' => [array_replace($valid, ['DB_URL' => 'mysql://prod/db']), false],
];
$failures = 0;
foreach ($cases as $name => [$input, $expected]) {
    $actual = localMysqlQaErrors($input) === [];
    if ($actual !== $expected) {
        fwrite(STDERR, "FALHA: {$name}\n");
        $failures++;
    } else {
        echo "OK: {$name}\n";
    }
}
echo sprintf("QA MySQL local: %d/%d aprovações.\n", count($cases) - $failures, count($cases));
exit($failures === 0 ? 0 : 1);
