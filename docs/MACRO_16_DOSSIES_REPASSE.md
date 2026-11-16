# Macroentrega 16 — Dossiês de repasse (somente preparação documental)

## Problema resolvido
Permitir que operadores organizem casos associados ao futuro processo de repasse, vinculando pessoas/empresas já cadastradas e registrando alterações sem criar obrigações financeiras fictícias.

## Entrega operacional
- Tabelas aditivas `repasse_cases`, `repasse_case_parties`, `repasse_case_events`.
- CRUD documental: criar, listar/pesquisar, detalhar, editar e arquivar/reabrir dossiês.
- Associar e desassociar cadastros ativos, com histórico do evento e confirmação na interface.
- Auditoria transacional com operador e data, sem armazenar corpo de requisição.
- Permissão independente `cases.manage`, incluída de forma idempotente no `AdminUserSeeder` do perfil técnico.
- Rejeição explícita de campos desconhecidos, financeiros ou bancários em mutações.

## Contrato API
- `GET/POST /api/v1/repasse-cases`
- `GET/PATCH /api/v1/repasse-cases/{case}`
- `POST /api/v1/repasse-cases/{case}/parties`
- `DELETE /api/v1/repasse-cases/{case}/parties/{party}`
- `GET /api/v1/repasse-cases/{case}/events`

As operações requerem JWT válido e `cases.manage`. O estado `draft` é editável; `archived` impede mutações até reabertura.

## Fora do escopo
Sem valores, comissões, saldos, cobrança, liquidação, autorização de pagamento, repasse bancário, retenções ou regras automáticas. Nem o nome "dossiê de repasse" nem a associação cadastral prova uma obrigação legal ou financeira.

## Implantação e aceite
- Fazer backup do banco de desenvolvimento e executar migrations aditivas por `php artisan migrate`; **não executar migrate:fresh**.
- Rodar `php artisan db:seed --class=AdminUserSeeder` no banco autorizado (idempotente).
- Reautenticar para atualizar permissões, navegar em **Dossiês de repasse**, criar um dossiê, associar pessoa/empresa, editar, arquivar, reabrir e consultar eventos.
- Foco QA: `php artisan test --filter=RepasseCasesTest`, lint/build React e smoke de integração. Não foram executados nesta sessão.

## Próxima macro
Após disponibilizar e aprovar as regras do domínio financeiro: origem e destino, evento gerador, autorização, comissões, prazos, reversões e conciliação. Esse bloqueio é de requisitos, não de QA.
