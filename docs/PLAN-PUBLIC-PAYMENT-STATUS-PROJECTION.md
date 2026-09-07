# [VALIDATION] Contrato público de projeção de status de pagamento

**Origem:** [RFC — Contrato público de projeção de status de pagamento](rfcs/RFC-PUBLIC-PAYMENT-STATUS-PROJECTION.md)

**Decisão aprovada:** 2026-09-06

**Escopo de execução:** plugin Base; as adaptações no repositório Pro são critérios consumidores,
não autorização para implementar M6/M7 neste repositório.

**Estado:** implementação Base concluída; validação cross-repo do Pro ainda pendente.

## Resultado esperado

O Base publicará uma API versionada e concorrente-segura para o Pro projetar o status de invoices
Lightning. Confirmação, polling, webhook, reconciliação, conclusão do pedido e retries continuam
autoritativos no Pro. A tabela Lightning do Base conserva somente a projeção usada por ele.

Confirmações on-chain não serão projetadas no Base. O método, a action e as colunas removidos na
versão 0.2.2 não serão restaurados. Esta implementação não altera schema nem `DbInstaller::DB_VERSION`.

## Contratos públicos a introduzir

### Capability registry

Criar a classe pública e autoloadable `PaymentStatusProjectionCapabilities`, no namespace
`PayCryptoMe\WooCommerce`, com:

```php
public static function all(): array;
```

O retorno da versão inicial é fixo:

```php
[
    'contract_version'              => 1,
    'lightning_invoice_status_cas'  => 1,
    'onchain_confirmation_progress' => 0,
]
```

O consumidor deve detectar a classe com `class_exists()`. Classe ausente, chave ausente ou valor
`0` significam capability indisponível, nunca erro. O Pro pode manter Base 0.2.1 como mínimo global,
mas não deve escrever status Lightning no Base 0.2.1/0.2.2 por meio do método legado não atômico.

### Resultado tipado

Criar o DTO público, final e imutável `LightningStatusTransitionResult`, seguindo o padrão dos DTOs
Lightning existentes. Ele expõe:

- outcomes constantes `applied`, `already_applied`, `conflict`, `not_found` e `error`;
- `outcome`, `order_id`, `requested_invoice_id`, `stored_invoice_id`, `expected_status`,
  `requested_status`, `current_status` e `error_message`;
- `is_success(): bool`, verdadeiro somente para `applied` e `already_applied`.

`stored_invoice_id`, `current_status` e `error_message` são nullable. `error_message` é diagnóstico
interno para log do consumidor e não deve ser exibido diretamente ao cliente.

### Transição atômica

Adicionar a `PayCryptoMeLightningDBStatementsService`:

```php
public function transition_status(
    int $order_id,
    string $invoice_id,
    string $expected_status,
    string $new_status
): LightningStatusTransitionResult;
```

`order_id` deve ser positivo. `invoice_id`, `expected_status` e `new_status` não podem ser vazios;
além disso, devem respeitar os limites físicos do schema: 255 bytes para `invoice_id` e 30 bytes
para cada status. Violações são erro de programação e lançam `InvalidArgumentException` antes de
consultar o banco. A medição é em bytes (`strlen`), coerente com o limite que o MySQL efetivamente
persiste e sem depender de `mbstring`. Falhas operacionais do banco não lançam: retornam outcome
`error` com `error_message`.

## Algoritmo obrigatório

Executar uma única mutação condicional preparada, equivalente a:

```sql
UPDATE {paycrypto_me_lightning_invoices}
SET status = :new_status
WHERE order_id = :order_id
  AND invoice_id = :invoice_id
  AND status = :expected_status
```

Interpretar o retorno desta forma:

1. `false`: retornar `error`; não emitir action.
2. Uma linha alterada: invalidar o cache do pedido, retornar `applied` e emitir a action uma vez.
3. Zero linhas: consultar diretamente o banco por `order_id`, sem `get_by_order_id()` e sem object
   cache, e então:
   - nenhuma linha: `not_found`;
   - mesmo invoice já em `new_status`: `already_applied`;
   - invoice diferente ou status diferente de `expected_status` e `new_status`: `conflict`;
   - erro na leitura de resolução: `error`.

Quando a leitura de resolução encontrar uma linha, invalidar o cache do pedido para não conservar
um snapshot diferente daquele usado no resultado.

Somente o outcome `applied` emite:

```php
do_action(
    'paycryptome_lightning_status_changed',
    $order_id,
    $expected_status,
    $new_status,
    $invoice_id
);
```

O quarto argumento é aditivo; listeners registrados para três argumentos continuam compatíveis.
A garantia é **no máximo uma action por CAS aplicado**, não entrega exatamente uma vez. Um crash
entre o `UPDATE` e `do_action()` pode perder a notificação. O Base não captura exceções de listeners,
e o Pro deve retomar seus efeitos a partir da própria decisão persistida.

## Compatibilidade do método legado

Manter `update_status(int $order_id, string $status): bool` e marcá-lo deprecated, sem removê-lo.
Ele deve ler diretamente do banco o invoice/status atual e delegar a `transition_status()` usando
esse snapshot como identidade e estado esperado.

- Retornar `true` para `applied` e `already_applied`.
- Retornar `false` para `conflict`, `not_found` e `error`.
- Nunca emitir action por conta própria; somente o CAS pode emiti-la.
- Não repetir automaticamente após `conflict`, pois isso poderia aplicar um evento antigo a um
  invoice que acabou de ser substituído.

