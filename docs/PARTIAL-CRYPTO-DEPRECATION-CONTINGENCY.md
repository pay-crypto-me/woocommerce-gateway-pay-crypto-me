# [PARTIAL — MANUAL BROWSER TEST PENDING] Conter as deprecations do `bitwasp/buffertools` que quebram o save das settings On-Chain

> **Status: executado e verificado em 2026-08-16.** A supressão foi implementada em
> `BitcoinAddressService` (`suppress_vendor_deprecations()` + os 5 wraps) e verificada: suíte
> **363/755/4 skipped naquela data** (baseline de hoje: ver `CLAUDE.md`); prova antes/depois em
> processo frio (o caminho vendor cru emite as notices em `CachingTypeFactory.php:376/36/88`, o
> serviço envolvido emite **0 bytes** e a validação segue `true`); `OnchainWithoutGmpTest` 10/17/1 e
> o contrato `\Error` intactos. Falta apenas o teste manual de aceitação no navegador — **seção C,
> passos 2–4**: o passo 1 ("Antes") não reproduz mais nesta branch, porque a correção já está nela.
> Contingência para um defeito observado após a troca para os pacotes oficiais `bitwasp/*` (ver
> [`docs/archive/DONE-CRYPTO-DEPENDENCIES.md`](archive/DONE-CRYPTO-DEPENDENCIES.md) — arquivado/gitignored,
> pode estar ausente no seu checkout).
>
> **Auditoria de execução (2026-08-18).** Ao revisar as duas branches antes do merge, o que a
> seção C ainda cobria foi medido de novo e por fora: os pontos de entrada bitwasp **fora** do wrap
> (`NetworkFactory::bitcoin()/bitcoinTestnet()`, `Bitcoin::getEcAdapter()` e o próprio
> `require vendor/autoload.php`) emitem **0** deprecations, então o conjunto de wraps cobre o
> caminho inteiro do plugin; `validate_extended_pubkey()` e `generate_address_from_xPub()` emitem
> **0 bytes** com `E_ALL` + `display_errors`, devolvem o valor certo e restauram o
> `error_reporting()`; e um `\Error` lançado dentro do wrap propaga. Sobra do C apenas o julgamento
> visual no navegador — a supressão de *display* em si já está provada em processo frio.
>
> **Revalidação independente (2026-08-17):** tudo acima reproduzido contra uma instalação limpa do
> `composer.lock` — o vendor cru imprime **590 bytes** de `Deprecated` (`CachingTypeFactory`
> 376/36/88), o serviço envolvido imprime **0** e devolve `true`; 60/60 vetores; num host sem gmp o
> `\Error` (`Call to undefined function BitWasp\Bitcoin\gmp_init()`) propaga e a rota bech32 segue
> devolvendo `true`/`false` corretamente, sem imprimir nada. Dois ajustes saíram dela: o
> `phpcs:disable` passou a nomear também o sniff próprio do Plugin Check (eram 3 `WARNING` em código
> enviado) e a seção C ganhou o pré-requisito de vendor.
>
> Documento auto-suficiente: quem executar não precisa da conversa que o originou. Prosa em
> português; identificadores, caminhos e nomes de teste em inglês, como no resto do repo.
> Restrição do solicitante: **não editar arquivos dentro de `vendor/`** — mudanças ali se perdem
> num `composer update`. Mexer em `composer.json`/tooling é permitido se necessário; a abordagem
> recomendada abaixo não precisa.

---

## Context

Ao **salvar as settings do gateway On-Chain** (com um xPub/zpub configurado), num host com
**PHP 8.3 + `WP_DEBUG_DISPLAY`** (o container de dev é exatamente isso), a tela imprime:

```
Deprecated: Use of "parent" in callables is deprecated in
  .../vendor/bitwasp/buffertools/src/Buffertools/CachingTypeFactory.php on line 376 (e 36, 88)
Warning: Cannot modify header information - headers already sent by
  (... CachingTypeFactory.php:376) in .../wp-includes/functions.php on line 7220
Warning: Cannot modify header information - headers already sent by (...)
  in .../wp-admin/admin-header.php on line 14
```

**Causa-raiz (medida).** `WC_Gateway_PayCryptoMe::process_admin_options()`
(`includes/class-wc-gateway-paycrypto-me.php:186`) roda no `admin_init` (via a ação
`woocommerce_update_options_payment_gateways_paycrypto_me`), **antes** do `wp_safe_redirect()`
pós-save do WooCommerce. Ele valida o identificador chamando
`BitcoinAddressService::validate_extended_pubkey()` → `convert_extended_pubkey_prefix()`
(`Base58::decodeCheck`) + `HierarchicalKeyFactory::fromExtended()`. Essas operações exercitam o
`bitwasp/buffertools`, que emite notices `E_DEPRECATED` (`Use of "parent" in callables`, de
`CachingTypeFactory.php` linhas 36/88/376) **direto na saída**. Como a saída começa **durante** a
ação de save, o redirect seguinte falha com "headers already sent". Nosso código não dá `echo` — a
saída é 100% da lib.

