# 🏁 MARCO — Assinatura (Subscription) funcionando ponta a ponta

> **Data do marco:** 26/06/2026 · **Versão:** 2.0.9 · **Branch:** `claude/sleepy-cray-kgcow0`
>
> Este documento congela o **maior marco do projeto**: o ciclo completo de
> assinatura do Mercado Pago rodando **dentro do CORE** do JetFormBuilder, e
> registra **tudo que aprendemos** (incluindo as nuances/diferenças vs Stripe) +
> o **plano de ação** de limpeza/reforço para depois de fechar os itens de core.
>
> **Regra de ouro do projeto (vale também aqui):** nunca supor — sempre conferir
> no código real (raiz dos plugins de referência) como a coisa de fato funciona.

---

## 0. TL;DR

- **Pay-now:** 100% funcional (redirect → pagamento → `Payment_Model` no CORE).
- **Subscription:** ✅ **ponta a ponta** — submit → redirect MP → autorização →
  webhook → assinatura **ACTIVE** → cobrança "initial" **Completed** em *Payments*
  → evento do form disparado → ligada ao Form Record → admin com **Suspend/Cancel**.
- **Tudo no CORE:** `Payment_Model` (tabela `payments`) e `SubscriptionModel`
  (tabela `subscriptions` da lib PayPal compartilhada) — aparece em *JFB → Payments*
  e *JFB → Subscriptions*, com relations/Query Builder/Profile Builder.
- **Prova (v2.0.9):** Subscription #20 `Active`; Related Payments "Initial payment"
  Completed +7.58 BRL; Note do WEBHOOK "status changed from APPROVAL_PENDING to ACTIVE".

---

## 1. O ciclo completo (como funciona, arquivo a arquivo)

```
[EDITOR] ação Subscription + plano escolhido (preapproval_plan = TEMPLATE)
   │
[SUBMIT] Default_With_Gateway_Executor::after_execute (core)
   └─> Base_Scenario_Gateway::after_actions  →  Subscription_Logic::after_actions()
         1. create_subscription()  → SubscriptionModel (APPROVAL_PENDING)
                                     + RecurringCyclesModel (tenure_type='REGULAR')
         2. create_resource()      → lê os termos do plano (Retrieve_Preapproval_Plan,
                                     memoizado) e POST /preapproval com auto_recurring
                                     INLINE (sem preapproval_plan_id) → { id, init_point }
         3. save_resource()        → billing_id = preapproval.id (chave do webhook)
         4. add_response(['redirect'=>init_point])  → core faz wp_redirect
         5. attach_record_id (after-send) → SubscriptionToRecordModel (sub ↔ record)
   │
[MP] pagador autoriza no checkout hospedado (cartão na página do MP)
   │
[WEBHOOK]  POST /wp-json/jfb-mercadopago/v1/webhook
   └─> MercadopagoWebHookBase::handle_webhook
         · resolve_type / resolve_data_id (body v2 OU query IPN)
         · SignatureValidator (enforça SE houver JFB_MP_WEBHOOK_SECRET; senão processa)
         · Dispatcher::dispatch(type, data_id)
              ├ 'payment'                         → PaymentNotification
              ├ 'subscription_preapproval'|'preapproval'         → PreapprovalNotification
              └ 'subscription_authorized_payment'|'authorized_payment' → AuthorizedPaymentNotification
   │
[O QUE O MP REALMENTE MANDA] (descoberto no painel): a COBRANÇA da assinatura chega
   como evento **`payment`** (payment.created), NÃO como subscription_authorized_payment.
   │
[PaymentNotification]  GET /v1/payments/{id} (fonte de verdade)
   · external_reference começa com 'jfbmp-sub-<id>'?  → é cobrança de ASSINATURA
   └─> SubscriptionPaymentRecorder::record(subscription, payment_id, amount, currency)
         · maybe_activate()  → set_active() (APPROVAL_PENDING → ACTIVE)  [Note do WEBHOOK]
         · Payment_Model "initial"|"renew" (Completed) + SubscriptionToPaymentModel
         · execute_event_for_subscription → Gateway_Success_Event | RenewalPaymentEvent
         · IDEMPOTENTE por transaction_id (= payment_id do MP)
```