O método legado fica disponível apenas para compatibilidade. A capability v1 anuncia exclusivamente
`transition_status()` como superfície segura para novos consumidores.

## Alterações documentais e lifecycle

- Registrar as duas classes e o método novo no inventário público do Base.
- Atualizar a action existente com o quarto argumento e sua garantia concorrente.
- Fixar documentalmente `onchain_confirmation_progress = 0`: o Pro persiste confirmação/txid/valor
  recebido em seu próprio domínio e atualiza o pedido WooCommerce, sem SQL privado no Base.
- Adicionar a mudança ao changelog como feature compatível destinada à versão 0.3.0. Os números de
  versão não são editados à mão; `release.sh` continua sendo a única fonte do bump.
- Não tocar em activators, migrações, snapshots de schema ou tabelas on-chain.
- Depois de toda a implementação e validação, mudar o H1 para `[DONE]`, registrar as evidências e
  mover o documento para `docs/archive/` conforme a convenção do repositório. Não arquivar antes.

## Testes obrigatórios

### Unitários

Cobrir todos os outcomes, argumentos inválidos (vazios, `order_id` não positivo e comprimentos além
do schema), diagnóstico de erro, invalidação de cache, payload da action e wrapper legado. O fake
de `$wpdb` deve reproduzir o contrato real: update sem linha correspondente retorna `0`, enquanto
erro retorna `false`.

Casos mínimos:

1. `New → Settled`: `applied`, status persistido e uma action com quatro argumentos.
2. Repetição `Settled → Settled`: `already_applied`, sucesso idempotente e nenhuma action.
3. Pedido inexistente: `not_found` e nenhuma action.
4. Invoice armazenado diferente: `conflict`, nenhuma mutação e nenhuma action.
5. Status armazenado inesperado: `conflict`, nenhuma mutação e nenhuma action.
6. Falha no `UPDATE` ou na leitura de resolução: `error` e nenhuma action.
7. Wrapper legado: mesmos resultados booleanos definidos acima e nenhuma duplicação de action.

### WordPress/MySQL real

Adicionar um teste permanente à suíte MySQL-backed existente. Dois processos independentes devem
partir de uma linha `New`, disputar `New → Settled` para o mesmo `order_id`/`invoice_id` e registrar
a action em armazenamento compartilhado. Ao final:

- a linha está `Settled`;
- exatamente um processo obteve `applied`;
- o outro obteve `already_applied`;
- existe exatamente um registro compartilhado da action.

Adicionar também provas reais para:

- webhook atrasado do invoice antigo depois de `replace_invoice()`: `conflict`, invoice novo ainda
  `New`, nenhuma action;
- dois destinos concorrentes: somente o CAS compatível vence; o outro retorna `conflict`;
- pedido ausente distinguível de erro SQL.

O teste deve limpar tabelas/fixtures próprias em `finally`, integrar o gate obrigatório de release
e não depender de timing por `sleep` como mecanismo de correção. Atualizar contagens documentais
afetadas pelo novo teste.

## Aceite cross-repo do Pro

Esta etapa não implementa M6/M7, mas o contrato Base só é considerado consumível quando o harness
do Pro provar:

1. Base mínimo sem a classe/capability degrada sem fatal e não chama `update_status()`.
2. Base com capability v1 usa exclusivamente `transition_status()`.
3. On-chain persiste somente no Pro e conclui o pedido via WooCommerce.
4. Retry após interrupção usa a decisão autoritativa persistida e não reconfirma nem duplica efeitos.
5. Os planos `PLAN-BASE-REUSE.md` e `IMPLEMENTATION-PLAN.md` do Pro não prometem mais write-back
   on-chain no Base.

M6/M7 permanecem pendentes e exigem revisão própria depois desse harness; passar os testes deste
plano não equivale a implementar confirmação automática.

## Comandos de validação

Executar, nesta ordem, sem usar opções que pulem gates:

```bash
cd src/trunk
./vendor/bin/phpunit --configuration phpunit.xml.dist
cd ../..
./scripts/schema-tests.sh
./scripts/smoke-minimal-host.sh
./scripts/check-docs-drift.sh
./scripts/release.sh -v 0.3.0 -s paycrypto-me-for-woocommerce --dry-run
```

O plano só pode ser marcado `[DONE]` quando todos os comandos passarem, os testes concorrentes
forem permanentes e a evidência Base mínimo/atual do harness Pro estiver registrada.

## Evidência da implementação Base — revisada em 2026-09-07

- [x] PHPUnit unitário: 420 testes, 979 asserções, 4 skips esperados.
- [x] Suíte WordPress/MySQL: 23 testes, 174 asserções, incluindo os dois cenários concorrentes.
- [x] Smoke de host mínimo: GMP, GD, iconv e fileinfo degradam conforme o contrato existente.
- [x] Auditorias de platform pin, i18n, drift documental e `git diff --check`.
- [x] Revisão adversarial do CAS: limites do schema validados antes do SQL, exceções literais
  compatíveis com Plugin Check e fake de `$wpdb` alinhado ao retorno real para zero linhas.
- [x] Dry-run de release 0.3.0 com slug explícito.
- [ ] Harness do Pro contra Base mínimo e Base com capability v1.
- [ ] Atualização dos dois planos consumidores do Pro para remover o write-back on-chain.

O documento permanece `[VALIDATION]` enquanto os dois itens cross-repo estiverem abertos. O código
Base não deve ser ampliado com M6/M7 para encerrá-los.
