# Voltar `bitwasp/bitcoin` para o upstream oficial e aposentar os forks

> **Status: executado e verificado em 2026-08-15** (aprovado em 2026-08-14). Os forks
> `lucas-rosa95/*` foram aposentados e o plugin voltou aos pacotes oficiais `bitwasp/*`. A
> verificação abaixo rodou e passou: suíte **355/743/4 skipped**, `composer audit --locked` limpo
> **sem lista de ignore**, **60/60** vetores de endereço idênticos, `Signature` instanciável (o
> fatal latente de E2 sumiu), smoke de host mínimo verde e plugin check **sem ERROR em código
> enviado**. Feito na branch `chore/retire-crypto-forks`.
>
> Os números de suíte citados aqui (355/743/4) são os desta frente. O baseline atual da branch é
> **363/755/4** — os 8 testes a mais vêm do helper de supressão de deprecations documentado em
> [`docs/CRYPTO-DEPRECATION-CONTINGENCY.md`](CRYPTO-DEPRECATION-CONTINGENCY.md), que entrou depois.
> A revisão independente desta frente está em
> [`docs/CRYPTO-DEPENDENCIES-AUDIT.md`](CRYPTO-DEPENDENCIES-AUDIT.md) (achados todos aplicados).
>
> Documento auto-suficiente: quem executar não precisa da conversa que o originou. Prosa em
> português; identificadores, caminhos e nomes de teste em inglês, como no resto do repo.

---

## Context

O plugin depende de dois forks pessoais, ambos travados em `dev-master`:

| Pacote instalado | Origem | Ref travada no `composer.lock` |
|---|---|---|
| `lucas-rosa95/bitcoin` | fork de `Bit-Wasp/bitcoin-php` | `fb5f0d23` (2026-01-03) |
| `lucas-rosa95/buffertools-php` | fork de `Bit-Wasp/buffertools-php` | `7dbacdbd` (2026-01-03) |

Os forks nasceram para resolver incompatibilidade com PHP 8.x — o problema não era o
`bitwasp/bitcoin` em si, mas dependências transitivas sem manutenção. Havia também uma
vulnerabilidade de canal lateral por tempo (timing attack) na biblioteca de curva elíptica.

A pergunta que originou este plano: **os forks ainda se justificam, ou vale voltar aos pacotes
oficiais?** Restrição de produto: o plugin é **não-custodial** e deve continuar sendo — nenhuma
chave privada trafega ou é armazenada.

**Resposta curta, medida:** voltar. O fork de `bitcoin` não tem **nenhuma** correção própria de
código, está **atrás** do upstream por um método cuja ausência é **fatal ao carregar a classe**, e
o motivo original dele já foi resolvido no upstream. A vulnerabilidade de timing já não se aplica.

---

## Evidências medidas

Todas reproduzíveis com os comandos da seção "Verificação".

### E1 — O fork de `bitcoin` não tem correção própria de código

Divergiu do upstream em `e5a6125b` (2019-04-30, v1.0.1) e depois **reaplicou manualmente** os
commits do upstream de 2019–2020 (mesmos assuntos, hashes diferentes). Diff de `src/` entre o fork
e o upstream `v1.1.0` hoje:

```
 src/Crypto/EcAdapter/Impl/PhpEcc/Signature/Signature.php | 5 -----
 1 file changed, 5 deletions(-)
```

Uma única diferença, e é o fork **removendo** um método que o upstream tem. Todo o resto do que o
fork acrescenta está em `composer.json` (renome de pacote, versões, `minimum-stability`).

### E2 — Essa diferença é um fatal latente

`paragonie/ecc` v2.5.0 declara `getSignatureType(): string` em `SignatureInterface`. O fork não
implementa. Carregar a classe:

```
PHP Fatal error: Class BitWasp\Bitcoin\Crypto\EcAdapter\Impl\PhpEcc\Signature\Signature
contains 1 abstract method and must therefore be declared abstract or implement the remaining
methods (Mdanter\Ecc\Crypto\Signature\SignatureInterface::getSignatureType)
```

Hoje não explode porque o plugin nunca assina nada e essa classe nunca é carregada. Qualquer
caminho que a toque — inclusive um add-on de terceiro ou o add-on premium — derruba o site. No
upstream a classe é instanciável.

