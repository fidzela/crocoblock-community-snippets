# 🗺️ Plano — Meios de pagamento por formulário (Checkout Pro / Pay Now)

> Objetivo: permitir escolher, **por formulário**, quais meios de pagamento o
> Checkout Pro aceita no Pay Now (ex.: só PIX num form; cartão+PIX em outro;
> excluir boleto). Sólido, sem quebrar o que funciona, o mais próximo possível do
> CORE/base. **Status: PLANO (sem código ainda).**

---

## 1. Avaliação do fork da SDK (`fidzela/mercadopago-sdk`)

- **Acesso:** ✅ sim, consegui ler (é repo público).
- **É novo pra mim?** **Não.** É a **SDK oficial do Mercado Pago em PHP** (fork). A API do MP eu já conhecia; o fork não traz documentação de API que eu não tivesse. Os `examples/` só **confirmam** a estrutura da preference (`payment_methods`) — útil como referência, mas nada inédito.
- **Nosso plugin precisa da SDK?** **NÃO — e é melhor NÃO adotar.** Motivos:
  - Nós chamamos a API por **HTTP direto** (`Base_Action`/cURL) — leve, sem dependências.
  - A SDK exige **PHP 8.2+**; nosso plugin tem como alvo **PHP 7.0+**. Adotá-la subiria o piso de PHP e quebraria sites.
  - A SDK traz Guzzle/etc. → peso e risco de conflito com outros plugins.
- **A pasta `tests/`** = **testes automatizados (unit/integration) da PRÓPRIA SDK** (PHPUnit). Não servem direto pro nosso plugin, MAS são uma **boa referência de padrão**: vale, como iniciativa SEPARADA, montar um **PHPUnit nosso** testando NOSSAS classes (hoje fazemos testes standalone no scratchpad; um suite de verdade seria o upgrade). Não é pré-requisito desta feature.

**Resumo:** a SDK ajuda só como referência/confirmação; **não entra no plugin**. O ganho real de "antes não era possível" é **zero** aqui — já fazíamos tudo por HTTP. O que vale aproveitar é a **ideia de testes automatizados** (separado).

---

## 2. Checkout Pro — `payment_methods` na preference (`POST /checkout/preferences`)

O bloco `payment_methods` controla o que aparece no checkout:

```json
"payment_methods": {
  "excluded_payment_types":   [ { "id": "ticket" }, { "id": "atm" } ],
  "excluded_payment_methods": [ { "id": "visa" } ],
  "installments": 12,
  "default_installments": 1,
  "default_payment_method_id": null
}
```

**Tipos de pagamento (`payment_type_id`) — lista FIXA e pequena:**
| id | significado |
|---|---|
| `credit_card`   | cartão de crédito |
| `debit_card`    | cartão de débito |
| `ticket`        | **boleto** |
| `bank_transfer` | **PIX** (no Brasil) |
| `atm`           | pagamento em caixa eletrônico |
| `account_money` | saldo Mercado Pago |

- **"Só PIX"** = excluir `credit_card`, `debit_card`, `ticket`, `atm`, `account_money` (sobra `bank_transfer`).
- **"Cartão + PIX, sem boleto"** = excluir `ticket`, `atm`, `account_money`.
- **Métodos** (`excluded_payment_methods`: `visa`, `master`, `pix`, `bolbradesco`…) = granularidade fina, raramente necessária; os **tipos** cobrem 99% dos casos.

**O nosso código JÁ faz isto** (`create-preference.php` → `build_preference()` → `payment_methods.excluded_payment_types`), hoje fixo em `[ticket, bank_transfer, atm]` (só cartão), com o **filtro** `jet-form-builder/mercadopago/excluded-payment-types`. **Falta só a config por-form** que alimenta esse ponto.

> **Aplica-se a PAY NOW apenas.** Subscription usa **Preapproval** (cobrança recorrente = cartão por natureza; PIX/boleto não são recorrentes). Então a seleção de meios é **exclusiva do Pay Now**.

---

## 3. A restrição que define a arquitetura

- O editor do gateway (onde ficam Pay Now/Subscription, plano etc.) é renderizado por um **JS COMPILADO** (`builder.admin.js`) que só conhece chaves fixas. **Não há build setup** no repo (sem `package.json`/webpack) → **não dá pra adicionar campo no editor sem o ambiente de build original.** Foi exatamente por isso que a **aba de Settings** foi criada.
- Logo, "campo dentro da ação do gateway no editor" (o ideal ergonômico) **está fora** sem recompilar.
- **Mas** a config pode ser lida no submit: o `Create_Preference` roda com `jet_fb_handler()->form_id` disponível → **dá pra ter config por-form** armazenada e lida na hora.

