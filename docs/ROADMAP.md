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
- [x] `package-lock.json` já presente na main do APP; inspecionado em 525 entradas, com manifesto consistente e hashes de integridade.
- [ ] Executar Docker/Composer/PHPUnit/Pint/migrations MySQL em banco real.
- [ ] Executar npm ci, Vitest, lint, build e smoke E2E com evidência.

**Macro 07 conjunta API+APP: 11/14 checkpoints = 78,57%.** O marco global 07 **não está concluído**. Índice total de marcos fechados: **6/12 = 50,00%**, sem extrapolar para o produto financeiro. Fonte canônica: `docs/PROGRESSO_GERAL.md`.

**Próximo passo:** fechar lockfiles e executar QA isolado; não iniciar operações de repasse sem escopo aprovado.

## Macroentrega 08 — Isolamento MySQL, regresões e RBAC negativo (PR #8)

- [x] Corrigir expectativa de evento de auditoria após troca de e-mail.
- [x] `phpunit.mysql.xml` força MySQL e remove `:memory:`.
- [x] Bootstrap fail-closed anterior ao autoload de PHPUnit/Laravel.
- [x] Dez casos PHP de bloqueio de banco/host/driver/DB_URL executados.
- [x] Runner com `DB_URL=""` e 10 autotestes shell executados.
- [x] Script opcional para resolver `composer.lock` via Composer real, sem scripts/plugins.
- [x] Espera de readiness limitada e 8 testes Node aprovados.
- [x] Smoke de RBAC negativo com segundo usuário e 7 testes Node aprovados.
- [x] Modo `QA_LEVEL=full` exige dois usuários diferentes, e manuais atualizados.
- [x] `composer.lock` já versionado na main da API, 113 pacotes inspecionados; validação Composer real ainda pendente.
- [ ] Executar PHPUnit/Pint/migrations em MySQL de QA.
- [ ] Executar npm ci, Vitest, lint e build reais.
- [ ] Executar smoke autenticado e QA PWA em navegador real.

**Métrica da macro 08 conjunta:** 15/18 = **83,33%**, 35 testes de isolamento/contrato executados. **Índice global de macromarcos concluídos:** 6/12 = **50,00%**; não contabilizar marcos parciais 07/08. `docs/PROGRESSO_GERAL.md` é a fonte canônica. O percentual financeiro não pode ser aferido sem escopo aprovado.

## Macroentrega 09 — Segurança de cache HTTP e offline (PR #9)

- [x] Middleware global API com cabeçalhos anti-cache e defesa de respostas JSON.
- [x] Handler de exceções aplica os mesmos cabeçalhos a respostas negadas.
- [x] Cinco testes Laravel de headers escritos; cinco testes legados adequados à mudança de contrato.
- [x] Dois arquivos PHP novos passaram em validação `php -l`.
- [x] OpenAPI versão 0.7 e documentação de privacidade.
- [x] Workbox do APP sem runtime cache, fallback só em rotas públicas existentes.
- [x] Oito testes Node do PWA executados, 8/8 aprovados.
- [x] APP oculta área privada em perda de rede e implementa melhoria de acessibilidade.
- [ ] Executar PHPUnit em Laravel/MySQL para headers e regressões.
- [ ] Executar Vitest de tela offline/reativação de login.
- [ ] Executar build PWA/inspeção do service worker gerado.
- [ ] Validar em navegadores, dispositivos móveis e auditoria acessibilidade.

**Progresso macro 09 conjunto: 11/15 = 73,33%. Total do roadmap provisório: 6/12 = 50,00%.** Os marcos 07–09 permanecem parcialmente concluídos até QA real. Não confundir com percentual de operações de repasse financeiro.

## Macroentrega 10 — Operação segura e preparação de deploy (PR #10)

