# Plano — Add-on Premium para PayCrypto.Me for WooCommerce

> Plano de implementação aprovado para o add-on premium separado. Referência para agentes e
> humanos que forem construir o plugin pago. A fonte de verdade da arquitetura do plugin **base**
> continua sendo o `CLAUDE.md` (raiz do repo).

## Contexto

O plugin base (`paycrypto-me-for-woocommerce`, v0.1.0, GPL-3.0, no WordPress.org) foi
desenhado desde o início para receber um **add-on premium separado**. O `CLAUDE.md` reserva
explicitamente duas capacidades para esse add-on (confirmação async via webhook/polling e
fiat→sats), e o próprio código marca campos de settings como `paycrypto-premium-field`
desabilitados ("ships in the upcoming PayCrypto.Me Premium add-on") em **ambos** os gateways.

**Objetivo:** construir um plugin WordPress separado que ativa essas features conectando-se
aos hooks/serviços do base — sem `if (is_premium())` no repo base. O add-on é a primeira coisa
a efetivamente *chamar* seams que hoje existem mas não têm consumidor (ex.: `update_status()`
do Lightning não tem nenhum caller em produção).

**Decisões do usuário (já tomadas):**
- **Repositório:** novo repo separado (ex. `paycrypto-me-premium`), independente do monorepo base.
- **Escopo v1:** tudo que o código marca como premium — (A) confirmação async Lightning,
  (B) fiat→sats, (C) rastreio de confirmações on-chain, (D) auto-expiração de pedidos.
- **O plugin base NÃO será mais tocado.** Os dois seams necessários já entraram na 0.1.0 e estão
  verificados (§2). Essa é a razão de o add-on existir como projeto separado, e vale como
  restrição dura em todas as decisões abaixo — inclusive na de licenciamento.
- **Licenciamento: Freemius**, com o SDK **apenas dentro do add-on**. Decidido em 2026-08-08 —
  ver §8, que substitui o "adiado" das versões anteriores deste plano.
- **Canais de venda:** dois checkouts, um sistema de licença — Freemius (cartão/PayPal, mundo
  todo) + loja WooCommerce própria aceitando **Bitcoin on-chain** via o próprio plugin. Ver §8.4.
- **Resiliência de APIs externas (requisito transversal):** **toda** consulta a API pública
  (câmbio fiat→sats, block explorers, feeds de preço) passa por **interface com múltiplos
  providers**, com **retry por provider** e **failover automático** para o próximo provider
  disponível quando um estiver fora no momento da consulta. Ver §3 — é a espinha dorsal dos
  módulos B e C.

---

## 1. Arquitetura do add-on

- **Plugin WordPress independente**, distribuído **fora do WP.org** (WP.org só aceita GPL grátis).
- **Namespace próprio:** `PayCryptoMe\WooCommerce\Premium` (o base usa `PayCryptoMe\WooCommerce`
  sem sub-namespaces + classmap; escolher raiz própria evita colisão de classe).
- **Autoload:** Composer **classmap** (mesmo padrão do base — `composer.json` do add-on com
  `"autoload": { "classmap": ["includes/"] }`). Reusa classes do base por FQN, já carregadas
  pelo `vendor/autoload.php` do base.
- **Header do plugin:** `Requires Plugins: paycrypto-me-for-woocommerce` (WP 6.5+; o base está
  no WP.org, então o header força a instalação da dependência) + `Requires: woocommerce`.
- **SDK do Freemius vendored** em `src/trunk/freemius/`, com `pcm_premium_fs()` inicializado no
  **topo do entrypoint**, antes do dependency guard — o SDK precisa hookar ativação/desativação e
  o canal de update cedo no ciclo do WordPress. O guard e o registro de módulos continuam no
  `plugins_loaded` prioridade 20. Ver §8.
- **Dependency guard** (bootstrap em `plugins_loaded` **prioridade 20**, depois do base que roda em 10):
  ```php
  if (!class_exists('\PayCryptoMe\WooCommerce\WC_PayCryptoMe')
      || version_compare(\PayCryptoMe\WooCommerce\WC_PayCryptoMe::VERSION, '0.1.0', '<')) {
      // admin_notice + bail
  }
  ```
  `WC_PayCryptoMe::VERSION` é a fonte de verdade da versão do base
  (`src/trunk/paycrypto-me-for-woocommerce.php:38`). Os seams de enablement (§2) fazem parte da
  própria **0.1.0** (ainda em pré-release), então o guard só exige o base presente em ≥ 0.1.0.
- **Reuso do `HttpClientContract` do base:** todo HTTP (providers, nós, webhook re-verify) passa
  pelo `WpHttpClient` do base (`includes/http/class-wp-http-client.php`) — uniformiza logging e
  torna tudo mockável com o `FakeHttpClient` já existente nos testes.
- **Composer do add-on é leve:** não precisa das libs Bitcoin forked (`lucas-rosa95/bitcoin`) —
  o add-on **consome** endereços/invoices já derivados e persistidos pelo base, e faz HTTP a
  block explorers / nós / feeds. Só `phpunit` como dev-dep.