**Correção de severidade.** O `docs/archive/DONE-CRYPTO-DEPENDENCIES.md` (E6/Decisão/Fora-de-escopo, arquivado — pode estar ausente no seu checkout) classificou
essas notices como "ruído inofensivo, visível só com `WP_DEBUG`" e decidiu **aceitá-las**. Isso
subestimou o impacto: elas **quebram o fluxo de save no admin** em qualquer host com
`display_errors` ligado (dev/staging típico). Daí esta contingência.

**Resultado pretendido.** Salvar as settings On-Chain (e o checkout/derivação) não emite mais essas
notices na saída nem quebra o redirect — **sem editar código de vendor** e **sem violar** o contrato
de "falha honesta" (nunca engolir um `\Error` de extensão ausente).

---

## Fatos estabelecidos por investigação

| # | Fato | Como foi verificado |
|---|---|---|
| G1 | A deprecation dispara no **PHP 8.3.32** do container `wordpress` (não é só 8.4). | `docker compose exec wordpress php -v`; o probe do E6 já media 5 ocorrências no 8.3. |
| G2 | `wp-config.php` tem `WP_DEBUG=true`, `WP_DEBUG_DISPLAY=true`, `WP_DEBUG_LOG=true` → no contexto web o WP liga `display_errors` e reporta `E_DEPRECATED`, então as notices **imprimem**. | `grep WP_DEBUG` no `wp-config.php` do container. |
| G3 | Só **carregar** as classes bitwasp não dispara nada; a deprecation é do **caminho de runtime** (serialização do buffertools). Não dá para "pré-carregar" para resolver. | `php -r 'require autoload; use HierarchicalKeyFactory...'` → "loaded ok", sem notice. |
| G4 | `BitcoinAddressService` é o **único** boundary cujas chamadas bitwasp alcançam a serialização que emite a notice. Os 5 métodos públicos que a alcançam: `generate_address_from_xPub`, `convert_extended_pubkey_prefix`, `validate_segwit_address`, `validate_bitcoin_address`, `validate_extended_pubkey`. | grep de `BitWasp\` em `includes/` (3 arquivos; os outros 2 só usam `NetworkFactory::bitcoin()/bitcoinTestnet()`, que retorna descritor estático e **não** serializa). |
| G5 | Todos os caminhos que serializam (save, checkout via `BitcoinPaymentProcessor`, order-pay) passam por esses 5 métodos. `is_available()`/`unavailability_reasons()` e o render de order-details/QR **não** serializam (leem meta persistida). | Trace de callers em `includes/`. |
| G6 | Contrato da casa: a validação captura **`\Exception` apenas, nunca `\Error`** — GMP ausente vira `\Error` que **propaga** até o `catch (\Error)` do gateway (`WC_Gateway_PayCryptoMe::validate_xpub_address()`/`validate_network_identifier()`), reportado como falha interna/host, jamais como "chave inválida". | Comentário de política acima de `BitcoinAddressService::validate_bitcoin_address()`; catches em `validate_segwit_address()`, `validate_bitcoin_address()` e `validate_extended_pubkey()`. Citado por símbolo, não por número de linha: o helper de supressão empurrou o arquivo e as linhas antigas passaram a cair em comentário. |
| G7 | Não existe `error_reporting`/`set_error_handler`/`ini_set` em código do plugin hoje (só em `vendor/`). Existe o idioma `try {} finally {}` de restauração (limpeza de cert temp; `RELEASE_LOCK`). | grep em `includes/`. |

---

## Decisão de design

Mascarar **apenas** `E_DEPRECATED` via `error_reporting()`, num `try/finally` que **sempre restaura**,
estritamente ao redor das chamadas à lib bitwasp — no único boundary que as faz
(`BitcoinAddressService`).

Por que é seguro e honesto:
- `error_reporting($x & ~E_DEPRECATED)` controla só quais **diagnósticos** o handler padrão
  exibe/loga. **Não** afeta `Throwable` lançado: um GMP ausente continua sendo um `\Error` que
  **propaga** (G6 preservado). O `finally` restaura em retorno normal **e** em exceção. `run()` não
  captura nada.
- Mascara **só** o bit `E_DEPRECATED`; `E_WARNING`/`E_NOTICE`/`E_ERROR`/`E_USER_*` intactos — não
  esconde diagnóstico nosso não relacionado.
- **Não** mascara o futuro fatal do PHP 9 (que vira `Error` lançado, não diagnóstico) — consistente
  com "Horizonte PHP 9" do `docs/archive/DONE-CRYPTO-DEPENDENCIES.md` (arquivado, pode estar ausente).

**Alternativas descartadas:**
- **Patch de vendor / `composer-patches`** — **permitido** pela restrição (a patch é reaplicada no
  `composer install`, não se perde num update), mas **não recomendado**: cobriria só os 3 sítios do
  `CachingTypeFactory` (`parent` in callables), enquanto as 7 deprecations de return-type em
  `bitcoin/src/Script/Opcodes.php`/`Parser.php` continuariam vazando na geração de endereço
  (checkout); e adiciona peça móvel frágil a versão num fluxo de release já validado — ver
  `docs/archive/DONE-CRYPTO-DEPENDENCIES.md` → "Fora de escopo" (arquivado, pode estar ausente). A
  supressão escopada de `E_DEPRECATED` cobre **todas**
  de uma vez, no nosso código.
- **`ob_start()` no save** — só cobriria o save, não o checkout; e engoliria qualquer saída.
- **`set_error_handler` custom** — desde o PHP 8.0 o handler custom é chamado **independente** do
  `error_reporting()`; mais peça móvel, risco de interferir com o handler do WP. Rejeitado.
- **Mask global no bootstrap** — amplo demais; esconderia `E_DEPRECATED` do nosso código e do WP
  core. Rejeitado.
- **Classe util em `includes/utils/`** — rejeitada em favor de método privado (abaixo): consumidor
  único, evita um "martelo" público de silenciar deprecations, e evita o footgun de classmap
  (arquivo novo exigiria `composer dump-autoload`; classmap defasado quebraria runtime **e** testes
  em silêncio).

---

## A mudança

### Arquivo único de produção: `src/trunk/includes/services/class-bitcoin-address-service.php`

**(a)** Adicionar um método `private static` no serviço:

```php
/**
 * Runs $fn with E_DEPRECATED masked, always restoring the previous level.
 *
 * ONLY for the consciously-accepted bitwasp/buffertools deprecations ("Use of parent in
 * callables", tentative return types) that would otherwise print mid-request and break the
 * admin settings-save redirect. Masks E_DEPRECATED alone — never other levels — and does NOT
 * catch anything: a missing-extension \Error still propagates (see EnvironmentRequirements and
 * the \Exception-only contract). Not a general tool: do not use it to hide our own deprecations.
 */
