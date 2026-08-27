# [PLAN — NOT STARTED] Registro de pagamentos on-chain estáticos + endurecimento do mecanismo de upgrade de schema

> **Status: plano aprovado, não iniciado.** Aprovado em 2026-08-14. Nenhuma das frentes abaixo
> foi implementada ainda.
>
> Documento auto-suficiente: quem executar não precisa da conversa que o originou. Prosa em
> português; identificadores, caminhos e nomes de teste em inglês, como no resto do repo.

---

## Context

O plugin está **publicado no WordPress.org** desde 2026-08-08 (v0.1.0). Existem instalações reais
lá fora, então toda mudança estrutural precisa funcionar tanto em instalação nova quanto em site
que atualiza a partir do schema publicado.

**Demanda original.** O fluxo on-chain com **endereço fixo** (o merchant configura um endereço
Bitcoin em vez de um xPub) não registra nada em `{prefix}paycrypto_me_bitcoin_transactions_data`,
enquanto o fluxo de derivação registra. Isso é um furo de contabilidade/reconciliação: pedidos
pagos a endereço fixo não têm linha na tabela de pagamentos.

**O que a investigação revelou.** Ao dimensionar essa correção, três problemas latentes apareceram
— nenhum deles causado pela demanda, todos capazes de quebrar silenciosamente uma futura mudança de
schema. Como o roadmap prevê mais gateways/redes (mais tabelas e colunas), eles entram no mesmo
esforço.

**Resultado pretendido.**
1. Pagamento a endereço fixo registrado como qualquer outro, sem mudança de schema.
2. Mecanismo de upgrade seguro (não rebaixa versão, não roda no page load do cliente, não corre em
   paralelo consigo mesmo).
3. Mudanças de schema **testáveis contra MySQL real**, com teste de regressão que pega
   declaração silenciosamente ignorada.

---

## Fatos estabelecidos por medição

Estes fatos são a base de várias decisões abaixo. Estão registrados com o método de verificação
para que possam ser re-checados, e para que ninguém "corrija" as decisões de volta por engano.

| # | Fato | Como foi verificado |
|---|---|---|
| F1 | **`dbDelta()` ignora mudança de nullability.** Declarar `NOT NULL` → `NULL` não gera ALTER, não gera erro, e `$wpdb->last_error` fica vazio. | `dbDelta()` rodado contra a tabela real no container `wordpress` com o `CREATE TABLE` proposto (uma coluna por linha). Retorno: `(nada)`. `SHOW COLUMNS` depois: `Null=NO` nas duas colunas. |
| F2 | **`dbDelta()` aplica coluna nova e mudança de tipo.** | Mesma sessão: `["Added column ...probe_new_col"]` e `["Changed type of ...payment_address from varchar(255) to VARCHAR(300)"]`. |
| F3 | **`dbDelta()` parseia linha a linha; duas colunas na mesma linha = a segunda é ignorada, sem erro.** | Primeira versão do teste tinha duas definições na mesma linha; a segunda coluna não foi criada e `dbDelta` reportou `(nada)`. |
| F4 | **`dbDelta()` nunca remove** coluna nem índice. | Comportamento documentado do WP core; consistente com F1–F3. |
| F5 | **`get_by_order_id()` usa `INNER JOIN`** nas tabelas de derivação, então uma linha sem carteira/índice **não é legível** mesmo que exista. | Leitura de `pay-crypto-me-db-statements-service.php:57-67`. |
| F6 | **Não existe CI** no repositório (sem `.github/workflows`, sem outro runner). Todo teste que exige ambiente é rodado à mão. | `ls -a .github/workflows` e busca por outros runners. |
| F7 | **A suíte unitária não pode pegar problemas de `dbDelta`**, porque usa shims de `wpdb`. F1 só apareceu ao rodar contra MySQL real. | `tests/_support/wp-helpers.php`, `tests/phpunit/unit/ActivateDbDeltaTest.php` (define seu próprio `dbDelta` de mentira). |

