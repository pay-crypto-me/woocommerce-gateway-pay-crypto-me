# Plano — Endurecimento para Produção (PayCrypto.Me for WooCommerce)

> **Documento auto-contido.** Pode ser executado por qualquer agente ou pessoa do time sem depender
> desta conversa. Fonte de verdade da arquitetura continua sendo o `CLAUDE.md` (raiz do repo).
> **Local canônico:** `docs/PRODUCTION-HARDENING.md` (ver **Tarefa 0**).

## Contexto

O plugin foi submetido ao WordPress.org. Após corrigirmos a primeira rodada de review
(ver `docs/WORDPRESS-ORG-REVIEW-FIXES.md`), o revisor reportou um **erro fatal na ativação**:

```
Uncaught Error: Call to undefined function Mdanter\Ecc\Curves\gmp_init()
in .../vendor/paragonie/ecc/src/Curves/SecgCurve.php on line 156
```

**Reproduzimos o crash exato** rodando `php -d disable_functions=gmp_init wp eval '...'` no container
(o stack trace bateu 100%). A causa raiz não foi lógica, e sim **ambiente**: nosso Docker tem *todas*
as extensões PHP (gmp, gd, imagick, intl, bcmath, sodium), enquanto o ambiente do revisor
(WordPress Playground / PHP WASM) não tem GMP. Nenhum teste ou smoke test nosso poderia detectar isso.

Isso motivou uma **auditoria profunda** (3 agentes: dependências de plataforma; bootstrap/ciclo de
vida; robustez de runtime e corretude de pagamento) buscando a mesma *classe* de bug: falhas que só
aparecem fora do ambiente controlado. A auditoria encontrou **outros fatais dependentes de ambiente**
e — mais grave — **3 bugs de perda de dinheiro** não relacionados a extensões.

**Objetivo:** eliminar os riscos de perda de fundos e os fatais em hosts reais, para que o plugin
seja seguro para merchants de verdade (e passe na review).

---

## Estado atual do working tree (IMPORTANTE)

A correção *lazy* do `BitcoinAddressService` foi feita e depois colocada em **`git stash`** para
reproduzirmos o crash como o revisor viu. O working tree tem a **versão com bug**.

```bash
git stash list   # deve mostrar: "temp: revert lazy BitcoinAddressService fix to reproduce..."
```

A **Tarefa 0** começa restaurando esse stash. Se o stash não existir mais, a correção está descrita
integralmente em C4-a abaixo.

Também há mudanças não commitadas e **não relacionadas** a este plano em `docker-compose.yml`,
`docs/RELEASE.md` e `scripts/release.sh` (rodada anterior). Não revertê-las.

## Decisões (já tomadas)

- **Escopo desta rodada:** Críticos + Altos. Cosméticos e refactors maiores ficam documentados como
  follow-up (seção "Fora de escopo").
- **lnd REST zero-amount:** **manter como está** (escopo premium documentado). Apenas registrar a
  decisão explicitamente na doc — nenhuma mudança de comportamento.
- **Ambiente de teste:** adicionar um **script de smoke** que simula host mínimo (sem criar novo
  serviço Docker).
- **Sem migração de schema:** o plugin **nunca foi publicado** (sem base instalada), então as
  correções de DDL podem ser feitas direto, sem rotina de upgrade para instalações existentes.

---

## Tarefa 0 — Restaurar o stash

`git stash pop` — restaura a correção lazy do `BitcoinAddressService` (base do C4). Conferir com
`git stash list` antes; se o stash não existir mais, a correção está descrita integralmente em C4-a.

*(Este documento já está persistido em `docs/PRODUCTION-HARDENING.md` e indexado no `CLAUDE.md`.)*

---

## Parte 1 — CRÍTICO: perda de dinheiro

> Estes três foram **verificados manualmente** lendo o código, não apenas reportados por agente.

### C1 — xPub de rede errada é aceito → BTC real em endereços invisíveis para o lojista

**Verificado.** `convert_extended_pubkey_prefix()`
([class-bitcoin-address-service.php](../src/trunk/includes/services/class-bitcoin-address-service.php),
~linha 172) faz `$newHex = $network->getHDPubByte()` — ou seja, **reescreve os version bytes para a
rede alvo antes de validar**. Logo `validate_extended_pubkey($tpub, mainnet)` **retorna true**.

