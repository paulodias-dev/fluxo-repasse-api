# Macroentrega 58 — Checklists guiados e Agenda do Veículo

## Escopo aprovado

Implementação das quatro telas propostas, seguindo o Metronic oficial do Fluxo Repasse e mantendo o PWA mobile-first:

1. Checklists por veículo: foto, progresso, quatro grupos, itens, filtros Hoje/Pendentes/Concluídos e alternância de checklist.
2. Novo checklist: cadastro guiado em três etapas, grupos Documentação, Preparação, Venda e Pós-venda, itens editáveis, responsáveis, prazos, rascunho e revisão.
3. Agenda: visão Mês/Semana/Lista, calendário navegável, marcadores de eventos e compromissos do dia.
4. Novo lembrete: título, veículo, data, hora inicial/final, responsável, tipo, observações, opção de aviso 30 minutos antes, foto e arquivo privado.

## Modelo de dados enxuto

- `repasse_case_checklist_sets`: nome/status do checklist por veículo e tenant.
- Colunas opcionais em `repasse_case_checklist`: identificador do checklist, grupo, prazo e responsável. Itens anteriores permanecem compatíveis como checklist legado.
- Colunas opcionais em `repasse_case_tasks`: tipo (lembrete/tarefa/agendamento), hora inicial/final e antecedência de aviso. A agenda reutiliza as tarefas já existentes; não cria um cadastro paralelo.
- `repasse_case_task_documents`: vínculo entre compromisso e evidência privada existente do mesmo veículo.

## Segurança e integridade

- Todas as novas rotas usam Bearer JWT, session.current, cases.manage, escopo `TenantDB` baseado no tenant verificado da sessão.
- Criação de checklist com seus itens é atômica; no máximo 80 itens por checklist.
- Rascunhos podem ser revisados. Estruturas ativas não podem ser substituídas em massa; itens são atualizados individualmente.
- Usuário responsável deve pertencer ao mesmo tenant, estar ativo e possuir cases.manage.
- Veículo arquivado impede alterações, preservando leitura e histórico.
- Documento só pode ser vinculado a compromisso pertencente ao mesmo tenant/veículo, e arquivo privado segue download autorizado e proteção existente de integridade.
- Sem sincronização externa nem promessa de push em segundo plano. Aviso de navegador, quando implementado no PWA aberto e com permissão concedida, é distinto de uma notificação push confiável fora do aplicativo.

## Rotas

`GET,POST /api/v1/repasse-cases/{case}/checklists`  
`GET,PUT /api/v1/repasse-cases/{case}/checklists/{set}`  
`PATCH /api/v1/repasse-cases/{case}/checklists/{set}/items/{item}`  
`GET,POST /api/v1/operations/calendar`  
`GET,PATCH /api/v1/operations/calendar/{task}`  
`GET,POST /api/v1/operations/calendar/{task}/attachments`

Consultar `docs/openapi.yaml` para contratos.

## Integração e QA

Aplicar as migrations aditivas antes de instalar o React da mesma macro. Verificar:

- Jornada completa de criação ativa e rascunho, retomada do rascunho, mudança de grupo/prazo/responsável, conclusão e cálculo de progresso.
- Calendário em mês, semana, lista, seleção de data e cadastro/edit de compromisso.
- Anexar arquivo e foto em agendamento, conferir vínculo e download autenticado.
- 401 sem JWT, 403 sem permissão, 404 ao acessar veículo/arquivo de outra empresa, 422 em datas/tipos inválidos e escrita em veículo arquivado.
- Layout em iPhone Safari/PWA, Android Chrome e desktop; tema claro/escuro, menu +, sidebar, barra inferior.
- QA PHP: `php artisan test --filter=ChecklistAgendaWorkspaceTest` e Pint; QA React: `npm run build`, `npm run lint` e QA local.

**Estado:** código e testes versionados; nenhuma execução ou homologação local é afirmada sem evidência de comando.
