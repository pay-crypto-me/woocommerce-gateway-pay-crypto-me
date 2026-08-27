# [GUIDE] Adicionando um novo gateway de pagamento

> Checklist mecânico, verificado direto no código-fonte. Implementar um terceiro gateway toca vários arquivos que não ficam óbvios lendo só uma classe (`WC_Gateway_PayCryptoMe`/`WC_Gateway_PayCryptoMe_Lightning`) — este documento reúne os pontos de edição obrigatórios. Convenção: `<X>` = nome do novo gateway (ex. `paycrypto_me_<x>`), `PayCryptoMe<X>` = prefixo de classe.

## 1. Registro no bootstrap (`src/trunk/paycrypto-me-for-woocommerce.php`)

Dois arrays hardcoded precisam de uma linha nova cada — não há mecanismo de auto-descoberta:

- `WC_PayCryptoMe::add_gateway($gateways)` — adicionar `$gateways[] = __NAMESPACE__ . '\WC_Gateway_PayCryptoMe_<X>';`
- `WC_PayCryptoMe::includes()` — adicionar `include_once plugin_dir_path(__FILE__) . 'includes/class-wc-gateway-paycrypto-me-<x>.php';`
- Se o novo gateway precisar de tabela(s) própria(s): `register_activation_hook(__FILE__, [PayCryptoMe<X>GatewayActivate::class, 'activate']);` (ver seção 4)

## 2. Classe do gateway — 5 métodos abstratos obrigatórios

`class WC_Gateway_PayCryptoMe_<X> extends Abstract_WC_Gateway_PayCryptoMe` precisa implementar:

| Método | Assinatura | Responsabilidade |
|---|---|---|
| `admin_enqueue_scripts_content` | `protected function admin_enqueue_scripts_content(WP_Screen\|null $screen)` | Enfileirar JS/CSS específicos da tela de admin deste gateway |
| `get_available_networks` | `public function get_available_networks()` | Redes suportadas (ex. mainnet/testnet para On-Chain) |
| `get_available_cryptocurrencies` | `public function get_available_cryptocurrencies($network = null)` | Moedas suportadas por rede |
| `init_form_fields_items` | `protected function init_form_fields_items()` | Array de campos de settings específicos (além dos genéricos que a abstrata já cuida — express, hide_for_non_admin_users, etc.) |
| `build_order_display_args` | `public function build_order_display_args(\WC_Order $order): ?array` | Ver seção 3 |

O construtor (`__construct()`) já é resolvido pela classe abstrata — chama `parent::__construct()` no seu (ele monta `$this->display_data_builder`, registra os hooks de admin/checkout order-details, `init_form_fields()`/`init_settings()`, etc.). Não reimplemente esses hooks.

## 3. `build_order_display_args()` — o hook mais bem coberto, mas os keys exatos só existem no código

Retorne `null` quando o pedido não tem pagamento deste gateway (guard de meta). Caso contrário, retorne exatamente estas chaves (consumidas por `PaymentDisplayDataBuilder::build()`, `includes/services/class-payment-display-data-builder.php`):

```php
[
    'payment_identifier'     => ..., // endereço, invoice id, etc.
    'payment_uri'            => ..., // URI usada para gerar o QR e o link "abrir na wallet"
    'logo_path'               => ..., // logo embutido no QR
    'crypto_network'         => ..., // ex. 'mainnet'/'testnet', ou o node_type no caso Lightning
    'network_label'          => ..., // rótulo humano da rede
    'crypto_amount'          => ...,
    'crypto_currency'        => ..., // ex. 'BTC'
    'confirmations_required' => ...,
]
```

`fiat_amount`/`fiat_currency`/`expires_at` são lidos direto da meta do pedido (`_paycrypto_me_fiat_amount`, `_paycrypto_me_fiat_currency`, `_paycrypto_me_payment_expires_at`) pelo builder — seu processor precisa gravar essa meta (ver seção 5), não precisa devolvê-la aqui.

Characterization tests de referência: `tests/phpunit/unit/OrderDisplayArgsTest.php` e `PaymentDisplayDataBuilderTest.php`.

## 4. Dispatch do processor

`ProcessorStrategiesFactory::create()` (`includes/strategies/class-processor-strategies-factory.php`) é um **`switch` hardcoded** por `$gateway->id`, sem fallback — id desconhecido lança `InvalidArgumentException`. Passos:

1. Criar `class PayCryptoMe<X>ProcessorStrategiesFactory` em `includes/strategies/`, seguindo o padrão de **composition root** (é aqui que o `new Service()`/`new XProcessor()` de produção deve viver — ver `BitcoinProcessorStrategiesFactory`/`LightningProcessorStrategiesFactory` como referência):
   ```php
   class PayCryptoMe<X>ProcessorStrategiesFactory
   {
       public static function create(\WC_Payment_Gateway $gateway): GatewayProcessorContract
       {
           return new <X>PaymentProcessor($gateway, new <AlgumService>(), ...);
       }
   }
   ```
2. Criar `class <X>PaymentProcessor implements GatewayProcessorContract` — único método exigido:
   ```php
   public function process(\WC_Order $order, array $payment_data): array
   ```
   Convenção de DI (desde a Fase 3+): parâmetros de serviço nullable com fallback interno (`?Dep $dep = null` → `$this->dep = $dep ?? new Dep()`), para não quebrar `new <X>PaymentProcessor($gateway)` sem argumentos.
3. Adicionar o `case '<gateway_id>': return PayCryptoMe<X>ProcessorStrategiesFactory::create($gateway);` em `ProcessorStrategiesFactory::create()`.

O array de retorno de `process()` vira o `$payment_data` salvo pelo `PaymentProcessor` como meta `_paycrypto_me_*` do pedido — inclua ali `fiat_amount`/`fiat_currency`/`payment_expires_at` (nomes de meta acima) para o `build_order_display_args()` conseguir ler depois.

**Antes disso, `PaymentOrderValidator::validate_order()`** (`includes/processors/class-payment-order-validator.php`) já roda automaticamente para qualquer gateway via `PaymentProcessor` — sem trabalho extra necessário — mas assume: `$order->get_payment_method()` é exatamente `$gateway->id` **ou** `$gateway->id . '_express'` (convenção do botão express); `fiat_amount > 0`; `$order->get_currency()` não vazio. Se seu gateway tiver uma convenção de payment-method-id diferente, `PaymentOrderValidator` vai rejeitar o pedido.

## 5. Persistência (só se o gateway precisar de tabela própria)

Padrão: uma classe `PayCryptoMe<X>GatewayActivate` por gateway com `activate()` estático usando `dbDelta()` (ver `includes/services/class-paycrypto-me-bitcoin-gateway-activate.php` como referência de sintaxe SQL). Registrar via `register_activation_hook()` própria no bootstrap (seção 1) — **não** reaproveitar o hook de outro gateway.

## 6. Validação de settings (opcional, só se tiver campos com validação customizada)

Se o novo gateway precisar de `validate_<key>_field()` customizados, siga o padrão `LightningConfigValidator` (`includes/validators/class-lightning-config-validator.php`):

- Validador **puro/stateless** em `includes/validators/class-<x>-config-validator.php`, sem acoplamento a `WC_Payment_Gateway`.
- No gateway, **mantenha stubs públicos de 1 linha** para cada `validate_<key>_field($key, $value)`, delegando ao validator — são obrigatórios porque `WC_Settings_API::validate_settings_fields()` descobre esses métodos via `method_exists($this, 'validate_<key>_field')` **na própria instância do gateway**, isso não é emulado nos shims de teste (só aparece quebrado em produção real).

## 7. Blocos Gutenberg (checkout blocks)

1. PHP: `final class WC_Gateway_PayCryptoMe_<X>_Blocks extends WC_PayCryptoMe_Blocks` só precisa de `protected $name = '<gateway_id>';` (ver `includes/blocks/class-wc-gateway-paycrypto-me-lightning-blocks.php`) — a classe abstrata (`includes/blocks/abstract-class-wc-paycrypto-me-blocks.php`) já cuida de `is_active()`, `get_payment_method_script_handles()`/`style_handles()` via `AssetManager`, `get_payment_method_data()`.
2. No mesmo arquivo, registrar via `add_action('woocommerce_blocks_payment_method_type_registration', fn($registry) => $registry->register(new WC_Gateway_PayCryptoMe_<X>_Blocks()));`.
3. JS: `includes/blocks/js/paycrypto_me_<x>-blocks.js`, importando `createPaymentComponents` de `./paycrypto-blocks-shared.js` (mesmo padrão de `paycrypto_me-blocks.js`/`paycrypto_me_lightning-blocks.js`).
4. Adicionar entry em `webpack.config.js` (`entry['paycrypto_me_<x>-blocks']`, apontando pro JS + SCSS correspondentes) e rodar `npm run build` — nunca editar `assets/blocks/` direto.

