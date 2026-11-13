# Macroentrega 10 — Operação, backup, recuperação e observabilidade

## Objetivo

Introduzir controles de operação que possam ser revisados e testados sem acessar dados de produção. Esta macro **não efetua deploy nem restaura bancos reais**. O domínio de repasses financeiros permanece fora de escopo enquanto as regras funcionais não estiverem aprovadas.

## API Laravel

- `RequestCorrelationId`: UUID novo, gerado pelo servidor em cada requisição; não confia em `X-Request-ID` recebido do cliente. Expõe o mesmo identificador em `X-Request-ID` da resposta, incluindo falhas tratadas pelo exception handler.
- `config/operations.php`: telemetria de resumo **desligada por padrão**. Com `LOG_API_REQUEST_SUMMARY=true`, registra apenas ID, método, nome fixo da rota, status e duração. Não registra URL, query, IP, cabeçalhos, credenciais ou payload. Não é monitoramento externo.
- `RequestCorrelationIdTest.php`: cinco casos feature escritos para IDs, isolamento contra ID do cliente, 401 e logs opcionais. **Não executados** sem Composer/Laravel/MySQL reais.
- `docs/openapi.yaml`: versão 0.8.0 e contrato `X-Request-ID`.

## Segurança e recuperação

- `ops/preflight-production.sh`: gate somente de leitura contra debug de produção, HTTP, CORS aberto, cache local na blacklist JWT, banco de QA e chaves fracas.
- `ops/preflight-selftest.sh`: **13/13** testes de bloqueio executados usando configurações de teste.
- `ops/backup-qa.sh`: backup transacional do MySQL do Compose QA, arquivo gzip 0600, pasta 0700 e checksum SHA-256; recusa qualquer diretório dentro do repositório.
- `ops/verify-backup.sh`: valida checksum e gzip sem exibir dump.
- `ops/restore-qa.sh`: recuperação apenas para **novo** schema `*_restore_qa`, com confirmação explícita, rejeição de banco existente e validação de tabela restaurada; nunca apaga o schema.
- `ops/selftest.sh`: **13/13** testes executados com artefatos de teste e Docker simulado, sem MySQL verdadeiro.
- `qa/run.sh`: executa os 26 autotestes de isolamento/produção antes do Compose QA.

**Atenção:** os backups QA contêm dados não criptografados. Permanecem locais e não devem ser versionados/publicados. SHA-256 verifica corrupção acidental, não a autenticidade de dumps recebidos de terceiros. Para produção, políticas de criptografia, retenção, armazenamento externo seguro e ensaio de recuperação dependem de aprovação separada. Ver `ops/README.md`.

## Verificações realmente executadas

| Categoria | Evidência | Resultado |
|---|---|---|
| Backup/checksum/recusa de operações inseguras | `bash ops/selftest.sh` | **13/13** aprovados, com Docker simulado |
| Gate de configuração de produção | `bash ops/preflight-selftest.sh` | **13/13** aprovados, com variáveis de teste |
| Inspeção do bundle PWA (APP) | `node --test scripts/verify-release.test.mjs` | **11/11** aprovados, com artefatos temporários simulados |
| Sintaxe PHP | `php -l` em middleware, configuração e feature test novos | **3/3** sem erros |
| Sintaxe Bash e Node | `bash -n`, `node --check` nos arquivos novos | Sem erros |
| Correspondência de fonte | Tamanho e hash FNV-1a das cópias executadas vs GitHub | Conferidos |

**Total de testes nativos novos executados: 37/37 aprovados** (26 Shell e 11 Node). Os testes PHP de integração e os dois Vitest do frontend foram **escritos, mas não executados**.

## Evidência que ainda falta para homologação

1. `composer validate --strict`, `composer install`, PHPUnit/MySQL/Pint e testes de endpoints reais com `X-Request-ID`.
2. Backup e restauração completos com dados de QA, comparação de contagem de registros e documentação do ensaio, sem acesso a produção.
3. `npm ci`, Vitest/ESLint, build PWA real, `npm run qa:release` com origem HTTPS válida, validação de `sw.js` gerado, navegadores e acessibilidade.
4. Deploy de staging, TLS, rollback, blacklist JWT compartilhada, monitoramento/alertas e restauração sob critérios de aceite.
5. Plano aprovado de criptografia e retenção de backups, responsáveis, RPO/RTO, privacidade e recuperação de desastres.

**Não afirmar que o sistema está pronto para produção ou que os testes integrados passaram.**

## Percentuais

- **Macroentrega 10: 17 de 22 checkpoints = 77,27%.** Cinco gates de homologação/operacionalização permanecem pendentes.
- **Evolução total do roadmap técnico provisório: 6 de 12 macromarcos fechados = 50,00%.** Os PRs #7–#9 já foram incorporados, mas os critérios de homologação dos marcos 07–10 continuam abertos. O indicador não corresponde à implementação funcional de repasses financeiros.

## Correção adicional de segurança: seeder administrativo

Durante a Macro 10, a main recebeu um `AdminUserSeeder` com credenciais administrativas fixas no código. O PR #10 remove a criação automática dessa conta e preserva somente o preparo idempotente de papel `platform_admin` e permissão `users.manage`. Os comandos interativos existentes `php artisan repasse:user:create` e `php artisan repasse:access:bootstrap email-do-usuario` continuam sendo o fluxo autorizado, sem senha versionada.

**Se o seeder antigo já foi executado:** tratar a credencial como exposta, redefinir a senha, revogar todas as sessões JWT anteriores pelo fluxo autorizado e investigar eventos de auditoria. A correção não apaga a credencial do histórico Git e não altera bancos em execução. Dois testes Laravel de regressão do seeder foram escritos, mas não executados aqui.