private static function suppress_vendor_deprecations(callable $fn)
{
    $previous = error_reporting();
    error_reporting($previous & ~E_DEPRECATED);
    try {
        return $fn();
    } finally {
        error_reporting($previous);
    }
}
```

> **Anotação de sniff (obrigatória, medida).** As três chamadas a `error_reporting()` disparam
> **dois** sniffs, não um: o do WPCS
> (`WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting`) e o próprio do
> Plugin Check (`PluginCheck.CodeAnalysis.PHPErrorReporting.DirectErrorReportingCall`, "Detected
> production-time change to PHP error reporting"), que roda independente do WPCS. O
> `phpcs:disable`/`phpcs:enable` precisa nomear **os dois** — nomear só o primeiro deixava 3
> `WARNING` em código enviado, exatamente o tipo de linha que a revisão do WordPress.org questiona
> num plugin de pagamento. São `WARNING`, não `ERROR`: o gate "nenhum `ERROR` em código enviado"
> passa de qualquer forma, mas isso não é razão para deixá-los lá.

**(b)** Rotear **apenas a região bitwasp** dos 5 métodos por ele (envolver só as chamadas à lib,
não a lógica nossa em volta):

| Método | O que envolver | Nota |
|---|---|---|
| `generate_address_from_xPub` (~:74) | o corpo de derivação+geração (`convert_...`, `fromExtended`, `derivePath`, `getPublicKey`/`getPubKeyHash`, os 3 geradores p2pkh/p2wpkh/p2sh) | **closure completa** `function () use ($xPub,$index,$network,$forceType,$logger){...}` (multi-statement com `switch`/`return`, não arrow `fn`); manter o guard `$index < 0` **fora**; o `catch (\InvalidArgumentException)` interno fica **dentro** do wrap, inalterado |
| `convert_extended_pubkey_prefix` (~:199) | a região `Base58::decodeCheck`/`Buffer`/`encodeCheck` (~:207-212) | `get_prefix_meta()` pode ficar fora |
| `validate_segwit_address` (~:243) | bech32-decode + `WitnessProgram` (~:246-252) **dentro** do `try` | `catch (\Exception)` fica fora |
| `validate_bitcoin_address` (~:274) | `AddressCreator::fromString` (~:284) **dentro** do `try` | rota segwit e `catch (\Exception)` ficam fora |
| `validate_extended_pubkey` (~:299) | `convert_...` + `new HierarchicalKeyFactory()` + `fromExtended` (~:303-306) **dentro** do `try` | `catch (\Exception)` fica fora |

Colocar o wrap **dentro** do `try` de cada `validate_*` garante que `\Exception` de parse ainda vira
`false` e `\Error` ainda propaga. **Nesting é seguro** (restauração LIFO): `validate_extended_pubkey`
chama `convert_extended_pubkey_prefix`, ambos envolvidos — o `finally` interno restaura ao nível já
mascarado, o externo ao original.

> **Ressalva de escopo em `generate_address_from_xPub`.** Nesse método a closure é o corpo inteiro,
> então a máscara cobre também um pouco de código nosso: `get_prefix_from_xpub()`,
> `get_prefix_meta()` e a chamada ao `$logger` do fallback de prefixo. É consequência de as chamadas
> bitwasp estarem intercaladas com essa lógica — quebrar em wraps menores exigiria espalhar 4 ou 5
> deles pelo método. Nada ali emite `E_DEPRECATED` hoje; se algum dia emitir, é o único ponto do
> plugin onde a regra "só a região bitwasp" está mais larga do que diz. Nos outros 4 métodos o wrap
> é estritamente a região da lib.

**Nenhuma mudança** em `class-wc-gateway-paycrypto-me.php`, `class-bitcoin-payment-processor.php`,
vendor, ou `composer.json`/autoload. As chamadas `NetworkFactory::bitcoin()/bitcoinTestnet()`
(gateway:107, processor:101-102) retornam descritor estático e **não** serializam → não precisam de
wrap. Como todos os caminhos de serialização passam pelos 5 métodos (G5), envolver no serviço corrige
**todos** de uma vez (save, checkout, order-pay).

### Documentação a atualizar (junto com o código)

| Arquivo | O quê |
|---|---|
| `docs/archive/DONE-CRYPTO-DEPENDENCIES.md` (arquivado, pode estar ausente no seu checkout) | Nota curta: as deprecations do `CachingTypeFactory`, além de ruído `WP_DEBUG`, **quebravam o redirect do save**; mitigadas por supressão de runtime escopada a `E_DEPRECATED` no boundary do `BitcoinAddressService` (não é patch de vendor — "Fora de escopo" segue valendo; o fatal do PHP 9 continua não mascarado). |
| `CLAUDE.md` | Na seção "Reporting failures honestly", registrar o boundary de supressão (por que existe; mascara só `E_DEPRECATED`; **nunca** engole `\Error`). Atualizar a contagem de testes (355 → 355 + nº de testes novos do helper). |
| `src/trunk/CHANGELOG.md` | `## Unreleased` → `### Fixed`: "Saving the On-Chain gateway settings no longer prints PHP deprecation notices from the Bitcoin library or breaks the post-save redirect (\"headers already sent\") on hosts with error display enabled." |

