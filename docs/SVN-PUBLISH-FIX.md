# 📡 Publicação SVN no WordPress.org — diagnóstico e plano de correção

> **Status:** plano **completo e verificado** — Fases 1, 2, 3 e 4 todas concluídas. O primeiro push
> real aconteceu em 2026-08-08: `trunk@3638906` + `tags/0.1.0@3638912`, conteúdo conferido
> byte-a-byte contra o zip aprovado via `svn export` + `diff`. A página pública do plugin
> (`https://wordpress.org/plugins/paycrypto-me-for-woocommerce`) deve indexar em até 72h.
>
> **Incidente durante o push real (registrado para os próximos releases):** o `svn commit` do
> passo 3c reportou `E000002: Can't open file '.../db/transactions/NNNNNNN-xxxxx.txn/props'` —
> um erro **do lado do servidor** do WP.org, não do script. A transação **tinha sido persistida
> mesmo assim** (`Last Changed Rev` do trunk batia com o commit, conteúdo idêntico ao zip); só a
> confirmação de sucesso não voltou ao cliente. Como o script usa `set -e`, ele abortou antes da
> etapa de tag — exatamente o cenário coberto por "Se falhar no meio" (Fase 3, abaixo). A correção
> foi rodar o mesmo `--svn-commit` de novo: o script resetou o working copy, viu que não havia nada
> a commitar e criou só a tag. Nenhuma ação manual além de repetir o comando.
>
> Documento relacionado: [RELEASE.md](RELEASE.md) — a seção SVN já reflete o fluxo corrigido.

**Ordem de execução obrigatória: Fase 1 → Fase 2 → Fase 3 → Fase 4.** A Fase 2 (ensaio offline) não
é opcional: ela é o único teste real das Fases 1, já que nada disso pode ser exercitado contra o
WP.org sem publicar de verdade. Fases 1, 2 e 4 são inteiramente offline e podem ser feitas por um
agente. **A Fase 3 é do mantenedor** (senha pessoal + ação pública irreversível).

Ambiente verificado: `svn` 1.14.5, `unzip`, `rsync` presentes; GNU coreutils (WSL/Linux).

---

## Contexto

O plugin foi **aprovado** no WordPress.org em 2026-08-04 (slug `paycrypto-me-for-woocommerce`,
usuário SVN `paycryptome`). A página pública não existe até o primeiro `svn commit`. A tag git
`v0.1.0` já está no GitHub; falta só o SVN.

O caminho documentado (`release.sh --svn`) **não funciona e nunca foi exercitado** — este seria o
primeiro release do projeto. Uma auditoria encontrou 1 bug fatal, 1 modo de perda silenciosa de
dados e ~10 defeitos menores.

### Defeitos encontrados

| # | Defeito | Onde |
|---|---|---|
| 1 | O working copy do SVN é criado **dentro** do `mktemp` que o `trap EXIT` apaga → o caminho que o script imprime já não existe quando o shell volta | `release.sh:393` + `:262` |
| 2 | `svn cp trunk tags/0.1.0` com a tag **já existente** não dá erro: cria `tags/0.1.0/trunk/` (`svn help copy`, verbatim: *"If DST is an existing directory, the sources will be added as children of DST"*) → release quebrada, commit sem erro | design |
| 3 | `!` no `svn status` significa "missing" **ou "incomplete"** → um `svn update` interrompido faria o sweep agendar `svn rm` de **todas as tags publicadas** | design |
| 4 | `grep '^!'` sai 1 quando nada falta → sob `set -o pipefail` (`:2`) aborta o script **depois** do working copy já ter sido mutado | design |
| 5 | `--no-zip` **não** pula o Composer (`:257-353` estão fora do guard `DO_ZIP`) → o publish documentado re-resolve os forks privados `lucas-rosa95/*` e reconstrói o zip aprovado | `release.sh:257-369` |
| 6 | `ZIP_PATH` só é definido dentro do bloco `DO_ZIP` → `unbound variable` sob `set -u` justamente no fluxo publish-only | `release.sh:359` |
| 7 | `rm -f "$ZIP_PATH"` roda **fora** do guard de dry-run → `--dry-run` destrói um zip já construído | `release.sh:360` |
| 8 | `svn revert -R` deixa arquivos schedule-add no disco (precisa `--remove-added`, svn 1.11+) | design |
| 9 | `svn add` respeita `global-ignores` (`*.a *.so *~ .DS_Store …`) → arquivo do payload sumiria silenciosamente | design |
| 10 | auto-props do operador (`~/.subversion/config`) aplicaria `svn:eol-style`/`svn:keywords` e alteraria os bytes publicados | design |
| 11 | `svn checkout ... \|\| true` mascara falha → rsync cria um dir sem `.svn` que *parece* preparado, e o script sai 0 dizendo "finished successfully" | `release.sh:397-401` |
| 12 | `find "$svn_dir/trunk" -mindepth 1 -delete` remove do disco sem `svn rm` → deleções nunca entram no commit | `release.sh:399` |

