# Macroentrega 54 — código final do MVP SaaS manual

## Encerramento do escopo codificado
A Macro 54 preserva as 12 decisões financeiras já aprovadas: controle de repasses
**manuais** vinculados à venda; banco único multiempresa; PWA nativo mobile-first;
aprovação por concessão (inclusive autor autorizado), parcelas e comprovantes
privados opcionais; cancelamento e estorno com justificativa; nenhum PSP/banco.

### Segurança e alçadas
- \`cases.manage + repasse.manage\`: preparar distribuição e registrar pagamento
  realizado fora da plataforma.
- \`repasse.approve\`: aprovar distribuição; estornar parcela; cancelar obrigação
  já aprovada ou rascunho de outro autor.
- O autor com \`repasse.manage\` pode cancelar somente o próprio rascunho.
- O estado da distribuição é novamente conferido dentro de transação antes de
  estornar ou cancelar. Os eventos e arquivos originais permanecem preservados.
- Repetição da mesma operação por \`request_key\` retorna contrato existente;
  chave com conteúdo divergente dá conflito.
- Operações concorrentes de pagamento/estorno/encerramento bloqueiam a mesma
  linha pai do veículo, antes de ler saldos/estados.
- Os percentuais explícitos não podem somar acima de 100% mesmo quando o
  arredondamento de uma base pequena ocultaria o excesso.

### Dados e fechamento
- Datas de venda realizada ou pagamento externo declarado não podem ser futuras.
- \`PATCH /repasse-cases/{id}\` não arquiva mais diretamente. Finalização
  exige \`POST /repasse-cases/{id}/complete\` com digest SHA de
  \`GET /closeout-review\` e ciência explícita das pendências.
- A revisão SHA também inclui cursores imutáveis dos eventos do veículo e dos
  repasses. Assim, dois movimentos de mesmo valor não deixam a revisão
  aparentemente inalterada.
- O arquivamento não quita as obrigações; relatórios externos não compensam
  saldos automaticamente e nunca entram no lucro gerencial.
- Administrador e usuário possuem papéis isolados por tenant; o cliente não
  fornece \`tenant_id\`.

### Compatibilidade do frontend
- Preservar Metronic Demo 1. Na ficha Veículo 360, mostrar ações apenas
  para quem possui a concessão correspondente.
- O formulário mantém dados/UUID após erro de escrita, mas os limpa assim que
  recebe sucesso do servidor, mesmo quando o refresh posterior falhar.
- O comprovante baixado recebe extensão coerente com MIME e só é servido via API
  autenticada; tamanho de upload limitado no navegador e na API.
- O cartão Financeiro sincroniza contrato selecionado, resumo e saldos.

### Matriz editorial de CÓDIGO (não de QA)
| Bloco | Peso | Escopo aprovado codificado |
|---|---:|---:|
| Identidade, cadastro e SaaS multiempresa | 15% | 15% |
| Veículo 360, compra e venda | 20% | 20% |
| Financeiro gerencial | 20% | 20% |
| Documentação, cenários e revisões | 10% | 10% |
| Motor de repasses manuais e alçadas | 30% | 30% |
| Dashboard, integração e UX operacional | 5% | 5% |
| **Total editorial de escopo codificado** | **100%** | **100%** |

**Esta matriz é uma avaliação de checklist de funcionalidades implementadas, NÃO é
cobertura de testes nem certificado de funcionamento.** O merge dos PRs da
Macro 54 e os testes executados podem revelar erros que exigirão correção;
se ocorrer, a classificação será revisada com evidência.

### Gate de validação posterior (não executado nesta macro)
No Mac com MySQL local e checkout API:
\`\`\`bash
composer install
php artisan migrate
php artisan test --filter=ControlledRepasseTest
php artisan test --filter=VehicleSaleCompletionTest
php artisan test
./vendor/bin/pint --test
\`\`\`
No checkout React:
\`\`\`bash
npm ci
npm run qa:controlled-repasse
npm run qa:financial-summary
npm run qa:mobile-layout
npm run qa:mvp-integrity
npm run build
npm run lint
\`\`\`
Também conferir manualmente os dois tenants e interfaces 320–1440 px nos
temas claro/escuro. Não executar \`migrate:fresh\` em banco com dados reais.
