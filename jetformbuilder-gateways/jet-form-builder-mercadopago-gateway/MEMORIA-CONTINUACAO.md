# 🧠 MEMÓRIA / PONTO DE CONTINUAÇÃO — JetFormBuilder Mercado Pago Gateway

> **PARA O CLAUDE DO FUTURO — LEIA ISTO PRIMEIRO.** Você NÃO tem acesso à conversa
> que gerou este projeto. Este arquivo é o ponto de entrada: ele consolida o
> HANDOFF, os commits, os comentários do código e uma análise atual. Os detalhes
> finos estão nos outros `.md` (citados abaixo) e nos comentários `file:line`.

---

## 0. Documentos deste plugin (ordem de leitura)
0. **MARCO-ASSINATURA.md** ⭐ — **LEIA PRIMEIRO.** Marco do projeto (assinatura
   ponta a ponta funcionando, v2.0.9): ciclo completo, nuances/diferenças vs Stripe,
   armadilhas resolvidas, setup, itens de core pendentes e PLANO DE AÇÃO de limpeza.
1. **MEMORIA-CONTINUACAO.md** (este) — estado/regras/mapa de repositórios.
2. **HANDOFF-FASE-2.md** — plano/decisões (D1/D2/D3), inventário, mapa de risco de fatal.
3. **REFUND-ARCHITECTURE.md** — desenho do estorno (gap do payment_id, idempotência, webhook).
4. **TESTING-CHECKLIST.md** — checklist de testes (estabilidade + integração CORE).
5. **WEBHOOK-SETUP.md** — setup do webhook (x-signature, allowlist de plugins de segurança).

> **Estado em 26/06 (v2.0.9):** pay-now ✅ e **subscription ✅ ponta a ponta**
> (ACTIVE + cobrança "initial" em Payments + eventos). Pendente do core: renovação
> (observar), Suspend/Cancel, Refund, "Subscriber attached". Depois: §6 do MARCO.

---

## 1. ⚠️ REGRA DE OURO DO PROJETO (o dono reforçou MUITAS vezes)
**NUNCA SUPONHA. SEMPRE CONFIRA NA RAIZ COMO AS COISAS FUNCIONAM DE VERDADE.**
- Antes de escrever qualquer integração, **leia o código real** do JFB CORE, do
  addon Stripe (base) e da lib Shared (PayPal). `grep` + `Read` no código-fonte.
- Quando “replicar o Stripe”, é **adaptar** o que já existe (igual fizemos
  Stripe→MP), não inventar. Só diverge quando for **genuinamente necessário** —
  e aí sim vira algo MP-native (esse limite já foi cruzado: planos, token, etc.).
- Erros que vieram de SUPOSIÇÃO nesta jornada (não repita):
  - Usar `php -l` como “prova” (ele **NÃO** pega `use` faltando → fatal em runtime).
  - Assumir o shape de um tab do SPA (era `title:string`+`component:objeto`, não funções).
  - Assumir que “a base funcionava” sem re-testar (o MP mudou o sandbox; a base também recusava).

---

## 2. 🗺️ MAPA DE REPOSITÓRIOS (onde checar / referência)
Raiz do repo: `/home/user/crocoblock-community-snippets/`

| Caminho | O que é | Use para |
|---|---|---|
| `jetformbuilder-gateways/jet-form-builder-mercadopago-gateway/` | **O NOSSO PLUGIN** | onde trabalhamos |
| `jetformbuilder-gateways/jet-form-builder-stripe-gateway/` | **Base de referência** (clonamos dele) | como o cenário/fluxo é feito |
| `CORE-PLUGINS/jetformbuilder/` | **JFB CORE — a “raiz da verdade”** | como tudo funciona DE FATO (REST, gateways, settings, Vue, events, db-models) |
| `jet-plugins/jet-form-builder-paypal-subscriptions/` | Origem da lib `Shared/` (namespace `Jet_FB_Paypal\*`) | DbModels/QueryViews/Resources/Utils de assinatura |
| `CORE-PLUGINS/jet-engine/` | JetEngine | integração/relations/query builder |
| `jet-plugins/*` , `others-plugins/*` | Outros addons (referência de padrões) | ex.: como um addon registra aba/repeater |