**Status (PreapprovalNotification, se o tópico `subscription_preapproval` chegar):**
`authorized`→ACTIVE (+ reativação se vinha de SUSPENDED) · `paused`→SUSPENDED ·
`cancelled`→CANCELLED — cada um com guard de transição e evento do form.

---

## 2. Mercado Pago vs Stripe — nuances e diferenças (a tabela-chave)

| Tema | Stripe (referência) | Mercado Pago (o que fizemos) |
|---|---|---|
| **Modelo de assinatura** | Checkout Session `mode=subscription` sobre um **Price** recorrente | **Preapproval**. Mas: COM `preapproval_plan_id` e SEM `card_token_id` = fluxo **direto** (exige cartão no SEU site). Para **redirect** (cartão na página do MP) = preapproval **SEM plano**, com `auto_recurring` **inline** → `init_point`. |
| **Papel do "plano"** | Price recorrente é enviado direto (`line_items[].price`) | `preapproval_plan` é só **TEMPLATE**: lemos os termos (valor/freq/moeda) e mandamos **inline**. O plano não vira o id da preapproval. |
| **Redirect** | `session.url` | `init_point` |
| **Sinal de ATIVAÇÃO** | `checkout.session.completed` → ACTIVE | **Não há equivalente confiável.** Ativamos na **1ª cobrança aprovada** (evento `payment`). `subscription_preapproval` (status `authorized`) também ativa, *se* chegar. |
| **Tópico da COBRANÇA** | `invoice.paid` | **`payment`** (payment.created) — **não** `subscription_authorized_payment`! (descoberto no painel). Por isso o `payment` handler precisou virar subscription-aware. |
| **billing_id (id do gateway)** | Vem só depois, no `checkout.session.completed` (`sub_…`) | O MP devolve o `preapproval.id` **na hora** da criação → gravamos billing_id imediatamente. |
| **Correlação webhook↔assinatura** | `metadata.subscription_id` (na session + subscription_data) | `external_reference = 'jfbmp-sub-<id>'` (ecoado na preapproval **e** nas cobranças) + `billing_id`. |
| **payer_email** | Coletado no checkout hospedado | **Obrigatório no corpo** da preapproval. Em sandbox, `payer` e `collector` precisam ser **ambos teste ou ambos reais**. |
| **Webhook — tópicos** | `checkout.session.completed`, `invoice.paid`, `invoice.payment_failed`, `customer.subscription.updated/deleted` | `payment`, `subscription_preapproval`, `subscription_authorized_payment` (v2) **+ apelidos IPN** `preapproval`/`authorized_payment` (notification_url). |
| **Webhook — assinatura HMAC** | Signing secret | "Assinatura secreta" do painel de Webhooks ( **≠** Access Token). |
| **Segredo do webhook** | `whsec_…` | `JFB_MP_WEBHOOK_SECRET` (wp-config). Hoje: **opcional** (processa sem, re-verifica via GET); define = **enforça**. |
| **process_after (retorno)** | Vazio — fulfillment via webhook | **Igual** — `process_after()`/`process_status()` vazios de propósito; tudo via webhook. |

**Mesma base, mesma espinha:** `Scenario_Logic_Base`, `With_Resource_It`,
`add_response(['redirect'=>…])`, `SubscriptionModel`/`RecurringCyclesModel`/
`SubscriptionToRecordModel`/`SubscriptionToPaymentModel`, `SubscriptionUtils::
execute_event_for_subscription` (re-roda as ações do form fora da submissão).
**A divergência é só a API** (Stripe → Mercado Pago) e os tópicos/sinais acima.

---

## 3. Armadilhas que custaram tempo (gotchas) — e como resolvemos

1. **Cache de asset (aba renderizava JS velho).** O `?ver=` (constante do plugin)
   não mudava entre builds → navegador servia o JS cacheado; só `Ctrl+Shift+R`
   resolvia. **Fix:** versionar os assets por `filemtime` (muda sozinho).
