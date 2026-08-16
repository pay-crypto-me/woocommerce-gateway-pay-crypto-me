# Auditoria da frente "aposentar os forks de cripto" — itens a corrigir antes de fechar

> **Status: auditoria concluída em 2026-08-15.** Revisão independente da mudança descrita em
> [`docs/CRYPTO-DEPENDENCIES.md`](CRYPTO-DEPENDENCIES.md), feita sobre o working tree da branch
> `chore/retire-crypto-forks` (mudança ainda **não commitada**, sobre o commit `9adce0a`).
>
> **Veredito: a troca de dependência está correta e é segura — nenhum bug funcional encontrado.**
> Toda a verificação declarada no plano foi reproduzida e passou. O que segue são **5 correções de
> registro/documentação** (uma delas em texto público que vai ao WordPress.org) e **1 risco de
> integração com a `main`**. Nada aqui exige refazer a troca de dependência.
>
> Documento autossuficiente: quem executar não precisa da conversa que o originou. Prosa em
> português; identificadores, caminhos e nomes de teste em inglês, como no resto do repo.

---

## Como ler este documento

- **Parte 1** — o que já foi verificado e passou. **Não refaça**, a não ser que altere código.
- **Parte 2** — os achados, cada um com evidência medida, comando de reprodução e o texto de
  correção pronto para colar.
- **Parte 3** — o risco de merge com a `main`, com passo a passo.
- **Parte 4** — o que **não** é problema (para não "consertar" o que está certo de propósito).
- **Apêndice** — o script de medição usado, para reproduzir ou estender.

Ambiente da auditoria: containers do próprio repo (`docker compose run --rm release`, imagem
`paycrypto-me-for-woocommerce:local`, PHP 8.3.32). O host não tem PHP nem `composer` instalados, e
**não tem o binário `docker-compose` (com hífen) — só `docker compose`**.

---

## Parte 1 — Verificação independente (tudo passou)

| Verificação | Resultado medido |
|---|---|
| `composer validate` | válido; `composer.lock` em sincronia com `composer.json` |
| Suíte completa | **334 testes, 709 asserções, 3 skipped — OK** |
| `composer audit --locked` | **`No security vulnerability advisories found`, sem lista de ignore** |
| 60 vetores de endereço | **60/60 idênticos** — rodados **nos dois** vendors (fork e upstream), zero divergência em ambos |
| E1 (fork não tem correção própria) | confirmado: o único arquivo diferente em `src/` é `Signature.php`, e a diferença é o upstream **adicionando** `getSignatureType(): string` |
| E2 (fatal latente) | confirmado nos dois sentidos: no fork, carregar a classe dá `PHP Fatal error: ... contains 1 abstract method`; no upstream, `ReflectionClass::isInstantiable() === true` |
| Instalação limpa só a partir do lock | `composer install --no-dev --optimize-autoloader --prefer-dist` em diretório vazio, **sem `auth.json` e com `COMPOSER_AUTH` vazio**: 13 pacotes, só Packagist, e o `vendor/bitwasp` resultante é **idêntico byte a byte** ao do repo |
| Vendor de produção (`--no-dev`) | deriva os 60 endereços corretamente |
| `scripts/smoke-minimal-host.sh` | todos os checks verdes |
| `OnchainWithoutGmpTest` (host sem GMP) | 10 testes, 1 skipped, OK |
| Plugin Check | `ERROR` **apenas** em `tests/`, `phpunit.xml.dist` e `.phpunit.result.cache` — nada em código enviado |
| Resíduo dos forks | nenhum: nem em arquivos versionados, nem em `src/trunk/vendor/`, nem no autoload gerado |

Comandos usados (rodar da raiz do repo):

```bash
docker compose run --rm release composer validate --no-check-publish
docker compose run --rm release ./vendor/bin/phpunit
docker compose run --rm release composer audit --locked
./scripts/smoke-minimal-host.sh
docker run --rm -v $(pwd)/src/trunk:/plugin -w /plugin php:8.3-cli php ./vendor/bin/phpunit --filter OnchainWithoutGmpTest
docker compose exec -T wordpress wp --allow-root plugin check paycrypto-me-for-woocommerce --format=csv \
  | awk '/^FILE: /{f=substr($0,7)} /,ERROR,/{print f}' | sort | uniq -c
```

Instalação limpa a partir do lock (prova de que não é preciso GitHub privado nem token):

