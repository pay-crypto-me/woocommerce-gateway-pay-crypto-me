# 🚀 PayCrypto.Me — Guia de Release

Este guia descreve o processo completo para gerar um build de produção e submeter o plugin **PayCrypto.Me for WooCommerce** ao diretório oficial do WordPress.org.

---

## Visão Geral do Processo

O release do plugin envolve três etapas principais:

1. **Build local** — compilar os assets JS/CSS e preparar o pacote PHP otimizado dentro do container Docker.
2. **Geração do zip** — criar o arquivo distribuível `.zip` para upload manual ou via SVN.
3. **Submissão ao WordPress.org** — enviar via SVN (método oficial) ou upload direto no painel do plugin.

O script `scripts/release.sh` automatiza as etapas 1 e 2 de ponta a ponta.

---

## Identidade Canônica do Projeto

> **Importante para agentes e automações:** os valores abaixo são fixos para este projeto e devem ser usados literalmente em todos os comandos de release.

| Campo | Valor canônico |
|---|---|
| **SLUG** | `paycrypto-me-for-woocommerce` |
| **Diretório raiz** | `paycrypto-me-for-woocommerce/` (raiz do repositório) |
| **Arquivo principal do plugin** | `src/trunk/paycrypto-me-for-woocommerce.php` |
| **SVN URL** | `https://plugins.svn.wordpress.org/paycrypto-me-for-woocommerce` |
| **Serviço Docker (dev)** | `wordpress` |
| **Serviço Docker (release)** | `release` (efêmero, atrás do profile `release`) |

O parâmetro `-s SLUG` do script existe para reutilização em outros projetos, mas **neste repositório sempre será `-s paycrypto-me-for-woocommerce`**. Nunca altere esse valor.

---

## De onde rodar os comandos

**Todos os comandos deste guia devem ser executados a partir da raiz do repositório**, não de dentro de `src/trunk/` ou `scripts/`. A raiz é o diretório que contém `docker-compose.yml`, `scripts/` e `src/`.

```bash
# Confirmar que você está na raiz correta
ls docker-compose.yml scripts/ src/trunk/
```

Se algum desses três não aparecer, navegue para a raiz antes de continuar.

---

## Determinando a Próxima Versão

O projeto segue **Semantic Versioning** (`MAJOR.MINOR.PATCH`):

| Tipo de mudança | Qual número incrementar | Exemplo |
|---|---|---|
| Correção de bug sem quebra de compatibilidade | `PATCH` | `0.1.0` → `0.1.1` |
| Nova feature sem quebra de compatibilidade | `MINOR` | `0.1.0` → `0.2.0` |
| Mudança que quebra compatibilidade com versões anteriores | `MAJOR` | `0.1.0` → `1.0.0` |

**Para descobrir a versão atual** antes de decidir a próxima:

```bash
# Opção 1 — ler do cabeçalho do plugin (fonte de verdade)
grep '^ \* Version:' src/trunk/paycrypto-me-for-woocommerce.php

# Opção 2 — ler do composer.json
grep '"version"' src/trunk/composer.json

# Opção 3 — ver a última tag git
git tag --sort=-version:refname | head -5
```

O script valida que a versão passada é um semver válido (`X.Y.Z`). Não use prefixo `v` nem sufixos como `-beta`.

---

## Atualizando o Changelog Antes do Release

**Antes de rodar o script**, atualize o changelog em **dois arquivos**:

1. **`src/trunk/readme.txt`** — changelog oficial exibido no WP.org
2. **`src/trunk/CHANGELOG.md`** — changelog estilo GitHub/Keep a Changelog (usado no repositório)

As mudanças devem ser idênticas em ambos os arquivos para manter sincronização. Atualize os dois de uma vez.

### Formato do changelog em `readme.txt`

O arquivo usa o formato WordPress readme. Localize a seção `== Changelog ==` e adicione a nova entrada **no topo da lista** (mais recente primeiro):

```
== Changelog ==

= X.Y.Z =
* Descrição curta da mudança 1.
* Descrição curta da mudança 2.
* Fix: descrição do bug corrigido.

= 0.1.0 =
* Initial public release.
...
```

### Formato do Upgrade Notice em `readme.txt`

