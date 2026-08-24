# PayCrypto.Me para WooCommerce - Proposta de Features e Melhorias

**Data:** [Data Início]
**Autor:** [Seu Nome]
**Contexto:** Early Pilot Program - 6 meses
**Objetivo:** Documentar features críticas, importantes e inovadoras para o add-on de conversão automática

---

## 📋 Índice

1. [Executive Summary](#executive-summary)
2. [Features Críticas (Must-Have)](#features-críticas-must-have)
3. [Features Importantes (Should-Have)](#features-importantes-should-have)
4. [Ideias Inovadoras (Nice-to-Have)](#ideias-inovadoras-nice-to-have)
5. [Considerações Técnicas](#considerações-técnicas)
6. [Modelo de Preço Proposto](#modelo-de-preço-proposto)
7. [Timeline e Prioridades](#timeline-e-prioridades)
8. [Validação Técnica Realizada](#validação-técnica-realizada)

---

## Executive Summary

Este documento resume as features e melhorias identificadas durante testes extensivos do plugin PayCrypto.Me em ambiente de produção. Como early pilot, realizei:

- ✅ Validação técnica com xPub (testnet + mainnet)
- ✅ Exploração do código-fonte e sistema de logs
- ✅ Análise de UX vs competitors (Blockonomics, CryptAPI)
- ✅ Simulação de casos de uso reais
- ✅ Identificação de gaps operacionais

**Resultado:** Plugin é genuinamente non-custodial e funcionalmente sólido. Add-on deve focar em **automação** (conversão + confirmação + reconciliação) e **UX profissional** (janelas de preço, notificações real-time).

**Volume esperado:**
- Piloto (hoje): ### tx/mês (~R$##k)
- Futuro (6 meses): ### tx/mês (~R$###k com stablecoins)

---

## Features Críticas (Must-Have)

Estas features são **obrigatórias** para o add-on ser viável operacionalmente. Sem elas, o fluxo permanece semi-manual.

### 1. Conversão Automática (Fiat ↔ Cripto em Tempo Real)

**Problema identificado:**
```
Sem conversão automática:
- Cliente vê: "R$500"
- Sistema mostra: "0.071 BTC" (captura manual do preço)
- Cliente demora 10 min pra pagar
- Preço mudou: agora é "0.0715 BTC"
- Cliente confuso: "Quanto eu pago?"
```

**Solução:**
- Sistema busca taxa fiat/cripto em tempo real
- Gera QR code com valor exato em cripto
- Registra o momento da conversão nos logs
- Cliente vê claramente: "R$500 = 0.071 BTC (neste momento)"

**Integração WooCommerce:**
- Plugin detecta moeda configurada (BRL, USD, EUR)
- WooCommerce nativo já suporta múltiplas moedas
- Plugin apenas integra a taxa cripto

**Moedas a suportar (Fase 1):**
- Bitcoin (BTC) - em produção
- Lightning (LN) - em desenvolvimento
- USDT (Ethereum/Polygon/Arbitrum)
- USDC (Ethereum/Polygon/Arbitrum)
- Outras conforme demanda

---

### 2. Confirmação Automática + Reconciliação WooCommerce

**Problema identificado:**
```
Fluxo atual:
1. Cliente paga 0.071 BTC
2. [NADA AUTOMÁTICO]
3. Lojista fica checando blockchain manualmente
4. Ou cliente avisa "já paguei!"
5. Lojista atualiza pedido manualmente no WooCommerce

Cenário com 500 tx/mês = impraticável
```

**Solução proposta:**

```
Fluxo automático:
1. Cliente paga 0.071 BTC → bc1qxyz789...
2. Plugin monitora endereço 24/7
3. TX chega na blockchain
4. [SISTEMA DETECTA AUTOMATICAMENTE]
5. Valida:
   - Valor confere? (0.071 BTC) ✅
   - Confirmações suficientes? ✅
   - Sem double-spend? ✅
6. Envia webhook/notificação
7. WooCommerce atualiza status:
   "Pending" → "Processing" (ou "Completed")
8. Cliente recebe email automático
9. Lojista recebe notificação
```

**Implementação técnica:**
- Monitorar endereço via blockchain API (blockchain.com, Blockchair, etc)
- Ou via node local (para alta segurança)
- Webhook para WooCommerce REST API
- Atualizar status do pedido automaticamente
- Enviar email de confirmação via WooCommerce nativo

**Latência esperada:**
- On-chain Bitcoin: 30-60s após 1ª confirmação
- Lightning: <1s
- Stablecoins: 15-30s

---

### 3. Reconciliação Automática com WooCommerce

**Confirmado em testes:** Sistema já funciona nos logs. Pergunta: como é o fluxo completo?

**Esperado:**
- ✅ Pedido #12345 = Endereço único bc1qxyz789...
- ✅ TX chega = Sistema identifica "é do pedido #12345"
- ✅ Status muda automaticamente
- ✅ Nenhuma intervenção manual necessária

**Caso de uso crítico:**
```
Cenário: 500 transações/mês

Sem reconciliação:
- 500 × (checar status + atualizar) = 500 ações manuais
- Impossível escalar

Com reconciliação:
- 500 atualizações automáticas
- Lojista só processa o pedido
- Escalável infinitamente
```

---

### 4. Custom Confirmations (Diferentes por Tipo de Cripto)

**Descoberta:** Campo "Custom confirmations" já existe no settings, marcado como "Premium (coming soon)".

**Necessidade identificada:**
```
Bitcoin (on-chain):    3-6 confirmações (segurança)
Lightning:             0 confirmações (instant)
Stablecoins:           1 confirmação (rápido + seguro)

Problema: Se configurar tudo com 6 conf:
- Lightning fica muito lento

Se configurar com 0 conf:
- Bitcoin fica inseguro
```

**Solução proposta:**
```
Admin consegue configurar por tipo:

[Bitcoin Settings]
Confirmations required: 3
Min conf time: ~30 min

[Lightning Settings]
Confirmations required: 0 (instant)
Fee coverage: automático

[Stablecoins Settings]
Confirmations required: 1
Min conf time: ~15 sec
```

**Prioridade:** ALTA - está no código, só precisa ativar

---

### 5. Suporte Completo a Lightning + On-Chain + Stablecoins

**Status atual:**
- ✅ Bitcoin on-chain: funcional
- ⏳ Lightning Network: em desenvolvimento
- ⏳ Stablecoins: roadmap (confirmado nos logs)

**Esperado no add-on:**
- Todos os 3 funcionando nativamente
- Mesmo checkout com opção de escolher
- Sem duplicação de plugins

**Implementação proposta:**
```
Checkout WooCommerce:
┌─────────────────────────────┐
│ Escolha o método            │
│                             │
│ [Bitcoin On-Chain]  ⚡      │
│ [Lightning Network] ⚡⚡    │
│ [USDT]             💵      │
│ [USDC]             💵      │
└─────────────────────────────┘

Cliente escolhe, plugin auto-detecta:
- Qual endereço usar
- Qual confirmação requer
- Qual taxa aplicar
```

---

## Features Importantes (Should-Have)

Estas features melhoram significativamente UX e conversão, mas não são bloqueadores.

### 1. Exibição de Preço em Cripto na Listagem de Produtos

**Propósito:** Aumentar transparência e conversão

**Exemplo visual:**
```
Antes:
"Notebook Gaming - R$5.000"

Depois:
"Notebook Gaming - R$5.000 (ou ₿0.071)"
```

**Benefícios:**
- Cliente que quer pagar em BTC vê logo quanto é
- Reduz fricção (não precisa calcular)
- Sinaliza profissionalismo
- Aumenta conversão (estudos mostram 10-15%)

**Implementação:**
- Toggle no admin: "Mostrar preço em cripto?"
- Busca taxa em tempo real
- Cache por 5-10 min (performance)
- Customizável por produto

**Prioridade:** MÉDIA - melhora UX, não crítico operacional

---

### 2. Countdown Timer com Janela de Preço Válido

**Insight:** Baseado em padrão de corretoras (Binance, Kraken, Kraken)

**Problema atual:**
```
Cliente vê: "0.071 BTC"
Abre wallet: 2 minutos depois
Preço agora: "0.0715 BTC"
Cliente: "Pago quanto?"
```

**Solução proposta:**
```
TELA 1: Primeira visualização
┌──────────────────────────────┐
│ Pagar em Bitcoin              │
│                              │
│ 🔄 0.071 BTC                 │
│ ✅ VÁLIDO POR 7 SEGUNDOS     │
│ [QR CODE]                    │
│ bc1qxyz789...                │
└──────────────────────────────┘

TELA 2: Countdown ativo
┌──────────────────────────────┐
│ Pagar em Bitcoin              │
│                              │
│ 🔄 0.071 BTC                 │
│ ⏱️ Válido por 3 segundos     │
│ [QR CODE]                    │
│ bc1qxyz789...                │
└──────────────────────────────┘

TELA 3: Preço expirou
┌──────────────────────────────┐
│ ⚠️ Preço atualizado!         │
│                              │
│ Novo preço: 0.0715 BTC       │
│ ✅ VÁLIDO POR 7 SEGUNDOS     │
│ [NOVO QR CODE]               │
│                              │
│ [Aceitar novo preço]         │
│ [Aguardar próximo refresh]   │
└──────────────────────────────┘
```

**Fluxo técnico:**
```javascript
1. generateInvoice() {
2.   btcPrice = fetch live price
3.   amountBTC = orderValue / btcPrice
4.   validWindow = 7 seconds
5.   displayCountdown(7)
6. }
7.
8. onCountdownEnd() {
9.   generateInvoice() // recursive
10. }
```

**Benefícios:**
- Cliente sabe EXATAMENTE quanto paga
- Reduz medo de volatilidade
- Aumenta urgência (psicologia de escassez)
- Profissional (padrão de corretoras)
- **Diferencial vs Blockonomics/CryptAPI**

**Configuração admin:**
- Tempo padrão: 7 segundos (customizável)
- Comportamento ao expirar: refresh auto vs perguntar
- Mostrar countdown visual: sim/não

**Prioridade:** ALTA - melhora conversão significativamente

---

### 3. Notificações Real-Time (Email, SMS, Webhook)

**Necessidade:** Saber IMEDIATAMENTE quando pagamento confirma

**Caso de uso:**
```
1. Cliente paga
2. Você recebe notificação < 1 min
3. Você processa pedido rapidamente
4. Cliente satisfeito (experiência rápida)

Sem notificação:
- Você checa manualmente a cada x tempo
- Delay no processamento
- Cliente fica esperando
```

**Canais propostos:**

**Email:**
```
Subject: Pagamento confirmado - Pedido #12345
From: noreply@paycrypto.me

Olá,

Pagamento de 0.071 BTC confirmado!

Pedido: #12345
Cliente: João Silva
Valor: R$5.000
TX ID: 0xabc123...
Confirmações: 3/3

[Link para processar pedido]
[Dashboard]
```

**Webhook (para integração programática):**
```json
POST /wp-json/paycrypto/v1/payment-confirmed
{
  "order_id": 12345,
  "customer_id": 789,
  "amount_crypto": "0.071",
  "currency_crypto": "BTC",
  "amount_fiat": 5000,
  "currency_fiat": "BRL",
  "tx_id": "0xabc123...",
  "confirmations": 3,
  "timestamp": 1692820456,
  "status": "completed"
}
```

**SMS (opcional):**
```
PayCrypto: Pagamento confirmado!
Pedido #12345 - 0.071 BTC
https://suacrypto.com/orders/12345
```

**Prioridade:** ALTA - operacional crítico

---

### 4. QR Code e Detalhes nos Emails de Confirmação

**Necessidade:** Facilitar para cliente que paga via email

**Exemplo:**
```
Email recebido: "Seu pedido foi criado"

[Pedido #12345]
[Valor: R$5.000]

┌─────────────────┐
│   [QR CODE]     │ ← Pode escanear direto do email
└─────────────────┘

Endereço: bc1qxyz789...
Valor: 0.071 BTC
Válido por: 7 dias

[Botão: Abrir no navegador]
[Botão: Copiar endereço]
[Botão: Ver detalhes]
```

**Benefícios:**
- Cliente recebe email, scanneia, paga
- Reduz abandonment
- Fluxo mais fluido

**Prioridade:** MÉDIA - melhora UX

---

## Ideias Inovadoras (Nice-to-Have com Alto Impacto)

### 1. Refund Automático com Integração MetaMask

**Insight:** Baseado em padrão Web3 (Uniswap, Aave, OpenSea)

**Problema atual:**
```
Fluxo manual:
1. Cliente pede refund
2. Admin vai pra crypto exchange
3. Copia endereço do cliente
4. Envia cripto manualmente
5. Atualiza WooCommerce
→ Lento, error-prone
```

**Solução proposta:**
```
Fluxo automático via MetaMask:

1. Admin clica "Refund" no WooCommerce
2. MetaMask abre automaticamente (WalletConnect)
3. Mostra transação pré-preenchida:
   "Enviar 100 USDT para 0xABC123..."
4. Admin aprova no MetaMask (1 clique)
5. Transação vai pro blockchain
6. WooCommerce marca como refunded automaticamente
7. Pronto
```

**Fluxo técnico (viável):**
```javascript
const refundPayment = async (orderId, amount, customerAddress) => {
  // 1. Conecta MetaMask via WalletConnect
  const provider = new ethers.BrowserProvider(window.ethereum);
  const signer = await provider.getSigner();

  // 2. Prepara transação USDT
  const tx = {
    to: USDT_CONTRACT_ADDRESS,
    data: encodeUSDTTransfer(customerAddress, amount),
  };

  // 3. Pede assinatura
  const txResponse = await signer.sendTransaction(tx);

  // 4. Aguarda confirmação
  await txResponse.wait();

  // 5. Atualiza WooCommerce
  await updateOrderStatus(orderId, "refunded");
};
```

**Segurança:**
- ✅ Admin sempre controla as chaves privadas
- ✅ Plugin NÃO toca em chaves
- ✅ MetaMask assina tudo
- ✅ Modelo igual a Uniswap/Aave

**Prioridade:** MÉDIA - diferencial massivo, mas não crítico piloto

---

### 2. Sistema de Logs Expandido

**Descoberta:** Já existe logging básico, sugerir expandir

**Métricas a rastrear:**
```
✅ Moeda fiat configurada
✅ Moeda cripto usada
✅ Taxa de conversão aplicada
✅ Tempo até pagamento (confirmação)
✅ Volatilidade observada
✅ Taxa de sucesso (pagamentos confirmados)
✅ Overpayments / Underpayments
✅ Refunds processados
✅ Erros encontrados
```

**Implementação:**
- Dashboard com gráficos
- Exportar CSV/JSON
- Filtros por período/produto/cliente
- Alertas automáticos (ex: alta taxa de underpayment)

**Prioridade:** BAIXA - nice-to-have

---

### 3. Suporte a Múltiplas Moedas Fiat

**Descoberta:** Logs mostram que estrutura já está preparada

**Status:**
- ✅ BRL: em uso
- ⏳ USD, EUR: conforme demanda

**Próximos passos:**
- Detectar automaticamente moeda de WooCommerce
- Buscar taxa real-time
- Converter corretamente

**Prioridade:** MÉDIA - importância cresce conforme escala internacional

---

## Considerações Técnicas

### Volatilidade e Divergência de Preço

**Problema identificado:**
```
Cliente vê: "R$500 = 0.071 BTC"
Cliente abre wallet: 10 min depois
Preço mudou: agora é "R$505 = 0.0715 BTC"

Questões:
- Se cliente pagar 0.071 BTC, você aceita?
  (mesmo que preço tenha subido)
- Se cliente pagar 0.0715 BTC?
  (mesmo que preço tenha caído)
```

**Proposta de handling:**

```
Configurável no admin:

[Tolerance Settings]

Underpayment:
○ Rejeitar (cliente precisa re-enviar)
○ Aceitar parcial (marcar pedido como "partial")
○ Aceitar (ponto de risco, não recomendado)

Overpayment:
○ Aceitar tudo (você ganha spread)
○ Criar crédito (cliente pode usar depois)
○ Rejeitar e retornar (bom pro relacionamento)

Padrão recomendado: Aceitar ±0.1% de variação
```

---

### Integração com Blockchain APIs

**Proposta:**
- Usar blockchain.com (free tier até X requisições)
- Ou Blockchair (mais robusto)
- Ou node local (para segurança máxima)
- Fallback entre APIs (redundância)

**Rate limiting:**
- Monitorar endereços em lotes
- Não fazer polling a cada segundo
- Usar webhooks onde disponível

---

### Gas Fees (para transações em blockchain)

**Impacto:**
- Bitcoin on-chain: mínimo (tx já é na blockchain do cliente)
- Ethereum/USDT: você paga gas fee do refund
- Lightning: zero gas fees

**Recomendação:**
- On-chain: custos já calculados pelo cliente
- Refund on Polygon: muito mais barato que Ethereum
- Lightning: preferir pra velocidade

---

## Modelo de Preço Proposto

### Piloto (6 meses)

```
Valor: $49.99 USD/mês
Equivalente: ~R$250/mês
Total: R$1.500 (6 meses)

Volume esperado:
- Mês 1-2: 100 tx (~R$50k)
- Mês 3-4: 200 tx (~R$100k)
- Mês 5-6: 300 tx (~R$150k)

Taxa efetiva (média):
- 0.5% em relação ao volume

Economia vs Blockonomics (1%):
- Blockonomics custaria: ~R$9.000
- PayCrypto.Me: R$1.500
- Economia: R$7.500 ✅
```

### Pós-Piloto (Proposta)

```
Modelo: Flat Mensal (não por transação)

Lógica: 40% menos que Blockonomics (1%)
Equivalente: 0.6% efetivo

Preço por volume:
- R$50k/mês → R$300/mês
- R$100k/mês → R$600/mês
- R$150k/mês → R$900/mês
- R$300k/mês → R$1.800/mês

Máximo aceitável: R$1.800/mês
Breakeven: R$3.000/mês (fica igual a Blockonomics)

Justificativa:
- Vocês ganham cliente que cresce exponencialmente
- Eu economizo massivamente (comparado a 1%)
- Relacionamento saudável (ambos ganham)
```

---

## Timeline e Prioridades

### Fase 1: Crítico (Piloto - meses 1-6)

**MUST HAVE no add-on:**
- [ ] Conversão automática (fiat → cripto)
- [ ] Confirmação automática (detecta tx)
- [ ] Reconciliação WooCommerce
- [ ] Custom confirmations (ativar field disabled)
- [ ] Suporte Lightning + On-chain + Stablecoins

**Status esperado no mês 6:**
- Todas 5 features 100% funcionais
- Em produção real com 500 tx/mês
- Documentação completa

---

### Fase 2: Importante (Roadmap - meses 7-12)

**SHOULD HAVE (sem bloqueio):**
- [ ] Exibição preço em cripto (listagem)
- [ ] Countdown timer (janela de preço)
- [ ] Notificações real-time (email/webhook)
- [ ] QR code em emails
- [ ] Suporte a USD/EUR

**Status esperado:**
- Pelo menos 3 de 5 implementadas
- Melhora de UX mensurável

---

### Fase 3: Inovação (Backlog - meses 13+)

**NICE-TO-HAVE:**
- [ ] Refund automático MetaMask
- [ ] Dashboard expandido
- [ ] Múltiplas blockchains (Polygon, Arbitrum)
- [ ] Histórico de cliente (meus pagamentos)
- [ ] API pública para integrações

**Status esperado:**
- Mínimo 2 de 5, com feedback de mercado

---

## Validação Técnica Realizada

### Testes Executados

**1. Non-custodial Validation**
```
✅ Testei com xPub em testnet
✅ Testei com xPub em mainnet
✅ Confirmei que endereços derivam corretamente
✅ Validei que plugin nunca acessa chaves privadas
✅ Conclusão: Genuinamente non-custodial ✅
```

**2. Code Exploration**
```
✅ Explorei /src do repositório
✅ Vi arquivo com CLAUDE.md (pronto pra IA)
✅ Identifiquei sistema de logs robusto
✅ Vi campo "Custom confirmations" (disabled)
✅ Confirmei estrutura pra múltiplas moedas
```

**3. Feature Discovery**
```
✅ Validei conversão automática básica
✅ Confirmei reconciliação funciona
✅ Testei em diferentes blockchains
✅ Validei WooCommerce integration
✅ Explorei logging system
```

**4. UX Testing**
```
✅ Comparei com Blockonomics
✅ Comparei com CryptAPI
✅ Testei fluxo de pagamento completo
✅ Validei QR code generation
✅ Testei em testnet sem riscos
```

---

## Próximos Passos

### Pré-Reunião
- [ ] Compartilhar este documento
- [ ] Validar que todas features fazem sentido
- [ ] Confirmar timeline esperada
- [ ] Ajustar expectativas se necessário

### During Reunião
- [ ] Apresentar features por prioridade
- [ ] Questionar timeline do roadmap
- [ ] Esclarecer implementação técnica
- [ ] Fechar acordo de preço
- [ ] Definir SLAs de suporte

### Pós-Reunião
- [ ] Enviar documento completo
- [ ] Confirmar por email próximos passos
- [ ] Integrar feedback deles em V2 deste doc
- [ ] Iniciar piloto

---

## Conclusão

PayCrypto.Me é um produto **genuinamente inovador e bem-arquitetado**. A filosofia non-custodial é real, o código é sólido, e o roadmap está claramente planejado (confirmado pelos logs).

**O add-on deve focar em:**
1. **Automação** - conversão + confirmação + reconciliação
2. **UX Profissional** - janelas de preço, countdown, notificações
3. **Escalabilidade** - suporte a múltiplas moedas/blockchains

**Diferenciais vs competitors:**
- Genuinamente non-custodial (Blockonomics também, mas CryptAPI é custodial)
- Suporte nativo a Lightning + On-chain + Stablecoins
- Modelo de preço inovador (flat vs %), não explorador

**Com implementação das 5 features críticas + 3 importantes, PayCrypto.Me seria o gateway de pagamento Bitcoin mais profissional para WooCommerce.**

---

## Contato & Dúvidas

Para discussão de qualquer feature acima:
- GitHub Issues: [seu usuário/repositório]
- Email: [seu email]
- Telegram/Discord: [seu contato]

---

**Documento versão:** 1.0
**Última atualização:** Agosto 2026
**Status:** Pronto para apresentação
