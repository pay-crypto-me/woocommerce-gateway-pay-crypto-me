#!/bin/bash

# Script simples para gerar traduções rapidamente
# Uso: ./scripts/quick-translate.sh

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="woocommerce-gateway-paycrypto-me"

echo "🚀 Gerando traduções para PayCrypto.Me..."

# Tornar executável o script principal
chmod +x "$PLUGIN_DIR/scripts/build-translations.sh"

# Executar script principal
"$PLUGIN_DIR/scripts/build-translations.sh"

echo ""
echo "✅ Pronto! Arquivos gerados em: $PLUGIN_DIR/languages/"
echo "📝 Para editar traduções:"
echo "   - Use PoEdit: https://poedit.net/"
echo "   - Ou Loco Translate (plugin WordPress)"
echo "   - Ou edite manualmente os arquivos .po"