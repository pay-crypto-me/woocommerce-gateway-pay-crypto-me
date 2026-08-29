# [VALIDATION] Roteiro de validação manual — `fix/schema-upgrade-and-static-records`

> Documento de trabalho para esta branch, não um guia permanente do repo (esse é o
> [docs/GUIDE-DB-SCHEMA-UPGRADE.md](GUIDE-DB-SCHEMA-UPGRADE.md), que fica). Move para `docs/archive/`
> como `[DONE]` (mesmo grupo/convenção de `DONE-SCHEMA-UPGRADE-AND-STATIC-RECORDS.md`) assim que a
> validação concluir e a branch mergear — o valor dele é só até a decisão de bump de versão.

**Dono da execução:** Lucas. **Dono da rede automatizada (unit/integration/scripts):** Claude.
Este documento cobre só a parte manual/regressão — a automatizada já roda (403 unit + 18 integration
+ smoke + GMP-less + docs-drift + platform-pin, todos verdes nesta branch — os números subiram de
384/11 para 403/18 com a execução de `docs/PLAN-SCHEMA-INSTALL-HARDENING.md` em cima desta mesma
branch: repair path, visibilidade de falha mascarada do `dbDelta`, as corridas de double-submit
dos Blocos 13/14 abaixo, e uma rodada de `/code-review` que corrigiu 3 achados reais antes do commit
— ver a seção "Rodada de code review" no fim do plano).

**Critério de aceite:** todos os blocos abaixo com resultado registrado (PASS/FAIL + nota). Um FAIL
em qualquer bloco 1–5 é bloqueador para bump de versão até investigado. Blocos 6–11 são
confirmação/rede de segurança — um FAIL ali também bloqueia, mas com menos ambiguidade sobre causa.

---

## Bloco 0 — Ambiente

**Decisão:** usar o stack docker já existente (`docker-compose.yml`, serviços `wordpress`+`wp_db`),
sem baixar nada do WordPress.org. `docker-compose` monta `src/trunk/` do working tree — então trocar
de branch com `git checkout` **enquanto os containers continuam rodando** é, literalmente, a mesma
operação que um update real de produção faz (troca os arquivos do plugin, mantém o banco intacto).
Não precisa de `git worktree` nem de zip nenhum.

**Ponto de partida:** `main`, não a tag `v0.1.2`. Cheguei a considerar a tag (é o que está
publicado no WordPress.org agora — `main` está 7 commits à frente, todos de rename/docs Premium→Pro),
mas `main` é o pai real desta branch (`fix/schema-upgrade-and-static-records` nasce dele), e é isso
que isola precisamente o escopo em teste: o diff `main` → branch é **só** o que esta branch mudou.
Usar a tag misturaria esse diff com o dos commits de rename, que não fazem parte deste escopo —
mesmo confirmando (fiz isso: `git diff v0.1.2..main -- src/trunk/includes`) que esse diff extra é
só um comentário renomeado, sem lógica, prefiro não depender disso.

```bash
cd /home/lucas/repos/paycrypto-me-for-woocommerce
git status --short          # confirmar árvore limpa antes de trocar de branch
docker compose up -d wordpress wp_db   # (ou docker-compose, conforme o binário disponível)
git checkout main
docker compose exec -T wordpress wp --allow-root plugin list   # confirmar 0.1.2 ativo (main é o que está publicado + só rename)
```

Se você tiver como restaurar um dump real do site em produção (tabelas + options do plugin) para
dentro do `wp_db` deste container, é o sinal mais forte possível — faça isso em vez do estado
sintético do Bloco 1 abaixo. Se não for viável, o Bloco 1 monta um estado sintético "realista" que
cobre os mesmos casos.

---

## Bloco 1 — Upgrade a partir de instalação existente (o mais importante)

Objetivo: provar que um site **já rodando em produção** não quebra ao receber esta mudança, sem
reativar o plugin (update real não reativa).

1. Com `main` já checked out (Bloco 0) e o plugin ativo:
   - Configurar On-Chain com um **xPub/zPub** válido.
   - Fazer 1 pedido, completar checkout → gera linha derivada real (wallet + index + endereço).
   - Trocar a configuração para um **endereço fixo** (bech32, ex. `bc1q...`).
   - Fazer 1 pedido, completar checkout → **não gera linha nenhuma** (comportamento antigo,
     confirme isso mesmo — é o bug que esta branch corrige).
   - Anotar os dois `order_id` e o endereço/derivation_index do pedido derivado.