## 8. Testes

Sem framework de scaffolding — replicar convenções existentes:

- Shims WP/WC centralizados em `tests/_support/wp-helpers.php` — **não redeclare shims por arquivo de teste**: shims duplicados com aridade divergente entre arquivos já causaram testes falsos-positivos aqui, porque o PHPUnit gera mocks pela assinatura da classe que "venceu" a ordem de carregamento. Se faltar um shim (`wp_cache_get`, `wc_price`, etc.), adicione lá.
- Mock de HTTP: `FakeHttpClient`/`http_ok()`/`http_error()` em `tests/_support/fake-http-client.php` (para qualquer service que implemente `HttpClientContract`).
- Spy de hooks: `hook_spy_calls()`/`hook_spy_reset()` — para asserir que `do_action`/`apply_filters` foram chamados (sem dispatch real).
- DI real (desde a Fase 3+): construa processors via `new <X>PaymentProcessor($gateway, $fakeService, $fakeDb)` diretamente, não via `disableOriginalConstructor()` + reflection (isso era necessário antes da DI, não é mais o padrão a seguir).
- Templates de referência mais próximos de um processor novo: `tests/phpunit/unit/BitcoinPaymentProcessorTest.php`, `AbstractLightningProcessorTest.php`. Para validators: `LightningConfigValidatorTest.php` (não precisa mock de gateway). Para display args: `OrderDisplayArgsTest.php`.

## 9. Convenções transversais

- i18n: strings do cliente e **rótulos/descrições de settings** via `__()`/`esc_html__()` com text domain `paycrypto-me-for-woocommerce`. **Erros, warnings, logs, feedback de botão de diagnóstico e notas de pedido do admin ficam em inglês literal**, sem `__()` — a regra completa e o porquê estão em [GUIDE-TRANSLATION.md](./GUIDE-TRANSLATION.md) → "O que entra (e o que NÃO entra) no catálogo". Não envolva uma mensagem de erro nova em `__()` só porque ela aparece na tela.
- Exceções: `PayCryptoMeException` (erro genérico) / `PayCryptoMePaymentException` (erro específico de fluxo de pagamento), em `src/trunk/exceptions/`.
- Hooks genéricos que já disparam automaticamente para qualquer gateway via `PaymentProcessor` (não precisa disparar você mesmo): `paycryptome_before_payment`/`paycryptome_after_payment` (actions), `paycryptome_payment_amount`/`paycryptome_payment_data` (filters), `paycryptome_for_woocommerce_gateway_loaded` (action, disparada no construtor da classe abstrata). Ver tabela completa em `CLAUDE.md` § "Public hooks".
- Se o gateway novo tocar as libs `bitwasp/*`, **passe pelo `BitcoinAddressService`** em vez de chamá-las direto: elas emitem `E_DEPRECATED` no meio da requisição (o que já quebrou o redirect pós-save das settings On-Chain), e a máscara escopada vive lá — ver `suppress_vendor_deprecations()` e [PARTIAL-CRYPTO-DEPRECATION-CONTINGENCY.md](./PARTIAL-CRYPTO-DEPRECATION-CONTINGENCY.md). Nunca capture `\Throwable` na validação: extensão ausente é `\Error` e tem de propagar.
- Dois guards da suíte alcançam arquivo novo automaticamente, então não há passo a fazer — só não os contorne: `VendorReplaceGuardTest` varre **todo** PHP enviado (`includes/`, `exceptions/`, `templates/`, entrypoint, `uninstall.php`) proibindo `lastguest\Murmur`/`murmur3`/`BloomFilter` (pacote removido via `replace` — ver [DONE-LEAN-VENDOR-TREE.md](./archive/DONE-LEAN-VENDOR-TREE.md), arquivado/gitignored, pode estar ausente no seu checkout), e `PhpFloorConsistencyTest` fixa o piso de PHP nos quatro lugares que o declaram, caso o gateway novo precise subir esse piso.
- Se o novo gateway for **assíncrono** (como o Lightning, que resolve o invoice depois) e precisar de action de mudança de status para consumidores externos reagirem, siga o precedente `paycryptome_lightning_status_changed` — action de domínio disparada dentro do service de persistência, só quando o valor realmente muda (ver `PayCryptoMeLightningDBStatementsService::update_status()`).
