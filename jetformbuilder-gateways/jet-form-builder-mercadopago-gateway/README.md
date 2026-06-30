# JetFormBuilder Mercadopago Gateway
Addon for JetFormBuilder & JetEngine Forms

## Payer info — chaves dos campos do formulário (Pay Now)

Para enviar os dados do pagador ao Mercado Pago (melhora a aprovação/anti-fraude) e
exibi-los em **JetFormBuilder → Payments**, dê ao campo do formulário um dos **NAME/ID**
abaixo (basta UM por dado; o primeiro preenchido vence):

| Dado | NAME/ID aceitos no campo |
|---|---|
| **E-mail** (obrigatório) | `payer_email`, `email`, `e_mail`, `mail`, `user_email` |
| Nome | `payer_first_name`, `first_name`, `payer_name`, `nome`, `primeiro_nome` |
| Sobrenome | `payer_last_name`, `last_name`, `sobrenome`, `surname` |
| Nome completo (alt. a nome+sobrenome) | `payer_full_name`, `full_name`, `nome_completo`, `name` |
| DDD (opcional, separado) | `payer_area_code`, `area_code`, `ddd` |
| Telefone | `payer_phone`, `phone`, `telefone`, `celular`, `whatsapp`, `fone`, `tel` |
| CPF/CNPJ | `payer_cpf`, `cpf`, `payer_cnpj`, `cnpj`, `identification`, `documento`, `doc` |
| CEP | `payer_zip`, `zip_code`, `cep`, `postal_code`, `codigo_postal` |
| Rua / logradouro | `payer_street`, `street_name`, `endereco`, `rua`, `logradouro` |
| Número | `payer_number`, `street_number`, `numero` |
| Cidade | `payer_city`, `city`, `cidade` |
| Estado / UF | `payer_state`, `state`, `estado`, `uf` |

Observações:
- O **e-mail é a chave**: sem ele o pagador não é vinculado ao pagamento no admin.
- O **telefone** pode ser um campo único com DDD (ex.: `(11) 99999-8888`) — o DDD é separado
  automaticamente — ou DDD + número em campos distintos.
- O **CPF/CNPJ** é detectado pela quantidade de dígitos (11 = CPF, 14 = CNPJ).
- Vale para **Pay Now**. Na **Assinatura**, o Mercado Pago usa apenas o **e-mail** (limitação
  da API de preapproval); os demais dados são informados pelo pagador no checkout do MP.
- Os nomes de campo são personalizáveis pelo filtro `jet-form-builder/mercadopago/payer-field-map`.

# ChangeLog

## 2.0.35
* ADD: a coluna "Payer" da tabela *JFB → Payments* mostra o **e-mail** do pagador quando
  não há nome (test users, ou quando o Mercado Pago não devolve o nome) — antes ficava
  "Not attached" mesmo com o pagador vinculado.
* UX: botão renomeado para **"Sincronizar meios de pagamento"** + espaçamento entre o
  botão e a lista de meios na aba MercadoPago Settings.
* CHORE: limpeza/organização para a versão estável — remoção de **código morto** (cliente
  de API legado do JetEngine, jamais usado pelo JetFormBuilder) e consolidação de um
  helper interno de contexto do formulário (`FormContext`). Sem mudança de comportamento.

## 2.0.34
* FIX: **Payer info do Pay Now** agora aparece em *JFB → Payments* (resolve o "Payer:
  Not attached"). O pay-now passa a criar a cadeia `Payer_Model` + `Payer_Shipping` +
  `Payment_To_Payer_Shipping` com os dados que o **Mercado Pago devolve** (`payment.payer`),
  no webhook **e** no retorno — paridade com a Assinatura. Não depende mais de campos no
  formulário (o e-mail/nome do pagador real vem do MP, inclusive no Pix). Os campos do
  form mapeados (ver tabela acima) continuam sendo enviados ao MP para pré-preencher o
  checkout e melhorar a aprovação.

## 2.0.33
* ADD: Suporte a **Pix/boleto** no Pay Now (Checkout Pro). Quando um formulário aceita
  Pix (não exclui `bank_transfer`) ou boleto (`ticket`), a preference passa a usar
  `binary_mode=false` (necessário para meios assíncronos) e o retorno em `pending` mostra
  "aguardando pagamento" em vez de erro — a venda é confirmada pelo webhook. Forms só-cartão
  ficam idênticos. Requer Pix ativo na conta Mercado Pago (chave Pix). Sem alterar o core.

## 2.0.32
* ADD: Payer info no Pay Now — envia nome/CPF/telefone/endereço do formulário ao Mercado Pago
  (`payer` + `additional_info.payer`) e vincula o pagador ao pagamento no admin (resolve
  "Payer: Not attached"). Ver a tabela de campos acima.
* FIX: coluna "Payment Type" mostrava "Renewal payment" para pagamentos Pay Now (o campo
  `initial_transaction_id`, reaproveitado para reconciliação, confundia a heurística do core).

## 2.0.3
* FIX: Getaways not working when Jet Appointments Booking is enabled

## 2.0.2
* ADD: Custom form events for Mercadopago subscription actions

## 2.0.1
* FIX: Form actions execution after Stripe subscription
  
## 2.0.0
* ADD: Subscription support

## 1.1.2
* FIX: Successful access token update with empty keys
* Tweak: Update jfb-addon-core to `1.1.11`

## 1.1.1
* ADD: `jet-form-builder/gateways/before-create` php hook
* FIX: Request body was changed according to [stripe upgrade](https://stripe.com/docs/upgrades#2022-08-01)

## 1.1.0
* ADD: Compatibility with Form Records
* UPD: Redirect to the checkout on the server-side

## 1.0.4
* FIX: JetAppointment compatibility

## 1.0.3
* ADD: filter `jet-form-builder/stripe/payment-methods`, for change payment method types
* Tweak: Removed unnecessary hook

## 1.0.2
* Tweak: add license manager

## 1.0.1
* FIX: Error when global settings did not use

## 1.0.0
* Initial release
