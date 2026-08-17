# Destravar as versões do vendor: sair da resolução presa ao PHP 7.4

> **Status: proposto em 2026-08-17, não iniciado.** Nasceu de uma medição feita ao auditar o
> `config.platform.php` na branch `chore/retire-crypto-forks` — ver
> [`docs/CRYPTO-DEPENDENCIES.md`](CRYPTO-DEPENDENCIES.md) → E7.1/E7.2. **Depende de aprovação**: mexe
> em `composer.lock`, e o lock em vigor foi verificado ponta a ponta em 2026-08-17, na branch acima.
>
> Documento auto-suficiente: quem executar não precisa da conversa que o originou. Prosa em
> português; identificadores, caminhos e nomes de teste em inglês, como no resto do repo.
>
> **Referências:** este documento é linkado do `CLAUDE.md` (lista *Context and guides*) e do fim do
> E7.2 de [`docs/CRYPTO-DEPENDENCIES.md`](CRYPTO-DEPENDENCIES.md) — as duas pontas foram religadas no
> commit que o trouxe para o repo. Se este arquivo for movido ou renomeado, os dois links quebram.

---

## Antes de começar

**Leitura obrigatória**, nesta ordem — este plano depende do que está registrado lá e não repete:

1. [`docs/CRYPTO-DEPENDENCIES.md`](CRYPTO-DEPENDENCIES.md) → **E7** (por que o pin existe),
   **E7.1** (o bloqueio é o pin exato do upstream, não o pacote) e **E7.2** (o que o pin custa).
2. [`docs/CRYPTO-DEPENDENCIES.md`](CRYPTO-DEPENDENCIES.md) → **E4**, últimos parágrafos: a ressalva
   do namespace `Mdanter\Ecc` e a do `ConstantTimeMath`. Explicam por que prefixar namespaces é
   frente separada e não entra aqui.
3. `CLAUDE.md` → seção *Composer dependencies (important)*.

**Pré-requisitos do ambiente:**

- `src/trunk/vendor/` sincronizado com o `composer.lock` **antes** de qualquer medição de baseline:
  `ls src/trunk/vendor/bitwasp` deve mostrar `bech32 bitcoin buffertools`, e
  `src/trunk/vendor/lucas-rosa95` não deve existir. Se divergir,
  `docker-compose run --rm release composer install` primeiro.
- `plugin-check` instalado no volume do WP (nada no repo o provisiona):
  `docker-compose exec -T wordpress wp --allow-root plugin install plugin-check --activate`.
- **Forma do compose:** os comandos deste documento usam `docker-compose` (binário standalone),
  porque é o que existe na máquina onde ele foi escrito e medido. Os outros docs do repo usam a forma
  `docker compose` (plugin v2) por convenção — num host com o plugin, troque. Os scripts
  (`release.sh`, `smoke-minimal-host.sh`, `build-translations.sh`, `check-platform-pin.sh`) detectam
  as duas formas sozinhos.
- **Rede:** o `codeload.github.com` tem devolvido `HTTP/2 429` e `504` para download anônimo de dist.
  Se o `composer update` falhar por isso, as saídas são um `auth.json` na raiz do repo
  (o `release.sh` o encaminha via `COMPOSER_AUTH`) ou `--prefer-source`, que clona em vez de baixar
  zips. Foi assim que a medição M4 foi obtida.

**Branch sugerida:** `chore/lean-vendor-tree`. Commit isolado — nada de código de produção entra
junto (ver "Sem mudança de código de produção").

**Como abortar a qualquer momento**, antes de commitar:

```bash
git checkout src/trunk/composer.json src/trunk/composer.lock
docker-compose run --rm release composer install
```

Isso devolve a árvore ao estado do lock versionado. Nenhuma etapa deste plano é destrutiva fora
desses dois arquivos e do `vendor/` (gitignored).

---

## Context

`src/trunk/composer.json` fixa `config.platform.php = "7.4"`. A razão registrada (E7) é um pacote:
`bitwasp/bitcoin v1.1.0` exige `lastguest/murmurhash` na versão **exata** `v2.0.0`, que declara
`php: ^7`; sem o pin, uma resolução honesta em PHP 8 se recusa a instalar.