**Consequência direta de F1:** a modelagem "tornar `derivation_index_id`/`wallet_xpubkeys_id`
nullable + bump de `DB_VERSION`" **foi descartada**. Ela passaria nos testes unitários, funcionaria
em instalação nova e falharia só em quem atualizou — exatamente a classe de falha silenciosa que
este plano existe para eliminar.

---

## Decisões já tomadas (não reabrir sem motivo novo)

| Decisão | Razão |
|---|---|
| **Sem mudança de schema** para o item 1. Sentinela `0` nas colunas de derivação + `LEFT JOIN`. | F1 impede a alternativa nullable. `wallet_xpubkeys_id = 0` nunca colide: `wallet_xpubkeys.id` é `AUTO_INCREMENT`, começa em 1. |
| **Forward-only e aditivo** em vez de rollback/down-migrations. | As 4 tabelas são o histórico financeiro da loja — é por isso que `uninstall.php` não as apaga. Down-migration que remove coluna destrói registro que não existe em outro lugar. O caso real de "voltar atrás" é o usuário instalar versão antiga do plugin; contra isso protege a invariante aditiva, não reversão. |
| **Não usar a suíte oficial de testes do WordPress** (`wp scaffold plugin-tests`). | O ganho dela é isolamento em CI, e não há CI (F6). A trilha de integração segue o padrão que já funciona no repo: script + container, como `scripts/smoke-minimal-host.sh`. |
| **Passos de migração versionados ficam para depois** (frente C). | Sem uma migração real, a forma dela seria especulação. Com a rede de testes (frente B) montada, adicioná-la depois é barato e seguro. |
| **Suíte unitária continua rápida e sem WordPress.** A trilha MySQL é uma suíte separada, opt-in. | Preserva o ciclo de feedback atual (~5s para a suíte inteira). |

---

## Frente 1 — Registrar pagamento a endereço fixo

**Objetivo:** todo pedido pago on-chain tem uma linha em
`{prefix}paycrypto_me_bitcoin_transactions_data`, inclusive quando o merchant usa endereço fixo.

### 1.1 `includes/services/pay-crypto-me-db-statements-service.php`

**(a) Constante da sentinela.** Adicionar na classe:

```php
/**
 * wallet_xpubkeys_id used for a payment to a fixed address: there is no extended public key and
 * no derivation index involved. Zero can never collide with a real row — wallet_xpubkeys.id is
 * AUTO_INCREMENT and starts at 1 — so `WHERE wallet_xpubkeys_id = 0` selects exactly the
 * fixed-address payments.
 *
 * A sentinel instead of NULL because dbDelta() does not apply NOT NULL -> NULL (verified against
 * MySQL 8), so making those columns nullable would silently leave already-published installs
 * unchanged while working on fresh ones.
 */
public const WALLET_ID_STATIC_ADDRESS = 0;
```

**(b) `get_by_order_id()`: `INNER JOIN` → `LEFT JOIN`** nos dois joins (linhas ~60-61).
Comentário obrigatório explicando o porquê (senão alguém "otimiza" de volta):

```php
// LEFT JOIN, not INNER: a payment to a fixed address has no wallet key and no derivation index
// (wallet_xpubkeys_id = WALLET_ID_STATIC_ADDRESS), and an INNER JOIN would silently drop its row.
// Derived payments always have matching rows, so their result is unchanged.
```

Efeito: linhas estáticas voltam com `derivation_index`, `xpub` e `network` = `null`. Isso é a
verdade, e é o que os consumidores devem esperar.

**(c) Novo método**, delegando ao `insert_address()` existente (nada de SQL duplicado):

