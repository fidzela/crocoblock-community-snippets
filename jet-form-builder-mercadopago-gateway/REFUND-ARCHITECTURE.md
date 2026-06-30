# 🏗️ Arquitetura do Refund (Estorno) — JetFormBuilder Mercado Pago Gateway

> **Status:** DESENHO (a implementar por último, após teste das assinaturas).
> **Princípio:** replicar o que o Stripe/PayPal já fazem, **reforçando segurança e
> lógica** onde o Mercado Pago difere. Estorno mexe em dinheiro de volta — é o
> ponto mais sensível do plugin, então aqui a régua de robustez é a mais alta.

---

## 1. API do Mercado Pago

- **Total:** `POST /v1/payments/{payment_id}/refunds` (corpo vazio) → estorna 100%.
- **Parcial:** `POST /v1/payments/{payment_id}/refunds` com `{ "amount": 12.34 }`.
- **Consulta:** `GET /v1/payments/{payment_id}` traz `status`
  (`refunded` | `partially_refunded`) e a lista `refunds[]`.

> Mapa Stripe → MP: `POST /v1/refunds {payment_intent}` → `POST
> /v1/payments/{payment_id}/refunds`. (No Stripe o id era resolvido via
> `invoice_payments`; no MP é direto pelo `payment_id`.)

---

## 2. 🔑 O GAP CRÍTICO: de onde vem o `payment_id` do MP?

O refund precisa do **id do PAGAMENTO no MP**. Onde ele está hoje, por cenário:

| Cenário | `Payment_Model.transaction_id` contém | Tem o payment_id do MP? |
|---|---|---|
| **Assinatura** (1ª/renovação) | o `payment.id` do MP (de `authorized_payments`) | ✅ **Sim, direto** |
| **Pay-now** | o **id da PREFERENCE** (não do pagamento!) | ❌ **Não** |

No pay-now gravamos `transaction_id = preference['id']` (necessário para a
reconciliação da back_url) e `initial_transaction_id = external_reference`
(reconciliação do webhook). **O payment_id do MP é consultado na confirmação mas
NÃO é persistido.** Então o refund de pay-now não tem o id direto.

### Como resolver (sem regredir o pay-now validado)

**Resolução do `payment_id` no endpoint de refund (ordem):**
1. Se `transaction_id` parece um id de pagamento do MP (numérico) **e** o cenário
   é assinatura → usa direto.
2. Senão (pay-now) → **busca** `GET /v1/payments/search?external_reference={initial_transaction_id}`
   → pega o pagamento `approved` → `id`. (Zero alteração no fluxo de pay-now.)
3. **(Otimização, opcional)** persistir o `payment_id` do MP em **`Payment_Meta`**
   (`modules/gateways/db-models/payment-meta-model.php`, do CORE) no momento da
   confirmação (`process_after` + `PaymentNotification::confirm`). É **aditivo**
   (uma escrita a mais, não muda o comportamento validado) e evita a busca.

> **Recomendação:** v1 com **busca por `external_reference`** (não toca no pay-now).
> Persistir em `Payment_Meta` entra como otimização depois, se necessário.

---

## 3. Wiring no admin (igual cancel/suspend — gateway-aware)

O botão "Refund" monta a URL via `PayPalRefundPayment::dynamic_rest_url(
['gateway' => $payment['gateway_id'], 'id' => $payment_id_interno])`. O
`Gateway_Endpoint` compõe `(?P<gateway>)/payment/refund/(?P<id>)`, então para um
pagamento MP resolve em **`mercadopago/payment/refund/{id}`**. → registrar o
endpoint MP nessa rota (hoje está em `stripe/payment/refund/...`). O `{id}` é o
**id interno** do `Payment_Model` (não o id do MP).

---

## 4. 🔒 Reforços de segurança e lógica (o ponto que o dono pediu p/ destrinchar)

