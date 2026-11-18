# AGENTS — Fluxo Repasse API (Macroentrega 03)

## Fundamentos
Laravel 12, PHP >=8.3, MySQL 8+, API REST `/api/v1`. Frontend React/Vite/PWA no repositório `fluxo-repasse-app`.
O histórico funcional Gemini não foi recuperado: não inventar fluxos financeiros, comissões, repasses, multitenancy ou perfis.

## Requisitos inegociáveis
1. pt-BR/UTF-8, validação FormRequest, Swagger/OpenAPI atualizado por endpoint e teste feature por mudança.
2. Usuário autenticado não possui direitos financeiros por padrão; rotas de dinheiro inexistem até RBAC e escopo aprovados.
3. Token Bearer JWT exclusivamente no header. Config `JWT_SECRET` somente em ambiente. Verificar `auth_version` e `is_active` a cada requisição privada.
4. Em troca de senha e status de conta, incrementar `auth_version` em transação, revogando todos os JWTs existentes.
5. Auditoria mínima de identidade, sem senhas, JWT, dados financeiros ou IP bruto; retenção LGPD pendente de aprovação.
6. Jamais adicionar senha fixa/usuário exemplo em seeder de produção; contas reais por `repasse:user:create`.
7. Qualquer escrita futura de dinheiro exige transações, locks, idempotência, testes de concorrência, rastreabilidade e valores monetários sem `float`.
8. Não executar `migrate:fresh` em ambiente persistente; manter migrations aditivas, backups e estratégia de rollback.
9. Sem segredos em arquivos versionados; `composer.lock` obrigatório para homologar, nunca forjar versão ou hash.
10. Executar `composer test`, `composer lint`, testes MySQL 8 reais e revisar `docs/QA.md` antes do merge.
11. PRs não draft; entregar documentação e relatório de resultados reais. Não alegar execução de testes não executados.

## Macroentrega 04 — regras adicionais de acesso

- `users.manage` é uma permissão técnica inicial, sem acesso financeiro; jamais equiparar a superpoderes sobre repasses.
- Todo endpoint administrativo requer `bearer.only`, `auth:api`, `session.current` e `permission:users.manage`.
- Toda alteração deve estar documentada em `docs/openapi.yaml` e testada com usuário comum, admin e não autenticado.
- Criar contas sem papéis por padrão; concessões e revogações de privilégio exclusivamente via Artisan confirmado, preservando o último admin ativo.
- Proteger a última conta administradora ativa e impedir alteração de status própria por HTTP.
- Auditoria de modificações administrativas deve registrar `actor_user_id` e `user_id`, sem dados sigilosos.
- Não assumir multitenancy ou escopo financeiro; regras dependem da fonte funcional original.
- Exigir lockfiles, testes MySQL, PHPUnit, Pint e validação ponta a ponta antes de homologação.

## Macro 05 — integridade e auditoria administrativa

- Consultas `/admin/users/{user}/events` exigem `users.manage` e retornam apenas os campos públicos definidos em `docs/openapi.yaml`.
- Não expor `ip_hash`, endereços IP, tokens, senhas ou payloads em respostas de histórico.
- Qualquer alteração de e-mail incrementa `auth_version` em transação e invalida JWTs antigos.
- Rejeitar explicitamente tentativas de alterar campos privilegiados via endpoints de usuários comuns; verificar 422 em testes.
- Mudanças de auditoria exigem testes de 401, 403, 404, filtros, paginação e privacidade.
- Smoke Node é leitura da API com login/logout de QA (gera eventos de auditoria); não utiliza credenciais em código e não altera cadastros.
- `composer.lock` e testes reais são gates formais; não declarar homologação com testes apenas escritos.

## Macro 06 — Prontidão, evidência e progresso

- Health não é readiness: `/api/v1/ready` deve testar conexão DB e tabelas essenciais e responder 503 de forma genérica quando indisponível.
- Não misturar 8 testes nativos Node executados com 4 testes Laravel apenas escritos. Evidência precisa identificar ambiente e comando.
- Não incluir segredos, SQL, nomes de hosts ou stack trace em respostas de readiness.
- Atualizar `docs/PROGRESSO_GERAL.md` com 12 marcos fixos; porcentagens se referem ao índice de roadmap, não à conclusão funcional financeira.
- Antes de produção: Composer/npm lockfiles genuínos, QA MySQL/PHPUnit/Vitest/build e testes mobile.

