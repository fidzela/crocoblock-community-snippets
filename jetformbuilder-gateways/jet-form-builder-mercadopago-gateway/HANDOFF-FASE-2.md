# 📋 HANDOFF — JetFormBuilder Mercado Pago Gateway
### Resumo do projeto + Plano de Ação da Fase 2 (Assinaturas + erradicação do Stripe)

> **Como usar este documento:** ele é o ponto de partida da PRÓXIMA sessão. Está
> 100% embasado no código (varredura `file:line`) e na documentação oficial do
> Mercado Pago. Leia as seções 1–6 para contexto, e use a **seção 8 (Plano de
> Ação)** como roteiro de execução. Objetivo macro: **deixar de ser um remendo do
> addon Stripe e virar, de fato, um plugin Mercado Pago** — sem erro fatal.

**Data:** 2026-06-16 · **Branch:** `claude/sweet-rubin-kyelm1` · **PR:** #7 (draft)
**Versão testada em produção:** **v3** (`jfb-mercadopago-WEBHOOK-v3-claude.zip`) · **Repo está em v4** (ver Adendo 4.1)

---

## Índice
1. [O que JÁ funciona (validado)](#1)
2. [Linha do tempo desta sessão](#2)
3. [Adendos importantes (v3/v4, ambiente, segurança)](#3)
4. [Comparativo: como cada gateway trabalha (e onde são parecidos)](#4)
5. [Modelo de Assinaturas do Mercado Pago (Preapproval)](#5)
6. [Inventário completo de ocorrências "stripe"](#6)
7. [Subsistema de assinaturas — estado de cada arquivo + risco de FATAL](#7)
8. [PLANO DE AÇÃO — Fase 2](#8)
9. [Decisões em aberto + critérios de aceite](#9)
10. [Referências](#10)

---

<a name="1"></a>
## 1. O que JÁ funciona (validado nesta sessão)

| Item | Estado | Evidência |
|---|---|---|
| **Plugin ativa sem erro fatal** | ✅ | Confirmado no site (prioridade nº 1) |
| **Pay-now (Checkout Pro) ponta a ponta** | ✅ **TESTADO** | Form → preference (R$10) → checkout MP → cartão `APRO` → retorno → registro `COMPLETED` |
| **Webhook registrado e seguro** | ✅ | `POST /wp-json/jfb-mercadopago/v1/webhook` responde 200 a notificações REAIS (`payment.created`, ids reais) |
| **Validação `x-signature` (HMAC) fail-closed** | ✅ | Sem segredo → 401; com segredo certo → valida |
| **Anti-fraude de valor + idempotência** | ✅ | `transaction_amount` vs `amount_value`; só age em `CREATED→COMPLETED` |
| **Botão SYNC (valida Access Token)** | ✅ | `Retrieve_Balance` → `GET /users/me` |
| **Label do editor "Access Token"** | ✅ | `assets/js/builder.admin.js` |
| **Credenciais via UI (Access Token) + wp-config (segredo)** | ✅ | `WebhookConfig` lê settings globais; segredo só no wp-config (mais seguro) |

**Fluxo pay-now confirmado (a fonte da verdade do código):**
`Pay_Now_Logic::after_actions()` cria a preference (`POST /checkout/preferences`), salva `Payment_Model` (`status=CREATED`, `transaction_id`=id da preference, `initial_transaction_id`=`external_reference`), redireciona para `init_point`/`sandbox_init_point`. No retorno, `process_after()` faz `GET /v1/payments/{id}` e exige `approved`.

---

<a name="2"></a>
## 2. Linha do tempo desta sessão (commits na branch)

| Commit | O que fez |
|---|---|
| `e3996f8` | Webhook receiver real: rota registrada, `x-signature`, Dispatcher por tópico `payment`, `PaymentNotification`, `notification_url` na preference, persiste `external_reference` |
| `53d9be4` | Manifest conforme MP (omite `request-id` ausente), log diagnóstico de 401, lookup "não encontrado" → 200 |
| `f53eb06` | Assinatura **fail-closed** (sem segredo → 401) + seção de segurança no doc |
| `cf676b2` | **SYNC** corrigido (`users/me` + `: string`), labels MP, config via UI (settings globais) |
| `64d3475` | **Revert** do reaproveitamento do campo `public`: segredo do webhook volta ao wp-config (mais seguro) |

---

<a name="3"></a>
## 3. Adendos importantes

### 3.1 Versão testada (v3) vs repo (v4)
- O site foi testado com a **v3**. O repositório está na **v4** (commit `64d3475`).
- **Única diferença funcional:** na v3 o campo `public` foi rotulado "Webhook Secret Signature" e o `WebhookConfig` lia o segredo dele; a v4 reverteu isso (segredo só no wp-config; `public` volta a "Public Key").
- Como o usuário usa a **constante** `JFB_MP_WEBHOOK_SECRET` no wp-config (que tem precedência), **v3 e v4 são equivalentes na prática** para o setup atual. A nova sessão deve trabalhar a partir do **repo (v4)** e, se for testar, reinstalar a v4.

### 3.2 Ambiente de teste (crucial)
- **Não existe toggle de "modo teste" do JFB** para Stripe/MP (só PayPal). Nosso código detecta teste pelo **prefixo do Access Token**: `TEST-` → `sandbox_init_point` (ver `Pay_Now_Logic::resolve_redirect_url`).
- **Combinações válidas:** `TEST-` + cartão de teste (nome `APRO`=aprovado, `OTHE`=recusado) → sandbox; `APP_USR-` + cartão real → produção. **Misturar produção + conta/cartão de teste = recusa.**
- Para o **webhook reconciliar um pagamento de teste**, o token que ele usa (`JFB_MP_ACCESS_TOKEN` ou settings global) precisa ser **do mesmo ambiente** (`TEST-`).

### 3.3 Plugins de segurança (lição aprendida)
- O plugin **ASE** (e similares: Wordfence, etc.) que **bloqueia REST API para não-autenticados** barra o webhook **antes** do nosso código (gera 401). Solução correta: **manter a proteção global e liberar SÓ a rota** `jfb-mercadopago/v1/webhook` na allowlist do plugin (a rota se autoprotege com HMAC). Documentado em `WEBHOOK-SETUP.md`.

### 3.4 Escopo da Fase 2 (mudança de plano)
- **Pix fica para DEPOIS.** A nova **Fase 2 = Assinaturas (Preapproval) + erradicação total do Stripe**, sem erro fatal. (Antes "assinaturas" era fase 3.)
- Métodos de pagamento do produto final: **cartão de crédito, débito e Pix**. **Boleto: jamais.**

---

<a name="4"></a>
## 4. Comparativo: como cada gateway trabalha (e onde são parecidos)

> O ponto-chave que torna o port viável: **a ARQUITETURA do addon é a mesma** (cenários do JFB, biblioteca `Shared/`, padrão de webhook+dispatcher, classe "logic" por cenário). **Só muda a API** (endpoints, payloads, nomes de evento).

| Aspecto | Stripe | Mercado Pago | Parecido? |
|---|---|---|---|
| **Checkout (redirect)** | Checkout Session → `url` | Preference (pay-now) / Preapproval (sub) → `init_point` | ✅ Mesmo padrão (criar recurso → redirecionar → retorno) |
| **Confirmação** | Webhook + retorno | Webhook + retorno | ✅ Idêntico conceitualmente |
| **Classe "logic" por cenário** | `Pay_Now_Logic`, `Subscription_Logic` | iguais | ✅ Mesma estrutura |
| **Camada de dados (Shared/)** | DbModels/QueryViews/Resources/Utils | **as MESMAS** (agnósticas) | ✅ **Reaproveitada como está** |
| **Base URL da API** | `api.stripe.com` | `api.mercadopago.com` | ❌ |
| **Corpo da requisição** | form-encoded | **JSON** | ❌ |
| **Valor monetário** | centavos (×100) | decimal BRL (sem ×100) | ❌ |
| **Eventos de webhook** | `invoice.paid`, `customer.subscription.*` | **tópicos** `payment`, `subscription_preapproval`, `subscription_authorized_payment` | ❌ |
| **Assinatura do webhook** | header `Stripe-Signature` | header `x-signature` (HMAC já implementado) | ❌ (mas já feito) |
| **Modelo de assinatura** | Product+Price / Subscription / Invoice | **Preapproval Plan / Preapproval / authorized_payment** | ❌ (ver seção 5) |

**Conclusão:** portar = trocar a **camada de API** (actions, endpoints, payloads) e os **gatilhos de webhook** (nomes → tópicos), **mantendo** a camada de dados e o esqueleto do addon.

---

<a name="5"></a>
## 5. Modelo de Assinaturas do Mercado Pago (Preapproval)

**Endpoints:**
- `POST /preapproval_plan` — cria um **plano** (template): `reason`, `auto_recurring {frequency, frequency_type, transaction_amount, currency_id}`, `back_url`. (Opcional.)
- `POST /preapproval` — cria a **assinatura**. Com plano (`preapproval_plan_id`) ou avulsa (`auto_recurring` inline). Campos: `reason`, `external_reference`, `payer_email`, `back_url`, e `card_token_id` (cobrança direta) **ou** retorna **`init_point`** para o pagador autorizar (igual pay-now).
- `GET /preapproval/{id}` — consulta (fonte de verdade do status).
- `PUT /preapproval/{id}` — **gerencia**: `status` = `cancelled` | `paused` | `authorized` (reativar).
- `GET /preapproval/search` — lista/busca.
- `GET /preapproval_plan/search` — lista planos (o botão "Refresh Plans").

**Status da Preapproval:** `pending` (precisa autorizar) · `authorized` (ativa, debitando) · `paused` · `cancelled`.

**Tópicos de webhook (a ativar no painel):**
- `subscription_preapproval` — criação/atualização de status da assinatura.
- `subscription_authorized_payment` — os pagamentos recorrentes gerados.
- `payment` — o pagamento em si (já tratado por `PaymentNotification`).

**Mapa Stripe → Mercado Pago (a "tabela de tradução" do port):**

| Stripe | Mercado Pago | Onde tocar |
|---|---|---|
| Product + Price (recorrente) | `POST /preapproval_plan` | nova action `Create_Preapproval_Plan` (opcional) |
| Checkout Session `mode=subscription` | `POST /preapproval` → `init_point` | `subscription-logic.php` + nova action `Create_Preapproval` |
| Subscription (status) | Preapproval (`pending/authorized/paused/cancelled`) | mapear para `SubscribeNow` (APPROVAL_PENDING/ACTIVE/SUSPENDED/CANCELLED) |
| `checkout.session.completed` | tópico `subscription_preapproval` (status `authorized`) | handler novo → `update_status_soft(ACTIVE)` |
| `invoice.paid` | tópico `subscription_authorized_payment` + `payment` | handler novo → cria `Payment_Model` INITIAL/RENEWAL, dispara `Gateway_Success_Event`/`RenewalPaymentEvent` |
| `customer.subscription.updated` (pause) | `subscription_preapproval` (status `paused`/`authorized`) | handler → SUSPENDED/REACTIVATE event |
| `customer.subscription.deleted` | `subscription_preapproval` (status `cancelled`) | handler → CANCELLED/EXPIRED event |
| `invoice.payment_failed` | `subscription_authorized_payment` (status rejected) | handler → `Gateway_Failed_Event` |
| Cancel: `DELETE /v1/subscriptions/{id}` | `PUT /preapproval/{id}` `status=cancelled` | `cancel-subscription.php` |
| Pause: `pause_collection` | `PUT /preapproval/{id}` `status=paused` | `subscription-suspend.php` |
| Reactivate | `PUT /preapproval/{id}` `status=authorized` | `subscription-suspend.php` (ou novo reactivate) |
| Refund: `POST /v1/refunds` | `POST /v1/payments/{id}/refunds` | `refund-payment.php` |

> **Insight de ouro:** o `init_point` da Preapproval funciona **exatamente** como o `init_point` da preference do pay-now. Logo, **toda a mecânica de redirect/retorno do pay-now é reaproveitável** para a autorização da assinatura.

**Fontes:** [Criar assinatura (POST /preapproval)](https://www.mercadopago.com.br/developers/pt/reference/subscriptions/_preapproval/post) · [Criar plano](https://www.mercadopago.com.mx/developers/pt/reference/subscriptions/_preapproval_plan/post) · [Atualizar (PUT)](https://www.mercadopago.com.pe/developers/pt/reference/subscriptions/_preapproval_id/put) · [Pagamento autorizado](https://www.mercadopago.com.co/developers/en/docs/subscriptions/integration-configuration/subscription-no-associated-plan/authorized-payments) · [Webhooks](https://www.mercadopago.com.pe/developers/en/docs/your-integrations/notifications/webhooks)

---

<a name="6"></a>
## 6. Inventário completo de ocorrências "stripe"

> **23 referências CRITICAL-RUNTIME** + ~8 cosméticas. Agrupadas por arquivo.

### 6.1 CRITICAL-RUNTIME (quebram em runtime)

**`includes/compatibility/jet-form-builder/rest-endpoints/subscription-suspend.php`** (9):
- L17 `return 'stripe/subscription/suspend/(?P<id>\d+)';` → rota no caminho errado
- L45 `if ( 'stripe' !== $gateway )` → **rejeita assinaturas MP**
- L46 `'error' => 'not_a_stripe_subscription'`
- L49-51 `$stripe_sub_id`, `'stripe_subscription_id_empty'`
- L74 `'https://api.stripe.com/v1/subscriptions/' . ...` → **404 no MP**
- L87 `'stripe_http_error'`, L98 `'stripe_api_error'`, L111 `'Subscription paused on Stripe'`

**`includes/compatibility/jet-form-builder/rest-endpoints/cancel-subscription.php`** (4):
- L15 `return '/stripe/subscription/cancel/(?P<id>[\d]+)';` → rota errada
- L59 `// 'https://api.stripe.com/v1/subscriptions/...'` **COMENTADO** → `wp_remote_request()` sem URL → **WP_Error/fatal ao chamar**
- L69/L78 mensagens "Stripe cancel..." · L101 text domain `jet-form-builder-stripe-gateway`

**`includes/compatibility/jet-form-builder/rest-endpoints/refund-payment.php`** (3) — ⚠️ **sempre carregado** (não está atrás do flag):
- L20 `return 'stripe/payment/refund/(?P<id>\d+)';` → rota errada
- L51 `//CLAUDE VERIFICAR'https://api.stripe.com/v1/invoice_payments?...'`
- L89 `wp_remote_post( 'https://api.stripe.com/v1/refunds', ... )` → **404 no MP**

**`includes/compatibility/jet-engine/manager.php`** (10) — caminho **JetEngine Forms** (legado):
- L130-132 lê `stripe_public`, `stripe_secret`, `stripe_currency`
- L224-226 `'stripe_args' => ['stripe_session_id'=>..., 'stripe_public_key'=>...]`
- L272-273, L313, L316 checagens/fallbacks de `stripe_secret`
- L352 `v-if="'stripe' === gateways.gateway"` · L354 `_e('Stripe settings:', ...)` · L357-375 campos `stripe_*`

### 6.2 COSMÉTICO (comentários, doc, inerte)
- `api-methods/base-api-method.php` L21, `actions/base-action.php` L36, `webhook-manager.php` L12/L21 — comentários "guia de port".
- `webhook-manager.php` L57/L89 — `api.stripe.com/v1/webhook_endpoints` (**inerte**; MP não cria webhook por API — usa `notification_url` + painel).
- `vendor/` — metadados do composer (ignorar).

---

<a name="7"></a>
## 7. Subsistema de assinaturas — estado + risco de FATAL

### 7.1 Estado de cada arquivo

| Arquivo | Estado | Ação na Fase 2 |
|---|---|---|
| `logic/subscription-logic.php` | **STRIPE-SHAPED** (`set_mode('subscription')`, `set_price_id`, `Retrieve_Price`) | **Reescrever** para `POST /preapproval` |
| `actions/create-checkout-session.php` (modo sub) | usa `mode`/`price_id` (no-ops no MP) | criar `Create_Preapproval` (e talvez `Create_Preapproval_Plan`) |
| `views/subscription-view.php` | **MP-PORTED** ("Refresh Plans From Mercadopago") | revisar campo plano → `preapproval_plan_id` |
| `RestEndpoints/WebhookEvents/Dispatcher.php` | **MP-PORTED** (tópico `payment` → `PaymentNotification`) | **adicionar** casos `subscription_preapproval` e `subscription_authorized_payment` |
| `WebhookEvents/{CheckoutSessionCompleted, InvoicePaid, InvoicePaymentFailed, CustomerSubscriptionUpdated, CustomerSubscriptionCancelled}.php` | **DORMENTES** (esperam payload do Stripe; não referenciados pelo Dispatcher atual) | **reescrever** p/ payloads MP + religar no Dispatcher |
| `rest-endpoints/cancel-subscription.php` | **BROKEN** (stripe path + URL comentada) | rota→`mercadopago/`, API→`PUT /preapproval/{id}` status=cancelled |
| `rest-endpoints/subscription-suspend.php` | **BROKEN** (stripe path, check 'stripe', api.stripe) | rota→`mercadopago/`, check→'mercadopago', API→`PUT /preapproval/{id}` status=paused/authorized |
| `rest-endpoints/refund-payment.php` | **BROKEN** (sempre carregado) | rota→`mercadopago/`, API→`POST /v1/payments/{id}/refunds` |
| `rest-endpoints/fetch-mercadopago-plans.php` | **MP-ish** (`preapproval_plan/search`) | validar; já não dá fatal (gated) |
| `webhook-manager.php` | **INERTE** (api.stripe.com) | **remover/neutralizar** (MP usa `notification_url`) |
| `includes/Shared/**` (DbModels, QueryViews, Resources, Logic, FormEvents, Utils) | **PAYPAL-DERIVED, AGNÓSTICO** (namespace `Jet_FB_Paypal\*`) | **REAPROVEITAR como está** (não mexer) |

### 7.2 Mapa de risco de FATAL (ao ligar `JFB_MP_SUBSCRIPTIONS_ENABLED=true`)

| # | Arquivo:linha | Problema | Resultado | Correção |
|---|---|---|---|---|
| 1 | `subscription-suspend.php:45` | `if ('stripe' !== $gateway)` | rejeita assinatura MP (400) | → `'mercadopago'` |
| 2 | `*-subscription/suspend/refund.php` (rotas) | path `stripe/` | rota inacessível | → `mercadopago/` |
| 3 | `subscription-suspend.php:74`, `refund-payment.php:89` | `api.stripe.com` | 404 | → endpoints MP |
| 4 | **`cancel-subscription.php:59`** | URL **comentada** → `wp_remote_request` sem URL | **WP_Error/fatal ao chamar** | implementar `PUT /preapproval/{id}` |
| 5 | `actions/expire-checkout-session.php:11`, `actions/retrieve-price.php:9` | `action_endpoint()` **sem `: string`** | incompatível com abstract → **fatal no autoload (PHP 8)** | adicionar `: string` |
| 6 | `jet-engine/manager.php` (L130+) | lê `stripe_*` | JetEngine Forms quebra | → `mercadopago_*` ou remover compat JetEngine |
| 7 | text domains / mensagens | `jet-form-builder-stripe-gateway` | tradução errada / "Stripe" na UI | → domínio MP |

> **Nota de blindagem atual:** os 3 endpoints de assinatura (`Cancel/Suspend/Fetch_Plans`) **só são instanciados** se o flag estiver `true` (ver `rest-controller.php:52-56`), então **hoje não dão fatal**. Mas `Refund_Payment` é **sempre** carregado (rota errada + api.stripe.com) — não é fatal no boot, mas está quebrado.

### 7.3 Autoloader (para nomear classes/arquivos certo)
- **PSR-4 (1º):** `Namespace\Sub\ClassName` → `includes/Sub/ClassName.php` (preserva CamelCase/underscore).
- **Legacy (fallback):** `strtolower(str_replace('_','-', ...))` → `includes/sub/class-name.php`.
- Ex.: `...\Actions\Create_Preapproval` → carrega `includes/compatibility/jet-form-builder/actions/create-preapproval.php`. **A classe precisa bater com o arquivo** (foi o que quebrou o `Fetch_Stripe_Plans` e o SYNC).

---

<a name="8"></a>
## 8. PLANO DE AÇÃO — Fase 2 (Assinaturas MP + erradicação do Stripe)

> **Meta:** ligar `JFB_MP_SUBSCRIPTIONS_ENABLED` **sem erro fatal**, com assinaturas 100% MP-native, e **zero `api.stripe.com` em caminho ativo**. Trabalhar incremental, testando no sandbox (`TEST-`).

### Etapa 2.0 — Blindagem anti-fatal (faça PRIMEIRO, baixo risco)
1. Adicionar `: string` em `action_endpoint()` de `expire-checkout-session.php` e `retrieve-price.php` (e auditar TODAS as subclasses de `Base_Action`).
2. Corrigir `cancel-subscription.php:59` (a `wp_remote_request` sem URL) — mesmo que provisório, não pode ficar sem URL.
3. Conferir nome de classe × arquivo de **todos** os arquivos de assinatura (autoloader).
4. **Critério:** `JFB_MP_SUBSCRIPTIONS_ENABLED=true` ativa sem fatal e sem quebrar a REST.

### Etapa 2.1 — Criar assinatura (Preapproval)
1. Nova action `Create_Preapproval` (`POST /preapproval`) espelhando `Create_Checkout_Session`: monta `reason`, `external_reference`, `payer_email`, `back_url`, `auto_recurring` (ou `preapproval_plan_id`), `notification_url`. Retorna `init_point`.
2. (Opcional) `Create_Preapproval_Plan` (`POST /preapproval_plan`) para o cenário com plano.
3. Reescrever `subscription-logic.php::after_actions()`: salvar `SubscriptionModel` (`APPROVAL_PENDING`), criar a preapproval, redirecionar para `init_point` (reusar `resolve_redirect_url`). `process_after()` pode confirmar o status via `GET /preapproval/{id}`.
4. Ajustar `views/subscription-view.php` (campo do plano → `preapproval_plan_id`); validar o botão "Refresh Plans" (`fetch-mercadopago-plans` → `preapproval_plan/search`).
5. **Critério:** criar assinatura → redireciona → autoriza → `SubscriptionModel` vira `ACTIVE`.

### Etapa 2.2 — Webhook de assinaturas
1. `Dispatcher`: adicionar `case 'subscription_preapproval'` e `case 'subscription_authorized_payment'`.
2. **Handler `subscription_preapproval`** (reescrever a partir de `CheckoutSessionCompleted`+`CustomerSubscriptionUpdated`+`CustomerSubscriptionCancelled`): `GET /preapproval/{data.id}` → mapear status (`authorized`→ACTIVE, `paused`→SUSPENDED, `cancelled`→CANCELLED) → `Subscription::update_status_soft()` + disparar evento via `SubscriptionUtils::execute_event_for_subscription()`.
3. **Handler `subscription_authorized_payment`** (a partir de `InvoicePaid`/`InvoicePaymentFailed`): `GET /authorized_payments/{data.id}` → criar `Payment_Model` (INITIAL/RENEWAL via `PaymentsBySubscription`) → disparar `Gateway_Success_Event` (1º) / `RenewalPaymentEvent` (renovação) / `Gateway_Failed_Event` (rejeitado).
4. Reaproveitar `notification_url` (já implementado) e a validação `x-signature` (já implementada). Manter idempotência.
5. **Critério:** autorizar assinatura gera `subscription_authorized_payment` → 1º `Payment_Model` `COMPLETED` + ações do form disparadas.

### Etapa 2.3 — Gerenciamento (endpoints admin)
1. `cancel-subscription.php`: rota `stripe/`→`mercadopago/`; API `PUT /preapproval/{billing_id}` body `{status:'cancelled'}`; status DB `CANCELLED`; text domain MP.
2. `subscription-suspend.php`: rota→`mercadopago/`; check `'stripe'`→`'mercadopago'`; API `PUT /preapproval/{billing_id}` `{status:'paused'}` (e um caminho reactivate `{status:'authorized'}`); mensagens MP.
3. `refund-payment.php`: rota→`mercadopago/`; API `POST /v1/payments/{payment_id}/refunds`; status `REFUNDED`.
4. **Critério:** cancelar/pausar/reativar/estornar via endpoint refletem no MP **e** no DB, e disparam os eventos corretos.

### Etapa 2.4 — Eventos do form
1. Confirmar que os `Shared/FormEvents/*` (Cancel/Suspended/Reactivate/Expired/Renewal) **registram e disparam** (eles já são agnósticos e retornam `get_gateway()='mercadopago'`).
2. Garantir que o `EventsManager` do `Shared/` está inicializado quando subscriptions ligado.
3. **Critério:** cada transição de status dispara o evento certo (testável com uma ação "Send Email" no evento correspondente).

### Etapa 2.5 — Erradicação do Stripe (limpeza)
1. `jet-engine/manager.php`: `stripe_*`→`mercadopago_*` (keys, Vue `v-if`, labels) **OU** decidir remover a compat JetEngine se o projeto não a usa (ver decisão D2).
2. Trocar **todos** os text domains `jet-form-builder-stripe-gateway` → `jet-form-builder-mercadopago-gateway`.
3. Renomear códigos/mensagens de erro `stripe_*` e textos "Stripe" voltados ao usuário.
4. **Remover/neutralizar** `webhook-manager.php` (api.stripe.com inerte) — MP não precisa criar webhook por API.
5. Limpar comentários "guia de port" (cosmético) — ou mover para este HANDOFF/base de conhecimento.
6. **Critério:** `grep -ri stripe includes/ assets/` retorna **só** comentários históricos (zero em caminho ativo).

### Etapa 2.6 — Testes (sandbox `TEST-`, depois produção)
- Ligar `JFB_MP_SUBSCRIPTIONS_ENABLED=true`; ativar tópicos `subscription_preapproval`, `subscription_authorized_payment`, `payment` no painel.
- Testar: criar assinatura → autorizar → ACTIVE → 1ª cobrança (webhook) → renovação → pausar/reativar → cancelar.
- Verificar em **JetFormBuilder → Subscriptions/Payments**.

---

<a name="9"></a>
## 9. Decisões em aberto + critérios de aceite

### Decisões — RESOLVIDAS pelo dono do projeto (2026-06-16)

> Premissa-mãe (do dono): **replicar o que o addon Stripe faz HOJE**, mantendo o
> pagamento dentro do **CORE** do JetFormBuilder (a row aparece em
> *JetFormBuilder → Payments* e é acessível por dashboard, listing, queries,
> Profile Builder, users, relations). "Stripe é Stripe e Mercado Livre é Mercado
> Livre": onde algo é exclusivo do Stripe, **manter inerte / sem conflito**,
> documentando no arquivo o porquê de ter sido removido/comentado.

- **D1 — Modelo de assinatura → RESOLVIDA: usar `preapproval_plan` (com plano).**
  Como o Stripe **NÃO cria** Product/Price em runtime — ele usa **Prices
  recorrentes pré-existentes**, que o admin escolhe no editor (campo manual
  `plan_manual` ou via field `plan_field`) e atualiza no botão **"Refresh Plans
  From Stripe"** (`/v1/prices/search?type=recurring`) — o modelo do Mercado Pago
  que **mais se assemelha** é a **Preapproval COM Plano** (`preapproval_plan`):
  o plano é o análogo do "Price recorrente". Isto **sobrepõe** a recomendação
  antiga ("começar avulsa"): para *replicar o Stripe*, vamos de plano. Bônus: o
  editor MP **já está scaffoldado** para isto ("Refresh Plans From Mercadopago"
  + `fetch-mercadopago-plans.php` → `preapproval_plan/search`). Mapa:
  Stripe `Price (recurring)` → MP `preapproval_plan`;
  Stripe `Checkout Session(mode=subscription)` → MP `POST /preapproval`
  (`preapproval_plan_id`) → `init_point`; demais transições por webhook
  (`subscription_preapproval` / `subscription_authorized_payment`).
- **D2 — JetEngine compat → RESOLVIDA: NÃO portar; neutralizar e documentar.**
  O projeto usa **apenas JetFormBuilder**. `jet-engine/manager.php` (caminho
  JetEngine Forms, com `stripe_*`) deve virar **inerte** (sem conflito), mantendo
  o arquivo **com um comentário explicando** por que o código foi removido/
  comentado. Mesma regra para qualquer arquivo que seja exclusivo do Stripe.
- **D3 — Disparo de sucesso no webhook (pendência da Fase 1) → RESOLVIDA e
  IMPLEMENTADA nesta sessão.** É a **fundação** da Fase 2. Agora o pay-now
  dispara o `Gateway_Success_Event` (ações do form) também **via webhook** (aba
  fechada / Pix futuro), não só no retorno do navegador. Ver "Progresso" abaixo.

### Progresso da Fase 2 (sessão 2026-06-16, branch `claude/sleepy-cray-kgcow0`)

**✅ Etapa 2.0 (parcial) — Blindagem anti-fatal:**
- `actions/retrieve-price.php` e `actions/expire-checkout-session.php`:
  `action_endpoint()` agora declara `: string` (casa com o abstract
  `Base_Action`), eliminando o *Fatal error* de assinatura incompatível no
  autoload sob PHP 8 (risco #5). Auditadas TODAS as subclasses de `Base_Action`
  (as demais já estavam corretas).

**✅ D3 — Fulfillment via webhook (a FUNDAÇÃO):**
- Novo `RestEndpoints/WebhookEvents/PaymentFulfillment.php`: escuta o hook
  `jet-form-builder/mercadopago/payment-approved` e **re-executa o
  `Gateway_Success_Event`** fora do contexto de submissão — espelhando
  `SubscriptionUtils::execute_event_for_subscription`, mas indexado pelo
  **pagamento** via o core `Record_By_Payment` (tabela `payment_to_record`).
  Reidrata usuário + form_id + referrer + `Tools::apply_context()` + opções de
  gateway, roda as ações e `Tools::update_record()`.
- `PaymentNotification::confirm()` agora faz a transição **CREATED → COMPLETED
  ATÔMICA** (UPDATE condicional `WHERE id=X AND status='CREATED'`; 0 linhas →
  `Sql_Exception`). Só o **vencedor** da corrida emite o hook ⇒
  `Gateway_Success_Event` dispara **no-máximo-uma-vez** por pagamento, fechando o
  TOCTOU entre webhook e retorno do navegador. (O caminho do navegador **não foi
  tocado** — não regride o fluxo já validado.)
- Listener registrado em `Compatibility\Jet_Form_Builder\Manager::__construct()`
  (carrega em toda requisição, inclusive REST/webhook).

**⚠️ Edge conhecido (não-regressão; pré-existente):** se o **webhook** efetiva
ANTES do retorno do navegador, o `process_after()` do pay-now vê status ≠
`CREATED` e cai no `Gateway_Failed_Event` (mensagem de falha ao usuário), embora
as ações de sucesso já tenham rodado via webhook. Antes desta sessão esse caso
**nem rodava as ações**; agora roda (uma vez). Refino opcional: sobrescrever
`process_after()/process_status()` no `pay-now-logic` para tratar "já COMPLETED"
como sucesso-sem-redisparo. *Fica como follow-up.*

**✅ Erradicação do Stripe (D2) — neutralizações seguras:**
- `jet-engine/manager.php`: `condition()` agora retorna `false` ⇒ o caminho de
  compat com **JetEngine Forms** (Stripe-shaped, lê `stripe_*`) NUNCA instancia.
  Inerte, sem conflito, documentado (decisão D2).
- `webhook-manager.php`: esvaziado. MP não cria webhook por API
  (`/v1/webhook_endpoints` é Stripe); a notificação vai por `notification_url` +
  endpoint REST com `x-signature` (já implementados). `maybe_create_webhook()`
  vira no-op documentado. Removido `api.stripe.com` de caminho ativo aqui.
- **Sobra `api.stripe.com` em caminho ATIVO apenas em:** `subscription-suspend.php`
  (será reescrito p/ MP) e `refund-payment.php` (refund é por ÚLTIMO). Demais
  ocorrências são **comentários** "guia de port" (aceitável pelo critério).

**🧭 ARQUITETURA DO GERENCIAMENTO (esclarecida — corrige uma leitura anterior
equivocada de "endpoints órfãos"):**
O admin monta a URL de Cancel/Suspend via `PayPalCancelSubscription::dynamic_rest_url(
[ 'gateway' => $record['gateway_id'], 'id' => $id ] )` (ver
`TableViews/Actions/CancelSubscription` + trait `BaseSubscriptionArgs`). O core
`Gateway_Endpoint::get_rest_base()` compõe a rota como
**`(?P<gateway>[\w-]+)/<gateway_rest_base>`**. Logo, `dynamic_rest_url` é
**gateway-aware**: para uma assinatura do MP a URL resolve em
**`mercadopago/subscription/cancel/{id}`**. Ou seja, **os endpoints
gateway-específicos NÃO são órfãos** — basta registrá-los na rota
`mercadopago/...` que o botão do admin os alcança. É **a mesma mecânica do addon
Stripe** (`stripe/subscription/cancel/{id}`). A classe `PayPalCancelSubscription`
serve só como *construtor de URL* compartilhado; quem responde é o endpoint do
gateways da assinatura. (A `PayPalRestSubscriptionStatus`, hardcoded p/ PayPal,
**não** é usada pelo MP — usamos endpoints próprios.)

**📌 Fatos confirmados p/ a reescrita de assinaturas (modelo plano / D1):**
- Chaves do cenário no editor: **`plan_field`** (campo do form cujo valor = id do
  plano) e **`plan_manual`** (plano escolhido na UI). Os rótulos da view
  (`subscribe_plan_field` / `subscribe_plan`) são só display. ⇒ usar
  `get_from_field_or_manual('plan_field','plan_manual')`.
- ⚠️ O core **NÃO** tem `set_plan_field()/set_plan_from_field()` (só
  `set_price_field/set_price_from_filed`). O `subscription-logic` atual chama os
  inexistentes em `set_gateway_data()` ⇒ **fatal se o cenário rodar**. Na
  reescrita, **remover** essas chamadas e resolver o plano direto.
- `fetch-mercadopago-plans.php` **já é MP-native** (`GET /preapproval_plan/search`
  → `{id,key,label}`). Serve ao botão "Refresh Plans From Mercadopago".
- Resource `Jet_FB_Paypal\Resources\Subscription`: `set_suspended()`,
  `set_active()`, `set_refunded()`, `update_status_soft($status)`,
  `update_status($status)`, `get_id()`. Constantes em
  `Jet_FB_Paypal\Logic\SubscribeNow` (APPROVAL_PENDING/APPROVED/ACTIVE/SUSPENDED/
  CANCELLED/EXPIRED/REFUNDED). Tipos de pagamento: `Base_Gateway::PAYMENT_TYPE_INITIAL`
  ('initial') / `PAYMENT_TYPE_RENEWAL` ('renewal').
- **Vantagem do MP vs Stripe:** o `POST /preapproval` retorna o `id` da assinatura
  na hora (status `pending` + `init_point`). Dá p/ gravar `billing_id` já na
  criação (no Stripe o id só vinha pelo webhook `checkout.session.completed`).

**✅ Assinaturas MP-native IMPLEMENTADAS (modelo plano / D1):**
1. **Criação:** actions `Create_Preapproval` (POST /preapproval),
   `Retrieve_Preapproval`, `Retrieve_Authorized_Payment`, `Retrieve_Preapproval_Plan`,
   `Update_Preapproval` (PUT). `subscription-logic.php` reescrito: cria
   `SubscriptionModel` APPROVAL_PENDING + `RecurringCyclesModel` (lido do plano),
   POST /preapproval, grava `billing_id` (o MP devolve na hora), redireciona p/
   `init_point`. `process_after/process_status` vazios (webhook dirige, igual
   Stripe). Resolve `payer_email` (campo do form → usuário logado → filtro).
2. **Webhook:** `Dispatcher` roteia 2 tópicos. `PreapprovalNotification`
   (`subscription_preapproval`) → status (authorized/paused/cancelled, com guard
   de transição). `AuthorizedPaymentNotification` (`subscription_authorized_payment`)
   → `Payment_Model` `initial`/`renew` (no CORE) + `SubscriptionToPaymentModel` +
   dispara `Gateway_Success_Event` / `RenewalPaymentEvent` / `Gateway_Failed_Event`.
   Idempotente por `transaction_id`. Removidos os 5 handlers dormentes Stripe-shaped.
3. **Gerenciamento:** `cancel-subscription.php` / `subscription-suspend.php`
   reescritos p/ MP (rota `mercadopago/subscription/...`, `PUT /preapproval` status,
   token via `Controller::get_credentials_by_form`). Ligados ao admin pela mecânica
   gateway-aware (ver acima). Reativação via webhook (igual Stripe).

**⏭️ Falta:**
- **Refund** (por ÚLTIMO): `refund-payment.php` ainda é Stripe (`POST
  /v1/refunds`) — único `api.stripe.com` ativo restante. Reescrever p/ `POST
  /v1/payments/{id}/refunds` **após a discussão de segurança** com o dono
  (idempotência, fonte-de-verdade via webhook, guard atômico, total×parcial,
  capability/auditoria).
- **Editor:** o cenário "Subscription" só aparece com
  `JFB_MP_SUBSCRIPTIONS_ENABLED=true` (`wp-config`); o seletor de planos
  ("Refresh Plans From Mercadopago") já está scaffoldado.
- **Teste sandbox (`TEST-`):** criar assinatura → autorizar → ACTIVE → 1ª cobrança
  (webhook) → renovação → pausar/cancelar. Ativar os tópicos
  `subscription_preapproval` + `subscription_authorized_payment` no painel MP.

**✅ Gerenciamento de planos (sessão 2026-06-25):** descoberta-chave — os "Planos"
do PAINEL do MP **não** aparecem na API; só `preapproval_plan` (criados via API)
populam o dropdown do cenário Subscription. Pay-now em sandbox também foi
confirmado funcionando (o problema era setup de conta de teste do MP, não o
código — a base do dia 16 também recusava). Feito:
- Endpoints REST: `fetch-mercadopago-plans` (enriquecido c/ reason/amount/freq/
  status), `create-mercadopago-plan` (POST /preapproval_plan), `delete-mercadopago-plan`
  (PUT status=cancelled — o MP não APAGA, desativa). Registrados sempre.
- **Aba "Mercado Pago Plans"** no SPA de settings do JFB (não mais página de menu
  standalone, que foi removida): `Admin\Plans_Page` enfileira `mp-plans-settings.js`
  no hook `jet-fb/admin-pages/before-assets/jfb-settings`; o JS registra a aba via
  o filtro `jet.fb.register.settings-page.tabs` (componente **Vue 2 render-function**,
  sem build — estilo repeater como os glossários). Frequência e Tipo separados.
- **SEGURANÇA:** o Access Token NUNCA trafega pelo cliente. Os endpoints usam
  SEMPRE a chave do gateway server-side (`Mp_Token_Trait` → `Controller::get_credentials`).
  O editor (dropdown) ainda envia o token do form; a aba não envia nada (cai no global).

**⏭️ Tarefas futuras (pedidas pelo dono):**
- **Botão "excluir log":** o MP só CANCELA o plano (fica `status=cancelled` na
  lista). Futuro: opção de sumir/arquivar os cancelados da visão.
- **Aba de settings "Mercado Pago" extensível:** pensada p/ crescer (ex.: chave
  Pix e outras configs MP-native). Estruturada p/ isso; não implementar agora.
- **Refund:** ainda pendente, após a discussão de segurança.
- **Validar a aba Vue** no SPA real do JFB (não testável aqui): se não renderizar,
  conferir o shape do tab (`{component, title}`) / versão do Vue.

### Critérios de aceite (Definition of Done — Fase 2)
- [ ] `JFB_MP_SUBSCRIPTIONS_ENABLED=true` **ativa sem fatal** e sem quebrar a REST.
- [ ] Criar → autorizar → 1ª cobrança → renovação registradas (DB + eventos).
- [ ] Cancelar / pausar / reativar / estornar funcionam (API MP + DB + eventos).
- [ ] **Zero `api.stripe.com`** em caminho ativo; todas as rotas sob `jfb-mercadopago/`.
- [ ] Text domains e mensagens 100% Mercado Pago.
- [ ] `grep -ri stripe includes/ assets/` → só comentários históricos.
- [ ] Pay-now **continua funcionando** (não regredir o que já está validado).

---

<a name="10"></a>
## 10. Referências

**Documentação Mercado Pago (assinaturas):**
- [Criar assinatura — POST /preapproval](https://www.mercadopago.com.br/developers/pt/reference/subscriptions/_preapproval/post)
- [Criar plano — POST /preapproval_plan](https://www.mercadopago.com.mx/developers/pt/reference/subscriptions/_preapproval_plan/post)
- [Atualizar assinatura — PUT /preapproval/{id}](https://www.mercadopago.com.pe/developers/pt/reference/subscriptions/_preapproval_id/put)
- [Buscar assinaturas — GET /preapproval/search](https://www.mercadopago.com.br/developers/pt/reference/subscriptions/_preapproval_search/get)
- [Assinaturas com pagamento autorizado](https://www.mercadopago.com.co/developers/en/docs/subscriptions/integration-configuration/subscription-no-associated-plan/authorized-payments)
- [Webhooks / Notificações](https://www.mercadopago.com.pe/developers/en/docs/your-integrations/notifications/webhooks)

**Arquivos-chave do projeto (fonte da verdade):**
- Pay-now: `includes/compatibility/jet-form-builder/logic/pay-now-logic.php`
- API client: `includes/compatibility/jet-form-builder/actions/base-action.php`
- Criar preference: `.../actions/create-checkout-session.php`
- Webhook: `includes/RestEndpoints/{Base/MercadopagoWebHookBase, MercadopagoWebHookGlobal, SignatureValidator, WebhookConfig}.php` + `WebhookEvents/{Dispatcher, PaymentNotification}.php`
- Gateway/credenciais: `includes/compatibility/base-mercadopago.php` (`options_list`, `get_credentials`)
- Cenários (subscription): `.../logic/subscription-logic.php`, `.../views/subscription-view.php`
- Endpoints admin: `.../rest-endpoints/{cancel-subscription, subscription-suspend, refund-payment, fetch-mercadopago-plans, rest-controller}.php`
- Lib agnóstica: `includes/Shared/**` (namespace `Jet_FB_Paypal\*`)
- Autoloader: `includes/autoloader.php`
- Flag: `jet-form-builder-mercadopago-gateway.php` (`JFB_MP_SUBSCRIPTIONS_ENABLED`)
- Setup do webhook: `WEBHOOK-SETUP.md`

**Referência de comparação (NÃO modificar):**
- `jetformbuilder-gateways/jet-form-builder-stripe-gateway/` — o addon Stripe original (base do clone)
- `jet-plugins/jet-form-builder-paypal-subscriptions/` — origem da lib `Shared/` (PayPal)
