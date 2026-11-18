# Macro 53 — consolidação financeira e encerramento coerente

## Princípio contábil
- Não somar repasse externo antigo a parcelas de obrigação aprovada para declarar «total pago».
- Repasses controlados aprovados: base autorizada menos parcelas informadas não estornadas do MESMO contrato. Rascunhos e cancelados não geram saldo exigível.
- Declarações antigas diretas e provenientes de proposta são mostradas SEPARADAMENTE, sem baixa, correspondência presumida ou reclassificação.
- Quando há obrigação aprovada e relato externo ativo, aviso de **possível sobreposição** ANTES da próxima parcela, sem afirmar duplicidade. Resultado gerencial de compra/venda continua inalterado.
- Encerramento é arquivamento operacional, não quitação: pendências de repasse exigem ciência, sem bloquear a conclusão após confirmação explícita.

## API, integridade e escopo
- GET autenticado \`/api/v1/repasse-cases/{case}/settlements/overview\` (cases.manage + repasse.manage).
- Serviço reutilizado pela revisão do encerramento, sujeito a mesma chave SHA de concorrência, dentro da transação que bloqueia o veículo.
- Impedir alteração de datas/valores comerciais quando já existe distribuição aprovada. Necessário estornar parcelas e cancelar antes de corrigir.
- Não desvincular participante usado como pagador ou favorecido em distribuição ativa, seja rascunho ou aprovada.
- Nenhuma nova tabela, job, modal de pagamento ou gateway; persistência e isolamento da Macro 52 preservados.
- JWT/tenant da sessão; consultas e JOINs por TenantDB. Sem \`tenant_id\` vindo do cliente.

## QA/implantação
- Funcional: dois tenants, dois veículos, aprovar, lançar parcial, estornar, cancelar, relato antigo separado, fechamento com SHA obsoleto e ciência.
- Testes unitários/feature no PR não equivalem a execução de PHPUnit, Pint, MySQL ou testes visuais. Sem homologação declarada.
- Manter Metronic Demo 1, PWA responsivo e nenhuma tela extra. Revisar integração após merge de API antes do React.