**Sub-repos de referência mais usados no JFB CORE:**
- `CORE-PLUGINS/jetformbuilder/modules/gateways/` — base de gateways (Payment_Model, scenario-logic-base, query-views, paypal/).
- `CORE-PLUGINS/jetformbuilder/modules/actions-v2/mailchimp/` + `modules/active-campaign/` — **referência de ABA de settings**.
- `CORE-PLUGINS/jetformbuilder/includes/admin/tabs-handlers/` — `Base_Handler` / `Tab_Handler_Manager` (mecânica das abas).
- `CORE-PLUGINS/jetformbuilder/modules/form-record/` — `Tools`, `Record_By_Payment` (re-rodar ações fora da submissão).

> **Acesso a OUTROS repositórios (fora deste workspace):** as ferramentas de
> GitHub MCP são limitadas ao repo da sessão. Para listar/adicionar outros repos,
> use `mcp__claude-code-remote__list_repos` / `add_repo` (carregue via ToolSearch).
> Para a API do Mercado Pago: o **proxy do ambiente BLOQUEIA `api.mercadopago.com`**
> (policy denial) — você NÃO consegue chamar a API MP daqui; peça ao dono rodar
> os `curl` no lado dele.

---

## 3. 🔎 PASSO A PASSO: pesquisar/alinhar sem supor
1. **Acha o mecanismo no CORE:** `Grep` por nomes (ex.: `register-tabs-handlers`,
   `settings-page.tabs`, `Payment_Model`, `execute_event_for_subscription`).
2. **Lê o arquivo real** (`Read`) — base class, contrato, exemplos (MailChimp/AC/PayPal).
3. **Compara com o addon Stripe** (mesmo arquivo) para ver o padrão a replicar.
4. **Confirma o shape/assinatura** lendo o consumidor (ex.: o SPA compilado:
   `assets/build/admin/pages/jfb-settings.js` — dá pra `grep -oE` trechos minificados).
5. **Auditor de imports (técnica — `php -l` não basta):** para cada arquivo seu,
   cruze `extends|new|::|catch|implements` (FORA de comentários) contra os `use`
   + classes do mesmo namespace; o que sobrar é `use` faltando → fatal em runtime.
   (Foi assim que achei o fatal do `refund-payment.php`.)

---

## 4. ✅ ESTADO ATUAL (consolidado — o que funciona / o que falta)
Branch de trabalho: `claude/sleepy-cray-kgcow0` · PR draft **#8** · base: merge do PR #7 (`8f75826`).

**FUNCIONA / FEITO:**
- **Boot sem fatal** (confirmado no site). `php -l` limpo em todo o plugin; 0 imports faltando.
- **Pay-now (cartão) ponta a ponta** — **CONFIRMADO no sandbox** com conta de teste
  correta. ⚠️ A “recusa” que assustou era **setup de conta de teste do MP** (precisa
  usuário COMPRADOR ≠ vendedor; cartão+`APRO`+CPF `12345678909`), **não** o código:
  a base do dia 16 (zero das nossas mudanças) **também recusava** → provado não-código.
- **D3 — fulfillment via webhook:** `PaymentFulfillment` re-roda o `Gateway_Success_Event`
  quando o pagamento confirma via webhook (aba fechada/Pix futuro); transição
  `CREATED→COMPLETED` atômica (dispara 1×).
- **Erradicação do Stripe:** 0 `api.stripe.com` em caminho ativo; classes renomeadas
  (`Create_Preference`, `Retrieve_Payment`); `jet-engine/manager.php` gutado (inerte);
  `webhook-manager.php` neutralizado. Sobram só comentários históricos.
- **Assinaturas MP-native (modelo PLANO / preapproval_plan):** `Create_Preapproval`,
  `subscription-logic` reescrito, 2 handlers de webhook (`PreapprovalNotification`,
  `AuthorizedPaymentNotification`), cancel/suspend. Pagamentos entram no **CORE**
  (`Payment_Model` `initial`/`renew`). **FALTA testar ponta a ponta no sandbox.**
- **Refund MP-native** (`POST /v1/payments/{id}/refunds`) + reconciliação por webhook.
  **Falta:** a discussão de segurança que o dono pediu + teste.
- **Gestão de planos (preapproval_plan) como ABA de settings** (Vue) + endpoints
  (create/list/delete) + **token SERVER-SIDE** (`Mp_Token_Trait`, nunca no cliente).

