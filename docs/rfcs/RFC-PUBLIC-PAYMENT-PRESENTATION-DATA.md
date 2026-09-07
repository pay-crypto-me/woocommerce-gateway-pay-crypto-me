# RFC — Contrato público de dados de apresentação do pagamento

**Status:** proposta para discussão

**Origem:** revisão independente P7f do PayCrypto.Me Pro

**Data:** 2026-09-07

## Resumo

Separar a obtenção dos dados normalizados de apresentação do pagamento da renderização do
template web. Add-ons precisam reutilizar URI, identificador, valor, validade e QR em canais como
e-mail sem capturar HTML por output buffer, enfileirar JavaScript ou depender do template de página
do pedido.

Esta RFC não transfere ao Base templates, notificações ou regras de produto do Pro. O Base continua
dono do dado canônico que já calcula; cada consumidor continua dono da apresentação adequada ao seu
canal.

## Problema observado

O Base expõe hoje:

- `build_order_display_args(WC_Order $order)`, que retorna apenas os argumentos específicos do
  gateway;
- `paycryptome_order_display_args`, antes do builder;
- `paycryptome_order_display_data`, depois do builder;
- `render_checkout_order_details_section()`, que calcula os dados, enfileira JavaScript e imprime
  o template web.

O array final com `payment_uri`, `payment_identifier`, `payment_qr_code`, valor, rede e validade só
fica acessível durante `render_checkout_order_details_section()`. O
`PaymentDisplayDataBuilder` mantido pela instância do gateway é protegido e não existe operação
pública que obtenha os dados finais sem renderização.

Na prova P7f, um consumidor de e-mail precisou abrir um output buffer e chamar o renderer de página
dentro de `woocommerce_email_order_details`. Esse caminho mistura três responsabilidades:

1. resolver e normalizar os dados canônicos;
2. gerar o QR;
3. imprimir HTML de web/admin e enfileirar um script de copiar endereço.

O acoplamento dificulta testar o corpo final do e-mail, leva para o canal de e-mail markup interativo
e classes dependentes do CSS frontend e pode disparar efeitos de assets em contexto sem página.
Duplicar no Pro a montagem do array ou a geração do QR evitaria esses efeitos, mas criaria duas
fontes para URI/QR e contrariaria o objetivo de integração entre os plugins.

## Objetivos

- Expor uma operação pública e sem output que retorne o mesmo array final usado pelo renderer.
- Manter filtros, validação, logging e geração de QR em uma única implementação do Base.
- Permitir que web, admin, e-mail e futuros canais escolham seu próprio template sem recalcular URI
  ou payload do QR.
- Evitar enqueue de CSS/JavaScript quando o consumidor solicita apenas dados.
- Definir lifecycle e compatibilidade do array destinado a extensões.

## Não objetivos

- Implementar e-mail do cliente, e-mail administrativo, webhook ou notificação no Base.
- Tornar o template web atual compatível com todos os clientes de e-mail.
- Garantir que `data:` URI seja exibida por todo cliente de e-mail.
- Escolher se o Pro usará `data:` URI, CID attachment ou imagem hospedada no corpo final.
- Mover M9/M10 ou suas regras de gating para o Base.

## Decisão proposta

### 1. Extração pública sem renderização

Adicionar ao contrato dos gateways uma operação com semântica equivalente a:

```php
public function get_order_display_data(\WC_Order $order): ?array;
```

O nome concreto pode mudar durante a discussão. A operação deve:

1. chamar `build_order_display_args()`;
2. retornar `null` quando o gateway não corresponde ao pagamento do pedido;
3. aplicar `paycryptome_order_display_args` com três argumentos;
4. chamar o mesmo `PaymentDisplayDataBuilder` e logger usados hoje;
5. aplicar `paycryptome_order_display_data` com três argumentos;
6. retornar o array final sem imprimir HTML e sem enfileirar assets.

### 2. Renderer como consumidor da operação

