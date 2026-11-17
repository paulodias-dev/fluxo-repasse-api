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

## Macro 13 — Cadastro de Pessoas e Empresas

**Entrega funcional, não infraestrutura de testes.** Implementados cadastro, edição, filtros e paginação, status sem exclusão, histórico/auditoria persistente, RBAC `parties.manage`, API e interface mobile-first. Migração aditiva e OpenAPI 0.9 na API. O APP só renderiza registros vindos do banco.

**Pendente de homologação no Mac:** migrations/seed em MySQL autorizado, testes essenciais Laravel/React e navegação manual. **Macro 13: 10/12 = 83,33%** de checkpoints de implementação/preparação.

**Prioridade a partir de agora:** módulos de negócio por macroentrega substancial. Backlog condicional: vínculos entre participantes, configuração de regras e eventos de repasse, lançamento/validação, aprovação e liquidação, conciliação, relatórios. **Não implementar regras de cálculo ou transferências sem escopo aprovado**.

O percentual global financeiro **não é aferível** sem o escopo original, e a antiga métrica 6/12 = 50% representa apenas marcos técnicos.


## Macroentrega 17 — CENTRAL OPERACIONAL (entrega grande e integrada)

**Seis frentes de negócio administrativo em um único par de PRs**: painel operacional, fila global de tarefas e prazos, tarefas atribuíveis dentro do dossiê, checklist editável, notas permanentes, documentos privados + exportação CSV.

- [x] Código API para as quatro tabelas, endpoints com `cases.manage`, registro de eventos e arquivo privado.
- [x] Painel React com indicadores reais e filtros de trabalho.
- [x] Tarefas, checklist, notas, documentos, paginação e navegação direta para o dossiê.
- [x] OpenAPI e documentação do bloco; testes focalizados escritos.
- [ ] Executar PHPUnit e lint/build/Vitest, migrar MySQL autorizado e realizar smoke único integrado.
- [ ] Homologação final de backups, restore e dispositivos reais.

**Regra de medição:** frentes de código da macro 17 = 6/6 entregues em branch; não usar 6/6 como percentual de conclusão financeira. Baseline de produto depende da definição de regras de repasse, ainda não recuperadas.

Próximo pacote substancial: motor financeiro + aprovações + trilha monetária + liquidação/conciliação, condicionado à aprovação das regras de origem/destino, cálculo, repasses, cancelamentos e gateways. Não voltar a entregar macros isoladas de QA.


## Macroentrega 18 — MOTOR DE CENÁRIOS DE REPASSE (macro funcional API + APP)

**7 frentes implementadas em um único pacote:** construtor de propostas, distribuição manual em centavos, conciliação aritmética de valores previstos (não bancária), idempotência/concorrência, versionamento imutável na aplicação, comparação/fork de alternativas, indicadores/CSV.
- [x] Migration e modelos de dados por cenário com origem, destinatários e revisões.
- [x] API autenticada com RBAC duplo, transações e bloqueio de mutações em dossiês arquivados.
- [x] React responsivo com editor dinâmico, validação e reconciliação exata em centavos.
- [x] Auditoria de revisões, comparação, ramificação e exportação segura.
- [x] OpenAPI v1.1, testes direcionados escritos, documentação de aceite.
- [ ] PHPUnit/MySQL, Pint, testes do APP, build e smoke de browser executados em homologação.
- [ ] Aprovação das regras do negócio financeiro real (indispensável antes de ledger/pagamentos).

**Progresso da macro de código: 7/7 frentes entregues.** Não constitui percentual global do produto nem prova homologação. O antigo índice técnico 6/12=50% não deve ser atualizado por extrapolação: os 12 marcos originais não cobrem a especificação financeira real.

**Próximo pacote substancial:** motor executável, obrigações, regras de comissão, aprovação, ledger e conciliação como um conjunto grande SOMENTE depois da aprovação rastreável das regras de negócio. Nunca tratar simulação balanced como autorização de pagar.


## Macroentrega 19 — Modelos de rateio configuráveis + cenários em lote