## Macro 07 — política de QA e medição

- Não fabricar `composer.lock`/`package-lock.json`; instalá-los e versioná-los apenas após resolução real de dependências.
- Usar MySQL QA privado e banco obrigatoriamente `*_qa`; exigir segredos próprios, nunca de produção.
- `qa/run.sh` executa migrations normais no banco isolado, sem `migrate:fresh` e sem destruição de volumes.
- Diferenciar seis verificações shell executadas de testes Laravel/MySQL/Composer não executados.
- Percentual global do roadmap é `marcos concluídos / 12`; marco 07 segue incompleto até locks e QA executado.

## Macro 08 — não declarar QA verde sem infraestrutura

- `phpunit.xml` permanece SQLite local; QA MySQL exige `--configuration phpunit.mysql.xml`, que bloqueia ambiente inseguro antes do autoload.
- Testes locais **executados**: 10 PHP + 10 shell + 15 Node = 35 aprovados. Não afirmar PHPUnit/MySQL/Vitest/build aprovados.
- `composer.lock` já está versionado; validar com Composer verdadeiro, sem inventar hashes. O script opt-in em `qa/resolve-composer-lock.sh` é apenas para clones sem lock.
- QA full exige duas contas distintas, uma com users.manage e outra sem; verificar 403 sem mutações de cadastro.
- Percentual macro 08 = 15/18 (83,33%); total de marcos concluídos = 6/12 (50,00%). Não apresentar isto como progresso de funcionalidades financeiras.
- Documento de contexto: `docs/MACRO_08_QA_MYSQL_RBAC.md` e `docs/PROGRESSO_GERAL.md`.

## Macro 09 — privacidade offline e cabeçalhos HTTP

- Toda resposta `/api/*`, inclusive 401/403, deve receber cabeçalhos no-store/private e nosniff; validar com PHPUnit.
- O PWA só pode armazenar o shell público em precache; nenhuma resposta JSON com JWT ou dados pessoais entra em runtimeCaching.
- Ao ficar offline, a aplicação não deve exibir dados privados nem restaurar a sessão automaticamente; revogação remota não pode ser prometida sem conexão.
- Testes desta macro: 8 Node PWA **executados**; 5 PHPUnit **escritos, não executados**; php -l em dois arquivos aprovado.
- Métricas: macro 09 11/15 (73,33%); roadmap total 6/12 (50,00%) de marcos fechados; não equivale a conclusão funcional financeira.

## Macro 10 — deploy sem atalhos

- Use `X-Request-ID` gerado pelo servidor para suporte; não registrar payload, JWT, IP, query ou e-mail na telemetria opcional.
- Pré-flight `ops/preflight-production.sh` é somente-leitura; não confundir com deploy realizado.
- `ops/backup-qa.sh` e `ops/restore-qa.sh` são exclusivos do Compose QA e nunca substituem backup criptografado de produção.
- Antes de liberar produção, exigir Composer/PHPUnit/MySQL, npm/Vitest/build, smoke com credenciais QA, restore real em schema separado e governança de retenção.
- Evidências reais: 13 autotestes backup + 13 preflight + 11 release Node = 37 aprovados; testes PHP/React integrados apenas escritos.
- Percentual macro 10: 17/22 (77,27%). Progresso global provisório: 6/12 (50,00%) de marcos fechados, não de funcionalidades financeiras.

## Correção QA nativo no macOS

