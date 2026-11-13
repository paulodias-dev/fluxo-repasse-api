# Homologação local no macOS — PHP + MySQL, sem Docker

Este é o fluxo **oficial para desenvolvimento/homologação local com MySQL**. **Não** usar \`qa/run.sh\`: ele é um executor separado, específico para Docker Compose. Também não executar \`phpunit.mysql.xml\` no Mac, pois seu guard foi criado para o host interno \`mysql\` do Compose.

## 1. Pré-requisitos

- PHP 8.3+ e Composer 2 (o PHP usado pelo Composer deve ser o mesmo verificado no terminal).
- Extensões PHP \`pdo_mysql\`, \`mbstring\`, \`openssl\`, \`xml\` e as exigidas pelo Composer.
- MySQL 8+ rodando localmente, inclusive por Homebrew/MAMP/Herd, desde que a conexão PDO/TCP seja em \`127.0.0.1\` (ou \`localhost\`/\`::1\`) e porta configurável.
- \`composer.lock\` já versionado; \`composer install\` instala dependências sem atualizar versões.

Verificações:

\`\`\`bash
php -v
php -m | grep -Ei 'pdo_mysql|mbstring|openssl|xml'
composer --version
mysql --version
composer validate --strict
composer install
\`\`\`

Se o projeto ainda não tem \`.env\` **e é uma nova instalação local**, \`cp .env.example .env\`, \`php artisan key:generate\` e \`php artisan jwt:secret\`. Não regenerar chaves de ambientes existentes; não modificar um \`.env\` de produção para executar testes.

## 2. Criar um banco separado e um usuário exclusivo para testes

**ATENÇÃO:** vários testes utilizam \`RefreshDatabase\`. Eles podem reconstruir tabelas. Nunca apontar esses testes para o banco normal de desenvolvimento, produção, staging com dados a preservar ou bancos de outros produtos.

No cliente MySQL administrativo **local** (\`mysql -u root -p\` quando aplicável), com uma senha nova e forte **substituída localmente**:

\`\`\`sql
CREATE DATABASE fluxo_repasse_qa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'fluxo_repasse_qa'@'127.0.0.1' IDENTIFIED BY 'SUBSTITUIR_POR_SENHA_EXCLUSIVA_LOCAL';
GRANT ALL PRIVILEGES ON fluxo_repasse_qa.* TO 'fluxo_repasse_qa'@'127.0.0.1';
\`\`\`

O exemplo define um esquema exclusivamente QA; não executar \`DROP DATABASE\` nem \`migrate:fresh\`. Se o servidor já possuir um schema exclusivo de QA, use os respectivos dados, depois de verificar que ele não contém registros que precisam ser preservados. É responsabilidade do operador confirmar o schema.

Se o seu MySQL estiver em outra porta local (por exemplo, MAMP), definir \`QA_DB_PORT\` com o valor real; o script não supõe que sua instalação usa 3306.

## 3. Configurar variáveis de sessão sem gravar senhas no Git

No terminal **da API**, exportar os nomes e a porta do banco QA:

\`\`\`bash
export QA_DB_NAME=fluxo_repasse_qa
export QA_DB_USERNAME=fluxo_repasse_qa
export QA_DB_HOST=127.0.0.1
export QA_DB_PORT=3306
printf 'Senha exclusiva do MySQL QA: '
read -s QA_DB_PASSWORD
echo
export QA_DB_PASSWORD
\`\`\`

\`read -s\` evita exibir senha ao digitar; não incluir senha em comandos do histórico, arquivos do repositório ou mensagens. Não exportar a senha de produção. Os valores \`QA_DB_*\` serão transformados em variáveis \`DB_*\` **somente no processo do runner**, sem editar o \`.env\` existente. \`DB_URL\` é fixado como vazio.

## 4. Executar testes locais

\`\`\`bash
php qa/mysql-local-safety.test.php
bash qa/selftest.sh
bash ops/selftest.sh
bash ops/preflight-selftest.sh

bash qa/run-local.sh
\`\`\`

O runner:

1. Verifica PHP/Composer, lockfile, dependências instaladas e ausência de cache de configuração Laravel.
2. Executa 13 testes nativos das regras de segurança MySQL, sem conexão.
3. Verifica \`QA_DB_*\`, host local, porta, banco terminado em \`_qa\`, usuário dedicado (não root/admin), senha preenchida e \`DB_URL\` vazio.
4. Faz **SELECTs somente de leitura** para confirmar o schema no MySQL local, sem criá-lo.
5. Por padrão, bloqueia bancos QA que já contêm tabelas, pois \`RefreshDatabase\` pode recriá-las.
6. Executa PHPUnit **em MySQL**, via \`phpunit.local-mysql.xml\`, e Laravel Pint \`--test\`.

Após uma primeira execução, o banco QA poderá conter tabelas. **Somente depois de confirmar que esse banco é descartável e exclusivo de testes**, permitir reconstrução:

\`\`\`bash
export QA_ALLOW_TEST_SCHEMA_REBUILD=YES
bash qa/run-local.sh
\`\`\`

A variável de autorização não é necessária para um schema QA vazio. Não use essa autorização para banco com dados que devam ser preservados. **Não execute \`migrate:fresh\`**.

## 5. Testar o APP React sem Docker

No repositório irmão \`fluxo-repasse-app\`, com Node.js compatível:

\`\`\`bash
npm ci
npm run qa:lock
npm run qa:native
npm run lint
npm test
VITE_API_BASE_URL=http://127.0.0.1:8000 npm run build
\`\`\`

Para testes HTTP integrados, inicie Laravel **apenas com o banco QA**, sem carregar dados de produção, e inicie o Vite na outra janela. Um build de **produção** precisa usar HTTPS e passar por \`npm run qa:release\`; o endereço local HTTP não passa nesse gate, intencionalmente.

\`ops/backup-qa.sh\` e \`ops/restore-qa.sh\` seguem sendo scripts **Docker Compose**. Não tentar executá-los como comandos de backup MySQL nativo; essa implementação exigirá um fluxo separado e seguro.

## 6. Como diagnosticar os dois erros informados no Mac

- \`qa/selftest.sh\`: os testes agora executam em \`env -i\`, sem herdar \`QA_DB_PASSWORD\` ou qualquer \`QA_*\` da sessão, corrigindo o falso bloqueio.
- \`ops/selftest.sh\`: o teste agora mostra as primeiras linhas da falha do backup simulado. Os utilitários foram ajustados para não depender de \`cat --\`, \`gzip -t --\` e outros usos inconsistentes com ferramentas BSD do macOS.
- \`ops/preflight-selftest.sh\` já passou em **13/13** no resultado informado pelo usuário.

As mudanças precisam ser executadas no próprio Mac para verificar compatibilidade final. Não declarar QA integrado aprovado sem os logs do PHPUnit/MySQL, Composer e Vitest.