O que não estava registrado — e é o motivo deste plano — é que o pin **não age só sobre esse
pacote**. Ele manda o Composer resolver a árvore **inteira** como se fosse PHP 7.4, então todo
pacote da árvore é escolhido na melhor versão compatível com 7.4, não com o piso real do plugin
(`Requires PHP: 8.1`).

Resultado: o plugin publica hoje um polyfill de criptografia um *major* atrás e um polyfill de PHP 5
que nunca executa.

---

## Evidências medidas

Todas obtidas em 2026-08-17, no container `release` (PHP 8.3.33). Reprodução ao final de cada item.

### M1 — O alcance real do pin: 4 pacotes, não 1

| pacote | com o pin (enviado hoje) | sem o pin |
|---|---|---|
| `paragonie/sodium_compat` | **v1.24.0** — declara suporte de PHP 5.2.4 a 8 | **v2.5.0** — `php: ^8.1` |
| `paragonie/random_compat` | **instalado** (`v9.99.100`) | **não existe** |
| `genkgo/php-asn1` | v2.5.0 | v2.9.0 |
| `endroid/qr-code` | 4.6.1 | 4.8.5 |

O `paragonie/ecc v2.5.0` aceita `sodium_compat: ^1|^2` — o mesmo autor declara as duas faixas como
válidas, e a escolha da v1 é **puro artefato do pin**. O `random_compat` só está na árvore porque o
`sodium_compat` v1 o exige (`>=1`); a v2 não exige.

```bash
docker-compose run --rm release composer why paragonie/sodium_compat
docker-compose run --rm release composer why paragonie/random_compat
```

### M2 — O que desses pacotes realmente executa

Probe no caminho de produção — os 60 vetores de `tests/vectors/bitcoin_addresses.json` via
`BitcoinAddressService::generate_address_from_xPub()`, mais os dois validadores — inspecionando
`get_included_files()`:

| pacote | arquivos carregados |
|---|---|
| `paragonie/random_compat` | **0** — peso morto puro |
| `lastguest/murmurhash` | **0** — confirma E7 |
| `paragonie/sodium_compat` | **10** — está no caminho vivo |
| `paragonie/ecc` | 36 (`GmpMath.php`, não `ConstantTimeMath`) |

### M3 — O `sodium_compat` é o único autoload **eager** da árvore

`paragonie/sodium_compat` declara `"autoload": {"files": ["autoload.php"]}`, e é a **única** entrada
em `vendor/composer/autoload_files.php`. Isso significa que ele é carregado por `require` em **todo
request** que carrega o autoloader do plugin — inclusive requests que nunca tocam Bitcoin.

Custo medido do load, na v1.24.0 que está enviada:

```
tempo: 4.62 ms | memoria: 506 KB | arquivos: 9
ext-sodium nativa neste PHP? sim
```

> O **tempo varia entre execuções** — repetições do mesmo comando deram de 3.0 a 4.6 ms. Trate a
> ordem de grandeza (poucos milissegundos) e não o número exato; a memória (506 KB) é estável.
> Os "9 arquivos" são o que o `require` puxa num processo CLI limpo; o request do WP abaixo conta 10
> porque inclui também o `autoload-php7.php` que o autoloader do pacote escolhe naquele contexto.

E dentro do container `wordpress`, num request normal do WP:

```
arquivos sodium_compat carregados: 10
  wp-content/plugins/paycrypto-me-for-woocommerce/vendor/paragonie/sodium_compat/autoload.php
  ...
classe ParagonIE_Sodium_Compat existe? sim      ext-sodium nativa? sim
```

Dois fatos daí, ambos contraintuitivos:

1. Num host com `ext-sodium` nativa (a maioria), esse trabalho é **funcionalmente inútil**: os
   guards de `function_exists` do polyfill fazem as funções nativas prevalecerem. Pagamos o load
   para nada.
2. Os arquivos carregados são **os nossos**, não os do core. O WordPress também empacota
   `sodium_compat` (`wp-includes/sodium_compat/`), mas carrega o dele sob demanda; o nosso entra
   eager. Ou seja: **é a nossa cópia que define as classes globais `ParagonIE_Sodium_*`** (sem
   namespace) para o site inteiro, chegando antes da do core. Somos o lado que sombreia.

**A v2.5.0 continua eager** (`{"files":["autoload.php"],"psr-4":{"ParagonIE\\Sodium\\":"namespaced/"}}`),
carregando 8 arquivos em vez de 10. O upgrade **não** elimina esse custo — reduz um pouco. Isso é
importante para não vender o plano como algo que ele não é.