**Oito frentes integradas em um único pacote API + APP:**
- [x] Modelos vinculados ao dossiê com origem/destinatários reais e pesos definidos pelo operador.
- [x] Calculadora em centavos inteiros com método do maior resto, soma exata e desempate determinístico.
- [x] Edição, arquivamento/reabertura e fork de modelos com histórico SHA-256 e revisão otimista.
- [x] Prévia de uma simulação sem persistência.
- [x] Prévia completa de 1 a 30 cenários por lote, com erro se qualquer linha falhar.
- [x] Gravação transacional integral, locks, idempotência por UUID e prevenção de duplicidades.
- [x] Histórico paginado de lotes, vínculo com cenários, metadados originais e CSV privado anti-fórmula.
- [x] UI React integrada no módulo existente, mobile-first, OpenAPI e testes focais escritos.
- [ ] Executar PHPUnit/MySQL, Pint, Vitest/Node, lint, build e smoke integrado antes de homologar.

**Métrica local da macro:** 8/8 frentes codificadas; **não** representa 100% de homologação nem evolução total do produto financeiro. Percentual técnico antigo 6/12 permanece histórico e inadequado para inferir conclusão do negócio.

**Pendente essencial:** motor efetivamente pagável, regras de origem/beneficiário, fato gerador, comissão, retenções, alçadas e integrações financeiras. Estes requisitos originais ainda não foram recuperados/aprovados e não podem ser inventados. Nenhum modelo desta macro cria obrigação ou liquidação.


## Macroentrega 20 — revisão documental independente de propostas

**Nove capacidades integradas em um par de PRs API + APP, sem Draft:**
- [x] Solicitar revisão por cenário+revision, UUID idempotente, prazo e comentário.
- [x] Fila com busca, filtros, escopo por operador, prazos e versões obsoletas.
- [x] Oito indicadores de trabalho, sem alegação de saldo financeiro.
- [x] Responsáveis habilitados via RBAC triplo e segregação solicitante/criador/revisor.
- [x] Parecer reviewed / changes_requested com justificativa e controle otimista.
- [x] Detectar versão/digest desatualizados e impedir decidir cenário alterado/arquivado.
- [x] Comentários permanentes, atribuição auditada e retirada pelo solicitante.
- [x] Snapshot monetário histórico e cadeia de eventos SHA-256 com verificação interna.
- [x] Exportação CSV segura e módulo React integrado ao planejamento, com testes focais escritos.
- [ ] Executar PHPUnit/MySQL, Pint, testes Vitest, lint/build e smoke de revisão antes da homologação.

**Escopo de código da macro 20: 9/9 frentes implementadas.** Não é índice global de produto nem prova de homologação; a métrica técnica histórica 6/12=50% não mede o negócio. Esta revisão é **documental**, nunca uma aprovação ou liquidação de repasse.

**Próximo passo para motor financeiro real:** aprovação formal de requisitos de quem paga/recebe, critérios de comissão/rateio, fato gerador, alçadas, conta/PSP, conciliação, estorno e isolamento por organização.


## Macroentrega 21 — pacotes de dossiê e prestação de contas documental

**Nove frentes funcionais concluídas em código API + APP, sem Draft:**
- [x] Seleção independente, paginada e pesquisável de evidências, cenários e pareceres.
- [x] Fotografia de participantes, tarefas, prazos, checklist, notas e status do dossiê.
- [x] Inclusão verificada de cenários/revisões e pareceres históricos.
- [x] Prévia sem gravação, SHA-256 e bloqueio HTTP 409 se conteúdo mudar antes da emissão.
- [x] Registro idempotente de pacotes com manifesto exato em LONGTEXT, sem mutação posterior via API.
- [x] Indicadores de conteúdo e pendências do pacote, sem saldos fictícios.
- [x] Verificação de hash do manifesto e SHA-256 de arquivos privados.
- [x] Comparação de adições/remoções/modificações entre fotografias do mesmo dossiê.
- [x] ZIP privado, manifest.json, anexos com hash conferido, React responsivo, OpenAPI 1.4 e testes dirigidos.
- [x] Compatibilidade com snapshots legados MySQL JSON preservando verificação criptográfica dos hashes existentes.
- [ ] Executar Laravel/MySQL, PHPUnit/Pint, Vitest/build e smoke de ZIP real antes da homologação.

