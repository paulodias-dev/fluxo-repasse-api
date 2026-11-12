# AGENTS — Fluxo Repasse API

## Contexto
API Laravel 12 + MySQL 8+ (PHP 8.3+) em `paulodias-dev/fluxo-repasse-api`.
Frontend PWA React/Vite em `paulodias-dev/fluxo-repasse-app`.
O histórico funcional do Gemini não foi recuperado. A fundação de identidade não autoriza assumir regras financeiras.

## Regras obrigatórias
1. Idioma pt-BR/UTF-8, contratos REST em `/api/v1`, sem dados financeiros fictícios e sem usuários/senhas fixos em seeders.
2. JWT via `php-open-source-saver/jwt-auth`, segredos somente `.env`, rotas privadas com `auth:api`, refresh rotativo e blacklist persistente.
3. Nunca abrir registro de usuários sem decisão aprovada de negócio; provisionar por `repasse:user:create`.
4. Todas as operações futuras têm políticas de autorização explícitas (deny-by-default), validação `FormRequest`, testes e auditoria.
5. Não criar repasses, beneficiários, comissões, movimentações ou regras tributárias sem fonte funcional aprovada.
6. Valor monetário: evitar `float`; validar moeda, escala, arredondamento, idempotência e concorrência.
7. `composer.lock` deve ser gerado e commitado antes de homologar; sempre rodar `composer test`, `composer lint`, migrations em MySQL e revisar `docs/openapi.yaml`.
8. Não divulgar segredos em `VITE_*`, logs, respostas ou repositório.
9. PRs não draft, sem alterar main diretamente; informar cobertura realmente executada, status verificável e próxima macroentrega.
10. Revisar `docs/REQUISITOS_PENDENTES.md` e `docs/SEGURANCA.md` em toda entrega relevante.