```bash
grep -o "sodium_compat[^']*" src/trunk/vendor/composer/autoload_files.php
docker-compose exec -T wordpress wp --allow-root eval \
  'echo count(array_filter(get_included_files(), fn($f) => str_contains($f, "sodium_compat")));'
```

### M4 — A árvore enxuta resolve, instala e passa verde

Feito numa cópia isolada, com `config.platform.php = "8.1"` e
`"replace": {"lastguest/murmurhash": "2.0.0"}`, lock apagado e `composer update` do zero:

```
PACOTES NAO-DEV: 11  (hoje: 13)
  bacon/bacon-qr-code 2.0.8      bitwasp/bech32 v0.0.1       bitwasp/bitcoin v1.1.0
  bitwasp/buffertools v0.5.7      composer/semver 3.4.4        dasprid/enum 1.0.7
  endroid/qr-code 4.8.5           genkgo/php-asn1 v2.9.0       paragonie/ecc v2.5.0
  paragonie/sodium_compat v2.5.0  pleonasm/merkle-tree 1.0.0

SUITE:   Tests: 363, Assertions: 755, Skipped: 4  — OK
AUDIT:   No security vulnerability advisories found
VETORES: 60 derivados, 0 divergências
POLYFILLS: random_compat 0 arquivos (ausente) | sodium_compat 8 | murmurhash 0
```

Os três pacotes de cripto que importam ficam **idênticos** (`bitwasp/*` e `paragonie/ecc v2.5.0`).
Nenhuma linha de código do plugin muda.

Observação de execução: um `composer update` re-resolve **tudo**, então as dev-deps também sobem
(`phpunit 9.6.34 → 9.6.36`, `doctrine/instantiator 1.5.0 → 2.0.0`, etc.). Não são enviadas, mas
precisam entrar consciente no diff do lock.

### M5 — Tamanho, e o que sai do zip

| pacote | tamanho no vendor |
|---|---|
| `lastguest/murmurhash` | 112 KB |
| `paragonie/random_compat` | 52 KB |
| `paragonie/sodium_compat` | 1.8 MB (v1; a v2 substitui, não some) |

Ganho de tamanho é modesto (~164 KB de remoção limpa). **O argumento deste plano não é tamanho** —
é parar de enviar versões da era 7.4 de bibliotecas adjacentes a criptografia, e parar de enviar um
polyfill de PHP 5 que nunca executa.

### M6 — O piso do plugin e o do `sodium_compat` v2 coincidem

`Requires PHP: 8.1` no header do plugin; `paragonie/sodium_compat v2.5.0` declara `php: ^8.1`.
Depois da mudança, o que o `composer.json` diz e o que a árvore instalada realmente exige passam a
ser a **mesma** coisa — hoje divergem (o vendor enviado tecnicamente roda em 7.4).

### M7 — Sem advisory: isto não é correção de segurança

`composer audit --locked` está limpo no lock **atual**, com a `v1.24.0`. Não existe advisory contra
ela. É defasagem de manutenção, não vulnerabilidade — e o texto público (CHANGELOG/`readme.txt`) não
pode sugerir o contrário. O achado A1 de
[`docs/CRYPTO-DEPENDENCIES-AUDIT.md`](CRYPTO-DEPENDENCIES-AUDIT.md) é precedente exato disso.

---

## Decisão

**Subir o pin para o piso real (`8.1`) e `replace` o `murmurhash`** — não remover o pin.

Por que `platform.php = "8.1"` em vez de apagar a chave: com a chave fora, a resolução passa a
depender do PHP do container que roda o build ("o que estiver instalado"). Com `8.1` — o piso
declarado no header — a resolução é **reprodutível e honesta**: qualquer máquina resolve o mesmo
lock, e o pin deixa de ser uma supressão para ser uma declaração.

### O que abre mão, explicitamente

Com o `replace`, o `lastguest\Murmur` não é instalado, e
`BitWasp\Bitcoin\Crypto\Hash::murmur3()` — alcançável só de `Bloom/BloomFilter.php:250` — passa de
*código morto que funciona* para *código morto que estoura* (`Class not found`). O plugin não
referencia nenhum dos dois (M2: 0 arquivos carregados), mas isso é uma propriedade do código de
**hoje**.