**Evolução aferível:** 9/9 frentes da macro 21 codificadas, validação em ambiente real pendente. O percentual global do projeto permanece indefinido (os antigos 6/12=50% se referem somente ao roadmap técnico anterior e não representam conclusão financeira).

A próxima macro deve priorizar publicação/documentação de fluxos para operadores autorizados e retenção/acessos se o escopo permitir. Fluxos bancários reais só após aprovação formal de regras de negócio e isolamento organizacional.


## Macroentrega 22 — Visibilidade real dos módulos e recuperação de administração

Incidente da tela /dashboard: conta autenticada com um grant users.manage e apenas o módulo Usuários; a tela de boas-vindas ocupava a primeira dobra. Confirmado em código: bootstrap antigo atribuía apenas users.manage e seeders novos não eram aplicados automaticamente.

- [x] Catálogo único com 7 grants técnicos e módulos reais, sem permissões financeiras executáveis.
- [x] Seeder idempotente atualiza platform_admin e invalida JWTs quando concede novos grants.
- [x] Migration de backfill apenas para membros existentes do platform_admin, sem promover usuários comuns.
- [x] Bootstrap administrativo corrigido, com confirmação explícita e auditoria.
- [x] CLI repasse:access:doctor, verificação e --repair limitado ao admin já existente.
- [x] Perfil e endpoint /auth/access com diagnóstico e grants reais, OpenAPI 1.5.
- [x] Workspace autenticado com menu lateral visível, mobile e dashboard separado das áreas de trabalho.
- [x] Módulos autênticos acessíveis por botões, RBAC deny-by-default e reconsulta de acessos.
- [x] Indicadores reais da API e testes de regressão PHP/React escritos.
- [ ] Validar migration/seeder MySQL, PHPUnit/Pint e Vitest/build/browser no localhost autorizado antes de homologar.

A macro prioriza **desbloquear o uso de funcionalidades já implementadas**, não criar mais um módulo invisível. A nova migration só funciona depois de atualizar a API e executar php artisan migrate com backup e novo login.

**Evolução local:** nove frentes concluídas em código; nenhuma porcentagem global de implementação financeira é inferida.


## Macroentrega 24 — Integração financeira operacional ao veículo 360 (PR pendente de publicação)

- [x] Busca de veículos aprimorada por título, placa, chassi, marca, modelo e procedência, sem alterar os contratos de integração anteriores.
- [x] Suíte de integração ponta a ponta da API escrita: veículo, participantes, cenário, modelo de distribuição, prévia, lote, parecer e pacote documental.
- [x] Segurança revisada no teste: a permissão `cases.manage` sozinha não autoriza cenários, revisões ou pacotes.
- [x] Rotas REST financeiras anteriores preservadas; não foram criadas transações bancárias nem novas permissões financeiras.
- [x] React conectado ao novo layout com editor de cenários, modelos e lotes, pareceres, pacotes por veículo, exportação e histórico.
- [ ] PHPUnit, Pint, migrations de homologação, build React, lint e testes end-to-end executados no ambiente real.
- [ ] Homologação dos fluxos em contas com papéis diferentes, incluindo dispositivos móveis e permissões parciais.

**Escopo:** cinco frentes funcionais: cenários, modelos, lotes, revisões e pacotes. A macro não representa pagamentos reais e não completa o produto financeiro sem regras de negócio aprovadas.

**Compatibilidade:** os conceitos técnicos `repasse_cases` e `scenarios` continuam no backend; no novo frontend, a entidade principal é apresentada como **Veículo**. Não há alteração destrutiva de migrations nesta macro.

**Métrica global:** percentual de conclusão do produto ainda não aferível com segurança; não reutilizar os 50% históricos como medição das operações de veículos e repasses.

## Macroentrega 26 — Carteira 360 de veículos no console operacional

Status do código: **8 frentes implementadas em branches API/React; homologação real pendente**.