### Estrutura de diretórios do novo repo
```
paycrypto-me-premium/
├── src/trunk/
│   ├── paycrypto-me-premium.php          ← entrypoint: pcm_premium_fs() → guard → bootstrap
│   ├── composer.json                     ← classmap autoload, namespace Premium
│   ├── freemius/                         ← SDK do Freemius (vendored, ~1.5MB) — §8
│   ├── includes/
│   │   ├── class-premium-bootstrap.php    ← singleton, registra módulos (espelha WC_PayCryptoMe)
│   │   ├── providers/                     ← §3 CAMADA RESILIENTE (multi-provider + retry + failover)
│   │   │   ├── contracts/
│   │   │   │   ├── ExchangeRateProviderContract.php
│   │   │   │   ├── BlockchainExplorerProviderContract.php
│   │   │   │   └── ResilientProviderContract.php   ← name()/is_available() comum
│   │   │   ├── class-provider-chain.php            ← executor genérico: ordena, retry, failover
│   │   │   ├── class-provider-health-tracker.php   ← cooldown de provider indisponível (transient)
│   │   │   ├── exchange/                            ← impls de câmbio
│   │   │   │   ├── class-coingecko-rate-provider.php
│   │   │   │   ├── class-kraken-rate-provider.php
│   │   │   │   ├── class-binance-rate-provider.php
│   │   │   │   └── class-mempool-space-rate-provider.php
│   │   │   └── explorer/                            ← impls de block explorer
│   │   │       ├── class-mempool-space-explorer-provider.php
│   │   │       ├── class-blockstream-esplora-provider.php
│   │   │       └── class-blockcypher-explorer-provider.php
│   │   ├── lightning/
│   │   │   ├── class-webhook-controller.php     ← REST paycrypto-me/v1/webhook (BTCPay push)
│   │   │   ├── class-lnd-status-poller.php       ← cron: get_invoice_status() por invoice pendente
│   │   │   └── class-lightning-status-listener.php ← on paycryptome_lightning_status_changed → payment_complete
│   │   ├── conversion/
│   │   │   ├── class-fiat-to-sats-converter.php   ← usa ProviderChain<ExchangeRate> + cache
│   │   │   └── class-lnd-amount-filter.php        ← filtro paycryptome_lightning_lnd_invoice_args
│   │   ├── onchain/
│   │   │   ├── class-onchain-confirmation-poller.php ← usa ProviderChain<Explorer>
│   │   │   └── class-onchain-status-listener.php  ← on paycryptome_bitcoin_status_changed → payment_complete
│   │   ├── expiry/
│   │   │   └── class-order-expiry-cron.php        ← cron: cancela pedidos vencidos (ambos gateways)
│   │   ├── settings/
│   │   │   ├── class-lightning-settings-injector.php ← filtro woocommerce_settings_api_form_fields_paycrypto_me_lightning
│   │   │   └── class-onchain-settings-injector.php   ← filtro ..._paycrypto_me (+ ordem/toggle de providers)
│   │   ├── cron/class-cron-scheduler.php          ← registra schedules na ativação, limpa no deactivate
│   │   └── license/                        ← §8.3 — ponto único de troca de plataforma
│   │       ├── LicenseManagerContract.php
│   │       ├── class-freemius-license-manager.php ← delega p/ can_use_premium_code()
│   │       └── class-stub-license-manager.php     ← sempre ativo (dev/testes)
│   └── tests/                             ← espelha tests/_support do base (shims WP/WC + FakeHttpClient)
├── scripts/release.sh                     ← adaptado do base (build/zip; sem submissão WP.org)
└── docs/
```

---

## 2. Enablement do plugin base — ⛔ ENCERRADO, não editar o base

> **Status: FEITO e fechado.** Seams #1 e #2 estão **implementados, testados e shipados na 0.1.0**
> (verificado em 2026-08-08: `class-lnd-rest-invoice-service.php:31` para o `value`;
> `pay-crypto-me-db-statements-service.php:240` e `:276` para `update_transaction_confirmations()`
> \+ `do_action`; cobertos por `tests/phpunit/unit/OnchainConfirmationsUpdateTest.php`). Seam #3 foi
> **dispensado** — o add-on enumera pedidos on-chain pendentes via `wc_get_orders()`, sem helper no
> base.
>
> **Qualquer solução daqui pra frente deve caber sem editar o repo base.** Isso vale inclusive para
> licenciamento e monetização: é o motivo de o SDK do Freemius ficar só no add-on (§8.1), abrindo
> mão do funil in-dashboard. Se um agente ou humano concluir que precisa mexer no base, a conclusão
> certa é procurar outro desenho — não abrir exceção.

Contexto histórico da decisão, mantido para referência: seams pequenos precisavam existir no base
para o add-on plugar **sem editar core depois**. Todos são **no-op para usuários free** (o valor só
é setado pelo add-on), e por isso entraram na própria 0.1.0, sem bump de versão.

| # | Arquivo base | Mudança | Por quê |
|---|---|---|---|
| 1 | `includes/services/class-lnd-rest-invoice-service.php` (`create_invoice`, ~linha 24) | Incluir `'value' => (string)(int)$args['value']` no `$body` **quando `isset($args['value'])`** | Hoje o invoice lnd manda só `memo`+`expiry` (zero-amount). Sem isso, o filtro fiat→sats **persiste** `amount_sats` mas **não força** o valor no invoice lnd. |
| 2 | `includes/services/pay-crypto-me-db-statements-service.php` | Novo método público `update_transaction_confirmations(int $order_id, int $num_confirmations, string $amount_received, string $tx_hash): bool` que faz `UPDATE` na `paycrypto_me_bitcoin_transactions_data` e dispara `do_action('paycryptome_bitcoin_status_changed', $order_id, $old, $new)` **só em transição real** | On-chain não tem método de update nem action de status. Espelha o precedente `PayCryptoMeLightningDBStatementsService::update_status()` (linha 121-146) / `paycryptome_lightning_status_changed`. |
| 3 | (opcional) mesmo arquivo | `get_pending_onchain_orders()` / lista de endereços pendentes | Conveniência p/ o poller. **Alternativa sem base:** o add-on faz sua própria query na tabela — decidir na implementação. |