- Para ambiente do usuário PHP + MySQL local, **sem Docker e sem SQLite**, usar `qa/run-local.sh` e `phpunit.local-mysql.xml`. `qa/run.sh` permanece exclusivo do Compose.
- Bloquear bancos sem sufixo `_qa`, hosts não loopback, senha vazia, usuário root/admin e DB_URL. Validar conexão PDO e presença de tabelas antes do PHPUnit.
- Testes com RefreshDatabase podem recriar dados no schema de testes. Permitir schema populado apenas com `QA_ALLOW_TEST_SCHEMA_REBUILD=YES` após confirmação humana de descartabilidade.
- Nunca armazenar senhas de teste no Git nem sugerir `migrate:fresh` sobre bases existentes.
- `qa/selftest.sh` não deve herdar variáveis do shell. `ops/selftest.sh` deve mostrar a falha simulada quando ocorrer.
- Evidência: 13/13 testes PHP da política MySQL local executados em cópia do código; integração real no Mac está pendente.

## Padrão de QA do MacBook autorizado: SQLite em memória

- Em ambiente nativo macOS, o comando principal é `bash qa/run-local.sh` com `QA_MODE=sqlite` implícito, PHPUnit em SQLite :memory: e Pint. A aplicação continua configurada em MySQL.
- O `phpunit.xml` deve forçar `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` e `DB_URL` vazio; não depender de variáveis herdadas.
- O MySQL local é **opcional** para teste de compatibilidade e só pode ser selecionado com `QA_MODE=mysql` e banco QA dedicado `*_qa`; nunca com banco produtivo.
- Tests de integração SQLite não provam compatibilidade completa de migrations e SQL com MySQL. A aprovação de produção requer QA MySQL separado.
- Não declarar sucesso de autotestes Bash, Vitest ou MySQL sem execução real no Mac.

## Aceite QA local/Pint após logs do operador

- Fonte de verdade de execução: Composer OK, PHPUnit SQLite 59/59 (329 assertions), autotestes Shell QA 10/10 + operações 13/13 + preflight 13/13 aprovados no Mac.
- Pint original falhou em quatro arquivos `qa/mysql-local-*.php` e bootstrap; reformatados no PR corretivo. Não alegar `vendor/bin/pint --test` aprovado até o Mac executar.
- `qa/run-local.sh` executa 49 gates independentes + Pint **antes** de PHPUnit; reexecutar no Mac para confirmar o pipeline.
- SQLite em memória permanece padrão; MySQL opcional somente `QA_MODE=mysql` com schema separado `*_qa`. Nunca alterar banco da aplicação nem adicionar migrate:fresh.
- Referência: `docs/QA_MACOS_PINT_ESTABILIZACAO.md`. Índice técnico global: 6/12 = 50%.

## Entrega técnica 11 — QA React Mac sem Docker

- API SQLite PHPUnit no Mac: 59 testes/329 assertions, Pint 67 arquivos, 49 autotestes aprovados conforme log do operador.
- Corrigir aviso duplicado via `php artisan test` sem flag; no modo MySQL QA opcional, invocar PHPUnit direto com arquivo próprio.
- APP: `npm run qa:local` agrega preflight, testes nativos, ESLint, Vitest, build e inspeção estática de PWA; `QA_INTEGRATION=1` é opt-in para API local em loopback.
- Não declarar build/Vitest/QA real aprovados sem execução no Mac; 21/21 testes novos aprovados são com mocks/fixtures.
- O banco MySQL da aplicação é preservado; não usar migrate:fresh ou operações de repasse não especificadas.
- Progresso QA técnico 16/20 (80%); roadmap canônico 6/12 (50%) sem escopo financeiro aprovado.

## Macro técnica 12 — testes de corrida de sessão

- Não restabelecer sessão se resposta de login/refresh/senha pertencer a intenção invalidada por offline, 401, logout ou expiração.
- `X-Request-ID` deve existir em 404/405/401/422 e preflight OPTIONS; não confiar em IDs fornecidos pelo cliente.
- Middleware de correlação registrado globalmente age **somente** em `api/*`; não registrar request headers, query, JWT, senha ou IP.
- QA executado nesta entrega: 7/7 testes Node do gate de sessão e 2/2 sintaxes PHP. Os 7 testes PHPUnit e 3 Vitest novos permanecem sem execução no Mac.
- Contagem: entrega técnica 12 17/21 (80,95%) e roadmap canônico 6/12 (50,00%).


## Guardrails da Macro 18 — cenários hipotéticos (não executáveis)

