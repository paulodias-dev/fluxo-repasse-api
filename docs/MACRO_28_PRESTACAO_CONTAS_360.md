# Macroentrega 28 — ETAPA 4: Prestação de contas 360 por veículo

## Entrega funcional integrada
Esta etapa une o processo completo de cenários hipotéticos, revisões documentais independentes e pacotes SHA-256/ZIP. Mantém o React comprado e reaproveita serviços já implementados, sem criar tabelas duplicadas ou novas regras monetárias.

1. Consulta GET /api/v1/repasse-cases/{case}/documentary/overview: JWT, session.current, cases.manage + scenarios.manage. Somente leitura, sem pagamentos.
2. Resumo real do veículo: cenários ativos/equilibrados, revisões pendentes/conferidas/obsoletas/retiradas/arquivadas, participantes ativos, documentos, pendências, checklist e pacotes emitidos.
3. Cenários com filtro por revisão e pesquisa, paginação 20 e revisão mais recente por cenário. Divergência entre revisão/hash atual e parecer significa stale. O status reviewed é apenas documental.
4. Solicitação de revisão com motivo, prazo, revisor opcional e UUID de idempotência; atribuição/comentário/decisão feita pelo fluxo já existente com segregação real entre solicitante e revisor.
5. Cenário conferido na revisão atual pode iniciar o pacote com cenário + parecer pré-selecionados. Anexos ainda são selecionados manualmente e a prévia SHA-256 + confirmação continuam obrigatórias.
6. Reuso do módulo de pacote: comparação, versões, integridade dos anexos privados, download ZIP, registros de autoria e hash. Nenhum pagamento, comissão ou liquidação.
7. Frontend: uma aba Prestação de contas 360 na ficha já existente, em lugar de duas abas separadas; links para os editores globais de simulações, modelos, lotes e pareceres.
8. Testes feature novos (7): RBAC 401/403, cenário vazio, conferência por operador independente, revisão obsoleta, arquivamento, pacote real com escopo por veículo, filtros e paginação.
9. OpenAPI 1.8 e documentação de etapas atualizadas.

## Segurança e limites
Não introduz tabelas, migrations nem armazenamento novo. A consulta agrupa cenários, revisões e alocações por ID antes dos cálculos para evitar multiplicação de centavos. A interface nunca substitui a autorização do backend. Permissão packages.manage é obrigatória para gravar pacote. Os campos financial_authorization e payment_enabled são false.

A implantação atual é single-org; não alegar multi-tenancy. A etapa 5 (repasses monetários reais) continua bloqueada até regras de origem/destino dos valores, alçadas, taxas, impostos, cancelamentos, reconciliação e isolamento organizacional serem aprovadas.

## QA para aceite
1. Testar PHP: php artisan test --filter=VehicleAccountabilityWorkflowTest.
2. Regressões: FinancialVehicleWorkspaceIntegrationTest, ScenarioReviewWorkflowTest, CasePackagesTest, VehicleFinanceWorkspaceTest.
3. Executar vendor/bin/pint --test e php artisan route:list --path=documentary.
4. React: npm ci, npm run build e npm run lint; verificar desktop/mobile.
5. Teste integrado: conta A cria veículo e cenário; solicita revisão; conta B diferente com scenario_reviews.manage registra parecer; A gera prévia e pacote com cenário+parecer, confere SHA/ZIP.
6. Verificar bloqueios: usuário sem scenarios.manage, usuário sem packages.manage, autor revisando própria proposta, cenário arquivado, parecer obsoleto e arquivo privado alterado.
7. Validar dados financeiros apenas como simulações, sem qualquer liberação de pagamento.

Status: código escrito em GitHub e testes de API adicionados, mas estes gates NÃO foram executados em runtime PHP/MySQL/React nesta sessão. Não declarar homologado ou publicado.