**Não precisam de mudança no base:**
- **Injeção/habilitação de campos de settings** — o WooCommerce core já aplica
  `apply_filters('woocommerce_settings_api_form_fields_' . $id, ...)`. O add-on remove o
  `disabled` e injeta a URL real do webhook via esse filtro (§7).
- **Seams Lightning** (`get_by_invoice_id`, `get_by_order_id`, `update_status`,
  `paycryptome_lightning_status_changed`, `get_invoice_status`) — **já existem e estão prontos.**

> Registrar as duas novas capacidades (action `paycryptome_bitcoin_status_changed`, arg `value`
> no lnd) na tabela de hooks do `CLAUDE.md` e em `docs/`.

---

## 3. Camada de providers resilientes (multi-provider + retry + failover) — REQUISITO TRANSVERSAL

Toda consulta a API pública externa é encapsulada atrás de um **contract** e executada por um
**`ProviderChain`** genérico que ordena providers, tenta com retry, e **faz failover** para o
próximo provider quando o atual falha ou está indisponível. Espelha o padrão contract+strategy
do base (`LightningInvoiceServiceContract`, factories em `includes/strategies/`).

### Contracts (uma interface por tipo de dado, N implementações cada)
- `ResilientProviderContract` — base comum: `name(): string`, `is_available(): bool`.
- `ExchangeRateProviderContract extends Resilient` — `get_btc_rate(string $fiat_currency): float`
  (lança exceção em falha). Impls: **CoinGecko, Kraken, Binance, mempool.space** (ordem
  configurável).
- `BlockchainExplorerProviderContract extends Resilient` —
  `get_address_status(string $address, string $network): AddressStatus`
  (`{confirmations, amount_received_sats, tx_hash}`). Impls: **mempool.space, Blockstream Esplora,
  BlockCypher** (mainnet + testnet).

### `ProviderChain` (executor de failover, genérico e reusável)
```php
$chain = new ProviderChain($orderedProviders, $healthTracker, $maxRetriesPerProvider);
$rate  = $chain->run(fn($p) => $p->get_btc_rate('USD'));  // 1º sucesso vence
```
Comportamento:
- Itera providers **na ordem configurada**; **pula** os marcados indisponíveis pelo
  `ProviderHealthTracker` (a menos que o cooldown tenha expirado).
- Por provider: **retry** com backoff (ex. 2-3 tentativas). Falhou todas → registra no
  health-tracker e **avança para o próximo provider** (failover).
- Todos falharam → lança `AllProvidersUnavailableException` (o caller decide o fallback: usar
  último valor em cache / adiar via cron / registrar log).
- Cada tentativa passa pelo `HttpClientContract` do base (mockável em teste).

### `ProviderHealthTracker` (circuit-breaker leve)
- Ao falhar, marca o provider indisponível por um cooldown (`set_transient`, ex. 5 min) → a chain
  o **pula** nas próximas consultas até o cooldown expirar, evitando martelar um endpoint morto.
- `is_available()` do provider consulta o tracker.

### Configuração (via settings injetados, §7)
- Ordem/enable dos providers de câmbio e de explorer, chaves de API opcionais (BlockCypher etc.),
  e `network` (mainnet/testnet) — lidos por `$gateway->get_option(...)`.

### Testes (§9)
- `ProviderChain`: 1º provider falha → 2º responde (failover); todos falham → exceção; provider em
  cooldown é pulado. Com `FakeHttpClient` devolvendo erro/ok por provider.

---

## 4. Módulo A — Confirmação async Lightning

**BTCPay (push):**
- Registrar rota REST `paycrypto-me/v1/webhook` em `rest_api_init` (**não existe no base** — greenfield).
- Handler: valida HMAC do header BTCPay com o secret `btcpay_webhook_secret`
  (`$gateway->get_option('btcpay_webhook_secret')`); extrai `invoiceId`; mapeia p/ pedido via
  `PayCryptoMeLightningDBStatementsService::get_by_invoice_id($invoice_id)`
  (`class-paycrypto-me-lightning-db-statements-service.php:65`).
- **Re-verificação server-side obrigatória** (nunca confiar só no payload): instanciar
  `new BtcpayInvoiceService(new WpHttpClient(), $gateway)` e chamar `get_invoice_status($invoice_id)`
  → `LightningInvoiceStatusResponse{paid,status}`. Se `paid`, chamar
  `$db->update_status($order_id, 'Settled')`.

**lnd (polling — lnd não tem webhook simples):**
- `class-lnd-status-poller.php` roda em cron (schedule custom, ex. cada 60-120s). Para cada
  invoice lnd em status não-final: `LndRestInvoiceService::get_invoice_status($invoice_id)`
  (`class-lnd-rest-invoice-service.php:50`) → se `paid` (`state === 'SETTLED'`),
  `$db->update_status($order_id, 'SETTLED')`.
- Reusa o `HttpClientContract` do base (`WpHttpClient`) e o `request_with_cert()` do serviço lnd
  (trata TLS/macaroon). Capturar `PayCryptoMePaymentException` (erro transitório do nó não pode
  matar o tick do cron). *(O nó lnd é infra do lojista, não "API pública" — não entra na chain
  de providers do §3; a resiliência aqui é o próprio loop de polling.)*

**Fechamento do pedido (ponto único, idempotente):**
- `class-lightning-status-listener.php`: `add_action('paycryptome_lightning_status_changed', fn, 10, 3)`.
  Mapeia status do nó → transição WC: se pago e `!$order->is_paid()` → `$order->payment_complete()`.
- A action **só dispara em transição real** (`update_status` linha 141-143), então webhook e
  polling convergem no mesmo listener sem dupla-baixa. **Nenhum consumidor no core hoje** — sem
  risco de double-handling.