- Rotas /api/v1/finance são exclusivamente de simulações e exigem BOTH cases.manage + scenarios.manage, JWT e sessão vigente. Permissionamento não equivale a autorização financeira.
- Cenários usam centavos inteiros, não float; atribuições manuais com origem/destinatários integrantes ativos do dossiê e validação de revisão concorrente.
- O estado balanced significa que a soma manual coincide com valor total previsto; **NÃO** é aprovação de pagamento, título a pagar, saldo ou lançamento contábil.
- NUNCA conectar cenário a banco, Pix, comissão automática, webhook, ledger ou status executável sem regras financeiras aprovadas e revisão de segurança/concorrência.
- Dossiê arquivado impede mutação; snapshots históricos não têm endpoints de alteração; integridade SHA não é trilha inviolável externa.
- Regras multi-organização continuam indefinidas; dados são controlados pelo RBAC técnico da plataforma sem garantia de isolamento multi-tenant. Não vender como multi-tenant pronto.
- Documentar resultados reais de QA e não confundir testes escritos com testes executados.


## Macro 19 — Modelos e lotes não executáveis

- Modelos de distribuição são por dossiê, com percentuais definidos PELO OPERADOR em basis points (10000 = 100%) e sem qualquer taxa padrão.
- Distribuir em centavos inteiros (até 10^12 por cenário), maior resto, desempate estável por party_id, proibir alocações silenciosas de zero centavo.
- Dossiê/modelo arquivado bloqueia escrita; validar todos os destinatários ativos e vinculados ao mesmo dossiê.
- Lotes de até 30 cenários têm uma transação/commit único, request_key UUID estável em retries, hash do payload e validação all-or-nothing.
- Rotas exigem JWT + session.current + cases.manage e scenarios.manage. Histórico de modelos e lotes é somente leitura.
- "Soma conferida" NÃO é autorização para pagar. Não adicionar ledger, obrigação, Pix, comissão automática ou saldos sem regras aprovadas.
- Sem isolamento multi-tenant assumido; não disponibilizar comercialmente a organizações separadas até autorização/isolamento aprovados.
- Distinguir artefatos/testes escritos de testes executados; não declarar homologação apenas com código.


## Macro 20 — segregação entre parecer documental e aprovação financeira

- JWT + session.current + cases.manage + scenarios.manage para consultar/criar revisões; scenario_reviews.manage adicional apenas para decisão/atribuição.
- Usuário que criou o cenário OU solicitou a análise não pode emitir parecer sobre ela, mesmo se possuir permissão de revisor. Usuários inativos não podem ser designados.
- Parecer sempre vinculado ao scenario_revision e current_digest do cenário. Se revisão/digest/status de cenário ou dossiê mudar, bloquear DECISÃO pendente (409) e preservar histórico; exibir is_stale.
- Solicitação tem request_key UUID idempotente (mesmo payload/mesmo usuário), conflito se outro payload. A mesma revisão não deve ser solicitada repetidamente após parecer/alterações.
- Revisões/decisões possuem controle otimista de revision. Comentários e eventos têm histórico por revisão, sequence e hashes encadeados. Hashes internos não equivalem a prova externa inviolável.
- Fonte de dados para parecer deve ser SEMPRE o snapshot histórico da scenario_revision solicitada, não o cenário atual.
- "reviewed" é SOMENTE conferência documental, nunca aprovação financeira, liberação de saldo ou ordem de pagamento. Não adicionar gateway/ledger/PSP sem aprovação do domínio financeiro real.
- Sem SLA default, aprovação automática, regras de comissão, valor mínimo de operação ou política de multiempresa presumidos.
- Nenhum seed cria credenciais fixas; migrações são aditivas e homologação/teste precisam de evidência.

- Após changes_requested, pode ser aberta nova rodada de revisão da MESMA versão se a correção foi somente documental (ex.: anexos no dossiê). Enquanto pending ou reviewed a mesma versão não pode receber segunda solicitação ativa, exceto após retirada.


## Macro 21 — pacotes documentais privados

