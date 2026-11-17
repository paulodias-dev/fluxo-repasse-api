# Macroentrega 35 — Realização direta de despesa/receita

## Princípio de UX
Usuário registra valor previsto; quando o gasto, serviço ou receita acontece, toca "Marcar realizada" e informa data (hoje já preenchido). Sem formulário duplicado, sem cadastro de categoria novamente, sem alterar descrição nem mover documentos.

## API — sem mudar schema
POST /api/v1/repasse-cases/{case}/finance/entries/{entry}/realize
Payload: {"effective_on":"AAAA-MM-DD"} (hoje ou anterior).
Resposta: {"data": {lançamento status realized}, "changed":true/false, "payment_executed":false}
- Autenticação JWT vigente, session.current, permission:cases.manage e rate limit.
- FormRequest valida data; operação rejeita payload inesperado.
- Transaction + row lock; somente previsto ativo muda. Se já realizado com mesma data, no-op idempotente. Se já realizado em outra data, 409: usar Retificar.
- Backend valida pertencimento e veículo editável. Estornado/substituído bloqueado.
- Atualiza SOMENTE status, effective_on, updated_at. Event 'realized' no registro com status/data anterior e autor; evento operacional no veículo. Nenhum novo lançamento ou documento.
- Agregações existentes passam a classificar o mesmo valor como realizado, excluindo-o dos previstos. Não implica movimentação bancária.

## React Metronic
- Tela e componente Financeiro 360 mantidos, sem página paralela.
- Filtros rápidos Todos, Previstos e Realizados.
- Em previsto ativo, botão Marcar realizada.
- Confirmação curta mostra referência, valor, data predefinida, diferença entre ocorrência e pagamento, Confirmar e Cancelar.
- Após confirmar, preserva ID, seleciona detalhe/comprovante e atualiza indicadores.
- Dois cards lado a lado no celular (col-6), botões compactos, mantendo tabela desktop.

## Escopo excluído
Nada de PSP, Pix, webhook, contas bancárias, saldo, alçada de pagamento, transferência, liquidação, autoaprovação, multitenancy presumido ou novos estados artificiais.

## QA e release
Executar composer test, composer lint, QA MySQL seguro conforme docs/QA.md, build/lint React e inspeção visual mobile/desktop antes do merge/produção.
Testes feature escritos para auth 401/403, cross-vehicle 404, data futura/ausente/extra 422, estorno/arquivamento 422, idempotência, status, totais e recibos.
Nenhum teste/runtime é declarado executado nesta entrega. Não é necessário migrate:fresh --seed porque o schema não mudou.