**FALTA / PRÓXIMO:**
1. **A aba “Mercado Pago Plans” renderizar** (bug recém-corrigido — ver §5).
2. Testar **assinatura ponta a ponta** no sandbox (criar→autorizar→ACTIVE→cobrança→renovação→cancelar).
3. Fechar o **refund** (discussão de segurança + teste).
4. **Pix** (purely-BR, só depois de tudo estável).
5. Tarefas futuras: botão “excluir/arquivar log” (MP só CANCELA plano, não apaga);
   aba “Mercado Pago” extensível p/ outras configs (chave Pix etc.).

---

## 5. 🐞 TAREFA EM ABERTO: aba de planos (settings tab) — bug, causa, fix, plano
**Sintoma:** a aba aparecia na lateral de Settings, mas o **título era o código-fonte
da função** (`function () { return t.title || ... }`) e o conteúdo não renderizava.

**Causa raiz (CONFIRMADA na RAIZ — li o SPA minificado inteiro, não supus):** em
`CORE-PLUGINS/jetformbuilder/assets/build/admin/pages/jfb-settings.js`:
```js
const Kt = applyFilters('jet.fb.register.settings-page.tabs', [r,o,s,e,l,t,n]); // <- no LOAD do SPA
// no render de cada tab `s`:
//   attrs:{ name:s.component.name, label:s.title, disabled:s.disabled, icon:s.icon }
//   s.component.render ? <renderiza s.component> : <nada>
//   (displayButton!==false) ? <botão Salvar -> getRequestOnSave()> : <nada>
```
Disso saem 3 fatos confirmados:
- **`title` precisa ser STRING** (vira o `label`) — eu passei uma função → o label virou
  o código-fonte da função (era exatamente o sintoma do print).
- **`component` precisa ser OBJETO Vue com `name` E `render`** — o SPA só renderiza o
  conteúdo se `s.component.render` existir. Eu passei uma função-factory → sem `.render` →
  corpo vazio. (O addon AC do core usa `()=>c`/`()=>s`, mas são **getters do webpack**
  `e.d(...)`, então `s.title` lá retorna a string. Função JS comum NÃO é resolvida.)
- O SPA injeta props `incoming` + `inner-slugs` e mostra um **botão "Salvar"** que chama
  `getRequestOnSave()` na aba. Nossa aba salva via REST própria (não no store), então
  precisamos de **`displayButton:false`** e declarar as props (senão vazam no `<div>`).

**O que isto PROVA (importante p/ não recomeçar do zero):** a aba **já estava sendo
registrada e aparecendo** (o print mostrava o tab na lateral, só com label errado). Logo,
o *wiring* está correto e verificado:
- `Plans_Page::register()` roda (`plugin.php:61`, em `after_setup_theme -100`, `is_admin()`);
- engata em `jet-fb/admin-pages/before-assets/jfb-settings` (mesmo hook do AC do core);
- **ordem garantida pelo core**: `pages-manager.php:200` dispara esse hook **ANTES** de
  `current_page->assets()` (linha 202) enfileirar o SPA → nosso `addFilter` registra antes
  de `Kt` ser calculado. Nada a "consertar" na ordem de scripts.

**Fix aplicado + reforçado** (`assets/js/mp-plans-settings.js`, no `addFilter`):
```js
tabs.push({ title: t.title || 'Mercado Pago Plans', component: component, displayButton: false });
// + component agora declara props { incoming, innerSlugs } (não vazam no DOM)
```

**Plano de ação (se AINDA não renderizar após este build):**
1. Reinstalar o zip, abrir *JetFormBuilder → Settings*, confirmar a aba "Mercado Pago Plans"
   e clicar nela. O label deve estar correto agora (não mais código de função).
2. Console: erros de Vue? `window.JFB_MP_PLANS` existe (localize)? `component.name` monta?
3. Se a aba some entre versões do JFB, re-conferir o shape na RAIZ (pode mudar): `grep`
   `settings-page.tabs` e `CxVuiTabsPanel` em `jfb-settings.js`; ver `label`/`name`/
   `component`/`displayButton`. Vue do SPA é **Vue 2** (`new Vue`, options + `render(h)`).
4. Se a aba renderiza mas o CRUD falha: Network → `jet-form-builder/v1/{fetch,create,delete}-
   mercadopago-plan` (exigem `X-WP-Nonce: wp_rest` + `manage_options`). Token server-side
   (`Mp_Token_Trait` → `Controller::get_credentials()['secret']`).