```php
/**
 * Records a payment to a fixed address — no derivation index, no wallet key.
 *
 * Delegates to insert_address() so both flows share one INSERT and the same
 * exists_for_order() guard.
 */
public function insert_static_address(int $order_id, string $payment_address): bool
{
    return $this->insert_address(
        $order_id,
        self::WALLET_ID_STATIC_ADDRESS,
        $payment_address,
        self::WALLET_ID_STATIC_ADDRESS
    );
}
```

### 1.2 `includes/processors/class-bitcoin-payment-processor.php`

O ramo estático (hoje `process()`, ~linhas 49-54) retorna direto pelo `finalize()` sem escrever.
Passa a resolver o endereço por um método novo, espelhando o `resolve_derived_address()`:

```php
if ($this->bitcoin_address_service->validate_bitcoin_address($xPub, $bitcoin_network)) {
    $payment_address = $this->resolve_static_address($order, $xPub);

    $payment_data['payment_address'] = $payment_address;
    $payment_data['payment_uri']     = $this->build_payment_uri($order, $payment_address, $payment_data['crypto_amount']);

    return $this->finalize($payment_data, $order);
}
```

```php
/**
 * Returns the address this order is paid to, persisting the payment record on first use.
 *
 * Mirrors resolve_derived_address()'s reuse branch and AbstractLightningProcessor::process():
 * WooCommerce reuses the same order across checkout retries and the order-pay endpoint, and the
 * order's own meta is written with add_meta_data(..., true) — so the address the customer first
 * saw must win, even if the merchant changes the configured one afterwards.
 *
 * @throws PayCryptoMePaymentException when the record cannot be persisted — the order meta would
 *                                     otherwise claim a payment the DB has no row for.
 */
private function resolve_static_address(\WC_Order $order, string $address): string
{
    $existing = $this->db->get_by_order_id((int) $order->get_id());

    if ($existing && !empty($existing['payment_address'])) {
        return $existing['payment_address'];
    }

    if (!$this->db->insert_static_address((int) $order->get_id(), $address)) {
        throw new PayCryptoMePaymentException(
            \sprintf('Failed to persist fixed-address payment for order #%s', esc_html((string) $order->get_id())),
            esc_html__('We could not register your payment. Please try again or contact the store.', 'paycrypto-me-for-woocommerce')
        );
    }

    return $address;
}
```

**Notas para quem implementa:**
- `payment_data['derivation_index']` **continua ausente** no ramo estático. Um teste fixa isso.
- Se o merchant trocar de xPub para endereço fixo (ou vice-versa), um pedido antigo reprocessado
  reusa a linha que já existe. Comportamento correto: o cliente pode já ter o QR antigo.
- A mensagem user-facing nova precisa de tradução (ver "Traduções").

### 1.3 Testes da frente 1

Em `src/trunk/tests/phpunit/unit/` (suíte unitária, sem MySQL):

| Teste | Afirma |
|---|---|
| `test_static_address_payment_is_persisted` | `insert_static_address()` chamado com o endereço; retorno contém `payment_address`/`payment_uri`. |
| `test_static_address_payment_reuses_the_existing_record` | Com linha existente, **não** insere e devolve o endereço da linha (não o das settings). |
| `test_static_address_payment_raises_when_it_cannot_persist` | `insert_static_address()` retornando `false` levanta `PayCryptoMePaymentException`. |
| `test_static_address_payment_has_no_derivation_index` | `derivation_index` ausente no payment_data. |
| `test_insert_static_address_uses_the_sentinel_wallet_id` | Fake `wpdb` recebe `wallet_xpubkeys_id = 0` e `derivation_index_id = 0`. |

Reaproveitar o fake `wpdb` de `tests/phpunit/unit/PayCryptoMeDBStatementsServiceTest.php` e o
padrão de mocks de `BitcoinPaymentProcessorTest.php`.

---

## Frente A — Segurança do mecanismo de upgrade

Arquivo: `includes/services/class-db-installer.php` (e o registro de hooks em
`src/trunk/paycrypto-me-for-woocommerce.php`).

