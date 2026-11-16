# MACRO 17 — CENTRAL OPERACIONAL INTEGRADA (API + APP)

## Objetivo
Entregar um grande bloco de produto, com **seis capacidades operacionais conectadas** ao módulo Dossiês da macro 16. O módulo deixa de ser apenas um cadastro documental e passa a oferecer gestão real de trabalho, prazos, pendências, registro histórico e evidências, sem simular movimentação financeira.

## Capacidades entregues em código
1. **Painel operacional:** quantidade de dossiês em andamento/arquivados, tarefas pendentes/em execução/concluídas/vencidas, checklist conferido e documentos ativos; dossiês recentes e tarefas com prazo.
2. **Fila global de trabalho:** busca e filtros status/prioridade/responsável (todas/minhas)/vencidas, paginação, atualização de status e navegação para dossiês.
3. **Tarefas por dossiê:** criação, edição, prioridade, prazo, responsável com permissão cases.manage, status pendente/em execução/concluída/cancelada e auditoria.
4. **Checklist:** itens criáveis e conferíveis por operador, sem impor uma lista fixa de requisitos de negócio.
5. **Notas de acompanhamento:** registro imutável, autor, data e histórico, sem permitir alteração/exclusão retroativa.
6. **Documentos e relatórios:** upload privado PDF/JPEG/PNG/WebP (10 MB), SHA-256, download com JWT e no-store, arquivamento lógico sem excluir conteúdo; exportação CSV de dossiês/tarefas com neutralização de fórmula.

## Persistência
Migration aditiva `2026_11_16_000003_create_case_operations.php`:
- `repasse_case_tasks`
- `repasse_case_checklist`
- `repasse_case_notes`
- `repasse_case_evidence`

Tabelas usam FKs para dossiês e usuários, índices de consulta e eventos em `repasse_case_events`. Nenhuma migração destrutiva.

## Contratos
- `/api/v1/operations/dashboard`, `/operations/tasks`, `/operations/assignees` e `/operations/export?entity=cases|tasks`.
- `/api/v1/repasse-cases/{case}/tasks` GET/POST, `/tasks/{task}` PATCH.
- `/api/v1/repasse-cases/{case}/checklist` GET/POST, `/checklist/{item}` PATCH.
- `/api/v1/repasse-cases/{case}/notes` GET/POST.
- `/api/v1/repasse-cases/{case}/evidence` GET/POST, `/evidence/{evidence}/download` GET, `/evidence/{evidence}/archive` PATCH.

O documento `docs/openapi.yaml` acompanha a versão 1.0 da central.

## Segurança
- Todos os endpoints sob JWT, `session.current` e `cases.manage` (inclusive exportação e download).
- Arquivamento de dossiê permite leitura, mas bloqueia mutações.
- Atribuição de tarefas só permite contas ativas com `cases.manage`.
- Arquivos no disco privado `local`, sem URL pública; download como attachment octet-stream.
- MIME/limite de upload validados no Laravel; metadados jamais incluem storage_path ou token.
- Cabeçalhos HTTP no-store e proteção contra CSV formula injection.
- Eventos de auditoria têm tipo, data e operador; não armazenam conteúdo de notas ou arquivos nos eventos.

## Instalação de homologação (somente banco autorizado)
1. Backup do banco antes da migração. Não executar migrate:fresh.
2. `composer install`; `php artisan migrate`; se ainda necessário para admin técnico, `php artisan db:seed --class=AdminUserSeeder`.
3. Garantir permissão de escrita para o disco `local` do Laravel e proteção de seu diretório fora do webroot; sem symlink público para os documentos.
4. Entrar no APP com usuário `cases.manage`. Criar/abrir dossiê; cadastrar tarefa, checklist e nota; enviar PDF; baixar arquivo e exportar CSV.
5. QA focalizado: `php artisan test --filter=CaseOperationsTest`. No APP: `npm ci`, `npm run lint`, `npm test -- --run src/features/operations/OperationsDashboard.test.jsx src/features/cases/CaseWorkspace.test.jsx`, `npm run build`.

**Não há evidência de execução real de migrations, PHPUnit, Vitest ou build nesta sessão.** Esses são gates objetivos para revisão/merge e staging, não uma nova macro exclusiva de QA.

## Exclusões funcionais explícitas
- Não existem saldos, comissões, cálculos, chave Pix, liquidação, pagamento, conciliação, agendamento financeiro ou gateways.
- Não presume single-org vs multiempresa, taxas, alçadas ou responsabilidades financeiras: dependem de escopo aprovado.
- A permissão `cases.manage` é ampla na organização técnica atual; isolamento por tenant só deve ser definido e implementado após decisão de modelo de dados e RBAC por organização.

## Medição de avanço
**6 de 6 frentes operacionais propostas nesta macro têm código entregue nos PRs; homologação 0/1 pendente.** Não converter essa razão em percentual de conclusão do negócio financeiro.
O índice histórico 6/12 = 50% do roadmap antigo cobre marcos técnicos desiguais e tornou-se inadequado para medir a conclusão de um produto financeiro. Reconstruir a baseline somente após a especificação financeira.

## Próxima etapa importante
A próxima macro deve atacar o **motor de repasses** com regras aprovadas: origem/destino, evento gerador, fórmula de cálculo, aprovações, tratamento de estorno, liquidação, conciliação, gateways e auditoria monetária em centavos. Não distribuir isso em uma dezena de micros.
