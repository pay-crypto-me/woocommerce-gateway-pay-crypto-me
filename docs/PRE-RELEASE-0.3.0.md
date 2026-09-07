# [PLAN — NOT STARTED] Pré-release 0.3.0 — projeção pública de status

**Estado:** documentação preparada; nenhum comando de release, tag, bump, upload ou merge foi
iniciado.

**Branch de trabalho:** `feat/payment-status-projection`

**Escopo:** publicar no Base o contrato versionado de projeção de status para o add-on Pro. A
validação manual do Base foi concluída e arquivada em
[`docs/archive/DONE-PUBLIC-PAYMENT-STATUS-PROJECTION-VALIDATION.md`](archive/DONE-PUBLIC-PAYMENT-STATUS-PROJECTION-VALIDATION.md).

## Conteúdo previsto para 0.3.0

- Registry de capabilities versionado (`contract_version=1`), com a capability Lightning CAS v1 e
  `onchain_confirmation_progress=0` explícito.
- Write-back Lightning por `order_id` + `invoice_id`, com compare-and-swap, outcomes explícitos e
  no máximo uma action para uma transição vencedora.
- Validação de limites e tipos antes do SQL, sem alterar o schema nem assumir confirmação on-chain.
- Compatibilidade de fallback para instalações Base anteriores, sem selecionar writer legado.

Ficam fora desta versão: webhook/polling, reconciliação, conversão fiat→sats, confirmação on-chain,
mudança de schema e implementação do add-on Pro. A RFC de dados de apresentação permanece um plano
separado e não entra neste release.

## Evidências já concluídas

- 420 testes unitários e 23 testes de integração MySQL aprovados na branch.
- Aceite manual de candidato, fallback 0.2.2, checkout On-Chain/Lightning, coexistência e upgrade
  aprovado em 2026-09-07; logs PHP vazios e fixtures removidas.
- O artefato de QA `releases/paycrypto-me-for-woocommerce-0.3.0-rc.27bed50.zip` foi usado apenas
  para validação e não é o pacote oficial.

## Gatilhos ainda necessários antes de publicar

- [x] O contrato foi entregue ao Pro em early access e o trabalho consumidor já avançou no
  repositório próprio. O harness e os planos detalhados do Pro ficam sob responsabilidade daquele
  repositório e não bloqueiam este release do Base.
- [ ] Revisar o diff final da branch e confirmar que somente o Base e a documentação aprovada estão
  no escopo.
- [ ] Executar o dry-run do release em uma árvore limpa:
  `./scripts/release.sh -v 0.3.0 --no-zip`.
- [ ] Executar o release real somente após o dry-run: `./scripts/release.sh -v 0.3.0`.
- [ ] Rodar `./scripts/check-docs-drift.sh`, a suíte automatizada e o Plugin Check via
  `docker compose exec -T wordpress wp --allow-root plugin check paycrypto-me-for-woocommerce --format=csv`,
  além da inspeção do ZIP, antes de qualquer upload.

## Regras de versão e histórico

Não editar manualmente o header do plugin, `Stable tag`, `readme.txt` ou locks para simular o bump.
O `release.sh` é a única fonte autorizada para atualizar a versão; este documento e o changelog são
preparação editorial. O pacote final deve ser gerado a partir do commit validado da branch, nunca do
ZIP de QA.