### E3 — O motivo original do fork já foi resolvido upstream

| Commit upstream | Data | Assunto |
|---|---|---|
| `058ac349` | 2024-04-28 | **Migrate to secure ECC library** |
| `527b1ee7` | 2026-02-25 | Add required method for paragonie/ecc signature (tag `v1.1.0`) |

O upstream migrou para `paragonie/ecc` quatro dias depois do advisory de timing, e continua
recebendo commits. O fork fez a própria migração em paralelo, e é justamente o follow-up
(`527b1ee7`) que ele não tem — a causa de E2.

### E4 — A vulnerabilidade de timing não se aplica mais

Os dois IDs silenciados em `composer.json` → `config.audit.ignore`:

| ID | O que é | Afeta |
|---|---|---|
| `PKSA-j43q-24zh-tyzv` | **CVE-2024-33851** — timing vulnerability in cryptographic side-channels | `mdanter/ecc` `>=0,<1` e `>=1,<2.0.0` |
| `PKSA-36gf-zqdd-tq5m` | Cryptographic side-channels in PHPECC | `mdanter/ecc` (mesmas faixas) |

Ambos são contra **`mdanter/ecc`**, que **não está mais na árvore**: o plugin usa
`paragonie/ecc v2.5.0` — o fork endurecido da Paragon, criado em resposta a esse advisory, que traz
`ConstantTimeMath`. O advisory próprio do `paragonie/ecc` (`PKSA-jz93-gkdw-s495`) afeta `<2.0.1`.

Consequência: **as duas entradas de `audit.ignore` são resíduo morto.** `composer audit --locked`
sem elas não acusa nada. Mantê-las só desliga o alarme para o futuro.

Relevância para este plugin, independentemente disso: canal lateral por tempo ataca operação de
curva com **escalar secreto** — assinatura, chave privada. Este plugin só faz derivação pública a
partir de xPub. Não há segredo no caminho. Mesmo na época do `mdanter/ecc`, a exploração aqui era
inexistente.

Duas ressalvas de precisão, medidas em 2026-08-17, que não mudam a conclusão:

- O `ConstantTimeMath` é o que o **pacote** traz; o adaptador que carrega no nosso caminho de
  derivação é o `GmpMath` (visto em `get_included_files()` rodando os 60 vetores). Coerente com o
  parágrafo acima — sem escalar secreto, não há o que proteger em tempo constante —, mas não se deve
  citar `ConstantTimeMath` como se fosse o caminho executado.
- O `paragonie/ecc` **mantém o namespace do original**: suas classes são `Mdanter\Ecc\*`. Como o
  WordPress tem um único espaço de classes por processo, outro plugin que embarque o `mdanter/ecc`
  original e registre o autoloader antes do nosso pode servir `Mdanter\Ecc\*` para o **nosso**
  `bitwasp/bitcoin` — e o `composer audit` nunca veria, porque audita o lock, não o processo. O
  desfecho mais provável é fatal ruidoso (o `bitwasp/bitcoin v1.1.0` chama API que só existe no
  `paragonie/ecc ^2.1`, exatamente o mismatch de E2); o caso silencioso exigiria um `mdanter/ecc`
  de API próxima o bastante. Prefixar namespaces do vendor é o que fecharia isso de vez — decisão
  deliberadamente **não** tomada nesta frente, registrada aqui para não se perder.

### E5 — O upstream instala e produz resultados idênticos

`bitwasp/bitcoin ^1.1` resolve limpo com o **mesmo** `config.platform.php = 7.4` que o plugin usava
à época desta medição (hoje o pin é `8.1` — ver E7.2), trazendo exatamente as mesmas dependências (`paragonie/ecc v2.5.0`, `bitwasp/bech32 v0.0.1`,
`bitwasp/buffertools v0.5.7`).

Os **12 vetores** de `tests/vectors/bitcoin_addresses.json` (60 endereços, cobrindo
xpub/ypub/zpub/tpub/upub/vpub) rodados contra o upstream:

```
endereços conferidos: 60   |   divergências: NENHUMA
```

E a suíte completa do plugin, numa cópia isolada com o upstream instalado e **sem alterar uma linha
do plugin**:

```
Tests: 355, Assertions: 743, Skipped: 4   — OK
```

### E6 — Deprecations no caminho real: 7 (fork) → 12 (upstream), no PHP 8.3

Medido executando o caminho de produção — os 60 vetores de `tests/vectors/bitcoin_addresses.json`
via `BitcoinAddressService::generate_address_from_xPub()`, mais `validate_extended_pubkey()` e
`validate_bitcoin_address()` — com `error_reporting(E_ALL)` e um coletor em `set_error_handler`:

| Diagnóstico (PHP 8.3) | Fork | Upstream |
|---|---|---|
| `Return type ... or #[\ReturnTypeWillChange]` — `bitcoin/src/Script/Opcodes.php`, `bitcoin/src/Script/Parser/Parser.php` | 7 | 7 |
| `Use of "parent" in callables` — `buffertools/src/Buffertools/CachingTypeFactory.php` (3 sítios) | 0 | 5 |
| **total** | **7** | **12** |

A base nunca foi zero: as 7 comuns estão no `src/` do próprio `bitcoin` e independem do fork. O
preço da troca é **+5 ocorrências**, correspondentes ao único commit de código do fork de
buffertools (`90e244c`, `CachingTypeFactory.php`, 28 linhas) — ver E8. Todas só aparecem com
`WP_DEBUG` ligado.

**Leitura estratégica:** o fork foi criado para resolver compatibilidade com PHP 8.x e resolve 5 de
12 no caminho real. Ele não é solução para o problema de longevidade — ver "Horizonte PHP 9".

### E7 — O bloqueio real do upstream, e por que ele não importa aqui

> **Estado final (executado em 2026-08-17, ver [`LEAN-VENDOR-TREE.md`](LEAN-VENDOR-TREE.md)):** o
> pin foi para `8.1` (o piso real do plugin) e o `murmurhash` saiu da árvore via
> `"replace": {"lastguest/murmurhash": "2.0.0"}`. O E7/E7.1/E7.2 abaixo mantêm o histórico de por
> que o pin de 7.4 existiu e o que ele custava — não foram reescritos como se ele nunca tivesse
> existido, porque é esse histórico que impede alguém de reintroduzi-lo.

`bitwasp/bitcoin` v1.1.0 fixa `lastguest/murmurhash: v2.0.0` (versão exata), que declara
`php: ^7`. Numa resolução honesta em PHP 8 isso falha:

```
lastguest/murmurhash 2.0.0 requires php ^7 -> your php version (8.3.33) does not satisfy that
```

O plugin contornava com `config.platform.php = 7.4`, que fazia o composer resolver como se fosse
PHP 7.4. **Esse contorno já existia antes da troca e era o que sustentava o próprio fork** — ele
também instalava `lastguest/murmurhash 2.0.0`.

Risco prático: nenhum. Dentro da lib, `murmurhash` só é alcançado por `Bloom/BloomFilter.php:250` e
`Crypto/Hash.php::murmur3()`; o plugin não referencia nenhum dos dois. O pacote era instalado e nunca
executado — e hoje não é nem instalado.

#### E7.1 — O bloqueio é o pin exato do upstream, não o pacote (medido 2026-08-17)

Três medições que o E7 original não tinha, e que mudam o que fazer a respeito:

| Fato | Medida |
|---|---|
| O constraint do `bitwasp/bitcoin` é **versão exata** | `require` do vendor: `"lastguest/murmurhash": "v2.0.0"` — logo, subir isso pelo nosso `require` dá conflito, não resolve |
| O pacote **já se consertou** | `2.1.1` declara `php: ^7||^8.0` (a `2.1.0` não declara `php` nenhum); só a `2.0.0` instalada declara `php: ^7` |
| É o **único** bloqueio | `composer update --dry-run` com `platform.php = 8.1` produz um só `Problem 1`, e é esse pacote. `composer why-not php 8.1` devolve uma única linha |

Ou seja: não há nada errado com a dependência nem com a nossa árvore — o que trava é o
`bitwasp/bitcoin` ter pinado a versão exata. Isso reposiciona o caminho 1 do "Horizonte PHP 9":
o PR upstream é literalmente **uma linha** (`v2.0.0` → `^2.0`), sem mudança de código, e o dado
acima é a justificativa pronta.