2. `wp option get paycrypto_me_db_version` → deve ser `1`. Anote.
3. **Sem** desativar/reativar o plugin, troque o código:
   ```bash
   git checkout fix/schema-upgrade-and-static-records
   ```
4. Abrir o **wp-admin** (dispara `admin_init`) → sem fatal, sem aviso de "tabelas falharam ao
   instalar".
5. Abrir o site como **visitante**, em aba anônima (página de produto ou carrinho) → sem fatal.
   *(Prova indireta — não dá pra observar de fora que o `admin_init` não roda no front-end sem
   instrumentar. Se quiser esse nível extra: ative `SAVEQUERIES` + um plugin tipo Query Monitor e
   confirme que nenhuma query de `dbDelta`/dos activators aparece numa request de visitante.
   Opcional, não bloqueador.)*
6. Reprocessar o pedido **derivado antigo** via `order-pay` (Minha Conta → Pedidos → Pagar):
   mesmo endereço, mesmo `derivation_index` de antes — prova que o `LEFT JOIN` não regrediu leitura
   de linha real.
7. Reprocessar o pedido **fixo antigo** (o que não tinha linha) via `order-pay`:
   - Mesmo endereço mostrado ao cliente (a configuração não mudou nesse meio-tempo).
   - Pedido conclui normalmente.
   - **Agora sim** aparece uma linha nova na tabela pra ele, retroativa:
     ```bash
     docker compose exec -T wordpress wp --allow-root db query \
       "SELECT order_id, payment_address, derivation_index_id, wallet_xpubkeys_id FROM wp_paycrypto_me_bitcoin_transactions_data WHERE wallet_xpubkeys_id = 0"
     ```
8. `wp option get paycrypto_me_db_version` continua `1`. Nenhum aviso novo no admin.

**Resultado:** **PASS** (2026-08-29) — nota: Lucas confirmou manualmente o upgrade sobre uma
instalação existente sem desativar/reativar o plugin. As linhas existentes do banco, configurações
dos métodos e dados dos pedidos foram preservados; os pedidos antigos e o novo comportamento de
registro continuaram funcionando, sem fatal ou aviso novo de schema. Double-check independente no
banco: `DB_VERSION=1`, as 4 tabelas presentes, nenhum buffer de erro/retry; pedidos fixos 14/15 com
sentinela `0/0`; pedidos derivados 16–18 com índices `0/1/2` ligados ao mesmo wallet; endereço e
índice de todas as linhas iguais ao order meta correspondente. Os registros Lightning 19–22 também
coincidem com invoice id, método e status dos respectivos pedidos.

---

## Bloco 2 — Endereço fixo, pedido novo (o mecanismo em si)

Rodar **cada sub-caso** abaixo pelo menos uma vez, alternando checkout clássico e blocks quando
indicado (o processamento é o mesmo `process_payment()` nos dois, mas é caminho de entrada
diferente e o teste é barato).

| # | Sub-caso | Checkout |
|---|---|---|
| 2a | Endereço fixo bech32 mainnet (`bc1...`) | Clássico |
| 2b | Endereço fixo bech32 mainnet (`bc1...`) | Blocks |
| 2c | Endereço fixo testnet (`tb1...`), rede testnet selecionada | Clássico |
| 2d | Endereço fixo legado/base58 (`1...` ou `3...`, mainnet) — caminho que usa GMP | Clássico |

Para cada um:
1. Configurar On-Chain com o endereço/rede do sub-caso.
2. Pedido novo, completar checkout.
3. Conferir: pedido concluído sem erro; order-details do **cliente** e do **admin** mostram
   endereço e QR corretos.
4. Conferir no banco: linha nova com `wallet_xpubkeys_id=0`/`derivation_index_id=0`.
5. Reprocessar o **mesmo** pedido (`order-pay`) → não duplica linha, endereço não muda.

**Caso extra, só no 2a (o mais importante do bloco):**

6. **Endereço trocado depois do pedido criado.** Com o pedido do passo 2 ainda pendente, vá em
   Settings → mude o endereço fixo configurado para um endereço **diferente**. Reprocesse o mesmo
   pedido (`order-pay`). O cliente deve continuar vendo o endereço **original** (o que ele já viu
   vence — nunca o recém-configurado). **Este é o teste mais crítico do bloco inteiro** — é onde um
   bug faria o cliente pagar num endereço diferente do que a página mostrou primeiro.