2. **`card_token_id is required`.** Preapproval COM `preapproval_plan_id` e SEM card
   token = fluxo direto. **Fix:** `auto_recurring` inline (sem plano) → `init_point`.
3. **`Both payer and collector must be real or test users`.** payer_email e o dono do
   token não eram ambos de teste. **Não é bug** — é setup do MP. **Diagnóstico:** o
   erro agora mostra `[payer_email | token]`.
4. **E-mail do usuário de teste NÃO é o nickname.** `TESTUSER<id>` é login; o e-mail é
   `test_user_<outro_n>@testuser.com` (vem na criação via API `POST /users/test_user`).
5. **Webhook fail-closed.** Sem `JFB_MP_WEBHOOK_SECRET`, todo webhook tomava 401 →
   a assinatura (que depende SÓ do webhook) nunca ativava; o pay-now mascarava (ele
   confirma também no retorno do navegador). **Fix:** processar sem segredo (cada
   handler re-verifica via GET autenticado); enforçar quando o segredo existir.
6. **A cobrança da assinatura chega como evento `payment`** (não `subscription_
   authorized_payment`) → caía no handler de pay-now e era ignorada. **Fix:** o
   `PaymentNotification` detecta `external_reference = jfbmp-sub-<id>` e roteia para o
   `SubscriptionPaymentRecorder` (ativa + registra). **← esse foi o último elo.**
7. **Capability ao apagar.** Limpar a assinatura órfã com `delete()` quebrava: o
   submit roda como visitante e `Base_Db_Model::before_delete` exige `manage_options`.
   **Fix:** removida a limpeza (órfãs ficam; apagam pela tela admin).
8. **`tenure_type` casing.** `SubscriptionsView` filtra `'REGULAR'`; gravávamos
   `'regular'` → coluna "Billing Cycle" vazia. **Fix:** `'REGULAR'`.
9. **Flag desligada.** `JFB_MP_SUBSCRIPTIONS_ENABLED=false` não registrava a
   `Subscription_Logic` → "nada acontece" no submit. **Fix:** default `true`.

---

## 4. Setup necessário (registrar pra não esquecer)

**Sandbox (testar sem dinheiro real):**
1. Painel MP → *Suas integrações → app → Contas de teste*: criar **2** test users
   (vendedor + comprador). Pegar o **e-mail** real de cada um (`test_user_…@testuser.com`).
2. **Access Token do gateway** = o do **vendedor de teste**.
3. Campo **`payer_email`** do form = e-mail do **comprador de teste**.
4. Aba anônima → logar no MP como **comprador** → autorizar. (payer ≠ collector.)

**Webhook:**
- Registrar `https://SEUSITE/wp-json/jfb-mercadopago/v1/webhook` no painel (eventos
  Pagamentos + Assinaturas + Cobranças). O `notification_url` também é setado na
  própria preapproval (rede dupla; tratamos tópicos v2 **e** IPN).
- `define('JFB_MP_WEBHOOK_SECRET', '…')` (Assinatura secreta) → **enforça** a assinatura.
- `define('WP_DEBUG', true)` → logs: "Webhook recebido", "dispatch", "tópico não tratado".

**Flags (wp-config):**
- `JFB_MP_SUBSCRIPTIONS_ENABLED` (default `true`) — liga o cenário Subscription.
- `JFB_MP_WEBHOOK_SECRET` (opcional hoje; recomendado em produção).
- `JFB_MP_ACCESS_TOKEN` (opcional; fallback do token no contexto do webhook).

---

## 5. Itens do CORE (item "b") — STATUS

1. **Renovação** — `Payment_Model` "renew" + `RenewalPaymentEvent` no recorder
   (implementado; falta só OBSERVAR um 2º ciclo no sandbox).
2. ✅ **Suspend / Cancel** pelo admin — código correto (`PUT /preapproval` cancelled/
   paused via `Cancel_Subscription`/`Subscription_Suspend`) **+ conflito de rota
   resolvido** (ver §5.3). Falta o dono clicar e confirmar no sandbox.
