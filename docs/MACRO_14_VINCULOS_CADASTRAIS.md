# Macroentrega 14 — Vínculos cadastrais

## Resultado
Módulo operacional de vínculos administrativos entre pessoas/empresas existentes.

- `party_links` persistente: origem, destino, descrição livre, status, responsáveis e timestamps.
- Rotas autenticadas JWT com `parties.manage`: listagem, criação e atualização.
- Pesquisa de pessoas e empresas pela UI existente, seleção de destino, descrição, edição, encerramento e reativação.
- Rejeita auto-vínculo, cadastro inativo, duplicação por origem/destino/descrição e troca de vínculo por outra origem.
- Operações de gravação transacionais, status lógico sem exclusão, eventos de auditoria na origem.
- Sem cadastro fictício, sem dados financeiros, sem transferência, sem novo layout ou módulo separado da navegação original.

## Contrato API
`GET /api/v1/parties/{party}/links?page=1` — lista paginada.
`POST /api/v1/parties/{party}/links` — JSON: `related_party_id`, `description`.
`PATCH /api/v1/parties/{party}/links/{link}` — JSON: `description` e/ou `is_active`.

A relação é **direcional e exclusivamente cadastral**. Não corresponde a pagador, recebedor, contrato, beneficiário ou vínculo trabalhista. A descrição é informada pelo operador. Nenhuma relação inversa é implícita.

## Homologação pendente
- Após backup do banco de desenvolvimento, executar `php artisan migrate`, sem `migrate:fresh`.
- Executar apenas `php artisan test --filter=PartyLinksModuleTest` e QA de integração da tela.
- Verificar o fluxo com dois cadastros reais e permissão `parties.manage`.
- A execução dos testes completos de produto permanece no fechamento do conjunto de módulos.

## Próximo foco
Recuperar/aprovar o fluxo real de repasse: origem, destino, evento gerador, critérios, alçadas de aprovação, liquidação e conciliação. Só depois desenvolver os módulos monetários. O índice técnico antigo de 6/12 não deve ser divulgado como percentual de conclusão financeira.