5. **Fallback de menor risco** (só se o SPA Vue brigar): havia menu standalone funcionando
   (commit `835db92`, removido em `456c0f7`) — dá pra ressuscitar como `add_menu_page`.

**Pontos de gestão de planos (MP-specific, NÃO supor):**
- Planos do **PAINEL** do MP (`/subscription-plans`) **≠** planos da **API**
  (`/preapproval_plan`). Só os de API aparecem no `GET /preapproval_plan/search`.
- O `search` lista os planos **da conta/app do token**. Token de app/conta diferente
  → `results: []`. (Gotcha real: o dono criou plano numa app e o gateway usava outra.)
- MP **não apaga** plano: `PUT /preapproval_plan/{id}` `{status:'cancelled'}` (desativa).

---

## 6. 📐 DECISÕES/REGRAS DO DONO (não reabrir sem perguntar)
- **D1 — assinatura = modelo PLANO** (`preapproval_plan`), por ser o que mais
  replica o Stripe (Prices recorrentes + “Refresh Plans”). (Sobrepõe a recomendação antiga “avulsa”.)
- **D2 — usa só JetFormBuilder:** JetEngine Forms compat fica **inerte** (sem porte), documentado.
- **D3 — fulfillment via webhook** era a fundação (pendência da Fase 1): **feito**.
- **Pagamento dentro do CORE** é inegociável (Payments/Subscriptions/Query Builder/Profile Builder/relations).
- **Refund por ÚLTIMO**, com discussão de segurança a fundo (manter base do Stripe, reforçar).
- **Pix** é aditivo BR puro, só no fim.
- **Token sempre da config do gateway, server-side** (segurança; já implementado nos planos).
- **Replicar o Stripe** até ser necessário divergir → aí vira MP-native (planos/token já cruzaram esse limite).

---

## 7. 🔗 LINKS DE REFERÊNCIA
**Mercado Pago (assinaturas/checkout):**
- Criar assinatura: https://www.mercadopago.com.br/developers/pt/reference/subscriptions/_preapproval/post
- Criar plano: https://www.mercadopago.com.br/developers/pt/reference/subscriptions/_preapproval_plan/post
- Atualizar (PUT): https://www.mercadopago.com.br/developers/pt/reference/subscriptions/_preapproval_id/put
- Buscar planos: `GET https://api.mercadopago.com/preapproval_plan/search`
- Webhooks: https://www.mercadopago.com.pe/developers/en/docs/your-integrations/notifications/webhooks
- Test cards / contas de teste: https://www.mercadopago.com.br/developers/pt/docs/checkout-pro/additional-content/your-integrations/test/cards
- MCP do MP (o dono pode usar na IDE dele): https://www.mercadopago.com.br/developers/pt/docs/mcp-server/overview
- **Setup sandbox que funcionou:** 2 usuários de teste (comprador ≠ vendedor) + app
  registrada + pagar LOGADO como comprador; cartão Mastercard `5031 4332 1540 6351`
  (ou Visa `4444 4444 4444 0008`), nome `APRO`, CPF `12345678909`, validade futura, CVV `123`.

**JetFormBuilder (mecânicas confirmadas no CORE):**
- Aba de settings: filtro PHP `jet-form-builder/register-tabs-handlers` (instâncias de
  `Base_Handler`) + JS `wp.hooks.addFilter('jet.fb.register.settings-page.tabs', ns, tabs => {...})`.
  Enqueue do JS no hook `jet-fb/admin-pages/before-assets/jfb-settings`. SPA = Vue 2.
- REST do gateway: namespace `jet-form-builder/v1`; endpoints `extends Rest_Api_Endpoint_Base`
  (`get_rest_base`, `get_methods`, `check_permission`, `run_callback`); controller
  em `compatibility/jet-form-builder/rest-endpoints/rest-controller.php`.
- Re-rodar ações fora da submissão: `JFB_Modules\Form_Record\Tools` + `Record_By_Payment`
  (pagamento) / `Jet_FB_Paypal\Utils\SubscriptionUtils::execute_event_for_subscription` (assinatura).

---

