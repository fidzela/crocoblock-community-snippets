# 🧭 CONTINUE-DAQUI — handoff entre janelas de contexto

> **Você (Claude do futuro): LEIA ISTO PRIMEIRO.** Esta janela vai/foi compactada no
> meio de uma tarefa. Este arquivo te religa ao projeto. Depois de ler, **confira o
> que já foi feito e o que falta** (checklist no fim) e **continue de onde parou**,
> SEMPRE se baseando no código raiz.

---

## 0. Regras de ouro (inegociáveis)
1. **NUNCA suponha.** Confira na RAIZ: JFB core (`CORE-PLUGINS/jetformbuilder/`), lib Shared (`includes/Shared/` = `Jet_FB_Paypal\*`), addon **Stripe de referência** (`../jet-form-builder-stripe-gateway/`), comentários, e os `.md` do projeto. Para o MP, confirme na **doc/sandbox** (o dono tem acesso e cola respostas da IA do MP).
2. **Sempre responda em pt-BR.** O dono usa tradução.
3. **Não quebrar o que funciona.** Mudança aditiva > refatorar. Se for tocar em algo ativo, verifique 1º: esse caminho está ativo no setup do dono? já há decisão documentada?
4. **Integrar com o CORE, não reconstruir.** Gravar nas tabelas do CORE; usar mecanismos do JFB; nada de modelo paralelo. Tab/UI no padrão **cx-vui** (sem recompilar o `builder.admin.js`, que NÃO tem build setup aqui).
5. **Access Token NUNCA vai ao cliente.** Sempre server-side.
6. **Foco/eficiência:** ataque o problema principal; não gaste a janela em doc/secundário. Ao fechar versão, **gere o `.zip`** (excluindo os `.md` internos) e mande via SendUserFile. Commits em pt-BR no branch `claude/sleepy-cray-kgcow0`.

## 1. Referências do projeto (LEIA conforme precisar)
- `BASE-CONHECIMENTO.md` — o "porquê" de cada arquivo/decisão (mapa do código).
- `QA-RESPOSTAS.md` + `QA-CENARIOS.md` — Q&A respondido (idempotência, recuperação, segurança, etc.). Aplique essas regras a features novas.
- `REFUND-ARCHITECTURE.md` — refund (transaction_id = payment_id real do MP). **Crítico.**
- `MARCO-ASSINATURA.md`, `MEMORIA-CONTINUACAO.md`, `HANDOFF-FASE-2.md`, `WEBHOOK-SETUP.md`, `SEGURANCA-REVERSAO.md`, `PLANO-MEIOS-PAGAMENTO.md`.
- **`PLANO-PIX.md`** — plano completo de implementação do Pix (Pay Now), aprovado para executar quando a conta MP estiver com Pix ativo (ver item 2E4). Sem tocar o core; tudo via filtro.
- Commits do branch (histórico do raciocínio). Rode `git log --oneline -30`.

## 2. Invariantes da arquitetura (não podem mudar)
- **Webhook-driven**; **MP = fonte da verdade do ESTADO** (sempre re-GET; nunca confiar no corpo do webhook); **DB local = registro durável** (tabelas do CORE).
- **Idempotência** por `transaction_id` (+ lock `GET_LOCK` no recorder, CAS atômico no pay-now). Exactly-once via transição atômica.
- **Cobrança de assinatura chega como tópico `payment`** (não `subscription_authorized_payment`), roteada por `external_reference = jfbmp-sub-<id>`.
- **Subscription = Preapproval inline** (plano = template). **Pay-now = Preference (Checkout Pro).**
- **Credenciais por-form caem nas GLOBAIS quando "Use Global Settings"** (`base-mercadopago.php::get_credentials_by_form`). Config de feature por-form deve ficar em storage PRÓPRIO (option), NUNCA no blob do gateway.

## 3. Estado atual (v2.0.28)
Funciona ponta-a-ponta: pay-now + assinatura, ativação/cobrança/cancel/suspend/refund (refund encerra a assinatura — decisão do dono), reconciliador WP-Cron, flag de efeitos pendentes, auditoria sempre-ligada, aba "MercadoPago Settings" (Planos) nativa, formatação por moeda no admin.