---

## Verificação

### A — Testes unitários do helper (núcleo determinístico, sem dependência de vendor/gmp)
Em `tests/phpunit/unit/BitcoinAddressValidationTest.php`, via `ReflectionMethod` (padrão de
`OnchainWithoutGmpTest`). Assertar:

1. **Passthrough:** `run(fn () => 42) === 42` (e identidade de objeto).
2. **Mascara dentro:** com `error_reporting(E_ALL)`, dentro de `$fn` → `(error_reporting() & E_DEPRECATED) === 0`.
3. **Preserva outros bits:** dentro de `$fn`, `E_WARNING` e `E_NOTICE` seguem setados (só 1 bit some).
4. **Restaura em retorno normal.**
5. **Restaura em throw** (callable que lança).
6. **NÃO captura (contrato GMP):** `expectException(\Error::class)` com callable que lança
   `\Error('...gmp_init()')`; idem `\TypeError`, `\RuntimeException`.
7. **Nesting restaura LIFO.**

> Gotcha (comentar no teste): **não** provar com `trigger_error(E_USER_DEPRECATED)` (bit diferente),
> nem com um `set_error_handler` coletor (desde PHP 8.0 o handler custom roda **independente** do
> `error_reporting()` → falso-negativo). Assertar o **estado do bit** (2/3); a supressão de *display*
> é coberta pelo teste manual D.