3. ✅ **Refund** — `Refund_Payment` trata cobrança de assinatura (`transaction_id` já
   é o `payment_id` do MP) **+ conflito de rota resolvido**. Falta confirmar no sandbox.
4. ✅ **"Subscriber attached"** — o `SubscriptionPaymentRecorder` vincula o pagador na
   1ª cobrança (Payer + Payer_Shipping + `SubscriptionToPayerShipping`), igual ao
   Stripe no `checkout.session.completed`. Assinaturas NOVAS deixam de ficar "Not
   attached". (As já existentes não recebem retroativo.)

> ⚠️ Nada disso pôde ser testado por mim contra a API do MP (proxy bloqueia
> `api.mercadopago.com`). O **código está correto e embasado na referência**; a
> validação final é no sandbox do dono (criar assinatura nova → ver Subscriber,
> Suspend/Cancel, Refund).

---

## 5.1 Análise: EDITAR planos vs assinaturas vigentes (decisão registrada)

**Insight-chave (da nossa arquitetura):** as assinaturas usam `auto_recurring`
**inline** — os termos do plano são **fotografados (snapshot)** no momento da criação
(`Subscription_Logic::create_resource` lê o plano e embute os valores na preapproval).
Logo, **uma assinatura vigente NÃO referencia o plano**; ela é independente.

**Cenários hipotéticos se permitíssemos editar um plano:**
1. *Editar o VALOR de um plano com assinaturas ativas* → as ativas mantêm o valor
   fotografado (não mudam); só novas assinaturas pegam o novo valor. **Sem corrupção**,
   mas gera **expectativa errada** (quem edita acha que muda as vigentes — não muda).
2. *Editar FREQUÊNCIA* → idem; o `RecurringCyclesModel` das vigentes foi snapshot →
   permanece. Consistente com o modelo, mas confunde.
3. *Restrições do MP* → `PUT /preapproval_plan/{id}` só permite alterar **alguns**
   campos (não dá pra trocar moeda, etc.). Edição "completa" nem é possível pela API.
4. *Plano é o template do dropdown* → mudar o nome é inócuo; mudar valor surpreende o
   dono do form, que montou esperando o valor antigo.

**Decisão (agora):** **NÃO** implementar edição. Mantemos **criar + desativar
(excluir)** + **datas de criação/exclusão** (feito) + **nomenclatura clara** (feito —
"Excluído pelo dono", não o cru "cancelled"). Bate com o fallback do dono.

**Se um dia adicionar edição:** restringir a **campos seguros** (nome/descrição),
deixar EXPLÍCITO que só afeta **assinaturas futuras**, e preferir o padrão
**"duplicar plano com novos termos + desativar o antigo"** em vez de editar in-place.

---

## 5.3 Conflito de rota gateway-aware (resolvido — v2.0.11)

**Sintoma latente:** os botões admin de **Cancel/Suspend/Refund** montam a URL
`mercadopago/subscription/cancel|suspend/{id}` e `mercadopago/payment/refund/{id}`
(via `PayPal*::dynamic_rest_url`, estático). Mas o **proxy** registrava também os
endpoints **Shared PayPal** desses mesmos caminhos, com rota *gateway-aware*
`(?P<gateway>...)/...` cujo core (`Gateway_Endpoint::get_common_args`) **valida**
`gateway === gateway_id()` e `gateway_id()` deles é **'paypal'**. Se o WP casasse a
rota PayPal primeiro para uma URL `mercadopago/…`, devolveria **400** (gateway !=
paypal) e o nosso handler MP nunca rodava.

**Fix:** removi `PayPalCancelSubscription`/`PayPalSuspendSubscription`/
`PayPalRefundPayment` do `Proxy\RestApiController::routes()`. As **classes
continuam** (o admin usa só os estáticos `dynamic_rest_url`/`get_messages`), mas as
**rotas** PayPal não são mais registradas → só as nossas `mercadopago/…` atendem.
(Somos MP-only; não há assinatura/pagamento PayPal pra essas rotas servirem.)

---

## 5.2 Melhorias da aba de planos entregues (v2.0.10)

