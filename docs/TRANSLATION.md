# 🌍 PayCrypto.Me Translation Guide

Este guia explica como gerenciar as traduções do plugin PayCrypto.Me for WooCommerce.

## 🚀 Fluxo Canônico

**Sempre rodar a partir da raiz do repositório** (não de dentro de `src/trunk/`), com o container
`wordpress` do `docker-compose.yml` no ar:

```bash
docker compose up -d wordpress   # se ainda não estiver rodando

# Gerar/atualizar tudo (POT + PO via msgmerge + MO) para os 7 locales
./scripts/build-translations.sh

# Comandos específicos
./scripts/build-translations.sh pot           # só o template
./scripts/build-translations.sh po pt_BR      # só um locale (PO)
./scripts/build-translations.sh mo pt_BR      # só compilar o MO de um locale

# Script rápido (mesma função, interface simplificada)
./scripts/quick-translate.sh
```

> **⚠️ `npm run translate` está QUEBRADO hoje.** O `package.json` referencia
> `./scripts/build-translations.sh`, mas `scripts/` fica na **raiz do repo**, não em
> `src/trunk/scripts/` (onde o `package.json` vive) — então rodar via `npm run` (cwd =
> `src/trunk/`) faz o script não encontrar a si mesmo. Use sempre o script direto do repo root,
> como acima. Isso vale para `npm run translate:quick`, `:pot`, `:po`, `:mo` também.

**Por que isso importa:** o script inteiro roda dentro do container via `docker compose exec`
(`wp i18n make-pot`, `msgmerge`, `msgfmt` — tudo com `docker_exec()` internamente). O `docker
compose` do host só encontra o `docker-compose.yml` quando o comando é executado da raiz do
repo — daí a exigência de cwd.

## 📁 Estrutura de Arquivos

```
src/trunk/languages/
├── paycrypto-me-for-woocommerce.pot        # Template (gerado automaticamente)
├── paycrypto-me-for-woocommerce-pt_BR.po   # Tradução Português (Brasil)
├── paycrypto-me-for-woocommerce-pt_BR.mo   # Compilado Português (Brasil)
├── paycrypto-me-for-woocommerce-es_ES.po   # Tradução Espanhol
├── paycrypto-me-for-woocommerce-es_ES.mo   # Compilado Espanhol
└── ...                                      # de_DE, fr_FR, it_IT, ru_RU, zh_CN (mesmo padrão)
```

Não existe `en_US.po`/`.mo`: inglês é o idioma-fonte das strings no código (`__('...',
'paycrypto-me-for-woocommerce')`), não precisa de arquivo de tradução próprio.

## 🎯 O que entra (e o que NÃO entra) no catálogo

Nem toda string do plugin é traduzível — a decisão é por **quem lê**, não por onde a string está no
código. Regra vigente:

| Categoria | Traduzir? | Exemplos |
|---|---|---|
| Qualquer string vista pelo **cliente** | ✅ **sempre** | template de order-details ("Awaiting Payment", "Pay Using Wallet"), mensagens de falha de pagamento, título/descrição do gateway no checkout, memo do BIP21 |
| **Settings** do painel: títulos, descrições, rótulos de campo, textos de botão, badges | ✅ sim | "BTCPay Server URL", "Invoice Expiry", "Danger Area", "🔌 Test connection", "Premium · Coming soon" |
| **Erros, warnings e logs** do painel admin | ❌ **não** — inglês literal | `WC_Admin_Settings::add_error(…)`, `wp_die('Security check failed')`, avisos `admin_notices`, `register_paycrypto_me_log(…)` |
| Retorno dos **botões de diagnóstico** (sucesso *e* falha) | ❌ não | "Connection OK (HTTP %d).", "Could not reach the server: %s", "Reset request received." |
| **Notas de pedido** (`add_order_note`, nota de mudança de status) | ❌ não | "PayCrypto.Me payment initiated…", "Awaiting cryptocurrency payment" |

**Por quê:** cada string traduzível custa 7 traduções e vive para sempre no catálogo. Erro de painel
é lido pelo lojista — quase sempre para repassar à hospedagem ou ao suporte —, e em inglês ele é
pesquisável e reportável. Nota de pedido é gravada **no banco** no idioma vigente na hora da escrita:
o histórico de um pedido antigo não acompanha uma troca de idioma depois, então português nele é uma
inconsistência permanente, não uma tradução.

**Exceção deliberada — rótulo dentro de mensagem:** quando um erro em inglês interpola o nome de um
campo, o **rótulo continua traduzido**, porque ele é uma string de settings e o `msgid` existe no
catálogo de qualquer jeito (vem do formulário). O resultado é uma frase em inglês com o rótulo no
idioma do painel, igual ao que o lojista vê no campo logo acima:

```php
// frase = admin error (literal) | rótulo = settings (traduzido)
\WC_Admin_Settings::add_error(sprintf('%s must use HTTPS.', esc_html__('BTCPay Server URL', 'paycrypto-me-for-woocommerce')));
```