As allow-lists que existiriam para impedir isso (`xpub_prefix`, `address_prefix` em
`get_available_networks()`, [class-wc-gateway-paycrypto-me.php](../src/trunk/includes/class-wc-gateway-paycrypto-me.php)
~linhas 118-128) são **código morto — nunca lidas em lugar nenhum** (confirmado por grep).

**Cenário real:** admin seleciona Mainnet e cola um `tpub`/`vpub` (comum: testou antes, ou copiou a
chave errada). Salva sem erro. O plugin passa a derivar endereços **mainnet reais** a partir da chave
testnet e entrega a clientes. A carteira (testnet) do lojista nunca mostra esses endereços nem o saldo.

**Correção:**
- Adicionar ao `$prefixMap` do `BitcoinAddressService` um campo `'testnet' => bool` por entrada
  (o mapa já separa os grupos por comentário).
- Novo método público e testável no serviço:
  `prefix_matches_network(string $identifier, string $network_type): bool`.
- Chamar esse guard em `validate_xpub_address()` **antes** de qualquer conversão, e usar
  `address_prefix` para o caminho de endereço estático em `validate_network_identifier()`
  (assim as allow-lists deixam de ser código morto — reuso, não código novo).
- Mensagem de erro clara ao admin ("esta chave é de testnet, mas a rede selecionada é mainnet").

### C2 — Lightning: retry de pagamento diverge meta ↔ banco → cliente paga e pedido nunca liquida

**Verificado.** `insert_invoice()` retorna `false` logo no início se já existe linha para o pedido
([class-paycrypto-me-lightning-db-statements-service.php](../src/trunk/includes/services/class-paycrypto-me-lightning-db-statements-service.php)
~linha 91, protegido por `UNIQUE KEY unique_order`), e o processor **descarta o retorno**
([abstract-class-lightning-processor.php](../src/trunk/includes/processors/abstract-class-lightning-processor.php)
~linha 58).

**Cenário real** (não exótico — o WooCommerce reusa o mesmo pedido em retry de checkout e no endpoint
`order-pay`): tentativa 1 cria invoice **A** + linha A. Tentativa 2 cria invoice **B** *de verdade no
nó*; `insert_invoice` retorna `false` silenciosamente; a meta do pedido é sobrescrita com **B**
(`add_meta_data(..., $unique = true)`). O cliente vê e paga **B**, mas o banco — única coisa que
`get_by_invoice_id()`/`update_status()`/o webhook premium conseguem casar — ainda guarda **A**.
Sats chegam e o pedido nunca é liquidado.

O lado on-chain trata o caso corretamente (branch de reuso em `resolve_derived_address()`,
[class-bitcoin-payment-processor.php](../src/trunk/includes/processors/class-bitcoin-payment-processor.php)
~linhas 111-114) — a assimetria confirma omissão, não design.

**Correção (espelhar o padrão on-chain):**
- Em `AbstractLightningProcessor::process()`, **antes** de criar invoice no nó: consultar
  `$this->db->get_by_order_id()`. Se existir invoice ainda **válida** (não expirada), **reusar**
  (retornar os dados persistidos) em vez de criar outra.
- Se existir mas estiver **expirada**, substituir a linha (novo método `replace_invoice()` no DB
  service, ou `update_invoice()` — expor um caminho de atualização em vez do early-return mudo).
- **Sempre checar o retorno** de `insert_invoice()`/`replace_invoice()` e lançar
  `PayCryptoMePaymentException` em falha — nunca divergir em silêncio.

### C3 — Índice de derivação é queimado fora da transação → estoura o gap limit da carteira

O índice é reservado e commitado (e o lock liberado) **antes** de o endereço ser derivado e persistido
([class-bitcoin-payment-processor.php](../src/trunk/includes/processors/class-bitcoin-payment-processor.php)
~linhas 126-139). Qualquer exceção entre os dois passos consome um índice permanentemente, sem pedido
correspondente.

Isoladamente seria inofensivo, mas essas falhas são **sistêmicas, não aleatórias** (GMP ausente, xpub
inválido, falha de escrita). ~20 falhas consecutivas ultrapassam o **gap limit BIP-44 (20)**: a
carteira do lojista para de escanear e **não mostra pagamentos** feitos a endereços posteriores.

