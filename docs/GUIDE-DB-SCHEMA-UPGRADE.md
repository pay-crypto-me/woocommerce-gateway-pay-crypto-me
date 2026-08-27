# [GUIDE] Mudando o schema das tabelas custom (bump de `DB_VERSION`)

> **Nota de origem:** este guia foi escrito em 2026-08-27, junto com o mecanismo que ele descreve
> (`DbInstaller::is_current()`, o lock de instalação, a trilha `schema-tests.sh`), a partir da
> execução de [docs/archive/DONE-SCHEMA-UPGRADE-AND-STATIC-RECORDS.md](./archive/DONE-SCHEMA-UPGRADE-AND-STATIC-RECORDS.md)
> (arquivado/gitignored, pode estar ausente no seu checkout). **Ainda não passou por um bump de
> `DB_VERSION` real** — o checklist abaixo é a melhor previsão do fluxo, não um caminho já andado.
> Se você for a primeira pessoa a de fato adicionar/alterar uma coluna ou tabela depois desta data,
> espere que algum passo aqui esteja incompleto ou na ordem errada — **corrija este documento junto
> com o seu PR**, não só o código. Isso vale tanto para passos que faltaram quanto para passos que
> se provaram desnecessários.

Leia primeiro [CLAUDE.md § "Schema lifecycle and what `dbDelta()` will not do for you"](../CLAUDE.md)
— este guia é o "como fazer", aquele é o "por que funciona assim". Em especial, os fatos F1–F4
sobre o que `dbDelta()` silenciosamente ignora (nullability, remoção de coluna/índice, duas colunas
na mesma linha) não estão repetidos aqui.

## Quando este guia se aplica

- Adicionar uma coluna ou tabela nova.
- Mudar o tipo de uma coluna existente.
- Qualquer coisa que `dbDelta()` **não** faz sozinho (F1/F4 no CLAUDE.md): remover coluna/índice,
  mudar nullability, renomear, fazer backfill de dado. Para esses casos, ver a seção "Se `dbDelta`
  não resolve sozinho" abaixo — é o contrato da frente C, ainda não implementada.

Não se aplica a: mudar uma `option` do WordPress (sem `dbDelta` envolvido), ou qualquer coisa que não
toque as 4 tabelas custom listadas em `CLAUDE.md` § "Custom DB tables".

## Passo a passo

### 1. Mude o `CREATE TABLE` no `*GatewayActivate` certo

`PayCryptoMeBitcoinGatewayActivate` ou `PayCryptoMeLightningGatewayActivate`
(`includes/services/class-paycrypto-me-*-gateway-activate.php`). Regras que já valem hoje e
continuam valendo:

- **Uma coluna por linha.** `dbDelta()` parseia linha a linha (F3) — duas na mesma linha e a
  segunda é descartada sem erro.
- **Sem `IF NOT EXISTS`** — quebra a extração do nome da tabela que `dbDelta()` faz via regex.
- **Sem `FOREIGN KEY`** — `dbDelta()` não gerencia FK; use PK composta ou aplicação.
- Coluna nova que precisa aceitar valor ausente em linhas antigas: **não** declare `NULL` se a
  coluna vai existir numa tabela que já tem linhas em produção — pense num valor sentinela como
  `WALLET_ID_STATIC_ADDRESS` fez, ou aceite que ela só é preenchida daqui pra frente.

### 2. Bump `DbInstaller::DB_VERSION`

Em `includes/services/class-db-installer.php`. É uma string comparada via `version_compare()` —
incrementos simples (`'1'` → `'2'`) funcionam. Este é o único gatilho que faz `maybe_upgrade()`
rodar os activators de novo num site já instalado.

### 3. Gere o snapshot congelado **depois** de mudar o código, não antes

```bash
docker compose up -d wordpress
docker compose exec -T -w /var/www/html/wp-content/plugins/paycrypto-me-for-woocommerce \
  wordpress php tests/bin/dump-schema.php
```

Sem argumento, usa `DbInstaller::DB_VERSION` do código — então isso só funciona **depois** dos
passos 1 e 2. O script recusa sobrescrever um `v<N>.sql` existente de propósito (snapshot descreve
schema que já foi publicado); se precisar mesmo regenerar um antigo, apague o arquivo primeiro e
saiba que está reescrevendo um registro histórico.

O snapshot vira `src/trunk/tests/schema/v<N>.sql` e é o que o teste de convergência
(`test_upgrade_from_each_frozen_version_converges_to_a_fresh_install`, em
`tests/integration/SchemaUpgradeTest.php`) varre automaticamente a partir de agora — nenhum passo
extra de registro é necessário além de o arquivo existir.