**Resultado:** **PASS** em todos os sub-casos (2026-08-29) — nota: Lucas validou checkout,
order-details/QR, retry sem duplicação e a troca mid-flight mantendo o endereço original. Double-check
independente: Classic representado pelos pedidos 26 (`bc1`), 27 (`tb1`) e 31 (`1…`); Blocks pelos
pedidos 33 (`bc1`), 34 (`tb1`) e 35 (`1…`, extra opcional). Todos os 6 têm exatamente uma linha,
sentinela `wallet_xpubkeys_id=0`/`derivation_index_id=0`, e endereço idêntico ao order meta. O pedido
28 manteve uma única linha/meta com o endereço fixo original no caso mid-flight; a configuração B
transitória é evidência da observação manual, não reconstruível do estado final do banco.

---

## Bloco 3 — Regressão do fluxo derivado (xPub)

Rápido, só pra provar que nada mudou: configurar com zPub/xPub, pedido novo, endereço/index
corretos, reprocessar o mesmo pedido → mesmo endereço, order-details ok (cliente e admin).

**Resultado:** **PASS** (2026-08-29) — nota: Lucas reprocessou manualmente o pedido derivado 29
via `order-pay`, mantendo endereço/índice e telas sem erro. Double-check independente confirmou uma
única linha, wallet mainnet 2, índice `0`, timestamps inalterados e endereço/índice iguais ao order
meta.

---

## Bloco 4 — Casos cruzados (achado na revisão de código, não óbvio)

Confirmado por leitura de código, mas nunca observado rodando de verdade. Objetivo: ver o
comportamento acontecer e confirmar que é inofensivo como a análise prevê.

O caso 4a específico (linha fixa reaproveitada após troca de config, `derivation_index` chega
`null` sem erro) agora também tem cobertura automatizada —
`BitcoinPaymentProcessorTest::test_derived_branch_reuses_a_fixed_address_row_left_behind_by_a_config_switch`
— confirmada com controle negativo (uma mutação que forçava `(int)` no valor fez o teste falhar como
esperado, revertida em seguida). Isso não substitui rodar o passo manualmente uma vez — o teste prova
o dado que chega no processor, não o que a tela realmente renderiza.

**4a. Fixo → derivado no meio do pedido.**
1. Configurar endereço fixo. Pedido novo, completar checkout (gera linha sentinela).
2. **Sem** tocar o pedido, trocar a configuração do gateway para um xPub válido.
3. Reprocessar o mesmo pedido via `order-pay`.
4. Esperado: o cliente recebe de volta o **endereço fixo original** (a linha existente vence,
   mesma regra do Bloco 2.6) — **não** deriva um endereço novo do xPub recém-configurado.
5. Checar `_paycrypto_me_derivation_index` via `$order->get_meta()` — deve ser lido como vazio/null.
   No armazenamento HPOS, WooCommerce pode não criar uma row física em `wp_wc_orders_meta` para o
   `null` que o processor devolve (no legado, a inspeção equivalente é em `wp_postmeta`); ausência
   da row e row vazia são equivalentes aqui. Confirme que isso **não** causa nenhum erro visível em
   nenhuma tela (order-details do cliente e do admin).

**4b. Derivado → fixo no meio do pedido.**
1. Configurar xPub. Pedido novo, completar checkout (gera linha real derivada).
2. Trocar a configuração para um endereço fixo.
3. Reprocessar o mesmo pedido via `order-pay`.
4. Esperado: o cliente recebe de volta o endereço **derivado original** (não o endereço fixo
   recém-configurado). Nenhuma linha nova é criada.

**Resultado:** **PASS** nos dois sub-casos (2026-08-29) — nota: Lucas validou manualmente as duas
trocas e as telas. Double-check independente: pedido 37 (fixo→derivado) manteve uma única row
sentinela `0/0` e o endereço original igual ao order meta; HPOS omitiu a row física do meta nulo,
comportamento documentado acima. Pedido 38 (derivado→fixo) manteve uma única row no wallet mainnet
2/índice 2, com endereço e índice originais iguais ao order meta. Nenhuma row nova foi criada.

---

## Bloco 5 — Falha de persistência determinística + corrida real

**5a. Forçar a falha de INSERT (determinístico, via trigger temporário):**

> **Corrigido no primeiro uso manual (2026-08-29):** a versão original estreitava
> `payment_address` para `VARCHAR(5)`, mas isso falha antes de montar o teste quando a tabela já tem
> endereços reais maiores que 5 caracteres — exatamente o estado esperado nesta altura do roteiro.
> Um trigger temporário rejeita apenas INSERTs novos, sem reescrever ou arriscar as rows existentes.