**Ainda não enviado, e continua sendo o desfecho melhor** (reconferido em 2026-08-17: o
`bitwasp/bitcoin` segue em `v1.1.0`, lançada em 2026-02-25, com o pin exato intacto). Ele é
estritamente superior ao que fizemos: dispensa o `replace`, dispensa o `VendorReplaceGuardTest` e
mantém o `murmur3()` funcional, sem abrir mão do pin como declaração do piso. Quando entrar, tire o
`replace` e o teste de guarda; o pin em `8.1` fica.

#### E7.2 — O gap real do pin, e como ele passou a ser auditado

O murmurhash é inofensivo; o **pin** não é inteiramente. Ele não diz "ignore o php desse pacote",
diz "resolva a árvore **inteira** como se fosse 7.4" — hoje e para sempre. Isso tem duas
consequências, e a primeira só foi medida em 2026-08-17:

**(i) O pin segurava toda a árvore em versões da era 7.4.** Não era custo hipotético nem cosmético —
era o que ia para as lojas até 2026-08-17:

| pacote | com o pin de 7.4 (enviado até então) | hoje, com o pin em 8.1 |
|---|---|---|
| `paragonie/sodium_compat` | **v1.24.0** — declara suporte de PHP 5.2.4 a 8 | **v2.5.0** — `php: ^8.1`, exatamente o piso do plugin |
| `paragonie/random_compat` | **instalado** — polyfill de `random_bytes` para PHP 5 | **não existe** |
| `genkgo/php-asn1` | v2.5.0 | v2.9.0 |
| `endroid/qr-code` | 4.6.1 | 4.8.5 |

O `paragonie/ecc` aceita `sodium_compat ^1|^2`; a escolha da v1 era puro artefato do pin. Medido no
caminho de produção (60 vetores + validadores, via `get_included_files()`): o `random_compat`
carregava **0 arquivos** — polyfill de PHP 5 enviado a toda loja que nunca executava, presente só
porque o `sodium_compat` v1 o exigia. O `sodium_compat` carrega **10 arquivos** na v1 e **8** na v2,
então esse está no caminho vivo (diferente do murmurhash, que também era 0).

**Sem inflar:** não há advisory contra a `v1.24.0` — `composer audit` estava limpo no lock antigo e
continua limpo no novo. Foi defasagem de manutenção, não vulnerabilidade.

Duas medições complementares sobre o `sodium_compat`, porque a intuição erra a direção aqui. Ele
declara `"autoload": {"files": ["autoload.php"]}`, logo é carregado por `require` em **todo request**
que carrega o autoloader do plugin — 505 KB medidos e poucos milissegundos, funcionalmente inúteis
num host com `ext-sodium` nativa. E dentro do container `wordpress`, os arquivos de `sodium_compat`
carregados num request normal são **os nossos**, não os do core: o WP também empacota o dele
(`wp-includes/sodium_compat/`), mas carrega sob demanda, então é a nossa cópia que define as classes
globais `ParagonIE_Sodium_*` (sem namespace) para o site. Somos o lado que sombreia — o que também é
a razão de um polyfill nunca poder ser prefixado.

> **Correção de medição (2026-08-17, na execução do plano).** Este parágrafo dizia que o
> `sodium_compat` era a **única** entrada de `vendor/composer/autoload_files.php`. Não é, e nunca
> foi: num install `--no-dev` são **três**, e as outras duas são `bitwasp/bech32/src/bech32.php` e
> `bitwasp/bitcoin/src/Script/functions.php`. Medido nas duas árvores, a antiga e a nova — logo é
> erro de leitura do `grep` original (que filtrava por `sodium_compat`), não efeito da troca. O que
> continua verdade é o que sustenta o argumento: o `sodium_compat` é carregado eager em todo request.
> A troca de major **não** reduziu esse custo — 505 KB / 10 arquivos na v1 contra 518 KB / 8 arquivos
> na v2, tempo dentro do ruído nas duas. O que caiu foi o disco: 1.8 MB → 1.1 MB.