### Premissa corrigida

O WP.org **não redistribui o zip** — ele *reconstrói* o download a partir da tag SVN. O requisito é
fidelidade de **conteúdo dos arquivos**, não do arquivo `.zip`. Mesmo assim o zip aprovado é a fonte
da verdade certa: re-rodar `composer install` contra os forks privados (`lucas-rosa95/bitcoin`,
`bitwasp/buffertools`) é risco real de divergência ou falha de resolução.

---

## Decisões de design

- **Fonte da verdade = o zip aprovado** em `releases/${SLUG}-${VERSION}.zip`, nunca o `BUILD_DIR`.
- **Working copy persistente** em `releases/svn/` — fora do `mktemp`, já coberto por `.gitignore:112`
  (`releases/*`). Staging em `releases/.svn-stage/`, limpo no **início** de cada run (sem trap, para
  continuar inspecionável depois de uma falha).
- **`--svn` implica publish-only.** Passar `--svn` sem `--no-build --no-tests --no-zip` é **erro
  duro** com a mensagem do comando correto. Isso elimina a ambiguidade de "quais seções pular".
- **Commit opt-in:** `--svn` prepara e mostra o resumo; `--svn-commit` faz o ciclo inteiro. O gate de
  revisão é a última proteção contra o defeito #3 e fica permanente.
- **Tag por cópia server-side, em 2 revisões** (`svn copy $URL/trunk@REV $URL/tags/$VERSION`).
  Um `svn cp` local com mods pendentes re-envia todos os ~820 arquivos (só arquivos *não modificados*
  viajam de graça) e depende de semântica não testável offline. A cópia server-side custa 0 bytes,
  é o fluxo dos docs do WP.org, e não encosta em `tags/` no working copy.
- **`assets/` espelhado com `--delete`** — `src/assets/` é a fonte da verdade; deleções aparecem no
  resumo antes do commit.
- **`SVN_URL` sobrescrevível por env** — habilita o ensaio offline (Fase 2). Acompanhado de uma
  **assertiva de URL** no working copy (ver `svn_publish`), sem a qual um ensaio rodado depois de um
  run real reusaria silenciosamente um WC apontado para o WP.org de verdade.

---

## Fase 1 — Consertar `scripts/release.sh`

> **Nota de leitura:** todas as referências `release.sh:NNN` desta seção apontam para o script
> **antes** da correção — servem para localizar o que foi mudado no histórico, não para navegar o
> arquivo atual (que ficou maior). Para o estado atual, leia `scripts/release.sh` direto.

Reaproveitar o que já existe: os helpers `log/warn/error/header/step` (`:16-20`), o guard
`if [[ $DRY_RUN -eq 0 ]]`, e o idiom `mkdir -p "$ROOT_DIR/releases"` ancorado em `ROOT_DIR` (`:358`).

### 1.1 — `ZIP_PATH` para a seção PATH SETUP

Mover para junto de `PLUGIN_FILE`/`README_FILE` (~`:129`), porque o fluxo publish-only precisa dele
sem entrar no bloco do zip:

```bash
ZIP_PATH="$ROOT_DIR/releases/${SLUG}-${VERSION}.zip"
```

O bloco do zip (`:359`) passa a **reusar** a variável em vez de redefini-la, e o log final (`:411`)
passa a usar `$ZIP_PATH` em vez de re-interpolar a string.

### 1.2 — Flag `--svn-commit`

No parsing (`:70-84`), junto de `--svn`; default `DO_SVN_COMMIT=0` ao lado dos outros (`:60-68`):

```bash
--svn)        DO_SVN=1; shift;;
--svn-commit) DO_SVN=1; DO_SVN_COMMIT=1; shift;;
```