Logo abaixo da seção `== Upgrade Notice ==`, adicione também uma nota para a nova versão:

```
== Upgrade Notice ==

= X.Y.Z =
Descrição resumida do que muda para quem está atualizando.

= 0.1.0 =
Initial release.
```

> O `Upgrade Notice` aparece no painel do WP para quem já tem o plugin instalado e está prestes a atualizar. Mantenha-o em uma linha curta e objetiva.

### Formato do changelog em `src/trunk/CHANGELOG.md`

O arquivo usa o formato **Keep a Changelog** (https://keepachangelog.com/). Localize a seção `## Unreleased` e mova os items relevantes para uma **nova seção com a versão**:

```markdown
## Unreleased

 - (itens realmente futuros/planejados)

## X.Y.Z

- Descrição curta da mudança 1.
- Descrição curta da mudança 2.
- Fix: descrição do bug corrigido.

## 0.1.0

- Initial public release.
...
```

> **Importante:** O conteúdo bullet-point de `CHANGELOG.md` e `readme.txt` deve ser **idêntico** (ou equivalente) para ambos os formatos. Atualize os dois ao mesmo tempo para evitar drift.

---

## Pré-requisitos

Antes de executar o release, verifique:

| Requisito | Como verificar |
|---|---|
| Está na raiz do repositório | `ls docker-compose.yml scripts/ src/trunk/` |
| Docker + Compose disponíveis (o release roda em container efêmero `release`; o stack de dev **não** precisa estar no ar) | `docker compose version` — ou `docker-compose version`; os scripts aceitam as duas formas |
| `rsync` disponível **no host** (o `release.sh` sincroniza o build dir fora do container) | `command -v rsync` |
| Branch `main` limpa (sem changes pendentes) | `git status` |
| `readme.txt` atualizado com changelog da nova versão | Ver seção acima |
| Todos os testes passando | `./scripts/release.sh ... --no-zip` primeiro |
| Smoke de host mínimo passando (**stack de dev precisa estar no ar** — diferente do resto do release) | `docker compose up -d wordpress` e depois `./scripts/smoke-minimal-host.sh` — ver seção abaixo |
| Auditoria do pin de plataforma passando | `./scripts/check-platform-pin.sh` — **já roda automaticamente** na fase de testes do `release.sh`; ver seção abaixo |
| Versão nova definida (semver `X.Y.Z`) | Ver seção "Determinando a Próxima Versão" |
| Credenciais SVN configuradas (se for submeter ao WP.org) | Ver seção "Configurando Credenciais SVN" abaixo |

> **Por que Docker?** O `release.sh` executa `npm run build`, `phpunit` e `composer install --no-dev` dentro de um **container efêmero** (serviço `release` do `docker-compose.yml`, invocado via `docker compose run --rm release`). Esse serviço reutiliza a **mesma imagem** e o **mesmo bind mount `./src/trunk`** do serviço de dev `wordpress` — mas sem WordPress/MySQL, sem `depends_on` e atrás de um profile. Assim o ambiente de build é idêntico ao de execução do plugin **sem** depender do stack de dev no ar (e sem ligar o banco).

---

## Smoke de Host Mínimo (passo obrigatório antes de gerar release)

`./scripts/smoke-minimal-host.sh` existe para fechar uma classe de bug real: um fatal de ativação
(`gmp_init` indefinida) reportado pelo revisor do WordPress.org, cujo ambiente não tinha a
extensão GMP — nosso container de dev (e o do serviço `release`) tem *todas* as extensões
instaladas, então nenhum PHPUnit consegue detectar esse tipo de regressão.

O script reutiliza a mesma técnica que reproduziu o bug original — desabilitar uma função
específica via `php -d disable_functions=...` para simular a extensão correspondente ausente —
contra o serviço de dev `wordpress` (que, ao contrário do resto do `release.sh`, **precisa
estar no ar**):

```bash
docker compose up -d wordpress   # se ainda não estiver no ar
./scripts/smoke-minimal-host.sh
```

Cobre, cada combinação isoladamente e sem fatal:

| Extensão simulada ausente | O que deve acontecer |
|---|---|
| `gmp` | Listagem/construção dos gateways não fatala (construção é lazy) |
| `gd` | Geração de QR degrada para vazio, sem fatal |
| `iconv` | Idem (dependência obrigatória do `bacon/bacon-qr-code`) |
| `fileinfo` | Idem (`mime_content_type`, usado pelo logo do QR) |
| `gd` (novamente) | A página de detalhes do pedido ainda renderiza (endereço presente), mesmo sem QR |

O script sai com código != 0 se qualquer combinação produzir um fatal. Cria arquivos PHP
temporários em `src/trunk/.smoke-minimal-host-tmp/` (necessário para rodar via
`wp eval-file` dentro do container) e sempre os remove ao final, mesmo em caso de erro.

> **Limitação conhecida:** `disable_functions` bloqueia a *função*, não a extensão — por isso
> o check de `gmp` não consegue validar o guard `extension_loaded('gmp')` de `is_available()`
> (que continua reportando `true` nesse cenário simulado). Esse guard só é verificável de fato
> num host que realmente não tenha a extensão compilada (como o WordPress Playground do
> revisor) — o smoke test cobre a parte que É simulável: a construção/listagem dos gateways
> nunca deve tocar `gmp_init` diretamente.

---

## Auditoria do pin de plataforma (roda dentro do `release.sh`)

`./scripts/check-platform-pin.sh` fecha o ponto cego que o `config.platform.php = 7.4` do
`src/trunk/composer.json` abre. Esse pin existe por **um** pacote: o `bitwasp/bitcoin v1.1.0` exige
`lastguest/murmurhash` na versão **exata** `v2.0.0`, que declara `php: ^7` — sem o pin, uma
resolução honesta em PHP 8 se recusa a instalar. O pacote só é alcançável por
`Crypto/Hash.php::murmur3()` (chamado de `Bloom/BloomFilter.php`), e o plugin não referencia nenhum
dos dois: é instalado e nunca executado. Detalhe medido em
[docs/CRYPTO-DEPENDENCIES.md](CRYPTO-DEPENDENCIES.md) → E7.

O problema é que o pin é **global e permanente**: ele manda o Composer resolver a árvore **inteira**
como se fosse 7.4, hoje e no futuro. Uma dependência nova (direta ou transitiva) incompatível com o
piso real de PHP do plugin entraria **calada** — justamente a checagem que o Composer daria de graça.

O script roda `composer why-not php <piso>`, que **ignora o pin** e lista *todos* os pacotes cujo
requisito de PHP exclui aquele piso. O piso vem do header do plugin (`Requires PHP:`), então subir o
piso move a checagem junto. Qualquer pacote além do único allowlistado reprova com exit != 0:

```bash
./scripts/check-platform-pin.sh
```

Três resultados possíveis, todos acionáveis:

| Resultado | O que significa |
|---|---|
| só `lastguest/murmurhash` | esperado — pin justificado e auditado |
| qualquer outro pacote | **reprova.** É uma incompatibilidade real sendo silenciada em código que vai para as lojas. Não alargue a allowlist para passar: ou a dependência é alcançável do plugin (aí é bug, não workaround), ou a prova de que não é precisa ir para o `CRYPTO-DEPENDENCIES.md` primeiro |
| nenhum pacote | o pin virou peso morto — **remova-o** do `composer.json`, regrave o lock e apague a nota do E7. É o que vai acontecer quando o upstream soltar o pin exato do murmurhash (a `2.1.1` já declara `php: ^7||^8.0`) |

Não precisa do stack de dev no ar: usa o serviço efêmero `release`, e cai para um `composer` do host
se não houver Docker. Já está ligado na fase **Platform pin audit** do `release.sh`, logo depois do
PHPUnit, e é pulado junto com os testes por `--no-tests` (então o fluxo `--svn`, que exige
`--no-tests`, não o reexecuta).

---

## Estrutura do Repositório (Referência Rápida)

```
paycrypto-me-for-woocommerce/
├── scripts/
│   └── release.sh              ← script principal de release
├── src/trunk/                  ← código-fonte do plugin (tudo que vai no zip)
│   ├── includes/blocks/js/     ← fontes JS dos blocos Gutenberg (EDITAR AQUI)
│   ├── assets/blocks/          ← output webpack (NÃO editar diretamente)
│   ├── package.json
│   ├── webpack.config.js       ← define as 2 entradas: on-chain + lightning
│   └── composer.json
├── releases/                   ← zips gerados ficam aqui
└── docs/
    └── RELEASE.md              ← este arquivo
```

---

## Uso do Script de Release

### Sintaxe

```bash
./scripts/release.sh -v VERSION -s SLUG [opções]
```

### Parâmetros Obrigatórios

| Parâmetro | Descrição | Exemplo |
|---|---|---|
| `-v VERSION` | Versão no formato semver `X.Y.Z` | `-v 1.2.0` |
| `-s SLUG` | Slug do plugin (nome do diretório no WP.org) | `-s paycrypto-me-for-woocommerce` |

### Flags Opcionais

| Flag | Comportamento padrão | Quando usar |
|---|---|---|
| `--no-build` | Build JS ativo por padrão | Quando os assets já foram compilados e não houve mudança de frontend |
| `--no-tests` | PHPUnit ativo por padrão | Em hotfixes urgentes (não recomendado em releases normais) |
| `--no-zip` | Zip criado por padrão | Para testar apenas o bump de versão e build |
| `--git` | Git desligado por padrão | Para commitar o bump e criar a tag `vX.Y.Z` automaticamente |
| `--svn` | SVN desligado por padrão | Prepara o working copy SVN a partir do zip aprovado e mostra o gate de revisão — **não commita**. Exige `--no-build --no-tests --no-zip` |
| `--svn-commit` | SVN desligado por padrão | Igual a `--svn`, mas também commita `trunk/`+`assets/` e cria a tag da versão |
| `--no-docker` | Docker ativo por padrão | Para rodar em CI/CD sem container (requer Node.js e Composer locais) |
| `--dry-run` | Execução real | Para visualizar todos os passos sem aplicar nenhuma mudança |

---

## Fluxo Completo de Release (Passo a Passo)

### 1. Validar com Dry-Run

Antes de qualquer coisa, execute com `--dry-run` para confirmar o que vai acontecer:

```bash
./scripts/release.sh \
  -v 1.2.0 \
  -s paycrypto-me-for-woocommerce \
  --dry-run
```

O output listará cada step (build, testes, bump de versão, rsync, composer, zip) sem executar nada. Use para revisar antes de rodar de verdade.

---

### 2. Release Completo (Comando Principal)

```bash
./scripts/release.sh \
  -v 1.2.0 \
  -s paycrypto-me-for-woocommerce \
  --git
```

Este comando executa na ordem:

1. **Pre-flight checks** — valida semver, verifica Docker rodando, avisa sobre mudanças não commitadas no git.
2. **npm build (no container)** — `npm ci && npm run build` compila ambos os blocos Gutenberg via `webpack.config.js`:
   - `assets/blocks/paycrypto_me-blocks.js` (gateway On-Chain)
   - `assets/blocks/paycrypto_me_lightning-blocks.js` (gateway Lightning)
3. **PHPUnit (no container)** — executa a suite de testes contra a versão PHP do container.
4. **Bump de versão** — atualiza a string de versão em 4 arquivos automaticamente:
   - Cabeçalho do plugin (`paycrypto-me-for-woocommerce.php`)
   - Constante `VERSION` na classe PHP
   - `Stable tag` em `readme.txt`
   - Campo `"version"` em `composer.json` e `package.json`
5. **rsync para build dir** — copia o `src/trunk/` para um diretório temporário **sem** `vendor/` e `node_modules/`.
6. **Composer de produção (no container efêmero `release`)** — `composer install --no-dev --optimize-autoloader --prefer-dist` no build dir via `docker compose run --rm release`. Resultado: vendor sem dependências de desenvolvimento e com autoloader classmap otimizado.
7. **Limpeza do vendor** — remove arquivos residuais não necessários em runtime: diretórios `.git/`, `tests/`, `examples/`, `bin/`, arquivos `.md`, `.yml`, fontes pesadas do `endroid/qr-code`. **`composer.json`/`composer.lock`/`package.json` do plugin são mantidos no pacote** (transparência open-source — requisito do WordPress.org).
8. **Criação do zip** — `releases/paycrypto-me-for-woocommerce-1.2.0.zip`.
9. **Git** (com `--git`) — commit dos arquivos de versão + tag `v1.2.0`. **Não faz push automaticamente.**
10. **Cleanup** — o diretório temporário de build é removido automaticamente (inclusive em caso de erro).

Ao final, o arquivo `releases/paycrypto-me-for-woocommerce-1.2.0.zip` está pronto para submissão.

---

### 3. Inspecionar o Zip Gerado

Antes de submeter, valide o conteúdo do zip:

```bash
# Listar conteúdo do zip
unzip -l releases/paycrypto-me-for-woocommerce-1.2.0.zip

# Verificar se ambos os blocos estão presentes
unzip -l releases/paycrypto-me-for-woocommerce-1.2.0.zip | grep 'assets/blocks'

# Verificar que NÃO há phpunit, testes ou .git no vendor
unzip -l releases/paycrypto-me-for-woocommerce-1.2.0.zip | grep -E '(phpunit|tests/|\.git)'

# Verificar que o autoloader otimizado foi gerado
unzip -l releases/paycrypto-me-for-woocommerce-1.2.0.zip | grep 'autoload_classmap'
```

O zip correto deve conter:
- `paycrypto-me-for-woocommerce/assets/blocks/paycrypto_me-blocks.js` ✓
- `paycrypto-me-for-woocommerce/assets/blocks/paycrypto_me_lightning-blocks.js` ✓
- `paycrypto-me-for-woocommerce/vendor/composer/autoload_classmap.php` ✓
- **Não deve conter** `phpunit`, `tests/`, `.git/` dentro do vendor ✓

---

### 4. Publicar a Tag Git e Fazer Push

O `--git` cria o commit e a tag localmente, mas **não faz push**. Após validar o zip:

```bash
# Revisar o commit gerado
git log --oneline -3

# Enviar o commit e a tag para o repositório remoto
git push origin main
git push origin v1.2.0
```

---

### 5. Submissão ao WordPress.org

#### Opção A — Upload Manual (mais simples)

1. Acesse [wordpress.org/plugins/wp-admin/plugin.php](https://wordpress.org/plugins/wp-admin/) (painel do autor no WP.org).
2. Vá até o seu plugin → **Advanced** → **Upload new version**.
3. Faça upload do arquivo `releases/paycrypto-me-for-woocommerce-1.2.0.zip`.

#### Opção B — SVN (método oficial recomendado pelo WP.org)

##### Como funciona (leia antes de rodar)

- **Working copy persistente em `releases/svn/`** (fora do diretório de build efêmero; já coberto pelo `.gitignore`). Não é apagado automaticamente ao final — fica disponível para inspeção entre execuções. Staging intermediário em `releases/.svn-stage/`.
- **A fonte da verdade é o zip aprovado** em `releases/{slug}-{version}.zip`, nunca um rebuild. O WP.org **reconstrói** o download a partir da tag SVN — o requisito é fidelidade de **conteúdo dos arquivos**, não do `.zip` em si. Ainda assim publicamos a partir do zip já validado: re-rodar `composer install` no momento da publicação reintroduz o risco de o `vendor/` divergir do que foi testado. As dependências são todas oficiais (Packagist), mas o artefato que passou pela verificação é o zip aprovado — é ele que vai ao ar.
- **`--svn`/`--svn-commit` exigem `--no-build --no-tests --no-zip`.** O script recusa rodar (erro duro) se qualquer flag de build estiver ativa — garante que nunca se publique algo diferente do zip já aprovado.
- **Commit é opt-in.** `--svn` sozinho prepara o working copy e imprime um resumo do que mudaria (gate de revisão) — **nada é commitado**. `--svn-commit` faz o ciclo completo: commit de `trunk/` + `assets/`, depois cria a tag por cópia server-side.
- **Tags do WP.org são imutáveis por convenção.** Rodar `--svn-commit` de novo na mesma versão falha com erro claro — nunca sobrescreve nem aninha a tag existente (`svn cp` para um destino já existente aninharia em vez de falhar, por isso o script checa antes). Para republicar, bump a versão e rode de novo.
- **`assets/` (banner, ícone, screenshots) agora é automático** — todo `--svn-commit` espelha `src/assets/` para o `assets/` do SVN junto com `trunk/`. Deixou de ser um passo manual separado.
- **`git clean -xdf` apaga tanto `releases/svn/` quanto o zip aprovado** em `releases/` (ambos ignorados pelo git). Se isso acontecer, gere o zip de novo antes de publicar.

##### Configurando Credenciais SVN

As credenciais SVN são as mesmas do seu login em **wordpress.org** (não do wp-admin do seu site). Na primeira vez, o SVN solicitará usuário e senha interativamente e poderá salvá-las em cache.

```bash
# Testar acesso ao repositório SVN do plugin (deve listar trunk/ e tags/)
svn list https://plugins.svn.wordpress.org/paycrypto-me-for-woocommerce \
  --username SEU_USUARIO_WP_ORG

# Se quiser salvar as credenciais em cache para não precisar digitar sempre
svn info https://plugins.svn.wordpress.org/paycrypto-me-for-woocommerce \
  --username SEU_USUARIO_WP_ORG \
  --password SUA_SENHA \
  --no-auth-cache  # remova esta flag se quiser que o SVN salve o login
```

> As credenciais SVN do WP.org são **diferentes** da senha do painel de administração do WordPress. São as credenciais de login em `wordpress.org/login/`.

##### Ensaio offline (recomendado antes do primeiro push real ou de qualquer mudança no script)

Valida o fluxo inteiro contra um repositório SVN local fake — sem tocar no WP.org:

```bash
svnadmin create /tmp/fake-wporg
svn mkdir -m init \
  file:///tmp/fake-wporg/trunk file:///tmp/fake-wporg/tags \
  file:///tmp/fake-wporg/branches file:///tmp/fake-wporg/assets

# prepara e mostra o gate de revisão — nada é commitado
SVN_URL=file:///tmp/fake-wporg ./scripts/release.sh \
  -v X.Y.Z -s paycrypto-me-for-woocommerce --no-build --no-tests --no-zip --svn
(cd releases/svn && svn status)

# publica de verdade, mas no repositório fake
SVN_URL=file:///tmp/fake-wporg ./scripts/release.sh \
  -v X.Y.Z -s paycrypto-me-for-woocommerce --no-build --no-tests --no-zip --svn-commit

rm -rf releases/svn releases/.svn-stage   # descarta o WC do ensaio antes do push real
```

Critério de aceite principal: `diff -r` entre o zip extraído e a tag SVN publicada no repositório
fake não pode mostrar nenhuma linha de diferença — é a prova de que o working copy publica
exatamente o conteúdo do zip aprovado, sem rebuild.

##### Executando o Release via SVN (push real)

```bash
# 1. preparar sem commitar — inspeciona o que vai mudar (gate de revisão)
./scripts/release.sh \
  -v 1.2.0 \
  -s paycrypto-me-for-woocommerce \
  --no-build --no-tests --no-zip --svn

# 2. revisar manualmente
(cd releases/svn && svn status | head -40)

# 3. publicar (pede a senha SVN do wordpress.org — não a do wp-admin)
./scripts/release.sh \
  -v 1.2.0 \
  -s paycrypto-me-for-woocommerce \
  --no-build --no-tests --no-zip --svn-commit
```

O passo 3 executa duas revisões no SVN: commit de `trunk/` + `assets/`, depois `svn copy` server-side para `tags/1.2.0` (custa 0 bytes, não depende do working copy). Se o commit passar e a cópia da tag falhar, **rode o passo 3 de novo** — o script detecta que não há nada a commitar e refaz só a cópia da tag, sem duplicar o commit.

Após o commit SVN, o WP.org processa a nova versão em alguns minutos e ela aparece disponível para atualização nos sites que têm o plugin instalado.

---

## Cenários Comuns

### Hotfix (sem recompilar frontend)

Os assets JS/CSS não mudaram, apenas PHP:

```bash
./scripts/release.sh \
  -v 1.1.1 \
  -s paycrypto-me-for-woocommerce \
  --no-build \
  --git
```

### Beta / RC (zip de teste local)

Para gerar um zip de teste sem commitar nem taguear (git é desligado por padrão — só liga com `--git`; os arquivos de versão são bumpados de qualquer forma, com ou sem esse flag):

```bash
./scripts/release.sh \
  -v 1.2.0 \
  -s paycrypto-me-for-woocommerce
```

> **Nota:** o script aceita apenas semver puro (`X.Y.Z`). Strings como `1.2.0-beta.1` são rejeitadas na validação. Para testes locais, use a versão final sem sufixo e simplesmente não suba o zip ao WP.org até estar pronto.

### Validar build sem gerar zip

Útil para checar se build e testes passam antes de subir a versão:

```bash
./scripts/release.sh \
  -v 1.2.0 \
  -s paycrypto-me-for-woocommerce \
  --no-zip
```

### CI/CD sem Docker

Em pipelines onde o container não está disponível (e Node.js + Composer estão instalados nativamente):

```bash
./scripts/release.sh \
  -v 1.2.0 \
  -s paycrypto-me-for-woocommerce \
  --no-docker
```

---

## O Que o Script NÃO Faz (Responsabilidade Manual)

| Ação | Por quê manual |
|---|---|
| `git push origin main` | Evitar push acidental; deve ser revisado antes |
| `git push origin vX.Y.Z` | Idem |
| Atualizar `readme.txt` com changelog | Conteúdo editorial, não automatizável |
| Editar os arquivos em `src/assets/` (banner/ícone/screenshots) | Conteúdo editorial; o **upload** ao SVN já é automático via `--svn-commit` (ver seção SVN acima) |
| Gerar e submeter traduções atualizadas | Usar `npm run translate` separadamente (ver [TRANSLATION.md](./TRANSLATION.md)) |

---

## Arquivos Modificados pelo Script (Bump de Versão)

O script atualiza **apenas** estes 4 arquivos ao bumpar a versão. Nenhum outro arquivo é alterado no repositório:

| Arquivo | Campo atualizado |
|---|---|
| `src/trunk/paycrypto-me-for-woocommerce.php` | `* Version: X.Y.Z` no cabeçalho |
| `src/trunk/paycrypto-me-for-woocommerce.php` | `const string VERSION = 'X.Y.Z'` |
| `src/trunk/readme.txt` | `Stable tag: X.Y.Z` |
| `src/trunk/composer.json` | `"version": "X.Y.Z"` |
| `src/trunk/package.json` | `"version": "X.Y.Z"` |

---

## Entendendo o Build dos Blocos Gutenberg

O plugin tem dois blocos Gutenberg (para o WooCommerce Checkout Blocks):

| Bloco | Fonte | Output |
|---|---|---|
| On-Chain (Bitcoin) | `includes/blocks/js/paycrypto_me-blocks.js` + `scss/paycrypto_me-blocks.scss` | `assets/blocks/paycrypto_me-blocks.js` + `.css` |
| Lightning Network | `includes/blocks/js/paycrypto_me_lightning-blocks.js` | `assets/blocks/paycrypto_me_lightning-blocks.js` |

O `webpack.config.js` define as duas entradas. O script `npm run build` usa `--config webpack.config.js`, garantindo que ambos sejam compilados juntos.

> **Regra importante:** Nunca edite arquivos dentro de `assets/blocks/` diretamente. Edite as fontes em `includes/blocks/js/` e execute `npm run build` (ou deixe o script de release fazer isso automaticamente).

---

## Solução de Problemas

### Docker Compose não disponível

```
[ERROR] Docker Compose is not available.
```

**Solução:** instale o Docker (com o plugin Compose) e confirme com `docker compose version`.
O release **não** exige o stack de dev no ar — ele sobe o serviço efêmero `release` sob
demanda (`docker compose run --rm release`). Alternativa: passar `--no-docker` para rodar
build/testes no host (requer Node.js, PHP e Composer locais).

---

### npm build falha no container

**Diagnóstico:**
```bash
# Testar o build manualmente no container efêmero de release
docker compose run --rm release bash -c "npm ci && npm run build"
```

---

### Composer falha no build dir

As dependências vêm todas do Packagist (nenhum repositório VCS privado). Se o container não tiver acesso à internet, o `composer install` falhará.

**Solução:** Garantir que o container tem acesso à internet e que `composer.lock` está atualizado:
```bash
# No host, atualizar o lock file
docker compose run --rm release bash -c "composer update --lock"
```

---

### Versão com formato inválido

```
[ERROR] VERSION must be a valid semver string (e.g. 1.2.3). Got: v1.0
```

Use sempre três números separados por ponto: `1.2.0`, `0.1.3`, `2.0.0`. Não use prefixo `v`.

---

### zip não contém os blocos compilados

Se `assets/blocks/paycrypto_me_lightning-blocks.js` estiver ausente no zip, significa que o build não rodou ou falhou silenciosamente.

**Diagnóstico:**
```bash
# Verificar se o arquivo existe na source
ls src/trunk/assets/blocks/

# Rodar apenas o build para verificar
./scripts/release.sh -v 0.0.0 -s teste --no-tests --no-zip
```

---

### `svn commit` retorna erro mas o trunk foi publicado mesmo assim

Observado no primeiro push real (2026-08-08): `svn commit` retornou
`E000002: Can't open file '.../db/transactions/NNNNNNN-xxxxx.txn/props'` depois que toda a
transmissão de arquivos já tinha terminado. É uma falha **do lado do servidor** do
`plugins.svn.wordpress.org` — a transação já tinha sido persistida (confirmado via `svn info` no
trunk remoto: revisão e conteúdo batiam com o zip). Como o script usa `set -e`, ele abortou antes
de criar a tag.

**Correção: rodar o mesmo `--svn-commit` de novo.** O script vê que não há nada a commitar, lê a
revisão atual do trunk remoto e cria só a tag por cima dela — nenhuma ação manual além de repetir
o comando. Não é um bug do script; pode voltar a acontecer em releases futuros.

---

## Checklist de Release

Copie e use a cada release. Substitua `X.Y.Z` pela versão real.

```
PRÉ-RELEASE
[ ] Está na raiz do repositório (ls docker-compose.yml scripts/ src/trunk/)
[ ] Branch main limpa: git status
[ ] Versão determinada (ver seção "Determinando a Próxima Versão")
[ ] src/trunk/readme.txt atualizado: nova entrada em == Changelog == e == Upgrade Notice ==
[ ] src/trunk/CHANGELOG.md sincronizado: movido item de Unreleased para nova seção de versão
[ ] Docker rodando: docker compose ps
[ ] Smoke de host mínimo passando:
    docker compose up -d wordpress && ./scripts/smoke-minimal-host.sh

BUILD E VALIDAÇÃO
[ ] Dry-run sem erros:
    ./scripts/release.sh -v X.Y.Z -s paycrypto-me-for-woocommerce --dry-run
[ ] Release completo com git:
    ./scripts/release.sh -v X.Y.Z -s paycrypto-me-for-woocommerce --git
[ ] Zip inspecionado:
    - ambos os blocos presentes (paycrypto_me-blocks.js e paycrypto_me_lightning-blocks.js)
    - vendor/composer/autoload_classmap.php presente
    - nenhum .git/, tests/ ou phpunit dentro do vendor

GIT E PUBLICAÇÃO
[ ] git push origin main
[ ] git push origin vX.Y.Z

SVN (só na primeira vez que mexer no script, ou antes do primeiro push real)
[ ] Ensaio offline completo, ver seção "Ensaio offline" acima —
    diff -r entre zip e tag publicada no repositório fake é idêntico (0 linhas)
[ ] rm -rf releases/svn releases/.svn-stage (descarta o WC do ensaio)

SVN (todo release)
[ ] Preparar sem commitar: ./scripts/release.sh -v X.Y.Z -s paycrypto-me-for-woocommerce
    --no-build --no-tests --no-zip --svn
[ ] Gate de revisão inspecionado: (cd releases/svn && svn status)
[ ] Publicar: ./scripts/release.sh -v X.Y.Z -s paycrypto-me-for-woocommerce
    --no-build --no-tests --no-zip --svn-commit
[ ] svn ls https://plugins.svn.wordpress.org/paycrypto-me-for-woocommerce/tags/
    mostra X.Y.Z/
[ ] Nova versão visível na página do plugin no WP.org (indexação completa até 72h)
```

---

## Referências

- [WordPress Plugin Developer Handbook — Releasing Your Plugin](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/)
- [Guia de Traduções do Plugin](./TRANSLATION.md)
- Script de release: [`scripts/release.sh`](../scripts/release.sh)
- Configuração webpack: [`src/trunk/webpack.config.js`](../src/trunk/webpack.config.js)