- Pacotes são sempre snapshots documentais, nunca ordem de pagamento, aprovação monetária, comprovante de quitação ou liquidação.
- Rotas de leitura exigem cases.manage + scenarios.manage + JWT/session.current; a emissão exige packages.manage adicional.
- Selecionar até 40 evidências, 30 cenários e 30 pareceres do mesmo dossiê; não selecionar evidência arquivada.
- Incluir participantes, tarefas, checklist e notas em snapshot até 400 registros por coleção; recusar excesso em vez de truncar silenciosamente.
- Validar SHA-256 de arquivos privados antes da prévia, na emissão e antes/depois do ZIP; máximo 100 MiB, sem URL pública; se divergente, negar download e manter manifesto preservado para inspeção.
- Não emitir novos pacotes com dossiê arquivado; pacotes antigos continuam legíveis/verificáveis.
- Prévia deve produzir SHA estável; POST exige o MESMO manifesto SHA e request_key UUID estável no retry, com 409 em mudança de dados.
- Guardar manifesto serialized LONGTEXT, sem JSON column que pode reordenar chaves antes do hash. Para snapshots legados das macros 18/20, usar HistoricalHashCompatibility para verificar hash registrado com ordem de chaves original sem relaxar integridade.
- ZIP só com ZipArchive disponível, nomes internos sanitizados, arquivo temporário privado removido após envio e sem guardar cópias desnecessárias.
- NÃO expor esses pacotes a clientes/tenants externos antes de regras de acesso e LGPD aprovadas. Não inferir regras de repasse financeiro.


## Macro 22 — UI deve exibir módulos liberados, sem conceder grants indevidos

- Ao atualizar API e APP, módulos da aplicação devem estar na navegação principal com prioridade, não abaixo de uma landing de boas-vindas persistente.
- Fonte única de módulos autorizados é GET /auth/me e os grants JWT efetivamente aceitos no backend; o frontend não pode inferir administração pelo nome ou e-mail.
- Catalogar 7 permissões técnicas: users.manage, parties.manage, roles.manage, cases.manage, scenarios.manage, scenario_reviews.manage, packages.manage. Não confundir com aprovação financeira.
- Sempre sincronizar o papel platform_admin existente por seeder idempotente, invalidando tokens ao conceder novas permissões.
- Migration de backfill NUNCA deve promover contas comuns nem criar senhas/usuários; afeta somente membros do papel técnico já presente. Diagnóstico por CLI não altera dados, reparo exige --repair, papel preexistente e confirmação.
- Preserve /auth/me e o GET /auth/access como diagnóstico da própria sessão. Erro 401 após modificação de privilégios exige login novo.
- Entregar testes focais sobre permissões, mínima autoridade, navegação mobile e um usuário que só tem users.manage, sem falsos positivos de liberação.
- Não executar migrate:fresh em banco persistente ou atualizar arquivos do usuário sem autorização/backup.

## Macro 45 — propostas internas rigorosamente não executáveis
- A proposta é fotografia documental de uma apuração manual existente e atual. Não constitui obrigação exigível, saldo, dívida, comissão devida, taxa, imposto, autorização ou pagamento.
- Responsável/pagador é explicitamente selecionado entre partes ativas e vinculadas ao mesmo veículo; nunca usar defaults, inferência de texto livre, taxas automáticas ou parâmetros de banco.
- Snapshots append-only com SHA canônico, UUID idempotente por autor/escopo/intenção, transação/lock; rejeitar origem obsoleta, adulterada ou de outro veículo. Histórico legível depois do arquivamento ou mudança de minuta.
- JWT + session.current + cases.manage E scenarios.manage. Nenhum papel técnico concede autorização monetária.
- Continuar no layout Metronic oficial da main: cards mobile col-6, ações compactas, desktop preservado; sem CSS global ou novo shell.
- QA de homologação apenas com evidência real; não declarar PHP/React/Pint/MySQL aprovados sem executar.

## Macro 46 — consulta global sem afirmar validade financeira
- A Central Comercial pode agregar número/última proposta por minuta, mas não pode declarar fotografia "atual" sem comparação integral de SHA. Timestamp t.updated_at posterior a op.created_at só comprova minuta editada depois, não outros motivos de obsolescência.
- Filtros e CSV devem compartilhar a MESMA query SQL antes da paginação; agrupar propostas por term_id para não multiplicar contagens. Paginação de 20 minutas não limita indicadores globais.
- JWT + session.current + cases.manage E scenarios.manage. Nenhuma permissão financeira, obrigação, banco, taxa ou comissão surge da Central.
- Testes feature e OpenAPI necessários, executados somente com evidência real. O layout do app permanece mobile-first, sem novos menus.