**Correção:** envolver derivação + persistência num `try/catch` que **libera/estorna o índice
reservado** em caso de falha (novo método `release_derivation_index()` no
`PayCryptoMeDBStatementsService`, deletando a linha reservada). O lock em si já está correto
(`RELEASE_LOCK` em `finally`, timeout de 10s, PK composta como backstop) — **não mexer nele**.

---

## Parte 2 — CRÍTICO: fatais dependentes de ambiente

### C4 — GMP ausente: degradação graciosa em vez de fatal

Três partes. Sozinha, a correção *lazy* **não basta**: `Base58::decodeCheck/encodeCheck` na lib chama
`gmp_*` cru, então o checkout on-chain ainda daria fatal num host sem GMP.

**a) Inicialização lazy** (já implementada, no stash — restaurar na Tarefa 0): `BitcoinAddressService`
não instancia `HierarchicalKeyFactory`/`AddressCreator` no construtor; usa acessores `get_hd_factory()`
/ `get_address_creator()`. **Estender também ao gateway**: em
[class-wc-gateway-paycrypto-me.php](../src/trunk/includes/class-wc-gateway-paycrypto-me.php) (~linhas
49-50) tanto `$bitcoin_address_service` quanto `$db_statements_service` são construídos no construtor
mas usados **só** ao salvar settings e no AJAX de reset — trocar por acessores lazy.
*(Por que importa: o WooCommerce constrói todos os gateways em toda requisição — frontend, admin,
cron, REST, AJAX.)*

**b) Guard de capacidade:** `WC_Gateway_PayCryptoMe::is_available()` (~linha 197) retorna `false`
quando `!extension_loaded('gmp')` — o gateway On-Chain simplesmente não é oferecido. Encaixa no
padrão que já existe ali (checa `selected_network`/`network_identifier`).
**O Lightning e o QR Code são independentes de GMP (verificado)** — degradação é parcial, o Lightning
continua funcionando.

**c) Aviso ao admin + documentação:** `admin_notice` explicando por que o gateway On-Chain está
indisponível (apenas quando ele estiver habilitado), e declarar `ext-gmp`/`ext-gd` em `readme.txt`
e em `composer.json` (`require`), já que hoje o `composer.json` não declara nenhum `ext-*` e
`config.platform` só fixa `php: 7.4` (pin intencional — **não alterar**, ver memória do projeto).

### C5 — `catch (\Exception)` não pega `\Error` → é isto que transforma falha em tela branca

Defeito **sistêmico** e a razão de o bug do GMP ter sido WSOD em vez de erro tratado: em PHP 8,
`Call to undefined function` é `\Error`, que **não** é `\Exception`.

Trocar para `\Throwable` nos limites (mantendo o log):
- [class-payment-processor.php](../src/trunk/includes/processors/class-payment-processor.php) ~linha 51 (limite do checkout)
- [class-bitcoin-payment-processor.php](../src/trunk/includes/processors/class-bitcoin-payment-processor.php) ~linha 73
- [class-bitcoin-address-service.php](../src/trunk/includes/services/class-bitcoin-address-service.php) ~linhas 191 e 212 (validações no admin)

*(`class-qr-code-service.php` ~linha 109 e `class-wc-gateway-paycrypto-me.php` ~linha 294 já usam
`\Throwable` — servem de precedente no próprio repo.)*

### C6 — QR Code: o guard está invertido → tela branca depois de o cliente pagar

**Verificado.** Em [class-qr-code-service.php](../src/trunk/includes/services/class-qr-code-service.php)
(~linhas 60-89): o caminho **com** guard (`extension_loaded('gd')` + `catch (\Throwable)`) retorna
`null` e **cai para `generate_native()`, que não tem try/catch nenhum** — e depende de **GD**
(`PngWriter` lança se ausente), **ext-fileinfo** (`mime_content_type` para o logo) e **ext-iconv**
(exigência dura do `bacon/bacon-qr-code`). O comentário na linha ~63 diz "so the QR always renders":
a intenção era resiliência, mas o fallback é o caminho frágil.

Blast radius: o hook roda em `woocommerce_order_details_before_order_table` **e**
`woocommerce_admin_order_data_after_order_details` → **o cliente paga e vê tela branca**, e o
**lojista não consegue abrir o pedido no admin**. Afeta **os dois gateways** (o QR é compartilhado),
inclusive o Lightning.

