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

## Macroentrega 04 — RBAC técnico e usuários (PR #4)

- [x] Tabelas e modelos de papéis, permissões e vínculos, sem dados financeiros.
- [x] Permissão técnica `users.manage` com middleware deny-by-default.
- [x] Comandos de concessão e revogação de administrador existente, com auditoria e proteção do último administrador.
- [x] API paginada de listagem e busca de usuários.
- [x] Cadastro e edição de usuários comuns sem escalar papéis.
- [x] Ativação/desativação com revogação JWT e proteções de administrador.
- [x] Trilha de auditoria com referência ao operador.
- [x] Novos cenários PHPUnit de autorização, usuários e CLI escritos.
- [x] Contratos OpenAPI 0.4 e documentação de instalação e segurança.
- [ ] `composer.lock` resolvido/commitado com Composer real.
- [ ] PHPUnit, Pint, migrations e testes em MySQL 8 executados.
- [ ] E2E com frontend, cenário de dois administradores e homologação.

**Indicador API da macro:** 9/12 checkpoints com artefatos implementados (75%); três gates de execução pendentes. O percentual total do produto ainda depende do escopo Gemini.

**Macro 05 proposta:** executar homologação reproduzível, implementar gestão de papéis/convites somente após especificação e ampliar observabilidade. Nenhuma operação financeira deve ser adicionada sem regras aprovadas.

## Macroentrega 05 — Histórico, integridade e smoke QA (PR #5)

- [x] Endpoint de histórico por usuário, autorizado com `users.manage`.
- [x] Filtros tipados, paginação e restrição dos campos expostos.
- [x] Rate limit em consultas administrativas de auditoria.
- [x] Troca de e-mail com revogação global de sessões.
- [x] Tratamento de no-op e evento de auditoria com identificação do operador.
- [x] Bloqueio de payload com campos privilegiados em criação/edição/status.
- [x] Testes PHPUnit de autorização, privacidade e alteração de contas escritos.
- [x] OpenAPI 0.5 e documentação operacional atualizada.
- [ ] `composer.lock` criado/versionado via Composer legítimo.
- [ ] Testes PHPUnit/Pint e migrations MySQL executados e evidenciados.
- [ ] Smoke integrado com a API real e testes móveis executados.

**Progresso da macro na API:** 8/11 checkpoints com código e documentação (72,7%); 3 gates de execução bloqueados. Isso não representa o progresso total do produto.

**Próxima macro:** primeiro sanar a ausência de dependências/QA, depois revisar desempenho e privacidade da trilha. O escopo financeiro segue não documentado e não deve ser inventado.

## Macroentrega 06 — Prontidão e testes executáveis (PR #6)

- [x] `GET /api/v1/ready` independente de health, consulta DB e tabelas essenciais.
- [x] Falha 503 sem vazamento de hostname, SQL ou segredo.
- [x] 4 novos testes feature Laravel escritos, incluindo cenários de falha.
- [x] Sintaxe dos 2 arquivos PHP novos verificada com PHP 8.4.24.
- [x] OpenAPI atualizado para 0.6.
- [x] Smoke Node do APP passou em 8/8 testes isolados, incluindo falha de readiness.
- [x] Documentação e medição global fixa registradas.
- [ ] Gerar/versionar composer.lock com Composer e dependências reais.
- [ ] PHPUnit/Pint/MySQL e migrations executados integralmente.
- [ ] QA ponta a ponta com PWA, MySQL e browsers reais.

**Métrica macro 06 (conjunta):** 12/16 checkpoints de API/APP entregues (75,00%), quatro gates sem homologação.

**Métrica global do roadmap provisório:** 5/12 = 41,67% de marcos já na main; **6/12 = 50,00%** incluindo a macro 06 em PR. Não é estimativa de esforço nem de conclusão funcional do repasse. Fonte canônica: `docs/PROGRESSO_GERAL.md`.

## Macroentrega 07 — Ambiente de QA, locks e execução segura (PR #7)

- [x] Dockerfile PHP 8.4 com Composer, PDO MySQL/SQLite, mbstring, XML e ZIP.
- [x] Compose com MySQL 8.4 privado, Laravel e Node APP.
- [x] Runner fail-closed exige locks legítimos, segredos externos e DB exclusivo `*_qa`.
- [x] O runner não usa `migrate:fresh`, não remove volumes e diferencia QA público/completo.
- [x] Seis testes locais de bloqueio shell executados e aprovados (6/6).
- [x] Sintaxe bash, esquema YAML, gitignore de segredos e documentação atualizados.
- [ ] Resolver/versionar `composer.lock` com acesso ao Packagist.
- [ ] Resolver/versionar `package-lock.json` com acesso ao registro npm.
- [ ] Executar Docker/Composer/PHPUnit/Pint/migrations MySQL em banco real.
- [ ] Executar npm ci, Vitest, lint, build e smoke E2E com evidência.

**Macro 07 conjunta API+APP: 10/14 checkpoints = 71,43%.** O marco global 07 **não está concluído**. Índice total de marcos fechados: **6/12 = 50,00%**, sem extrapolar para o produto financeiro. Fonte canônica: `docs/PROGRESSO_GERAL.md`.

**Próximo passo:** fechar lockfiles e executar QA isolado; não iniciar operações de repasse sem escopo aprovado.