Isso foi destravado em 2026-08-17 — pin no piso real (`8.1`) e `murmurhash` fora da árvore via
`replace` — como frente própria, medida e registrada em
[`docs/LEAN-VENDOR-TREE.md`](LEAN-VENDOR-TREE.md), com a própria rodada de verificação incluindo o
install limpo `--no-dev` que é o caminho do release.

**(ii) Uma dependência futura entraria calada.** Direta ou transitiva, incompatível com o piso real
de PHP do plugin: é exatamente a checagem que o Composer faria de graça, desligada por nós.

Fechado por [`scripts/check-platform-pin.sh`](../scripts/check-platform-pin.sh), que roda
`composer why-not php <piso>` — comando que **ignora o pin** e lista *todos* os pacotes cujo
requisito de PHP exclui o piso. O piso é lido do header do plugin (`Requires PHP:`), então subir o
piso move a checagem junto.

Desde 2026-08-17 o script distingue **dois regimes**, porque o mesmo pin significa coisas opostas
conforme a relação com o piso (comparação por versão, não por string — `8.10` não é menor que `8.9`):

| relação | regime | o que o script faz |
|---|---|---|
| `platform.php` **>=** piso | **declaração** — o pin não esconde nada, só torna a resolução reproduzível em vez de depender do PHP do container de build | qualquer pacote bloqueando o piso **reprova**: não é workaround, é incompatibilidade real em código que vai para as lojas |
| `platform.php` **<** piso | **supressão** — resolve a árvore inteira num PHP mais velho que o piso | audita contra a `ALLOWED_OFFENDERS`, e avisa quando um allowlistado deixa de aparecer |

Hoje o regime é **declaração** e a `ALLOWED_OFFENDERS` está **vazia** — o estado saudável. Baixar o
pin para fazer o script passar, ou alargar a allowlist, nunca é a correção: cada entrada precisaria
de razão aqui **e** da prova de que o plugin nunca executa aquele código. No regime de supressão o
script também reporta o caso inverso, que é o que normalmente se esquece: quando o ofensor conhecido
deixa de bloquear o piso, ele avisa que o pin virou peso morto. Está ligado na fase
*Platform pin audit* do `release.sh`, logo depois do PHPUnit — ver
[`docs/RELEASE.md`](RELEASE.md) → "Auditoria do pin de plataforma".

Alternativa antes rejeitada e depois **adotada**: `"replace": {"lastguest/murmurhash": "2.0.0"}` no
nosso `composer.json`. A objeção continua correta e não foi anulada — o `replace` troca "instalado e
nunca executado" por "não existe", então `Hash::murmur3()` sai de no-op para `Class not found` se
algum consumidor chegar lá. O que mudou foi o outro prato da balança: o "ganho cosmético no
`composer.json`" não era cosmético, era a árvore inteira presa na era 7.4 (i). Adotado **com**
mitigação: o `VendorReplaceGuardTest` reprova em desenvolvimento se código do plugin referenciar
`lastguest\Murmur`, `murmur3` ou `BloomFilter`, com granularidade de **método** — as outras oito
estáticas de `Crypto\Hash` (`sha256`, `hmac`, `pbkdf2`, …) continuam intactas e usáveis. A metade da
objeção que sobrevive é a do consumidor terceiro alcançando `BloomFilter`, e ela some sozinha quando
o PR de E7.1 entrar.

### E8 — O segundo fork sai junto, e é o único que perde algo real

`lucas-rosa95/buffertools-php` **não está no `require` do plugin**. Ele entra na árvore só porque o
fork de `bitcoin` o exige:

```
lucas-rosa95/bitcoin (dev-master) requer lucas-rosa95/buffertools-php: dev-master
```

O `composer.json` do plugin o menciona apenas no bloco `repositories`, para que o composer saiba
onde encontrá-lo. Consequência: ao trocar para `bitwasp/bitcoin ^1.1` — que exige
`bitwasp/buffertools ^0.5.0` — **o fork desaparece sozinho**. Verificado na build isolada:

```
buffertools instalado: bitwasp/buffertools v0.5.7
vendor/lucas-rosa95 presente? NAO
```

Remover os dois entries de `repositories` apenas apaga a referência que já não é usada.