**Consequências práticas ao escrever código novo:**

- String de erro/warning/log do admin: literal em inglês, **sem** `__()` e **sem** comentário
  `/* translators: */` (o comentário só existe para o gettext, que não olha mais para ela).
- O escape continua obrigatório onde havia: `esc_html__()` virou literal, não "literal sem escape" —
  se o valor for interpolado em HTML, escape os **argumentos** (`esc_html($alias)`), como antes.
- Ao mover uma string entre categorias, rodar o fluxo canônico e conferir as estatísticas: o número
  de mensagens por locale tem que bater com a mudança esperada.

### Entradas obsoletas (`#~`)

Quando um `msgid` deixa de existir no código, o `msgmerge` **não** apaga a tradução: guarda a
entrada comentada com `#~` no fim do `.po`. Isso nunca chega ao `.mo`, mas incha o `.po` e o PoEdit
mostra essas linhas como "obsoletas" — inclusive strings tiradas do catálogo de propósito, que não
devem voltar a ser oferecidas ao tradutor.

Por isso `create_po_file()` roda `msgattrib --no-obsolete` logo depois do `msgmerge`, dentro do
próprio script (grava em `.tmp` e só então substitui — `msgattrib` lendo e gravando o mesmo caminho
trunca o arquivo). Se o `msgattrib` não existir no container, o script avisa e mantém as entradas,
em vez de falhar.

O efeito medido quando a regra do catálogo passou a valer (35 strings retiradas, 2026-08-15):

```
antes:  ~850 linhas / ~31 KB por .po, 40 entradas obsoletas
depois: ~625 linhas / ~22 KB por .po,  0 entradas obsoletas
.mo:    byte a byte idêntico  (obsoletas nunca chegavam ao runtime)
```

**Consequência a aceitar:** se uma string retirada voltar ao código, sua tradução antiga não é
ressuscitada pelo `msgmerge` — ela reaparece como `msgstr ""` e precisa ser traduzida de novo (ou
recuperada do histórico do git).

## 🛠️ Ferramentas Recomendadas (para preencher `.po`)

### 1. PoEdit (Desktop)
- **Download**: https://poedit.net/
- **Uso**: Abrir arquivos `.po` para tradução visual
- **Vantagens**: Interface amigável, validação automática, compilação MO

### 2. Loco Translate (WordPress Plugin)
- **Instalação**: WordPress Admin > Plugins > Adicionar Novo > "Loco Translate"
- **Uso**: Admin > Loco Translate > Plugins > PayCrypto.Me
- **Vantagens**: Tradução direto no WordPress, sem arquivos externos

### 3. Editor Manual / agente
- **Arquivos**: Editar `.po` em qualquer editor de texto (`msgid "Original"` → `msgstr
  "Tradução"`), depois recompilar o `.mo` (`./scripts/build-translations.sh mo <locale>` ou
  `msgfmt` dentro do container).
- **Regra:** o script (`build-translations.sh`) é sempre quem regenera a estrutura — POT,
  merge do PO via `msgmerge`, headers/Plural-Forms, compilação do MO. Nunca contornar isso com
  comandos gettext ad-hoc. **Preencher o *conteúdo* das entradas vazias/fuzzy que o script deixou
  para trás é diferente** e está liberado: quando pedido (ex.: "use nosso sistema de tradução"),
  o agente traduz essas entradas diretamente, sem precisar de handoff humano/PoEdit antes. Sempre
  validar com `msgfmt --check` antes de recompilar o `.mo`, e preservar placeholders (`%s`,
  `%1$s`, etc.) intactos.

## 📝 Como Adicionar Nova Tradução (novo idioma)

### 1. Criar arquivos para o novo idioma

```bash
./scripts/build-translations.sh po fr_FR
./scripts/build-translations.sh mo fr_FR
```

### 2. Registrar o idioma no script

Editar `scripts/build-translations.sh`, na função `plural_forms_for_locale()` (define o
`Plural-Forms` correto do locale — obrigatório para as entradas `_n()`) e no array de locales
processados por padrão.

## 🔄 Workflow de Tradução

### Para Desenvolvedores

1. **Adicionar novas strings** sempre com o text domain literal:
   ```php
   __('New string', 'paycrypto-me-for-woocommerce')
   esc_html__('Safe string', 'paycrypto-me-for-woocommerce')
   ```

2. **Rodar o fluxo canônico** (da raiz do repo, container no ar):
   ```bash
   ./scripts/build-translations.sh
   ```
   Isso regenera o `.pot`, faz `msgmerge` em cada `.po` (strings novas entram como `msgstr ""` —
   **vazias**, ou `fuzzy` quando o msgid mudou ligeiramente de um já traduzido) e recompila os
   `.mo`. **O script NÃO traduz** — só atualiza a estrutura. A tradução em si é um passo separado.

