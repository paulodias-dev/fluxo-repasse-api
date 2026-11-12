# Segurança — fundação de identidade

## Modelo de ameaça inicial
- Roubo de senha: política de provisionamento manual com 12+ caracteres, rate limit, respostas genéricas e HTTPS.
- Roubo de JWT: token de curta duração, memória de aba no frontend, não persistir em localStorage/sessionStorage/IndexedDB; controlar XSS e CSP.
- Reutilização de JWT revogado: blacklist habilitada, cache compartilhado entre instâncias.
- Roubo de credenciais por CORS: sem cookies de sessão no navegador; tokens apenas via Bearer; allowlist explícita de origens.
- Falha de logout offline: o cliente remove o token em memória, mas um token remoto já copiado pode permanecer válido até a expiração.
- Ataque por proxies: rate limit depende de IP confiável; configurar proxy e não confiar cegamente em X-Forwarded-For.
- Acesso indevido: não existe autorização por entidade nem endpoints financeiros; **qualquer função de negócio futura exige RBAC, testes de autorização e isolamento de dados**.

## Gatilhos antes do MVP financeiro
1. Definir perfis/permissões/tenants de forma aprovada e mapear matriz de acesso.
2. MFA ou autenticação reforçada para operações críticas.
3. Logs auditáveis e imutáveis para mudanças financeiras.
4. Idempotência, proteção de concorrência e precisão decimal definida por negócio.
5. Restauração de backups, rotação de credenciais e política de retenção LGPD.
6. Pentest/revisão de dependências e ambientes homologados.
7. Política de sessão PWA/reautenticação e armazenamento seguro conforme UX aprovada.
