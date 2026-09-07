# [VALIDATION] Projeção pública de status — aceite manual aprovado

**Branch:** `feat/payment-status-projection`

**Commit do Base:** `27bed50`

**Artefato candidato:** `releases/paycrypto-me-for-woocommerce-0.3.0-rc.27bed50.zip`

**SHA-256:** `b3c7680655ebbd467a0fde6888a1c40cfc574cdec15f6170cfcd7978e8b8ce60`

**Estado:** validação manual aprovada em 2026-09-07; merge e release ainda pendentes.

Este checklist é o registro da validação humana da branch. Não autoriza merge, tag ou bump da
checkout de trabalho. Depois do aceite, os bumps continuam sendo feitos somente por `release.sh`.

## 1. Ambientes já provisionados

As duas instalações usam volumes Docker independentes, plugin instalado de ZIP e nenhum bind mount
para `src/trunk`.

| Perfil | URL | Base | WooCommerce | Pro |
|---|---|---:|---:|---:|
| Candidato | <http://localhost:8092> | RC 0.3.0 do commit `27bed50` | 11.1.0 | 0.1.0 instalado, inicialmente inativo |
| Baseline | <http://localhost:8093> | release 0.2.2 | 11.1.0 | 0.1.0 instalado, inicialmente inativo |

Login em ambos: `admin` / `admin123`.

Para recriar ambos do zero:

```bash
./tests/manual/payment-status-projection/setup.sh --fresh
```

O comando destrói somente os volumes do projeto Docker isolado `pcm-browser-projection`.

## 2. Regras desta rodada

- Nunca envie BTC real. O endereço e a invoice são exclusivamente fixtures de interface.
- Use uma janela anônima para checkout e mantenha outra autenticada no admin.
- Capture uma imagem de cada tabela QA e dos detalhes finais de pelo menos um pedido On-Chain e um
  Lightning no candidato.
- Registre qualquer tela branca, aviso PHP, resposta duplicada, alteração inesperada de pedido ou
  diferença visual relevante entre baseline e candidato.
- Execute U01–U03 por último: eles transformam a instalação baseline em candidata.

## 3. Contrato público pelo navegador

### C01 — matriz candidata

1. Abra <http://localhost:8092/wp-admin/tools.php?page=pcm-payment-status-qa>.
2. Confirme `Perfil provisionado: candidate` e `Base instalado: 0.3.0`.
3. Clique **Executar matriz candidate**.

Esperado: oito linhas PASS:

- capability v1 e `onchain_confirmation_progress=0`;
- `applied` seguido de `already_applied`, com uma única action;
- evento da invoice antiga retorna `conflict` e conserva a nova em `New`;
- status inesperado retorna `conflict`;
- pedido inexistente retorna `not_found`;
- fronteiras 255/30 são aceitas;
- entradas além dos limites são rejeitadas antes do SQL;
- erro SQL vira outcome `error`, sem fatal.

Resultado: [x] PASS [ ] FAIL

Evidência/observação:
```
Payment Status Projection QA
Perfil provisionado: candidate
Base instalado: 0.3.0

Este harness usa somente pedidos fictícios 990001–990099 e remove suas fixtures ao terminar.
O hostname reservado qa-btcpay.invalid é interceptado para permitir checkout Lightning determinístico; nenhuma outra requisição HTTP é alterada.
Executar matriz candidate Executar matriz baseline
Resultado — candidate
```

| Caso | Estado | Evidência |
|---|---|---:|
C01 — capability v1 publicada | PASS | {"contract_version":1,"lightning_invoice_status_cas":1,"onchain_confirmation_progress":0}
C02 — applied + retry idempotente + uma action | PASS | first=applied; retry=already_applied; stored=Settled; actions=1
C03 — evento atrasado não liquida invoice substituta | PASS | outcome=conflict; row={"invoice_id":"qa-new-invoice","status":"New"}
C04 — estado inesperado retorna conflict | PASS | outcome=conflict; current=Expired
C05 — pedido ausente retorna not_found | PASS | outcome=not_found
C06 — limites exatos 255/30 são aceitos | PASS | outcome=applied; invoice_bytes=255; status_bytes=30
C07 — entradas fora do schema falham antes do SQL | PASS | ["Order id must be greater than zero.","Invoice id must not exceed 255 bytes.","Expected status must not exceed 30 bytes.","New status must not exceed 30 bytes."]
C08 — erro SQL retorna outcome error sem exception | PASS | outcome=error; diagnostic_present=yes