`render_checkout_order_details_section()` passa a chamar `get_order_display_data()`. Somente depois
de receber dados não nulos ele enfileira os assets do contexto web/admin e carrega o template atual.
Assim, renderer e add-ons não divergem na normalização.

### 3. Contrato de dados e extensibilidade

Documentar como estáveis para consumo os campos já observados pelo renderer:

- `payment_identifier` e `payment_uri`;
- `payment_qr_code` como `data:` URI ou string vazia na degradação suportada;
- valores fiat/cripto e moedas;
- labels e rede;
- validade formatada/expirada;
- confirmações requeridas.

Campos adicionais podem ser acrescentados. Remoção ou mudança de unidade exige deprecação ou
versão incompatível. Os dois filtros existentes permanecem a extensão oficial e devem executar
uma vez por chamada da nova operação.

### 4. Uso por e-mail

O Pro pode solicitar os dados finais e construir markup próprio, compatível com HTML ou plain text,
no hook nativo do WooCommerce. Se precisar transformar a representação do QR para CID ou outro
transporte, deve preservar exatamente o payload de `payment_uri`; não deve recalcular o pagamento.

O Base não promete que seu template web seja adequado a e-mail e não precisa registrar hooks de
e-mail.

### 5. Detecção e compatibilidade

O Pro deve detectar a nova API durante uma janela de compatibilidade. Na versão Base anterior, pode
usar um adapter explicitamente testado ou exigir a nova versão antes de ativar o enriquecimento de
e-mail. Não deve acessar a propriedade protegida do builder nem usar reflection em runtime.

## Alternativas consideradas

### Chamar o renderer atual com output buffer

Reutiliza o HTML sem duplicar cálculo, mas enfileira JavaScript, acopla e-mail ao template web e
torna difícil tratar plain text e compatibilidade de clientes. É aceitável como harness transitório,
não como contrato ideal para M9.

### Instanciar `PaymentDisplayDataBuilder` no Pro

Evita output, mas duplica a composição do serviço, o logger e parte da resolução por gateway. O
consumidor pode divergir do renderer do Base.

### Usar apenas metadados do pedido

Evita dependência de classes, mas replica labels, validade, degradação do QR e regras de gateway.
Também transforma detalhes internos de storage em API de apresentação.

### Adicionar diretamente um template de e-mail ao Base

Elimina trabalho no Pro, mas move uma feature gated para o plugin gratuito e reduz a liberdade do
consumidor de escolher canais e copy. Não é recomendado.

## Critérios de aceite futuros

1. `get_order_display_data()` retorna `null` para pedido/gateway incompatível sem disparar filtros.
2. Para Bitcoin e Lightning reais, retorna o mesmo array que o renderer fornece ao template.
3. Os filtros pré e pós recebem três argumentos e executam exatamente uma vez por chamada.
4. Solicitar somente os dados não imprime bytes nem enfileira scripts/estilos.
5. QR indisponível degrada para string vazia mantendo URI e identificador.
6. O renderer continua produzindo o HTML existente e enfileira seus assets somente ao renderizar.
7. Um consumidor sintético externo injeta os dados no corpo final capturado de um e-mail HTML do
   WooCommerce sem acessar propriedades protegidas ou gerar outro QR.
8. O consumidor trata `plain_text=true` sem inserir HTML.
9. Testes de contrato rodam contra a versão mínima suportada e a versão que introduz a API.

## Impacto nos consumidores

P7f pode corrigir sua prova atual usando o renderer existente e não precisa aguardar esta RFC. Antes
da implementação real de M9, o Pro deve decidir entre exigir a nova API aprovada ou documentar e
testar um adapter de compatibilidade. M10 pode usar a mesma operação para garantir que ajustes de
display permaneçam coerentes com o Base.

Aceitar esta RFC não conclui P7f, M9 ou M10. Cada entrega conserva seus próprios testes e revisão.
