# Fluxo Repasse — Etapas funcionais do produto

A evolução a partir da macro 27 passa a ser acompanhada por **etapas completas**, não por contagem de micro-PRs. Cada etapa deve entregar API, React no layout adquirido, permissões, persistência, documentação e regressão focada.

| Etapa | Resultado verificável | Código | Homologação |
| --- | --- | --- | --- |
| 1 — Acesso e cadastros | Autenticação JWT, papéis/permissões, usuários, pessoas/empresas e vínculos | Desenvolvido | Pendente de evidência integrada |
| 2 — Veículo 360 | Cadastro, origem, compra/venda, participantes, anexos privados, tarefas, checklist, notas, histórico e central operacional | Desenvolvido | Pendente de evidência integrada |
| 3 — Financeiro gerencial do veículo | Lançamentos, categorias editáveis, retificação, estorno, comprovantes privados vinculados, auditoria, filtros, relatório por categoria/mês e CSV | Macro 27 desenvolvida | Pendente de migrations/tests/browser |
| 4 — Cenários e revisão documental | Simulações, versões, rateios hipotéticos, pareceres, pacotes com hashes e ZIP | Desenvolvido sem pagamento real | Pendente de evidência integrada |
| 5 — Motor efetivo de repasses | Fato gerador, beneficiários, cálculo aprovado, comissões, tributos, alçadas, obrigações, liquidação, reembolso e conciliação | **Bloqueado por escopo de negócio não recuperado/aprovado** | Não iniciado |
| 6 — Homologação e entrega operacional | Cenários reais, QA Laravel/MySQL/React, segurança, backups, deploy/staging, migrações, mobile e aceite | Preparações técnicas parciais | Pendente |

**Regra:** não dividir mais uma etapa de negócio em melhorias isoladas de navegação/QA. Construir o módulo com CRUD completo, consultas, filtros, segurança e integração no ambiente comprado. Só abrir PRs em conjunto ao final da etapa, sem draft.

**Separação obrigatória:** Valores do Financeiro 360 são **gerenciais**, não prova de pagamento. Simulação/parecer não autoriza remessa de dinheiro. Etapa 5 depende de aprovação explícita de quem paga, quem recebe, valor/percentual, momento, estorno, taxa, tributos, regras de elegibilidade, PSP/banco, armazenamento e isolamento organizacional.

**Métricas:** "Etapa 3 codificada" não significa "etapa homologada" ou "% total do produto". O indicador antigo de 6/12 marcos técnicos (50%) pertence à fundação QA e não mede a conclusão financeira real.