Em `show_help` (`:47`):

```
  --svn           Prepare the SVN working copy from the approved zip (no commit)
  --svn-commit    Same as --svn, then commit trunk/assets and create the SVN tag
```

### 1.3 — `--svn` implica publish-only

Logo após a validação de semver (`:96`):

```bash
# Publicar re-rodando o build re-resolveria os forks privados do Composer e
# divergiria dos bytes aprovados. --svn publica o zip que já existe, ponto.
if [[ $DO_SVN -eq 1 && ( $DO_BUILD -eq 1 || $DO_TESTS -eq 1 || $DO_ZIP -eq 1 ) ]]; then
  error "--svn/--svn-commit publishes the already-built zip and must not rebuild it."
  error "Re-run with: --no-build --no-tests --no-zip"
  exit 1
fi
PUBLISH_ONLY=$DO_SVN
```

Envolver em `if [[ $PUBLISH_ONLY -eq 0 ]]; then … fi` **tudo** de `=== VERSION BUMPS ===` (`:213`)
até o fim de `=== ZIP ===` (`:369`) — isto é: bump, `mktemp`+`trap`, rsync, Composer, vendor cleanup
e zip. Consequência importante: em run publish-only o `BUILD_DIR` **não é criado** e o `trap` **não é
registrado** (nada de `unbound variable`); por isso `svn_publish()` faz seu próprio
`mkdir -p "$ROOT_DIR/releases"`.

### 1.4 — Bug de dry-run apagando o zip

Em `:358-360`, `rm -f "$ZIP_PATH"` está fora do guard. Mover para dentro do
`if [[ $DRY_RUN -eq 0 ]]` que começa em `:362`.

### 1.5 — Substituir o bloco `--svn` (`:390-407`)

Código completo, para colar no lugar do bloco atual. **Não é pseudo-código** — todas as flags foram
verificadas contra `svn` 1.14.5.

