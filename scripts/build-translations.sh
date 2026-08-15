#!/bin/bash

# PayCrypto.Me Translation Build Script
# Este script automatiza a geração de arquivos de tradução

set -e  # Parar em caso de erro

# Configurações
PLUGIN_DIR="/var/www/html/wp-content/plugins/paycrypto-me-for-woocommerce"
PLUGIN_SLUG="paycrypto-me-for-woocommerce"
TEXT_DOMAIN="paycrypto-me-for-woocommerce"
LANGUAGES_DIR="$PLUGIN_DIR/languages"
POT_FILE="$LANGUAGES_DIR/$PLUGIN_SLUG.pot"

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Função para log colorido
log() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

header() {
    echo -e "${BLUE}=== $1 ===${NC}"
}

# Compose v2 ships as the `docker compose` plugin, but plenty of hosts only have the standalone
# `docker-compose` binary (also v2 nowadays). Resolved once instead of per call.
if docker compose version >/dev/null 2>&1; then
    DOCKER_COMPOSE=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
    DOCKER_COMPOSE=(docker-compose)
else
    error "Neither 'docker compose' nor 'docker-compose' is available."
    exit 1
fi

docker_exec() {
    "${DOCKER_COMPOSE[@]}" exec -w "$PLUGIN_DIR" wordpress bash -c "$@"
}

# Verificar se wp-cli está disponível
check_wp_cli() {
    if ! docker_exec "command -v wp &> /dev/null"; then
        warn "WP-CLI não encontrado. Tentando usar método alternativo..."
        return 1
    fi
    return 0
}

# Ensure the languages directory is created inside the container
create_languages_dir() {
    if ! docker_exec "[ -d \"$LANGUAGES_DIR\" ]"; then
        log "Criando diretório de idiomas: $LANGUAGES_DIR"
        docker_exec "mkdir -p \"$LANGUAGES_DIR\""
    fi
}

# Gerar POT usando WP-CLI
generate_pot_wp_cli() {
    header "Gerando arquivo POT com WP-CLI em: $PLUGIN_DIR"

    if docker_exec "wp i18n make-pot . \"$POT_FILE\" \
        --domain=\"$TEXT_DOMAIN\" \
        --package-name=\"PayCrypto.Me for WooCommerce\" \
        --headers='{\"Report-Msgid-Bugs-To\":\"https://github.com/paycrypto-me/paycrypto-me-for-woocommerce/issues\",\"Language-Team\":\"PayCrypto.Me Team <contact@paycrypto.me>\"}' \
        --exclude=\"node_modules,vendor,.git,assets/js,webpack.config.js\" \
        --skip-js" 2>/dev/null; then
        log "Arquivo POT gerado: $POT_FILE"
        return 0
    else
        warn "WP-CLI falhou ao gerar o arquivo POT. Verifique se o WP-CLI está configurado corretamente."
        return 1
    fi
}

generate_pot_xgettext() {
    header "Gerando arquivo POT com xgettext em: $PLUGIN_DIR"

    if docker_exec "find . -name '*.php' | xargs xgettext --from-code=UTF-8 --language=PHP --output=\"$POT_FILE\""; then
        log "Arquivo POT gerado com sucesso usando xgettext: $POT_FILE"
        return 0
    else
        error "Falha ao gerar arquivo POT com xgettext."
        exit 1
    fi
}

# Plural-Forms correto por locale (gettext exige isso quando há entradas com _n())
plural_forms_for_locale() {
    case "$1" in
        de_DE|es_ES|it_IT) echo 'nplurals=2; plural=(n != 1);' ;;
        fr_FR|pt_BR) echo 'nplurals=2; plural=(n > 1);' ;;
        ru_RU) echo 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<12 || n%100>14) ? 1 : 2);' ;;
        zh_CN) echo 'nplurals=1; plural=0;' ;;
        *) echo 'nplurals=2; plural=(n != 1);' ;;
    esac
}