## Macro 47 — venda e conclusão atomicamente
- A ficha React oferece salvar venda sem encerrar OU salvar venda e concluir em uma transação; também conclui sem venda. Nunca fechar automaticamente ao vender.
- GET closeout-review contém review_sha256 do resumo atual. POST de conclusão sempre compara SHA sob lockForUpdate da linha repasse_cases; mudança de custos e pendências conhecidos = 409 sem gravação parcial.
- Se existem tarefas/checklist/lançamentos previstos, exigir acknowledge_pending=true explicitamente; nunca baixá-los, alterar status realizado ou produzir comprovante bancário.
- Venda precisa preço > 0 em centavos e data declarada não anterior à compra; não contabilizar venda novamente como receita gerencial.
- Usar apenas cases.manage/JWT/session.current. Não habilitar pagamento, saldo, comissão, imposto ou repasse.
- O PATCH legado de status permanece por compatibilidade e não exige SHA: deixar essa limitação clara; a UI oficial deve usar os endpoints novos.
- Testes escritos não significam homologação; medir QA real depois do código do MVP.

## Macro 48 — declarações de repasses externos (NÃO movimentação)
- Só registrar transferências que o operador afirma terem acontecido fora do aplicativo, com venda datada, participante favorecido vindo da proposta SHA verificada, valor/data explícitos e comprovante privado.
- A proposta, o rateio e a diferença aritmética são referências documentais, NUNCA obrigação exigível, saldo devedor, pagamento autorizado ou conciliação bancária. bank_confirmed/payment_executed/obligation_created devem permanecer false.
- Permitir novo relatório após encerramento SEM alterar dados do veículo arquivado; travar linha pai ao gravar/estornar relatório; UUID idempotente incluindo SHA do comprovante, ator e fonte.
- Um único esquema aditivo repasse_external_transfer_reports, comprovantes no disco local privado, SHA e tamanho conferidos antes de cada download. Sem URL pública, segredos, números bancários, ZIP ou PSP.
- Não permitir excluir/editar declaração original: correção por estorno com razão e auditoria; somar somente registros não estornados, todos antes de paginar.
- JWT + session.current + cases.manage E scenarios.manage; desconhecidos rejeitados; CSV anti-fórmula no-store. Testes reais só declarados após execução, código do MVP sem homologação.

## Macro 49 — repasse externo direto sem minuta
- O caminho direto exige venda datada, pagador E favorecido ativos vinculados à ficha, arquivo privado, valor/data explícitos e declaração expressa de evento fora da plataforma.
- Reutiliza repasse_external_transfer_reports da Macro 48: term_id/proposal_id/proposal_sha256 NULL no modo direto; preservar FKs e hashes históricos no modo com proposta.
- Hash da intenção diferencia NULL de 0, congela os nomes dos participantes em SHA independente e valida valores, IDs, prova e nomes antes de leitura, estorno, download e CSV.
- A ficha pode estar arquivada; registrar relato não modifica compra, venda, custo, resultado nem o estado do veículo.
- A interface usa o MESMO workspace e precisa de cases.manage apenas para fluxo direto; cenários e propostas continuam exigindo scenarios.manage.
- Nenhum valor relatado é saldo devedor, obrigação, lucro contábil, conciliação ou pagamento certificado/executado. Campos adicionais como payment_enabled são proibidos.
- Testes escritos não significam executados; homologação somente após código MVP.