1. Criar um trigger temporário que rejeita todo INSERT novo nessa tabela:
   ```bash
   docker compose exec -T wp_db mysql -uwordpressdbuser -pwordpress123456 wordpressdb \
     -e "CREATE TRIGGER wp_paycrypto_me_validation_reject_insert
         BEFORE INSERT ON wp_paycrypto_me_bitcoin_transactions_data
         FOR EACH ROW SIGNAL SQLSTATE '45000'
         SET MESSAGE_TEXT = 'Intentional validation insert failure';"
   ```
2. Configurar endereço fixo (qualquer bech32 real). Pedido novo, tentar completar checkout.
3. **Esperado:** checkout falha com o aviso amigável ("We could not register your payment...",
   ou a tradução se o site estiver em pt_BR/etc — ver Bloco 7), redireciona para o checkout,
   **sem fatal, sem tela branca**. Confirme isso é exatamente o que aparece.
4. Remover o trigger mesmo se o passo anterior falhar de forma inesperada:
   ```bash
   docker compose exec -T wp_db mysql -uwordpressdbuser -pwordpress123456 wordpressdb \
     -e "DROP TRIGGER IF EXISTS wp_paycrypto_me_validation_reject_insert;"
   ```
5. Repetir o mesmo pedido → agora completa normalmente e cria exatamente uma row.

**5b. Corrida real (melhor esforço, não determinístico — duas abas):**

> **Atualizado (2026-08-28):** esta descrição original foi escrita antes de
> `docs/PLAN-SCHEMA-INSTALL-HARDENING.md` (Front C) existir, quando a única proteção era a
> `UNIQUE KEY unique_order` da tabela — nesse regime, a perdedora da corrida via mesmo o aviso
> amigável, e isso contava como PASS. Front C mudou o que conta como sucesso aqui: agora a
> perdedora relê a linha e devolve o endereço da vencedora **sem** mostrar erro nenhum (ver o
> "Esperado" corrigido no passo 3 abaixo). O Bloco 14 mais adiante roda essa mesma corrida de duas
> abas com esse novo critério já como foco principal do bloco (e cobre também o caminho derivado,
> opcionalmente) — mantenha os dois se quiser tanto o smoke geral (Bloco 5) quanto o bloco dedicado
> ao achado do Front C (Bloco 14).

1. Configurar endereço fixo. Criar um pedido e ir até a tela de pagamento (`order-pay`), mas sem
   confirmar ainda, em **duas abas do navegador** (ou duas janelas anônimas) na mesma URL de
   pagamento do mesmo pedido.
2. Confirmar o pagamento nas duas abas o mais simultaneamente possível.
3. **Esperado agora (pós Front C):** as duas abas terminam na página do pedido normalmente, **sem**
   nenhuma mostrar o aviso amigável de falha — mesmo na pior hipótese de corrida genuína. Ver o
   aviso amigável em qualquer uma das duas abas é **FAIL** (é exatamente o sintoma que Front C
   corrigiu). Mais provável: as duas simplesmente mostram o mesmo resultado (PHP processa
   sequencialmente na maioria dos setups de dev) — isso também é PASS, mas não exercita a corrida de
   fato; não forçar artificialmente, só registrar se não conseguiu observar a corrida acontecendo.
4. Conferir no banco: exatamente **uma** linha para esse `order_id`, com o mesmo endereço mostrado
   nas duas abas.

**Resultado parcial (2026-08-29):**

