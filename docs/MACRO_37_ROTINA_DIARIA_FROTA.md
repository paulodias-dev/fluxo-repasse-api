# Macroentrega 37 — Rotina diária clara e operacional

## Objetivo do usuário
Abrir o Metronic no celular e descobrir rapidamente **o que precisa de atenção hoje**, sem navegar entre novas centrais e sem transformar todos os dados opcionais em burocracia.

## Experiência do operador
1. Dashboard apresenta contagens reais e atalhos para veículos com **tarefas vencidas, abertas, despesas previstas e identificação incompleta**; clique já aplica o filtro da lista.
2. A frota abre priorizando o trabalho operacional e oferece os mesmos filtros diretamente na URL: pesquisa, situação, atenção, ordenação e página. Back/forward do navegador preserva contexto.
3. Os cards mobile exibem compra, despesas e resultado já conhecidos, duas colunas de ações compactas e uma chamada contextual quando há trabalho aberto.
4. Veículos com tarefa vencida/aberta abrem a aba **Pendências**. Usuário toca **Concluir** na tarefa — sem usar select no celular; edição avançada de status permanece no desktop.
5. Veículo com despesas previstas abre diretamente **Financeiro → Previstos**; pode registrar a ocorrência pela Macro 35 ou anexar comprovante pela Macro 36.
6. Identificação incompleta leva diretamente à ficha, sem forçar campos opcionais para cadastrar veículo.

## Backend
GET /api/v1/operations/vehicles?sort=priority
- Novo valor válido em FormRequest: priority.
- SQL faz a ordenação **ANTES de paginar**, com status arquivado por último, prazos vencidos primeiro, tarefas abertas, despesas previstas, identificação incompleta e demais ativos. Desempate estável.
- Mantém summary global autorizado independentemente do filtro/página.
- Sem mudanças em endpoints financeiros, contratos monetários, SQL de agregação, schema ou permissões.
- Default histórico da API: updated. Default novo do app: priority.

## Frontend
- Meus veículos usa filtros como estado de URL, sem quebrar links antigos (?new=1) e com fallback para query inválida.
- Links do dashboard para /vehicles?attention=...&status=draft&sort=priority são aplicados no carregamento.
- Escolha do filtro de prioridade e páginas atualiza URL; limpar filtro remove atenção e situação draft.
- Next action só aparece com motivo real da API; não obriga documento ou prazo financeiro não definido.
- A aba Financeiro existente pode abrir com financeStatus=planned.
- Layout de indicador/modulo Metronic continua col-6 no mobile, com botões compactos.

## Regras de produto e segurança
- 'Vencido' aqui se refere **exclusivamente a due_on das tarefas**. Despesa prevista não implica vencimento ou pagamento.
- Não há decisão, automação, liquidação, PSP, Pix, repasse, comissão ou cobrança financeira.
- Sem novos módulos, telas, endpoints, permissões, tabelas ou dependências.
- App e API continuam privados por autenticação/RBAC. Números de um filtro não são o total da frota; summary global segue separado.

## Aceite pendente
- Testes feature escritos para prioridade e para reordenação após completar tarefa e efetivar despesa, verificando que arquivados continuam fora de filtros operacionais.
- QA real: composer test/lint, SQLite/MySQL, npm QA e inspeção 320/375/390px, iOS/Android e desktop para o Metronic.
- **Nada disso foi executado nesta sessão.** Não declarar produção ou homologação até concluir.