Mitigação obrigatória, e é o que torna esta decisão aceitável: um **teste de guarda** que falha se
código do plugin passar a referenciar esses símbolos (ver "A mudança", item 4). Assim a consequência
aparece como teste vermelho em desenvolvimento, não como fatal em loja.

### Alternativas descartadas

| Alternativa | Por que não |
|---|---|
| **Esperar o PR upstream** (`v2.0.0` → `^2.0` no `bitwasp/bitcoin`) | É a solução **certa** — remove o pin sem `replace` e mantém o `murmurhash` funcional. Mas depende de terceiro, com latência desconhecida. **Deve ser enviado de qualquer forma**: se entrar antes da execução, este plano vira "trocar o pin por nada" e o `replace` é dispensado. Ver E7.1. |
| **`replace` do `sodium_compat`,** confiando na cópia do WP core | É a única coisa que eliminaria o load eager de M3, e o core sempre a tem (bundled desde o WP 5.2; exigimos 6.5). Rejeitado: `wp-includes/sodium_compat/` é biblioteca **interna** do core, não contrato público — a versão muda a cada release do WP, sem coordenação, e o `paragonie/ecc` espera uma faixa específica. Trocar dependência declarada por dependência implícita num caminho de cripto não vale poucos milissegundos por request. |
| **Remover o `sodium_compat`** | Impossível: é requisito duro do `paragonie/ecc` (`^1|^2`). |
| **Prefixar namespaces do vendor** (php-scoper/Strauss) | Frente separada, com justificativa própria (o `paragonie/ecc` ocupa `Mdanter\Ecc\*` — ver a ressalva no E4). **Não** deve ser usada como justificativa para o `replace`: a metade da objeção que o scoping resolveria (terceiro consumindo nossas classes) é a mais fraca; a que sobrevive é a nossa, e é a que o teste de guarda cobre. |
| **Não fazer nada** | Mantém o polyfill de cripto um major atrás, o `random_compat` inerte no zip, e a divergência entre o piso declarado e o instalado. |

---

## A mudança

### 1. `src/trunk/composer.json`

| O quê | De | Para |
|---|---|---|
| `config.platform.php` | `"7.4"` | `"8.1"` |
| `replace` | (ausente) | `{"lastguest/murmurhash": "2.0.0"}` |

Forma final das duas chaves — `replace` é chave de topo, irmã de `require`; o resto do arquivo
(`name`, `version`, `autoload`, `authors`, `require`, `require-dev`) **não muda**:

```json
    "config": {
        "platform": {
            "php": "8.1"
        }
    },
    "replace": {
        "lastguest/murmurhash": "2.0.0"
    },
```

Duas coisas que **não** precisam mudar, para não haver dúvida:

- `"endroid/qr-code": "^4.6"` já permite a `4.8.5`. Nenhum constraint do `require` é tocado — os
  upgrades de M1 vêm só de deixar a resolução usar o piso real.
- `"ext-gmp"` e `"ext-gd"` continuam como estão.

**Consequência a registrar (e que vale para o CHANGELOG):** depois disso o código da árvore instalada
passa a ter PHP 8.1 como alvo real, porque o `sodium_compat v2.5.0` declara `php: ^8.1` — hoje o
`vendor/` enviado tecnicamente roda em 7.4. Isso é coerente com o `Requires PHP: 8.1` do header, que
é o portão de verdade: o WordPress bloqueia a ativação abaixo disso desde a 5.5.

> **O que essa consequência NÃO é.** Ela não muda o comportamento do `composer install`. O
> `config.platform` governa a checagem de plataforma **do próprio install**, não só a resolução — a
> prova está no estado atual: hoje `platform.php = "7.4"` e o `composer install` roda verde no PHP
> 8.3 mesmo com `lastguest/murmurhash` exigindo `php: ^7`. Se o override não governasse o install,
> esse estado seria impossível. Com o pin em `"8.1"` o Composer passa a assumir 8.1, então um install
> num host PHP 8.0 continua **funcionando** — a quebra, se houver, é de runtime. Não escreva no
> CHANGELOG que o install passa a exigir 8.1.

### 2. `src/trunk/composer.lock`

Regravado assim — é exatamente o que M4 mediu:

```bash
rm -f src/trunk/composer.lock
docker-compose run --rm release composer update --prefer-dist --no-interaction
# se der 429/504 do codeload: --prefer-source, ou um auth.json na raiz
```

O diff **precisa ser revisado item a item** e o delta registrado na mensagem do commit:

| esperado | pacotes |
|---|---|
| 2 remoções | `lastguest/murmurhash`, `paragonie/random_compat` |
| 3 upgrades não-dev | `paragonie/sodium_compat`, `genkgo/php-asn1`, `endroid/qr-code` |
| upgrades de dev-deps | os de M4 (`phpunit`, `doctrine/instantiator`, etc.) — não são enviados |
| **intactos** | `bitwasp/bitcoin`, `bitwasp/buffertools`, `bitwasp/bech32`, `paragonie/ecc` |

**Se qualquer pacote de cripto mudar além disso, pare o plano** e reavalie — a premissa medida é que
a troca não toca a cadeia de derivação de endereço.

Por que `composer update` completo em vez de alvo dirigido: foi a forma medida em M4, e mudar o
`platform` obriga a re-resolver toda a árvore de qualquer jeito. A churn de dev-deps é o preço, e é
inofensiva (não vai no zip — o `release.sh` instala `--no-dev` num diretório de build próprio). Um
update dirigido reduziria a churn, mas não foi medido; se for tentado, é preciso repetir a
verificação inteira em cima dele.

### 3. `scripts/check-platform-pin.sh` — semântica pin-vs-piso

O script existe desde o commit `1b59409`. Hoje ele trata **qualquer** pin como supressão e, se nada
bloquear o piso, avisa "o pin virou peso morto, remova". Depois desta mudança (pin = piso, nada
bloqueando) isso seria falso alarme em toda execução — inclusive dentro do `release.sh`. Passa a
distinguir dois regimes:

| relação | regime | o que checar |
|---|---|---|
| `platform.php` **>=** piso | **declaração** | nada na árvore pode bloquear o piso. Se algo bloquear, é falha real: o pin não está escondendo nada, o pacote simplesmente não satisfaz o piso declarado |
| `platform.php` **<** piso | **supressão** | auditar contra a allowlist, exatamente como hoje |

E a `ALLOWED_OFFENDERS` fica **vazia** (o `murmurhash` sai da árvore) — o estado saudável.

Dois detalhes de implementação que já custaram tempo em scripts parecidos:

- Comparar `8.1` com `7.4` como **versão**, não como string (`"8.10" < "8.9"` em comparação
  lexicográfica). O caminho curto é `sort -V`, ou `printf '%s\n' "$a" "$b" | sort -V | head -1`.
- Com `set -u`, expandir array vazio (`"${ALLOWED_OFFENDERS[@]}"`) aborta em bash < 4.4. Guardar com
  `if [[ ${#ALLOWED_OFFENDERS[@]} -gt 0 ]]` antes de iterar. Com a lista vazia esse caminho nem é
  alcançado no regime de declaração, mas volta a ser se alguém baixar o pin no futuro — e um guard
  que morre em vez de acusar é pior que guard nenhum.

A tabela de três resultados em [`docs/RELEASE.md`](RELEASE.md) → "Auditoria do pin de plataforma"
descreve o comportamento antigo; ela ganha o caso novo (ver item 5).

**Um caminho que já está pronto e não precisa de trabalho:** se o PR upstream de E7.1 entrar e o
`platform.php` sair de vez, o script já trata pin ausente — `check-platform-pin.sh:80-83` devolve
*"No platform pin in composer.json — nothing to audit"* com exit 0. Não implemente esse caso de novo.

### 4. Teste de guarda (novo)

`src/trunk/tests/phpunit/unit/VendorReplaceGuardTest.php`. Varre os arquivos `.php` de
`src/trunk/includes/` e `src/trunk/exceptions/` e falha se algum contiver referência a:

| símbolo | por que |
|---|---|
| `lastguest\Murmur` (escapar a barra no regex) | a classe não existe na árvore por causa do `replace` |
| `murmur3` | o único método que a alcança dentro da lib |
| `BloomFilter` | o único chamador de `murmur3()` |

**A granularidade é o método, não a classe — e isso é deliberado.**
`BitWasp\Bitcoin\Crypto\Hash` expõe 9 métodos públicos estáticos, e o `replace` afeta **um**:

