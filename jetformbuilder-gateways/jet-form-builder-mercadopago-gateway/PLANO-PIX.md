# 🟢 PLANO DE IMPLEMENTAÇÃO — PIX no Pay Now (Checkout Pro)

> **Status:** PLANO (não implementado). Foco TOTAL no Pix. **Sem alterar o CORE**
> (`CORE-PLUGINS/**`), **sem mexer na lib Shared** e **sem quebrar** cartão/saldo.
> A configuração da CONTA Mercado Pago (chave Pix etc.) é feita pelo dono — o
> passo a passo está na seção 8.

---

## 1. Objetivo e garantias

Permitir que um formulário Pay Now aceite **Pix** no Checkout Pro: o pagador escolhe
Pix, o Mercado Pago gera o QR/copia-e-cola **na própria página do MP** (não no nosso
site), paga pelo app do banco, e o pagamento é confirmado **via webhook**. Quando
confirmado, as ações do formulário rodam (e-mail, post, etc.).

**Garantias (inegociáveis):**
- **Zero** alteração no CORE e na lib Shared (`includes/Shared/**`).
- **Aditivo:** quem não habilitar Pix continua 100% como hoje (cartão, `binary_mode`).
- Integração via os **filtros que já existem** (`jet-form-builder/mercadopago/preference`)
  + o nosso `pay-now-logic.php` (que já é nosso e já foi ajustado na 1B). O
  `create-preference.php` **não é tocado**.
- O Access Token nunca vai ao cliente (mantido).

---

## 2. Por que o Pix não funciona hoje (2 camadas — confirmado no código + doc MP)

1. **SYNC / conta:** `GET /v1/payment_methods` (`fetch-payment-methods.php`) só lista
   meios **ativos** da conta. Sem **chave Pix** cadastrada/conta apta, `bank_transfer`
   não vem. → resolvido pela **config da conta** (seção 8).
2. **`binary_mode: true`** (`create-preference.php::build_preference`): a doc do MP
   confirma que binary_mode **rejeita pagamentos `pending`/em processamento**. O Pix é
   **assíncrono** (fica `pending` até o pagador pagar) → é excluído do checkout, mesmo
   sem estar em `excluded_payment_types`.
3. **Default exclui `bank_transfer`** (`Payment_Methods_Config::DEFAULT_EXCLUDED`) → o
   Pix só é oferecido se o form **não** excluir esse tipo (a UI por-form já permite).
4. **Retorno trata `pending` como ERRO** (`pay-now-logic.php::process_after`): hoje, se
   o status no retorno não é `approved`, cai em `on_error()` → marca **VOIDED** e exibe
   erro. Com Pix, o pagador volta (ou o MP redireciona) frequentemente em `pending`
   (ainda não pagou) → **viraria erro indevido**.

> **Resumo:** o backend de confirmação assíncrona JÁ existe (webhook
> `PaymentNotification` + `PaymentFulfillment` foram feitos para "aba fechada / Pix
> futuro"). Falta: **(a)** conta com Pix, **(b)** não excluir `bank_transfer`,
> **(c)** `binary_mode=false` quando o form aceita Pix, **(d)** tratar o retorno
> `pending` com graça.

---

## 3. Como o Pix funciona no Checkout Pro (fluxo-alvo)

1. Form enviado → criamos a **preference** (sem excluir `bank_transfer`, com
   `binary_mode=false`) → redirect para o `init_point` do MP.
2. No checkout do MP o pagador escolhe **Pix** → o **MP gera o QR/copia-e-cola** e
   mostra "aguardando pagamento". (Nós **não** geramos QR — isso é o MP.)
3. O pagador paga pelo app do banco. O pagamento nasce `pending` e vira `approved`.
4. **Confirmação (a que vale):** o MP chama o nosso **webhook** (`payment`,
   `external_reference = jfbmp-<form>-<uniq>`) → `PaymentNotification::confirm()` faz
   `CREATED→COMPLETED` (atômico) → dispara `PaymentFulfillment` → **as ações do form
   rodam**. (Tudo isso JÁ existe.)
5. **Retorno do navegador (cosmético):**
   - se o pagador esperou e pagou → volta `approved` → sucesso (já funciona);
   - se voltou antes de pagar → volta `pending` → **mostrar "Pix gerado, aguardando
     pagamento"** (NOVO — hoje vira erro). A linha fica `CREATED`; o webhook conclui.

---

## 4. Decisões de arquitetura

- **Onde ligar/desligar o Pix:** inferir do `Payment_Methods_Config` (que já é por-form
  e isolado das credenciais). Se o form **não exclui** `bank_transfer` → "aceita Pix /
  assíncrono". Sem campo novo, sem UI nova. (Boleto = `ticket` é análogo; o mesmo
  mecanismo cobre os dois, mas o foco aqui é Pix.)
