# 📚 BASE DE CONHECIMENTO — raciocínio e comentários do projeto

> **Propósito:** preservar o **PORQUÊ** que hoje vive nos comentários do código.
> O código diz *o quê*; este arquivo diz *por quê* e *quais armadilhas*. Depois que
> ele estiver completo, os comentários verbosos podem sair do código (que fica
> enxuto) sem perder conhecimento — é o que permite manter o projeto no futuro.
>
> **Como ler:** hierárquico por **camada → arquivo**. Cada item: *propósito* +
> *decisões/armadilhas* + *referência* (`caminho`). Complementa o `MARCO-ASSINATURA.md`
> (fluxo/decisões macro) e o `MEMORIA-CONTINUACAO.md` (estado/regra de ouro).
>
> **Regra de ouro do projeto:** nunca supor — confira na RAIZ (JFB core, addon Stripe,
> lib PayPal `Shared`) como funciona de verdade.
>
> **O que NÃO é nosso (não documentado aqui, não mexer nos comentários):**
> `includes/Shared/**` (lib PayPal `Jet_FB_Paypal\*`, agnóstica) — exceto os 2
> arquivos que NÓS modificamos (GrossColumn/BillingCycleColumn). E `assets/js/
> builder.admin.js` + `assets/js/mercadopago.js` são **bundles compilados** (webpack)
> do editor — os "comentários" lá são do build, não nossos.

---

## 1. Bootstrap & infraestrutura

### `jet-form-builder-mercadopago-gateway.php` (arquivo principal)
- **Versão** em `JET_FB_MERCADOPAGO_GATEWAY_VERSION` (hoje 2.0.14). Bump a cada build.
- **Guard do autoloader** (`is_readable(vendor/autoload.php)` → admin_notice + `return`):
  durante uma ATUALIZAÇÃO do plugin a pasta é re-extraída e o `vendor/autoload.php` fica
  ausente por um instante; o `require` duro derrubava o site inteiro (fatal no
  `wp-admin/update.php`). Agora degrada para aviso, sem fatal.
- **Flag `JFB_MP_SUBSCRIPTIONS_ENABLED`** (default `true`): liga o cenário Subscription.
  Para desligar, definir `false` no wp-config ANTES do plugin carregar.

### `includes/plugin.php`
- Bootstrap em `after_setup_theme` (prio -100). `init_components()`: `Editor` + `Plans_Page`
  só em `is_admin()`; `AdminPages`/`AdminSinglePages`/`RestApiController`/`EventsManager`
  sempre. JetEngine só se presente.

