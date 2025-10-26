#!/bin/bash

# PayCrypto.Me Translation Build Script
# Este script automatiza a geração de arquivos de tradução

set -e  # Parar em caso de erro

# Configurações
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="woocommerce-gateway-pay-crypto-me"
TEXT_DOMAIN="woocommerce-gateway-pay-crypto-me"
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

# Verificar se wp-cli está disponível
check_wp_cli() {
    if ! command -v wp &> /dev/null; then
        warn "WP-CLI não encontrado. Tentando usar método alternativo..."
        return 1
    fi
    return 0
}

# Criar diretório de idiomas se não existir
create_languages_dir() {
    if [ ! -d "$LANGUAGES_DIR" ]; then
        log "Criando diretório de idiomas: $LANGUAGES_DIR"
        mkdir -p "$LANGUAGES_DIR"
    fi
}

# Gerar POT usando WP-CLI
generate_pot_wp_cli() {
    header "Gerando arquivo POT com WP-CLI"
    
    cd "$PLUGIN_DIR"
    
    if wp i18n make-pot . "$POT_FILE" \
        --domain="$TEXT_DOMAIN" \
        --package-name="PayCrypto.Me for WooCommerce" \
        --headers='{"Report-Msgid-Bugs-To":"https://github.com/pay-crypto-me/woocommerce-gateway-pay-crypto-me/issues","Language-Team":"PayCrypto.Me Team <support@paycrypto.me>"}' \
        --exclude="node_modules,vendor,.git,assets/js,webpack.config.js" \
        --skip-js 2>/dev/null; then
        log "Arquivo POT gerado: $POT_FILE"
        return 0
    else
        warn "WP-CLI falhou. Tentando método alternativo..."
        return 1
    fi
}

# Gerar POT usando gerador PHP (método alternativo final)
generate_pot_php() {
    header "Gerando arquivo POT com gerador PHP"
    
    cd "$PLUGIN_DIR"
    php scripts/generate-pot.php
    
    if [ -f "$POT_FILE" ]; then
        log "Arquivo POT gerado: $POT_FILE"
    else
        error "Falha ao gerar arquivo POT"
        exit 1
    fi
}

# Criar arquivo PO para um idioma específico
create_po_file() {
    local locale=$1
    local po_file="$LANGUAGES_DIR/$PLUGIN_SLUG-$locale.po"
    
    if [ ! -f "$po_file" ]; then
        log "Criando arquivo PO para $locale: $po_file"
        
        # Copiar do POT e ajustar headers
        cp "$POT_FILE" "$po_file"
        
        # Atualizar headers específicos do idioma
        sed -i "s/Language: /Language: $locale/" "$po_file"
        sed -i "s/CHARSET/UTF-8/" "$po_file"
        
        # Adicionar header de idioma se não existir
        if ! grep -q "Language:" "$po_file"; then
            sed -i '/Content-Type/ a\
"Language: '$locale'\\n"' "$po_file"
        fi
    else
        log "Atualizando arquivo PO existente: $po_file"
        
        if command -v msgmerge &> /dev/null; then
            msgmerge --update "$po_file" "$POT_FILE"
        else
            warn "msgmerge não encontrado. Arquivo PO não foi atualizado automaticamente."
        fi
    fi
}

# Compilar arquivo MO
compile_mo_file() {
    local locale=$1
    local po_file="$LANGUAGES_DIR/$PLUGIN_SLUG-$locale.po"
    local mo_file="$LANGUAGES_DIR/$PLUGIN_SLUG-$locale.mo"
    
    if [ -f "$po_file" ]; then
        if command -v msgfmt &> /dev/null; then
            log "Compilando arquivo MO para $locale: $mo_file"
            msgfmt -o "$mo_file" "$po_file"
            
            # Verificar se foi criado com sucesso
            if [ -f "$mo_file" ]; then
                log "✓ Arquivo MO compilado com sucesso"
            else
                error "✗ Falha ao compilar arquivo MO"
            fi
        else
            log "Compilando arquivo MO para $locale com PHP: $mo_file"
            cd "$PLUGIN_DIR"
            php scripts/compile-mo.php "$po_file" "$mo_file" 2>/dev/null
            
            if [ -f "$mo_file" ]; then
                log "✓ Arquivo MO compilado com sucesso (PHP)"
            else
                warn "✗ Falha ao compilar arquivo MO com PHP"
            fi
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
    elif command -v xgettext &> /dev/null; then
        generate_pot_xgettext
    else
        warn "WP-CLI e xgettext não encontrados. Usando gerador PHP..."
        generate_pot_php
    fi
    
    # Idiomas para criar/atualizar
    LANGUAGES=("pt_BR" "en_US" "es_ES")
    
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
        if [ -f "$po_file" ]; then
            echo "  ✓ $lang: $(basename "$po_file")"
        else
            echo "  ✗ $lang: Falha na criação"
        fi
    done
    
    echo ""
    log "Arquivos MO compilados:"
    for lang in "${LANGUAGES[@]}"; do
        mo_file="$LANGUAGES_DIR/$PLUGIN_SLUG-$lang.mo"
        if [ -f "$mo_file" ]; then
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
        elif command -v xgettext &> /dev/null; then
            generate_pot_xgettext
        else
            warn "WP-CLI e xgettext não encontrados. Usando gerador PHP..."
            generate_pot_php
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