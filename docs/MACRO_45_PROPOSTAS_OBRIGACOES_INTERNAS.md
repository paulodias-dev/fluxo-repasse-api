# Macro 45 — Propostas internas de obrigações (NÃO executáveis)

**Uma etapa funcional, API + React, integrada ao Veículo 360 existente.**

## Finalidade
A partir de uma apuração manual imutável da Macro 44, o operador declara explicitamente o participante responsável pela origem, informa datas opcionais e congela uma proposta de distribuição hipotética em centavos. Isto NÃO lança conta a pagar/receber, saldo, ledger, aprovação financeira, imposto, comissão, banco, Pix, quitação ou obrigação exigível.

As condições da minuta são congeladas como declarações textuais. Nunca viram políticas automaticamente aprovadas.

## Jornada ponta a ponta
1. Abrir Veículo 360 > Condições Comerciais > Preparação > Apuração manual e histórico.
2. Selecionar uma fotografia íntegra e atual, e abrir Preparar proposta.
3. Selecionar explicitamente um participante ativo e já vinculado ao mesmo veículo. O sistema não presume o responsável a partir do modelo de distribuição.
4. Informar opcionalmente data do evento, data pretendida e observação (sem preenchimento implícito). Uma data pretendida não pode preceder a data do evento declarado.
5. POST congela SHA da fonte, fotografia de apuração, condições contratuais declaradas, responsável, datas, revisão de parecer e distribuição por participante.
6. Histórico completo paginado em blocos de 15, leitura da fotografia, CSV autenticado com proteção anti-fórmula. Alteração posterior da minuta/modelo/parecer/financeiro só sinaliza fonte obsoleta; nunca altera a proposta.
7. Nova intenção cria nova proposta; nenhuma mutação ou exclusão da fotografia anterior.

## Contratos e persistência
Uma migration aditiva cria repasse_obligation_proposals, com FK de veículo, minuta, apuração, participante responsável, UUID única de idempotência, digest da intenção, SHA de fonte, snapshot LONGTEXT, SHA de conteúdo, autor e data. Não existe updated_at ou rota de edição/exclusão. Nenhum lançamento em repasse_case_financial_entries é criado.

Base privada: /api/v1/repasse-cases/{case}/commercial-terms/{term}/preparation/proposals

- GET /options: participantes ativos vinculados ao veículo, sem selecioná-los automaticamente.
- GET /?page=1: histórico paginado.
- POST /: criar fotografia, 201 ou replay 200 (created=false).
- GET /{proposal}: fotografia e stale.
- GET /{proposal}/export: CSV privado UTF-8 com integridade prévia.

Campos aceitos no POST: request_key (UUID), preparation_record_id (ID), expected_record_sha256, payer_party_id (ID), trigger_on/due_on (datas opcionais ISO) e operator_note (até 1000 caracteres). O cliente nunca envia beneficiários, valores, comissão, saldo, pagamento ou autorização.

## Segurança
JWT Bearer + sessão vigente + cases.manage E scenarios.manage, throttles existentes e FormRequest de campos permitidos. Escrita transacional bloqueia veículo/minuta e valida SHA, pertencimento do participante, atualidade de origem e soma do rateio. Retry da mesma chave, ator e payload devolve a mesma fotografia; reutilização divergente responde 409. Escopo cruzado 404, acesso ausente 401/403, campos inválidos 422.

Os campos financial_policy_approved, financial_authorization, obligation_created, payment_enabled e executable são sempre false. Nenhuma política bancária, multi-tenant ou obrigação real pode ser inferida. Hash é conferência interna, não prova WORM ou assinatura jurídica.

## QA e pendências
Testes feature foram escritos: histórico imutável, idempotência, revisão obsoleta, 401/403/404, isolamento entre veículos, datas, participante e adulteração de SHA. Não foram executados neste contexto; PHPUnit, Pint, MySQL, npm lint/build e browser fazem parte da homologação posterior ao código do MVP.

Dependências de escopo financeiro ainda sem aprovação: origem/pagador, fato gerador, base, alçadas, taxas, tributos, PSP/conta bancária, estorno/reembolso, conciliação e isolamento organizacional. A Macro 45 **não aprova** essas políticas; apenas prepara uma proposta documental interna.
