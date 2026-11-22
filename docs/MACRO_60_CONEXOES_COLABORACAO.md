# Macroentrega 60 — Conexões, chat e veículos colaborativos

## Decisões aprovadas

Conexões (solicitações/aceite, favoritos), chat privado individual e grupos vinculados ao veículo, múltiplos sócios, mensagens em tempo real, anexos privados, convites por e-mail para usuário existente ou futuro, compartilhamento e transferência com aceite explícito, escolha de histórico e permissão de escrita, identificação do autor e do pagador, veículos recebidos na carteira.

**Fora do escopo:** demonstrativos financeiros individuais, saldos e acertos entre sócios (backlog de macroentrega futura).

## Política de segurança

- `TenantContext` jamais é alterado para o tenant alheio. Rotas legadas de veículos não mudam de escopo.
- Colaboração é ACL explícita por `recipient_id`, veículo e tenant proprietário, validada a cada requisição.
- Pesquisa de usuários exige **e-mail exato**; não expõe diretório por CPF ou nome e limita requisições.
- Solicitação de conexão NÃO dá acesso a veículos. Convite não concede acesso antes do aceite.
- Compartilhamento sem histórico usa pisos de IDs de documentos/financeiro registrados no instante do aceite. A fotografia de identificação é exceção visual autorizada.
- Grupo do veículo restringe membros a criador e sócios autorizados. Entrada/reentrada usa `joined_after_message_id`, sem liberar mensagens anteriores.
- Revogar acesso interrompe leitura, gravação e participação no grupo; trilha auditável permanece.
- Mensagens e anexos são privados; download autentica usuário/participação, verifica SHA-256 e nunca expõe `file_path`.
- Lançamentos colaborativos são idempotentes por UUID e trazem `created_by` e `paid_by_user_id`. Lançamento realizado exige pagador autorizado; previstos não afirmam pagamento.
- O pagador nos lançamentos **legados** permanece `null` quando não declarado. Não inferir autor como pagador.
- Não realizar qualquer pagamento ou autorização financeira.

## Semântica da transferência

Transferir não é compartilhar. O aceite arquiva o registro de origem, preservando toda sua auditoria/lançamentos/comprovantes, e cria **nova ficha ativa** no tenant do recebedor com dados de identificação. Nenhum dado é destruído e o carro não fica simultaneamente ativo nas duas empresas. O registro `vehicle_ownership_transfers` conecta origem/destino.

Se **com histórico**, o novo titular recebe acesso somente leitura ao arquivo anterior via `/collaboration-transfers/{transfer}/history` e downloads privados. Esse arquivo NÃO alimenta automaticamente os totais da nova ficha: evita duplicação de custos e rendimentos contábeis.

Se **sem histórico**, não é concedido acesso ao arquivo anterior.

**Limitação segura:** transferir sem histórico para outro usuário do MESMO tenant é bloqueado; os papéis atuais de `cases.manage` já permitiriam ver arquivos do tenant. Convite restritivo de visualização/histórico também é bloqueado quando o destinatário do mesmo tenant já possui `cases.manage`. Não prometer isolamento que a regra vigente de papéis não consegue garantir.

## Mensagens em tempo real

O endpoint SSE `GET /connections/chats/{chat}/stream` aceita JWT Bearer, revalida permissão durante o streaming, envia eventos até 18 segundos e cliente reconecta com cursor/idempotência. A interface também faz polling de recuperação. Escala de alta concorrência exige migração para Redis/Reverb ou broker equivalente; o streaming mantém uma worker PHP ocupada enquanto conectado.

Notificações em primeiro plano são consultadas a cada 30 segundos e podem disparar API Notification do navegador com permissão. **Push verdadeiro com aplicativo fechado não está operacional** nesta macro: requer configuração VAPID, service worker com push handler, subscrições e provedor, que não foram implementados/validados. Não descrever como pronto.

## Operação

- API: rodar migrations aditivas antes de publicar o React; configurar `FRONTEND_URL`, `MAIL_MAILER` e envio SMTP real.
- Front React: tela Conexões com abas Pessoas, Mensagens, Convites e Veículos; Veículo 360 abre compartilhamento/transferência; frota mostra compartilhados; recebidos usam rota `/vehicles/shared/:grantId`.
- Aceite por e-mail preservado durante login e cadastro via sessionStorage e token não adivinhável.
- Testes novos: `php artisan test --filter=CrossTenantCollaborationTest`; repetir todos os testes de veículos, finanças, RBAC e isolamento; testar recusas/revogação/replay/convites expiração.
- QA manual: testar 320/375/390/768/1024/1440 px, temas claro/escuro, duas contas/tenants, anexos, histórico com/sem, transferência e chat.
- Não foi executada compilação, PHPUnit, Pint ou homologação com banco real nesta sessão. Verificar antes de merge.

## Backlog futuro explícito

Painel financeiro de cada sócio no Veículo 360: aportes, desembolsos, participação, saldos/acertos e valores a pagar/receber. Não implementar nesta macro.


## Revisão final de robustez do núcleo

- Pedidos de amizade exigem e-mail exato também no POST; IDs sequenciais de usuários não são aceitos como entrada.
- Mensagens com a mesma chave idempotente só são reconhecidas se texto e hash do arquivo forem idênticos; conflitos respondem HTTP 409. A inserção serializa pela conversa para evitar duplicações concorrentes.
- Gravações colaborativas bloqueiam a permissão em banco dentro da mesma transação, impedindo que uma revogação ultrapasse uma gravação em curso; pagador é revalidado dentro da transação e precisa estar ativo.
- Em convites que exigem acesso restrito, a permissão da conta no mesmo tenant é revalidada **no aceite**, pois o papel do destinatário pode mudar após o envio.
- Transferência com histórico passa a fornecer cursores independentes `before_entry` e `before_document`, 50 itens por página, sinalizadores de continuação e histórico completo sob demanda, sem alterar valores do veículo atual.
- Foram adicionados cenários PHPUnit correspondentes. **Não se afirma execução nem sucesso de PHPUnit/Pint/MySQL nesta sessão.**