# Garante Plural-Forms (obrigatório para as entradas _n()) e limpa os placeholders
# padrão do WP-CLI (Last-Translator/PO-Revision-Date) sem sobrescrever valores já preenchidos
fix_po_headers() {
    local locale=$1
    local po_file="$LANGUAGES_DIR/$PLUGIN_SLUG-$locale.po"
    local plural_forms
    plural_forms=$(plural_forms_for_locale "$locale")
    local revision_date
    revision_date=$(date -u '+%Y-%m-%d %H:%M')

    if ! docker_exec "grep -q '^\"Plural-Forms:' \"$po_file\""; then
        docker_exec "sed -i '/^\"Language: $locale/ a\\
\"Plural-Forms: $plural_forms\\\\n\"' \"$po_file\""
    fi

    docker_exec "sed -i 's/\"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\\\n\"/\"PO-Revision-Date: $revision_date+0000\\\\n\"/' \"$po_file\""
    docker_exec "sed -i 's/\"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\\\n\"/\"Last-Translator: PayCrypto.Me Team <contact@paycrypto.me>\\\\n\"/' \"$po_file\""
}

# Remove as entradas obsoletas (`#~`) que o msgmerge acumula quando um msgid deixa de existir.
# Elas nunca entram no .mo, mas incham o .po e aparecem como "obsoletas" no PoEdit/Loco — inclusive
# strings tiradas do catálogo de propósito (erros/warnings/logs do painel; ver docs/TRANSLATION.md),
# que não devem voltar a ser oferecidas ao tradutor. Escreve em arquivo temporário e só então
# substitui: msgattrib lendo e gravando o mesmo caminho trunca o arquivo.
strip_obsolete_entries() {
    local locale=$1
    local po_file="$LANGUAGES_DIR/$PLUGIN_SLUG-$locale.po"

    if ! docker_exec "command -v msgattrib &> /dev/null"; then
        warn "msgattrib não encontrado. Entradas obsoletas mantidas em $locale."
        return
    fi

    if docker_exec "msgattrib --no-obsolete --output-file=\"$po_file.tmp\" \"$po_file\" && mv \"$po_file.tmp\" \"$po_file\""; then
        return
    fi

    warn "Falha ao remover entradas obsoletas de $locale — arquivo original preservado."
    docker_exec "rm -f \"$po_file.tmp\""
}

# Criar arquivo PO para um idioma específico
create_po_file() {
    local locale=$1
    local po_file="$LANGUAGES_DIR/$PLUGIN_SLUG-$locale.po"
    
    if ! docker_exec "[ -f \"$po_file\" ]"; then
        log "Criando arquivo PO para $locale: $po_file"
        
        # Copiar do POT e ajustar headers
        docker_exec "cp \"$POT_FILE\" \"$po_file\""
        
        # Atualizar headers específicos do idioma
        docker_exec "sed -i 's/Language: /Language: $locale/' \"$po_file\""
        docker_exec "sed -i 's/CHARSET/UTF-8/' \"$po_file\""
        
        # Adicionar header de idioma se não existir
        if ! docker_exec "grep -q 'Language:' \"$po_file\""; then
            docker_exec "sed -i '/Content-Type/ a\\
            \"Language: $locale\\\\n\"' \"$po_file\""
        fi
    else
        log "Atualizando arquivo PO existente: $po_file"
        
        if docker_exec "command -v msgmerge &> /dev/null"; then
            # --backup=off: por padrão o msgmerge deixa um `<arquivo>.po~` ao lado de cada PO
            # atualizado. São ignorados pelo git e excluídos do zip de release, mas o
            # `wp plugin check` roda sobre a árvore de trabalho e reprova cada um deles
            # (badly_named_files) — ruído que esconde achado de verdade. O histórico do git já é
            # o backup.
            docker_exec "msgmerge --backup=off --update \"$po_file\" \"$POT_FILE\""
        else
            warn "msgmerge não encontrado. Arquivo PO não foi atualizado automaticamente."
        fi
    fi

    strip_obsolete_entries "$locale"
    fix_po_headers "$locale"
}

# Compilar arquivo MO
compile_mo_file() {
    local locale=$1
    local po_file="$LANGUAGES_DIR/$PLUGIN_SLUG-$locale.po"
    local mo_file="$LANGUAGES_DIR/$PLUGIN_SLUG-$locale.mo"
    
    if docker_exec "[ -f \"$po_file\" ]"; then
        if docker_exec "command -v msgfmt &> /dev/null"; then
            log "Compilando arquivo MO para $locale: $mo_file"
            docker_exec "msgfmt -o \"$mo_file\" \"$po_file\""
            
            # Verificar se foi criado com sucesso
            if docker_exec "[ -f \"$mo_file\" ]"; then
                log "✓ Arquivo MO compilado com sucesso"
            else
                error "✗ Falha ao compilar arquivo MO"
            fi
        else
            error "msgfmt não encontrado. Não foi possível compilar o arquivo MO para $locale."
            exit 1
        fi
    else
        error "Arquivo PO não encontrado: $po_file"
    fi
}