```bash
# === SVN PUBLISH (WordPress.org) ===
# A fonte da verdade é o ZIP APROVADO, nunca o BUILD_DIR efêmero.
svn_publish() {
  local payload bad left st rev existing wc_url out
  local containers=()

  mkdir -p "$ROOT_DIR/releases"

  # ---- 1. working copy esparso: checkout novo, ou reset de um existente ----
  if [[ -e "$SVN_DIR" ]] && ! svn info "$SVN_DIR" >/dev/null 2>&1; then
    error "$SVN_DIR existe mas não é um working copy SVN."
    error "Remova e rode de novo:  rm -rf \"$SVN_DIR\""
    return 1
  fi

  if svn info "$SVN_DIR" >/dev/null 2>&1; then
    # Sem esta assertiva, um ensaio (SVN_URL=file://...) rodado depois de um run
    # real reusaria um WC apontado para o plugins.svn.wordpress.org de verdade —
    # e com --svn-commit publicaria lá achando que estava ensaiando.
    wc_url="$(svn info --show-item url --no-newline "$SVN_DIR")"
    if [[ "$wc_url" != "$SVN_URL" ]]; then
      error "O working copy em $SVN_DIR aponta para:"
      error "  $wc_url"
      error "mas SVN_URL é:"
      error "  $SVN_URL"
      error "Remova e rode de novo:  rm -rf \"$SVN_DIR\""
      return 1
    fi
    step "Resetando o working copy para o estado pristino"
    svn cleanup "$SVN_DIR"                                        # solta locks
    svn revert -R --remove-added "$SVN_DIR"                       # mods E schedule-adds
    svn cleanup --remove-unversioned --remove-ignored "$SVN_DIR"  # cruft
    svn update "$SVN_DIR" "${SVN_RO[@]}"                          # cura '!' incomplete
  else
    step "Checkout esparso de $SVN_URL"
    svn checkout --depth immediates "$SVN_URL" "$SVN_DIR" "${SVN_RO[@]}"
  fi

  # trunk PRECISA estar completo: um trunk esparso commitaria deleções falsas.
  if [[ ! -d "$SVN_DIR/trunk" ]]; then
    error "Não há trunk/ em $SVN_URL — slug errado?"; return 1
  fi
  svn update --set-depth infinity "$SVN_DIR/trunk" "${SVN_RO[@]}"
  if [[ "$(svn info --show-item depth --no-newline "$SVN_DIR/trunk")" != "infinity" ]]; then
    error "trunk/ não está em depth=infinity; abortando."; return 1
  fi

  if [[ -d "$SVN_DIR/assets" ]]; then
    svn update --set-depth infinity "$SVN_DIR/assets" "${SVN_RO[@]}"
  else
    warn "assets/ ausente no repositório — será criado por este commit."
    mkdir -p "$SVN_DIR/assets"
    svn add --depth empty "$SVN_DIR/assets"
  fi
  # tags/ fica em depth=empty de propósito: a tag é criada server-side, então
  # nada local consegue aninhar dentro de um tags/<versão>/ já existente.

  # ---- 2. payload vem do ZIP APROVADO -------------------------------------
  step "Extraindo $ZIP_PATH"
  rm -rf "$SVN_STAGE"; mkdir -p "$SVN_STAGE"
  unzip -q "$ZIP_PATH" -d "$SVN_STAGE"
  payload="$SVN_STAGE/$SLUG"
  if [[ ! -d "$payload" ]]; then
    error "O zip não tem o diretório de topo '$SLUG/'."; return 1
  fi
  # O zip precisa ser o artefato DESTA versão, senão publicaríamos um readme cujo
  # Stable tag discorda da tag que estamos prestes a criar.
  if ! grep -qE "^Stable tag:[[:space:]]*${VERSION}[[:space:]]*$" "$payload/readme.txt"; then
    error "readme.txt do zip não declara 'Stable tag: $VERSION'."; return 1
  fi
  if ! grep -qE "^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*${VERSION}[[:space:]]*$" \
                "$payload/${SLUG}.php"; then
    error "Header do plugin no zip não é 'Version: $VERSION'."; return 1
  fi

  # ---- 3. espelhar payload em trunk/ e assets/ ----------------------------
  # -c: compara por checksum, então arquivos iguais mantêm mtime e o svn status
  # fica O(mudanças). --exclude='.svn/': no-op hoje (svn 1.7+ mantém um único
  # .svn na raiz do WC, que está ACIMA de trunk/) — mantido como seguro caso
  # alguém reancore o WC em trunk/. NUNCA adicionar --delete-excluded.
  step "Espelhando payload -> trunk/"
  rsync -a -c --delete --exclude='.svn/' "$payload/" "$SVN_DIR/trunk/"
  step "Espelhando src/assets -> assets/"
  rsync -a -c --delete --exclude='.svn/' "$ASSETS_SRC/" "$SVN_DIR/assets/"

  # ---- 4. reconciliar metadados do SVN com o filesystem -------------------
  # GUARD PRIMEIRO: '!' também significa "incomplete" (svn help status), então um
  # update interrompido faria o sweep abaixo agendar a deleção de TODAS as tags.
  containers=( "$SVN_DIR" "$SVN_DIR/trunk" "$SVN_DIR/assets" )
  if [[ -d "$SVN_DIR/tags" ]];     then containers+=( "$SVN_DIR/tags" ); fi
  if [[ -d "$SVN_DIR/branches" ]]; then containers+=( "$SVN_DIR/branches" ); fi
  bad="$( svn status --depth empty "${containers[@]}" | { grep -E '^[!~]' || true; } )"
  if [[ -n "$bad" ]]; then
    error "Working copy inconsistente (dirs de topo missing/obstructed):"
    printf '%s\n' "$bad" >&2
    error "Remova e rode de novo para um checkout limpo:  rm -rf \"$SVN_DIR\""
    return 1
  fi

  # Deleções: '!' = versionado mas sumiu do disco. ESCOPADO a trunk+assets.
  # O '|| true' é obrigatório: grep sai 1 quando nada falta, e pipefail
  # transformaria isso em abort DEPOIS do WC já ter sido mutado.
  # (xargs -r -d é GNU — ok em Linux/WSL, não em macOS/BSD.)
  step "Agendando deleções"
  ( cd "$SVN_DIR" \
    && svn status trunk assets | { grep '^!' || true; } | cut -c9- \
       | xargs -r -d '\n' svn rm --force )

  # Adições: alvos explícitos apenas — nunca `svn add .` na raiz do WC, que
  # entraria nos tags/<versão-antiga>/ depth-empty e colidiria no commit.
  # --no-ignore: global-ignores (*.a *.so *~ .DS_Store ...) descartaria arquivos
  # do payload em silêncio. --no-auto-props: bytes publicados verbatim.
  step "Agendando adições"
  svn add --force --no-ignore --no-auto-props --depth infinity -q \
          "$SVN_DIR/trunk" "$SVN_DIR/assets"

  # Pós-condição: nada não-reconciliado pode sobreviver (?=unversioned,
  # !=missing, ~=obstructed, C=conflito).
  left="$( svn status "$SVN_DIR/trunk" "$SVN_DIR/assets" \
           | { grep -E '^[?!~]|^.C|^.{6}C' || true; } )"
  if [[ -n "$left" ]]; then
    error "Estado não reconciliado no working copy:"
    printf '%s\n' "$left" >&2
    return 1
  fi

  # ---- 5. gate de revisão -------------------------------------------------
  header "Mudanças SVN pendentes para $VERSION"
  st="$(svn status -q "$SVN_DIR")"
  if [[ -z "$st" ]]; then
    warn "Nenhuma mudança versionada: trunk/ e assets/ já batem com este release."
  else
    # Nada de `| head`: SIGPIPE (141) + pipefail abortaria com o WC já preparado.
    awk '{print substr($0,1,2)}' <<<"$st" | sort | uniq -c | sort -rn
    awk 'NR<=30' <<<"$st"
    log "$(wc -l <<<"$st") caminho(s) alterado(s)"
  fi

  if [[ $DO_SVN_COMMIT -eq 0 ]]; then
    log "Nada commitado (gate de revisão)."
    log "Inspecionar:  (cd $SVN_DIR && svn status)"
    log "Publicar:     $0 -v $VERSION -s $SLUG --no-build --no-tests --no-zip --svn-commit"
    return 0
  fi

  # ---- 6. commit de trunk+assets, depois tag por cópia server-side --------
  # A tag NÃO pode existir: `svn help copy` — "If DST is an existing directory,
  # the sources will be added as children of DST" — então re-taggear criaria
  # tags/$VERSION/trunk/ silenciosamente em vez de falhar. Capturar antes, para
  # que uma falha do `svn ls` aborte em vez de ser engolida pelo exit do grep.
  existing="$(svn ls "$SVN_URL/tags/" "${SVN_RO[@]}")"
  if printf '%s\n' "$existing" | grep -qx "${VERSION}/"; then
    error "tags/$VERSION já existe em $SVN_URL."
    error "Tags do WP.org são imutáveis por convenção — bumpe a versão e rode de novo."
    return 1
  fi

  step "Commitando trunk/ + assets/"
  out="$(LC_ALL=C svn commit "$SVN_DIR" -m "Release $VERSION" "${SVN_RW[@]}" | tee /dev/stderr)"
  rev="$(sed -n 's/^Committed revision \([0-9]\{1,\}\)\.$/\1/p' <<<"$out" | tail -n1)"
  if [[ -z "$rev" ]]; then
    # Nada a commitar — acontece ao re-rodar depois de um `svn copy` que falhou.
    # Taggeia o trunk atual em vez de morrer com uma revisão vazia.
    rev="$(svn info --show-item revision --no-newline "$SVN_URL/trunk" "${SVN_RO[@]}")"
    warn "Nada a commitar; taggeando o trunk existente @$rev."
  fi

  step "Taggeando trunk@$rev -> tags/$VERSION (cópia server-side, 0 bytes)"
  svn copy "$SVN_URL/trunk@$rev" "$SVN_URL/tags/$VERSION" \
           -m "Tag $VERSION (copy of trunk@$rev)" "${SVN_RW[@]}"

  svn update "$SVN_DIR" "${SVN_RO[@]}" >/dev/null
  log "Publicado $VERSION: trunk@$rev + tags/$VERSION"
}

if [[ $DO_SVN -eq 1 ]]; then
  header "SVN publish -> WordPress.org"

  SVN_URL="${SVN_URL:-https://plugins.svn.wordpress.org/${SLUG}}"  # env-overridable p/ ensaio
  SVN_DIR="$ROOT_DIR/releases/svn"            # persistente, gitignored, FORA do BUILD_DIR
  SVN_STAGE="$ROOT_DIR/releases/.svn-stage"   # limpo no início do run
  ASSETS_SRC="$ROOT_DIR/src/assets"           # NÃO src/trunk/assets (esse é runtime, vai no zip)
  SVN_USER="${SVN_USER:-paycryptome}"

  # Fixar auto-props=no torna os bytes publicados independentes do
  # ~/.subversion/config do operador (que poderia aplicar svn:eol-style /
  # svn:keywords). RO pode rodar desassistido; RW precisa poder pedir a senha,
  # por isso NÃO leva --non-interactive.
  SVN_RO=( --username "$SVN_USER" --non-interactive
           --config-option "config:miscellany:enable-auto-props=no" )
  SVN_RW=( --username "$SVN_USER"
           --config-option "config:miscellany:enable-auto-props=no" )

  for _bin in svn unzip rsync; do
    command -v "$_bin" >/dev/null 2>&1 || { error "'$_bin' não encontrado no PATH."; exit 1; }
  done
  [[ -f "$ZIP_PATH" ]] || { error "Zip aprovado não encontrado: $ZIP_PATH"; exit 1; }
  # Protege contra confundir src/assets (banner/ícone/screenshots do diretório)
  # com src/trunk/assets (assets de runtime, que vão dentro do zip).
  [[ -f "$ASSETS_SRC/banner-1544x500.png" ]] \
    || { error "Assets de diretório do WP.org não encontrados em $ASSETS_SRC"; exit 1; }

  if [[ $DRY_RUN -eq 1 ]]; then
    step "[dry-run] svn checkout --depth immediates $SVN_URL $SVN_DIR (+ set-depth infinity trunk/assets)"
    step "[dry-run] unzip $ZIP_PATH -> $SVN_STAGE; rsync --delete -> trunk/ e assets/"
    step "[dry-run] svn rm (missing) / svn add --force --no-ignore; gate de revisão"
    step "[dry-run] svn commit + svn copy $SVN_URL/trunk@REV -> tags/$VERSION"
  else
    svn_publish
  fi
fi
```

