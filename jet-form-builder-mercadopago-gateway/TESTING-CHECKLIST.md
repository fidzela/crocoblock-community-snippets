# ✅ Checklist de Testes — JetFormBuilder Mercado Pago Gateway (Fase 2)

> **Objetivo:** validar que esta versão é **estável** (sem fatal, integrada ao
> CORE do JetFormBuilder) para virar a base do projeto no lugar do Stripe.
> Marque cada item. "Passou se…" descreve a evidência esperada.
>
> **Ambiente de teste:** use **Access Token `TEST-...`** (sandbox) + **cartão de
> teste** (`APRO` = aprovado, `OTHE`/`CONT` = recusado) + uma **conta compradora
> de teste** (diferente da vendedora). Misturar produção com teste = recusa.

---

## 0. Pré-requisitos (setup)
- [ ] Access Token (`TEST-`) salvo no gateway (campo **Access Token**), por-form ou global.
- [ ] `JFB_MP_WEBHOOK_SECRET` no `wp-config.php` (segredo do webhook, do painel MP).
- [ ] No painel do MP, **notificações/webhooks** ativados para os tópicos:
      `payment`, `subscription_preapproval`, `subscription_authorized_payment`.
- [ ] Site acessível por **HTTPS público** (o MP exige p/ enviar webhook).
- [ ] Para assinatura: `define('JFB_MP_SUBSCRIPTIONS_ENABLED', true);` no `wp-config.php`.
- [ ] (Assinatura) existe pelo menos **1 plano** (`preapproval_plan`) na conta MP.
      Dica: use um plano de **frequência curta** (ex.: diária) p/ testar renovação.

---

## 1. Ativação & boot (smoke test) — ✅ JÁ VALIDADO
- [x] Plugin **ativa sem erro fatal** e sem aviso.
- [ ] `/wp-admin` abre normal; **editor do JetFormBuilder abre** (prova que a REST não quebrou).
- [ ] **JetFormBuilder → Payments** e **→ Subscriptions** abrem sem erro.
- [ ] Botão **Sync** (Access Token) no editor valida a credencial (200).

---

## 2. Pay-now (REGRESSÃO — não pode ter piorado)
- [ ] Form com gateway **Pay Now**, valor (ex.: R$10), cartão `APRO` → **redireciona** ao checkout MP.
- [ ] Retorno do navegador → **mensagem de sucesso** + ações do form rodam (e-mail, etc.).
- [ ] **Payment** aparece em *JFB → Payments* com status **COMPLETED**, valor e moeda corretos.
- [ ] **Aba fechada (D3):** pague e **feche a aba antes de voltar**. Passou se:
      o pagamento vira **COMPLETED via webhook** *e* **as ações do form rodam mesmo assim**
      (ex.: o e-mail de sucesso chega). Sem aba aberta, quem dispara é o webhook.
- [ ] Cartão recusado (`OTHE`) → mensagem de falha, pagamento **não** COMPLETED.

---

## 3. Assinatura — criação & ativação
- [ ] No editor, ao escolher **Subscription**, aparecem os campos de plano
      (**Subscription Plan** + botão **Refresh Plans From Mercadopago**).
- [ ] **Refresh Plans** lista os planos da conta (vindos de `preapproval_plan/search`).
- [ ] Submeter o form → **redireciona para o `init_point`** do MP (tela de autorização da assinatura).
- [ ] Logo após submeter (antes de autorizar): em *JFB → Subscriptions* existe uma
      linha com status **APPROVAL_PENDING** e `billing_id` preenchido (id da preapproval).
- [ ] **Autorizar** a assinatura no MP (conta de teste). Passou se, em segundos,
      a linha vira **ACTIVE** em *JFB → Subscriptions* (webhook `subscription_preapproval`).
- [ ] O **ciclo recorrente** (frequência/valor) aparece na assinatura (RecurringCycles).

---

## 4. Assinatura — cobranças (webhook `subscription_authorized_payment`)
- [ ] **1ª cobrança** (logo após autorizar): aparece um **Payment** em *JFB → Payments*
      do tipo **`initial`**, status **COMPLETED**, **vinculado à assinatura**.
- [ ] A **1ª cobrança dispara o `Gateway_Success_Event`** → as ações de sucesso do
      form rodam (teste com uma ação "Send Email" no evento de sucesso).
- [ ] **Renovação** (na virada do ciclo — use plano de frequência curta): novo
      **Payment** tipo **`renew`**, COMPLETED, vinculado à assinatura.
- [ ] A renovação dispara o **Renewal Payment Event** (anexe uma ação a esse evento e confirme).
- [ ] **Cobrança recusada** → dispara o **Gateway Failed Event** (sem criar pagamento COMPLETED).
- [ ] **Idempotência:** reenviar o mesmo webhook (Simulador do MP) **não** cria pagamento duplicado.

