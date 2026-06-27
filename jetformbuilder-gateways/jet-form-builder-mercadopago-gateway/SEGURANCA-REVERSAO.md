# 🔐 Segurança — pontos afrouxados e como REVERTER

> Lista cirúrgica do que foi **deliberadamente afrouxado** durante o projeto, o
> **porquê**, o **risco**, a **mitigação atual** e o **código exato para reverter**
> (caso algo deixe de funcionar ou se queira endurecer). Foco em **webhooks**.
>
> **Atalho importante:** o item nº 1 (o principal) **não precisa de reversão de
> código** para ficar seguro — basta **definir o segredo** (ver "Reversão SEM mexer
> no código"). Com o segredo definido, a assinatura já é enforçada.

---

## 1. ⭐ Webhook: validação de assinatura FAIL-OPEN quando não há segredo  (PRINCIPAL)

- **Arquivo:** `includes/RestEndpoints/SignatureValidator.php` → método `is_valid()`,
  bloco `if ( '' === $secret ) { ... return true; }`.
- **O que mudou:** ANTES era *fail-closed* — sem a "Assinatura secreta"
  (`JFB_MP_WEBHOOK_SECRET`), todo webhook era **recusado (401)**. AGORA é *fail-open* —
  sem o segredo, o webhook é **processado** (com aviso no log).
- **Por que afrouxei:** o fail-closed travava **todo o ciclo de assinatura** até o
  segredo ser configurado (a assinatura depende 100% do webhook; o pay-now mascarava
  por confirmar também no retorno do navegador). Sem isso, "Pending approve" eterno.
- **Risco:** o endpoint do webhook é público; um POST forjado passa pela validação de
  assinatura.
- **Mitigação que torna o risco baixo:** cada handler **RE-VERIFICA o evento com um GET
  autenticado** na API do MP (`GET /preapproval/{id}`, `/authorized_payments/{id}`,
  `/v1/payments/{id}`) — **nunca confiamos no corpo do webhook**. Um forjado só consegue
  disparar uma *consulta* de um recurso da **própria conta** (o token é nosso), jamais
  injetar estado arbitrário; e a criação de pagamento é **idempotente** (transaction_id).

### ✅ Reversão SEM mexer no código (recomendado)
Basta **definir o segredo** no `wp-config.php` (a "Assinatura secreta" do painel
**Webhooks** do Mercado Pago — NÃO é o Access Token):
```php
define( 'JFB_MP_WEBHOOK_SECRET', 'cole_aqui_a_assinatura_secreta' );
```
Com o segredo definido, o bloco do fail-open **nem roda** — a assinatura passa a ser
**enforçada** (webhook com assinatura inválida → 401). Ou seja: já fica seguro, sem
tocar no código.

### 🔁 Reversão NO CÓDIGO (fail-closed total)
Se quiser recusar webhook mesmo quando o segredo não estiver definido, troque o bloco:
```php
// ATUAL (fail-open):
if ( '' === $secret ) {
    WebhookConfig::log( 'AVISO: webhook processado SEM validar x-signature ...' );
    return true;
}
// REVERTER PARA (fail-closed):
if ( '' === $secret ) {
    WebhookConfig::log( 'RECUSADO: JFB_MP_WEBHOOK_SECRET nao configurado; assinatura nao validada.' );
    return false;
}
```
⚠️ **Atenção:** com o fail-closed e SEM o segredo definido, **todo webhook toma 401** e a
assinatura **nunca ativa** (volta o "Pending approve" eterno). Só use o fail-closed
**junto** com `JFB_MP_WEBHOOK_SECRET` definido.

> Obs.: o docblock no topo do `SignatureValidator.php` ainda descreve o comportamento
> antigo ("fail-closed / filtro allow-unsigned") — está **desatualizado** (legado);
> o comportamento real é o descrito aqui.

---

## 2. Diagnóstico no erro expõe `payer_email` + prefixo do token

- **Arquivo:** `includes/compatibility/jet-form-builder/logic/subscription-logic.php`
  → `create_resource()`, no `throw` do erro do MP (`$hint`).
- **O que faz:** quando o MP recusa a criação da assinatura, a mensagem de erro gravada
  no **Form Record** vira:
  `… [payer_email: comprador@teste.com | token: TEST-abc…]`.
- **Risco:** expõe o e-mail do pagador e os **8 primeiros caracteres** do Access Token
  no registro do formulário (visível a quem acessa o admin/Form Records).
- **Por que afrouxei:** diagnosticar o erro "Both payer and collector must be real or
  test users" (descasamento test/live) sem ficar adivinhando.

### 🔁 Reversão (remover a exposição)
No `create_resource()`, troque:
```php
// ATUAL (com diagnóstico):
if ( isset( $preapproval['error'] ) ) {
    $hint = sprintf( ' [payer_email: %s | token: %s]', ... );
    throw new Gateway_Exception( $preapproval['error']['message'] . $hint, $preapproval );
}
// REVERTER PARA (sem expor nada):
if ( isset( $preapproval['error'] ) ) {
    throw new Gateway_Exception( $preapproval['error']['message'], $preapproval );
}
```
(Alternativa: manter o `$hint` só quando `WP_DEBUG` estiver ligado.)
**Recomendado remover/gatear antes de produção.**

---

## 3. (NÃO é afrouxamento) Endpoint do webhook é PÚBLICO — por design

- **Arquivo:** `includes/RestEndpoints/Base/MercadopagoWebHookBase.php` →
  `register_endpoint()` com `'permission_callback' => '__return_true'`.
- **Por que está assim:** webhooks são chamados pelo Mercado Pago **sem autenticação
  de usuário** — TEM que ser público. A segurança é a **assinatura** (item 1) + a
  re-verificação via GET. **Não reverter** (quebraria o recebimento). Padrão de mercado
  (o Stripe/PayPal fazem igual).

---

## 4. Logs de webhook (baixo risco, já contido)

- **Arquivo:** `includes/RestEndpoints/WebhookConfig.php` → `log()`.
- **O que registram:** topic, `data_id`, `external_reference`, mensagens de status
  (nunca token/assinatura/cartão).
- **Contenção atual:** só gravam com `WP_DEBUG` ligado **ou** o filtro
  `jet-form-builder/mercadopago/webhook-logging` em true. Em produção (WP_DEBUG off) ficam
  **desligados**.
- **Garantia:** não deixar `WP_DEBUG` ligado em produção. Sem reversão de código.

---

## Resumo (o que fazer para produção segura)
1. **Definir `JFB_MP_WEBHOOK_SECRET`** no wp-config (Assinatura secreta do painel Webhooks
   do MP) → a assinatura passa a ser **enforçada** automaticamente (resolve o item 1).
2. **Remover/gatear** o `$hint` de diagnóstico do item 2 (não expor payer/token).
3. Manter `WP_DEBUG` **desligado** em produção (item 4).
4. **Não** mexer no item 3 (endpoint público é obrigatório).
