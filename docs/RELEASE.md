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
| **Serviço Docker** | `wordpress` |

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
| Docker em execução com container `wordpress` | `docker compose ps` |
| Branch `main` limpa (sem changes pendentes) | `git status` |
| `readme.txt` atualizado com changelog da nova versão | Ver seção acima |
| Todos os testes passando | `./scripts/release.sh ... --no-zip` primeiro |
| Versão nova definida (semver `X.Y.Z`) | Ver seção "Determinando a Próxima Versão" |
| Credenciais SVN configuradas (se for submeter ao WP.org) | Ver seção "Configurando Credenciais SVN" abaixo |

> **Por que Docker?** O projeto roda em container (WordPress + PHP + Node.js + Composer). O script executa o `npm run build` e o `composer install --no-dev` dentro do container para garantir que o ambiente de build é idêntico ao ambiente de execução do plugin.

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
| `--svn` | SVN desligado por padrão | Para preparar o trunk do repositório SVN do WP.org |
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
6. **Composer de produção (no container)** — `composer install --no-dev --optimize-autoloader --prefer-dist` no build dir via `docker compose run`. Resultado: vendor sem dependências de desenvolvimento e com autoloader classmap otimizado.
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

##### Executando o Release via SVN

Use a flag `--svn` no script, que automatiza o checkout e a cópia:

```bash
./scripts/release.sh \
  -v 1.2.0 \
  -s paycrypto-me-for-woocommerce \
  --no-build \
  --no-tests \
  --svn
```

> `--no-build` e `--no-tests` aqui porque o zip já foi validado na etapa anterior. Esta execução apenas prepara o diretório SVN.

O script fará o checkout do repositório SVN em um diretório temporário, copiará o build para `svn-checkout/trunk/` e exibirá o caminho. Em seguida, você executa manualmente:

```bash
cd /caminho/exibido/svn-checkout

# Verificar o que vai ser enviado
svn status

# Adicionar arquivos novos (arquivos modificados já estão marcados)
svn add --force .

# Criar tag da versão no SVN (WP.org usa tags SVN para cada versão)
svn cp trunk tags/1.2.0

# Commitar tudo (solicitará suas credenciais do WP.org se não estiverem em cache)
svn commit -m "Release 1.2.0"
```

Após o commit SVN, o WP.org processa a nova versão em alguns minutos e ela aparece disponível para atualização nos sites que têm o plugin instalado.

##### Enviando banner, ícone e screenshots (`src/assets/`) ao SVN

A pasta `assets/` do SVN (banner, ícone e screenshots exibidos na página do plugin no WP.org) é
**independente** do ciclo de release de código: não faz parte do build (`trunk/`) e por isso
`scripts/release.sh` não mexe nela. Só é preciso repetir estes passos quando os arquivos em
`src/assets/` realmente mudarem (não é obrigatório em todo release):

```bash
# 1. O checkout do --svn já existe em /caminho/exibido/svn-checkout (populado só com trunk/)
# Copiar os assets atuais do repositório para a pasta assets/ do checkout SVN
cp src/assets/* /caminho/exibido/svn-checkout/assets/

cd /caminho/exibido/svn-checkout

# 2. Verificar o que vai ser enviado
svn status assets/

# 3. Adicionar arquivos novos/alterados
svn add --force assets/

# 4. Commitar (pode ser no mesmo commit do trunk/tags, se ambos mudaram na mesma sessão,
#    ou em um commit separado se só os assets mudaram sem bump de versão de código)
svn commit -m "Update plugin assets (banner/icon/screenshots)"
```

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
| Atualizar screenshots no WP.org | Upload manual no painel do plugin |
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

### Container Docker não está rodando

```
[ERROR] Docker service 'wordpress' is not running.
```

**Solução:**
```bash
docker compose up -d
# Aguardar o container inicializar (~10s) e tentar novamente
```

---

### npm build falha no container

**Diagnóstico:**
```bash
# Verificar logs do container
docker compose logs wordpress

# Testar o build manualmente no container
docker compose exec -w /var/www/html/wp-content/plugins/paycrypto-me-for-woocommerce wordpress bash -c "npm ci && npm run build"
```

---

### Composer falha no build dir (dependências privadas)

O projeto usa dependências de repositórios privados GitHub (`lucas-rosa95/bitcoin-php`). Se o container não tiver acesso ao GitHub, o `composer install` falhará.

**Solução:** Garantir que o container tem acesso à internet e que `composer.lock` está atualizado:
```bash
# No host, atualizar o lock file
docker compose exec -w /var/www/html/wp-content/plugins/paycrypto-me-for-woocommerce wordpress bash -c "composer update --lock"
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
[ ] zip submetido ao WordPress.org (upload manual ou SVN)
[ ] Nova versão visível na página do plugin no WP.org
```

---

## Referências

- [WordPress Plugin Developer Handbook — Releasing Your Plugin](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/)
- [Guia de Traduções do Plugin](./TRANSLATION.md)
- Script de release: [`scripts/release.sh`](../scripts/release.sh)
- Configuração webpack: [`src/trunk/webpack.config.js`](../src/trunk/webpack.config.js)
