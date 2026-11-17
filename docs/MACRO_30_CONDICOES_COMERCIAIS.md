# Macroentrega 30 — Condições Comerciais do Repasse (módulo completo de minutas)

## Propósito
Desenvolver uma etapa comercial integrada de **minutas por veículo** para documentar as regras ainda não definidas do futuro motor de repasses. Não inventar comissões, tributos, regra de lucro, pagadores, obrigação, beneficiário legal ou alçada monetária. A fonte de verdade continua docs/REQUISITOS_PENDENTES.md.

### Funcionalidades da macro (API + React)
1. Cadastro por veículo de minuta com título, descrição, referência BRL opcional **digitada pelo operador** e modelo de distribuição já cadastrado (id + revisão).
2. Modelo de distribuição existente é congelado como snapshot íntegro SHA-256, incluindo origem, participantes e pesos explícitos. Validação de percentual total 10000 bps, partes ativas e vinculadas ao mesmo veículo, sem deduções financeiras automáticas.
3. Matriz de **dez condições comerciais**, todas inicialmente vazias: fato gerador, base de cálculo, comissão/taxas, tributos/retenções, elegibilidade, alçadas, liquidação, estorno/disputa, integração bancária e isolamento organizacional. Campos de texto declaratórios até 2000 caracteres; não são regras executáveis.
4. Anexos privados existentes por veículo (até 25): somente associações a evidências ativas do MESMO veículo, snapshot contendo nome, SHA-256, MIME e tamanho. O upload/arquivamento permanecem no módulo de anexos.
5. Edição com revisão otimista e bloqueio 409 para versão desatualizada; arquivamento e reabertura sem exclusão física; cópia de revisão histórica sem alterar original.
6. Histórico imutável por revisão, com snapshot JSON, SHA-256, tipo do evento, usuário e data; eventos também integrados na timeline existente do veículo.
7. Verificação de atualidade: modelo alterado ou arquivado; participante desligado/inativo; anexo arquivado; veículo arquivado; quais condições ainda faltam. \`documentary_complete\` **só** confirma que dez campos estão preenchidos e vínculos atuais: **não é homologação jurídica nem aprovação monetária**.
8. Simulação opcional com valor de referência informado manualmente e \`DistributionCalculator\` existente: divisão determinística em centavos com pesos explícitos. Não define que o valor é lucro, base legal ou dinheiro disponível. Nenhum endpoint de pagamento criado.
9. Listagem, filtros ativos/arquivados, paginação, busca, exportação CSV filtrado anti-fórmula em UTF-8 privado.
10. Interface na aba Veículo 360 > Condições comerciais e card próprio no dashboard original Metronic com RBAC. Se o operador não tem cases.manage E scenarios.manage, a API responde 403.
11. Dez rotas REST próprias dentro de repasse-cases, OpenAPI 1.9, migration aditiva, integração em OperationalAcceptance e oito testes feature escritos.

## Persistência
- Migration: database/migrations/2026_11_17_000001_create_commercial_terms_module.php
- repasse_commercial_terms: snapshot de modelo, dez condições JSON, referência manual, revisão/sha e arquivo status.
- repasse_commercial_term_versions: snapshot integral por revisão com SHA-256, autor e evento.
- repasse_commercial_term_evidence: associação entre minuta e evidência existente; não duplica nem publica arquivos.

## Rotas
Sob \`/api/v1/repasse-cases/{case}/commercial-terms\`, JWT + session.current + cases.manage + scenarios.manage:
- GET / — listar (status, search, page)
- POST / — criar minuta
- GET /export — CSV filtrado sem cache
- GET /{term} — matriz e snapshot
- PUT /{term} — editar com revision
- PATCH /{term}/state — arquivar/reabrir
- GET /{term}/versions — histórico paginado
- GET /{term}/versions/{revision} — conferir revisão SHA-256
- GET /{term}/preview — simulação hipotética somente leitura
- POST /{term}/fork — clonar revisão histórica para uma NOVA minuta

## Regras de segurança
- Campos desconhecidos como payment_instruction são rejeitados.
- Cada operação é escopada por veículo; source, destinatários e anexos devem estar ativos e vinculados ao veículo.
- Arquivar veículo impede mutações; arquivar minuta impede sua edição.
- A API verifica digest do termo e do modelo antes de exibir os detalhes; snapshots históricos são imutáveis.
- Não concede permissão nova, não cria contas bancárias, saldo, obrigação, pagamento, Pix, conciliação, fee, tributo ou cobrança.
- Nenhum isolamento multi-tenant foi presumido: instalação atual single-org.
- Nenhum \`migrate:fresh\`, seeder com credenciais, backup destruído ou secrets em .env versionados.

## Implantação
Após backup de banco autorizado e aprovação do PR da API:
\`\`\`bash
php artisan migrate
php artisan repasse:acceptance --json
\`\`\`
Depois do merge do PR React, realizar o build/deploy segundo o processo da equipe.

A solicitação atual foi de seguir com o desenvolvimento e pular a rodada de testes; por isso, **nenhum PHPUnit/MySQL, Pint, React build ou navegador foi executado nesta sessão**. Os oito testes feature são código escrito, não evidência de sucesso. É necessário validar antes de uso com dados financeiros reais.

## O que ainda falta na Etapa 5
O **motor efetivo de repasses** continua dependente de aprovação das regras de negócio: eventos geradores, base apurada, remuneração/tributação, titularidade e beneficiários, alçadas, saldo/ledger, contas bancárias/PSP, liquidação e conciliação, falhas, reembolsos e segregação organizacional. A macro 30 prepara sua documentação e governança; **não a substitui**.
