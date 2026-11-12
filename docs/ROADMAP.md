# Roadmap — Fluxo Repasse API

Status de macros por capacidade implementada **não equivale ao percentual total do produto**, que depende do contexto funcional ainda inacessível.

## Macro 01 — fundação (integrada à main em 12/11/2026)
- [x] Laravel 12, MySQL configurável, estrutura HTTP.
- [x] Endpoint público `/api/v1/health`.
- [x] Migrations de infraestrutura, seeder vazio.
- [x] Documentação e teste inicial escrito.

## Macro 02 — identidade e contrato (PR 2)
- [x] JWT guard e User JWTSubject (PHP >= 8.3).
- [x] Login e rate limit; sem registro público.
- [x] Me/Refresh/Logout autenticados e revogação JWT.
- [x] Provisionamento de usuário via Artisan, sem senha fixa.
- [x] Testes feature adicionais escritos.
- [x] OpenAPI e documentação de segurança, QA e instalação.
- [ ] composer.lock gerado e versionado.
- [ ] Suite PHPUnit/Laravel Pint e migrations MySQL executadas com evidências.
- [ ] Homologação da integração real com PWA.
 
## Macro 03 — autorização e robustez (depende de requisitos)
- [ ] Definição de RBAC, papéis, escopos, políticas de sessão.
- [ ] Controle de acesso por organização/tenant apenas se confirmado.
- [ ] Auditoria de ações, logs e política de privacidade.
- [ ] Plano de observabilidade, deploy seguro, backup e rollback.
- [ ] Teste de concorrência e proteção de operação financeira após definição de domínio.

**Medição da Macro 02:** 6/9 critérios com artefatos implementados (66,7%); 3 requerem execução em ambiente real. Não inferir avanço total do negócio.
