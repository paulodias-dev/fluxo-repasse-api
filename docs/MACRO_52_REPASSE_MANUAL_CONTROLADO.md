# Macro 52 — repasse manual controlado no Veículo 360

## Escopo aprovado
- Uma empresa por JWT; banco MySQL único; todas as novas tabelas e consultas carimbadas/escopadas por tenant.
- Só depois de **venda registrada com data**. Pagador e favorecidos devem ser partes ativas vinculadas à ficha.
- **Base informada pelo operador**; nenhuma fórmula inferida sobre lucro gerencial ou valor bruto da venda.
- Destinos fixos ou percentuais explícitos. Porcentagens em basis points, sem float; fechamento exato em centavos por maior resto. Comissões percentuais ou fixas somente quando informadas; tributos e taxas exclusivamente manuais.
- Criar distribuição como rascunho e aprovar expressamente com permissão separada. Autor pode aprovar se também autorizado.
- Só após aprovação registrar parcelas de pagamentos feitos **fora do sistema**. Soma não pode exceder saldo de cada beneficiário; comprovante PDF/imagem opcional, sempre privado e SHA-256 verificado.
- Cancelar distribuição com justificativa somente sem pagamentos ativos; estornar cada pagamento previamente com justificativa e preservar histórico.
- Request-key UUID idempotente para criação e pagamento; transações e locks na linha do veículo e da distribuição.
- Nenhum PIX, PSP, ordem bancária, ledger bancário, taxa presumida ou duplicação da venda/receita. Valores pagos são **declarações do operador**, não conciliação bancária.

## Banco e endpoints
Migração aditiva: repasse_settlements, repasse_settlement_lines, repasse_settlement_payments, repasse_settlement_events.

Prefixo autenticado: GET /repasse-cases/{case}/settlements/options, GET/POST /settlements,
GET /settlements/{settlement}, POST /{settlement}/approve, POST /{settlement}/cancel,
POST /{settlement}/payments, POST /{settlement}/payments/{payment}/void e
GET /{settlement}/payments/{payment}/proof.

Leitura/escrita: cases.manage + repasse.manage. Aprovação: adicional repasse.approve.
Administrador de tenant recebe ambos, Usuário recebe repasse.manage; papéis customizados devem ter grants explícitos.

## Implantação e QA
Aplicar API/migration/seeders antes do React. Não executar migrate:fresh em banco persistente.
Executar PHPUnit/SQLite, MySQL QA, Pint, npm build/lint e QA visual 320/375/390/768/1024/1440 em ambos temas.
Testes escritos não equivalem a execução. A Macro 51 ainda requer regressão de fixtures antigas sob tenant_id.
O total do MVP deve ser recalculado pelo escopo SaaS revisado; não afirmar 100% antes da validação da matriz e da integração.
