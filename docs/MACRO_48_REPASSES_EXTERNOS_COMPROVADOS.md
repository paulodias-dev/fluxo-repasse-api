# Macroentrega 48 — Acompanhamento de repasses EXTERNOS com comprovantes

## Propósito e limite
**Uma etapa funcional completa**, partindo de proposta interna já registrada e de venda real datada, para registrar um fato declarado pelo operador: uma transferência que ele afirma ter realizado FORA do sistema. Este controle NÃO executa pagamento, não consulta banco, não valida liquidação, não constitui dívida, comissão exigível, alçada, saldo ou aprovação monetária. Os rateios da proposta permanecem exclusivamente **referências hipotéticas**; a diferença aritmética exibida NÃO é um valor devido.

## Fluxo completo
1. Abrir Veículo 360 → Condições comerciais → Preparação do repasse → proposta selecionada. Se não há venda datada, o formulário informa o bloqueio.
2. Selecionar favorecido **da própria fotografia SHA da proposta**, que contém pagador previamente escolhido explicitamente. Informar valor real declarado em centavos, data não futura e opcionalmente identificador externo/observação. Declarar explicitamente que se trata de operação feita fora do aplicativo.
3. Anexar comprovante privado PDF/JPG/PNG/WebP de até 10 MiB. Um POST multipart único armazena a declaração e seu arquivo, com SHA-256, nome, MIME e tamanho; UUID request_key evita registros duplicados no retry do mesmo autor/escopo/conteúdo/prova.
4. Ver totais globais por favorecido e todos os registros filtrados/paginados, com IDs e comprovantes baixáveis apenas via rota autenticada. A leitura não confirma autenticidade bancária; **o download verifica tamanho e SHA no disco**.
5. Corrigir lançamento por **estorno com justificativa obrigatória**, sem apagar registros/comprovantes. Totais informados excluem estornados. Retry de estorno com mesma justificativa é idempotente.
6. Exportar CSV UTF-8 com BOM, filtro reportado/estornado/todos, proteção contra fórmulas em campos livres e indicação explícita "NÃO VERIFICADO PELO BANCO".

## Contratos
No prefixo preexistente /api/v1/repasse-cases/{case}/commercial-terms/{term}/preparation/proposals/{proposal}:
- GET /external-transfers — resumo por favorecido + histórico paginado de 15, filtro status=all|reported|voided.
- POST /external-transfers — multipart com request_key, expected_proposal_sha256, beneficiary_party_id, amount_cents, transferred_on, confirmed_external e proof; external_reference/operator_note opcionais.
- GET /external-transfers/export — CSV filtrado privado.
- GET /external-transfers/{transfer} — detalhe do relato e SHA do arquivo, sem URL pública.
- GET /external-transfers/{transfer}/proof — download após checagem de integridade SHA.
- POST /external-transfers/{transfer}/void — estorno justificado, sem exclusão.

JWT Bearer, session.current, cases.manage E scenarios.manage e limites de escrita/leitura existentes. A tabela aditiva única repasse_external_transfer_reports mantém FKs por veículo/minuta/proposta/pagador/favorecido, UUID único, SHA de intenção e proposta e metadados de arquivo. Nenhuma tabela de ledger, saldo ou pagamentos bancários.

## Arquivamento e fonte histórica
Após a Macro 47, a venda pode ser concluída e a ficha arquivada. A Macro 48 permite acrescentar **somente um relato externo em tabela própria**, sem reabrir a ficha nem alterar a venda ou lançamentos gerenciais. A proposta ficará frequentemente stale após a venda; o usuário deve conferir manualmente a fonte histórica e o comprovante, e a API não afirma aprovação monetária nem que o rateio se tornou exigível.

## Regressão coberta por código
Teste feature novo inclui: venda não registrada bloqueia POST; declaração após arquivamento; cifra e destinatário de proposta; SHA de proposta e comprovante; idempotência UUID; payload divergente; validador de arquivo e data; 401/403/404/409/422; estorno e retry; exclusão de estorno no total; 17 relatórios com duas páginas; CSV anti-fórmula e histórico intacto. Teste estrutural React acrescentado ao qa-mobile-layout.

**Testes escritos, NÃO executados aqui.** Ainda faltam PHPUnit/Pint/MySQL, npm build/lint, navegador real, inspeção mobile/desktop e homologação de negócio, previstos após código MVP.

## Progresso
Matriz ponderada provisória do CÓDIGO do MVP (excluindo QA/homologação) avança de **~84% para ~85%**: etapa 5 preparatória de 46% para cerca de 50%, sem afirmar funcionamento do motor bancário. Para execução monetária real continuam pendentes: regra aprovada de obrigação, bases/comissões/tributos, titularidade, aprovações, provedores, conciliação, estornos financeiros e eventual isolamento organizacional.