- **`binary_mode` condicional:** quando o form aceita Pix → `binary_mode=false` via o
  filtro `jet-form-builder/mercadopago/preference`. Senão → mantém `true` (hoje). Assim
  só os forms com Pix mudam de comportamento.
- **Confirmação = webhook** (já é a fonte de verdade). O retorno é só UX.
- **Reconciliação:** o reconciliador WP-Cron já varre `CREATED` parados — cobre o Pix
  gerado e não pago (expira sozinho).

---

## 5. Componentes (o que muda, onde, e o risco)

| # | Mudança | Arquivo | Mecanismo | Risco |
|---|---|---|---|---|
| C1 | Helper "form aceita o tipo X?" (e "aceita assíncrono?") | `Payment_Methods_Config.php` (nosso) | método novo `accepts_type()` / `accepts_async()` | nulo (aditivo) |
| C2 | `binary_mode=false` + não excluir `bank_transfer` quando o form aceita Pix | **novo** `Pix_Support.php` (nosso) → hook em `…/preference` | filtro existente | baixo (só afeta forms c/ Pix) |
| C3 | Retorno `pending`/`in_process` → "aguardando", **não** erro/void | `pay-now-logic.php::process_after` (nosso) | novo branch antes do `on_error` | baixo (aditivo; cartão segue igual com binary_mode) |
| C4 | Mensagem "Pix gerado — conclua o pagamento" | `pay-now-logic.php` + i18n | novo status de retorno | nulo |
| C5 | (Opcional) nota na aba: "Pix ativa modo assíncrono" | `mp-plans-settings.js` / `Plans_Page.php` | aditivo | nulo |
| C6 | (Opcional) `date_of_expiration` do Pix (validade do QR) | `Pix_Support.php` via filtro | filtro | baixo |

**Nada no CORE. Nada na lib Shared. `create-preference.php` intocado** (tudo via filtro).

