# AGENTS — Fluxo Repasse API

## Padrões obrigatórios
- API Laravel 12 e MySQL 8+, rotas versionadas `/api/v1`; idioma pt_BR e UTF-8.
- Frontend separado em `paulodias-dev/fluxo-repasse-app`.
- **Histórico do Gemini não recuperado.** Não implementar regras de repasses, saldos, comissões, beneficiários nem integrações bancárias sem definição escrita.
- Migrations idempotentes, seeders sem dados financeiros fictícios, testes automatizados a cada rota.
- Validar input via FormRequest; respostas e exceções padronizadas; Swagger/OpenAPI quando endpoints do negócio forem acordados.
- Segurança padrão deny-by-default; autenticação, RBAC, auditoria e isolamento são mandatórios antes de operações financeiras.
- Valores monetários: nunca usar `float` para cálculo oficial; definir unidade mínima, precisão, arredondamento, fuso e moeda com o negócio.
- Operações financeiras futuras exigem transações, idempotência, logs auditáveis e proteção contra concorrência; nenhum job de pagamento automático sem autorização.
- Não versionar `.env`, segredos, tokens, credenciais ou dumps de dados reais.
- PRs não draft, preservar `main`, atualizar `docs/ROADMAP.md` e reportar testes realmente executados.
- Antes de merge: `composer test`, `composer lint` e checagem da migration no MySQL real.