### `includes/autoloader.php`
- PSR-4 caseiro: remove o prefixo `Jet_FB_Mercadopago_Gateway\` e mapeia o resto para
  `includes/<Rel>.php` (e um fallback legacy kebab-case). Por isso `…\Money` → `includes/Money.php`.

### `includes/Shared/Loader.php` (lib compartilhada — CRÍTICO)
- A lib `Jet_FB_Paypal` é **versionada e compartilhada em runtime**: cada plugin que a
  embute registra um candidate; o Loader ordena por `version_compare` **descendente** e
  carrega **UMA** cópia (a mais alta) para TODOS os gateways. **Implicação:** mexer na
  NOSSA cópia só tem efeito se ela for a carregada. Por isso toda customização na lib
  precisa ser **condicional ao gateway** (não quebrar PayPal/Stripe).

---

## 2. Cenários (Scenario Logic) — `includes/compatibility/jet-form-builder/logic/`

### `pay-now-logic.php` (Pay Now — pagamento único)
- Replica o pay-now do Stripe via **Checkout Pro Preference**. `set_gateway_data()`
  resolve o preço (campo `price_field` → valor). `amount_value` gravado **em BRL real
  (sem dividir por 100)** — MP não usa centavos-inteiros como o Stripe.
- `get_referrer_url()`: **não** injeta `{CHECKOUT_SESSION_ID}` (placeholder do Stripe);
  o MP devolve `external_reference` ecoado, que é a ponte de reconciliação.
- `external_reference` único por submissão (anti-replay) gravado em `initial_transaction_id`.

### `subscription-logic.php` (Subscription — assinatura)  ⭐ núcleo
- Replica o "Checkout Session mode=subscription" do Stripe com **Preapproval SEM plano,
  `auto_recurring` INLINE**. **ARMADILHA #1 (card_token_id):** criar `/preapproval` COM
  `preapproval_plan_id` e SEM `card_token_id` é o fluxo DIRETO → MP exige cartão
  tokenizado no SEU site. O fluxo de redirect (cartão na página do MP) é a preapproval
  SEM plano. Então o plano escolhido é só **TEMPLATE**: lemos os termos
  (`Retrieve_Preapproval_Plan`, memoizado 1×/submit) e mandamos inline.
- `after_actions()`: cria `SubscriptionModel` (APPROVAL_PENDING) + `RecurringCyclesModel`
  → `create_resource()` (POST /preapproval) → `save_resource()` (billing_id =
  preapproval.id) → `add_response(['redirect'=>init_point])` → `attach_record_id`.
- `process_after()/process_status()` **vazios de propósito** (igual Stripe): estado e
  eventos vêm do WEBHOOK, não do retorno do navegador.
- **ARMADILHA #2 (tenure_type):** gravar `'REGULAR'` MAIÚSCULO — `SubscriptionsView`
  filtra `tenure_type='REGULAR'`; minúsculo deixa a coluna "Billing Cycle" vazia.
- **ARMADILHA #3 (payer_email):** a Preapproval EXIGE `payer_email`. `resolve_payer_email()`
  prioriza campos nomeados (`payer_email`/`email`/…) → heurístico → usuário WP. **Cuidado
  sandbox:** com admin logado, o fallback traz e-mail REAL → MP recusa "Both payer and
  collector must be real or test users".
- **ARMADILHA #4 (delete/capability):** NÃO apagamos a assinatura órfã no submit —
  `Base_Db_Model::before_delete` exige `manage_options` e o submit roda como visitante.
- Diagnóstico inline no erro do MP: `[payer_email | token]` para depurar test/live.

---

## 3. Controller & base do gateway — `includes/compatibility/`

### `controller.php` + `base-mercadopago.php` (trait)
- `Controller extends Base_Scenario_Gateway` `use Base_Mercadopago`. `get_id()='mercadopago'`,
  `get_name()='Mercado Pago Checkout'`. `get_current_token()` = `secret` (Access Token).
- `options_list()` (base): campos do modal por-form (`public`/`secret`/`currency`/
  `use_global`/`gateway_type`). **A opção "Subscription" no dropdown** só entra se
  `JFB_MP_SUBSCRIPTIONS_ENABLED`.
- `get_credentials()` / `get_credentials_by_form($form_id)`: o Access Token é SEMPRE
  server-side; por-form cai no global se vazio. Usado por cancel/suspend/refund e
  pela aba de planos (`Mp_Token_Trait`).

---

## 4. Actions (chamadas à API MP) — `includes/compatibility/jet-form-builder/actions/`

- `base-action.php`: base das chamadas (auth bearer, path, body, idempotency-key,
  `send_request`). `base_url` = `https://api.mercadopago.com/`.
- `create-preference.php`: pay-now (POST /checkout/preferences → init_point). `binary_mode`
  em paridade com a base.
