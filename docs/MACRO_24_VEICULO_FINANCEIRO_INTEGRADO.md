# Macroentrega 24 — Integração do financeiro documental ao Veículo 360

## Escopo funcional
A macro usa as APIs das macros 18–21, anteriormente pouco acessíveis no novo layout React, sem inventar motor financeiro executável.

1. Cenários manuais: valores em centavos, origem e destinatários vinculados ao veículo, distribuição, diferença, versões imutáveis, comparador, alternativas e exportação CSV.
2. Modelos: pesos em pontos-base, soma exata de 10000 pontos, versões, prévia individual e processamento em lote de até 30 cenários.
3. Pareceres: fila documental, responsabilidade, segregação solicitante/criador/revisor, snapshot, comparação do estado e validação de hashes.
4. Pacotes: escolha de evidências, cenários e revisões, prévia SHA-256, confirmação de manifesto, emissão idempotente, integridade e ZIP privado.
5. Navegação e operação: rotas visíveis no sidebar adquirido, ligações diretas desde o veículo e busca de veículos por título, placa, chassi, marca, modelo e procedência.

## Alterações na API
- Aditivo em `RepasseCaseController::index`: consulta textual agregada segura (like escapado) sem quebrar filtro por status.
- Teste `FinancialVehicleWorkspaceIntegrationTest`: operações financeiras documentais integradas e negação de acessos indevidos.
- Endpoints das macros 18–21, permissões, armazenamento e migrations preservados.

## Regras de segurança
- `cases.manage` protege o workspace operacional; `scenarios.manage` é exigido nos cenários e pareceres; `scenario_reviews.manage` é exigido para atribuição/decisão; `packages.manage` é exigido para a emissão de pacotes.
- Uma revisão não é autorização de pagamento; um cenário não altera saldos, obrigações nem conta bancária.
- Edições usam número de revisão. Criações de cenários, revisões, lotes e pacotes usam UUID idempotente. Pacotes exigem hash SHA-256 da prévia.
- Documentos são obtidos por downloads privados autenticados e nunca por URL de storage pública.
- A separação entre organizações e o motor financeiro executável permanecem fora do escopo, pois ainda dependem de regras formais.

## QA/homologação pendente
Executar no ambiente da API: backup; `php artisan migrate` (macro 23); `php artisan test --filter=FinancialVehicleWorkspaceIntegrationTest`; demais testes críticos existentes de cenários, lotes, revisões e pacotes; Pint; MySQL real de homologação. No React: `npm ci`, `npm run build`, lint e smoke autenticado desktop/mobile com permissões parciais.

Não há confirmação de build, migrations ou testes reais desta macro nesta sessão. PRs sem Draft não são equivalentes a deploy ou homologação.