## Macro 51 — SaaS MULTIEMPRESA APROVADO (sobrepõe restrições de escopo anteriores)
- **Decisão expressamente aprovada em 18/11/2026:** um ÚNICO MySQL com \`tenant_id\` obrigatório em **todas as tabelas operacionais de negócio**, sem banco individual por empresa. \`tenants\` é o cadastro da organização; permissões são catálogo técnico global; cargos funcionais são instâncias isoladas por empresa, com tenant_id nas roles e únicos por (tenant_id, slug). Usuários pertencem a um tenant neste MVP, com e-mail globalmente único.
- **Nunca confiar em tenant_id de URL/body/header**. JWT emite \`tid\`; middleware valida usuário ativo, \`auth_version\`, tenant_id e empresa ativa antes do model binding. \`TenantDB::table\` carimba INSERT e restringe WHERE/JOIN/UPDATE/DELETE. Eloquent Party/User usa global scope e evento creating.
- **Papéis funcionais fixos:** \`tenant_admin\` (Administrador) e \`tenant_user\` (Usuário); não conceder \`platform_admin\` a tenant e nunca permitir que um tenant modifique permissão/papel global. Um usuário não recebe dados/permissões de outra empresa.
- **Seeder local/teste:** \`DatabaseSeeder\` chama \`SaasDemoSeeder\` somente em local; testing exige \`REPASSE_SEED_DEMO=true\` explícito. \`php artisan migrate:fresh --seed\` foi AUTORIZADO PARA DESENVOLVIMENTO, jamais executar em ambiente com dados reais. \`REPASSE_DEMO_PASSWORD\` opcional; se ausente, gera senha criptográfica e apresenta no terminal local. Nenhuma senha fixa versionada. Empresa real via \`repasse:tenant:create\`.
- Demo claramente fictícia: duas empresas, quatro usuários, cinco participantes por empresa, três veículos por empresa, despesas, receitas, tarefas, checklist, cenários, modelos e termos com hashes gerados pelos serviços. Nunca inventar transferência realizada, status banco ou recibo.
- Não declarar seguro para produção sem PHPUnit/SQLite, MySQL real, validação dos escopos em todos os endpoints, inspeção de storage privado/avatares e QA autenticado com pelo menos dois tenants.

- **Guardas adicionais obrigatórias:** `roles.tenant_id` para cargos customizados, `auth_security_events.tenant_id` para auditoria identificada, regras de existência de role_id scoped no FormRequest. Um admin não pode atribuir, editar nem consultar papel de outra empresa. `platform_admin` permanece global e não substitui a identificação por empresa na sessão.


## Macro 52 — repasses manuais controlados após venda
- Não inferir base distributiva: operador informa valor total explícito. Distribuição fixa/percentual fecha os centavos; comissão percentual somente declarada, taxa/imposto somente manual.
- Permissões novas: repasse.manage (cadastro, parcela e estorno) + repasse.approve (aprovação). Autor pode aprovar se tiver grant. Mantém cases.manage como pré-requisito.
- Valores são declarações de pagamento externo, não conciliação bancária. Comprovante opcional e privado com SHA; nenhum PIX, PSP ou duplicação de custos/receitas.
- Tabelas tenant-scoped, row locks no veículo e obrigação, UUID de criação/pagamento com digest da intenção, limite por beneficiário, eventos append-only e cancelamento após estornar parcelas.
- SaasDemoSeeder não inventa pagamento realizado; AdminUserSeeder e TenantRoles provisionam novos grants, com revogação por auth_version se alterados.
- API da Macro 52 antes do APP; testes e build devem ser reportados apenas se executados. Recalcular % do MVP SaaS, não herdar os 86% pré-revisão.


## Macro 53 — consolidação sem dupla contagem
- \`RepasseFinancialOverview\` é leitura por veículo de três grupos: obrigações aprovadas/parcelas ativas; relatos antigos diretos; relatos antigos com proposta. Nunca abater relatos de saldo aprovado nem somar fluxos como «pagamento bancário».
- A revisão SHA de fechamento inclui saldos pendentes, rascunhos e aviso de possível sobreposição. O arquivamento exige ciência, MAS não liquida ou apaga obrigações e não impede encerramento após ciência.
- Não alterar compra/venda com distribuição aprovada sem cancelamento/estorno explícitos; não desvincular participantes com distribuição não cancelada.
- TenantDB obrigatório em consultas de negócio e JOINs, permissões da Macro 52 preservadas, testes só declarados executados com evidência.
