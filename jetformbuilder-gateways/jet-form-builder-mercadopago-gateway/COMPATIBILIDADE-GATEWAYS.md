# 🔗 Compatibilidade & Coexistência com os Gateways Oficiais (PayPal / Stripe)

> **Propósito:** garantir que o gateway MercadoPago **conviva como um plugin oficial**
> com o PayPal e o Stripe quando os 3 estão ATIVOS ao mesmo tempo (o JetFormBuilder
> agora permite múltiplos gateways). Este é o documento de **aparar arestas**: a
> correlação entre os plugins, os invariantes que NÃO podem quebrar, e o checklist a
> rodar antes de cada release e ao adicionar features.
>
> **Veredito (auditoria de coexistência):** ✅ **os 3 coexistem sem se quebrar.** Todas
> as nossas customizações são **condicionais ao `gateway_id`**; o que não tocamos é
> idêntico ao oficial. Nenhuma alteração de código foi necessária nesta auditoria.

---

## 0. Regra de ouro da coexistência (inegociável)

> **Se for gateway X → comportamento Y.** Toda customização nossa que rode em código
> COMPARTILHADO (lib `Jet_FB_Paypal`, que serve aos 3) DEVE ser condicional ao gateway:
> `Money::is_mercadopago( $record )` (= `$record['gateway_id'] === 'mercadopago'`).
> Para qualquer outro gateway, **cair no comportamento original** (`parent::…`). Nunca
> um efeito global sem checar o gateway.

---

## 1. O mecanismo que governa tudo: a lib Shared é COMPARTILHADA

A pasta `includes/Shared/**` (namespace `Jet_FB_Paypal`) é a **mesma biblioteca de
assinaturas** que o PayPal (dono), o Stripe e o nosso plugin embutem. Em runtime, o
`Shared/Loader.php` junta todas as cópias registradas e **carrega UMA** — a de **maior
versão semântica** — para os 3 gateways.

| Plugin | Versão que registra | Local |
|---|---|---|
| PayPal (oficial, dono da lib) | `1.0.0` | `jet-plugins/jet-form-builder-paypal-subscriptions/` |
| Stripe (oficial) | `1.0.0` | `jetformbuilder-gateways/jet-form-builder-stripe-gateway/` |
| **MercadoPago (nosso)** | **`1.0.1`** | `jetformbuilder-gateways/jet-form-builder-mercadopago-gateway/` |

**Consequência:** a NOSSA cópia (`1.0.1`) é a **selecionada** e roda para PayPal +
Stripe + MP. Por isso **nossas modificações na lib Shared têm que ser compatíveis com
os 3**. Confirmado: a nossa cópia é um **superset** (todos os 134 arquivos = os 133 do
PayPal/Stripe + a `PayerColumn` nova), então **nada falta** quando ela vence — sem risco
de fatal por classe ausente.

> ⚠️ **Aresta a MONITORAR:** se o PayPal ou o Stripe subir a versão da lib para **acima
> de `1.0.1`**, a cópia DELES passa a vencer e o MP **perde** as customizações (moeda,
> ciclo, payer e-mail). Não quebra nada — só volta ao visual padrão para registros MP.
> Ação: ao atualizar PayPal/Stripe, conferir `Shared/Loader.php::register('…')` deles e,
> se preciso, subir a nossa versão acima (e **re-rodar esta auditoria**, pois a cópia
> deles pode ter divergido). Ver comentário em `includes/Shared/Loader.php` (linha ~101).

---

## 2. O que NÓS modificamos na lib Shared (e por que é seguro)

São **5 classes** (todas em `includes/Shared/TableViews/`). Cada diff vs o PayPal
oficial confirma: a lógica nova só dispara para `mercadopago`; os demais caem no
`parent`/original.

| Classe | Modificação nossa | PayPal/Stripe |
|---|---|---|
| `Columns/GrossColumn.php` | `get_value()`: formata valor pela moeda **só** se `is_mercadopago` | `parent::get_value()` (idêntico) |
| `Columns/BillingCycleColumn.php` | branch MP: "R$ X / 3 meses" (interval_count+unit) + `mp_period_label()` | código original (unit + total_cycles) |
| `Columns/PaymentTypeColumn.php` | "Initial payment" **só** se `is_mercadopago` (pay-now reaproveita `initial_transaction_id`) | `parent::get_type_name()` |
| `Columns/PayerColumn.php` (**nova**) | e-mail como fallback **só** se `is_mercadopago` | `parent` (= `Payer_Column` do core) |
| `Payments.php` | troca `Payer_Column`→`PayerColumn` na lista; **resto idêntico** | colunas delegam ao `parent` |

**Divergências que NÃO são nossas:** `Utils/SubscriptionUtils.php` e os bundles
`assets/js/pages/*.js` diferem do PayPal, mas são **idênticos ao Stripe** — é a versão
mais nova da lib (Stripe-based) que herdamos ao copiar do Stripe. Gateway-agnósticas.

---

## 3. Matriz de coexistência (todos os pontos auditados)