**A ressalva honesta:** este é o único dos dois forks com um commit de código próprio — `90e244c`,
`CachingTypeFactory.php`, 28 linhas, corrigindo `Use of "parent" in callables`. Mas ele **não** é
"upstream + essa correção". O `Bit-Wasp/buffertools-php` tem duas linhas: `master` (último commit
2020-01-17), de onde o fork foi tirado, e o branch de release `0.5`, onde vive a tag `v0.5.7` — a
versão que o composer resolve. As duas divergiram em `debe860` (2018-11-10) e nenhuma é ancestral
da outra. Resultado: o `src/` do fork difere do `v0.5.7` em 8 arquivos, e em 7 deles é o fork que
está atrás:

| Presente no `v0.5.7`, ausente no fork | Commit |
|---|---|
| `Uint32: should use pack, much faster` | `03f960b` |
| `VarInt: performance speedup, use pack instead of our int objects` (+ caminho 64-bit e guarda de overflow) | `99e8edc` |
| `ByteString: don't count how many base conversions there were` | `19442af` |
| `ByteString: should accept BufferInterface, not specifically Buffer` | `40febbb` |

O `ByteString` do fork ainda faz `gmp_init()`/`gmp_strval()` em todo read/write; o do `v0.5.7` opera
direto sobre binário. Saldo da troca: ganham-se essas quatro melhorias da linha de release e
perde-se apenas o fix do `CachingTypeFactory` — as +5 deprecations de E6.

Manter só esse fork também não é opção barata: ele foi **renomeado** para
`lucas-rosa95/buffertools-php`, então já não satisfaz o `bitwasp/buffertools` que o upstream exige —
seria preciso ginástica de `replace`/`provide` mais o `repositories` e o `minimum-stability: dev` de
volta, para corrigir uma deprecation e abrir mão de quatro melhorias.

Se essa deprecation virar fatal (PHP 9), os caminhos estão em "Horizonte PHP 9" — não é resolver com
fork.

---

## Decisão

**Voltar aos pacotes oficiais — os dois.** O fork de `bitcoin` é uma cópia estritamente pior: zero
ganho de código, um fatal latente, dependência de repositórios pessoais na cadeia de suprimentos, e
`dev-master` sem versionamento. O de `buffertools` sai transitivamente (E8), custando as +5
ocorrências que o fix `CachingTypeFactory` suprimia — e, em troca, ganhando as quatro melhorias da
linha de release `v0.5.7` que o fork não tinha.

Preço aceito conscientemente: **+5 ocorrências** de deprecation no PHP 8.3
(`parent` in callables, visíveis só com `WP_DEBUG`). Não vale montar infraestrutura de patch para
corrigir 5 de 12 — ver "Fora de escopo".

---

## A mudança

### Arquivo único de produção: `src/trunk/composer.json`

| O quê | De | Para |
|---|---|---|
| `require` | `"lucas-rosa95/bitcoin": "dev-master"` | `"bitwasp/bitcoin": "^1.1"` |
| `repositories` | dois entries VCS (`lucas-rosa95/bitcoin-php`, `lucas-rosa95/buffertools-php`) | **remover o bloco inteiro** |
| `minimum-stability` / `prefer-stable` | `"dev"` / `true` | **remover ambos** — só existiam por causa do `dev-master` |
| `config.audit.ignore` | dois IDs `PKSA-*` | **remover** (E4: são resíduo; removê-los devolve sentido ao `composer audit`) |
| `config.platform.php` | `"7.4"` | **manter** (E7) — a razão fica documentada na seção *Composer dependencies* do `CLAUDE.md` (JSON não tem comentário, e não deve ganhar um `_comment` só para isso) |

Depois: `composer update bitwasp/bitcoin --with-dependencies` dentro do container `release`, para
regravar `composer.lock`.

**Nenhuma linha de código PHP do plugin muda.** Os namespaces são os mesmos (`BitWasp\...`); o fork
nunca renomeou nada. Comprovado por E5.

### Documentação