- **Moeda antes do valor** + **formato dinâmico por moeda** (símbolo/decimais/
  separador; CLP sem centavos) com **preview ao vivo** do valor formatado.
- **Label da aba** compacto: **"MercadoPago Settings"** (estilo "ActiveCampaign API").
- **Títulos de seção** agora GRANDES (18px) — diferenciam "blocos" (antes pareciam label).
- **Datas** de criação e de exclusão por plano + **nomenclatura** clara.
- **Descrição premium** + **popup "Como funciona?"** com docs e **links oficiais do MP**.

> ⚠️ A **formatação GLOBAL de moeda** (Payments/Subscriptions/recorder/sync) continua
> pendente como **módulo à parte** — ver §6.3. Hoje só a ABA de planos formata por moeda.

---

## 6. 🧹 PLANO DE AÇÃO pós-core (limpeza, organização, reforço, melhorias)

> Fazer **depois** de fechar o item "b". Objetivo: sair de "funciona" para
> "limpo, seguro e manutenível" — sem parecer remendo.

### 6.1 Dedup / arquitetura
- [ ] **Unificar a persistência de cobrança**: `AuthorizedPaymentNotification` ainda
      tem a mesma lógica do `SubscriptionPaymentRecorder` → refatorar para **usar o
      recorder** (e assim também passa a ATIVAR a assinatura). Hoje convivem por
      idempotência, mas é duplicação.
- [ ] **Extrair um trait** para os helpers repetidos nos 3 handlers:
      `find_subscription` (por billing_id / id), `already_processed`, `has_prior_payment`.
- [ ] Revisar `resolve_payer_email()` — hoje é heurístico; avaliar um **campo
      dedicado configurável** no editor (em vez de "primeiro e-mail do request").

### 6.2 Segurança (reforçar)
- [ ] **Webhook:** decidir a postura final. Opções: (a) manter fail-open + GET
      re-verificação (atual) e documentar `JFB_MP_WEBHOOK_SECRET` como hardening;
      (b) voltar a fail-closed **com onboarding claro** do segredo. Discussão a fundo
      (era o combinado do refund/segurança).
- [ ] **Remover o e-mail do payer da mensagem de erro** (era auxílio de debug) —
      ou gatear por WP_DEBUG. Não deve vazar em produção.
- [ ] Revisar todos os `WebhookConfig::log` para nunca registrarem dado sensível.
- [ ] Confirmar que o token do gateway é SEMPRE server-side (já é, nos planos; revisar
      o restante).

### 6.3 Robustez / dados
- [ ] ⭐ **Estrutura GLOBAL de formatação de moeda** (prioridade do dono): um módulo
      único que formata valores **por moeda** (símbolo/decimais/separador — `9,00` BRL
      vs `9.00`) em **TODO lugar**: tabelas de *Payments* e *Subscriptions*, o
      `SubscriptionPaymentRecorder`, o "Sync Plans" e qualquer exibição de valor.
      **STATUS (v2.0.14):** classe `includes/Money.php` criada + aplicada em
      `GrossColumn`/`BillingCycleColumn` (condicional a `gateway_id==='mercadopago'`),
      MAS no teste do dono **NÃO aplicou** (segue "10.00"). **A INVESTIGAR** (deixado
      de lado a pedido do dono): provavelmente (a) o **Loader** está carregando OUTRA
      cópia da lib Shared (não a nossa) → nossas colunas não rodam; ou (b) o
      `gateway_id` no `$record` da tabela não vem como `mercadopago`; ou (c) cache.
      Regra de ouro já documentada em `Money.php`: formatar só EXIBIÇÃO, nunca dado.
      Pendente também: "Amount" cru do single Payment + export CSV/PDF.
- [ ] **Assinaturas órfãs** (APPROVAL_PENDING que falharam): mecanismo de limpeza
      (ação admin em massa ou cron) — não dá pra apagar no submit (capability).
- [ ] **Idempotência ponta a ponta**: garantir que `payment` + `subscription_
      authorized_payment` para a MESMA cobrança nunca dupliquem (já cobre por
      transaction_id; documentar/testar o caso dos 2 tópicos).