# Função principal
main() {
    header "PayCrypto.Me - Script de Tradução"
    
    # Criar diretório de idiomas
    create_languages_dir
    
    # Gerar arquivo POT
    if check_wp_cli && generate_pot_wp_cli; then
        # WP-CLI funcionou
        :
    elif docker_exec "command -v xgettext &> /dev/null"; then
        generate_pot_xgettext
    else
        error "WP-CLI e xgettext não encontrados. Usando gerador PHP..."
        exit 1
    fi
    
    # Idiomas para criar/atualizar
    LANGUAGES=("pt_BR" "es_ES" "de_DE" "fr_FR" "it_IT" "ru_RU" "zh_CN")
    
    # Criar/atualizar arquivos PO
    header "Criando/Atualizando arquivos PO"
    for lang in "${LANGUAGES[@]}"; do
        create_po_file "$lang"
    done
    
    # Compilar arquivos MO
    header "Compilando arquivos MO"
    for lang in "${LANGUAGES[@]}"; do
        compile_mo_file "$lang"
    done
    
    # Relatório final
    header "Relatório Final"
    log "Arquivo POT: $(basename "$POT_FILE")"
    
    echo ""
    log "Arquivos PO criados/atualizados:"
    for lang in "${LANGUAGES[@]}"; do
        po_file="$LANGUAGES_DIR/$PLUGIN_SLUG-$lang.po"
        if docker_exec "[ -f \"$po_file\" ]"; then
            echo "  ✓ $lang: $(basename "$po_file")"
        else
            echo "  ✗ $lang: Falha na criação"
        fi
    done
    
    echo ""
    log "Arquivos MO compilados:"
    for lang in "${LANGUAGES[@]}"; do
        mo_file="$LANGUAGES_DIR/$PLUGIN_SLUG-$lang.mo"
        if docker_exec "[ -f \"$mo_file\" ]"; then
            echo "  ✓ $lang: $(basename "$mo_file")"
        else
            echo "  - $lang: Não compilado (msgfmt não disponível ou erro)"
        fi
    done
    
    echo ""
    log "✅ Script de tradução concluído!"
    log "📁 Arquivos gerados em: $LANGUAGES_DIR"
    log "📝 Para editar traduções, use um editor como PoEdit ou Loco Translate"
}

# Verificar argumentos
case "${1:-}" in
    "pot")
        create_languages_dir
        if check_wp_cli && generate_pot_wp_cli; then
            # WP-CLI funcionou
            :
        elif docker_exec "command -v xgettext &> /dev/null"; then
            generate_pot_xgettext
        else
            error "WP-CLI e xgettext não encontrados. Usando gerador PHP..."
            exit 1
        fi
        ;;
    "po")
        if [ -z "$2" ]; then
            error "Uso: $0 po <locale>"
            error "Exemplo: $0 po pt_BR"
            exit 1
        fi
        create_po_file "$2"
        ;;
    "mo")
        if [ -z "$2" ]; then
            error "Uso: $0 mo <locale>"
            error "Exemplo: $0 mo pt_BR"
            exit 1
        fi
        compile_mo_file "$2"
        ;;
    "help"|"-h"|"--help")
        echo "PayCrypto.Me Translation Build Script"
        echo ""
        echo "Uso:"
        echo "  $0                 # Executar processo completo"
        echo "  $0 pot             # Gerar apenas arquivo POT"
        echo "  $0 po <locale>     # Criar/atualizar arquivo PO específico"
        echo "  $0 mo <locale>     # Compilar arquivo MO específico"
        echo "  $0 help            # Mostrar esta ajuda"
        echo ""
        echo "Exemplos:"
        echo "  $0 po pt_BR        # Criar/atualizar tradução pt_BR"
        echo "  $0 mo pt_BR        # Compilar arquivo MO pt_BR"
        ;;
    *)
        main
        ;;
esac