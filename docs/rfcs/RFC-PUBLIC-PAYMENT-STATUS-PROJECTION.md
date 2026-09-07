# RFC — Contrato público de projeção de status de pagamento

**Status:** proposta para discussão

**Origem:** revisão independente P7d do PayCrypto.Me Pro

**Data:** 2026-09-06

## Resumo

Definir uma superfície pública, detectável e concorrente-segura para add-ons projetarem no Base o
resultado de uma confirmação que continua sendo autoritativa fora dele. A decisão deve esclarecer
se on-chain precisa de write-back, tornar Lightning atômico e impedir que uma capacidade publicada
seja removida silenciosamente em uma versão patch.

Esta RFC não transfere confirmação, polling, reconciliação ou regras WooCommerce para o Base. O
Base continua dono apenas de seus registros de apresentação; o add-on continua dono da decisão.

## Problema observado

Na versão 0.2.1, `PayCryptoMeDBStatementsService::update_transaction_confirmations()` expunha um
seam Pro e gravava `num_confirmations`, `amount_received` e `tx_hash`, emitindo
`paycryptome_bitcoin_status_changed`. A versão 0.2.2 removeu o método, a action, o teste e as três
colunas por migração. Documentação consumidora ainda tratava essa API como contrato público atual.

Lightning conserva `PayCryptoMeLightningDBStatementsService::update_status()`, mas sua transição é
“ler status antigo → atualizar → comparar valor lido”. Duas requisições podem ler `New` antes de
qualquer escrita e ambas emitir `paycryptome_lightning_status_changed` para `New → Settled`. Um
probe MySQL com dois processos e barreira antes dos `UPDATE`s reproduziu exatamente duas actions.

Os problemas são relacionados: não há contrato explícito sobre capability, ownership,
compatibilidade entre versões ou semântica concorrente da projeção.

## Objetivos

- Permitir que um consumidor descubra a capacidade sem `method_exists()` espalhado nem SQL
  privado.
- Definir uma única ownership: o add-on decide; o Base recebe, no máximo, uma projeção derivada.
- Fazer repetição e concorrência convergirem sem duplicar notificações de transição.
- Versionar o contrato de extensão separadamente dos detalhes internos de schema.
- Cobrir versão mínima e atual com testes de contrato reais.

## Não objetivos

- Implementar confirmação automática no Base.
- Fazer actions funcionarem como locks distribuídos.
- Restaurar por padrão colunas legadas sem um uso de UI/relatório definido.
- Exigir que todo método de pagamento exponha a mesma projeção.

## Decisão proposta

### 1. Capability explícita

Expor no Base uma API estável que descreva projeções suportadas, por exemplo:

```php
$capabilities = paycryptome_payment_status_projection_capabilities();
// ['lightning_invoice_status' => 1, 'onchain_confirmation_progress' => 0]
```

O formato concreto pode ser função, service registry ou interface; o requisito é que seja público,
documentado e versionado. Valor `0` significa capacidade ausente, não erro. Add-ons degradam sem
fatal quando uma capacidade não existe.

### 2. Lightning com compare-and-swap

`update_status()` deve mudar a linha atomicamente apenas quando o status atual difere do novo. A
action deve ser emitida somente pelo processo cujo `UPDATE` efetivamente realizou a transição. O
resultado precisa distinguir pelo menos:

- linha ausente/erro;
- transição aplicada;
- já estava no estado desejado.

Manter `bool` é possível por compatibilidade, mas uma API nova com resultado tipado evita confundir
“no-op idempotente” com falha. A action continua sendo notificação posterior, nunca lock.

### 3. Decisão explícita para on-chain

Antes de restaurar o seam 0.2.1, decidir se existe uma visão do Base que realmente precise de
confirmações, total recebido e txid.

- Se **não houver consumidor no Base**, declarar `onchain_confirmation_progress = 0`; o Pro mantém
  o estado próprio e atualiza apenas o pedido WooCommerce. Remover dos planos consumidores a
  promessa de write-back.
- Se **houver consumidor**, introduzir uma projeção nova, independente da tabela de identidade de
  endereço, com schema/versionamento próprios e atualização atômica. Não ressuscitar colunas
  legadas apenas para preservar o nome do método.

Em ambos os casos, `PayCryptoMeDBStatementsService` permanece responsável pelo endereço apresentado,
não pela decisão autoritativa do add-on.

### 4. Lifecycle do contrato

- Documentar APIs destinadas a extensões em um inventário versionado.
- Não remover uma capability publicada em PATCH. Deprecar por ao menos uma linha de compatibilidade
  ou lançar versão incompatível conforme a política SemVer do guia de release.
- Adicionar testes de contrato que instalem o Base com um consumidor sintético externo.
- Quando uma migração apagar dados de projeção, testar upgrade com linhas preenchidas e registrar a
  política de retenção, mesmo que a feature que os escreveu não esteja no runtime do Base.

## Alternativas consideradas

### Restaurar exatamente o método e as três colunas de 0.2.1

Preserva o consumidor antigo, mas duplica estado de confirmação dentro de uma tabela cujo papel
atual é identidade/apresentação do endereço. Também não resolve a corrida de notificação. Não é a
opção recomendada sem um consumidor Base concreto.

### Não oferecer nenhum write-back

É coerente para on-chain se o Base não exibe a informação, mas Lightning já mantém status de invoice
e precisa continuar convergindo. A ausência total também não resolve governança de compatibilidade.

### Deixar toda serialização no add-on

O add-on precisa serializar seus efeitos autoritativos de qualquer forma, mas uma API Base que
notifica duas vezes a mesma transição permanece perigosa para qualquer listener. O Base deve garantir
atomicidade da mutação que ele próprio expõe.

## Critérios de aceite futuros

1. Dois processos tentam `New → Settled`; a linha termina `Settled` e exatamente uma action é
   observada em armazenamento compartilhado.
2. Repetição sequencial retorna o resultado documentado e não emite nova action.
3. Linha ausente não emite action e é distinguível de no-op.
4. Consumidor sintético descobre on-chain presente/ausente sem fatal nas versões suportadas.
5. Se on-chain for implementado, duas atualizações concorrentes não duplicam transição e upgrade de
   schema preserva a política de histórico aprovada.
6. A matriz Base mínimo/atual roda em CI ou no gate obrigatório de release.

## Impacto nos consumidores

Após a decisão, o Pro deve atualizar `PLAN-BASE-REUSE.md` e `IMPLEMENTATION-PLAN.md`, adaptar o
harness P7d e manter M6/M7 pendentes até sua própria implementação. Capability ausente deve resultar
em “sem projeção no Base”, nunca em confirmação inventada, acesso a SQL privado ou fatal.