| Arquivo | O quê |
|---|---|
| `CLAUDE.md` | A seção **"Composer dependencies (important)"** descreve os dois forks e o acesso a repos privados como requisito de `composer install`. Substituir por: dependências oficiais, sem repos VCS, e uma nota curta explicando por que `config.platform.php = 7.4` continua ali (pin de `lastguest/murmurhash`, pacote nunca executado). |
| `docs/RELEASE.md` | Verificar se menciona os forks / acesso aos repos como pré-requisito; se sim, atualizar. |
| `src/trunk/CHANGELOG.md` | Em `## Unreleased`, `### Changed`: troca para os pacotes oficiais mantidos, com menção a que os advisories de canal lateral (CVE-2024-33851) não se aplicam à árvore atual. |

### Sem mudança de código, sem tradução

Nenhuma string nova. Nenhum arquivo em `includes/`. A frente é de dependência e documentação.

---

## Verificação

Rodar da raiz do repo. Os itens 1–3 são o núcleo; 4–6 fecham o release.

> **Compose:** a forma `docker compose` (plugin v2) é a convenção do repo. Num host que só tem o
> binário standalone — **é o caso desta máquina** —, troque por `docker-compose`. Os scripts
> detectam as duas formas sozinhos; só os comandos colados à mão precisam da troca.
>
> **Vendor:** conferir que `src/trunk/vendor/` corresponde ao `composer.lock` **antes** de rodar
> qualquer item daqui (`ls src/trunk/vendor/bitwasp` deve mostrar `bech32 bitcoin buffertools`).
> Uma árvore instalada antes desta frente ainda carrega os forks e faria a verificação medir o
> código aposentado. Se preciso: `docker-compose run --rm release composer install`.

| # | Comando | Esperado |
|---|---|---|
| 1 | `docker compose run --rm release ./vendor/bin/phpunit` | **355 testes, 743 asserções, 4 skipped, 0 falhas** — idêntico ao baseline com o fork. Depois da contingência de deprecations: **363/755/4**. |
| 2 | `docker compose run --rm release composer audit --locked` | `No security vulnerability advisories found` — **agora sem lista de ignore**, então o resultado passa a ter significado. |
| 3 | Vetores contra o vendor novo: derivar os 12 xpubs de `tests/vectors/bitcoin_addresses.json` e comparar os 60 endereços | Zero divergências. Já coberto por `BitcoinAddressVectorsTest` na suíte do item 1 — confirmar que ele roda e passa. |
| 4 | `./scripts/smoke-minimal-host.sh` | Todos os checks passando. |
| 5 | `docker run --rm -v $(pwd)/src/trunk:/plugin -w /plugin php:8.3-cli php ./vendor/bin/phpunit --filter OnchainWithoutGmpTest` | 10 testes, 17 asserções, 1 skipped, OK (host sem GMP). O 1 skip é o teste do caso *com* GMP, que se auto-pula quando a extensão está ausente. |
| 6 | `docker compose exec -T wordpress wp --allow-root plugin check paycrypto-me-for-woocommerce --format=csv` | Nenhum `ERROR` em código enviado. Exige o `plugin-check` instalado no volume do WP (`wp plugin install plugin-check --activate`); nada no repo o provisiona. |
| 7 | `./scripts/check-platform-pin.sh` | Só `lastguest/murmurhash` listado, exit 0 — o pin de plataforma segue justificado (E7.2). Já roda dentro do `release.sh`. |

Checagem extra específica desta frente — o fatal de E2 deve desaparecer:

```bash
docker compose run --rm release php -r '
require "/plugin/vendor/autoload.php";
$c = new ReflectionClass("BitWasp\\Bitcoin\\Crypto\\EcAdapter\\Impl\\PhpEcc\\Signature\\Signature");
echo $c->isInstantiable() ? "OK: instanciável\n" : "FALHOU: ainda incompleta\n";'
```

E conferir que o `vendor/` publicado não contém mais os forks:

```bash
ls src/trunk/vendor/bitwasp/     # bech32  bitcoin  buffertools
ls src/trunk/vendor/lucas-rosa95 # não deve existir
```

---

## Fora de escopo (decisões conscientes)

**Não adicionar camada de patch** (`cweagans/composer-patches`) para a deprecation de
`CachingTypeFactory`. Corrigiria 5 de 12 no caminho real (PHP 8.3), ao custo de mais uma peça móvel
num fluxo de release já validado em produção.

