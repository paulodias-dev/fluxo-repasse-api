# Fluxo Repasse API — roadmap de execução

## Histórico
- [x] Macro 01: Laravel/MySQL, estrutura REST e health — merge na main.
- [x] Macro 02: identidade JWT, refresh/logout, provisionamento inicial, contrato e testes escritos — merge na main.

## Macro 03: segurança do ciclo de identidade (PR #3)
- [x] Campo `is_active` e versão revogável de sessão `auth_version`.
- [x] Middlewares contra conta desativada e JWT antigo, inclusive após refresh.
- [x] Nova rota autenticada para alteração da própria senha.
- [x] Comando seguro para ativação/desativação de conta.
- [x] Eventos de segurança persistidos com minimização de dados.
- [x] Testes feature para credenciais, revogação global e auditoria escritos.
- [x] OpenAPI, documentação operacional, LGPD e QA atualizados.
- [ ] Composer lockfile gerado e versionado.
- [ ] Testes PHPUnit/Laravel Pint e migrations MySQL 8 executados e evidenciados.
- [ ] Testes integrados com PWA e homologação de segurança.

**Métrica da macro 03:** 7/10 checkpoints com artefatos implementados (70%), pendentes 3 gates executáveis. Este indicador não representa o progresso total do produto.

## Próxima macroentrega (dependente de escopo)
- [ ] Autorização por papéis e organizações com política deny-by-default, sem presumir multitenancy.
- [ ] Rotina de recuperação de credenciais e reforço de sessão conforme política aprovada.
- [ ] Observabilidade, retenção LGPD de eventos, testes de concorrência e operação.
- [ ] Preparação do modelo de repasse somente após recuperação e aprovação do contexto Gemini.

Regras de cálculo, repasses, comissões e obrigações fiscais seguem NÃO ESPECIFICADAS.