```
sha256ripe160  sha256  sha256d  ripemd160  ripemd160d  sha1  pbkdf2  hmac   ← intactos, usáveis
murmur3                                                                     ← o único afetado
```

Proibir `Crypto\Hash` como classe proibiria `Hash::sha256()` e companhia, que funcionam e não têm
relação alguma com o `murmurhash`. Hoje o plugin não referencia nenhum deles (medido: zero
ocorrências de `Crypto\Hash`, `Hash::`, `BloomFilter` ou `Murmur` em `includes/` e `exceptions/`), mas
um guard largo daria teste vermelho com mensagem enganosa a quem usasse `Hash::sha256()` — e o
caminho de menor resistência seria enfraquecer o guard em vez de corrigi-lo.

Mensagem de falha explicando o contrato, não só o fato: *este símbolo não existe na árvore enviada
porque `composer.json` faz `replace` do `lastguest/murmurhash` — ver docs/LEAN-VENDOR-TREE.md. Para
usá-lo, o `replace` precisa sair primeiro (e o pin de plataforma volta a ser necessário).*

**Deliberadamente é grep de código-fonte, não `class_exists()`.** Assertar
`class_exists('lastguest\Murmur') === false` acoplaria o teste ao estado do `vendor/` e falharia
numa máquina que tenha o pacote instalado por outro motivo — por exemplo alguém testando o PR
upstream de E7.1, que é justamente o cenário em que o `replace` deve sair. O que precisa ser pinado é
o **nosso código**, não a árvore.

Precedente do padrão: `OrderDetailsTemplateMarkupTest`, que existe para pinar uma invariante que
nenhum outro teste da suíte consegue ver.

### 5. Documentação

| Arquivo | O quê |
|---|---|
| `docs/CRYPTO-DEPENDENCIES.md` | E7/E7.1/E7.2 passam a descrever o estado final (pin = piso, `replace`, allowlist vazia). Manter o histórico do porquê — não reescrever como se o pin de 7.4 nunca tivesse existido. |
| `docs/CRYPTO-DEPENDENCIES.md` → **E6** | **Condicional:** só se o item 10 da Verificação medir algo diferente de 12. Nesse caso, atualizar o título, a tabela e a linha do total — e conferir se as menções no `CLAUDE.md` e no `CRYPTO-DEPRECATION-CONTINGENCY.md` continuam válidas. Se der 12, não tocar. |
| `CLAUDE.md` | Seção *Composer dependencies*: atualizar o parágrafo do pin e a nova semântica da guarda. |
| `docs/RELEASE.md` | Seção "Auditoria do pin de plataforma": a tabela de resultados ganha o caso "pin = piso". |
| `src/trunk/CHANGELOG.md` | Em `## Unreleased` → `### Changed`, sem sugerir correção de segurança (M7): dependências atualizadas para as versões compatíveis com o PHP 8.1 que o plugin já exige, e dois pacotes de compatibilidade com PHP antigo removidos do pacote publicado. |
| `src/trunk/readme.txt` | **Nada agora.** Este plano não corta versão; a entrada vai para `## Unreleased` e o `== Changelog ==` do `readme.txt` é sincronizado na hora do release, conforme o checklist de [`docs/RELEASE.md`](RELEASE.md). |

### Sem mudança de código de produção

Nenhum arquivo em `includes/`. Nenhuma string nova, nenhuma tradução. O único código novo é o teste
de guarda.

---

## Verificação

Rodar da raiz do repo. Itens 1–4 são o núcleo; 5–8 fecham o release; 9–10 cobrem duas coisas que os
outros oito **não** alcançam (o caminho de install do release e o número de deprecations que três
documentos citam).

> **Meça o baseline antes de mudar qualquer coisa** (com o vendor sincronizado): hoje a suíte é
> **363 testes / 755 asserções / 4 skipped**. É contra esse número que os itens abaixo são lidos —
> nenhum teste nem asserção existente pode desaparecer.

