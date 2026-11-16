# Macro 23 — Veículos 360 e integração administrativa

## Escopo implementado
- Ampliação compatível da entidade `repasse_cases` com placa, chassi, marca, modelo, ano, procedência, vendedor, comprador, datas de compra/entrega/venda e valores em centavos. Não renomear tabelas existentes nesta macro porque módulos históricos continuam referenciando `repasse_cases`.
- Lançamentos gerenciais de receita/despesa por veículo, previstos ou realizados, registro imutável de criação e estorno lógico com auditoria. Não representam pagamentos, conciliação, faturas ou transferência bancária.
- Resumo financeiro gerencial: compra, venda, custo atual, custo projetado e resultado projetado; compra/venda não informadas permanecem nulas, não são presumidas como zero.
- Vínculo aos módulos já existentes: evidências privadas, checklist, tarefas, notas, participantes, cenários documentais e eventos.
- `cases.manage` continua sendo a autorização existente; nenhuma permissão financeira executável é criada.

## Contratos REST novos
- `GET /api/v1/repasse-cases/{case}/finance/summary`
- `GET /api/v1/repasse-cases/{case}/finance/entries`
- `POST /api/v1/repasse-cases/{case}/finance/entries`
- `POST /api/v1/repasse-cases/{case}/finance/entries/{entry}/void`

## Migração
Executar backup e `php artisan migrate` no ambiente correto antes de publicar o APP atualizado.
A migração é aditiva, preserva registros existentes e não usa `migrate:fresh`.
Veículos arquivados são somente leitura; reabrir requer uma ação explícita.

## Restrições e dívida técnica
- Operações financeiras reais, regras de comissões, papéis de negócio e segregação por organização ainda dependem de especificação formal.
- A macro entrega um módulo de custo gerencial; despesas previstas não viram automaticamente obrigações. Receita extra não equivale ao preço de venda.
- Não há exclusão definitiva de registros financeiros, apenas estorno rastreável. Usuários administrativos devem ser desativados para preservar auditoria.
- A API de cenários, revisões e pacotes foi preservada; sua cobertura completa no novo layout React permanece uma próxima frente.
- Homologação real com PHPUnit, Pint, migrations MySQL, `npm run build`, lint, e testes de navegação autenticada depende de execução em ambiente com as dependências e banco disponíveis.

## Verificações mínimas para aceite
1. Migrate segura sobre base com dossiês legados, sem perda ou mudança de IDs.
2. Usuário somente com `cases.manage` abre o veículo, suas tarefas e anexos, sem acessar cadastros não autorizados.
3. Entradas previstas e realizadas têm totais corretos em centavos; estorno não apaga histórico.
4. Arquivamento impede alterações de ficha, anexos e entradas; reabertura restaura edições.
5. Login renovado reflete os papéis; usuário sem permissão recebe 403.
6. Navegação desktop e mobile preserva o shell e as classes visuais do layout adquirido.