### 1.6 — Notas de implementação

- **`set -euo pipefail` (linha 2)**: todo `grep` em pipeline precisa de `{ grep … || true; }`, e
  **nada de `| head`** dentro do script (SIGPIPE → exit 141 → abort com o WC já mutado). Usar
  `awk 'NR<=30'` sobre variável capturada, como no bloco acima. (Nos comandos *manuais* das Fases 2
  e 3, rodados num shell interativo sem `pipefail`, `| head` é inofensivo.)
- **Evitar `[[ cond ]] && cmd` como statement solto** — sob `set -e`, quando `cond` é falsa a lista
  retorna 1 e o script morre. Usar `if`, como no bloco acima. (`:411` do script atual usa esse padrão
  e só escapa porque `:413` é `exit 0`.)
- `run_shell()` (`:108-115`) é dead code (zero call sites) e já é dry-run aware — remover ou usar.

---

## Fase 2 — Ensaio offline (obrigatório)

Transforma em fato testado o que não dá para verificar sem um repositório real:
`revert --remove-added`, `status` de diretório ausente, `set-depth` em path inexistente, o guard de
re-tag (defeito #2) e a assertiva de URL do working copy.

```bash
# --- reset: TUDO isto é obrigatório para a fase ser re-executável ---
rm -rf /tmp/fake-wporg releases/svn releases/.svn-stage /tmp/vz /tmp/vz-tag
svnadmin create /tmp/fake-wporg
svn mkdir -m init \
  file:///tmp/fake-wporg/trunk \
  file:///tmp/fake-wporg/tags \
  file:///tmp/fake-wporg/branches \
  file:///tmp/fake-wporg/assets

# --- 2a. dry-run não pode escrever nada ---
SVN_URL=file:///tmp/fake-wporg ./scripts/release.sh \
  -v 0.1.0 -s paycrypto-me-for-woocommerce --no-build --no-tests --no-zip --svn --dry-run
[[ ! -e releases/svn ]] && echo "OK: dry-run não criou releases/svn"

# --- 2b. gate de revisão: prepara mas não commita ---
SVN_URL=file:///tmp/fake-wporg ./scripts/release.sh \
  -v 0.1.0 -s paycrypto-me-for-woocommerce --no-build --no-tests --no-zip --svn
svn ls file:///tmp/fake-wporg/tags/    # deve estar VAZIO — nada foi commitado

# --- 2c. publica de verdade (no repo falso) ---
SVN_URL=file:///tmp/fake-wporg ./scripts/release.sh \
  -v 0.1.0 -s paycrypto-me-for-woocommerce --no-build --no-tests --no-zip --svn-commit

# --- 2d. re-run na MESMA versão: deve FALHAR, não aninhar (defeito #2) ---
SVN_URL=file:///tmp/fake-wporg ./scripts/release.sh \
  -v 0.1.0 -s paycrypto-me-for-woocommerce --no-build --no-tests --no-zip --svn-commit \
  && echo "FALHOU: deveria ter recusado re-taggear" \
  || echo "OK: recusou re-taggear"

# --- 2e. assertiva de URL: WC do ensaio não pode ser reusado no repo real ---
./scripts/release.sh -v 0.1.0 -s paycrypto-me-for-woocommerce \
  --no-build --no-tests --no-zip --svn \
  && echo "FALHOU: reusou um WC de outro repositório" \
  || echo "OK: detectou o mismatch de URL"

# --- 2f. flags de build proibidas com --svn ---
SVN_URL=file:///tmp/fake-wporg ./scripts/release.sh \
  -v 0.1.0 -s paycrypto-me-for-woocommerce --svn \
  && echo "FALHOU: deveria exigir --no-build --no-tests --no-zip" \
  || echo "OK: exigiu as flags"

# --- 2g. fidelidade de conteúdo: a tag publicada == o zip aprovado ---
mkdir -p /tmp/vz && unzip -q releases/paycrypto-me-for-woocommerce-0.1.0.zip -d /tmp/vz
svn export -q file:///tmp/fake-wporg/tags/0.1.0 /tmp/vz-tag
diff -r /tmp/vz/paycrypto-me-for-woocommerce /tmp/vz-tag && echo "OK: conteúdo idêntico"

# --- 2h. assets e ausência de aninhamento ---
svn ls file:///tmp/fake-wporg/assets/            # 10 PNGs
svn ls file:///tmp/fake-wporg/tags/0.1.0/        # NÃO pode conter 'trunk/'
```

**Critérios de aceite:** 2a não cria `releases/svn`; 2b deixa `tags/` vazio; 2c cria trunk+assets+tag;
2d/2e/2f saem != 0 com mensagem clara; **2g imprime `OK: conteúdo idêntico` sem nenhuma linha de
diff** (este é o critério mais importante); 2h lista os 10 PNGs e um `tags/0.1.0/` sem `trunk/`
dentro.

**Antes de passar para a Fase 3:** `rm -rf releases/svn releases/.svn-stage` — senão a assertiva de
URL (corretamente) vai barrar o run real.

---

## Fase 3 — Primeiro push real no WP.org

```bash
rm -rf releases/svn releases/.svn-stage      # descarta o WC do ensaio

# 3a. preparar sem commitar
./scripts/release.sh -v 0.1.0 -s paycrypto-me-for-woocommerce --no-build --no-tests --no-zip --svn

# 3b. revisar na mão
(cd releases/svn && svn status | head -40)

# 3c. publicar (pede a senha SVN do wordpress.org — não a do wp-admin)
./scripts/release.sh -v 0.1.0 -s paycrypto-me-for-woocommerce --no-build --no-tests --no-zip --svn-commit
```

Zip a publicar: `releases/paycrypto-me-for-woocommerce-0.1.0.zip` (2.5 MB, 1053 entradas incluindo
diretórios, topo `paycrypto-me-for-woocommerce/`, `Stable tag: 0.1.0` — verificado). **Sem rebuild.**

> A senha é pessoal e o commit é ação pública e irreversível — **a Fase 3 é do mantenedor**.

### Se falhar no meio

O passo 6 são duas revisões. Se o `svn commit` (rev 1) passar e o `svn copy` (rev 2) falhar, o trunk
fica publicado com `Stable tag: 0.1.0` apontando para uma tag inexistente. **Correção: rodar 3c de
novo.** O `svn commit` não encontra nada a commitar, o fallback de `rev` lê a revisão atual do trunk
remoto (`svn info --show-item revision`) e só a cópia server-side é refeita. Nenhuma ação manual.

Se o `svn commit` falhar antes de qualquer escrita, basta corrigir a causa (rede/credencial) e rodar
3c de novo — o reset do working copy no passo 1 cuida do estado sujo.

**Caso real observado em 2026-08-08:** o `svn commit` em si (não o `svn copy` da tag) reportou
`E000002: Can't open file '.../db/transactions/NNNNNNN-xxxxx.txn/props'` — erro do lado do servidor
do WP.org depois que toda a transmissão de arquivos já tinha terminado. A transação **tinha sido
persistida** apesar do erro reportado ao cliente (confirmado via `svn info` no trunk remoto: rev e
autor batiam; conteúdo idêntico ao zip via `svn export` + `diff`). Tratamento: mesmo caso do
parágrafo acima — rodar 3c de novo. Não é um bug do script; é uma falha transitória de
infraestrutura do `plugins.svn.wordpress.org` que pode voltar a acontecer em releases futuros.

---

## Fase 4 — Atualizar `docs/RELEASE.md`

Reescrever a seção SVN (`~:348-426`, pt-BR) que hoje documenta o fluxo quebrado. Corrigir:

- o caminho efêmero → `releases/svn/` persistente;
- **remover** `svn add --force .` na raiz do working copy (`:392`) — com `tags/` esparso isso agenda
  adds dentro de tags antigas → colisão no commit;
- os flags: `--svn` / `--svn-commit`, com `--no-build --no-tests --no-zip` **obrigatórios**;
- o passo manual de `cp src/assets/*` (`:403-426`) → agora automático;
- a linha `:487` ("Atualizar screenshots no WP.org | Upload manual no painel"), que contradiz a
  própria seção de assets;
- o checklist de release (`:583-613`), acrescentando o ensaio da Fase 2 e o gate de revisão.

**Acrescentar:** tags do WP.org são imutáveis; o ensaio com `svnadmin create`; que o WP.org
reconstrói o download a partir da tag (fidelidade de conteúdo, não do zip — então **nunca** re-rodar
Composer para publicar); e que `git clean -xdf` apaga o working copy **e** o zip aprovado.

---

## Arquivos afetados

| Arquivo | Mudança |
|---|---|
| `scripts/release.sh` | §1.1 hoist `ZIP_PATH` (~`:129`); §1.2 flag (`:60-68`, `:70-84`, `:47`); §1.3 guard + `PUBLISH_ONLY` (após `:96`, envolvendo `:213-369`); §1.4 fix dry-run (`:358-360`); §1.5 substituir `:390-407` |
| `docs/RELEASE.md` | reescrever seção SVN `~:348-426`; corrigir `:487` e o checklist `:583-613` |
| `docs/SVN-PUBLISH-FIX.md` | este arquivo — trocar o banner de status ao concluir |
| `CLAUDE.md` | atualizar a linha **Status** e a entrada deste doc em "Context and guides" |
| `src/assets/` | fonte do rsync de assets (10 PNGs) — **read-only**, não confundir com `src/trunk/assets/` |
| `releases/…-0.1.0.zip` | artefato aprovado, fonte da verdade — **read-only** |
| `.gitignore` | nada a fazer: `:112` `releases/*` já cobre `releases/svn` e `releases/.svn-stage` |

---

## Verificação

1. **Sintaxe:** `bash -n scripts/release.sh`; `shellcheck scripts/release.sh` se disponível.
2. **Ensaio offline completo (Fase 2, itens 2a-2h)** — é o teste de verdade da Fase 1.
3. **Não-regressão do build:** `./scripts/release.sh -v 0.1.0 -s paycrypto-me-for-woocommerce --dry-run`
   continua íntegro **e** `releases/paycrypto-me-for-woocommerce-0.1.0.zip` continua existindo depois
   (defeito #7). Confirmar com `sha256sum` antes e depois.
4. **Build real ainda funciona:** `./scripts/release.sh -v 0.1.0 -s paycrypto-me-for-woocommerce --no-zip`
   roda npm+PHPUnit+Composer sem erro (prova que o gate `PUBLISH_ONLY` não quebrou o caminho normal).
5. **Pós-push real:** `svn ls https://plugins.svn.wordpress.org/paycrypto-me-for-woocommerce/tags/`
   mostra `0.1.0/`, e a página pública responde 200 em alguns minutos (indexação completa até 72h).
6. **Suite existente:** `cd src/trunk && ./vendor/bin/phpunit` (277 testes) — não deve ser afetada,
   mas confirma que nada em `src/trunk/` foi tocado.
