# Webhook do Mercado Pago — Configuração (Fase 1: tópico `payment`)

O pay-now (Checkout Pro) confirma o pagamento no **retorno do navegador**. O
webhook é a **rede de segurança**: confirma pagamentos quando o cliente fecha a
aba e é a **base obrigatória para a Fase 2 (Pix/boleto)**, que são assíncronos.

## 1. Endpoint

```
POST https://SEU-SITE/wp-json/jfb-mercadopago/v1/webhook
```

Esta URL já é enviada **automaticamente** no campo `notification_url` de cada
preference (só em sites **HTTPS** — em `http`/localhost é omitida para não
quebrar a criação da preference).

## 2. Credenciais (via constante em `wp-config.php` ou filtro)

O painel de configuração do gateway é um app Vue compilado; para evitar
recompilar, as credenciais do webhook são lidas de constantes/filtros.

```php
// wp-config.php

// Access Token (APP_USR-...) — autentica o GET /v1/payments/{id} no webhook,
// onde não há gateway controller ativo. É o MESMO Access Token do gateway.
define( 'JFB_MP_ACCESS_TOKEN', 'APP_USR-xxxxxxxx...' );

// "Assinatura secreta" do painel de Webhooks (NÃO é o Access Token) — valida
// o header x-signature. Sem ela, a validação é PULADA (com aviso no log);
// o GET autenticado continua sendo a fonte de verdade do pagamento.
define( 'JFB_MP_WEBHOOK_SECRET', 'sua-assinatura-secreta' );
```

Equivalentes via filtro (se preferir não usar constantes):

```php
add_filter( 'jet-form-builder/mercadopago/webhook-access-token', fn() => 'APP_USR-...' );
add_filter( 'jet-form-builder/mercadopago/webhook-secret',       fn() => '...' );
add_filter( 'jet-form-builder/mercadopago/notification-url',     fn( $u ) => $u );
```

## 3. Painel do Mercado Pago

**Suas integrações → (sua aplicação) → Webhooks → Configurar notificações**

1. Informe a URL do endpoint (item 1).
2. Marque o evento **Pagamentos** (tópico `payment`).
3. Copie a **Assinatura secreta** gerada → use em `JFB_MP_WEBHOOK_SECRET`.

> A `notification_url` enviada na preference tem **precedência** sobre a do
> painel, mas a **Assinatura secreta** vem da configuração do painel.

## 4. Como funciona o handler

1. Recebe a notificação (`type` + `data.id`, de query **ou** corpo).
2. Valida o `x-signature` (HMAC-SHA256) — se o segredo estiver configurado.
3. `GET /v1/payments/{data.id}` (fonte de verdade).
4. Reconciliação por `external_reference` (gravado em `initial_transaction_id`).
5. **Idempotência**: só age na transição `CREATED → COMPLETED`.
6. **Anti-fraude**: confere `transaction_amount` (MP) × `amount_value` (DB).
7. Aprovado → marca `COMPLETED` e dispara
   `do_action( 'jet-form-builder/mercadopago/payment-approved', $payment, $row )`.

Respostas: **200** para tratado/ignorado; **401** assinatura inválida;
**500** apenas em erro transitório de API (para o MP reenviar).

## 5. Teste (modo produção)

Use o **Simulador de webhooks** do painel do Mercado Pago (envia uma
notificação real **assinada** ao endpoint) e/ou credenciais **TEST-** com
cartões de teste. Ative `WP_DEBUG` para ver os logs do handler
(`[JFB MercadoPago Webhook] ...`).

## 6. Fase 2 (próximo passo)

O hook `jet-form-builder/mercadopago/payment-approved` é o ponto de plugue para
disparar o `Gateway_Success_Event` (rodar as ações pós-pagamento do form a
partir do webhook) — necessário para **Pix/boleto** e para a fulfillment de
pagamentos de cartão com aba fechada. Será implementado e validado contra o
formulário real.