---

## 5. Assinatura — gerenciamento (admin)
- [ ] Em *JFB → Subscriptions*, o botão **Cancel** numa assinatura MP → status **CANCELLED**
      e dispara o **Subscription Cancel Event**. Conferir no MP que a preapproval foi cancelada.
- [ ] Botão **Suspend** → status **SUSPENDED** + **Subscription Suspended Event**;
      no MP a preapproval fica `paused`.
- [ ] **Reativar pelo painel do MP** (volta a `authorized`) → o site reflete **ACTIVE**
      via webhook + dispara o **Subscription Reactivate Event**.
- [ ] Cancelar/pausar uma assinatura **não-MP** (se houver PayPal) pelo mesmo botão
      **não** afeta a MP (e vice-versa) — gateways isolados.

---

## 6. Refund (estorno)
- [ ] Em *JFB → Payments*, **Refund** num pagamento **pay-now** COMPLETED → status **REFUNDED**;
      conferir o estorno no painel do MP.
- [ ] **Refund** numa **cobrança de assinatura** → status **REFUNDED** + a assinatura
      vai para **REFUNDED** (se estava ACTIVE).
- [ ] **Idempotência:** clicar Refund 2× (ou reenviar) **não** gera 2 estornos.
- [ ] **Fonte de verdade:** estornar **direto no painel do MP** → o site reflete
      **REFUNDED** via webhook (tópico `payment`, status `refunded`).
- [ ] Tentar estornar algo **não-COMPLETED** (já estornado) → no-op, sem erro.

---

## 7. Integração com o CORE do JetFormBuilder (o ponto central)
> O motivo de replicar o Stripe: o pagamento/assinatura tem que viver no CORE.
- [ ] **Payments**: pagamentos (pay-now, initial, renew) listam com status/tipo/valor/pagador corretos.
- [ ] **Subscriptions**: assinaturas listam com status, pagador, **pagamentos relacionados**,
      ciclo e **notas** (mudanças de status geram nota de auditoria).
- [ ] **Save Record / Form Record**: o pagamento/assinatura está **ligado ao registro** do form.
- [ ] **Query Builder (JetEngine)**: consigo montar uma query sobre os pagamentos/assinaturas.
- [ ] **Profile Builder / usuário**: o pagamento/assinatura aparece atrelado ao **usuário** correto.
- [ ] **Relations / Listing**: dá pra relacionar/listar como qualquer dado do CORE.
- [ ] Macros nas mensagens (ex.: `%gateway_amount%`) renderizam o valor/moeda certos.

---

## 8. Eventos de formulário (as "ações")
Anexe uma ação visível (ex.: **Send Email** ou **Insert/Update Post**) a cada
evento e confirme que dispara **uma vez** na transição certa:
- [ ] **Gateway Success** (pay-now aprovado + 1ª cobrança de assinatura).
- [ ] **Renewal Payment** (renovação).
- [ ] **Gateway Failed** (recusado).
- [ ] **Subscription Cancel / Suspended / Reactivate** (gerenciamento).
- [ ] (Se aplicável) **Subscription Expired**.

---

## 9. Segurança & robustez
- [ ] Webhook **sem `x-signature` válido** → **401** (fail-closed) com o segredo configurado.
- [ ] **Anti-fraude de valor** (pay-now): `transaction_amount` do MP tem que bater com o gravado.
- [ ] **Corrida webhook × retorno do navegador** (pay-now): sucesso dispara **no máximo 1×**
      (transição `CREATED→COMPLETED` atômica). *Edge conhecido:* se o webhook chega
      **antes** do retorno, o navegador pode mostrar "falha" embora as ações já tenham
      rodado — me avise se isso te incomodar que eu refino.
- [ ] Token de **ambiente errado** (prod × teste) → o webhook não confirma indevidamente.

---

## 🏁 Critério de "ESTÁVEL" (vira a base no lugar do Stripe)
Consideramos estável quando, no sandbox:
1. **Seções 1–7 passam** (boot, pay-now sem regressão, assinatura ponta-a-ponta,
   refund, e tudo aparecendo/integrando no CORE).
2. **Seção 8** confirma que as ações do form disparam nos eventos certos.
3. **Seção 9** confirma segurança/idempotência.
4. Repetir o **fluxo crítico de assinatura** (criar → autorizar → ACTIVE → 1ª cobrança →
   renovação → cancelar) **2×** sem erro.

> Depois disso: repetir um teste rápido em **produção** (`APP_USR-` + cartão real,
> valor baixo) e **estornar**, para validar credenciais reais.