---

## 5. Módulo B — fiat→sats (usa a chain de câmbio do §3)

- `class-fiat-to-sats-converter.php`: converte `$order->get_total()` + `$order->get_currency()`
  em sats usando `ProviderChain<ExchangeRateProviderContract>` (§3) — CoinGecko → Kraken →
  Binance → mempool.space com retry/failover. Cache `set_transient` (ex. 60s) da taxa; em
  `AllProvidersUnavailableException`, fallback para o último valor em cache (ou adia).
- `class-lnd-amount-filter.php`: `add_filter('paycryptome_lightning_lnd_invoice_args', fn, 10, 3)`
  (recebe `$args, $order, $gateway` — `abstract-class-lightning-processor.php:30`). Seta
  `$args['amount_sats']` (persistido na coluna via `insert_invoice`, `abstract-class-lightning-processor.php:63`)
  **e** `$args['value']` (força o valor no invoice lnd — depende do enablement §2-#1).
- **BTCPay:** já manda `amount`+`currency` fiat e converte internamente
  (`class-btcpay-invoice-service.php:22-23`) — **não precisa de enforcement fiat→sats**. Para
  BTCPay o add-on pode, opcionalmente, só *exibir* o equivalente em sats (reusa a mesma chain).

---

## 6. Módulo C — Rastreio de confirmações on-chain (usa a chain de explorer do §3)

- `class-onchain-confirmation-poller.php` (cron): para cada pedido on-chain pendente, lê o
  `payment_address` da tabela `paycrypto_me_bitcoin_transactions_data`
  (via `PayCryptoMeDBStatementsService::get_by_order_id()`), consulta o endereço via
  `ProviderChain<BlockchainExplorerProviderContract>` (§3) — mempool.space → Esplora →
  BlockCypher com retry/failover — obtendo `{confirmations, amount_received_sats, tx_hash}`.
- Chama o novo `update_transaction_confirmations()` (enablement §2-#2). O `confirmations_required`
  por pedido vem da meta `_paycrypto_me_payment_number_confirmations`
  (`class-wc-gateway-paycrypto-me.php:234`).
- `class-onchain-status-listener.php`: `add_action('paycryptome_bitcoin_status_changed', fn)` —
  quando `confirmations >= required` **e** `amount_received` está dentro da tolerância abaixo →
  `$order->payment_complete()`. Mesmo padrão idempotente do Lightning.

> **Requisito — subpagamento e volatilidade:** entre a criação do pedido e a chegada da
> transação passa-se de minutos a horas, e o preço do BTC se move nesse intervalo. `amount_received`
> pode ficar abaixo do esperado **sem má-fé do comprador**. Comparar com `>=` estrito contra o
> valor cheio trava esses pedidos em `pending` para sempre. Portanto:
> - **Tolerância configurável** (default sugerido: aceitar `>= 98%` do esperado, absorvendo a
>   diferença) — campo injetado pelo `class-onchain-settings-injector.php` (§7).
> - **Fila de revisão manual** para subpagamento fora da tolerância: nota no pedido + status
>   explícito (ex. `on-hold` com order note descrevendo esperado vs. recebido). **Nunca** deixar
>   preso em `pending` silenciosamente — o lojista precisa saber que existe uma decisão a tomar.
> - **Sobrepagamento** completa normalmente e registra order note com a diferença.
>
> Isso aparece primeiro na loja própria (§8.4), que é o primeiro cliente do módulo C.

> **Limitação documentada (F5):** o rastreio automático on-chain cobre **apenas endereços
> derivados** (xpub/ypub/zpub), que geram linha em `paycrypto_me_bitcoin_transactions_data`.
> Pagamento a **endereço estático** não gera linha (o processador retorna antes de `insert_address()`,
> [class-bitcoin-payment-processor.php:49-54](../src/trunk/includes/processors/class-bitcoin-payment-processor.php#L49-L54)),
> então `get_by_order_id()` retorna `null` e o poller **ignora naturalmente** esses pedidos —
> endereço estático permanece **confirmação manual**, por design. Sem código extra no add-on nem
> mudança de schema no base.

---

## 7. Módulo D — Auto-expiração + injeção de settings

**D. Auto-expiração** (`class-order-expiry-cron.php`, cron): pedidos `pending` além do
`_paycrypto_me_payment_expires_at` → `$order->update_status('cancelled'/'failed', ...)`. Vale para
os dois gateways. Toggle habilitado via injeção de settings.

**Exibição da expiração/valor (via filtros F1 — já no base 0.1.0):** para mostrar a contagem de
expiração on-chain (que o base fixa `show_expiry => false`) e o valor cripto no Lightning (fixo
`null`), o add-on usa `add_filter('paycryptome_order_display_args', fn($args,$order,$gateway))` para
virar `show_expiry => true` / setar `crypto_amount` antes do `PaymentDisplayDataBuilder`, e/ou
`paycryptome_order_display_data` para ajustar campos já computados. Sem esses filtros a camada de
exibição era 100% fechada.

**Injeção de settings (habilitar os campos hoje `disabled` + config de providers):**
- **A/D Lightning** — `class-lightning-settings-injector.php`:
  `add_filter('woocommerce_settings_api_form_fields_paycrypto_me_lightning', fn)`. Remove
  `custom_attributes['disabled']` de `btcpay_webhook_secret`; injeta a URL real
  `rest_url('paycrypto-me/v1/webhook')` na descrição do campo `webhook_info`
  (`class-wc-gateway-paycrypto-me-lightning.php:159-174`); adiciona campo de intervalo de polling
  lnd e **ordem/enable dos providers de câmbio** (§3).
- **B/C On-chain** — `class-onchain-settings-injector.php`:
  `add_filter('woocommerce_settings_api_form_fields_paycrypto_me', fn)`. Habilita
  `payment_number_confirmations` e o timeout de expiração (`class-wc-gateway-paycrypto-me.php:154-165`);
  adiciona **ordem/enable dos block explorers + chaves de API opcionais** (§3).

**Infra de cron** (`class-cron-scheduler.php`): registrar `wp_schedule_event` na **ativação** do
add-on e limpar (`wp_clear_scheduled_hook`) na **desativação**. Um schedule custom único dispara os
três pollers (lnd status, on-chain confirmations, expiração). BTCPay é push (sem cron).

---

## 8. Licenciamento, monetização e canais de venda

**Decidido em 2026-08-08.** Substitui integralmente o "licenciamento adiado" das versões
anteriores deste plano.

### 8.1 Plataforma: Freemius, SDK apenas no add-on

**Freemius** é a plataforma de licenciamento, cobrança e distribuição de updates.

**Custo:** 4,7% base + 2,3% de sobretaxa WordPress = **7% + gateway (~3%) ≈ 10,5% all-in**, sem
mensalidade e sem custo de setup — só cobra sobre o que vende, então custo zero até a primeira
venda. Os descontos progressivos só começam acima de **$50k/mês** de receita bruta; na prática é
7% fixo por um bom tempo.

**Por que Freemius e não uma opção mais barata:** é a única plataforma que entrega *merchant of
record* **e** canal de auto-update nativo do WordPress no mesmo pacote, com zero código próprio.

| | Taxa | MoR | Canal de update WP |
|---|---|---|---|
| **Freemius** | 7% + gateway | ✅ | ✅ SDK nativo |
| Fungies.io | ~5,9% | ✅ | ❌ |
| Polar / Dodo | ~4–5% | ✅ | ❌ |
| Paddle | 5% + $0,50 | ✅ | ❌ (nem licença) |
| SureCart | 2,9% ou $179–499/ano | ❌ | ✅ |
| WP Licenser / ChargePanda / EDD | 0% plataforma | ❌ | ✅ |

Os MoR mais baratos obrigam a construir e **hospedar** o endpoint de update — se cair, ninguém
atualiza. Os de update nativo não são MoR, e aí a compliance fiscal de cada jurisdição volta pro
vendedor: vendendo do Brasil pro mundo, isso significa VAT OSS na UE e nexus de sales tax nos
EUA, um a um. **O MoR é justamente o que viabiliza a internacionalização** — o Freemius é o
vendedor legal em todas as jurisdições, recolhe imposto, emite invoice e absorve
refund/chargeback/fraude. Lemon Squeezy foi descartado apesar do preço: está sendo absorvido pelo
Stripe Managed Payments desde a aquisição de 2024, com migração anunciada em jan/2026 — não se
constrói infraestrutura de licença sobre isso.

**O SDK do Freemius fica SÓ no add-on. O plugin base não é tocado** (§2). O custo consciente
dessa escolha é abrir mão do funil in-dashboard do Freemius — upgrade com um clique, trial sem
cartão, opt-in de e-mail dos usuários free — que exigiria o SDK dentro do plugin free no WP.org.
O funil que resta já está shipado na 0.1.0: os campos `paycrypto-premium-field` desabilitados em
ambos os gateways, com a mensagem "ships in the upcoming PayCrypto.Me Premium add-on".

**Lock-in a ter em mente:** o custo real do Freemius não é a taxa, é o canal de update. Ao migrar
de plataforma um dia, quem não atualizar antes fica órfão apontando pro Freemius. O
`LicenseManagerContract` (§8.3) mantém a troca localizada no código, mas não resolve a base
instalada. **Reavaliar a partir de ~$25–30k/ano de receita bruta**, quando trocar por um MoR
barato + endpoint próprio passa a economizar o suficiente para pagar o build e a operação.

**Setup operacional:**
- Cadastrar como **produto standalone**, não como "add-on" do Freemius — o tipo "add-on" deles
  pressupõe que o plugin pai carrega o SDK, o que não é o caso aqui. O vínculo com o base é feito
  pelo header `Requires Plugins: paycrypto-me-for-woocommerce` do lado do WordPress.
- Confirmar o **método de payout para o Brasil** (PayPal/Wise/banco, saldo mínimo $100) antes de
  investir no setup. *Payout é apenas como o dinheiro chega até você — não limita para quem se
  pode vender; o alcance global vem do MoR.*
- Receita de exportação de software (PF vs PJ no Simples) é questão de contador, fora do escopo
  técnico, mas resolver antes de faturar.

### 8.2 O que a licença controla — e o que NUNCA controla

**Regra:** a licença controla **updates, suporte e features novas**. Nunca o runtime crítico de
pagamento.

Licença vencida derrubando o poller de confirmação significa: o pagamento Bitcoin do cliente do
lojista chega, ninguém confirma, o pedido fica preso em `pending` e o lojista recebeu dinheiro sem
baixa. O lojista culpa o plugin, não a licença. Para um add-on de **pagamento**, isso é
inaceitável.

| Sempre ativo (independe de licença) | Gated pela licença |
|---|---|
| Webhook BTCPay + `class-lightning-status-listener.php` (§4) | Canal de update |
| Poller lnd (§4) | Features novas lançadas após o vencimento |
| Poller de confirmação on-chain + listener (§6) | Suporte |
| `payment_complete()` dos dois listeners | Auto-expiração (§7) — desligar não perde dinheiro |

O comportamento padrão do Freemius já casa com isso: em ciclo anual, `can_use_premium_code()`
continua `true` depois do vencimento — para de atualizar, não para de funcionar. Não é preciso
lutar contra o SDK, basta **não** implementar kill-switch.

Isso também é o mais defensável sob GPL: o add-on chama classes do base GPL-3.0-or-later, logo é
obra derivada e essencialmente precisa ser GPL. Gatear *updates e suporte* é prática consolidada
no ecossistema WordPress; kill-switch de funcionalidade é terreno cinzento.

### 8.3 `LicenseManagerContract` — ponto único de troca

Um adapter por provedor, para que trocar de plataforma seja arquivo novo e não refatoração. Cobre
os dois eixos porque no Freemius licença e update são o mesmo SDK, enquanto nas alternativas são
coisas separadas:

```php
namespace PayCryptoMe\WooCommerce\Premium\License;

interface LicenseManagerContract
{
    public function is_active(): bool;                // gate de features novas (§8.2)
    public function register_update_channel(): void;  // no-op no Freemius (o SDK já faz)
}
```

- `FreemiusLicenseManager` — `is_active()` delega para `pcm_premium_fs()->can_use_premium_code()`;
  `register_update_channel()` é no-op.
- `StubLicenseManager` — sempre `true`, sem canal de update. Uso em desenvolvimento e testes.

O bootstrap registra os módulos da coluna "sempre ativo" (§8.2) **incondicionalmente**, e só
consulta `is_active()` para o que está na coluna gated. Não existe
`if (is_active()) { registra tudo }`.

### 8.4 Canais de venda: Freemius (cartão) + loja própria (Bitcoin on-chain)

Dois checkouts, **um** sistema de licença. O Freemius é a única fonte de verdade da validade da
licença nos dois canais — o cliente tem a mesma experiência de ativação e auto-update
independente de como pagou.

```
Cartão/PayPal → checkout Freemius → licença → SDK ativa → updates
Bitcoin       → loja Woo própria → PayCrypto.Me (on-chain) → pedido pago
              → API Freemius cria/estende licença → e-mail → SDK ativa → updates
```

**Por que o canal Bitcoin existe:** (1) coerência — vender um plugin de pagamento em Bitcoin
aceitando só cartão é contraditório; (2) é o canal de **maior margem** (0% de taxa contra ~10,5%,
e sem chargeback); (3) a loja vira demo pública ao vivo do produto, rodando no próprio plugin.

**Stack da loja: `Woo + PayCrypto.Me + PayCrypto.Me Premium`.** Sem WooCommerce Subscriptions —
ver §8.5.

**Rail: on-chain, não Lightning.** On-chain **não exige infraestrutura nenhuma** — um xpub de
hardware wallet, sem nó, sem canal, sem liquidez inbound, não-custodial. Lightning exigiria operar
um nó com liquidez de entrada, que custa dinheiro (abertura de canal é transação on-chain) e vira
responsabilidade contínua; para vender licença anual não se paga. Fee on-chain em período calmo é
irrelevante no ticket dessa faixa, e 10–30 min de confirmação para receber uma licença por e-mail
é aceitável. *Nota de produto: o atrito que fez descartar o Lightning aqui é exatamente o cálculo
que o lojista-cliente vai fazer — o gateway on-chain é o que carrega a adoção, e o Lightning é
diferencial para quem já tem nó. Isso deve influenciar onde se investe polimento e documentação.*

**Endpoints do Freemius:**

| Momento | Endpoint | Efeito |
|---|---|---|
| Aquisição | `POST /products/{product_id}/plans/{plan_id}/pricing/{pricing_id}/licenses.json` (migration source = `WC`) | Cria licença + assinatura |
| Renovação | `POST /products/{product_id}/subscriptions/{subscription_id}/payments.json` | Registra pagamento externo e **estende** a licença |

A extensão **soma ao saldo restante** em vez de resetar (renovar 5 dias antes do vencimento com
ciclo anual = 370 dias), então renovar cedo não penaliza o cliente.

**Código necessário na loja** — plugin próprio do site, fora deste repo **e** fora do base: hook de
pedido pago → chamada da API do Freemius → e-mail com a chave; mais o lembrete de renovação (§8.5).

**Opcional, não v1:** emitir a licença com 0-conf e revogá-la via API se a transação não confirmar.
Produto digital com entrega revogável tem risco limitado, e elimina a espera de confirmação.

#### ⚠️ Bloqueios a resolver com o suporte do Freemius ANTES de implementar

1. **Uso contínuo dos endpoints de pagamento migrado.** Eles existem para **migração pontual** de
   plataforma. Usá-los para faturar continuamente fora do Freemius — pagando 0% de comissão
   enquanto se usa a infra de licença e update deles — mexe no modelo de receita da plataforma e
   pode esbarrar nos termos de uso. Pergunta literal a fazer: *"posso registrar vendas contínuas do
   meu próprio checkout via a API de licença / pagamento migrado?"*
   **Se a resposta for não:** o canal Bitcoin vira apenas aquisição e a renovação passa pelo link
   de renovação do Freemius (cartão). Nada quebra, mas o desenho muda — por isso perguntar antes de
   construir.
2. **A licença precisa nascer com assinatura associada**, senão não existe `subscription_id` para
   postar o pagamento de renovação. É parâmetro de ciclo de cobrança na criação, não incógnita de
   arquitetura — confirmar a forma exata.

### 8.5 Por que NÃO usar WooCommerce Subscriptions

Renovação em crypto é sempre **manual** — on-chain e Lightning são push-only, não existe cobrança
recorrente automática (o Woo Subscriptions só acomodaria isso via *Accept Manual Renewals*). E
renovação manual é **pedido comum**, que o plugin base já processa sem nenhuma integração especial.
Ou seja: o Subscriptions não contribui nada do lado do pagamento.

O argumento decisivo é outro: **a validade da licença já vive no Freemius**, que é o que o plugin
do cliente efetivamente lê. Se o Woo Subscriptions também rastrear expiração, passam a existir dois
relógios para a mesma data, que divergem no primeiro erro (cliente paga a renovação, a chamada de
API falha, Woo diz "ativo" e o plugin do cliente diz "expirado"). Pagar ~$239/ano e aceitar uma
segunda fonte de verdade para disparar um e-mail é troca ruim.

**O que sobra para resolver: só o lembrete de renovação.** Em ordem de esforço:
1. Manual nos primeiros clientes — planilha e e-mail, custo zero
2. Cron simples no site: pedidos com ~11 meses → e-mail com link de compra (~50 linhas)
3. Só se o volume justificar, reavaliar o Subscriptions

**Mandar o lembrete com 30 dias de antecedência.** Confirmação on-chain lenta ou pico de fee na
última hora deixaria o cliente expirado por alguns dias — e como a extensão soma ao saldo restante,
renovar cedo não tem downside algum para o cliente.

> **Roadmap (não v1):** integração de verdade com WooCommerce Subscriptions **como feature do
> premium** — lojista que vende assinatura e quer receber em crypto é segmento real. Não exige
> mudança no base: o *Accept Manual Renewals* do Woo Subscriptions já expõe qualquer gateway.

---

## 9. Testes

Espelhar a infra do base (`src/trunk/tests/_support/`): shims WP/WC centralizados, `FakeHttpClient`
/ `http_ok()` / `http_error()`, spies de hook. Copiar `phpunit.xml.dist` + `tests/bootstrap.php`.
Alvos prioritários:
- **`ProviderChain` (§3):** failover (1º falha → 2º responde), retry por provider, exceção quando
  todos falham, provider em cooldown é pulado. Cada provider de câmbio/explorer: parse correto da
  resposta e detecção de falha, com `FakeHttpClient`.
- **Webhook controller:** validação de assinatura HMAC (aceita/rejeita), mapeamento invoice→order,
  re-verificação server-side, idempotência (segundo push não re-completa).
- **lnd poller / on-chain poller:** com `FakeHttpClient` devolvendo status pago/pendente.
- **Listeners:** transição → `payment_complete()` só uma vez; ignora pedido já pago.
- **Tolerância de subpagamento (§6):** dentro da tolerância → completa; fora → vai para revisão
  manual com order note, **nunca** fica em `pending`; sobrepagamento completa e registra a diferença.
- **FiatToSatsConverter:** cache, e fallback para último valor quando a chain lança
  `AllProvidersUnavailableException`.
- **Gate de licença (§8.2):** com `is_active() === false`, os módulos de confirmação de pagamento
  **continuam registrados** e o `payment_complete()` continua funcionando; só o que está na coluna
  gated deixa de ser registrado. Este teste é a trava contra alguém "otimizar" o bootstrap para um
  `if (is_active())` único no futuro.
- ~~**Enablement do base**~~ — **feito.** Coberto no repo base por
  `tests/phpunit/unit/OnchainConfirmationsUpdateTest.php`; nada a fazer aqui (§2).

---

## 10. Release / distribuição

- **Base:** nada a fazer — os seams (§2) já estão na 0.1.0, live no WP.org desde 2026-08-08.
- **Add-on:** `scripts/release.sh` adaptado (build/zip; **sem** SVN/WP.org). O ZIP gerado é
  **enviado ao dashboard do Freemius** (deploy), que é a origem do auto-update dos clientes —
  não basta disponibilizar download no site. Versionar independente do base.
- **Segredos:** o `public_key` do Freemius vai no código (é público por design); a **secret key**
  é usada apenas para deploy e chamadas de API server-side (§8.4) e **nunca** entra no ZIP
  distribuído nem no repositório.
- Traduções do add-on com text domain próprio (ex. `paycrypto-me-premium`), espelhando o fluxo de
  `docs/TRANSLATION.md` (script `scripts/build-translations.sh`).

---

## 11. Verificação end-to-end

1. **Base:** nada a verificar — os seams §2 estão shipados e cobertos por testes. Se ainda assim
   quiser confirmar, `./vendor/bin/phpunit` no repo base (esperado: suíte verde).
2. **Ambiente:** subir o `docker-compose.yml` do base, instalar o base 0.1.0 + o add-on; confirmar
   que o guard não bloqueia (base presente e versão OK) e que os campos premium ficam **editáveis**.
3. **Providers (§3):** derrubar/mistrar o 1º provider (ex. URL inválida em teste) e confirmar
   failover para o próximo + cooldown do provider derrubado; conferir logs.
4. **Lightning BTCPay:** disparar um POST assinado no endpoint
   `rest_url('paycrypto-me/v1/webhook')` (curl com HMAC válido) para um pedido de teste → conferir
   que o pedido vai a `processing/completed` e que um segundo POST não re-completa (idempotência).
5. **Lightning lnd:** com nó/regtest (ou `FakeHttpClient` em teste), rodar o poller manualmente
   (`wp cron event run <hook>`) e confirmar transição ao pagar.
6. **fiat→sats:** criar invoice lnd via checkout e verificar `amount_sats` persistido **e** o
   `value` no corpo enviado ao lnd (log/inspeção); a taxa veio da chain de câmbio.
7. **On-chain:** pedido testnet, pagar o endereço, rodar o poller, conferir
   `paycrypto_me_bitcoin_transactions_data` atualizada (via chain de explorer) e `payment_complete()`
   ao atingir as confirmações.
8. **Auto-expiração:** pedido pending com expiry curto → rodar o cron → status `cancelled`.
9. **Subpagamento (§6):** simular `amount_received` a 99% e a 80% do esperado → o primeiro
   completa, o segundo vai para revisão manual com order note e **não** fica em `pending`.
10. **Licença (§8.2/§8.3):** ativar licença via SDK do Freemius (sandbox) e confirmar auto-update;
    depois **expirar a licença** e confirmar que o poller de confirmação e o `payment_complete()`
    **continuam funcionando** — só o update e as features gated param. Este é o teste que protege
    o dinheiro do lojista.
11. **Canal Bitcoin (§8.4):** comprar o próprio add-on na loja própria pagando on-chain → pedido
    confirma pelo módulo C → chamada da API do Freemius cria a licença → e-mail chega → ativar num
    WordPress limpo. Depois repetir o fluxo de **renovação** e confirmar que a expiração foi
    estendida somando ao saldo restante (e não resetada).
    *Pré-requisito: os dois bloqueios do §8.4 respondidos pelo suporte do Freemius.*

---

## 12. Ordem de implementação recomendada

Sequenciada por dependência, não por importância. Nada aqui exige tocar no plugin base.

| # | Etapa | Depende de | Bloqueia |
|---|---|---|---|
| 0 | **Perguntar ao suporte do Freemius** os dois bloqueios do §8.4 | — | etapa 6 |
| 1 | Esqueleto do add-on: repo, `composer.json`, entrypoint, guard, bootstrap, infra de testes (§1, §9) | — | tudo |
| 2 | `ProviderChain` + `ProviderHealthTracker` + contracts (§3) | 1 | módulos B e C |
| 3 | Módulo C — confirmações on-chain, **com a tolerância de subpagamento** (§6) | 2 | canal Bitcoin |
| 4 | Módulo A — webhook BTCPay + poller lnd + listener (§4) | 1 | — |
| 5 | Módulo B (fiat→sats, §5) e Módulo D (auto-expiração + settings, §7) | 2 | — |
| 6 | Freemius: cadastro do produto, SDK vendored, `LicenseManagerContract` + adapters (§8.1–8.3) | 1, 0 | venda |
| 7 | Loja própria: Woo + base + add-on, hook de pedido pago → API, lembrete de renovação (§8.4–8.5) | 3, 6 | — |

**Por que o módulo C vem antes dos outros:** ele é pré-requisito do canal Bitcoin (etapa 7) — a
loja própria só fecha pedido se a confirmação on-chain funcionar. É também o módulo mais
importante do ponto de vista de adoção (§8.4, nota de produto).

**A etapa 0 é barata e assíncrona — dispare no dia 1.** A resposta do suporte só é necessária na
etapa 6, mas se for negativa muda o desenho da 7, e ninguém quer descobrir isso depois de
construir a loja.

**Risco consciente da etapa 7:** a loja passa a depender do código alpha do próprio add-on. Bug
no módulo C = venda travada em `pending`. É a melhor pressão de teste possível — você vira o
cliente zero — mas comece com confirmação manual como fallback e monitore os primeiros pedidos.

---

## Arquivos-chave (referência)

**Base — consumidos sem edição:**
- `includes/services/class-paycrypto-me-lightning-db-statements-service.php` — `get_by_invoice_id():65`,
  `update_status():121`, action `paycryptome_lightning_status_changed:142`
- `includes/services/class-btcpay-invoice-service.php:49` / `class-lnd-rest-invoice-service.php:50` —
  `get_invoice_status()`
- `includes/processors/abstract-class-lightning-processor.php:30` — filtros de invoice args
- `includes/contracts/HttpClientContract.php` + `includes/http/class-wp-http-client.php`
- `includes/services/pay-crypto-me-db-statements-service.php` — `get_by_order_id()` on-chain
- `paycrypto-me-for-woocommerce.php:38` — `WC_PayCryptoMe::VERSION` (guard)

**Base — enablement (§2): JÁ FEITO, não editar de novo:**
- `includes/services/class-lnd-rest-invoice-service.php:31` — `value` no body
- `includes/services/pay-crypto-me-db-statements-service.php:240` / `:276` —
  `update_transaction_confirmations()` + `do_action('paycryptome_bitcoin_status_changed')`
- `tests/phpunit/unit/OnchainConfirmationsUpdateTest.php` — cobertura dos dois

**Add-on — criar:** todos sob `paycrypto-me-premium/src/trunk/includes/` (ver §1); o coração da
resiliência é `includes/providers/` (§3), consumido pelos módulos B (câmbio) e C (explorer).

**Loja própria — criar (fora deste repo e do base):** plugin do site com o hook de pedido pago →
API do Freemius (§8.4) + o cron de lembrete de renovação (§8.5).

---

## Referências externas

**Freemius:**
- [Pricing](https://freemius.com/pricing/) — 4,7% + 2,3% WP; tiers progressivos só acima de $50k/mês
- [Create a license](https://docs.freemius.com/api/plans/create-license) — endpoint de aquisição (§8.4)
- [Create new migrated payment](https://freemius.com/help/api/subscriptions/create-new-migrated-payment/) — endpoint de renovação (§8.4)
- [License renewals mechanism](https://freemius.com/help/documentation/selling-with-freemius/license-renewals-mechanism/) — extensão soma ao saldo restante
- [Migrating from EDD to Freemius](https://freemius.com/help/documentation/migration/migrating-from-edd-to-freemius/) — contexto do "migration source"

**WooCommerce:**
- [Subscriptions — payment gateways](https://woocommerce.com/document/subscriptions/payment-gateways/) e
  [renewal process](https://woocommerce.com/document/subscriptions/renewal-process/) — base do
  *Accept Manual Renewals* citado em §8.5
