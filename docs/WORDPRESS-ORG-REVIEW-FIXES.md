# Plano — Correções da Review do WordPress.org (PayCrypto.Me for WooCommerce)

> **Documento de projeto — auto-contido.** Guia para resolver a review pendente do WordPress.org.
> Escrito para ser seguido por **qualquer agente ou pessoa do time** sem depender de nenhuma
> conversa anterior: todos os caminhos são `repo-relativos` e clicáveis. A fonte de verdade da
> arquitetura do plugin continua sendo o `CLAUDE.md` (raiz do repo); este arquivo cobre apenas a
> rodada de correções da review.
>
> **Review ID (responder na MESMA thread de `plugins@wordpress.org`):**
> `AUTOPREREVIEW paycrypto-me-for-woocommerce/paycryptome/20Jul26/T1 20Jul26/4.2A2 (P0TDX343130HGN)`.
> **Permalink/slug (não alterar):** `paycrypto-me-for-woocommerce`.

## O que o reviewer sinalizou (resumo auto-contido)

O email da review aponta:

1. **🔴 Usar `wp_enqueue`** — remover tags `<script>`/`<style>` inline e enfileirar via
   `wp_enqueue_script`/`wp_enqueue_style` (ou `wp_add_inline_*`). Casos citados:
   `templates/order-details/paycrypto-me-order-details.php:91` (`<script>`) e
   `includes/abstract-class-wc-gateway-paycrypto-me.php:75` (`<style>`).
2. **🔴 Sanitização de inputs** — o nonce do checkout em
   `includes/processors/class-payment-processor.php:151` chega ao `wp_verify_nonce` sem
   `sanitize_text_field( wp_unslash( ... ) )`.
3. **🔴 `composer.json` ausente** — o plugin usa Composer mas o `composer.json` não está no pacote
   (`paycrypto-me-for-woocommerce/composer.json` não encontrado).
4. **🟡 Arquivos de tradução incluídos** — `.po/.mo` empacotados; o WP.org sugere usar o
   translate.wordpress.org. (Decisão do time: **manter** — ver Decisões e Tarefa FINAL.)

O reviewer também referencia o **Plugin Check (PCP)** como validador e pede para **procurar outras
ocorrências dos mesmos problemas**.

## Contexto

O objetivo é **resolver todos os 4 pontos do reviewer** e, junto, os **pontos extras** levantados
por uma auditoria completa da codebase (inline JS/CSS; sanitização/segurança; i18n/compliance), para
a **re-submissão passar em menos ciclos**. A auditoria confirmou que as ocorrências citadas acima são
as **únicas** do tipo no código, e levantou itens adjacentes que o PCP pode sinalizar (Parte 2). A
verificação final roda o **Plugin Check** no ambiente Docker do projeto (`docker-compose.yml` na raiz).

**Decisões (já tomadas):**
- **Traduções:** MANTER os `.po/.mo` empacotados **e** adicionar `load_plugin_textdomain()` (hoje
  os `.mo` embutidos nem carregam). Justificar a manutenção na resposta ao reviewer.
- **`Requires at least`:** subir de `5.3` → **`6.5`** (alinha com o header `Requires Plugins`, que é
  recurso do WP 6.5+, e com `Requires PHP: 8.1`).
- **`Tested up to`:** manter **`7.0`** (versão já lançada e testada).
- **Sem bump de versão:** 0.1.0 ainda não foi publicada; re-upload de uma 0.1.0 corrigida durante a
  review é o fluxo normal. Changelog inalterado.

---

## Parte 1 — Pontos sinalizados pelo reviewer (obrigatórios)

### 1.1 — `<script>`/`<style>` inline → enfileirar (wp_enqueue)

A auditoria confirmou **apenas 2** emissões inline em todo o código (nenhuma outra): o `<script>`
do botão "copiar endereço" e o `<style>` do admin.