- **5a: PASS após correção do Blocks.** Pedido 40: o trigger rejeitou o INSERT como
  esperado, nenhuma row nem order meta foi gravada, e o log registrou corretamente
  `Failed to persist fixed-address payment for order #40`. Porém o cliente viu apenas a mensagem
  genérica do Store API ("Something went wrong when placing the order...") em vez do aviso amigável
  do plugin. Trigger removido; retry então completou normalmente e o double-check confirmou uma
  única row sentinela `0/0`, endereço igual ao order meta e nenhum trigger restante. Persistência,
  rollback e recuperação: PASS. O primeiro teste revelou que o Blocks recebia a mensagem genérica
  do Store API; a correção passou a devolver a mensagem amigável no resultado de falha e a impedir
  que o erro SQL contamine a resposta JSON. No reteste do Blocks, pedido 44, o cliente viu
  corretamente "We could not register your payment. Please try again or contact the store.", o
  pedido permaneceu pendente e nenhuma row de transação foi criada. Controle
  no checkout Classic com pedido 42 mostrou corretamente "We could not register your payment...",
  sem criar row; após remover o trigger, o retry do 42 criou exatamente uma row sentinela `0/0`,
  com endereço igual ao order meta. Após o reteste, o trigger temporário também foi removido e sua
  ausência confirmada no banco. Novos cliques sem recarregar ainda exibiram o erro já mantido pelo
  estado do Blocks e não chegaram ao servidor (nenhum novo erro no log); após recarregar a página,
  o retry do 44 completou normalmente. Double-check: exatamente uma row sentinela `0/0`, endereço
  igual ao order meta, e uma única falha seguida por sucesso no log. Esse refresh é comportamento
  de recuperação do cliente após a falha forçada, não nova falha de persistência do plugin.
- **5b: PASS.** Pedido 39 processado em duas abas sem erro visível; o log mostra três passagens pelo
  processor em ~2 segundos, mas o banco tem exatamente uma row sentinela `0/0`, com endereço igual
  ao order meta e timestamps não regravados.

---

## Bloco 6 — Ambiente sem GMP

Repetir o Bloco 2 (endereço fixo, sub-caso 2a e 2d) num host/container sem a extensão GMP:

```bash
docker compose exec -T wordpress php -d disable_functions=gmp_init /usr/local/bin/wp eval '...'
```

(ou mais simples: usar `docker run --rm -v $(pwd)/src/trunk:/plugin -w /plugin php:8.3-cli` com a
extensão gmp de fato ausente na imagem, pra um teste mais realista que o `disable_functions`).

Esperado: 2a (bech32) completa normalmente e cria a linha. 2d (base58/legado, que depende de GMP)
deve ser rejeitado/indisponível de forma explicada — **não** fatal.

**Resultado:** **PASS** (2026-08-29) — nota: GMP foi removido de fato do carregamento do PHP no
container WordPress (`extension_loaded('gmp') === false`) e o Apache foi reiniciado; o site
continuou respondendo normalmente. No sub-caso 2a, o pedido 45 com endereço fixo bech32 completou
sem fatal e criou exatamente uma linha na tabela de transações, com sentinela
`wallet_xpubkeys_id=0`/`derivation_index_id=0`; o endereço persistido
`bc1qgvc07956sxuudk3jku6n03q5vc9tkrvkcar7uw` coincide com o order meta, e o pedido ficou pendente
com o método `paycrypto_me` em mainnet. Nos casos dependentes de GMP, incluindo 2d com endereço
legado `1…`, a configuração foi preservada e o admin exibiu o alerta explicativo, enquanto o
método ficou indisponível no checkout para o cliente, sem fatal — rejeição esperada para esse
ambiente.

---

## Bloco 7 — Tradução renderizada de verdade

A mensagem de falha de persistência (Bloco 5a) é a única string nova desta branch. Confirmar que
ela aparece **traduzida**, não em inglês, num site não-inglês:

1. `wp language core install pt_BR --activate` (ou trocar o site para pt_BR nas configurações gerais).
2. Repetir o Bloco 5a (ALTER TABLE temporário) com o site em pt_BR.
3. Esperado: o aviso amigável aparece em português ("Não foi possível registrar seu pagamento...").

**Resultado:** **SKIPPED por decisão** (2026-08-29) — nota: repetir toda a falha forçada de
persistência do Bloco 5 apenas para inspecionar visualmente uma única tradução foi considerado
esforço excessivo para o ganho de confiança. O comportamento funcional e a mensagem amigável já
foram validados no Bloco 5; este resultado não é registrado como PASS porque a renderização em
português não foi testada manualmente, mas a dispensa foi aceita como não bloqueadora.

---

## Bloco 8 — Lightning: smoke + double submit

O follow-up do último achado da rodada de code review agora toca `AbstractLightningProcessor`: se
duas requests criarem invoices para o mesmo pedido antes de qualquer uma persistir, a perdedora da
`UNIQUE KEY unique_order` relê e devolve a invoice vencedora que ficou no banco. O mesmo vale quando
ambas tentam substituir uma invoice expirada: `replace_invoice()` faz compare-and-swap pelo invoice
id antigo, então só uma request vence. A invoice criada pela request perdedora pode continuar
existindo no node, mas nunca chega ao order meta nem ao cliente — o registro persistido vence,
mantendo checkout e futuros webhooks em sintonia.