| # | Componente | Como isola | Status |
|---|---|---|---|
| 1 | **Páginas admin** (Payments / Subscriptions) | mesmo slug → core deduplica (`Repository_Pattern`); a página é da lib comum e filtra por `gateway_id` na query | ✅ OK |
| 2 | **Metaboxes** das páginas single | `add_meta_box → rep_install_item_soft` dedup por slug do box → 1 box, não 3 | ✅ OK |
| 3 | **REST endpoints** (cancel/suspend/refund/receive) | rota `(?P<gateway>…)/…` + `Gateway_Endpoint` valida `gateway` da URL == `gateway_id()`. MP **não** registra os do PayPal de propósito (evita o regex casar `mercadopago/…` primeiro e dar 400) — ver `Proxy/RestApiController.php` | ✅ OK |
| 4 | **Eventos** (FormEvents) | IDs e `gateway_id` distintos: `MERCADOPAGO.SUBSCRIPTION.*` vs `PAYPAL.*`/`STRIPE.*`. MP e Stripe usam EventsManager **local**; PayPal usa o da Shared | ✅ OK |
| 5 | **Scenarios** (editor do form) | `SubscribeNow`/pay-now vêm da lib comum; cada gateway tem o seu no editor por `gateway_id` | ✅ OK |
| 6 | **Colunas** de Payments/Subscriptions | condicionais ao gateway (§2) | ✅ OK |
| 7 | **Hooks globais** | só `add_filter('jet-form-builder/use-gateways', …→true)` (flag), sem efeito colateral | ✅ OK |
| 8 | **Autoloader** da lib | um único `spl_autoload_register` (o da cópia vencedora); namespace único `Jet_FB_Paypal\` | ✅ OK |
| 9 | **Refund / Subscriber / Status columns** (não tocadas) | idênticas às oficiais; a URL de refund é montada com o `gateway_id` do pagamento | ✅ OK |

---

## 4. Invariantes — o que NUNCA pode quebrar (releia antes de mexer na Shared)

1. **Toda customização em `includes/Shared/**` é condicional ao gateway.** Se você
   adicionar lógica numa classe Shared, comece com
   `if ( ! ( class_exists( Money::class ) && Money::is_mercadopago( $record ) ) ) { return parent::…; }`.
2. **Nunca remova arquivos da nossa cópia Shared** (ela vence e precisa ser superset —
   o PayPal/Stripe dependem de TODAS as classes existirem).
3. **Nunca registre endpoints/eventos de OUTRO gateway** (PayPal/Stripe). Use só os
   `mercadopago/…` e os eventos `MERCADOPAGO.*`.
4. **Nada de hook/filtro global sem checar `gateway_id`** no caminho compartilhado.
5. **`gateway_id` é a chave de tudo.** Pay-now e assinatura gravam
   `gateway_id = 'mercadopago'`; as colunas, o refund e os eventos se isolam por ele.

---

## 5. Checklist — aparar arestas (rodar a cada release / ao adicionar feature)

**Coexistência da lib Shared:**
- [ ] A versão registrada em `includes/Shared/Loader.php` ainda é **maior** que a do
  PayPal e do Stripe? (`grep "Loader::register" nos 3`). Se PayPal/Stripe subiram,
  reavaliar.
- [ ] `diff -rq` da nossa `includes/Shared` vs a do PayPal e a do Stripe: as ÚNICAS
  divergências esperadas são `Loader.php` + as 5 classes do §2 (+ `SubscriptionUtils`/
  `assets js` herdados do Stripe). Surgiu outra? Investigar.
- [ ] Nossa cópia continua **superset** (nenhum "Only in PayPal/Stripe" no `diff -rq`).

**Comportamento por gateway (exibição):**
- [ ] Toda classe Shared que tocamos retorna `parent::…` quando `! is_mercadopago`.
- [ ] Um registro de PayPal/Stripe nas tabelas Payments/Subscriptions aparece **igual ao
  original** (moeda, tipo, ciclo, payer) — sem formatação MP vazando.
- [ ] Um registro MP aparece com o formato MP (moeda BRL, "X / 3 meses", payer e-mail).

**Registro / boot:**
- [ ] Nenhum endpoint/evento de outro gateway registrado por nós.
- [ ] Páginas e metaboxes não duplicam (dedup por slug) quando os 3 estão ativos.
- [ ] `php -l` em todos os arquivos; testes standalone do scratchpad passam.

**Teste manual (ideal, com 2+ gateways ativos):**
- [ ] Ativar MP + Stripe (e PayPal se houver) e abrir *JFB → Payments* e *Subscriptions*:
  conferir que as linhas de CADA gateway exibem corretamente na MESMA tabela.
- [ ] Refund/cancel/suspend de um registro MP usa a rota `mercadopago/…` (não a de outro).

---

## 6. Arestas conhecidas (baixo risco, monitorar — não exigem ação agora)

- **Ordem de carga das páginas admin:** qual plugin "possui" o item de menu
  Payments/Subscriptions depende da ordem de ativação (dedup = primeiro vence). É só
  cosmético — a página é a mesma (lib comum) e mostra todos os gateways. Sem ação.
- **MP desativado:** se desligar o nosso plugin, o Stripe (`1.0.0`) vence o PayPal
  (`1.0.0`) por "last write wins". Compatível (cópias estruturalmente iguais). Sem ação.
- **Versão da lib (já em §1):** PayPal/Stripe subindo acima de `1.0.1`. Monitorar.
