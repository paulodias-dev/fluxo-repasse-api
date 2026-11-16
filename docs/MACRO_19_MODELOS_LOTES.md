# Macroentrega 19 — Modelos de distribuição e geração em lote

## Objetivo
Ampliar o motor financeiro **não executável** da macro 18 com um conjunto integrado de modelos de distribuição, cálculo determinístico em centavos e produção em lote de cenários, com rastreabilidade completa. Não há porcentagens padrão nem suposição de comissões contratuais. Toda porcentagem é escolhida pelo operador.

## Capacidades implementadas em código

1. Modelos reutilizáveis associados a um dossiê: origem prevista, destinatários cadastrados, nome, descrição, pesos e observações.
2. Pesos em **pontos-base inteiros**: 10.000 = 100,00%. Cada peso é informado explicitamente, sem padrão. Destinatários devem estar ativos e vinculados ao dossiê, e a soma dos pesos deve ser 10.000.
3. Cálculo determinístico de distribuição em centavos: maior resto, empate desempatado pelo menor ID de cadastro, sem floats. Permite ratear centavos residuais com soma final exatamente igual ao total. Rejeita valores que gerariam 0 centavo para algum destinatário.
4. Revisões otimistas de modelos, arquivamento/reabertura, snapshot JSON com SHA-256, ator/data e fork de revisão histórica em novo modelo.
5. Prévia individual sem escrita em banco (valor de entrada em centavos, montantes calculados, diferença zero, metodologia e flag explícita payment_enabled=false).
6. Prévia **integral de lote** de 1 a 30 cenários, cada um com UUID, título, descrição e valor informado. Valida todas as linhas sem persistir cenários.
7. Aplicação de lote como **uma única transação de banco**, com locks, conferência da revisão do modelo e validação integral das linhas. Qualquer erro desfaz todos os cenários do lote. Chave UUID de idempotência: repetição idêntica recupera os IDs e resultados originais; alteração do payload sob a mesma chave retorna 409.
8. Histórico imutável dos lotes: cópia da revisão usada, valores em centavos, participantes, resultados, identificação do operador; consulta paginada e CSV autenticado com mitigação de CSV formula injection.

## Persistência — 4 tabelas aditivas

Migration: database/migrations/2026_11_16_000005_create_distribution_templates_batches.php

- repasse_distribution_templates — modelo, dossiê, origem, revisão, digest, proveniência/fork e status lógico.
- repasse_distribution_lines — destinatários, pesos em bps e observações.
- repasse_distribution_versions — snapshots imutáveis, SHA-256, operador e data.
- repasse_distribution_batches — chave idempotente, digest, snapshot do modelo e resultados originais.

Os cenários gerados são persistidos nas tabelas repasse_scenarios, repasse_scenario_allocations e repasse_scenario_versions já existentes. O registro operacional de auditoria usa repasse_case_events.

## Endpoints — 14 operações

Base: /api/v1/finance, todas com JWT, session.current, cases.manage **e** scenarios.manage.

- GET/POST /distribution-templates
- GET/PUT /distribution-templates/{template}
- PATCH /distribution-templates/{template}/state
- GET /distribution-templates/{template}/versions
- GET /distribution-templates/{template}/versions/{revision}
- POST /distribution-templates/{template}/fork
- POST /distribution-templates/{template}/preview
- POST /distribution-templates/{template}/batch-preview
- POST /distribution-templates/{template}/batches
- GET /distribution-batches
- GET /distribution-batches/{batch}
- GET /distribution-batches/{batch}/export

OpenAPI 1.2 em docs/openapi.yaml.

## Limites e guardrails de segurança

- Não executar pagamentos, Pix, liquidar, aprovar, cobrar, gerar saldos, reconhecer obrigação ou movimentar bancos.
- Não inferir taxas, descontos, retenções, tributos, regras automáticas de comissão ou responsabilidades de pagador/beneficiário. Os pesos são **parâmetros de simulação**, não uma política comercial aprovada.
- Sem alteração de valores via float: cálculos em centavos inteiros PHP 64-bit (limite por cenário de 10^12 centavos) e bps inteiros. Soma máxima 10^16 fica dentro da capacidade de inteiros 64-bit.
- Origem e destinatários somente quando integrantes ativos do dossiê; não modificar cenários/modelos se o dossiê estiver arquivado. Lotes antigos são apenas leitura.
- Rateio com valor insuficiente para gerar 1 centavo por destinatário falha 422; nenhuma parcela zero é descartada silenciosamente.
- Prévia não faz qualquer escrita; requisição de aplicar lote exige UUID, revisão exata e validação de todas as linhas.
- Perdas de conexão podem ser repetidas com a mesma chave, sem duplicação; não usar novos UUIDs no retry de uma mesma operação.
- Os snapshots SHA-256 verificam consistência interna, mas não equivalem a WORM externo ou trilha inviolável.
- O modelo organizacional / isolamento multi-tenant permanece **não aprovado**. Este módulo NÃO deve ser disponibilizado para tenants distintos sem política de autorização e isolamento específica.

## Homologação focalizada (não executada nesta sessão)

1. Backup do MySQL de desenvolvimento homologável e aplicação aditiva de migration com php artisan migrate. Proibido migrate:fresh em ambiente persistente.
2. Reutilizar permissão scenarios.manage já existente na macro 18; **não** provisionar senha ou usuário padrão.
3. php artisan test --filter=DistributionWorkbenchTest
4. vendor/bin/pint --test nos arquivos PHP alterados e inspeção OpenAPI.
5. APP: node --test src/features/finance/distributionMath.test.mjs; npm test -- --run src/features/finance/DistributionWorkbench.test.jsx src/features/finance/DistributionBatchPanel.test.jsx; npm run lint; npm run build.
6. Smoke API+APP: criar modelo 60/40, ratear R$ 1,01, conferir resíduos, alterar revisão, ver histórico, prever 2 cenários, aplicar em lote, repetir mesmo request_key, tentar reuso divergente (409), testar erro na segunda linha (rollback integral), arquivar e testar 422, baixar CSV autenticado.

**PHPUnit/MySQL/Pint/Vitest/build/browser não foram executados neste ambiente; teste escrito não significa homologado.**

## Próximo foco

O núcleo executável (obrigações, aprovações, ledger, liquidação/estorno, conciliação e integrações bancárias) permanece condicionado à recuperação e aprovação formal de quem paga/recebe, fatos geradores, comissão, retenções, alçadas e modelo organizacional. O sistema de cenários não oferece comando de pagamento nem endpoint equivalente.

Progresso **da macro de código**: 8/8 frentes implementadas na branch, validação runtime pendente. Não converter isso em conclusão percentual do produto financeiro.


## Verificação focalizada executada durante esta entrega

- O arquivo DistributionCalculator.php foi reproduzido no ambiente local e sua identidade de conteúdo foi conferida pelo Git blob SHA 35c4c22ab8675f45eb1d7f888b6471fe6e7e0db3.
- php -l DistributionCalculator.php: aprovado, sem erro de sintaxe.
- Quatro checagens aritméticas com uma implementação mínima de ValidationException para isolar o cálculo: 33,33%/33,33%/33,34% em 10.001 centavos; soma exata; determinismo independente da ordem; rejeição de alocação com zero centavo. **4/4 aprovadas**.
- Essas checagens validam a lógica pura; NÃO executam Laravel, migrations ou API HTTP. A suíte DistributionWorkbenchTest e homologação MySQL permanecem pendentes.