- [ ] **Subscriber attachment** (item b) + exibir nome/e-mail na tabela.
- [ ] Tratar cobrança **recusada** que chega como `payment` (status != approved) →
      `Gateway_Failed_Event` (hoje o recorder só age no approved).

### 6.4 Limpeza de código e comentários
- [ ] Varredura de **comentários defasados** que ainda citam o fluxo antigo
      (`preapproval_plan_id` no corpo, "fail-closed", "fase 2", "INERTE").
- [ ] Remover **código morto** e imports não usados (auditor de imports do projeto).
- [ ] Padronizar nomes/PHPDoc dos handlers de webhook.

### 6.5 Documentação
- [ ] **Consolidar os MD** (HANDOFF, MEMORIA, WEBHOOK-SETUP, REFUND-ARCHITECTURE,
      TESTING-CHECKLIST, este MARCO) — hoje há sobreposição. Manter este MARCO como
      o "estado verdadeiro" e a MEMORIA como índice.
- [ ] Atualizar `TESTING-CHECKLIST.md` com o fluxo real validado (contas de teste,
      evento `payment`, setup do webhook).

### 6.6 Futuro (BR puro)
- [ ] **Pix** (aditivo) — depois de tudo acima estável.

---

## 7. Mapa dos arquivos-chave (assinatura + webhook)

| Arquivo | Papel |
|---|---|
| `compatibility/jet-form-builder/logic/subscription-logic.php` | Cenário: cria sub local + preapproval (auto_recurring inline) + redirect. |
| `compatibility/jet-form-builder/actions/create-preapproval.php` | `POST /preapproval` (auto_recurring inline → init_point, sem card token). |
| `compatibility/jet-form-builder/logic-repository.php` | Registra `Subscription_Logic` (gated por `JFB_MP_SUBSCRIPTIONS_ENABLED`). |
| `RestEndpoints/Base/MercadopagoWebHookBase.php` | Recebe webhook: resolve tópico/data.id, assinatura, log de chegada. |
| `RestEndpoints/SignatureValidator.php` | HMAC x-signature; sem segredo → processa (re-verifica via GET). |
| `RestEndpoints/WebhookEvents/Dispatcher.php` | Roteia tópico (v2 + apelidos IPN) → handler. |
| `RestEndpoints/WebhookEvents/PaymentNotification.php` | `payment`: pay-now **e** detecção de cobrança de assinatura. |
| `RestEndpoints/WebhookEvents/SubscriptionPaymentRecorder.php` | **Ponto único**: ativa sub + grava Payment_Model + dispara evento. |
| `RestEndpoints/WebhookEvents/PreapprovalNotification.php` | `subscription_preapproval`: status (ACTIVE/SUSPENDED/CANCELLED) + eventos. |
| `RestEndpoints/WebhookEvents/AuthorizedPaymentNotification.php` | `subscription_authorized_payment`: cobrança (a unificar com o recorder). |
| `includes/Shared/**` (`Jet_FB_Paypal\*`) | Lib agnóstica: models/views/admin de Subscriptions. **NÃO mexer.** |

---

## 8. Histórico de versões desta fase

| Ver | Commit | O quê |
|---|---|---|
| 2.0.9 | `70222b6` | Cobrança via evento `payment` → ativa + registra (o último elo). |
| 2.0.8 | `cf2eeef` | Webhook processa sem segredo (GET re-verifica) + apelidos IPN + logs. |
| 2.0.7 | `f6dd65d` | Remove cleanup que exigia caps + diagnóstico payer/collector. |
| 2.0.6 | `6361ba1` | Preapproval com `auto_recurring` inline (corrige card_token_id). |
| 2.0.5 | `57a7b89` | Liga o cenário Subscription (flag) + casing `REGULAR`. |
| 2.0.4 | `18376b2` | Cache-bust por `filemtime` + ajustes finos de UI. |
| (2.0.3) | — | Base anterior (aba de planos nativa, pay-now ok). |

---

**Próximo passo recomendado:** fechar o item "b" (Suspend/Cancel → Subscriber
attached → Refund) e, em seguida, executar o **§6 (plano de ação)**.