1. Fazer um pedido Lightning ponta a ponta (BTCPay ou lnd, o que estiver configurado).
2. Criar outro pedido e abrir a mesma tela `order-pay` em duas abas/janelas, antes de confirmar.
3. Confirmar nas duas tão simultaneamente quanto possível.
4. Esperado: ambas terminam normalmente na página do pedido, sem aviso de falha; ambas mostram o
   mesmo BOLT11/invoice id.
5. Confirmar no banco exatamente uma linha para o `order_id`, com o mesmo BOLT11/invoice id mostrado
   nas duas respostas. Se o node expuser a invoice perdedora criada durante uma corrida real, ela
   não pode ser a que aparece no pedido — registre isso na nota, mas não é FAIL por si só.
6. Repetir 2–5 depois que a invoice armazenada expirar, para cobrir a corrida de substituição; o
   resultado esperado é o mesmo.

**Resultado:** **PASS** (2026-08-29) — nota: smoke no pedido 46 criou exatamente uma invoice LND
e manteve invoice id, BOLT11 e payment URI idênticos entre a tabela e o order meta. Na corrida de
invoice ainda válida, o pedido 47 passou por três processamentos e terminou com uma única row,
um único invoice id e um único BOLT11, sem divergência no pedido. Para a substituição, o
`expires_at` persistido do pedido 48 foi movido de forma controlada para o passado e duas abas
reprocessaram o pedido: exatamente uma nova invoice substituiu a antiga, as duas passagens de
sucesso devolveram o mesmo BOLT11, e invoice id, payment request e URI finais coincidem exatamente
com o order meta. Houve timeouts de transporte do LND antes dos sucessos (cURL 28, inclusive nas
duas primeiras tentativas simultâneas da substituição), tratados como instabilidade externa do
node: essas tentativas não criaram nem corromperam rows; o retry terminou pendente e consistente.

---

## Bloco 9 — Instalação nova (site do zero)

Banco novo, plugin novo (branch já ativa). Confirmar: tabelas criadas, `DB_VERSION=1` gravado,
endereço fixo e derivado funcionando desde o primeiro pedido (repetir uma vez cada, rápido).

**Resultado:** **PASS** (2026-08-29) — nota: stack recriado do zero; ativação nova gravou
`paycrypto_me_db_version=1`, criou as 4 tabelas custom e não deixou option de erro/retry de schema.
O pedido fixo 15 ficou pendente com `paycrypto_me`, uma única row sentinela
`wallet_xpubkeys_id=0`/`derivation_index_id=0` e endereço idêntico ao order meta. O pedido derivado
17 ficou pendente com o mesmo método, uma única row ligada ao wallet mainnet 1 no índice 1, e
endereço e índice idênticos ao order meta; a tabela de índices também contém as reservas 0 e 1
para esse wallet.

---

## Bloco 10 — Reversão (o teste que sustenta ou derruba a hipótese de "não precisa tag de incompatibilidade")

Depois de rodar os blocos 1–9 com o código novo (banco agora tem linhas de endereço fixo que não
existiam antes desta branch):

```bash
git checkout main
```

(containers continuam rodando, banco intacto)

1. Abrir wp-admin → sem fatal, sem aviso.
2. Pedido **novo** de endereço fixo, código antigo → completa normalmente (o código antigo nunca lê
   a tabela nesse ramo, deve ignorar as linhas novas sem erro).
3. Reprocessar (`order-pay`) um pedido **criado durante os blocos 1–9** com o código novo → confirma
   que o código antigo não fatala nem se comporta mal ao encontrar uma linha sentinela
   (`wallet_xpubkeys_id=0`) que ele não sabe que existe (o ramo fixo do código antigo nunca chama
   `get_by_order_id()`, então isso deve ser um não-evento — mas confirme).
4. Pedidos derivados (antigos e feitos durante o teste) continuam corretos.

**Se isso passar limpo:** não acho que precisamos de tag de incompatibilidade — é aditivo e
reversível de verdade, não só na teoria. **Se falhar:** é o sinal concreto pra reconsiderar.