- [x] X-Request-ID exclusivo do servidor em respostas da API e tratamento de exceções.
- [x] Telemetria resumida opt-in com nomes de rota, sem URL/token/IP/payload.
- [x] Cinco testes feature de correlação escritos e PHP lint executado.
- [x] Backup QA transacional e checksum SHA-256; gravação fora do repositório.
- [x] Ensaio de restauração somente em novo schema de QA, com confirmação e proteção contra overwrite.
- [x] Verificador de checksum/gzip e regras de privacidade dos arquivos.
- [x] 13/13 testes operacionais Shell com Docker simulado executados.
- [x] Pré-flight produção somente-leitura: TLS, cache JWT, CORS, debug, chaves e banco.
- [x] 13/13 testes de pré-flight Shell executados.
- [x] ErrorBoundary e dois testes Vitest escritos no APP.
- [x] Gate de release PWA e 11/11 testes Node executados.
- [x] OpenAPI 0.8, manuais, pacote de testes e docs de deploy atualizados.
- [ ] Executar PHPUnit/MySQL e validar os novos cabeçalhos em ambiente real.
- [ ] Executar `npm ci`, Vitest, build e gate em artefatos PWA reais.
- [ ] Executar backup e restauração de MySQL QA em schema isolado.
- [ ] Realizar implantação de staging, TLS, monitoramento, rollback e E2E.
- [ ] Aprovar criptografia/retention, RPO/RTO e recuperação de desastres.

**Progresso conjunto Macro 10: 17/22 = 77,27%. Total do roadmap técnico provisório: 6/12 = 50,00% de marcos concluídos.** Não contabilizar o marco 10 sem evidência operacional real. Não confundir com percentual de produto financeiro.

## Entrega corretiva de QA local — falha Pint nos quatro arquivos MySQL

- [x] Isolar 4 arquivos informados pelo operador.
- [x] Aplicar formatação compatível com regras usuais Laravel Pint sem mudar semântica.
- [x] Validar `php -l` nos 4 arquivos corrigidos: 4/4.
- [x] Reexecutar os 13 casos PHP de isolamento MySQL: 13/13.
- [x] Preservar SQLite `:memory:` como padrão e MySQL local apenas opt-in `QA_MODE=mysql`.
- [x] Incluir os autotestes QA/ops/preflight no runner local: 10+13+13+13 = 49 gates.
- [x] Rodar Pint antes de PHPUnit para fail-fast.
- [x] Registrar 59 testes / 329 asserções, 36 autotestes Shell aprovados pelo operador e manual de repetição.
- [ ] Executar `vendor/bin/pint --test` real sobre os arquivos corrigidos no Mac.
- [ ] Executar `bash qa/run-local.sh` completo após a aprovação/merge.

**Progresso desta entrega de estabilização: 8/10 = 80,00%. Índice global técnico provisório permanece 6/12 = 50,00%**, pois os marcos integrais de QA/integração e os requisitos financeiros ainda estão abertos.

## Entrega técnica 11 — homologação local React/QA (não equivale ao marco financeiro 11)

- [x] Leitura dos logs Mac: 59 PHPUnit, 329 assertions, 49 gates e Pint 67 arquivos.
- [x] Remover `--configuration` duplicado do PHPUnit SQLite e preservar MySQL QA opcional.
- [x] APP com runner único `qa:local` em macOS sem Docker.
- [x] Geração de relatório local de fases sem credenciais.
- [x] Testes nativos novos: **21/21 executados** com mocks/fixtures.
- [x] Smoke HTTP local/CORS PATCH/readiness opt-in sem mutações.
- [x] Política HTTPS de release de produção mantida.
- [ ] Instalar/rodar npm ci, ESLint e Vitest reais no Mac.
- [ ] Gerar/inspecionar build PWA real.
- [ ] Verificar CORS/headers no Laravel/MySQL em execução e runner sem aviso.
- [ ] Realizar QA manual mobile/offline/teclado no navegador.

**Entrega técnica 11: 16/20 = 80%; roadmap canônico: 6/12 = 50%.** O marco 11 financeiro continua pendente de escopo validado.

## Macroentrega técnica 12 — proteção contra corrida de sessões (PR #14 API)

- [x] ID de correlação antes do roteamento em 404/405, mantendo escopo `api/*`.
- [x] Cabeçalhos de segurança em CORS OPTIONS, erros 401/422 e rotas desconhecidas.
- [x] Sete testes Laravel escritos, ainda não executados.
- [x] APP com invalidação de login/refresh/senha atrasados, logout imediato e timer seguro.
- [x] Sete testes Node nativos novos executados: 7/7 aprovados.
- [x] Três testes Vitest React e regressão de CORS/404 escritos.
- [ ] PHPUnit/Pint no Mac com os novos contratos.
- [ ] npm ci/Vitest/ESLint/build PWA real no Mac.
- [ ] Smoke CORS/404 em API Laravel/MySQL local.
- [ ] Navegador/mobile: offline, logout lento, sessão expirada e PWA instalado.

**Entrega técnica 12: 17/21 = 80,95%. Total roadmap canônico provisório: 6/12 = 50,00%**, sem afirmar conclusão financeira.
