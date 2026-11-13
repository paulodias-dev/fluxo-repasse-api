<?php
declare(strict_types=1);
require_once __DIR__.'/mysql-local-safety.php';
$vars = [];
foreach (['APP_ENV', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL'] as $key) {
    $vars[$key] = getenv($key);
}
$errors = localMysqlQaErrors($vars);
if ($errors !== []) {
    fwrite(STDERR, "BLOQUEADO: MySQL local QA inseguro:\n".implode("\n", $errors)."\n");
    exit(2);
}
if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "BLOQUEADO: extensão pdo_mysql ausente.\n");
    exit(2);
}
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $vars['DB_HOST'], $vars['DB_PORT'], $vars['DB_DATABASE']),
        $vars['DB_USERNAME'], $vars['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 4]
    );
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $vars['DB_DATABASE']) {
        throw new RuntimeException('Schema selecionado incorreto.');
    }
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
    $statement->execute([$vars['DB_DATABASE']]);
    $count = (int) $statement->fetchColumn();
    if ($count > 0 && getenv('QA_ALLOW_TEST_SCHEMA_REBUILD') !== 'YES') {
        fwrite(STDERR, "BLOQUEADO: schema QA já contém tabelas.\n".
            "RefreshDatabase pode reconstruí-las. Apenas se for banco exclusivo de testes, defina QA_ALLOW_TEST_SCHEMA_REBUILD=YES.\n");
        exit(2);
    }
    fwrite(STDOUT, "Banco MySQL local de QA verificado; {$count} tabela(s) existentes.\n");
} catch (PDOException $exception) {
    fwrite(STDERR, "BLOQUEADO: conexão PDO MySQL QA falhou. Confirme servidor, usuário, senha, porta e schema.\n");
    exit(2);
} catch (Throwable $exception) {
    fwrite(STDERR, "BLOQUEADO: verificação do schema QA falhou.\n");
    exit(2);
}
