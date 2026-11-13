# Operação segura — Fluxo Repasse (Macroentrega 10)

## Limite de escopo

Os scripts em `ops/` são **ferramentas manuais de homologação e proteção contra erro operacional**. Nenhum deles executa deployment de produção. O backup e a restauração funcionam exclusivamente com `compose.qa.yaml`, em banco de nome `*_qa`; a restauração requer um **novo** schema `*_restore_qa` e confirmação explícita. Não há dados, contas ou segredos fixos no código.

**Não apontar para bancos de clientes ou produção.** A configuração Compose deste projeto é de desenvolvimento/QA, não uma topologia de produção.

## 1. Preflight de configuração para produção — somente leitura

Com as variáveis exportadas pelo gerenciador de segredos, executar:

```bash
bash ops/preflight-selftest.sh
bash ops/preflight-production.sh
```

O pré-flight verifica `APP_ENV=production`, `APP_DEBUG=false`, URL HTTPS, `APP_KEY` no formato Laravel, segredo JWT com comprimento mínimo, `JWT_BLACKLIST_ENABLED=true`, `DB_CONNECTION=mysql`, banco que **não** termina em `_qa`, `CACHE_STORE=redis|database` compartilhado entre instâncias, CORS somente em origens HTTPS explícitas e presença do `composer.lock`.

Ele **não valida** a qualidade criptográfica dos segredos, a existência do MySQL/Redis, certificados TLS, política de backups, disponibilidade real ou rota de rollback. A checagem do cache é especialmente importante para a blacklist de JWT entre múltiplas instâncias. Na instalação real, confirmar que o driver configurado é efetivamente compartilhado e persistente.

**Execução desta entrega:** 13/13 testes de regressão do pré-flight aprovados com variáveis de teste e arquivo lock fictício em diretório temporário; o pré-flight não foi executado contra o ambiente de produção.

## 2. Backup manual exclusivo de QA

Pré-requisitos: Docker Compose v2, serviço `mysql` inicializado, `QA_APP_PATH` apontando para o clone do APP, `QA_DB_NAME` com sufixo `_qa`, e segredos `QA_DB_PASSWORD`, `QA_DB_ROOT_PASSWORD`, `QA_APP_KEY`, `QA_JWT_SECRET` fornecidos pelo ambiente.

Definir `QA_BACKUP_DIR` como diretório **absoluto, fora do repositório e fora de qualquer webroot**. O script recusa diretórios dentro da árvore do projeto, symlinks no diretório final e nomes de banco fora de QA.

```bash
QA_BACKUP_DIR=/caminho-privado/backups-qa bash ops/backup-qa.sh
```

O comando gera `*.sql.gz` com `mysqldump --single-transaction --quick`, permissões de diretório 0700/arquivos 0600 e arquivo `*.sha256`. Credenciais são consumidas dentro do contêiner MySQL, sem serem colocadas na linha de comando do host. O script não abre porta MySQL, não remove dados e não usa `migrate:fresh`.

**Atenção:** o arquivo de backup **não é criptografado**. Pode conter dados pessoais. Armazenar em disco protegido, criptografar antes de qualquer transporte ou retenção externa, restringir acesso e estabelecer prazo de exclusão conforme avaliação LGPD. SHA-256 detecta corrupção acidental, **não prova autenticidade** contra adulteração intencional de arquivo e checksum.

## 3. Verificação e ensaio de restauração QA

A verificação não acessa o banco:

```bash
bash ops/verify-backup.sh /caminho-privado/backups-qa/arquivo.sql.gz
```

Para o ensaio completo, escolher um banco **novo** que termine em `_restore_qa`, diferente do banco original, e fornecer o arquivo confiável gerado pelo fluxo anterior:

```bash
QA_RESTORE_DATABASE=repasse_drill_restore_qa \
QA_RESTORE_CONFIRM=CREATE_NEW_QA_SCHEMA \
bash ops/restore-qa.sh /caminho-privado/backups-qa/arquivo.sql.gz
```

O script checa integridade do arquivo, exige confirmação, recusa banco já existente, cria um novo schema e importa o dump via stream sem expor SQL no console. Verifica se existem tabelas restauradas. **Em caso de falha, o schema de ensaio permanece para diagnóstico; nenhum banco é removido automaticamente.**

O arquivo precisa ser confiável: um dump SQL malicioso pode executar operações indesejadas mesmo com um SHA-256 válido. Não utilizar arquivos recebidos de terceiros ou endereços remotos não verificados.

## 4. Testes executáveis sem Docker real

```bash
bash -n ops/backup-qa.sh
bash -n ops/restore-qa.sh
bash -n ops/verify-backup.sh
bash -n ops/selftest.sh
bash ops/selftest.sh
```

O `ops/selftest.sh` cobre integridade, arquivos adulterados, recusa de banco fora de QA e backup fora do repositório. Usa fixtures comprimidas e um **Docker simulado**, nunca conecta ao MySQL real. **13/13 aprovações nesta entrega.** Também passaram 13/13 testes de pré-flight de produção, igualmente com fixtures.

## 5. Correlação de incidentes (sem exposição de dados)

Todas as respostas `/api/*` recebem `X-Request-ID` (UUID gerado pelo servidor), inclusive respostas de erro. O cliente não escolhe o ID. Esse código facilita diagnosticar um incidente a partir do identificador fornecido pelo suporte.

Por padrão, `LOG_API_REQUEST_SUMMARY=false`. Se ativado após análise de privacidade e retenção, o log contém somente: `request_id`, método HTTP, **nome estático da rota**, status e duração. **Não registrar** URL completa, parâmetros, headers Authorization, senhas, e-mails, IP, CPF ou payloads. Configurar retenção e acesso aos logs segundo política aprovada; a telemetria opcional não é um sistema de monitoramento externo.

## 6. Gates antes de produção

- `composer validate --strict`, `composer install`, PHPUnit/MySQL, Pint e smoke com duas contas distintas em QA.
- Em staging, backup e **restauração real** com contagem e verificação de dados, sem expor credenciais ou dados pessoais.
- No APP, `npm ci`, lint/Vitest, build e `VITE_API_BASE_URL=https://api-real npm run qa:release`, além de auditoria do PWA instalado.
- Verificar TLS, CORS, limites de taxa, filas, disponibilidade da blacklist JWT compartilhada, proteção de storage e controles LGPD.
- Definir RPO e RTO por requisitos do cliente; não existem objetivos numéricos aprovados nesta fase.
- Revisar procedimento de rollback de código e migrações, **sem** `migrate:fresh` ou restauração automática.
- Aprovar separadamente mecanismo de backup **criptografado**, retenção, testes de recuperação e monitoramento de produção.

**Status:** scripts e testes locais entregues; deploy, backup/restauração MySQL reais, validação de segurança no runtime, armazenamento criptografado e aceite operacional ainda pendentes.