**Correção (defesa em profundidade):**
- Envolver `generate_native()` em `try/catch (\Throwable)`; QR indisponível → retorna `null`/vazio.
- Tornar o QR **opcional na renderização**: `PaymentDisplayDataBuilder` e o template
  `templates/order-details/paycrypto-me-order-details.php` devem tolerar QR ausente (o endereço e o
  botão copiar continuam aparecendo — o essencial para pagar).
- Envolver o corpo de `render_checkout_order_details_section()`
  ([abstract-class-wc-gateway-paycrypto-me.php](../src/trunk/includes/abstract-class-wc-gateway-paycrypto-me.php))
  em `try/catch (\Throwable)` + log: **a página de pedido nunca pode dar fatal**.

### C7 — `vendor/autoload.php`: o guard é cosmético → fatal em instalação por zip do GitHub

O `if (file_exists(...))` na abertura do
[paycrypto-me-for-woocommerce.php](../src/trunk/paycrypto-me-for-woocommerce.php) (~linhas 28-30)
evita o fatal **só naquela linha** e segue a execução sem autoloader. Como `vendor/` é gitignored e o
`Plugin URI` aponta para o GitHub, instalar pelo zip do repo é caminho real → classes não encontradas
na ativação e no bootstrap (fatal de site inteiro).

**Correção:** no `else`, registrar `admin_notice` explicando e **retornar cedo**, sem registrar
`register_activation_hook` nem o `plugins_loaded`.

### C8 — `tempnam()` sem guard no certificado lnd → fatal no checkout

Em [class-lnd-rest-invoice-service.php](../src/trunk/includes/services/class-lnd-rest-invoice-service.php)
(~linhas 89-90), `tempnam()` retorna `false` sob `open_basedir` ou tmp somente-leitura →
`file_put_contents(false, ...)` → `ValueError` (um `\Error`) → fatal no checkout.
O **mesmo código já está corretamente guardado** em
[class-lightning-connection-tester.php](../src/trunk/includes/services/class-lightning-connection-tester.php)
(~linhas 83-84) — replicar esse padrão (omissão acidental, não deliberada).

---

## Parte 3 — ALTO: integridade de schema e dados

### H1 — `CREATE TABLE IF NOT EXISTS` quebra o `dbDelta` permanentemente

**Verificado contra o core:** `dbDelta` extrai o nome com
`preg_match('|CREATE TABLE ([^ ]*)|', ...)` (`wp-admin/includes/upgrade.php`). Com `IF NOT EXISTS`, o
nome capturado é literalmente **`IF`** → o diff de schema nunca acontece.

Hoje "funciona" (o CREATE cru passa direto na primeira ativação), mas **toda alteração futura de
schema será um no-op silencioso** — e não existe `db_version` nem rotina de upgrade (o WordPress não
re-executa hooks de ativação em update de plugin).

Riscos correlatos no mesmo DDL
([class-paycrypto-me-bitcoin-gateway-activate.php](../src/trunk/includes/services/class-paycrypto-me-bitcoin-gateway-activate.php)):
- `UNIQUE KEY unique_xpub_network (xpub, network)` sobre `VARCHAR(255)`+`VARCHAR(50)` em utf8mb4 =
  **1220 bytes** > limite de **767 bytes** do InnoDB em MySQL/MariaDB antigos (row format COMPACT).
  É exatamente por isso que o core usa `VARCHAR(191)`. Falhando, a tabela não é criada, a FK seguinte
  também falha, e **a ativação reporta sucesso** (o retorno do `dbDelta` é descartado e
  `$wpdb->last_error` nunca é lido) → quebra silenciosa no primeiro pedido on-chain.
- `FOREIGN KEY ... REFERENCES` dentro do `dbDelta`: o dbDelta não gerencia FKs, e em host com MyISAM
  a FK é silenciosamente descartada.

**Correção (sem base instalada — pode ser feita direto):**
- Remover `IF NOT EXISTS` (usar `CREATE TABLE` puro, como o dbDelta exige).
- `xpub` → `VARCHAR(191)`.
- Tirar a `FOREIGN KEY` do SQL do dbDelta (manter a integridade pela PK composta já existente).
- Checar `$wpdb->last_error` após cada `dbDelta` e exibir `admin_notice` em caso de falha.
- Adicionar opção `paycrypto_me_db_version` + verificação de upgrade em `plugins_loaded`, para que
  migrações futuras funcionem de fato.

