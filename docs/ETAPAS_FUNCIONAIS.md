# Fluxo Repasse — Etapas funcionais do produto

A evolução a partir da macro 27 passa a ser acompanhada por **etapas completas**, não por contagem de micro-PRs. Cada etapa deve entregar API, React no layout adquirido, permissões, persistência, documentação e regressão focada.

| Etapa | Resultado verificável | Código | Homologação |
| --- | --- | --- | --- |
| 1 — Acesso e cadastros | Autenticação JWT, papéis/permissões, usuários, pessoas/empresas e vínculos | Desenvolvido | Pendente de evidência integrada |
| 2 — Veículo 360 | Cadastro, origem, compra/venda, participantes, anexos privados, tarefas, checklist, notas, histórico e central operacional | Desenvolvido | Pendente de evidência integrada |
| 3 — Financeiro gerencial do veículo | Lançamentos, categorias editáveis, retificação, estorno, comprovantes privados vinculados, auditoria, filtros, relatório por categoria/mês e CSV | Macro 27 desenvolvida | Pendente de migrations/tests/browser |
| 4 — Cenários e revisão documental | Fluxo 360 integrado por veículo: simulações, revisão segregada, estados/obsolescência, pacotes SHA/ZIP e CSV documental | Macro 28 integrada em código | PHPUnit/MySQL + build/mobile/E2E pendentes |
| 5 — Motor efetivo de repasses | Minutas comerciais por veículo: modelo congelado, condições declaradas, anexos, versões, matriz de pendências e simulações não executáveis; ainda faltam regras aprovadas para cobrança, obrigação, pagamento e conciliação | **Macro 30: módulo comercial codificado; motor de dinheiro real segue bloqueado por escopo** | Validação de migração/UI e políticas comerciais ainda pendentes |
| 6 — Homologação e entrega operacional | Dashboard com módulos reais, ficha 360 resiliente, diagnóstico estrutural e runner API/React/smoke local protegido | Macro 29 codificada (gates preparados) | Testes reais, backup/restore, QA visual e aceite ainda pendentes |

**Regra:** não dividir mais uma etapa de negócio em melhorias isoladas de navegação/QA. Construir o módulo com CRUD completo, consultas, filtros, segurança e integração no ambiente comprado. Só abrir PRs em conjunto ao final da etapa, sem draft.

**Separação obrigatória:** Valores do Financeiro 360 são **gerenciais**, não prova de pagamento. Simulação/parecer não autoriza remessa de dinheiro. Etapa 5 depende de aprovação explícita de quem paga, quem recebe, valor/percentual, momento, estorno, taxa, tributos, regras de elegibilidade, PSP/banco, armazenamento e isolamento organizacional.

**Métricas:** "Etapa 3 codificada" não significa "etapa homologada" ou "% total do produto". O indicador antigo de 6/12 marcos técnicos (50%) pertence à fundação QA e não mede a conclusão financeira real.


**Macro 31 (código em PR):** Central Comercial 360 unifica a carteira de minutas dos veículos com busca, indicadores de risco documental, filtros, CSV e link para edição, sob o Metronic. Etapa 5 executável permanece bloqueada por escopo; não há repasse real nem autorização bancária.


**Macro 32 em PR — Revisões comerciais:** tramitação documental por versão congelada, revisor segregado, atribuição, comentários, decisão e retirada, histórico encadeado e consulta Metronic. Etapa 5 executável continua sem escopo financeiro aprovado; QA pendente.