**Recém-entregue (commit `fb0842a`, v2.0.28): meios de pagamento por-formulário (Pay Now).**
- Backend (testado standalone): `includes/Payment_Methods_Config.php` (option `jfb_mp_excluded_types` mapa form_id→[tipos] + hook no filtro `jet-form-builder/mercadopago/excluded-payment-types` que o `create-preference.php` já dispara → **create-preference NÃO foi tocado**). Endpoints `fetch-payment-methods.php` (GET /v1/payment_methods, agrupa por tipo) e `save-form-payment-methods.php`. Registrados em `rest-controller.php` + hook em `plugin.php`.
- UI: seção na aba `assets/js/mp-plans-settings.js` (data/methods/render) + `includes/Admin/Plans_Page.php` (forms + formExclusions + i18n + `forms_list()`).
- **VALIDADO pelo dono:** setou "saldo em conta apenas" e no checkout apareceu só essa opção. ✅ O caminho está certo.

## 4. TAREFA ATUAL — correções e melhorias do recurso de meios de pagamento + payer info
> Ordem definida por **dependência + criticidade** (bugs que quebram pagamento primeiro).
> **Escopo travado: PAY NOW.** Subscription = cartão (Preapproval), NÃO alterar — exceto o payer info (item F), que vale p/ os dois.

### FASE 1 — bugs que QUEBRAM o pagamento (críticos)
- [x] **(A) `account_money cannot be excluded`** ✅ FEITO (backend v2.0.29 + UI v2.0.31): `Payment_Methods_Config` nunca envia/guarda `account_money` em excluded (`NEVER_EXCLUDE`). **UI:** o switcher do "Saldo" fica travado ligado (`disabled`) com a nota "Sempre disponível — o MP não permite desativar o saldo". No JS, `alwaysOn()` espelha o `NEVER_EXCLUDE` e o `togglePm` ignora tentativas de desligar. Testado standalone (8/8) + headless (15/15).
  - *Contexto/evidência:* o MP **NÃO permite excluir `account_money`** (saldo MP) no Checkout Pro desta conta. Erro real ao editar a config e enviar:
  `Gateway_Exception: account_money cannot be excluded` em `pay-now-logic.php:173` (HTTP 400 do MP). **Fix:** NUNCA enviar `account_money` em `excluded_payment_types` → filtrar fora em `Payment_Methods_Config::filter_excluded()` (e/ou no save). Saldo MP fica SEMPRE disponível (constraint do MP). Refletir isso na UI (não deixar excluir saldo, ou avisar). *(Obs.: o 1º teste "só account_money" funcionou; o problema é EXCLUIR account_money.)*
- [x] **(B) Retorno mostra erro mas o pagamento COMPLETA** ✅ FEITO (v2.0.30). **Causa-raiz confirmada na raiz:** corrida webhook×retorno. Com `account_money` (saldo) o pagamento é instantâneo → o webhook (`PaymentNotification::confirm`) efetiva `CREATED→COMPLETED` e roda as ações de sucesso (`PaymentFulfillment`) ANTES de o cliente voltar do countdown. Aí o `process_after()` via status≠`CREATED` e lançava `"Payment was already captured"`; o `on_catch()` do core (`scenario-logic-base.php:53`) convertia em `'failed'` → `status=derror`, mesmo com pagamento aprovado. **Fix em `pay-now-logic.php`:** se a linha já está `COMPLETED` no retorno → trata como SUCESSO (status `approved` em memória) e **suprime o re-disparo** das ações (`process_status` override com flag `already_fulfilled`), pois o webhook já as rodou. VOIDED/REFUNDED mantêm o throw. Testado standalone (7/7).
  - *Evidência original:* pagar com saldo: debita, aparece em Payments `completed`, MAS ao voltar mostra "não foi possível concluir o pagamento". URL `...&status=derror|...` com `collection_status=approved`. external_reference = `jfbmp-87844-...` (PAY NOW).