### A.1 Nunca rebaixar a versão gravada

`maybe_upgrade()` hoje compara com `!==`. Uma option **maior** que o `DB_VERSION` do código
(usuário instalou uma versão antiga do plugin) conta como "diferente" e re-roda os activators,
podendo rebaixar o valor gravado.

```php
if (version_compare((string) get_option(self::VERSION_OPTION, '0'), self::DB_VERSION, '>=')) {
    return;
}
```

### A.2 Tirar o upgrade do page load do cliente

Hoje `DbInstaller::maybe_upgrade()` é chamado no construtor de `WC_PayCryptoMe`, que roda em
`plugins_loaded` — **em toda requisição, inclusive de visitante**. Um `ALTER` em
`paycrypto_me_bitcoin_transactions_data` (que cresce com os pedidos) cairia no page load de um
cliente.

- Remover a chamada direta do construtor.
- Registrar: `add_action('admin_init', [DbInstaller::class, 'maybe_upgrade'])` e
  `add_action('upgrader_process_complete', [DbInstaller::class, 'maybe_upgrade'])`
  (esse último recebe argumentos — usar um wrapper que os descarta).

**Invariante que substitui o disparo no front-end** (declarar no CLAUDE.md):

> Nenhum caminho de pagamento pode depender de coluna/tabela introduzida por uma versão de schema
> mais nova que a gravada. Código novo que precise disso deve consultar
> `DbInstaller::is_current()` e degradar, nunca assumir.

Adicionar então:

```php
public static function is_current(): bool
{
    return version_compare((string) get_option(self::VERSION_OPTION, '0'), self::DB_VERSION, '>=');
}
```

### A.3 Serializar o install

Duas requisições admin simultâneas com versão obsoleta rodam `ALTER` ao mesmo tempo. Envolver o
corpo de `install()` num lock MySQL, **reusando o padrão que já existe** em
`PayCryptoMeDBStatementsService::reserve_derivation_index_for_wallet()`
(`SELECT GET_LOCK(%s, %d)` … `RELEASE_LOCK` num `finally`). Nome do lock:
`paycrypto_me_db_install`. Se não obtiver o lock, retornar `false` sem gravar versão (outra
requisição está cuidando) — **sem** gravar erro em `paycrypto_me_db_activation_errors`, porque não
é falha.

### A.4 Testes da frente A

Unitários, estendendo `tests/phpunit/unit/DbInstallerTest.php`:

| Teste | Afirma |
|---|---|
| `test_maybe_upgrade_does_nothing_when_the_recorded_version_is_newer` | option `'9'` + `DB_VERSION '1'` → nenhum activator roda, option intacta. |
| `test_maybe_upgrade_runs_when_the_recorded_version_is_older` | option `'0'` → roda. |
| `test_is_current_reflects_the_recorded_version` | ambos os sentidos. |
| `test_install_gives_up_quietly_when_the_lock_is_held` | fake `wpdb` devolvendo `GET_LOCK = 0` → `false`, sem erro registrado, sem bump. |

---

## Frente B — Trilha de teste contra MySQL real

**É a peça que habilita todo o resto** (F7): hoje nenhum teste pode observar o comportamento do
`dbDelta`. Fazer a frente C sem isso seria mecanismo validado por inspeção.

### B.1 Como roda

Novo `scripts/schema-tests.sh`, **no mesmo molde de `scripts/smoke-minimal-host.sh`** (copiar a
estrutura: detecção `docker compose` vs `docker-compose`, checagem de serviço no ar, cores, exit
code). Ele executa a suíte de integração **dentro do container `wordpress`**, onde existem
`$wpdb` e `dbDelta` reais.

- Bootstrap: `src/trunk/tests/integration/bootstrap.php`, que faz
  `require '/var/www/html/wp-load.php'` e depois
  `require_once ABSPATH . 'wp-admin/includes/upgrade.php'`.