### H2 — Sem `uninstall.php`: segredos permanecem no banco após desinstalar

Não existe `register_deactivation_hook`, `register_uninstall_hook` nem `uninstall.php` (verificado no
repo inteiro). Após a desinstalação persistem as 4 tabelas e as duas linhas de options — incluindo
**segredos em texto plano**: `lnd_macaroon_hex` (macaroon admin = **controle total do nó**),
`btcpay_api_key` e `lnd_certificate`.

**Correção:** criar `src/trunk/uninstall.php` (guard `WP_UNINSTALL_PLUGIN`) que apaga
`woocommerce_paycrypto_me_settings` e `woocommerce_paycrypto_me_lightning_settings`.

> **Decisão (revisada na execução): as 4 tabelas são MANTIDAS, não dropadas.** Elas são o registro
> de pagamentos da loja (endereços derivados, índices de derivação, invoices Lightning) e continuam
> necessárias para contabilidade e conciliação de pedidos antigos depois que o plugin é removido —
> dropá-las destruiria histórico financeiro que não existe em nenhum outro lugar. `paycrypto_me_db_version`
> também é mantida, pelo mesmo motivo: descreve o schema que fica no banco, então uma reinstalação
> futura parte do estado correto em vez de assumir instalação limpa. Só a option de diagnóstico
> `paycrypto_me_db_activation_errors` é removida (buffer de aviso, não é registro).

### H3 — Nenhum timeout HTTP nas chamadas ao nó → checkout trava

[class-wp-http-client.php](../src/trunk/includes/http/class-wp-http-client.php) (~linhas 20 e 33) não
define `timeout`, e nenhum caller do fluxo de pagamento informa um → usa o default do WP (5s).
BTCPay atrás de Tor ou um lnd "frio" estouram isso rotineiramente. Pior, no BTCPay soma-se
`create_invoice` + 2× `resolve_payment_request` + `usleep(750ms)` ≈ 16s de worker PHP bloqueado por
checkout, com invoice **já criada no nó** no caminho de erro.
*(`is_wp_error` e o parsing de resposta já estão corretos — só falta o timeout.)*

**Correção:** definir timeout explícito (15s, alinhado ao que o connection tester já usa) no
`WpHttpClient`, permitindo override por chamada.

---

## Parte 4 — Correções baratas (one-liners) incluídas nesta rodada

- **Precedência + deref de null:** `if ($screen && $screen->id === 'woocommerce_page_wc-orders' || $screen->id === 'shop_order')`
  em [class-wc-gateway-paycrypto-me.php](../src/trunk/includes/class-wc-gateway-paycrypto-me.php) (~linha 267):
  `&&` liga mais forte que `||`, então o guard `$screen` é inútil e há deref de null. O gateway
  Lightning (~linhas 65-67) já faz certo — usar como referência. Parenteses.
- **`WP_Screen` sem `\` em namespace:** [abstract-class-wc-gateway-paycrypto-me.php](../src/trunk/includes/abstract-class-wc-gateway-paycrypto-me.php)
  (~linha 59) declara `WP_Screen|null`, que resolve para `PayCryptoMe\WooCommerce\WP_Screen`
  (inexistente). Inofensivo só porque as duas implementações não tipam o parâmetro; vira `TypeError`
  em toda página admin assim que alguém tipar. Trocar por `\WP_Screen|null`.
- **AJAX registrado na classe errada:** `wp_ajax_paycryptome_reset_derivation_index` é registrado no
  **abstract** (~linha 56), mas `ajax_reset_derivation_index()` só existe no gateway On-Chain →
  o Lightning registra um callback inexistente (hoje mascarado por ordem de registro). Mover o
  `add_action` para o gateway On-Chain.
- **`filemtime()` sem guard** (~linhas 130 e 136 do abstract): todos os outros no arquivo usam
  `file_exists()` antes — padronizar.
- **Notice de "WooCommerce necessário" nunca aparece:** está dentro de `if (!headers_sent())`
  ([main](../src/trunk/paycrypto-me-for-woocommerce.php) ~linha 188), mas quando `admin_notices`
  dispara o `<head>` já foi emitido → com `output_buffering=Off` (default) a condição é falsa e o
  aviso é engolido. Remover a condição.

---

## Parte 5 — Processo: fechar o buraco que escondeu o bug

Criar **`scripts/smoke-minimal-host.sh`** que exercita o plugin simulando um host mínimo, usando a
mesma técnica que reproduziu o bug original:

```bash
docker compose exec wordpress php -d disable_functions=gmp_init \
  /usr/local/bin/wp eval 'WC()->payment_gateways()->payment_gateways();'