### B — Guardas de regressão (devem seguir verdes, sem novos testes)
`BitcoinAddressValidationTest::test_validate_*_lets_internal_errors_propagate` (contrato `\Error`);
`..._returns_false_for_parse_exceptions`/rejeições (`\Exception` → `false`); `BitcoinAddressVectorsTest`
(60/60 idênticos — o wrap não muda resultado); `BitcoinAddressServiceTest`;
`BitcoinPaymentProcessorTest`/`WCGatewayValidationTest` (`\Error` ainda vira `PayCryptoMeException`);
`OnchainWithoutGmpTest` (roteamento sem-gmp inalterado).

Rodar:
```bash
docker compose run --rm release ./vendor/bin/phpunit           # baseline atual: ver CLAUDE.md (era 363/755/4 quando esta frente entrou)
docker run --rm -v $(pwd)/src/trunk:/plugin -w /plugin php:8.3-cli \
  php ./vendor/bin/phpunit --filter OnchainWithoutGmpTest       # host sem gmp
```

### C — Teste manual de aceitação (reproduz/confirma o bug real)

> **Pré-requisito que já quebrou uma rodada de validação.** O container `wordpress` monta
> `./src/trunk`, então ele usa o `vendor/` que está no host. Uma árvore instalada **antes** da troca
> de pacotes ainda carrega `lucas-rosa95/bitcoin` + `lucas-rosa95/buffertools-php`, e o
> `CachingTypeFactory.php` do fork **tem** o fix de `parent`-in-callables: o bug não reproduz, o
> "antes" não aparece, e o teste valida código aposentado. Conferir primeiro:
>
> ```bash
> ls src/trunk/vendor/bitwasp        # deve mostrar: bech32  bitcoin  buffertools
> ls src/trunk/vendor/lucas-rosa95   # não deve existir
> ```
>
> Se divergir: `docker-compose run --rm release composer install` (use `--prefer-source` se o
> `codeload.github.com` estiver devolvendo `429`).

Host PHP 8.3, `WP_DEBUG_DISPLAY=true`, **com** gmp:
1. **Antes:** WooCommerce → Settings → Payments → Bitcoin On-Chain → xPub/zpub válido → Save →
   observar as notices `Deprecated ... CachingTypeFactory` + "headers already sent" + redirect quebrado.
   *Este passo só reproduz num checkout anterior a `df73406`* (ou com o wrap removido à mão): na branch
   atual a correção já está aplicada, então comece do passo 2. Quem quiser ver o "antes" tem a prova em
   processo frio no bloco de Status — o caminho vendor cru imprime 590 bytes de `Deprecated`.
2. **Depois:** mesmos passos → redirect limpo, sem `Deprecated`, sem headers-already-sent; valor persistido.
3. **Checkout:** pedido com xPub configurado → order-pay/thank-you renderiza endereço+QR sem `Deprecated`.
4. **Contrato sem-gmp:** num host sem gmp, salvar xPub segue com o comportamento honesto
   (short-circuit para `parent::process_admin_options()` em `:222`, ou mensagem de "internal error"
   do gateway em `:229` — nunca "not valid for the selected network"). Mascarar `E_DEPRECATED` não
   muda nada disso.

> **Teste de integração automatizado descartado de propósito** do suite unitário: o
> `CachingTypeFactory` cacheia por instância (só dispara "frio"), exigiria `@runInSeparateProcess` +
> gmp e ficaria flaky. Mantém-se o padrão da casa (suíte unitária rápida/sem WP; checagens
> dependentes de ambiente ficam em teste manual/script, como `OnchainWithoutGmpTest`/`smoke-minimal-host.sh`).

---

## Fora de escopo / notas

- **Não editar arquivos em `vendor/`** (mudanças ali se perdem num `composer update`).
  `composer.json`/tooling é permitido se necessário — mas a abordagem recomendada **não** precisa
  mudar composer/lock/autoload. `composer-patches` é permitido, porém não recomendado (ver
  "Alternativas descartadas").
- Não mascarar globalmente — só na região bitwasp dos 5 métodos.
- PHP 9: quando `parent`-in-callables virar fatal, ressurge como `\Error` (não mascarado) e propaga
  pelo contrato — ver "Horizonte PHP 9" em
  [`docs/archive/DONE-CRYPTO-DEPENDENCIES.md`](archive/DONE-CRYPTO-DEPENDENCIES.md) (arquivado, pode estar
  ausente).
- **Base:** branch `chore/retire-crypto-forks` (onde os pacotes oficiais `bitwasp/*` entraram), ou
  `main` depois do merge.