### 4. Rode a trilha de schema

```bash
./scripts/schema-tests.sh
```

Isso instala **cada** snapshot congelado (incluindo o que você acabou de gerar) e roda
`DbInstaller::install()` por cima, comparando o resultado com uma instalação nova. Se falhar, é
porque a mudança do passo 1 não faz o que você imagina que faz — volte para os fatos F1–F4 no
CLAUDE.md antes de assumir que é bug no teste.

**Vale rodar um controle negativo pelo menos uma vez** (foi assim que esta trilha foi validada):
quebre a mudança de propósito (ex. declare a coluna nova `NULL` quando devia ser `NOT NULL`) e
confirme que o teste falha **nomeando a coluna certa**. Reverta depois. Sem isso, um teste de
convergência que nunca falhou é indistinguível de um que não consegue falhar.

### 5. Proteja qualquer caminho de pagamento que dependa da coluna/tabela nova

Se código em `includes/processors/` ou `includes/services/` vai ler a coluna nova, ele pode rodar
num request onde o schema ainda não foi upgradeado (site que acabou de atualizar o plugin mas cujo
`admin_init`/`upgrader_process_complete` ainda não disparou — ver CLAUDE.md). Consulte
`DbInstaller::is_current()` e degrade em vez de assumir que a coluna existe.

### 6. Atualize a documentação

- `CLAUDE.md` § "Custom DB tables" — se mudou o formato de uma tabela existente ou adicionou uma
  nova, a lista de colunas ali precisa refletir isso.
- `CLAUDE.md` § "Schema lifecycle and what `dbDelta()` will not do for you" — só se o mecanismo em
  si mudou (não para toda mudança de schema; a maioria só toca a seção "Custom DB tables" acima).
- `scripts/check-docs-drift.sh` — a contagem de "custom database tables" (hoje `4`) é verificada
  automaticamente contra `grep -c '"CREATE TABLE '`; se você adicionou uma tabela, `./scripts/check-docs-drift.sh`
  vai pegar a divergência sozinho — não precisa lembrar de atualizar o número à mão, só rodar o
  script e corrigir o que ele apontar.
- `src/trunk/CHANGELOG.md` — só se a mudança tiver efeito observável pelo usuário (nova
  funcionalidade habilitada pela coluna nova, por exemplo). Uma coluna adicionada só para uso
  interno futuro não precisa de changelog.

### 7. Verificação de release

Nenhuma mudança no `release.sh` é necessária — ele já roda `check-docs-drift.sh` e
`check-platform-pin.sh` automaticamente (não dependem do stack de dev), e `schema-tests.sh` já é um
item manual do checklist em [GUIDE-RELEASE.md](./GUIDE-RELEASE.md) § "Trilha de schema", no mesmo
molde do smoke de host mínimo. Se este guia e o `GUIDE-RELEASE.md` divergirem sobre isso no futuro,
o `GUIDE-RELEASE.md` é a fonte de verdade para o que entra no checklist de release.

## Se `dbDelta` não resolve sozinho

Remoção de coluna, rename, backfill de dado — nada disso `dbDelta()` faz (F4 no CLAUDE.md), e não
há mecanismo implementado para isso ainda (frente C do plano arquivado, deliberadamente adiada). O
contrato para quando alguém escrever o primeiro passo desse tipo já está registrado em
`CLAUDE.md` § "Schema lifecycle...", último bloco. Resumo: `dbDelta` continua rodando primeiro como
baseline declarativa; o que ele não cobre vira um passo imperativo, idempotente, com verificação de
pós-condição, e `install()` só grava a versão se tudo verificar. Antes de implementar isso, leia
esse contrato — não este guia, que ainda não cobre esse caminho.

## Coisas que este guia provavelmente não previu

Como nenhum bump real aconteceu ainda, é bem possível que faltem aqui: o que fazer se o snapshot
gerado no passo 3 divergir do que você esperava (collation/engine diferente entre ambientes?), como
lidar com uma coluna que precisa de índice em tabela grande num site com muitos pedidos, e se o
checklist do `GUIDE-RELEASE.md` precisa de um passo extra quando `DB_VERSION` muda numa release
específica (hoje ele só diz "se `DB_VERSION` mudou, gere o snapshot" — não detalha nada sobre
migração em produção fora do que `DbInstaller::maybe_upgrade()` já faz sozinho). Trate a ausência
dessas respostas como sinal de que ainda não foram necessárias, não como garantia de que não vão
ser.