**Resultado:** **PASS** (2026-08-29) — nota: rollback real para `main` com o banco da instalação
nova intacto; wp-admin e front-end abriram sem fatal ou aviso de schema. No código antigo, o novo
pedido fixo 18 completou normalmente e, como esperado, não criou row na tabela. O pedido fixo 15
criado pelo código novo foi reprocessado sem erro: sua row sentinela `0/0` permaneceu intacta,
inalterada e com endereço igual ao order meta, sendo ignorada de forma segura pelo ramo fixo
antigo. O pedido derivado 17 também foi reprocessado sem erro e preservou sua única row no wallet
1/índice 1, com endereço e índice iguais ao order meta. O log registrou os três sucessos sem erro.
Branch `fix/schema-upgrade-and-static-records` restaurada depois do teste. A reversão é, portanto,
aditiva e segura no cenário validado, sustentando a decisão de não exigir tag de incompatibilidade.

---

## Bloco 11 — `uninstall.php` (sanidade rápida, custo quase zero)

Não foi tocado por esta branch, mas agora existe um tipo novo de linha na tabela que ele
deliberadamente preserva. Confirmar que continua preservando (não precisa desinstalar de verdade o
site principal — pode ser feito num site de teste descartável):

1. Num site de teste separado, com linhas de ambos os tipos (fixo e derivado) na tabela, desativar
   e desinstalar o plugin pelo wp-admin.
2. Confirmar: as 4 tabelas custom continuam existindo, com as linhas intactas.
3. Confirmar: as settings (incluindo secrets) foram removidas; `paycrypto_me_db_version` continua
   `1`, pois descreve a versão das tabelas deliberadamente preservadas e permite que uma futura
   reinstalação retome/atualize o schema correto (`uninstall.php` documenta esse contrato).

**Resultado:** **PASS** (2026-08-29) — nota: gerado o ZIP de produção 0.1.2 e instalado sob o slug
temporário isolado `paycrypto-me-block11`, sem risco de apagar o bind mount de `src/trunk`. Antes
do uninstall havia 4 tabelas, 1 wallet, 2 índices e 3 transações (incluindo rows fixa e derivadas);
foram também inseridos secrets Lightning sentinela para tornar sua remoção observável. O lifecycle
real de uninstall removeu ambas as options de settings e os secrets sentinela, preservou
`paycrypto_me_db_version=1`, as 4 tabelas e todas as contagens/rows/timestamps. Duas invocações
WP-CLI sobrepostas ficaram presas apenas na remoção física do diretório temporário depois de
`uninstall.php` já ter concluído; os dois processos foram encerrados e somente o pacote temporário
foi removido manualmente. O bind mount original permaneceu intacto. A expectativa antiga deste
bloco, de remover também `paycrypto_me_db_version`, foi corrigida para refletir o contrato explícito
do código: a versão precisa acompanhar o schema financeiro preservado.

---

## Bloco 12 — Checklist técnico automatizado (recap, já roda do meu lado)

Útil rodar você mesmo por conta própria, como parte de ficar em sintonia:

```bash
docker compose run --rm release ./vendor/bin/phpunit
./scripts/schema-tests.sh
./scripts/smoke-minimal-host.sh
docker compose exec -T wordpress wp --allow-root plugin check paycrypto-me-for-woocommerce --format=csv
```

**Resultado:** PASS / FAIL — nota: ___________

---

## Bloco 13 — Reparo de tabela ausente (docs/PLAN-SCHEMA-INSTALL-HARDENING.md, Front A)

Achado da revisão adversarial 2026-08-28 (M1): antes da Front A, um site com a versão gravada como
atual mas com uma tabela faltando (migração/restore que copiou `wp_options` mas não as 4 tabelas
custom; um merchant que apagou manualmente a tabela que `uninstall.php` deliberadamente preserva)
não tinha **nenhum** caminho de auto-reparo — nem a ativação, que curto-circuitava em `is_current()`.

1. Com o plugin ativo e saudável, apagar uma das 4 tabelas:
   ```bash
   docker compose exec -T wordpress wp --allow-root db query \
     "DROP TABLE wp_paycrypto_me_bitcoin_transactions_data;"
   docker compose exec -T wordpress wp --allow-root transient delete paycrypto_me_db_health_check
   ```
2. Carregar qualquer tela do **wp-admin** (dispara `admin_init`) → a tabela volta sozinha, sem
   precisar reativar o plugin. Confirmar:
   ```bash
   docker compose exec -T wordpress wp --allow-root db query "SHOW TABLES LIKE 'wp_paycrypto%'"
   ```
   → as 4 tabelas presentes, nenhum aviso novo no admin, `paycrypto_me_db_version` continua `1`.