### B01 — fallback no Base publicado

1. Abra <http://localhost:8093/wp-admin/tools.php?page=pcm-payment-status-qa>.
2. Confirme `Perfil provisionado: baseline` e `Base instalado: 0.2.2`.
3. Clique **Executar matriz baseline**.

Esperado: duas linhas PASS — classe de capability ausente e retorno antecipado sem escolher writer.

Resultado: [x] PASS [ ] FAIL
Evidência/observação:
```
Payment Status Projection QA
Perfil provisionado: baseline

Base instalado: 0.2.2

Este harness usa somente pedidos fictícios 990001–990099 e remove suas fixtures ao terminar.

O hostname reservado qa-btcpay.invalid é interceptado para permitir checkout Lightning determinístico; nenhuma outra requisição HTTP é alterada.

Resultado — baseline
```

| Caso | Estado | Evidência |
|---|---|---:|
B01 — capability ausente no Base 0.2.2 | PASS | class_exists=false
B02 — fallback não seleciona writer legado | PASS | retorno antecipado sem writer

O segundo botão de cada página existe para o teste de upgrade. Antes do upgrade, executar a matriz
do perfil oposto deve falhar e não constitui defeito.

## 4. Configurações do candidato

### S01 — On-Chain

1. Abra <http://localhost:8092/wp-admin/admin.php?page=wc-settings&tab=checkout&section=paycrypto_me>.
2. Confirme gateway habilitado, rede Mainnet e identificador
   `1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa`.
3. Acrescente ` revisado` ao título, salve, recarregue e confirme persistência.
4. Remova o sufixo e salve novamente.

Esperado: nenhum erro; aviso, se houver, pertence somente ao gateway/tela corrente.

Resultado: [x] PASS [ ] FAIL
Evidência/observação:

### S02 — Lightning fixture

1. Abra <http://localhost:8092/wp-admin/admin.php?page=wc-settings&tab=checkout&section=paycrypto_me_lightning>.
2. Confirme gateway habilitado, BTCPay selecionado e URL `https://qa-btcpay.invalid`.
3. Altere Invoice Expiry de `3600` para `420`, salve e recarregue.
4. Volte a `3600` e salve.

Esperado: valor persiste e nenhum aviso On-Chain aparece nessa tela. Não use **Test connection**:
o fixture cobre criação/consulta de invoice, não o endpoint administrativo de diagnóstico.

Resultado: [x] PASS [ ] FAIL
Evidência/observação:

## 5. Checkout candidato

Use dados fictícios de cobrança. Sugestão: `QA Tester`, Brasil, Rua Teste 1, São Paulo/SP,
CEP `01001-000`, `qa@example.test`.

### O01 — pedido On-Chain

1. Em janela anônima, abra <http://localhost:8092/product/qa-bitcoin-product/>.
2. Adicione o produto ao carrinho e avance ao checkout.
3. Escolha **Bitcoin On-Chain QA** e finalize.
4. Recarregue a página recebida.
5. No admin, abra WooCommerce → Orders e entre no pedido.
6. Clique no botão de copiar endereço dentro dos detalhes de pagamento.

Esperado:

- pedido `pending`;
- endereço fixture, URI e QR visíveis no cliente e no admin;
- reload não troca o endereço;
- clicar em copiar não salva o pedido e não mostra “Order updated.”.

Resultado: [x] PASS [ ] FAIL

Pedido/evidência:

### L01 — pedido Lightning pelo processor real

1. Esvazie o carrinho ou use nova janela anônima.
2. Abra <http://localhost:8092/product/qa-bitcoin-product/> e avance ao checkout.
3. Escolha **Bitcoin Lightning QA** e finalize.
4. Recarregue a página recebida e abra o mesmo pedido no admin.

Esperado:

- checkout conclui sem acessar internet externa;
- pedido `pending`;
- invoice começa com `lnbc1pcmprojectionqafixture`;
- QR e expiração aparecem;
- reload conserva a mesma invoice;
- copiar invoice no admin não submete o formulário do pedido.

Esse caso percorre o fluxo real até `WpHttpClient`; somente o host reservado é respondido pelo
harness. Ele não valida TLS, autenticação ou operação de um BTCPay real.

Resultado: [x] PASS [ ] FAIL
Pedido/evidência:

## 6. Comparação visual e funcional com 0.2.2

### R01 — baseline On-Chain

Repita O01 em <http://localhost:8093/product/qa-bitcoin-product/> escolhendo **Bitcoin On-Chain QA**.