3. **Identificar o que ficou pendente** (por locale):
   ```bash
   docker compose exec -w /var/www/html/wp-content/plugins/paycrypto-me-for-woocommerce/languages \
     wordpress bash -c 'for f in *.po; do printf "%-45s " "$f"; msgfmt --statistics -o /dev/null "$f"; done'
   ```
   Repassar as strings `untranslated`/`fuzzy` para tradução (PoEdit/Loco, ou preenchimento
   direto quando explicitamente pedido — ver seção "Editor Manual / agente" acima).

### Para Tradutores

1. **Abrir arquivo PO** no PoEdit ou Loco Translate
2. **Traduzir strings** vazias (`msgstr ""`) e revisar as marcadas `fuzzy`
3. **Salvar arquivo** (PoEdit compila MO automaticamente; senão, `./scripts/build-translations.sh mo <locale>`)
4. **Testar** mudando idioma do WordPress

## 🎯 Boas Práticas

### ✅ Fazer
- Conferir a seção "O que entra (e o que NÃO entra) no catálogo" **antes** de embrulhar uma string
  nova em `__()` — erro/warning/log de painel fica em inglês literal
- Usar sempre text domain: `'paycrypto-me-for-woocommerce'`
- Rodar `./scripts/build-translations.sh` da raiz do repo após adicionar strings
- Testar traduções em diferentes idiomas
- Manter traduções curtas e claras
- Validar `.po` com `msgfmt --check` antes de recompilar o `.mo`

### ❌ Evitar
- Strings hardcoded sem tradução **onde o cliente lê** (no painel, erro/warning/log é hardcoded de
  propósito — ver a seção do catálogo)
- Text domain incorreto ou ausente
- Concatenação de strings traduzidas
- Tradução de strings de debug/desenvolvimento
- Rodar via `npm run translate` (quebrado — ver aviso acima)
- Inventar comandos `xgettext`/`msgmerge`/`msgfmt` ad-hoc fora do script

## 🔧 Dependências

**Nenhuma ferramenta de tradução precisa estar instalada no host** (nem `gettext`, nem `wp-cli`,
nem PHP) — o script inteiro roda dentro do container `wordpress`, que já as tem. Único requisito
do host: Docker, com o container no ar (`docker compose up -d wordpress`).

Dentro do container (já provisionado pela imagem do projeto):
- **WP-CLI** (preferencial para o POT): `wp i18n make-pot`
- **xgettext** (fallback se WP-CLI falhar)
- **msgmerge** / **msgfmt** (merge de PO e compilação de MO)

## 🐛 Solução de Problemas

### `npm run translate` falha / não encontra o script
Esperado — ver aviso na seção "Fluxo Canônico". Rode `./scripts/build-translations.sh` direto da
raiz do repo.

### Erro: "docker compose: command not found" ou não encontra `docker-compose.yml`
Confirme que está rodando da **raiz do repositório**, não de `src/trunk/` nem `scripts/`.

### Erro: container não está rodando
```bash
docker compose up -d wordpress
```

### Traduções não aparecem no site
1. Verificar se o `.mo` existe e foi recompilado após editar o `.po`
2. Verificar `Domain Path: /languages/` no cabeçalho do plugin
3. Verificar que `load_plugin_textdomain()` está registrado (hook `init`, em
   `paycrypto-me-for-woocommerce.php`)
4. Limpar cache do WordPress

### Strings não aparecem no POT
1. Verificar se usam funções de tradução corretas (`__()`, `_e()`, `esc_html__()`, etc.) com
   text domain literal (não variável)
2. Regenerar: `./scripts/build-translations.sh pot`

## 📊 Status Atual

- ✅ Text Domain configurado: `paycrypto-me-for-woocommerce`
- ✅ Domain Path: `/languages/`
- ✅ `load_plugin_textdomain()` registrado no hook `init` (ver `paycrypto-me-for-woocommerce.php`)
- ✅ Strings usando funções corretas de tradução
- ✅ Idiomas traduzidos (116/116 strings, 100% em 2026-08-15): `pt_BR`, `es_ES`, `de_DE`, `fr_FR`,
  `it_IT`, `ru_RU`, `zh_CN`. O total de strings muda conforme o código evolui — rodar o comando de
  estatísticas do Workflow acima para o número atual antes de assumir 100%. (Eram 151 antes de
  erros/warnings/logs do painel saírem do catálogo — ver a seção "O que entra".)
- ✅ `.po` sem entradas obsoletas: o script roda `msgattrib --no-obsolete` a cada merge (ver
  "Entradas obsoletas" acima).

## 🤝 Contribuindo com Traduções

Para contribuir com traduções:

1. Fork do repositório
2. Criar/atualizar arquivo de tradução
3. Testar tradução
4. Enviar Pull Request

Ou usar plataforma de tradução online (se configurada futuramente).