| # | Comando | Esperado |
|---|---|---|
| 1 | `docker-compose run --rm release ./vendor/bin/phpunit` | `363 + N` testes (N = métodos do teste de guarda), asserções `>= 755`, **4 skipped**, 0 falhas. Perder teste ou asserção é regressão, não "mudou o número" |
| 2 | `docker-compose run --rm release composer audit --locked` | `No security vulnerability advisories found` |
| 3 | Revisão do diff do `composer.lock` | Exatamente o delta de M4: `bitwasp/*` e `paragonie/ecc` **intactos** |
| 4 | `BitcoinAddressVectorsTest` (dentro do item 1) | 60/60 endereços idênticos |
| 5 | `./scripts/check-platform-pin.sh` | Reporta o pin como **declaração** (pin = piso), allowlist vazia, exit 0 |
| 6 | `./scripts/smoke-minimal-host.sh` | Todos os checks passando |
| 7 | `docker run --rm -v $(pwd)/src/trunk:/plugin -w /plugin php:8.3-cli php ./vendor/bin/phpunit --filter OnchainWithoutGmpTest` | 10 testes, 17 asserções, 1 skipped |
| 8 | `docker-compose exec -T wordpress wp --allow-root plugin check paycrypto-me-for-woocommerce --format=csv` | Nenhum `ERROR` em código enviado |
| 9 | **Install limpo `--no-dev`** (bloco abaixo) — é o caminho que o `release.sh` usa, e nenhum item de 1 a 8 o exercita | 11 pacotes, `vendor/lastguest` e `vendor/paragonie/random_compat` ausentes |
| 10 | **Probe de deprecations** (Apêndice de [`CRYPTO-DEPENDENCIES-AUDIT.md`](CRYPTO-DEPENDENCIES-AUDIT.md)) contra o vendor novo | **12** diagnósticos, iguais aos de E6. Se mudar, atualizar o E6 (item 5) |

**Item 9 — por que ele existe.** M4 mediu `composer update`, e os itens 1 a 8 rodam sobre o vendor de
desenvolvimento. Mas o que vai para as lojas é outro caminho: o `release.sh` faz `rsync` de
`src/trunk` **sem** `vendor/` e roda `composer install --no-dev --optimize-autoloader --prefer-dist`
num diretório de build, a partir do lock commitado. O comportamento do `replace` nesse install limpo
é o que determina o zip publicado. Mesmo formato usado na Parte 1 de
[`CRYPTO-DEPENDENCIES-AUDIT.md`](CRYPTO-DEPENDENCIES-AUDIT.md):

```bash
docker-compose run --rm -e COMPOSER_AUTH= release bash -lc '
  rm -rf /tmp/fresh && mkdir -p /tmp/fresh && cd /tmp/fresh
  cp /plugin/composer.json /plugin/composer.lock .
  mkdir -p includes exceptions
  composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction
  echo "--- pacotes instalados:"; find vendor -mindepth 2 -maxdepth 2 -type d | wc -l   # 11 (hoje: 13)
  echo "--- murmurhash:";    ls vendor/lastguest 2>&1 || echo "ausente (ok)"
  echo "--- random_compat:"; ls vendor/paragonie/random_compat 2>&1 || echo "ausente (ok)"
  echo "--- eager:"; grep -c sodium_compat vendor/composer/autoload_files.php   # 1'
```

**Item 10 — por que ele existe.** O E6 de [`CRYPTO-DEPENDENCIES.md`](CRYPTO-DEPENDENCIES.md) fixa
"7 (fork) → **12** (upstream)" no título e numa tabela, e esse número sustenta a decisão de aceitar as
deprecations — além de ser referenciado pelo `CLAUDE.md` e pelo `CRYPTO-DEPRECATION-CONTINGENCY.md`.
Os 12 vêm de `buffertools` (5) e `bitcoin` (7), **ambos intactos** neste plano, então o esperado é que
não mude. Mas o `sodium_compat` está no caminho vivo de derivação (M2/M3) e sobe de major, e
`endroid`/`genkgo` também mudam: qualquer diagnóstico novo que eles emitam torna o E6 obsoleto em três
documentos ao mesmo tempo.

Checagens específicas desta frente:

```bash
# o murmurhash e o random_compat sairam do vendor?
ls src/trunk/vendor/lastguest 2>&1            # nao deve existir
ls src/trunk/vendor/paragonie/random_compat 2>&1   # nao deve existir

# a contagem de pacotes nao-dev caiu de 13 para 11?
python3 -c "import json; print(len(json.load(open('src/trunk/composer.lock'))['packages']))"   # 11

# as versoes batem com M4?
python3 -c "
import json
for p in sorted(json.load(open('src/trunk/composer.lock'))['packages'], key=lambda x: x['name']):
    print(f\"  {p['name']:32} {p['version']}\")"

# o sodium_compat subiu, e continua sendo o unico eager?
grep -o "sodium_compat" src/trunk/vendor/composer/autoload_files.php | wc -l   # 1

# o custo do load eager caiu (M3 mediu 4.62 ms / 506 KB na v1)
docker-compose run --rm release php -r '
$m0=memory_get_usage(); $t0=microtime(true);
require "/plugin/vendor/paragonie/sodium_compat/autoload.php";
printf("%.2f ms | %.0f KB\n", (microtime(true)-$t0)*1000, (memory_get_usage()-$m0)/1024);'
```

> **Teste manual mínimo:** salvar as settings On-Chain com um xPub válido e fazer um pedido de teste
> até a tela de order-details (endereço + QR renderizados).
>
> O upgrade do `endroid/qr-code` (4.6.1 → 4.8.5) é *minor* dentro da v4, mas a suíte o exercita com
> stubs, então o QR real só é verificável a olho. O consumidor único é
> `src/trunk/includes/services/class-qr-code-service.php`, e o que precisa sobreviver ao minor é a
> cadeia `Builder::create() … ->build() … getDataUri()` em **dois** sítios (~`:102-119`, o caminho com
> logo/badge, e ~`:163-172`, o simples), mais as classes `Encoding`, `ErrorCorrectionLevelHigh`,
> `ErrorCorrectionLevelLow` e `PngWriter`. Conferir os dois caminhos: um pedido com logo configurado e
> um sem.

---

## Fora de escopo

- **Prefixar namespaces do vendor.** Frente própria; ver a ressalva do E4 em
  [`docs/CRYPTO-DEPENDENCIES.md`](CRYPTO-DEPENDENCIES.md).
- **Eliminar o load eager do `sodium_compat`.** Só o `replace` dele resolveria, e está rejeitado
  acima. Se algum dia virar prioridade, a medição de M3 (poucos ms + 506 KB por request) é a
  baseline a bater.
- **Subir o piso de PHP do plugin.** Não faz parte disto: o piso é 8.1 e continua 8.1; a mudança só
  faz a árvore instalada passar a respeitá-lo de verdade.

---

## Ordem e base

**Base:** branch `chore/lean-vendor-tree`, criada a partir de `chore/retire-crypto-forks` no commit
`1b59409` — que estava fechada e aguardando só os testes manuais para ser mergeada. Esta frente
**não** entra naquela branch: ela existe para aposentar os forks e conter as deprecations, e o lock
dela foi verificado ponta a ponta em 2026-08-17. Um lock novo com 5 pacotes mexidos exige a sua
própria rodada de verificação, e misturar as duas tira a possibilidade de bissecar se algo aparecer.

Consequência prática de ter partido da branch em vez da `main`: quando `chore/retire-crypto-forks`
mergear, rebasear esta em cima da `main` antes de abrir o PR — os commits daquela branch vão aparecer
como já presentes, e o diff desta deve ficar restrito ao que está descrito em "A mudança". Se o diff
mostrar mais que isso depois do rebase, o rebase saiu errado.

**Antes de executar:** enviar o PR upstream de E7.1. Se ele for aceito, reavaliar — o plano encolhe
para "trocar o pin por nada", sem `replace` e sem o teste de guarda do item 4.

**Ao concluir:** atualizar o bloco de *Status* no topo deste arquivo para
`**Status: executado e verificado em <data>**`, com os números realmente medidos (contagem da suíte,
pacotes não-dev, resultado do `check-platform-pin.sh`) substituindo os previstos, e marcar o que
ficou pendente — é a convenção dos outros documentos deste `docs/`
([`CRYPTO-DEPENDENCIES.md`](CRYPTO-DEPENDENCIES.md) e
[`CRYPTO-DEPRECATION-CONTINGENCY.md`](CRYPTO-DEPRECATION-CONTINGENCY.md) são os exemplos). Um plano
que fica marcado como "proposto" depois de executado faz o próximo agente refazer a análise.

**Se abortar:** usar o procedimento de "Antes de começar" e registrar aqui, no Status, o que falhou e
onde — um plano que volta a "proposto" sem dizer por que já falhou uma vez custa a mesma investigação
duas vezes.