> **Atualização (2026-08-16):** essas deprecations do `CachingTypeFactory`, além de ruído, **quebravam
> o redirect do save das settings On-Chain** ("headers already sent") em host com `display_errors`
> ligado — a severidade estava subestimada aqui. Foram mitigadas por supressão de runtime escopada a
> `E_DEPRECATED` no boundary do `BitcoinAddressService` (`suppress_vendor_deprecations()`). **Não** é
> patch de vendor — a decisão acima segue valendo — e o fatal do PHP 9 continua não mascarado. Ver
> [docs/CRYPTO-DEPRECATION-CONTINGENCY.md](CRYPTO-DEPRECATION-CONTINGENCY.md).

**Não reescrever a criptografia agora.** Ver abaixo.

---

## Horizonte PHP 9 (decisão futura, não agora)

As deprecations medidas em E6 — `parent` in callables e os tentative return types
(`Return type ... #[\ReturnTypeWillChange]`, presentes nos dois vendors, dentro do próprio
`bitwasp/bitcoin`) — tendem a virar **erro fatal no PHP 9**, no fork **e** no upstream. Ou seja:
nenhuma das duas opções atuais sobrevive ao PHP 9 sem intervenção. Voltar ao upstream **não**
resolve isso; apenas coloca o plugin de volta numa base mantida, onde a correção pode vir de fora.

Três caminhos, para avaliar quando o PHP 9 tiver data:

1. **Contribuir upstream.** Duas mudanças pequenas e de alto retorno: soltar o pin
   `lastguest/murmurhash: v2.0.0` para `^2.0` (elimina a necessidade do `config.platform` override)
   e adicionar tipos nulláveis explícitos. O upstream aceitou commits em 2024 e 2026 — não está
   morto. **O primeiro está pronto para enviar:** é uma linha em `composer.json`, sem mudança de
   código, e a justificativa está medida em E7.1 (a `2.1.1` já declara `php: ^7||^8.0`; o pin exato
   é o único bloqueio de PHP 8 na árvore). **Segue não enviado** (reconferido em 2026-08-17). Quando
   entrar, o desfecho já não é "remover o pin": o pin agora *declara* o piso e fica; o que sai é o
   `"replace"` e o `VendorReplaceGuardTest` — ver E7.2 e
   [`LEAN-VENDOR-TREE.md`](LEAN-VENDOR-TREE.md).
2. **Substituir a fatia estreita.** O plugin usa **9 classes**: `AddressCreator`, `SegwitAddress`,
   `HierarchicalKeyFactory`, `NetworkFactory`, `NetworkInterface`, `ScriptFactory`,
   `WitnessProgram`, `Base58`, `Buffer`. Implementar isso sobre `phpseclib/phpseclib` v3 —
   **já verificado nesta investigação**: `secp256k1` disponível e multiplicação escalar + soma de
   ponto em **219 ms sem `gmp` e sem `bcmath`** (engine `PHP64`). Isso mataria o requisito de GMP
   **e** o problema de deprecations de uma vez. Os 12 vetores existentes tornam a equivalência
   verificável byte a byte.
3. **Não fazer nada** até o PHP 9 ser realidade em hospedagens WordPress.

Recomendação: **(1) agora que é barato**, e reavaliar (2) quando houver data do PHP 9. O caminho
(2) é escrever código adjacente a criptografia no fluxo do dinheiro — só se justifica com a rede de
vetores em pé e uma razão concreta.

---

## Ordem, base e relação com o outro plano

Esta frente é **independente** de
[`docs/SCHEMA-UPGRADE-AND-STATIC-RECORDS.md`](SCHEMA-UPGRADE-AND-STATIC-RECORDS.md): não toca em
schema, nem em `DbInstaller`, nem no fluxo de pagamento. Pode ir antes, depois ou em paralelo.

**Base:** branch `fix/honest-failure-reporting` (pendente de validação manual), ou `main` depois do
merge. Não há dependência técnica com o outro plano.

Sugestão: commit próprio, isolado. O diff versionado é pequeno — `vendor/` é gitignored, então só
`composer.json`, `composer.lock` e a documentação entram no commit; o `vendor/` publicado é gerado
pelo `release.sh` no container. Ainda assim vale isolar: é uma troca de cadeia de dependência
criptográfica, e uma revisão futura deve poder olhá-la sem ruído de outra mudança.