**A) `<script>` do botão copiar** — [templates/order-details/paycrypto-me-order-details.php:91-102](../src/trunk/templates/order-details/paycrypto-me-order-details.php#L91-L102)
- Criar **novo** arquivo `src/trunk/assets/js/paycrypto-me-order-details.js` com a IIFE atual
  (não há `assets/js/` hoje — é greenfield; não é entrada webpack, é asset estático).
- Remover o bloco `<script>…</script>` do template.
- Enfileirar em `render_checkout_order_details_section()` ([abstract:79-105](../src/trunk/includes/abstract-class-wc-gateway-paycrypto-me.php#L79-L105)),
  **após** o guard `$args === null` e antes do `wc_get_template`, com
  `wp_enqueue_script('paycrypto-me-order-details', … '/assets/js/paycrypto-me-order-details.js', [], filemtime(...), true)`.
  - **Por que nesse ponto (e não em `enqueue_checkout_styles`):** o botão copiar também renderiza na
    **tela de edição de pedido no admin** (o template é reusado via `render_admin_order_details_section`
    → `render_checkout_order_details_section`). `enqueue_checkout_styles` só roda no `wp_enqueue_scripts`
    (frontend). Enfileirar no ponto de render cobre **frontend + admin** num único lugar e só quando a
    seção realmente aparece. Enfileirar durante o corpo da página imprime no `wp_footer`/`admin_footer` — válido.
  - Atenção à assimetria: `plugin_url()` **sem** barra final / `plugin_abspath()` **com** barra final
    ([main:64-72](../src/trunk/paycrypto-me-for-woocommerce.php#L64-L72)). Usar guard `file_exists` no `filemtime` (padrão de `enqueue_checkout_styles`).

**B) `<style>` do admin** — [abstract:75](../src/trunk/includes/abstract-class-wc-gateway-paycrypto-me.php#L75) em `render_admin_order_details_section()`
- Remover o `echo '<style>…</style>';`.
- Criar **novo** `src/trunk/assets/css/admin/paycrypto-me-order-details-admin.css` com as 2 regras
  atuais (`.paycrypto-me-order-details { clear:both }` + margens do `h3`).
- **NÃO** enfileirar a partir de `render_admin_order_details_section()` — esse método roda no hook
  `woocommerce_admin_order_data_after_order_details`, que dispara **durante o render do corpo da
  página** (dentro dos metaboxes), ou seja, **depois** que `admin_print_styles` já imprimiu o
  `<head>`. Um `wp_enqueue_style()` ali seria tarde demais (ao contrário de scripts com
  `$in_footer = true`, não existe um "footer pass" padrão para estilos no admin do WordPress) —
  é exatamente por isso que o código original usava `echo` inline, que funciona em qualquer ponto.
- Em vez disso, enfileirar dentro do método **`admin_enqueue_scripts()`** já existente na classe
  abstrata (hook `admin_enqueue_scripts`, que roda cedo, antes do `<head>` fechar), com um novo
  bloco condicionado à tela de pedido (`$screen->id === 'woocommerce_page_wc-orders' ||
  $screen->id === 'shop_order'`) — mesmo padrão de screen-check já usado ali para os assets da
  página de configurações. Por estar no método **compartilhado** da classe abstrata (não no
  `admin_enqueue_scripts_content()`, que cada gateway sobrescreve independentemente), cobre os 2
  gateways de uma vez — inclusive o Lightning, cujo `admin_enqueue_scripts_content` retorna cedo em
  telas de pedido ([lightning:72-74](../src/trunk/includes/class-wc-gateway-paycrypto-me-lightning.php#L72-L74))
  e por isso nunca enfileirou esse CSS antes.

### 1.2 — Sanitização do nonce — [class-payment-processor.php:147-157](../src/trunk/includes/processors/class-payment-processor.php#L147-L157)

Único input realmente não sanitizado em produção (confirmado pela auditoria — todo o resto já usa
`wp_unslash`+`sanitize_*`, todas as queries usam `$wpdb->prepare`, todo output é escapado).

- Remover o `$post = wp_unslash( $_POST );` em bloco (linha 147) — o analisador estático não
  rastreia esse padrão.
- **Nonce:** referenciar `$_POST` direto e sanitizar (padrão exato pedido pelo reviewer):
  ```php
  if ( isset( $_POST['woocommerce-process-checkout-nonce'] ) ) {
      $checkout_nonce = sanitize_text_field( wp_unslash( $_POST['woocommerce-process-checkout-nonce'] ) );
      if ( ! wp_verify_nonce( $checkout_nonce, 'woocommerce-process_checkout' ) ) {
          throw new PayCryptoMePaymentException('Security check failed during checkout.');
      }
  }
  ```
- **`paycrypto_me_crypto_currency`** (linha 156): trocar leitura via `$post` por
  `strtoupper( sanitize_text_field( wp_unslash( $_POST['paycrypto_me_crypto_currency'] ) ) )` e
  precedê-la de um `// phpcs:ignore WordPress.Security.NonceVerification.Missing -- …` justificando
  (o WC core valida o nonce do checkout **antes** do `process_payment`; o checkout via Blocks/Store API
  usa nonce REST próprio; e o clássico é revalidado acima quando presente).
  - **NÃO** tornar a verificação incondicional: fluxos express/Blocks legítimos não postam esse nonce
    clássico (ver comentário existente na linha 159) — torná-la obrigatória quebraria esses fluxos.

### 1.3 — `composer.json` ausente no pacote — [scripts/release.sh:342-346](../scripts/release.sh#L342-L346)

Causa raiz: o script de release **deleta** `composer.json`/`composer.lock`/`package.json` do build
("nada lê em runtime"). O reviewer quer o `composer.json` no pacote (transparência open-source).

- Remover o `rm -f … composer.json composer.lock package.json` (manter os 3 no zip — poucos KB,
  transparência total e `composer install` reprodutível a partir do pacote).
- Atualizar a descrição do passo 7 (Vendor cleanup) em [docs/RELEASE.md](./RELEASE.md) para refletir que
  o `composer.json` agora é retido.
- Resultado: `paycrypto-me-for-woocommerce/composer.json` presente na raiz do zip.

### 1.4 — Traduções (MANTER + corrigir carregamento) 🟡

Decisão: manter os `.po/.mo` no pacote. **Não** alterar o `release.sh` para traduções
(o rsync já os inclui e exclui só backups `*~`/`*.po~`). Porém há uma **lacuna real**: não existe
nenhum `load_plugin_textdomain()` — o comentário em [main:130](../src/trunk/paycrypto-me-for-woocommerce.php#L130)
("handled by WordPress") só vale para *language packs*; os `.mo` embutidos **não carregam** em WP < 6.7.

- Substituir o comentário da linha 130 por carregamento real: registrar
  `add_action('init', …)` na classe principal chamando
  `load_plugin_textdomain('paycrypto-me-for-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages/')`.
  Hook em **`init`** (não antes) para evitar o aviso `_doing_it_wrong` de just-in-time do WP 6.7+.
- Na resposta ao reviewer: justificar brevemente a manutenção das traduções (mantidas pelo autor,
  7 locales 100%), confirmando i18n completo (text domain, Domain Path e agora carregadas explicitamente).

---

## Parte 2 — Pontos extras encontrados na auditoria (proativos)

Priorizados: **A** = fazer nesta rodada (barato, evita novo flag do PCP); **B** = limpeza de baixo
risco; **C** = opcional/nota.

### Prioridade A — consistência + hardening que o Plugin Check pode apontar
- **Header `Requires at least: 5.3` → `6.5`** em [main header](../src/trunk/paycrypto-me-for-woocommerce.php#L7) **e** [readme.txt](../src/trunk/readme.txt#L5). (Inconsistente hoje com `Requires Plugins` = WP 6.5+ e `Requires PHP: 8.1`.) `Tested up to: 7.0` permanece.
- **`stripslashes()` → `wp_unslash()`** em [class-lightning-config-validator.php](../src/trunk/includes/validators/class-lightning-config-validator.php) linhas 38, 40, 88, 90 (sniff `ValidatedSanitizedInput`; já sanitizado depois via `esc_url_raw`/`sanitize_text_field` — mudança neutra de comportamento).
- **Comentários `phpcs:ignore` justificados** onde o PCP dá falso-positivo:
  - `@file_get_contents()` de asset local — [class-qr-code-service.php:228](../src/trunk/includes/services/class-qr-code-service.php#L228) (asset empacotado, não remoto/não input do usuário; `AlternativeFunctions`).
  - `base64_decode` do `r_hash` (resposta confiável do lnd) — [class-lnd-rest-invoice-service.php:45](../src/trunk/includes/services/class-lnd-rest-invoice-service.php#L45); `base64_encode` para data-URI — [class-qr-code-service.php:249](../src/trunk/includes/services/class-qr-code-service.php#L249) (heurística de ofuscação).
  - `TRUNCATE` + interpolação `{$table}` dentro de `prepare()` — [pay-crypto-me-db-statements-service.php:263-270](../src/trunk/includes/services/pay-crypto-me-db-statements-service.php#L263-L270) (nome de tabela derivado de `$wpdb->prefix` + `esc_sql`; conferir se o `phpcs:ignore` existente cobre `DirectDatabaseQuery`/`InterpolatedNotPrepared`).

### Prioridade B — limpeza de baixo risco
- **Código morto:** remover a propriedade `$support_btc_payment_address` (código de pagamento BIP47) — [abstract:30](../src/trunk/includes/abstract-class-wc-gateway-paycrypto-me.php#L30). Declarada e **nunca referenciada** em nenhum lugar.
- **Referência de asset morta:** [abstract:303-313](../src/trunk/includes/abstract-class-wc-gateway-paycrypto-me.php#L303-L313) enfileira `assets/css/frontend/paycrypto-me-styles.css` que **não existe** no disco (protegido por `file_exists`, então é no-op silencioso). Remover o bloco morto (ou criar o arquivo se estilo de checkout for realmente pretendido — recomendo remover).
- **Higiene do repo** (não vão pro zip, mas sujam a árvore): apagar os `src/trunk/languages/*.po~` (7 backups locais, não versionados) e o caminho acidental `src/trunk/tests/..wp-admin/includes/upgrade.php`.

### Prioridade C — opcional / apenas registro (baixíssimo valor)
- Atributos `style="..."` inline (7 pontos: [main:101](../src/trunk/paycrypto-me-for-woocommerce.php#L101), abstract 267/391, bitcoin 176, lightning 249, template 65/66) — o review **NÃO** sinaliza atributos `style`, só tags `<style>`/`<script>`. Deixar; mover pra CSS depois se quiser.
- `assets/blocks/*.asset.php` sem guard `ABSPATH` — output do webpack, risco baixíssimo; exigiria mudança na config do webpack.
- Campos só-de-readme redundantes no header PHP (`Tested up to`, `Contributors`, `Donate link`) — cosmético.
- **(Follow-up i18n, opcional):** gerar as traduções JSON de JS (`wp i18n make-json`) para que as
  strings dos blocos/checkout também traduzam a partir dos arquivos embutidos — hoje
  `wp_set_script_translations` ([class-asset-manager.php:51](../src/trunk/includes/utils/class-asset-manager.php#L51)) aponta pra `/languages` sem `.json`. Não é blocker.

---

## Arquivos a modificar / criar

**Correções do reviewer:**
- `src/trunk/templates/order-details/paycrypto-me-order-details.php` — remover `<script>`.
- `src/trunk/includes/abstract-class-wc-gateway-paycrypto-me.php` — remover `<style>`; add enqueue JS (render compartilhado) + enqueue CSS admin; (B) remover ref. morta de CSS + propriedade morta.
- **NOVO** `src/trunk/assets/js/paycrypto-me-order-details.js` — IIFE do botão copiar.
- **NOVO** `src/trunk/assets/css/admin/paycrypto-me-order-details-admin.css` — 2 regras admin.
- `src/trunk/includes/processors/class-payment-processor.php` — sanitização do nonce + `phpcs:ignore`.
- `src/trunk/paycrypto-me-for-woocommerce.php` — `load_plugin_textdomain` no `init`; `Requires at least` → 6.5.
- `src/trunk/readme.txt` — `Requires at least` → 6.5.
- `scripts/release.sh` — parar de deletar `composer.json` (linhas 342-346).
- `docs/RELEASE.md` — atualizar descrição do passo 7.

**Extras (A/B):**
- `src/trunk/includes/validators/class-lightning-config-validator.php` — `stripslashes`→`wp_unslash`.
- `src/trunk/includes/services/class-qr-code-service.php`, `class-lnd-rest-invoice-service.php`,
  `pay-crypto-me-db-statements-service.php` — comentários `phpcs:ignore` justificados.

---

## Verificação (end-to-end)

Ambiente: `docker compose up -d` (WordPress + WooCommerce do projeto).

1. **Plugin Check (principal — o reviewer cita explicitamente):**
   ```bash
   docker compose exec wordpress wp plugin install plugin-check --activate
   docker compose exec wordpress wp plugin check paycrypto-me-for-woocommerce
   ```
   Confirmar limpo nas categorias sinalizadas (enqueue de scripts/styles; sanitização de input;
   i18n) e nos sniffs extras endereçados.
2. **PHPUnit:** `cd src/trunk && ./vendor/bin/phpunit` — 243 testes verdes. Atenção ao
   `PaymentProcessorTest` (define `$_POST['woocommerce-process-checkout-nonce']`): o refactor lê
   `$_POST` direto, então o teste continua válido; rodar para confirmar.
3. **Smoke manual no WP (Docker):**
   - *Frontend:* pedido de teste (On-Chain e Lightning) → na página order-received, o botão "copiar
     endereço" funciona (JS agora enfileirado) e o layout está intacto. No fonte da página: aparece
     `<script src=".../assets/js/paycrypto-me-order-details.js">` e **nenhum** `<script>` inline.
   - *Admin:* abrir a edição do pedido → estilo da seção PayCrypto aplicado via CSS admin enfileirado,
     **sem** `<style>` inline; botão copiar funciona também aqui.
   - *i18n:* trocar idioma do WP para `pt_BR` → strings traduzidas (valida o `load_plugin_textdomain`).
4. **Inspeção do zip de release:**
   ```bash
   ./scripts/release.sh -v 0.1.0 -s paycrypto-me-for-woocommerce --dry-run   # revisar passos
   ./scripts/release.sh -v 0.1.0 -s paycrypto-me-for-woocommerce             # gera o zip (sem --git)
   unzip -l releases/paycrypto-me-for-woocommerce-0.1.0.zip | grep -E 'composer.json|assets/js/|assets/css/admin|languages/'
   ```
   Confirmar: `composer.json` na raiz; novos JS/CSS presentes; traduções ainda presentes (decisão).

## Tarefa FINAL — Email de resposta ao WordPress.org

> **Ordem:** esta é a **última tarefa**, executada **depois** de gerar e inspecionar o novo release
> (Verificação passo 4). O email só é redigido quando o zip corrigido já está pronto para upload.

O reviewer pediu explicitamente: resposta **curta**, **sem listar cada mudança** ("we don't need
that, we will review the entire plugin again"), mas **incluindo contexto relevante**. Logo, o email
NÃO enumera correções — foca na **única clarificação que exige contexto**: a decisão deliberada de
**manter as traduções empacotadas**.

**Justificativa das traduções (ponto central):** mantê-las por **facilidade e tooling próprio** do
projeto (`scripts/build-translations.sh` / `npm run translate`) que **agiliza o processo** e **não
depende de terceiros** (cobertura da comunidade no translate.wordpress.org). Resultado: 7 locales
100%, completos e disponíveis **imediatamente na ativação**. Reforçar que o plugin segue totalmente
internacionalizado (text domain + Domain Path) e que agora as traduções são carregadas explicitamente
via `load_plugin_textdomain` — as contribuições do translate.wordpress.org ainda se somam por cima.

**Nota de verificação (execução real, pós-implementação):** rodar o Plugin Check localmente
(`wp plugin check paycrypto-me-for-woocommerce`) confirmou que **nenhuma outra ocorrência** dos 4
pontos existe no código que efetivamente é empacotado (tudo que aparece em `tests/`, `vendor/dev`,
`.phpunit.result.cache` e `phpunit.xml.dist` já é excluído do zip por `scripts/release.sh` e não
embarca). O Plugin Check sinaliza `load_plugin_textdomain()` como "discouraged since WP 4.6" — essa
orientação genérica assume que a única fonte de tradução são os language packs do
translate.wordpress.org; ela não cobre o caso de um plugin que **também** empacota seus próprios
`.mo` (que de outra forma nunca carregariam). Vale mencionar isso brevemente na resposta para não
gerar confusão se o revisor rodar o Plugin Check e ver esse aviso.

**Rascunho (inglês, curto — ajustar após o upload):**

```
Hi, and thanks for the review — much appreciated.

We've uploaded an updated version addressing the reported issues.

One clarification, on the bundled translation files: we kept them on purpose. They're
maintained in-house through our own i18n build tooling (7 locales at 100%), which lets us
keep them complete, consistent and available immediately on activation without depending
on third-party/community coverage. The plugin stays fully internationalized (text domain +
Domain Path) and now loads them explicitly via load_plugin_textdomain, so translations from
translate.wordpress.org still layer on top. Plugin Check flags that call as discouraged since
its guidance assumes language-pack-only translations, which doesn't apply to bundled files —
happy to reconsider if you'd still prefer we drop them.

Thanks again!
```

Observações de envio: responder **na mesma thread** do email original (não criar novo); não alterar
o permalink `paycrypto-me-for-woocommerce`; evitar verbosidade/"AI filler".
