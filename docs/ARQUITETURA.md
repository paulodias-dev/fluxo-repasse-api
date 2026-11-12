# Arquitetura técnica — Fluxo Repasse API (Macro 02)

## Camadas
- Laravel 12 e PHP 8.3+, MySQL 8+. API JSON versionada em `/api/v1`.
- `app/Http/Requests/Api/V1/LoginRequest.php`: validação.
- `app/Http/Controllers/Api/V1/AuthController.php`: login, perfil, rotação e logout.
- `app/Models/User.php`: identidade implementando `JWTSubject`, sem qualquer papel financeiro presumido.
- `app/Providers/AppServiceProvider.php`: rate limiter por e-mail + IP e IP total.
- `routes/console.php`: provisionamento seguro de usuários locais.
- `config/jwt.php`: parâmetros explícitos e blacklist.
- `routes/api.php`: rotas públicas e privadas; registro público inexistente.
- `docs/openapi.yaml`: contrato da API; não equivale a L5-Swagger instalado.
- `tests/Feature/AuthApiTest.php`: cenários de identidade.

## Regras e limites
- Apenas autenticação básica, sem autorização por módulo. **Usuário autenticado não possui privilégio de repasse**.
- Tokens assinados com chave gerada por ambiente e prazo curto; refresh exige token válido (não expirado), invalida o anterior.
- Refresh apenas sob JWT válido evita mecanismo de refresh persistente nesta fase.
- O client React retém token em memória, não em localStorage nem em IndexedDB.
- Rate limit: 5/min por combinação e-mail/IP, 30/min global por IP. Se usar proxy, configurar trusted proxies corretamente no Laravel.
- Blacklist deve usar cache compartilhado e persistente ao rodar múltiplas instâncias.
- Não existe rota de registro aberto, transferência, pagamento ou modificação de saldos.
- Extensões futuras: modelo RBAC aprovado, política multi-tenant confirmada, auditoria e gestão de sessão antes de ativar operações financeiras.