- [x] Nova consulta paginada GET /operations/vehicles sem permissionamento novo: cases.manage e JWT vigentes.
- [x] Subqueries agregadas por veículo para tarefas, checklist, anexos e lançamentos; nunca multiplicar centavos por join cruzado.
- [x] Indicadores globais de ativos, arquivados, vencidos, tarefas abertas, ausência de documentos, identificação incompleta e gastos previstos.
- [x] Filtros de atenção/status, busca por placa/chassi/marca/modelo/vendedor/origem e ordenação estável.
- [x] Custos e resultado projetado com centavos inteiros, preservando null e resultados negativos.
- [x] Central Operacional React com carteira de veículos e fila de tarefas avançada, navegação direta à ficha 360.
- [x] Listagem de veículos modernizada, cadastro inicial completo para dados de compra e cartões de alerta na Visão Geral.
- [x] Seis testes feature PHP escritos, contrato OpenAPI 1.6 e documentação de aceite.
- [ ] Executar testes PHPUnit/MySQL, Pint, build, lint e smoke autenticado desktop/mobile nos ambientes reais.

**Sem novas migrations ou lógica bancária**. O indicador histórico de 6/12 marcos técnicos da fundação não mede conclusão funcional do negócio financeiro e não deve ser elevado artificialmente. Consultar docs/MACRO_26_CENTRAL_FROTA_360.md.

## Macroentrega 27 — ETAPA COMPLETA: FINANCEIRO GERENCIAL DO VEÍCULO 360

Uma etapa de negócio de ponta a ponta em API+React Metronic, sem voltar à fragmentação:
- [x] Categorias configuráveis com ativação/desativação e snapshot do nome histórico.
- [x] Lançamentos gerenciais em centavos, filtros e paginação, preservando contratos legados.
- [x] Retificação transacional, justificativa, trilha original/substituta e estorno auditável.
- [x] Comprovante privado associado atomicamente ao lançamento, vínculo de anexo existente, download SHA-256 e proteção contra arquivo arquivado / outro veículo.
- [x] Linha de auditoria por lançamento (autor, data e transição), sem fabricar histórico anterior.
- [x] Demonstrativos por categoria e competência, filtros e CSV seguro anti-fórmula.
- [x] Interface Financeiro 360 integral na aba já existente da ficha do veículo, sem duplicar layout.
- [x] Migration aditiva, controllers e serviço centralizado, 15 rotas documentadas no OpenAPI 1.7 e 9 novos testes feature.
- [ ] Executar migrações MySQL + PHPUnit/Pint + build/lint React + smoke real mobile/desktop; **não homologado ainda**.

Documentos: docs/MACRO_27_FINANCEIRO_VEICULO_360.md e docs/ETAPAS_FUNCIONAIS.md.

**Progresso local:** 8/8 frentes codificadas. Sem % global artificial. A execução de repasses monetários reais segue bloqueada até aprovação das regras financeiras e isolamentos necessários.

## Macroentrega 28 — ETAPA 4 INTEGRADA: Prestação de contas documental 360

Marco de negócio entregue como UM bloco integrado em API e React (não micro-PRs):
- [x] Carteira documental por veículo: nova consulta GET /repasse-cases/{case}/documentary/overview e relatório CSV filtrado, sob cases.manage + scenarios.manage.
- [x] Cenários, revisões e pacotes em uma etapa única, com contagens globais e busca/paginação de 20 itens.
- [x] Revisão atual/obsoleta determinada por versão+SHA; sinalização de arquivado, diferenças em centavos e documentos/pendências.
- [x] Solicitar parecer com prazo e revisor opcional, atribuir, comentar e decidir com segregação existente entre criador/solicitante/revisor.
- [x] Cenário conferido gera atalho para pacote com cenário+parecer pré-selecionados; prévia SHA-256, seleção de anexos e confirmação explícita continuam obrigatórias.
- [x] Hash/ZIP, comparação e validação de integridade reaproveitados do módulo oficial, sem armazenamento paralelo.
- [x] Ficha do Veículo 360 com uma aba Prestação de contas 360, preservando o layout adquirido e as telas detalhadas existentes.
- [x] CSV anti-fórmula e sem cache; OpenAPI 1.8; 9 testes feature direcionados escritos, documentação e roteiro de aceite.
- [ ] Executar PHPUnit/MySQL, Pint, build/lint React e smoke de duas contas desktop/mobile — etapa ainda NÃO homologada.

Não cria migration, gateway ou permissão financeira; packages.manage continua obrigatória para emitir pacotes. Nem revisão 'reviewed' nem pacote verificado liberam transferências. A etapa 5 depende de regras financeiras aprovadas; a etapa 6 pode prosseguir com QA operacional das quatro primeiras.