### FASE 2 — correção do recurso de meios de pagamento (UI/fluxo)
- [x] **(C) Editar/sobrescrever não persiste** ✅ FEITO (v2.0.31). **Causa-raiz (frontend):** o backend SOBRESCREVE certo (provado: 4/4) — o bug era reatividade no JS. O `cx-vui-switcher` emite `returnFalse: ""` (string vazia, não `false`) e o `togglePm` fazia `this.pmKept[id]=val` (mutação de propriedade, frágil no Vue 2); os switchers não tinham `:key` (reuso de instância entre forms) → o 2º save mandava o mesmo `excluded` do 1º. **Fix:** `togglePm` agora substitui o objeto inteiro com booleans limpos; `:key=tp.id` em cada switcher + `key='pm-panel-'+pmFormId` no painel (re-monta ao trocar de form); `savePm` re-sincroniza a tela com a verdade canônica do servidor (`res.excluded`); `onSelectForm` reaplica sem exigir re-SYNC. Testado headless (15/15, inclui sobrescrita).
- [x] **(D) Default = SÓ CARTÕES DE CRÉDITO** ✅ FEITO (backend v2.0.29 + UI v2.0.31). Backend: `DEFAULT_EXCLUDED` exclui tudo menos `credit_card` (account_money fica junto, não excluível). UI: quando o form NÃO tem config (`pmHasConfig()` false), mostra aviso `cx-vui-notice--warning`: "Este formulário ainda não tem meios definidos: por padrão, aceita apenas cartões de crédito (e o saldo Mercado Pago...)".
- [~] **(E) Fluxo/UX:** (1) ✅ bloquear "Sincronizar" antes de escolher o Formulário — agora o botão SYNC só aparece com form escolhido; sem form, mostra a instrução `pmChooseFirst`; o `syncPm` também valida; (2) ✅ mensagem nova `pmSyncedMsg`: "Há %d meios de pagamento disponíveis e sincronizados com o Mercado Pago."; (3) ✅ separador `.jfb-mp-plans__pm-sep` (border-top + margin 18/22) após o seletor de Formulário. **(4) ⏳ PENDENTE — PIX não aparece. CAUSA RAIZ = DUAS CAMADAS (confirmado no código + doc MP):**
  1. **SYNC (`fetch-payment-methods.php`):** `GET /v1/payment_methods` só lista o que a conta tem **ATIVO** (filtramos `status==='active'`, agrupado por `payment_type_id`; Pix=`bank_transfer`). Se a conta — em especial **test user / sandbox** — não tem Pix habilitado (sem **chave Pix** cadastrada no MP), ele simplesmente **não vem**. Por isso o sync do dono trouxe crédito/pré-pago/boleto/saldo/débito, sem `bank_transfer`. **Ação:** habilitar Pix na conta MP (chave Pix + conta apta) e re-sincronizar.
  2. **PREFERENCE (`create-preference.php`): `binary_mode: true`** — a doc do MP confirma que binary_mode rejeita automaticamente pagamentos `pending`/em processamento. **Pix é assíncrono** (gera QR, fica `pending` até pagar) → com `binary_mode:true` o Pix é **excluído do checkout mesmo se não estiver em `excluded_payment_types`**. (Documentado em QA-RESPOSTAS §13.2.)
  **✅ CÓDIGO IMPLEMENTADO (v2.0.33) — ver `PLANO-PIX.md`.** Falta só (a) a conta com Pix ativo e (b) o teste real. Implementado: `Pix_Support` baixa o `binary_mode` (filtro `…/preference`) só nos forms que aceitam Pix/boleto (`Payment_Methods_Config::accepts_async`); o `pay-now-logic` trata o retorno `pending`/`in_process` como "aguardando" (não erro/void) com mensagem própria e sem re-disparar ações; nota na aba sobre o modo assíncrono. Confirmação assíncrona pelo webhook (`PaymentFulfillment`, já existia). **`create-preference.php` e o core NÃO foram tocados.** Testado standalone (C1+C2 12/12, C3/C4 6/6).
- [~] **(F-UI) Ordenação dos forms no select = mais recentes** ✅ FEITO (v2.0.31): `forms_list()` agora ordena por `date` DESC (mais recentes primeiro). ⏳ **select com busca (select2):** o `cx-vui-select` nativo não tem busca embutida confirmada; deixado para avaliar depois (não recompilar/não quebrar). Baixa prioridade.
- [ ] **(G) Lista "quais forms têm config e qual meio"** (espelhando a lista de Planos) — cosmético/baixo impacto, com consistência (aplicar regras do Q&A: backup/edição/remoção). Só se seguro.