---

## 4. Opções de UI (onde o dono configura) — honesto

| Opção | Onde | Form-level? | Recompila editor? | Esforço | Ergonomia |
|---|---|---|---|---|---|
| **1. Seção na aba** (após Planos) + seletor de form | Settings tab | Sim (via seletor) | ❌ não | baixo | média (config longe do form) |
| **2. Meta box na tela do form** ⭐ | Editor do form (painel lateral) | **Sim, nativo** | ❌ não | médio | **alta** (config junto do form) |
| 3. Campo na ação do gateway | Editor do gateway | Sim | ✅ **exige recompilar** | alto/bloqueado | ideal, mas inviável aqui |

**Recomendação: Opção 2 (meta box na tela de edição do form).** É a que entrega **form-level de verdade** (o que você quer), **sem recompilar** o editor, e fica **junto do formulário** (mais ergonômico que a aba). A Opção 1 é o plano B (mais simples, mas a config mora na aba).

---

## 5. Estrutura de implementação (Opção 2 recomendada)

**A. Armazenamento (genérico e extensível):** `post_meta` do form (CPT `jet-form-builder`), chave `_jfb_mp_preferences`, guardando um objeto de **overrides da preference** — não só meios de pagamento, já preparado para o resto do `additional-settings`:
```
_jfb_mp_preferences = {
  "excluded_payment_types":   ["ticket","atm"],
  "excluded_payment_methods": [],
  "installments": 12,
  "statement_descriptor": "",        // futuro
  "expiration_minutes": null         // futuro
}
```
Default ausente = comportamento ATUAL (retrocompatível).

**B. Fonte do SYNC:** nova action `Retrieve_Payment_Methods` → `GET /v1/payment_methods` (lista os meios da CONTA: id, name, payment_type_id, status). + REST endpoint `fetch-mercadopago-payment-methods` (server-side, token do gateway — nunca no cliente), espelhando o `fetch-mercadopago-plans`.

**C. UI (meta box):** registrada via `add_meta_box` no CPT do form; enqueue de um JS/CSS próprios (cx-vui, **mesmo padrão da aba** — sem recompilar nada do core); botão **SYNC** (busca os meios da conta) + **multi-select** "Excluir estes meios" (agrupados por tipo) + parcelas. Salva no `post_meta` via REST/nonce.

**D. Integração (o ponto que já existe):** hook no filtro **`jet-form-builder/mercadopago/excluded-payment-types`** (e/ou `…/preference`) lendo `_jfb_mp_preferences` do `jet_fb_handler()->form_id`. Sem tocar no fluxo: se não houver config, mantém o default de hoje.

**E. Escopo/guardas:**
- Só Pay Now (no Subscription o meta box nem aparece, ou aparece desabilitado com nota).
- Validação: nunca excluir TODOS os tipos (checkout vazio) — bloquear no save.
- `installments` default 12 (como hoje).

**Arquivos novos (espelham os de planos):**
- `includes/compatibility/jet-form-builder/actions/retrieve-payment-methods.php`
- `includes/compatibility/jet-form-builder/rest-endpoints/fetch-mercadopago-payment-methods.php`
- `includes/compatibility/jet-form-builder/rest-endpoints/save-form-preferences.php` (salvar o meta)
- `includes/Admin/Form_Preferences_Metabox.php` (registro + enqueue)
- `assets/js/mp-form-preferences.js` + `assets/css/mp-form-preferences.css`

**Arquivos alterados (mínimo, aditivo):**
- `create-preference.php`: `get_excluded_payment_types()`/`installments` passam a ler `_jfb_mp_preferences` do form (com fallback no default atual). **Nada removido.**

**Risco:** baixo — tudo aditivo; o caminho atual segue idêntico quando não há config; zero mudança no editor compilado; zero dependência nova.

---

## 6. Fora de escopo (desta etapa) / futuro
- `excluded_payment_methods` (granularidade por método) — a estrutura já comporta; ligar depois se preciso.
- Outros `additional-settings` (expiração, statement_descriptor, shipments…) — o objeto `_jfb_mp_preferences` já é o lugar deles.
- Suite PHPUnit própria (inspirada nos `tests/` da SDK) — iniciativa separada.