```

Deve cobrir, cada um isoladamente e sem fatal: **gmp** ausente (gateway On-Chain some, Lightning
segue), **gd**, **iconv**, **fileinfo** ausentes (QR degrada, página de pedido renderiza). O script
deve falhar (exit != 0) se qualquer combinação produzir fatal. Documentar no `docs/RELEASE.md` como
passo obrigatório antes de gerar release.

---

## Fora de escopo (decidido) — registrar como follow-up

- **lnd REST zero-amount** (decisão do usuário: manter). O trilho lnd gera BOLT11 sem valor; qualquer
  pagador pode liquidar com 1 sat. BTCPay **não** tem o problema (recebe fiat e converte). Registrar a
  decisão explicitamente na doc para não ser relida como bug.
- Multisite (tabelas criadas só para um site); `uninstall` de tabelas por site.
- Cache do QR (hoje re-renderiza supersampled a cada visualização do pedido).
- `add_gateway()` remover do registry em vez de usar `is_available()`, e usar a option do On-Chain
  para ocultar os dois gateways.
- `ceil()` na expiração Lightning (mostra "1 hora" para invoice de 5 min); tz de `expires_at`.
- SSRF admin-only no connection tester (relevante só em multisite).
- `number_format_i18n` para valor BTC (vírgula decimal em pt_BR/de_DE) — só alcançável com add-on.
- Namespaces do vendor sem prefixo (colisão com outro plugin que embarque `bitwasp/bitcoin-php`).

---

## Verificação

1. **PHPUnit:** `docker compose exec -w /var/www/html/wp-content/plugins/paycrypto-me-for-woocommerce wordpress ./vendor/bin/phpunit`
   — baseline atual: **246 testes verdes**. Novos testes a adicionar (fecham cegos reais apontados na auditoria):
   - `tpub`/`vpub` sob mainnet deve ser **rejeitado** (C1) — hoje passa.
   - Segundo `process_payment()` no mesmo pedido reusa a invoice e **não** diverge meta↔DB (C2).
   - Falha após reservar índice **estorna** o índice (C3).
   - `wc_get_order()` retornando `false` não gera `TypeError` (C5).
2. **Smoke de host mínimo:** `./scripts/smoke-minimal-host.sh` (Parte 5) — deve passar sem fatal em
   todas as combinações de extensão ausente.
3. **Plugin Check:** `docker compose exec wordpress wp plugin check paycrypto-me-for-woocommerce --exclude-directories=tests,vendor,node_modules`
   (achados restantes esperados: só o warning conhecido de `load_plugin_textdomain`).
4. **Smoke manual (usuário):** checkout On-Chain e Lightning; página de pedido do cliente e tela de
   edição do pedido no admin; salvar settings com xpub correto **e** com `tpub` sob mainnet (deve
   recusar com mensagem clara).
5. **Ativação limpa:** desativar/reativar o plugin e conferir que as 4 tabelas são criadas
   (`SHOW TABLES LIKE '%paycrypto%'`) e que `$wpdb->last_error` está vazio.
6. **Zip de release:** regerar e inspecionar conforme `docs/RELEASE.md`.

## Resposta ao WordPress.org

Após o release corrigido, responder **na mesma thread** (Review ID
`AUTOPREREVIEW paycrypto-me-for-woocommerce/paycryptome/20Jul26/T1 20Jul26/4.2A2 (P0TDX343130HGN)`),
curto e direto: o fatal de ativação foi corrigido; o gateway on-chain agora degrada graciosamente
quando `ext-gmp` não está disponível (em vez de derrubar o site), o Lightning segue funcionando, e os
requisitos de extensão passaram a ser declarados. Sem listar cada mudança — eles reavaliam o plugin
inteiro.
