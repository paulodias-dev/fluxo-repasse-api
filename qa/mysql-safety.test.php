<?php

declare(strict_types=1);

require_once __DIR__.'/mysql-safety.php';

$valid = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'mysql',
    'DB_DATABASE' => 'repasse_qa',
    'DB_HOST' => 'mysql',
    'DB_PORT' => '3306',
    'DB_URL' => '',
    'DB_PASSWORD' => 'senha-somente-de-teste',
];

$cases = [
    'ambiente legítimo aceito' => [$valid, true],
    'produção bloqueada' => [array_replace($valid, ['APP_ENV' => 'production']), false],
    'SQLite bloqueado' => [array_replace($valid, ['DB_CONNECTION' => 'sqlite']), false],
    'banco de produção bloqueado' => [array_replace($valid, ['DB_DATABASE' => 'repasse']), false],
    'banco vazio bloqueado' => [array_replace($valid, ['DB_DATABASE' => '']), false],
    'host remoto bloqueado' => [array_replace($valid, ['DB_HOST' => 'db.example.test']), false],
    'porta externa bloqueada' => [array_replace($valid, ['DB_PORT' => '3307']), false],
    'DB_URL externo bloqueado' => [array_replace($valid, ['DB_URL' => 'mysql://user:secret@prod/db']), false],
    'senha vazia bloqueada' => [array_replace($valid, ['DB_PASSWORD' => '']), false],
    'nome com caractere perigoso bloqueado' => [array_replace($valid, ['DB_DATABASE' => 'repasse_qa;DROP']), false],
];

$passed = 0;
foreach ($cases as $name => [$input, $expected]) {
    $result = mysqlQaEnvironmentErrors($input);
    $actual = $result === [];
    if ($actual !== $expected) {
        fwrite(STDERR, "FALHA: {$name}\n");
        exit(1);
    }
    echo "OK: {$name}\n";
    $passed++;
}
echo "Verificação de segurança MySQL: {$passed}/".count($cases)." aprovados.\n";