### FASE 3 — Payer info COMPLETO (pay-now + subscription, em pé de igualdade)
- [x] **(H) Payer info** ✅ FEITO (v2.0.32). Classe `Payer_Info` (`includes/Payer_Info.php`) lê o form por convenção de nome (+ filtros `payer-field-map`/`payer-data`) e: **(1)** injeta `payer` + `additional_info.payer` na preference do Pay Now via o filtro `.../preference` (NÃO toca create-preference); **(2)** vincula o pagador ao pagamento no admin (`Payer_Model` + `Payer_Shipping` + `Payment_To_Payer_Shipping` no SUBMIT, via `attach_payer` no `after-send` do pay-now-logic) → resolve "Payer: Not attached" (o pay-now só gravava o Payer_Model, sem o vínculo que o Stripe cria). **Telefone** separa DDD; **CPF/CNPJ** detecta por dígitos; **endereço** zip/rua/número. **Subscription:** só `payer_email` (limite da API preapproval — documentado); o `resolve_payer_email` usa as MESMAS convenções → e-mail consistente. **Keys documentadas no README.md** (tabela). Testado standalone 10/10. *(plano original abaixo)*
  - **Pay-now:** no `Create_Preference` → `payer` + `additional_info.payer` (e itens). Hoje o pay-now NÃO captura o nome (fica vazio em Payments → "Payer: Not attached"). Subscription JÁ mostra o e-mail do test user em "Payer Info".
  - **Subscription:** a preapproval já leva `payer_email`; ver o que mais cabe.
  - **Entregar ao dono:** QUAIS campos do form (e o **id/key**) ele deve usar para mapear cada dado (mesmo os não-obrigatórios). Provavelmente um mapeamento por convenção de nome de campo (`payer_email`, `first_name`/`payer_name`, `last_name`, `phone`, `identification`/`cpf`, `zip_code`/`cep`, ...) + filtros. Confirmar os campos que o MP aceita na doc do Checkout Pro `additional-settings` (payer/additional_info).
  - Manter os 2 cenários **consistentes**.

### FASE 4 — cosmético / bug antigo
- [x] **(I) Label "Renewal payment"** ✅ FEITO (v2.0.32). **Causa-raiz confirmada:** a coluna do core (`payment-type-column.php:24`) decide "Renewal vs Initial" pela presença de `initial_transaction_id`; mas o NOSSO pay-now **reaproveita** esse campo para guardar o `external_reference` (chave de reconciliação do webhook), então ele fica sempre preenchido → TODO pay-now caía como "Renewal payment". **Fix isolado** (padrão Money, condicional ao gateway) na nossa `Shared/TableViews/Columns/PaymentTypeColumn.php`: para `mercadopago` decide pelo `type` real (initial/renew); PayPal/Stripe seguem o `parent` original (intacto). Testado 5/5 (inclui isolamento). *(o `type` é correto na origem: recorder grava initial/renew; pay-now grava initial.)*
  - *(Contexto confirmado pelo dono, NÃO são bugs: "Payer: Not attached"/nome vazio no pay-now — o MP só devolve nome em produção; "Subscription" vazio num pay-now é esperado.)*

## 5. Como continuar (próximos passos imediatos)
1. `git log --oneline -15` e leia os últimos commits.
2. Comece pela **FASE 1 (A e B)** — são os que quebram/confundem o pagamento.
3. Verifique CADA ponto no código antes de mexer (regra de ouro). Para o MP, peça/observe confirmação do dono ou consulte a doc.
4. Teste o backend em standalone (padrão: `scratchpad/test_*.php` com stubs de WP) antes da UI.
5. A cada fase: lint (`php -l`, `node --check`), commit, e ao fechar versão gere o `.zip` (bump de versão; exclua os `.md` internos no zip).
6. Mantenha este arquivo atualizado (marque os checkboxes ✅ conforme concluir).

> **Arquivos-chave desta tarefa:** `includes/Payment_Methods_Config.php`, `includes/compatibility/jet-form-builder/rest-endpoints/{fetch-payment-methods,save-form-payment-methods}.php`, `includes/compatibility/jet-form-builder/actions/create-preference.php` (NÃO tocar — só via filtro), `includes/compatibility/jet-form-builder/logic/pay-now-logic.php` (FASE 1B), `assets/js/mp-plans-settings.js` + `includes/Admin/Plans_Page.php` (UI).