### Detalhe do C3 (o ponto sensível)
No `process_after`, hoje: `if ('approved' !== $status) { on_error(...) }`. Passa a:
- `approved` → fluxo atual (salva COMPLETED, dispara sucesso).
- `pending` / `in_process` → **não** chama `on_error`; **não** marca VOIDED; mantém a
  linha `CREATED`; seta um status "pending" em memória e exibe a mensagem de espera
  (C4). O webhook conclui depois (idempotente; o fix da 1B já trata o caso "webhook
  venceu" no retorno).
- `rejected` / `cancelled` → continua em `on_error` (erro real).

> Importante: como `binary_mode=false` só vale para forms com Pix, o `pending` "bom"
> (Pix aguardando) só acontece nesses forms. Em forms só-cartão, nada muda.

---

## 6. Riscos e mitigações

- **binary_mode=false afeta o cartão** (passa a aceitar `in_process`): mitigado por só
  desligar quando o form aceita Pix. Para esses forms, um cartão em análise vira
  "aguardando" (e o webhook conclui) em vez de recusa imediata — comportamento normal
  do MP para checkout com meios assíncronos.
- **Retorno `pending` virar erro:** resolvido no C3.
- **Pix gerado e não pago:** linha fica `CREATED`; o reconciliador encerra/expira; o QR
  do MP expira (default ~30 min, ajustável no C6).
- **Conta sem Pix (sandbox):** Pix real exige **produção** com chave cadastrada
  (seção 8). Em sandbox o teste é limitado.
- **Idempotência exatamente-uma-vez:** já garantida (transição atômica
  `CREATED→COMPLETED` no webhook; fix 1B no retorno).

---

## 7. Plano de teste

**Sandbox (parcial):** criar form com `bank_transfer` ativo; enviar; conferir que o
checkout do MP **oferece Pix** e que o retorno `pending` mostra "aguardando" (sem erro)
e a linha fica `CREATED`.

**Produção (R$ 0,01):** com a conta real + chave Pix:
1. Enviar o form → escolher Pix → o MP gera o QR.
2. Pagar com o app de outro banco/conta.
3. Conferir o **webhook** confirmando (`payment` approved) → linha `COMPLETED` em
   *JFB → Payments* → ações do form rodaram (e-mail/post).
4. Repetir **fechando a aba** após gerar o QR (testa o caminho 100% webhook).
5. Gerar o Pix e **não pagar** → conferir que fica `CREATED` e expira sem virar venda.

**Regressão:** um form **só-cartão** (Pix excluído) deve manter `binary_mode=true` e o
comportamento atual idêntico.

---

## 8. Passo a passo da SUA configuração no Mercado Pago (pré-requisito)

> O Pix no Checkout Pro depende da CONTA. Sem isto, o código não tem o que oferecer.

1. **Use uma conta de PRODUÇÃO** (vendedor). Pix real não funciona de forma completa
   com usuários de teste/sandbox.
2. **Verifique a conta:** dados pessoais/empresa (CPF/CNPJ) e **conta bancária**
   validada no Mercado Pago (necessário para receber via Pix).
3. **Cadastre uma chave Pix** no Mercado Pago:
   - App Mercado Pago → **Pix** → *Cadastrar/Minhas chaves* (ou painel web →
     *Seu negócio → Configurações → Pix/Meios de pagamento*).
   - A chave pode ser **CPF/CNPJ, e-mail, celular ou aleatória**.
4. **Ative o Pix como meio de recebimento** (em *Meios de pagamento* do painel de
   vendedor, deixe o Pix habilitado para receber).
5. **Credenciais de PRODUÇÃO no gateway:** em *JetFormBuilder → Settings → Payments
   Gateways → Mercado Pago*, use o **Access Token de produção** (`APP_USR-…`). Com
   `TEST-…` o Pix tende a não aparecer.
6. **Webhook:** confirme que a `notification_url` do plugin está configurada e que o
   tópico **`payment`** está ativo (já usamos hoje para cartão/saldo).
7. **Confirme o sync:** na aba *MercadoPago Settings → Meios de pagamento*, clique
   **Sincronizar** — o **Pix** deve aparecer na lista (`bank_transfer`). Se aparecer, a
   conta está pronta; se não, revise os passos 1–4 (provável chave/condição da conta).

> Quando o passo 7 mostrar o Pix, me avise — aí eu implemento os componentes C1–C4
> (e C5/C6 se você quiser), testo e fecho a versão.

---

## 9. Ordem de implementação (quando liberado)

1. **C1** — helpers no `Payment_Methods_Config` (`accepts_type`/`accepts_async`) + teste.
2. **C2** — `Pix_Support` (hook em `…/preference`: `binary_mode=false` + remove
   `bank_transfer` dos excluídos quando o form aceita Pix) + teste standalone.
3. **C3 + C4** — branch `pending` no `process_after` + mensagem de espera + teste.
4. (Opcional) **C5** nota na aba; **C6** validade do QR.
5. Teste sandbox + **teste produção R$ 0,01** (seção 7) → bump de versão → `.zip`.

**Resultado:** um form que **não exclui Pix** passa a oferecer Pix no Checkout Pro, com
confirmação assíncrona via webhook e UX correta no retorno — sem afetar os demais forms,
o cartão, o saldo, o core ou a lib Shared.