1. **Idempotência (anti-duplo-estorno):** header `X-Idempotency-Key` =
   `jfbmp-refund-{payment_id_mp}` (e, para parcial, incluir o valor no hash). O
   `Base_Action` já suporta. Sem isso, um duplo-clique ou retry estorna 2×.

2. **Guard ATÔMICO de status:** só estorna pagamento `COMPLETED`; bloqueia
   `REFUNDED`/`VOIDED`. Usar a **mesma transição condicional** do pay-now
   (`UPDATE ... WHERE id=X AND status='COMPLETED'`; 0 linhas → já tratado). Isso
   fecha a janela de corrida (clique + webhook).

3. **Fonte de verdade = webhook, não o clique:** o admin DISPARA o estorno, mas o
   status `REFUNDED` no DB deve ser **confirmado pela notificação `payment`**
   (status `refunded`/`partially_refunded`), via `GET /v1/payments/{id}`
   autenticado — exatamente como já fazemos no pay-now. O `PaymentNotification`
   (tópico `payment`) ganha um ramo: se o status virou refunded → marca REFUNDED
   (atômico). Assim, estorno feito **direto no painel do MP** também reflete no
   site.

4. **Validação de valor:** `amount` do refund **≤** `amount_value` do pagamento.
   Para parcial, **somar** os refunds já feitos (`GET payment.refunds[]`) e
   impedir over-refund (o `Payment_Model` não tem coluna de "valor estornado" →
   somar do MP ou guardar em `Payment_Meta`).

5. **Total × parcial:** **v1 = total** (igual ao Shared PayPal/Stripe, que marcam
   `REFUNDED`). Parcial entra depois (precisa do acumulado do item 4 e de um
   status `partially_refunded` que o admin Shared hoje não exibe).

6. **Autorização + auditoria:** `current_user_can('manage_options')` (já é o
   padrão) + nonce do REST (o admin Shared já manda). Logar **quem/quando/quanto/
   motivo** (o MP aceita `metadata`) e gravar uma `SubscriptionNoteModel`/log
   quando for de assinatura. Avaliar capability dedicada (ex.: `jfb_refund`) no
   futuro.

---

## 5. Efeito no banco (integração com o CORE)

- `Payment_Model.status` → `REFUNDED` (`PaymentsWithSales::REFUNDED_STATUS`).
- Se o pagamento é de **assinatura**: `Subscription::set_refunded()` (só age se
  ACTIVE → `REFUNDED`), como o Stripe/PayPal fazem.
- Aparece em **JetFormBuilder → Payments** com o status REFUNDED (e a coluna
  `RefundOptionsColumn`/`PaymentStatusColumn` do Shared já lida com isso).

---

## 6. Passos de implementação (quando liberado)

1. Reescrever `rest-endpoints/refund-payment.php`: rota `mercadopago/payment/refund/{id}`;
   resolver `payment_id` do MP (seção 2); `POST /v1/payments/{id}/refunds` via uma
   nova action `Refund_Payment_Action` (com `X-Idempotency-Key`); guard atômico;
   token via `Controller::get_credentials_by_form`; text domain MP.
2. Estender `PaymentNotification` (tópico `payment`): status `refunded`/
   `partially_refunded` → marcar `REFUNDED` (atômico) + `set_refunded()` se
   assinatura.
3. (Opcional) persistir `payment_id` do MP em `Payment_Meta` na confirmação.
4. Erradicar o último `api.stripe.com` (este arquivo é o único ativo restante).

## 7. Critérios de aceite

- [ ] Estornar um pay-now → `REFUNDED` no DB, refletido por webhook, idempotente.
- [ ] Estornar 1ª cobrança/renovação de assinatura → `REFUNDED` + `set_refunded()`.
- [ ] Duplo-clique/retry **não** gera 2 estornos (idempotência + guard atômico).
- [ ] Estorno feito no painel do MP reflete no site (webhook).
- [ ] **Zero** `api.stripe.com` em caminho ativo após este passo.