## 8. 🧱 ARQUIVOS-CHAVE (mapa rápido do nosso plugin)
- **Pay-now:** `includes/compatibility/jet-form-builder/logic/pay-now-logic.php`
  + `actions/create-preference.php` (preference) + `actions/retrieve-payment.php` (GET /v1/payments/{id}).
- **Webhook (receiver):** `includes/RestEndpoints/{MercadopagoWebHookGlobal, Base/MercadopagoWebHookBase, SignatureValidator, WebhookConfig}.php`
  + `WebhookEvents/{Dispatcher, PaymentNotification, PaymentFulfillment, PreapprovalNotification, AuthorizedPaymentNotification}.php`.
- **Assinatura:** `logic/subscription-logic.php` + `actions/{create-preapproval, retrieve-preapproval, retrieve-authorized-payment, retrieve-preapproval-plan, update-preapproval}.php`
  + `rest-endpoints/{cancel-subscription, subscription-suspend}.php`.
- **Refund:** `rest-endpoints/refund-payment.php` + `actions/{refund-payment-action, search-payments}.php` (ver REFUND-ARCHITECTURE.md).
- **Planos (admin):** `rest-endpoints/{fetch,create,delete}-mercadopago-plan.php` + `mp-token-trait.php`
  + `includes/Admin/Plans_Page.php` (enqueue da aba) + `assets/js/mp-plans-settings.js` (componente Vue).
- **Gateway/credenciais:** `includes/compatibility/base-mercadopago.php` (trait: `get_credentials`, `options_list`)
  + `compatibility/jet-form-builder/controller.php`.
- **Cliente HTTP:** `includes/compatibility/jet-form-builder/actions/base-action.php` (JSON, Bearer, X-Idempotency-Key).
- **Lib agnóstica (NÃO mexer):** `includes/Shared/**` (namespace `Jet_FB_Paypal\*`).
- **Flag de assinatura:** `JFB_MP_SUBSCRIPTIONS_ENABLED` (wp-config) — liga o cenário Subscription.

---

## 9. 🧾 HISTÓRICO DE COMMITS (esta fase — branch `claude/sleepy-cray-kgcow0`)
```
(HEAD) fix(settings): aba MP Plans renderiza de fato — title=STRING, component=OBJETO c/ render,
       displayButton:false + props; raiz-causa CONFIRMADA lendo o SPA minificado + doc memória
456c0f7 feat(settings): aba "Mercado Pago Plans" (Vue) + token server-side (segurança)
835db92 feat(admin): página "MP Planos" — criar/listar/excluir planos via API (depois virou aba)
bfad42c fix(editor): Fetch_Mercadopago_Plans diagnóstico — fim do "Request failed" opaco
b1b5fab revert(pay-now): volta init_point/binary_mode pra paridade EXATA com a base
ec8b9a3 / e32fb11 fix(pay-now): binary_mode/init_point (tentativas; revertidas — era a conta de teste do MP)
3b543c5 docs: checklist de testes
75144c3 fix: refund-payment.php — use faltando (fatal) → achado pelo auditor de imports
52b41fa Fase 2: erradicação do Stripe (renomes, código morto, JetEngine gutado)
a461c1c Fase 2: refund MP-native (último api.stripe.com)
ac0a9a9 Arquitetura do refund (desenho)
275adce / a9b6562 Fase 2: assinaturas MP-native (criação+webhook+gerenciamento)
0a79bfa Fase 2: erradicação D2 (JetEngine + Webhook_Manager)
1e84a2a Fase 2 (fundação): fulfillment via webhook no pay-now + anti-fatal
(8f75826 = base do dia 16, merge do PR #7 — a versão que “funcionava” no pay-now)
```

---

## 10. ▶️ PRIMEIRA AÇÃO NA PRÓXIMA SESSÃO
1. Ler este doc + HANDOFF-FASE-2.md.
2. Pedir ao dono reinstalar o build atual e confirmar se a **aba “Mercado Pago Plans”**
   renderiza com o **label correto** (bug do §5 corrigido E reforçado: `title` string,
   `component` objeto com `render`, `displayButton:false`, props declaradas). O wiring/ordem
   já está **provado** (§5) — se ainda falhar, é Vue/CRUD, não registro. Seguir o §5 — **não supor**.
3. Com planos listando, testar **assinatura ponta a ponta** no sandbox (§4 + TESTING-CHECKLIST.md).
4. Depois: discussão de **refund** (segurança) e, por fim, **Pix**.
