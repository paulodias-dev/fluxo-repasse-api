# ETAPA FUNCIONAL — Macroentrega 27: Financeiro 360 completo do veículo

## Compromisso de macroentrega
Esta entrega fecha **um módulo de negócio inteiro** em API Laravel/MySQL e React dentro da ficha 360 do veículo, em vez de distribuir ajustes em micro-PRs. O layout Metronic adquirido permanece a fonte visual única. Não há duplicação de shell, nem módulo financeiro bancário fictício.

## Funcionalidades incluídas na MESMA etapa

### 1. Escrituração gerencial completa e retrocompatível
- Criação de receita extra ou despesa, prevista/realizada, em centavos, categoria, descrição e data.
- Filtros combináveis (natureza, estado, categoria, período, pesquisa, com/sem estornados), paginação de 30.
- Histórico consulta lançamentos legados sem category_id, sem migrar dados historicamente.
- Campos proibidos como dados bancários ou ordens de transferência são rejeitados em POST/PATCH.
- Compra e venda pertencem à ficha do veículo, não são inseridas automaticamente como lançamentos para evitar dupla contagem.

### 2. Catálogo de categorias
- Cadastro global na instalação single-org, segregado por natureza.
- Listagem de categorias ativas e inativas; renomeação, desativação e reativação.
- Duplicação por slug + natureza bloqueada pelo banco.
- Lançamentos históricos mantêm o nome de categoria capturado no momento da inclusão; editar categoria não reescreve a linha do histórico.
- Categorias inativas não são aceitas para novos lançamentos. Continua possível criar categorias descritivas livres para compatibilidade com a API original.

### 3. Retificação auditada e estorno
- POST /entries/{entry}/replace: em uma transação, inativa linha anterior, grava justificativa, cria linha substituta e eventos nos dois registros.
- Linha antiga **não é apagada**. Seus documentos permanecem vinculados e seu valor sai dos resumos correntes.
- Não é possível retificar novamente uma linha já anulada, alterar documento de lançamento anulado ou mutar veículo arquivado.
- POST /entries/{entry}/void aceita justificativa opcional, com histórico de autor e timestamp. Sem operação bancária.

### 4. Comprovantes associados a cada lançamento
- POST /entries/{entry}/documents aceita **ou** arquivo PDF/JPEG/PNG/WebP até 10 MiB **ou** evidence_id de documento privado já cadastrado no mesmo veículo.
- Upload guarda no disco privado `local`, cria evidência e vínculo na mesma transação; rollback da transação remove arquivo físico, evitando resíduos.
- Banco impede que evidência seja vinculada a dois lançamentos diferentes; repetir a associação existente é idempotente.
- DELETE /entries/{entry}/documents/{evidence} desvincula com auditoria, sem excluir o documento original do veículo.
- Download autenticado confere o vínculo, não permite evidência arquivada e compara SHA-256 do arquivo com o valor original antes de entregar. Não há URL pública ou pasta de upload exposta.
- Todos os detalhes são retornados sem storage_path.

### 5. Histórico e auditoria
- Nova tabela append-only pela API com evento, operador, contexto mínimo e timestamp por lançamento.
- Inclui criação, estorno, retificação, upload/vínculo e desvínculo.
- Lançamentos anteriores à macro seguem consultáveis, com autor/data originais; não se fabrica log retroativo.
- Trilha do veículo existente continua recebendo eventos agregados (sem valores sensíveis nos nomes dos eventos).

### 6. Demonstrativos e exportação
- Resumo por natureza e estado, total exato em centavos.
- Demonstrativo paginado por categoria, e série por competência efetiva. Sem data explicitamente apresentada como "sem data".
- Filtros de período e inclusão de estornados; este último resulta em totais **históricos**, não saldo vigente.
- CSV privado com BOM UTF-8, delimitador ;, dados filtrados e proteção contra fórmula em células textuais.
- Não confundir custo gerencial projetado com lucro contábil ou recebimento bancário real.

## Arquitetura e persistência
- Migration **aditiva**: database/migrations/2026_11_16_000010_create_vehicle_finance_workspace.php
  - repasse_financial_categories
  - repasse_case_financial_documents
  - repasse_case_financial_events
  - category_id, replaces_entry_id e void_reason em repasse_case_financial_entries.
- Serviço único VehicleFinanceWorkspace para categoria, relatório, filtro, registro e consulta de auditoria.
- VehicleFinanceController evoluído preservando resumo e operações anteriores.
- Dois controllers dedicados ao catálogo e ao vínculo seguro de comprovantes.
- **14 operações REST** incluídas no OpenAPI 1.7, contando contratos pré-existentes ampliados, todas sob JWT, session.current e cases.manage, writes limitados por throttle.
- Não há migrations destrutivas, seeder de credenciais ou tabelas duplicadas de usuários.

## Segurança e limites de escopo
- Modelo atual é single-org: categorias compartilhadas globalmente. Multitenancy não foi presumido; não publicar acesso entre organizações sem política formal.
- A macro não cria contas a pagar bancárias, gateway, Pix, TED, comissão real, obrigações fiscais, aprovação de pagamentos, ledger de liquidação nem conciliação externa.
- Upload máximo 10 MiB. Tipos permitidos detectados pelo MIME; SVG/HTML e arquivos executáveis não são aceitos.
- Repasse financeiro executável e alçadas continuam bloqueados até regras formalmente aprovadas.

## QA dirigido — executar ANTES da homologação
1. Backup em banco autorizado, `php artisan migrate` (NUNCA migrate:fresh em ambiente persistente).
2. `php artisan test --filter=VehicleFinanceWorkspaceTest`
3. `php artisan test --filter=Vehicle360Test`
4. `php artisan test --filter=CaseOperationsTest` e `php artisan test --filter=VehiclePortfolioTest`
5. `vendor/bin/pint --test`, `php artisan route:list --path=repasse-cases`, validar OpenAPI 1.7.
6. No frontend: `npm ci`, `npm run build`, `npm run lint`.
7. Smoke com veículo em acompanhamento e arquivado; categoria nova/renomeada/inativa, lançamento legado, estorno/retificação, comprovantes com veículos distintos, PDF/PNG válido, arquivo adulterado, CSV e relatório por período.
8. Validar totais atuais/projetados do Veículo 360 e carteira operacional após retificação sem dupla contagem.

**Estado de evidência:** 8 testes feature focais escritos, não executados em ambiente PHP/MySQL real desta sessão. A implantação e validação por navegador permanecem pendentes. Não apresentar PR como homologação.