```bash
docker compose run --rm -e COMPOSER_AUTH= release bash -lc '
  mkdir -p /tmp/fresh && cd /tmp/fresh
  cp /plugin/composer.json /plugin/composer.lock .
  mkdir -p includes exceptions
  composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction
  diff -rq /plugin/vendor/bitwasp /tmp/fresh/vendor/bitwasp && echo IDENTICOS'
```

---

## Parte 2 — Achados

### A1 — `CHANGELOG.md` sugere uma correção de segurança que não aconteceu

**Severidade: alta** (é texto público — vai para `readme.txt` e para a página do plugin no
WordPress.org no próximo release).

**Onde:** `src/trunk/CHANGELOG.md`, seção `## Unreleased` → `### Changed` (linhas ~37–43).

**Texto atual:**

> Switched the Bitcoin cryptography libraries from personal forks to the official, maintained
> `bitwasp/bitcoin` packages. No functional change: the same addresses are derived, and the full
> test suite plus all 60 address vectors pass unchanged. **The side-channel advisory CVE-2024-33851
> no longer applies to the dependency tree — it was against `mdanter/ecc`, which the plugin no
> longer uses.**

**O problema:** `mdanter/ecc` **nunca esteve** na árvore de dependências do plugin. O
`composer.lock` publicado na 0.1.0 tem **zero** ocorrências dele — já resolvia `paragonie/ecc
v2.5.0`. Dizer "no longer applies" e "no longer uses" faz o leitor entender que a 0.1.0 esteve
exposta ao CVE-2024-33851 e que esta versão corrige isso. Não houve exposição e não houve correção.

O E4 do plano está certo (as duas entradas de `config.audit.ignore` eram resíduo morto); é só a
redação do changelog que inverte o sentido.

**Reproduzir:**

```bash
git show 9adce0a:src/trunk/composer.lock | grep -c mdanter   # => 0
git show 9adce0a:src/trunk/composer.lock | grep -n '"name": ".*ecc"'  # => paragonie/ecc
```

**Correção proposta** — substituir o bullet inteiro por:

```markdown
### Changed

 - Switched the Bitcoin cryptography libraries from personal forks to the official
   `bitwasp/bitcoin` packages, resolved from Packagist. No functional change: the same addresses
   are derived, and the full test suite plus all 60 address vectors pass unchanged. The two
   side-channel advisories that `composer.json` used to suppress were filed against `mdanter/ecc`,
   a package this plugin has never shipped, so the suppression list is gone and `composer audit`
   now runs clean without it.
```

Ao cortar a versão, o mesmo conteúdo precisa ir para `src/trunk/readme.txt` (`== Changelog ==`),
conforme o checklist de [`docs/RELEASE.md`](RELEASE.md).

---

### A2 — E6 mede o caminho errado: 7 → 12 deprecations, não 0 → 1

**Severidade: média** (registro permanente que sustenta a decisão; o número atual subestima o custo
por 5×).

**Onde:** `docs/CRYPTO-DEPENDENCIES.md`, seção `### E6` (linhas ~123–138).

**O problema:** a tabela do E6 (`Fork 0 | Upstream 1` no PHP 8.3) foi obtida **carregando classes**,
não executando o plugin. Medindo o caminho real de produção, com `error_reporting(E_ALL)` e um
`set_error_handler` coletor, nos dois vendors:

| Diagnóstico (PHP 8.3, caminho real) | Fork | Upstream |
|---|---|---|
| `Return type ... should either be compatible with ... or #[\ReturnTypeWillChange]` — `bitcoin/src/Script/Opcodes.php` (2) e `bitcoin/src/Script/Parser/Parser.php` (5) | 7 | 7 |
| `Use of "parent" in callables` — `buffertools/src/Buffertools/CachingTypeFactory.php`, 3 sítios (linhas 36, 88, 376) | 0 | 5 |
| **total** | **7** | **12** |

Duas leituras mudam:

1. A base **nunca foi zero** — um site com `WP_DEBUG` já registrava 7 diagnósticos por derivação
   antes desta mudança, vindos do `src/` do próprio `bitcoin` e presentes nos dois vendors.
2. O preço da troca é **+5 ocorrências (3 sítios)**, não +1.

Isso **não** muda a decisão (continua ruído sob `WP_DEBUG`, e as 7 comuns independem do fork), mas
muda o número que o documento registra.