3. **Throttle:** apagar a tabela de novo, **sem** apagar o transient `paycrypto_me_db_health_check`
   desta vez, e carregar o wp-admin de novo → a tabela **não** volta (o probe é pulado enquanto o
   transient estiver setado, no máximo a cada 12h).
4. Com a tabela ainda faltando, ir em Plugins → **desativar/reativar** o plugin → a tabela volta
   imediatamente (a ativação sempre repara, independente do transient/versão gravada).
5. Confirmar que nada disso aparece numa request de visitante (mesmo cheque do Bloco 1, passo 5 —
   `SAVEQUERIES`/Query Monitor numa aba anônima, sem `SHOW TABLES LIKE` nem `dbDelta` na query list).

**Resultado:** PASS / FAIL — nota: ___________

---

## Bloco 14 — Double submit num endereço fixo (docs/PLAN-SCHEMA-INSTALL-HARDENING.md, Front C)

Achado da mesma revisão (Front C): `insert_static_address()`/`insert_address()` retornando `false`
significava tanto "o INSERT falhou" quanto "já existe uma linha pra esse pedido" — a segunda request
de duas quase-simultâneas via `order-pay`/duplo-clique no checkout via a primeira leitura como "sem
linha ainda", perde a corrida do INSERT, e via então um erro de pagamento para um pedido que, de
fato, já estava registrado pela outra.

1. Configurar On-Chain com um endereço fixo (bech32).
2. Criar um pedido e chegar até a tela `order-pay`, mas **sem confirmar ainda**, em duas abas do
   navegador (ou duas janelas anônimas) apontando pra mesma URL de pagamento do mesmo pedido.
3. Confirmar o pagamento nas duas abas o mais simultaneamente possível.
4. Esperado: **nenhuma** das duas mostra o aviso amigável de falha de pagamento — as duas terminam
   na página do pedido normalmente (a perdedora da corrida agora relê e devolve o endereço da
   vencedora em vez de falhar).
5. Conferir no banco: exatamente **uma** linha para esse `order_id`.

**Extra opcional (Front C2, caminho derivado):** o mesmo fix foi aplicado simetricamente ao caminho
com xPub (`resolve_derived_address()`), mas ali o cenário é menos observável na UI — a proteção só
aparece quando o INSERT perde a corrida depois do índice já ter sido reservado, então o sinal visível
é o mesmo (nenhuma aba mostra erro, uma linha só na tabela) mas a garantia extra é o índice de
derivação reservado pela perdedora ser liberado (`release_derivation_index()`), não reaproveitado nem
vazado — algo que só dá pra confirmar olhando `paycrypto_me_bitcoin_derivation_indexes` (não há
índice "furado" nem duplicado para o wallet usado). Repita os passos 1–5 configurando um xPub/zPub
válido em vez do endereço fixo, se quiser cobrir os dois caminhos; cobertura automatizada já existe
(`BitcoinPaymentProcessorTest::test_derived_address_double_submit_returns_the_winners_row_and_releases_the_index`),
então isso é reforço, não bloqueador.

**Resultado:** PASS / FAIL — nota: ___________

---

## Resumo final

| Bloco | Resultado | Nota |
|---|---|---|
| 1 — Upgrade de instalação existente | **PASS** | Fluxo manual validado por Lucas; double-check independente confirmou schema v1 saudável e payment rows consistentes com os order metas (2026-08-29). |
| 2 — Endereço fixo, pedido novo (2a–2d + troca mid-flight) | **PASS** | Classic + Blocks, incluindo os casos opcionais; 6/6 rows sentinela consistentes e mid-flight confirmado manualmente (2026-08-29). |
| 3 — Regressão derivado | **PASS** | Pedido 29 reprocessado; uma row, endereço/índice inalterados e consistentes com order meta (2026-08-29). |
| 4 — Casos cruzados (fixo↔derivado) | **PASS** | Pedidos 37/38, uma row cada; endereço original preservado nos dois sentidos e metas consistentes (2026-08-29). |
| 5 — Falha determinística + corrida | | |
| 6 — Sem GMP | | |
| 7 — Tradução renderizada | | |
| 8 — Lightning smoke + double submit | | |
| 9 — Instalação nova | | |
| 10 — Reversão | | |
| 11 — uninstall.php | | |
| 12 — Técnico automatizado | | |
| 13 — Reparo de tabela ausente | | |
| 14 — Double submit endereço fixo (fixo + extra opcional derivado) | | |

**Decisão de bump de versão:** ___________