Esperado: comportamento equivalente ao candidato.

Resultado: [x] PASS [ ] FAIL

Diferenças observadas:

### R02 — baseline Lightning

Repita L01 em <http://localhost:8093/product/qa-bitcoin-product/> escolhendo **Bitcoin Lightning QA**.

Esperado: comportamento equivalente ao candidato; a nova feature não altera criação ou renderização
da invoice.

Resultado: [x] PASS [ ] FAIL
Diferenças observadas:

## 7. Coexistência com o ZIP Pro disponível

O Pro 0.1.0 está instalado, mas inativo, nas duas lojas.

### P01 — ativação no candidato

1. No candidato, abra Plugins → Installed Plugins.
2. Ative **PayCrypto.Me Pro**.
3. Se houver onboarding/licença, registre a tela apresentada e use apenas uma opção explícita de
   pular/continuar sem licença; não informe credenciais reais nesta rodada.
4. Reabra as duas telas de gateway e um pedido criado em O01/L01.
5. Reabra a página QA e execute **matriz candidate**.

Esperado: sem fatal, oito PASS novamente, pedidos e configurações continuam acessíveis.

Resultado: [x] PASS [ ] FAIL
Evidência/observação:

**Limite honesto:** o release Pro 0.1.0 ainda não implementa M6/M7 em runtime. Assim, P01 prova
coexistência e ausência de regressão, não webhook/poller real consumindo o CAS. A prova consumidora
cross-repo existente é sintética/automatizada; confirmação real será aceite próprio de M6/M7.

## 8. Upgrade real por ZIP — executar por último

### U01 — preservar pedidos do Base 0.2.2

Antes do upgrade, confirme que os pedidos R01/R02 ainda abrem e registre seus IDs, status,
endereço/invoice.

Resultado: [x] PASS [ ] FAIL

### U02 — atualizar pelo painel

1. Na baseline, abra Plugins → Add New Plugin → Upload Plugin.
2. Selecione
   `releases/paycrypto-me-for-woocommerce-0.3.0-rc.27bed50.zip` desta checkout.
3. Confirme **Replace current with uploaded** e mantenha o plugin ativo.
4. Confirme que a versão exibida passou de 0.2.2 para 0.3.0.

Esperado: atualização concluída sem fatal e sem aviso de schema.

Resultado: [x] PASS [ ] FAIL
Evidência/observação:

### U03 — pós-upgrade

1. Reabra os pedidos R01/R02 e compare os dados anotados em U01.
2. Abra Tools → Payment Projection QA.
3. Agora clique **Executar matriz candidate**, embora a página ainda informe perfil provisionado
   `baseline`.
4. Crie mais um pedido Lightning.

Esperado: dados antigos intactos, oito PASS e novo checkout funcional.

Resultado: [x] PASS [ ] FAIL
Evidência/observação:

## 9. Aceite e devolução

Envie os resultados preenchidos ou uma lista no formato:

```text
C01 PASS
B01 PASS
S01 PASS
S02 PASS
O01 PASS — pedido #...
L01 PASS — pedido #...
R01 PASS — pedido #...
R02 PASS — pedido #...
P01 PASS
U01 PASS
U02 PASS
U03 PASS

Erros visíveis: nenhum
Diferenças baseline/candidato: nenhuma
Observações/evidências: ...
```

Depois da devolução, a parte técnica deve inspecionar os dois `debug.log`, estados finais e tabelas.
O consenso para merge exige todos os casos obrigatórios PASS, logs sem erro novo atribuível à branch
e nenhuma perda/mutação indevida dos pedidos atravessados pelo upgrade.

## 10. Registro de aceite — 2026-09-07

- Todos os casos C01, B01, S01, S02, O01, L01, R01, R02, P01 e U01–U03 foram executados e aprovados
  manualmente pelo responsável pela regressão.
- Auditoria posterior do executor: os dois `debug.log` tinham 0 bytes; as fixtures QA 990001–990099
  estavam removidas; candidato e instalação atualizada exibiam Base 0.3.0, enquanto a coexistência
  candidata mantinha Pro 0.1.0 ativo sem erro.
- A instalação baseline foi convertida em candidata durante U02/U03, como previsto. Não recriar os
  volumes antes de um eventual diagnóstico complementar; `setup.sh --fresh` os apaga.
- Conclusão: **aprovado para merge e preparação de release**, aguardando autorização explícita para
  executar essas operações.