**Métrica:** etapas 1–4 têm código desenvolvido; etapas 1–4 ainda requerem evidência de homologação. Etapas 5 e 6 não estão concluídas. NÃO extrapolar porcentagem total do produto a partir de quantidade de etapas.

## Macroentrega 29 — Etapa 6: Consolidação operacional e preparação de homologação

Não consiste em uma nova sequência de microtestes. Inclui correções visíveis ao usuário e o executor seguro para ACEITAR o produto existente:
- [x] Dashboard React original Metronic sem widgets fictícios, com 11 módulos reais, cards de acesso/permissão e KPIs autorizados pela API.
- [x] Ficha de veículo carrega dados principais mesmo se serviços de documentos, tarefas, checklist, notas, histórico ou indicadores estiverem indisponíveis; falha explicitada e botão para recarregar.
- [x] Diagnóstico CLI read-only repasse:acceptance [--json] para migrations 008–010, 26+ tabelas, colunas-chave, catálogo de 7 grants, papel platform_admin, conta admin ativa e blacklist JWT.
- [x] Runner qa/run-stage6.sh com níveis static/local/full: usa o runner protegido já existente para Laravel/Pint, Node nativo para smoke HTTP, npm ci/lint/build e duas contas QA em localhost.
- [x] Prova de 401/403 sem contas, RBAC do administrador e usuário comum, e consultas de veículo/financeiro/revisão/pacote com dados de QA sem mutações de negócio.
- [x] Guarda contra host remoto, modo full sem banco QA confirmado, duas contas não distintas e veículo de homologação inexistente.
- [x] Testes PHP da verificação estrutural e suite Node nativa do smoke HTTP codificados, com documentação completa.
- [ ] Executar Composer/PHPUnit/Pint, npm ci/lint/build, MySQL QA e smoke HTTP em máquina com checkouts dos dois projetos.
- [ ] Validação final no navegador real desktop/mobile, backup/restore, autorização de deploy e aceite humano.

**Limite:** etapa 6 ainda NÃO homologada. O motor de pagamentos reais da etapa 5 segue bloqueado até regras financeiras formais. Não declarar percentual total do produto apenas pela implementação de scripts.

## Macroentrega 30 — Etapa 5: Módulo de Condições Comerciais de Repasse (não executável)

Nesta etapa foi concluído, em um bloco integrado API+React, **um módulo funcional de formalização de regras comerciais**, sem inventar regras jurídicas ou bancárias:
- [x] Minutas por veículo com título, observações, valor manual de referência e modelo de distribuição existente vinculado por ID/revisão.
- [x] Snapshot SHA-256 íntegro do modelo com origem/participantes/pesos explícitos, validando vínculos e partes ativas.
- [x] Dez questões comerciais declarativas livres, iniciadas em branco; matriz automática de preenchimento sem equivaler à aprovação.
- [x] Associação de evidências privadas do veículo (até 25), com SHA-256 e barreiras cross-case.
- [x] CRUD de minutas com revisão otimista, arquivamento, reabertura, cópia de versão histórica com proveniência.
- [x] Histórico SHA de todas as versões, eventos na linha do tempo do veículo e detecção de modelo/participante/documento desatualizado.
- [x] Simulação em centavos por modelo congelado e valor informado pelo operador, **sem gerar obrigação nem pagamento**.
- [x] Busca, filtros, paginação e CSV anti-fórmula; dez rotas sob JWT, cases.manage e scenarios.manage, OpenAPI 1.9.
- [x] Nova aba Condições Comerciais no Veículo 360 + módulo visível no dashboard Metronic adquirido.
- [x] Migration 2026_11_17_000001, diagnóstico de instalação atualizado, 8 testes feature escritos e documentação de implantação.
- [ ] Validar migration, PHPUnit, lint/build React e navegação em ambiente autorizado; a pedido do usuário não foi executada uma rodada de testes nesta sessão.

**Próximo bloqueio funcional:** motor de obrigações/liquidação/repasses reais exige validar evento gerador, titularidade/pagador, base, taxas/comissões/tributos, condições e alçadas, integração e isolamento multi-organização. Esta macro **não** afirma que há saldo, Pix, repasse bancário ou comissão calculada automaticamente.