- Config: `src/trunk/phpunit-integration.xml.dist`, suíte apontando para `./tests/integration`.
- Invocação (dentro do container, cwd no plugin):
  `./vendor/bin/phpunit -c phpunit-integration.xml.dist`
- A `phpunit.xml.dist` atual **não muda** e **não** inclui `tests/integration` (ela varre
  `./tests/phpunit`, então já está isolada).

### B.2 Isolamento sem banco separado

Os activators derivam os nomes das tabelas de `$wpdb->prefix`. Então cada teste troca o prefixo,
cria seu próprio namespace e limpa depois — sem tocar nas tabelas do site de dev e sem malabarismo
de credenciais.

Um `SchemaTestCase` base com:
- `setUp()`: guarda `$wpdb->prefix`, o valor das options `paycrypto_me_db_version` /
  `paycrypto_me_db_activation_errors` e do transient de retry; troca o prefixo para algo único
  (ex.: `pcmit_<n>_`).
- `tearDown()`: `DROP TABLE IF EXISTS` nas 4 tabelas do prefixo de teste; restaura prefixo,
  options e transient.
- `schema_fingerprint(string $table): array` — **comparação canônica e insensível a ordem**:
  monta um mapa `coluna => [type, null, default, extra]` a partir de `SHOW COLUMNS` e um mapa
  `índice => [colunas, unique]` a partir de `SHOW INDEX`, ambos ordenados por nome.
  **Não** comparar `SHOW CREATE TABLE` cru: `dbDelta` adiciona coluna nova no fim, enquanto
  instalação nova a cria na posição declarada — a ordem difere legitimamente.

### B.3 Snapshots de schema congelados

`src/trunk/tests/schema/v1.sql` — as 4 `CREATE TABLE` **exatamente como estão publicadas na
v0.1.0**, com placeholder de prefixo (ex.: `{PREFIX}`) que o teste substitui.

Gerar **agora**, enquanto a v1 é o que está no ar: rodar os activators num prefixo limpo e
extrair `SHOW CREATE TABLE`. Um `tests/bin/dump-schema.php` (precedente:
`tests/bin/generate_vectors.php`) deixa isso repetível para as versões futuras.

**Regra permanente:** todo bump de `DB_VERSION` acompanha um `tests/schema/v<N>.sql` novo. O teste
de convergência varre `tests/schema/v*.sql`, então cada versão histórica passa a ser coberta
automaticamente.

### B.4 Os testes

Em `src/trunk/tests/integration/`:

| Teste | Afirma | Pega qual defeito |
|---|---|---|
| **`test_upgrade_from_each_frozen_version_converges_to_a_fresh_install`** | Para cada `tests/schema/v*.sql`: criar as tabelas daquele snapshot, rodar `DbInstaller::install()`, e comparar `schema_fingerprint()` de cada tabela com o de uma instalação nova. Têm que ser **idênticos**. | **F1** — declaração silenciosamente ignorada pelo `dbDelta`. É o teste que teria pegado o problema de nullability. Também pega F3 (coluna que nunca foi criada) e F4 (coluna que deveria sair e não sai). |
| `test_install_is_idempotent` | `install()` duas vezes: o fingerprint não muda e a segunda execução não registra erro. | `dbDelta` emitindo ALTER repetido (churn) por definição mal formatada. |
| `test_version_is_not_recorded_when_a_table_fails` | Provocar falha real (ex.: criar previamente uma tabela incompatível que force erro no `dbDelta`), então `install()` → `false`, `paycrypto_me_db_version` **não** gravada, transient de retry setado. | Versão marcada como concluída sobre schema quebrado. |
| `test_version_is_never_downgraded` | Gravar `'9'`, chamar `maybe_upgrade()`, conferir que continua `'9'`. | Downgrade de plugin rebaixando a option (A.1). |
| `test_fresh_install_records_the_current_version` | Caminho feliz ponta a ponta. | Regressão básica. |

