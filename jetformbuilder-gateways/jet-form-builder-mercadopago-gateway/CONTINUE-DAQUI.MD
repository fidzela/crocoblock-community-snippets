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
- [ ] **(A) `account_money cannot be excluded`** — o MP **NÃO permite excluir `account_money`** (saldo MP) no Checkout Pro desta conta. Erro real ao editar a config e enviar:
  `Gateway_Exception: account_money cannot be excluded` em `pay-now-logic.php:173` (HTTP 400 do MP). **Fix:** NUNCA enviar `account_money` em `excluded_payment_types` → filtrar fora em `Payment_Methods_Config::filter_excluded()` (e/ou no save). Saldo MP fica SEMPRE disponível (constraint do MP). Refletir isso na UI (não deixar excluir saldo, ou avisar). *(Obs.: o 1º teste "só account_money" funcionou; o problema é EXCLUIR account_money.)*
- [ ] **(B) Retorno mostra erro mas o pagamento COMPLETA** — pagar com saldo: debita, aparece em Payments `completed`, MAS ao voltar pro site (após countdown) mostra "não foi possível concluir o pagamento". URL de retorno tinha `...&status=derror|Erro ao realizar a tentativa de pagamento através do Mercado Pago` mesmo com `collection_status=approved`. external_reference = `jfbmp-87844-...` (PAY NOW, form 87844). Investigar `pay-now-logic.php` `process_after()`/interpretação do retorno + back_urls/`auto_return`. Pode ser específico de `account_money`. **O webhook grava certo; o problema é a tela de RETORNO do navegador.**

### FASE 2 — correção do recurso de meios de pagamento (UI/fluxo)
- [ ] **(C) Editar/sobrescrever não persiste** — adiciona meio a um form, salva; volta em settings, escolhe o MESMO form, altera e salva → aparece "salvo" mas **some no Ctrl+Shift+R e volta o 1º salvo**. Não sobrescreve. Esperado: salvar SEMPRE sobrescreve a associação do form (boleto→boleto+cartão→só cartão→…). Investigar `savePm`/`applyFormExclusions` (JS) e `set_excluded`/`get_excluded` (PHP) + reatividade Vue ao re-selecionar o form.
- [ ] **(D) Default = SÓ CARTÕES DE CRÉDITO** (pedido do dono). Hoje "sem config" = default do `create-preference` (`[ticket, bank_transfer, atm]` excluídos → permite credito/debito/saldo/prepago). Mudar o DEFAULT para **excluir tudo menos `credit_card`** (lembrando: `account_money` não pode ser excluído → fica junto). Form vazio (config existe mas sem nada) → **avisar** com mensagem padrão JFB/JetEngine: "Este formulário não tem meios definidos, então aceita [default = cartões de crédito]". Ajustar a mensagem conforme o default.
- [ ] **(E) Fluxo/UX:** (1) bloquear "Sincronizar" antes de escolher o **Formulário** (ordem: form → sync → toggles → salvar); (2) mensagem "Meios sincronizados (5)" confusa → algo como **"Há 5 opções de pagamento disponíveis e sincronizadas com o Mercado Pago."**; (3) **separador + padding/margin** após o seletor de Formulário (bloco de meios está colado no botão); (4) **PIX não aparece** na lista sincronizada (a conta retornou: crédito, pré-pago, boleto, saldo, débito — sem `bank_transfer`/Pix). Investigar: a conta tem Pix ativo? `/v1/payment_methods` retorna Pix com `payment_type_id`=`bank_transfer`? (confirmar mapeamento/conta).
- [ ] **(F-UI) Ordenação dos forms no select = mais recentes** (hoje `forms_list()` ordena por título ASC → mudar p/ data DESC). Idealmente **select com busca (select2)** p/ muitos forms — SÓ se houver componente cx-vui nativo com busca (não recompilar/não quebrar). Se não, deixar pra lá.
- [ ] **(G) Lista "quais forms têm config e qual meio"** (espelhando a lista de Planos) — cosmético/baixo impacto, com consistência (aplicar regras do Q&A: backup/edição/remoção). Só se seguro.

### FASE 3 — Payer info COMPLETO (pay-now + subscription, em pé de igualdade)
- [ ] **(H)** Capturar dados do pagador (nome, sobrenome, e-mail, telefone, identificação/CPF, endereço/CEP) e enviar ao MP para **melhorar a pontuação da integração**:
  - **Pay-now:** no `Create_Preference` → `payer` + `additional_info.payer` (e itens). Hoje o pay-now NÃO captura o nome (fica vazio em Payments → "Payer: Not attached"). Subscription JÁ mostra o e-mail do test user em "Payer Info".
  - **Subscription:** a preapproval já leva `payer_email`; ver o que mais cabe.
  - **Entregar ao dono:** QUAIS campos do form (e o **id/key**) ele deve usar para mapear cada dado (mesmo os não-obrigatórios). Provavelmente um mapeamento por convenção de nome de campo (`payer_email`, `first_name`/`payer_name`, `last_name`, `phone`, `identification`/`cpf`, `zip_code`/`cep`, ...) + filtros. Confirmar os campos que o MP aceita na doc do Checkout Pro `additional-settings` (payer/additional_info).
  - Manter os 2 cenários **consistentes**.

### FASE 4 — cosmético / bug antigo
- [ ] **(I) Label "Renewal payment"** na tabela Payments parece errada p/ certos casos — o dono diz ser "bug antigo não resolvido". Verificar como o `type` do Payment_Model é definido (recorder: `is_renewal` por `has_prior_payment`; pay-now: outro caminho) e se a label/`type` está correta. Corrigir se for bug.
  - *(Contexto confirmado pelo dono, NÃO são bugs: "Payer: Not attached"/nome vazio no pay-now — o MP só devolve nome em produção; "Subscription" vazio num pay-now é esperado.)*

## 5. Como continuar (próximos passos imediatos)
1. `git log --oneline -15` e leia os últimos commits.
2. Comece pela **FASE 1 (A e B)** — são os que quebram/confundem o pagamento.
3. Verifique CADA ponto no código antes de mexer (regra de ouro). Para o MP, peça/observe confirmação do dono ou consulte a doc.
4. Teste o backend em standalone (padrão: `scratchpad/test_*.php` com stubs de WP) antes da UI.
5. A cada fase: lint (`php -l`, `node --check`), commit, e ao fechar versão gere o `.zip` (bump de versão; exclua os `.md` internos no zip).
6. Mantenha este arquivo atualizado (marque os checkboxes ✅ conforme concluir).

> **Arquivos-chave desta tarefa:** `includes/Payment_Methods_Config.php`, `includes/compatibility/jet-form-builder/rest-endpoints/{fetch-payment-methods,save-form-payment-methods}.php`, `includes/compatibility/jet-form-builder/actions/create-preference.php` (NÃO tocar — só via filtro), `includes/compatibility/jet-form-builder/logic/pay-now-logic.php` (FASE 1B), `assets/js/mp-plans-settings.js` + `includes/Admin/Plans_Page.php` (UI).