**Reproduzir:** ver o script no [Apêndice](#apêndice--script-de-medição-de-deprecations), rodado com
o vendor atual e com o vendor do estado anterior.

**Correção proposta** — substituir o bloco E6 inteiro por:

```markdown
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
```

**Ajuste em cascata (mesmo arquivo, seção "Horizonte PHP 9", linhas ~283–286):** o texto atual cita
`parent` in callables e `Implicitly marking parameter as nullable` como as deprecations medidas. A
medição no caminho real mostra que o segundo grupo relevante é o de **tentative return types**
(`Return type ... #[\ReturnTypeWillChange]`), presente **nos dois** vendors e dentro do próprio
`bitwasp/bitcoin`. Trocar a menção mantém a conclusão da seção intacta (nenhuma das duas opções
sobrevive ao PHP 9 sem intervenção) e a torna verificável.

**Se quiser manter a coluna PHP 8.4:** ela também foi medida no caminho sintético. Ou re-meça com o
script do apêndice numa imagem PHP 8.4 com `gmp` (não existe pronta: `php:8.4-cli` + `docker-php-ext-install gmp`),
ou remova a coluna e diga explicitamente que o 8.4 não foi medido no caminho real. Não deixe o
número antigo sem essa ressalva.

---

### A3 — E8 descreve mal a troca do buffertools (e subestima o ganho)

**Severidade: média** (do jeito que está, um mantenedor futuro pode concluir que vale ressuscitar o
fork).

**Onde:** `docs/CRYPTO-DEPENDENCIES.md`, seção `### E8`, parágrafos "A ressalva honesta" e o
seguinte (linhas ~177–191).

**Texto atual:** afirma que o fork de buffertools "tem um commit de código legítimo" e que "aqui a
troca é de um fork que tem uma correção por um upstream oficial que não tem".

**O problema:** o fork **não** é "upstream + uma correção". O repositório
`Bit-Wasp/buffertools-php` tem **duas linhas**:

- `master` — último commit `43c8bc2` (2020-01-17); foi daqui que o fork foi tirado;
- branch `0.5` — onde vive a tag **`v0.5.7`**, que é a versão que o composer resolve para
  `^0.5.0`.

As duas divergiram em `debe860` (2018-11-10) e **nenhuma é ancestral da outra**. Por isso o `src/`
do fork difere do `v0.5.7` em **8 arquivos** — e em 7 deles é o **fork** que está atrás:

| Presente na linha de release (`v0.5.7`), ausente no fork | Commit |
|---|---|
| `Uint32: should use pack, much faster` | `03f960b` |
| `VarInt: performance speedup, use pack instead of our int objects` (+ caminho 64-bit e guarda de overflow) | `99e8edc` |
| `ByteString: don't count how many base conversions there were` | `19442af` |
| `ByteString: should accept BufferInterface, not specifically Buffer` | `40febbb` |

Detalhe que vale registrar: o `ByteString` do fork faz `gmp_init()`/`gmp_strval()` em todo
read/write; o do `v0.5.7` opera direto sobre binário, sem GMP — direção coerente com a rota
"funciona sem GMP" documentada no `CLAUDE.md` (não muda o comportamento medido: `OnchainWithoutGmpTest`
passa nos dois, mas reduz superfície de dependência da extensão).

**Saldo real da troca:** ganham-se as quatro melhorias da linha de release e perde-se **apenas** o
fix do `CachingTypeFactory` (`90e244c`, 28 linhas) — as +5 deprecations do A2.

**Reproduzir:**

```bash
docker compose run --rm release bash -lc '
  cd /tmp && git clone -q https://github.com/Bit-Wasp/buffertools-php.git bt && cd bt
  git merge-base --is-ancestor v0.5.7 master && echo "master inclui v0.5.7" || echo "linhas divergentes"
  git log -1 --format="merge-base: %h %ad %s" --date=short $(git merge-base v0.5.7 master)
  git log --oneline master..v0.5.7
  git branch -r --contains v0.5.7'
```

E o diff dos dois vendors (o estado anterior é o commit `9adce0a`):

```bash
docker compose run --rm release bash -lc '
  mkdir -p /tmp/old && cd /tmp/old && mkdir -p includes exceptions
  git -C /plugin show 9adce0a:src/trunk/composer.json > composer.json 2>/dev/null || true
  # se /plugin não for um repo git, copie composer.json/lock do commit 9adce0a a partir do host
  composer install --no-dev --prefer-dist --no-interaction -q
  diff -rq /tmp/old/vendor/lucas-rosa95/buffertools-php/src /plugin/vendor/bitwasp/buffertools/src'
```

> Nota: `/plugin` é apenas `src/trunk` montado, não o repositório git. O caminho prático é gerar os
> arquivos no host antes:
> `git show 9adce0a:src/trunk/composer.json > /tmp/old/composer.json` (idem `composer.lock`) e montar
> esse diretório no container com `-v /tmp/old:/probe/old`.

**Correção proposta** — substituir os dois parágrafos finais do E8 por:

```markdown
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
```

---

### A4 — Comandos da seção "Verificação" não rodam como escritos

**Severidade: baixa** (mas o documento afirma que esses comandos foram executados).

**Onde:** `docs/CRYPTO-DEPENDENCIES.md`, tabela "Verificação" (itens 1, 2, 6, linhas ~246–251) e o
bloco de checagem do `Signature` (linha ~256).

**O problema:** usam `docker-compose` (com hífen), que **não existe nesta máquina** — só
`docker compose`. O resto do repo (`docs/RELEASE.md`, `CLAUDE.md`, `scripts/*.sh`) já usa a forma
com espaço.

**Correção:** trocar `docker-compose ` por `docker compose ` nas 4 ocorrências do arquivo.

```bash
grep -n "docker-compose " docs/CRYPTO-DEPENDENCIES.md
```

---

### A5 — Dois desalinhamentos menores entre plano e realidade

**Severidade: baixa.**

1. **`docs/CRYPTO-DEPENDENCIES.md` linha ~218** diz manter `config.platform.php` "com comentário
   explicando que existe por causa do pin de `lastguest/murmurhash` no upstream". `composer.json` é
   JSON e não tem comentário — e não deveria ganhar um `_comment` só para isso. A explicação foi
   parar no `CLAUDE.md`, que é o lugar certo. **Correção:** ajustar a linha do plano para
   "**manter** (E7) — a razão fica documentada na seção *Composer dependencies* do `CLAUDE.md`".

2. **`docs/CRYPTO-DEPENDENCIES.md` linha ~250** espera "10 verdes" no `OnchainWithoutGmpTest`. O
   resultado real é **10 testes, 17 asserções, 1 skipped, OK**. **Correção:** ajustar o esperado.

---

### Itens opcionais (não bloqueiam)

- **`docker-compose.yml` linha 46** ainda passa `COMPOSER_AUTH: ${COMPOSER_AUTH:-}` ao serviço
  `release`. É resíduo da era dos repositórios VCS; inofensivo, pode sair.
- **Não apague os repositórios `lucas-rosa95/bitcoin-php` e `lucas-rosa95/buffertools-php` no
  GitHub — arquive.** A tag `v0.1.0` existe neste repo e o `composer.lock` dela aponta para eles;
  sem os repositórios, reconstruir a 0.1.0 a partir do fonte deixa de ser possível (o zip publicado
  tem o `vendor/` embutido, mas o build a partir do git, não).
- **`bitwasp/bitcoin ^1.1` aceita `paragonie/ecc ^2.1.0`**, enquanto o fork exigia `^2.5`. Está
  travado em `v2.5.0` no lock, e `2.1.0` já é acima do piso do advisory do próprio `paragonie/ecc`
  (`PKSA-jz93-gkdw-s495`, afeta `<2.0.1`) — não há exposição. Só registrar que a folga passou a
  existir; se quiser fechá-la, seria adicionar `"paragonie/ecc": "^2.5"` ao `require` do plugin,
  criando uma dependência direta que o código não usa. Recomendação: **não fazer**, só registrar.

---

## Parte 3 — Risco de integração com a `main`

**Severidade: alta para a entrega** (não é defeito da mudança; é o estado das branches).

A branch `chore/retire-crypto-forks` está **atrás da `main` em 2 commits**, ambos de 2026-08-15:

| Commit | Quando | O que toca |
|---|---|---|
| `3009963 chore(docs): canonical docs` | 12:51 | `CLAUDE.md` e `docs/PREMIUM-ADDON.md` (368 → **651 linhas**) |
| `2b912ca fix: plugin url` | 13:00 | `src/trunk/paycrypto-me-for-woocommerce.php` + os 8 arquivos de tradução (`.po`/`.pot`) |

Merge-base: `549327e`. A branch está à frente em `aab3aef` (o fix de honest failure reporting),
`3e2fef8` e `9adce0a` (os dois planos).

Dois pontos concretos de atrito, **ambos em arquivos que esta mudança edita**:

1. **`CLAUDE.md`** — a versão da `main` contém **de volta** a seção antiga de *Composer
   dependencies*, descrevendo os dois forks e o acesso a repos GitHub como pré-requisito de
   `composer install` (e ainda afirmando, incorretamente, que `bitwasp/buffertools` vem de um fork).
   Resolver o conflito "pela `main`" ressuscita exatamente o texto que esta frente remove. A versão
   da `main` também traz contagens antigas (277 testes) e não tem as seções de honest failure
   reporting — que vêm de `aab3aef`, ainda não mergeado.

2. **`docs/PREMIUM-ADDON.md`** — a `main` reescreveu o arquivo (651 linhas); a branch tem a versão
   de 368 linhas, e a mudança pendente altera **uma linha** dela. Um "take ours" descarta a
   reescrita da `main`. Na versão da `main`, a linha equivalente é a **66**, e ela **ainda cita
   `lucas-rosa95/bitcoin`** — ou seja, a correção continua necessária, só que sobre o texto novo.

**Procedimento sugerido:**

```bash
# 1. commitar a frente de cripto isolada, como o plano pede
git add src/trunk/composer.json src/trunk/composer.lock \
        CLAUDE.md docs/RELEASE.md docs/PREMIUM-ADDON.md docs/CRYPTO-DEPENDENCIES.md \
        docs/CRYPTO-DEPENDENCIES-AUDIT.md src/trunk/CHANGELOG.md
git commit   # mensagem própria; nada de código PHP entra aqui

# 2. só então integrar
git fetch origin && git rebase origin/main
```

Na resolução de conflito:

- `CLAUDE.md` → manter a versão **da branch** na seção *Composer dependencies* (a que descreve os
  pacotes oficiais) e preservar o que a `main` acrescentou fora dela.
- `docs/PREMIUM-ADDON.md` → manter a versão **da `main`** (651 linhas) e reaplicar sobre ela a troca
  `lucas-rosa95/bitcoin` → `bitwasp/bitcoin` na linha 66.
- Depois do rebase, confirmar que sobrou zero referência aos forks fora do registro histórico:

```bash
git grep -n "lucas-rosa95" -- . ':!docs/CRYPTO-DEPENDENCIES.md' ':!docs/CRYPTO-DEPENDENCIES-AUDIT.md'
```

Vale lembrar que esta branch carrega junto os 3 commits de `fix/honest-failure-reporting`, que o
próprio plano marca como **pendentes de validação manual** — o merge para a `main` arrasta essa
validação junto.

---

## Parte 4 — O que **não** mexer

Itens que parecem problema e são decisão consciente. Não "corrigir":

- **`config.platform.php = "7.4"` deve ficar.** `bitwasp/bitcoin v1.1.0` fixa
  `lastguest/murmurhash: v2.0.0`, que declara `php: ^7`; sem o pin, a resolução honesta em PHP 8
  falha. `murmurhash` só é alcançável por `Bloom/BloomFilter.php` e um método de `Crypto/Hash.php`,
  nenhum dos dois referenciado pelo plugin.
- **`Requires PHP: 8.1` no header do plugin convivendo com `platform.php = 7.4` no composer** é
  esperado: o pin governa só a resolução de dependências, não o runtime. Pré-existente à mudança.
- **A remoção de `config.audit.ignore` está certa.** As duas entradas eram contra `mdanter/ecc`, que
  nunca esteve na árvore; sem elas o `composer audit` volta a ter significado (e passa limpo).
- **A remoção de `minimum-stability: dev` / `prefer-stable` está certa.** Só existiam para permitir
  `dev-master`; `bitwasp/bech32 v0.0.1` é tag estável e instala sem eles (verificado em árvore
  limpa).
- **`src/trunk/readme.txt` linha ~140** ("Built with the open-source `bitwasp/bitcoin` library")
  agora é literalmente verdade — não precisa mudar.
- **As deprecations do A2 não são bloqueio de release.** Só aparecem com `WP_DEBUG`, e 7 das 12 já
  existiam antes.

---

## Checklist de fechamento

```
[ ] A1 — CHANGELOG.md: reescrito o bullet de ### Changed (sem sugerir correção de CVE)
[ ] A2 — CRYPTO-DEPENDENCIES.md: E6 refeito com os números do caminho real (7 → 12)
[ ] A2 — CRYPTO-DEPENDENCIES.md: "Horizonte PHP 9" cita tentative return types
[ ] A3 — CRYPTO-DEPENDENCIES.md: E8 corrigido (linhas master x 0.5, 4 melhorias ganhas)
[ ] A4 — CRYPTO-DEPENDENCIES.md: docker-compose → docker compose (4 ocorrências)
[ ] A5 — CRYPTO-DEPENDENCIES.md: linha do config.platform e o esperado do OnchainWithoutGmpTest
[ ] Commit isolado da frente de cripto (sem código PHP junto)
[ ] Rebase sobre origin/main, com CLAUDE.md e PREMIUM-ADDON.md resolvidos conforme a Parte 3
[ ] git grep "lucas-rosa95" limpo fora dos dois documentos históricos
[ ] (no release) readme.txt == Changelog == sincronizado com o CHANGELOG.md
```

Nada nesta lista exige rodar a suíte de novo, a menos que algum arquivo em `src/trunk/includes/`
mude — o que não é o caso.

---

## Apêndice — script de medição de deprecations

Usado no A2. Salve como `deprecation-probe.php` num diretório do host, monte-o no container e rode
apontando para o `vendor/autoload.php` que quiser medir.

```php
<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

$collected = [];
set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$collected) {
    $collected[] = sprintf('[%d] %s  @ %s:%d', $errno, $errstr, $errfile, $errline);
    return true;
});

define('ABSPATH', '/tmp/fake-wp/');   // sem isto o guard do arquivo do plugin faz exit(0) silencioso

require ($argv[1] ?? '/plugin/vendor/autoload.php');
require '/plugin/includes/services/class-bitcoin-address-service.php';

use PayCryptoMe\WooCommerce\BitcoinAddressService;
use BitWasp\Bitcoin\Network\NetworkFactory;

$vectors = json_decode(file_get_contents('/plugin/tests/vectors/bitcoin_addresses.json'), true);
$svc     = new BitcoinAddressService();

$checked = 0;
$diff    = 0;
foreach ($vectors as $entry) {
    $network = $entry['network'] === 'mainnet' ? NetworkFactory::bitcoin() : NetworkFactory::bitcoinTestnet();
    foreach ($entry['addresses'] as $addr) {
        $got = $svc->generate_address_from_xPub($entry['xpub'], $addr['index'], $network, null);
        $checked++;
        if ($got !== $addr['address']) {
            $diff++;
            echo "DIVERGENCIA: {$entry['prefix']} idx {$addr['index']}: esperado {$addr['address']}, obtido {$got}\n";
        }
    }
}

$svc->validate_extended_pubkey($vectors[0]['xpub'], NetworkFactory::bitcoin());
$svc->validate_bitcoin_address('bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq', NetworkFactory::bitcoin());
$svc->validate_bitcoin_address('1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2', NetworkFactory::bitcoin());

restore_error_handler();

echo "PHP " . PHP_VERSION . "\n";
echo "enderecos conferidos: {$checked} | divergencias: {$diff}\n";
echo "diagnosticos coletados: " . count($collected) . "\n";
foreach (array_count_values($collected) as $msg => $n) {
    echo "  x{$n}  {$msg}\n";
}
```

Rodando contra o vendor atual:

```bash
docker compose run --rm -v /caminho/do/probe:/probe release php /probe/deprecation-probe.php
```

Rodando contra o estado anterior (fork), tudo numa invocação — o `/tmp` do container é efêmero:

```bash
# no host, primeiro:
mkdir -p /tmp/old-cfg
git show 9adce0a:src/trunk/composer.json > /tmp/old-cfg/composer.json
git show 9adce0a:src/trunk/composer.lock > /tmp/old-cfg/composer.lock
cp /caminho/do/probe/deprecation-probe.php /tmp/old-cfg/

docker compose run --rm -v /tmp/old-cfg:/probe release bash -lc '
  mkdir -p /tmp/old && cd /tmp/old
  cp /probe/composer.json /probe/composer.lock . && mkdir -p includes exceptions
  composer install --no-dev --prefer-dist --no-interaction -q
  php /probe/deprecation-probe.php /tmp/old/vendor/autoload.php'
```

Os dois devem reportar `enderecos conferidos: 60 | divergencias: 0` — é essa igualdade, medida nos
dois vendors, que sustenta a afirmação de equivalência funcional da troca.