### B.5 Tornar obrigatório

Sem CI (F6), o único ponto de imposição é o checklist de release. Em `docs/GUIDE-RELEASE.md`:
- Adicionar linha na tabela da seção **Pré-requisitos**, no mesmo formato da linha do smoke test.
- Nova seção curta explicando o que a trilha cobre e por que ela existe, ao lado da seção
  "Smoke de Host Mínimo (passo obrigatório antes de gerar release)".

---

## Frente C — Passos de migração versionados (adiar)

**Não implementar agora.** Registrar o contrato no CLAUDE.md para quem escrever a primeira:

- `dbDelta()` continua sendo a baseline declarativa e roda primeiro.
- O que o `dbDelta` não cobre (F1, F4, backfill, rename) vira um passo imperativo, ordenado por
  versão de destino, **idempotente** (checa `information_schema` antes de agir) e com
  **verificação de pós-condição** (confirma o resultado e devolve sucesso/erro, alimentando o
  mesmo `paycrypto_me_db_activation_errors`).
- `install()` = `dbDelta` → passos pendentes → gravar versão **só se tudo verificou**.
- Aditivo e forward-only. Nada de remover coluna com dado de pagamento.
- Todo passo novo vem com um `tests/schema/v<N>.sql` e é coberto pelo teste de convergência.

---

## Documentação a atualizar

| Arquivo | O que |
|---|---|
| `CLAUDE.md` | Seção nova sobre o mecanismo de schema: o que `dbDelta` cobre e o que **não** cobre (F1–F4, com o método de verificação), a invariante forward-only/aditiva, a regra do `is_current()` (A.2), a regra do snapshot por versão (B.3) e o contrato da frente C. Atualizar a descrição de `paycrypto_me_bitcoin_transactions_data` mencionando a sentinela `WALLET_ID_STATIC_ADDRESS`. Atualizar contagem de testes. |
| `docs/GUIDE-RELEASE.md` | Pré-requisito + seção da trilha de schema (B.5). |
| `src/trunk/CHANGELOG.md` | Em `## Unreleased`: item de `### Fixed` para o registro de endereço fixo; itens de `### Fixed` para A.1/A.2/A.3. A frente B é infra de teste — não vai para o changelog do usuário. |

## Traduções

A frente 1 introduz **uma** string user-facing (a mensagem de falha ao persistir). Os 7 locales
estão a 100% e precisam continuar. Fluxo canônico (ver `docs/GUIDE-TRANSLATION.md`, rodar da **raiz do
repo**, com o container `wordpress` no ar):

```bash
./scripts/build-translations.sh pot
for L in pt_BR es_ES de_DE fr_FR it_IT ru_RU zh_CN; do ./scripts/build-translations.sh po $L; done
# preencher os msgstr vazios/fuzzy nos 7 .po
for L in pt_BR es_ES de_DE fr_FR it_IT ru_RU zh_CN; do ./scripts/build-translations.sh mo $L; done
rm -f src/trunk/languages/*.po~   # backups do msgmerge, fora do release mas sujam a árvore
```

Conferir 0 untranslated e 0 fuzzy por locale com `msgattrib` dentro do container antes de fechar.

---

## Ordem de execução e dependências

```
Frente 1  ──────────────►  independente. Não toca em schema. Pode ir primeiro e sozinha.
Frente A  ──────────────►  independente da 1. Só mexe em DbInstaller + registro de hooks.
Frente B  ──── depende ──►  de A (os testes B.4 afirmam o comportamento de A.1)
Frente C  ──── depende ──►  de B (sem a rede, o mecanismo é fé). Adiada.
```

Sugestão: **1 → A → B**, em commits separados, para que uma revisão possa isolar o que é correção
de comportamento (1, A) do que é infra de teste (B).

