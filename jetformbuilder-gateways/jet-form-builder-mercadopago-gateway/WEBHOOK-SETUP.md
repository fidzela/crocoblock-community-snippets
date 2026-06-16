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

## 2. Credenciais

**Forma recomendada — pela UI do JetFormBuilder:** em
**wp-admin → JetFormBuilder → Settings → Payment Gateways → Mercado Pago**
(credenciais **globais**), preencha **Access Token** (o mesmo do pay-now;
`TEST-...` em teste) e **Webhook Secret Signature** (a "Assinatura secreta" do
painel de Webhooks do MP).

> ⚠️ O webhook só enxerga as credenciais **GLOBAIS** (não há form ativo na
> notificação). Configure no nível global, não apenas por formulário.

**Forma alternativa — constante no `wp-config.php`** (tem precedência sobre a
UI; útil para separar ambientes/versionar). As constantes/filtros abaixo
continuam valendo como override:

```php
// wp-config.php

// Access Token (APP_USR-...) — autentica o GET /v1/payments/{id} no webhook,
// onde não há gateway controller ativo. É o MESMO Access Token do gateway.
define( 'JFB_MP_ACCESS_TOKEN', 'APP_USR-xxxxxxxx...' );

// "Assinatura secreta" do painel de Webhooks (NÃO é o Access Token) — valida
// o header x-signature. OBRIGATÓRIA: sem ela, o webhook é RECUSADO (401),
// porque não há como provar que a notificação é do Mercado Pago (fail-closed).
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

## 5. Segurança (por que o endpoint é público e mesmo assim seguro)

Um webhook é, **por necessidade**, um endpoint **sem login do WordPress**: os
servidores do Mercado Pago não têm como autenticar numa sessão do WP. Isso vale
para **todo** gateway (Stripe, PayPal e o plugin oficial do MP no WooCommerce).
**Público ≠ inseguro** — a autenticação é **criptográfica**, não por login:

1. **`x-signature` (HMAC-SHA256)** — só quem tem a Assinatura secreta (o MP)
   gera assinatura válida. Sem ela → **401**. É **fail-closed**: sem
   `JFB_MP_WEBHOOK_SECRET`, recusa tudo.
2. **`GET /v1/payments/{id}`** com o Access Token privado — ninguém forja um
   pagamento "aprovado".
3. **Anti-fraude de valor + idempotência.**

**Teste você mesmo:** um POST sem assinatura no endpoint → **401**. Segurança OK.

### Plugins de segurança que bloqueiam a REST (ASE, Wordfence, etc.)
Se você usa algo que **bloqueia a REST API para não-autenticados** (ex.: ASE →
"Disable REST API for unauthenticated users"), ele barra o webhook **antes** de
chegar no nosso código (401 no Simulador). **Não desligue a proteção do site
inteiro.** O certo:
- **Mantenha** a proteção global ligada, e
- **Libere só a rota** `jfb-mercadopago/v1/webhook` na allowlist do plugin.

Não é "bypass inseguro": essa rota se **autoprotege** com a assinatura HMAC.

## 6. Teste

### Credenciais: `TEST-` (teste) vs `APP_USR-` (produção)
- Para **testar**, use o Access Token **`TEST-...`** e a Assinatura secreta do
  **app/modo de teste**.
- Em **produção**, use **`APP_USR-...`** e a Assinatura secreta de produção.
- O par precisa ser **coerente**: o segredo em `JFB_MP_WEBHOOK_SECRET` tem que
  ser o do **mesmo app/modo** cujo Simulador você está usando, senão dá **401**.

### Simulador de webhooks do painel
O Simulador envia uma notificação **assinada**, porém com um **`data.id` FALSO**
(`123456`). Então:
- Ele serve para validar **conectividade + assinatura** (sair do 404 e do 401).
- Como o id é falso, a consulta `GET /v1/payments/123456` retorna 404 no MP;
  o handler trata isso como **"payment not found" e responde 200** (não é erro).
  Ou seja: **200 no Simulador = URL + assinatura OK** (é o "verde" possível aqui).
- O teste **de ponta a ponta de verdade** é fazer um **pagamento de teste real**
  (pay-now com cartão de teste) e ver o webhook chegar com um id real.

### Ver o motivo de um 401/500 (debug)
No `wp-config.php`:
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```
Rode o Simulador e veja `wp-content/debug.log` nas linhas
`[JFB MercadoPago Webhook] ...`. Em 401 o log diz se foi **header ausente** ou
**assinatura não confere** (segredo errado). Desligue depois.

## 6. Fase 2 (próximo passo)

O hook `jet-form-builder/mercadopago/payment-approved` é o ponto de plugue para
disparar o `Gateway_Success_Event` (rodar as ações pós-pagamento do form a
partir do webhook) — necessário para **Pix/boleto** e para a fulfillment de
pagamentos de cartão com aba fechada. Será implementado e validado contra o
formulário real.