- `create-preapproval.php`: assinatura. **Envia `auto_recurring` INLINE** (não
  `preapproval_plan_id`) → `init_point` sem card token. `status:'pending'`. Idempotency
  por `external_reference`. (Ver Armadilha #1.)
- `update-preapproval.php`: `PUT /preapproval/{id}` — `status=cancelled|paused|authorized`
  (cancelar/suspender/reativar). Espelha DELETE/pause do Stripe.
- `retrieve-payment.php` / `retrieve-preapproval.php` / `retrieve-authorized-payment.php`
  / `retrieve-preapproval-plan.php`: GETs (fonte de verdade nos webhooks).
- `refund-payment-action.php`: `POST /v1/payments/{id}/refunds` (idempotente).
- `search-payments.php`: resolve o `payment_id` real do pay-now por `external_reference`.

---

## 5. REST endpoints (admin/editor) — `includes/compatibility/jet-form-builder/rest-endpoints/`

- `rest-controller.php`: **blindagem de boot** — endpoints de assinatura só instanciam
  com a flag ligada (evita fatal por classe ausente em `rest_api_init`). Sempre ativos:
  Fetch_Pay_Now_Editor, Refund_Payment, e o CRUD de planos.
- `mp-token-trait.php`: `gateway_token()` = `Controller::get_credentials()['secret']`
  (SEGURANÇA: o token nunca vai ao cliente).
- `fetch-mercadopago-plans.php`: lista `preapproval_plan/search`. Reescrito para devolver
  **WP_Error com mensagem** (fim do "Request failed" opaco). **Default = só ATIVOS**
  (editor); a aba manda `include_cancelled=true`. Cacheia a lista COMPLETA; filtra na
  resposta. Devolve `date_created`/`last_modified`.
- `create/delete-mercadopago-plan.php`: criar (POST /preapproval_plan, valida reason/
  amount; currency cai em BRL) e "excluir" (PUT status=cancelled — MP não apaga plano).
- `cancel-subscription.php` / `subscription-suspend.php`: PUT /preapproval cancelled|paused;
  acham a sub por id, validam `gateway_id==='mercadopago'`, pegam token por form, atualizam
  status local + disparam evento. **Mecânica gateway-aware:** o botão monta a URL via
  `PayPal*::dynamic_rest_url` → `mercadopago/subscription/cancel|suspend/{id}` → nosso endpoint.
- `refund-payment.php`: `POST /v1/payments/{id}/refunds`. Resolve o payment_id: **assinatura**
  → `transaction_id` já é o payment_id; **pay-now** → busca por `external_reference`. Guard
  atômico + idempotência.

---

## 6. Webhooks — `includes/RestEndpoints/`

### Recebimento
- `Base/MercadopagoWebHookBase.php`: rota `POST jfb-mercadopago/v1/webhook`. `resolve_type`
  (body v2 `type` OU query IPN `topic`; normaliza `payment.*`→`payment`) e `resolve_data_id`
  (PHP troca `.`→`_` em query, então `data.id` chega como `data_id`). Loga "Webhook recebido"
  (prova de chegada, com WP_DEBUG).
- `SignatureValidator.php`: HMAC x-signature. **Sem `JFB_MP_WEBHOOK_SECRET` → PROCESSA**
  (com aviso): cada handler re-verifica via GET autenticado, então um webhook forjado só
  dispara consulta de recurso da própria conta. Definir o segredo ENFORÇA a assinatura.
  (Antes era fail-closed → 401 em tudo → a assinatura, que depende só do webhook, nunca
  ativava; o pay-now mascarava por confirmar também no retorno.)
- `WebhookConfig.php`: credenciais por **constante/filtro** (o painel do gateway é Vue
  compilado, evitamos recompilar). `JFB_MP_WEBHOOK_SECRET` (assinatura ≠ Access Token),
  `JFB_MP_ACCESS_TOKEN` (fallback no contexto do webhook, sem form ativo). `log()` só com
  WP_DEBUG; nunca registra dado sensível. `notification_url` tem precedência sobre o painel.
- `Dispatcher.php`: roteia por tópico. **Aceita DUAS convenções** (v2 + IPN): `payment`;
  `subscription_preapproval`|`preapproval`; `subscription_authorized_payment`|`authorized_payment`.
  Sem os apelidos IPN, a assinatura criada via notification_url caía no default e nunca ativava.

### Fulfillment
- `PaymentNotification.php` (tópico `payment`): GET /v1/payments/{id} (fonte de verdade).
  Trata refund/chargeback (reconcilia REFUNDED). **Detecta cobrança de ASSINATURA** por
  `external_reference = jfbmp-sub-<id>` e roteia ao recorder (o MP entrega a cobrança da
  assinatura como `payment`, não `subscription_authorized_payment`). Pay-now: reconcilia por
  `external_reference` (initial_transaction_id), transição **atômica CREATED→COMPLETED**
  (UPDATE condicional lança Sql_Exception em 0 linhas → evento dispara **no máx. 1×**,
  fecha a corrida webhook×retorno). Anti-fraude: confere `transaction_amount` vs DB.
- `PaymentFulfillment.php`: escuta `jet-form-builder/mercadopago/payment-approved` e re-roda
  o `Gateway_Success_Event` do pay-now (aba fechada/Pix) via `Record_By_Payment`.
- `PreapprovalNotification.php` (tópico `subscription_preapproval`): status da assinatura.
  `authorized`→ACTIVE (+reativação se vinha de SUSPENDED); `paused`→SUSPENDED; `cancelled`→
  CANCELLED. Cada um com **guard de transição** (não duplica em reentregas). Acha por `billing_id`.
- `AuthorizedPaymentNotification.php` (tópico `subscription_authorized_payment`): cobrança via
  GET /authorized_payments/{id}. Cria Payment_Model initial/renew. **Convive** com o caminho
  `payment` por idempotência de `transaction_id` (a § cleanup vai unificar no recorder).
- `SubscriptionPaymentRecorder.php` ⭐ **ponto único** da cobrança de assinatura: ATIVA a sub
  se pendente (cobrança aprovada = ACTIVE) → grava Payment_Model (initial/renew) → vincula
  pagador à **assinatura** (`attach_payer`: Payer+Payer_Shipping+SubscriptionToPayerShipping,
  só na initial, igual Stripe `checkout.session.completed`) → vincula pagador a **CADA
  cobrança** (`link_payment_to_payer`: Payment_To_Payer_Shipping, igual Stripe `InvoicePaid` —
  é o que tira o "Payer: Not attached" da tabela Payments e traz o e-mail no popup de refund)
  → dispara evento. Idempotente por `transaction_id`.

---

## 7. Admin de planos + formatação de moeda

### `includes/Admin/Plans_Page.php` + `assets/js/mp-plans-settings.js` + `.css`
- A gestão de planos é uma **ABA do SPA de settings** do JFB (não um menu próprio),
  registrada via `jet.fb.register.settings-page.tabs`. **SHAPE confirmado no SPA:** tab =
  `{ title:STRING, component:OBJETO Vue com name+render, displayButton:false }`. O SPA
  computa as abas em LOAD (`applyFilters`) e a ordem é garantida pelo core
  (`before-assets/jfb-settings` dispara ANTES do enqueue do SPA).
- **Versão por `filemtime`** dos assets da aba → cache-bust automático (sem isso, o `?ver`
  não mudava e o navegador servia JS velho; só `Ctrl+Shift+R` resolvia).
- UI 100% nativa (`cx-vui-*` com `wrapper-css:[equalwidth]`+`size:fullwidth`; botões com
  `slot=label`; `link-error`=#c92c2c). Moeda ANTES do valor, com preview por moeda. Datas
  criação/exclusão. Popup "Como funciona?" com docs MP.
- **SEGURANÇA:** o Access Token NÃO trafega; só `hasToken` (bool). CRUD usa a chave do
  gateway server-side.

### `includes/Money.php` + `Shared/TableViews/Columns/{Gross,BillingCycle}Column.php`
- **Diagnóstico:** valor é `DECIMAL(10,2)` no banco (número puro, sem corrupção). A
  EXIBIÇÃO herda formato americano (`number_format(x,2)`="1,000.00"); para BRL é "1.000,00".
- **Regra de ouro:** `Money::format()` é SÓ exibição. NUNCA em campo de input/envio
  (refund amount, criação de plano, amount ao MP) — `(float)"1.000,00"`===1.0 quebra.
- **Isolamento:** só formata se `gateway_id==='mercadopago'` (`Money::is_mercadopago`);
  outros gateways caem no `parent`/original. (⚠️ STATUS v2.0.14: não aplicou no teste do
  dono — ver pendência no `MARCO §6.3`.)

---

## 8. Proxy & integração com a lib Shared — `includes/Proxy/`

- `AdminPages`/`AdminSinglePages`: registram as páginas de Payments/Subscriptions da lib.
- `RestApiController.php`: registra os endpoints Shared NECESSÁRIOS. **NÃO registrar**
  `PayPalCancelSubscription`/`PayPalSuspendSubscription`/`PayPalRefundPayment`: são
  gateway-aware com `gateway_id()='paypal'` e o core valida `gateway===gateway_id()`; se
  registrados, podiam casar a URL `mercadopago/…` primeiro e devolver 400. As CLASSES
  ficam (o admin usa só os estáticos `dynamic_rest_url`/`get_messages`); só as NOSSAS rotas
  `mercadopago/…` atendem.
- `ScenariosLogic`/`ScenariosViews`: pontos onde a lib registraria o cenário PayPal
  (`SubscribeNow`) — inertes no nosso contexto.

---

## 9. Compatibilidade JetEngine — `includes/compatibility/jet-engine/manager.php`
- **D2:** só JetFormBuilder é usado. O compat de JetEngine Forms fica **inerte**
  (`condition()=false`), sem porte do `stripe_*` — documentado para não dar conflito.

---

## 10. Armadilhas críticas (índice rápido)
1. **card_token_id is required** → preapproval SEM plano, auto_recurring inline (§2).
2. **Both payer and collector must be real or test** → payer_email = test buyer; token de teste (§2).
3. **Cobrança de assinatura chega como `payment`**, não `subscription_authorized_payment` (§6).
4. **Webhook fail-closed** sem segredo → 401 em tudo → assinatura não ativava (§6).
5. **Payer "Not attached"** → faltava `Payment_To_Payer_Shipping` por cobrança (§6).
6. **Conflito de rota** cancel/suspend/refund (PayPal gateway-aware × nossas) (§8).
7. **tenure_type** 'regular'≠'REGULAR' → coluna billing cycle vazia (§2).
8. **Cache de asset** sem filemtime → JS velho (§7).
9. **Fatal no update** por `require vendor/autoload.php` → guard `is_readable` (§1).
10. **Lib Shared compartilhada** (Loader) → customização sempre condicional ao gateway (§1/§7).
11. **Formato americano** na exibição de valores (DECIMAL não corrompe; é só exibição) (§7).

---

## 11. Pendências conhecidas (para manutenção)
- Formatação de moeda não aplicou no admin (investigar Loader/gateway_id/cache) — `MARCO §6.3`.
- Single Payment "Amount" cru + export CSV/PDF ainda no formato padrão.
- Unificar `AuthorizedPaymentNotification` no `SubscriptionPaymentRecorder` (dedup).
- Decisão final de segurança do webhook (fail-open atual × enforçar segredo).
- Pix (aditivo, fora do escopo atual — provável módulo à parte).