**Base:** o trabalho segue na branch `fix/honest-failure-reporting`, onde este documento foi
adicionado — **não** em `main`. Motivo técnico: o `DbInstaller` (frentes A/B/C) e o
`EnvironmentRequirements` só existem nessa branch; partir de `main` obrigaria a refazer essa
encanação. A branch está pendente de validação manual do solicitante; merge, bump de versão e
submissão ao SVN acontecem depois disso.

---

## Verificação

Rodar da **raiz do repo**. O stack de dev precisa estar no ar para tudo que envolve MySQL:

```bash
docker compose up -d wordpress wp_db
```

> **Compose:** os comandos abaixo usam a forma `docker compose` (plugin v2), como o resto do repo.
> Num host que só tem o binário standalone — **é o caso desta máquina** —, troque por
> `docker-compose` em todos eles. Os scripts (`release.sh`, `smoke-minimal-host.sh`,
> `build-translations.sh`) detectam as duas formas sozinhos; só os comandos colados à mão precisam
> da troca.

| # | Comando | Esperado |
|---|---|---|
| 1 | `docker compose run --rm release ./vendor/bin/phpunit` | Suíte unitária verde, incluindo os novos testes das frentes 1 e A. Baseline antes de começar: **371 testes, 828 asserções, 4 skipped** (era 363/755/4 até 2026-08-17 e 367/760/4 até 2026-08-18; a auditoria pré-merge das branches de vendor somou `PhpFloorConsistencyTest`, o teste de factory injetada e mais âncoras no `VendorReplaceGuardTest`). **Meça o baseline você mesmo antes de começar** em vez de confiar neste número — outra frente pode ter mexido nele. |
| 2 | `./scripts/schema-tests.sh` | Trilha de integração verde (frente B). |
| 3 | `./scripts/smoke-minimal-host.sh` | Todos os checks passando (não deve regredir). |
| 4 | `docker run --rm -v $(pwd)/src/trunk:/plugin -w /plugin php:8.3-cli php ./vendor/bin/phpunit --filter OnchainWithoutGmpTest` | 10 testes, 1 skipped (o teste do caso *com* GMP se auto-pula). Os 4 skipped do item 1 só são observáveis num host **sem** a extensão GMP. |
| 5 | `docker compose exec -T wordpress wp --allow-root plugin check paycrypto-me-for-woocommerce --format=csv` | Nenhum `ERROR` em código enviado. Erros em `tests/`, `vendor/`, `phpunit.xml.dist`, `.phpunit.result.cache` e `*.po~` são esperados — o `release.sh` já exclui esses caminhos. **Atenção:** a lista de `--exclude` do `release.sh` cobre `tests` (portanto `tests/integration/` também), mas casa `phpunit.xml.dist` **literalmente** — ao criar `phpunit-integration.xml.dist`, adicionar essa exclusão, senão o arquivo vai para o zip publicado. |

### Verificação manual da frente 1 (WordPress real)

```bash
# Configurar o gateway On-Chain com um endereço fixo, fazer um pedido, e conferir a linha:
docker compose exec -T wordpress wp --allow-root db query \
  "SELECT order_id, payment_address, derivation_index_id, wallet_xpubkeys_id
   FROM wp_paycrypto_me_bitcoin_transactions_data WHERE wallet_xpubkeys_id = 0"
```

Esperado: uma linha por pedido, com o endereço fixo e `0`/`0` nas colunas de derivação. Repetir o
checkout do **mesmo** pedido (endpoint `order-pay`) não deve criar segunda linha nem trocar o
endereço.

---

## Pendência não especificada

O solicitante mencionou **duas** pendências e descreveu apenas a primeira (registro de endereço
fixo, frente 1). **A segunda nunca foi informada** e não está coberta por este plano.

Antes de considerar a demanda encerrada, perguntar qual é o item 2 e avaliar se ele muda alguma
decisão aqui — em especial se envolver schema, porque aí a frente C pode deixar de ser adiável.
