# ✅ Q&A — Respostas (verdade do código `v2.0.17`)

> Cada resposta foi conferida na RAIZ do código (não suposição). Legenda:
> **✅ intencional/ok** · **🐞 aresta/risco real** · **🔧 melhoria** · **⚖️ decisão do dono** ·
> **🔎 MP (conferir na doc atual)**. Onde marquei 🔎, é conhecimento de modelo + como o
> NOSSO código trata — confirme contra a doc/sandbox do MP antes de fechar.
>
> **TL;DR das arestas que mexem com DINHEIRO (detalhe no plano no fim):**
> double-submit cria 2 assinaturas reais (§3.2); dedup da cobrança depende de um id
> convergir entre 2 tópicos (§4.2); recorder grava cobrança com check-then-insert SEM
> unique/lock → janela de duplicar (§7.1/§8.1); cobrança aprovada reativa assinatura
> CANCELADA (§5.2/§11.3); refund direto no MP não reflete no status da assinatura (§12.4).

> ## ✅ P0 RESOLVIDO — v2.0.18
> As três arestas P0 (dinheiro) foram fechadas:
> 1. **Double-submit (§3.2):** `Subscription_Logic::after_actions()` agora tem trava
>    por fingerprint da submissão (lock `GET_LOCK` + transient de 90s) → o 2º envio
>    idêntico reaproveita o `init_point` em vez de criar uma 2ª preapproval real.
> 2. **Dedup da cobrança (§4.2/§7.1/§8.1/§8.3):** confirmado que os dois tópicos usam o
>    id do pagamento real como `transaction_id` (converge); o check-then-insert do
>    `SubscriptionPaymentRecorder::record()` agora é serializado por lock nomeado por
>    `transaction_id` (novo helper `Locks`, com degradação segura sem `GET_LOCK`).
> 3. **Assinatura terminal (§5.2/§11.3):** novo `SubscriptionStatusGuard::is_terminal()`
>    (CANCELLED/EXPIRED/REFUNDED). Recorder e `PreapprovalNotification` NÃO reativam
>    assinatura terminal; o pagamento tardio é **registrado** (verdade financeira) mas
>    **sem reativar e sem evento** — logado para reconciliação manual.

---

## §1 Arquitetura & legado
- **1.1** Remoção quase completa. **`api.stripe.com` NÃO existe em caminho ativo** — só num docblock (`api-methods/base-api-method.php`), então **§15.6 já está resolvido**. Remanescentes: `api-methods/checkout-session.php` é **código morto** (classe não usada no fluxo MP) → 🔧 remover; `get_checkout_session()` no trait é compat JetEngine **inerte** (mantido de propósito — a camada JetEngine pode chamar; some sem fatal); o docblock de `base-action.php` cita nomes antigos (`Create_/Retrieve_Checkout_Session`) → 🔧 legado, limpar. **Nenhum fluxo ativo** espera `{CHECKOUT_SESSION_ID}` (pay-now usa `preference_id`, assinatura usa `subscription_id`). ✅+🔧
- **1.2** Intencional por ora: `PaymentNotification` roteia **e** muta. Fronteira ideal = dispatcher fino + sub-handlers próprios (`reconcile_refund` e `handle_subscription_payment` saem para classes próprias). 🔧 consolidação P1, não urgente.
- **1.3** Endpoint único é a intenção final. Retry/status **uniforme** hoje (200 = tratado/ignorado; 500 = transitório p/ reentrega). Por-tópico é over-engineering agora. ✅
- **1.4** Redundância de **tópicos** é estado final aceito (robustez: o MP pode mandar `payment` e/ou `subscription_authorized_payment`). A dedup de **código** já foi feita na v2.0.17 (`AuthorizedPaymentNotification` delega ao recorder). ✅ (feito)

## §2 Webhook — recebimento/roteamento/resposta
- **2.1** Tópico desconhecido → `200` + log `"Tópico NÃO tratado"` (só com WP_DEBUG). 🔎 `merchant_order`/`disputes`/`point_integration_wh` podem chegar nessa URL; hoje viram 200 ignorado. **Chargeback/disputa que vira `charged_back` JÁ é coberto** (chega como `payment` com status `charged_back` → `reconcile_refund`). 🔎 confirmar se disputa precisa de status próprio (ver §11.1).
- **2.2** `created`/`updated` → mesmo `payment` (intencional: o **GET é a fonte de verdade**, pega o status atual; created vs updated não muda o efeito). 🔎 não há transição que o GET não reflita.
- **2.3** `200 "no data.id"` descarta. 🔧 melhoria barata: **logar** (pode ser header removido pelo host). Hoje não loga esse caso.

## §3 Assinatura — criação & ativação
- **3.1** Intencional. Determinístico `jfbmp-sub-<id>` = idempotência de retry da MESMA submissão. PK autoincrement **não reusa** → dois envios = dois ids = dois `external_reference`, **sem colisão**. ✅
- **3.2** 🐞 **ARESTA P0.** Double-submit gera **duas linhas locais** (cada uma com id/external_reference próprio) → **duas preapprovals REAIS no MP** (a idempotency-key do MP é por external_reference, que difere entre os submits). Ou seja: **duas assinaturas/cobranças**. A 2ª recebe `billing_id` normalmente. 🔧 precisa de **trava de double-submit** (token de submissão / lock por form+user) antes do `create_subscription`.
- **3.3** Órfãs `APPROVAL_PENDING` **não** apagadas no submit (capability). Hoje **não há sweeper**; mas o admin **já consegue deletar** (status APPROVAL_PENDING é "broken" → botão Delete aparece). 🔧 sweeper diário (TTL ~24h via WP-Cron) seria a rede. ⚖️ entra agora?
- **3.4** Plano é template **inline**. Editar/cancelar o plano no painel do MP **não afeta** assinaturas vivas (são independentes). ✅ nada a sincronizar — é a vantagem do inline.

## §4 Assinatura — ordem & corrida
- **4.1** ✅ O 1º evento de sucesso sai **só da cobrança** (recorder → `Gateway_Success_Event`); `preapproval=authorized` vindo de pendente **não** dispara evento. Logo, **exatamente-uma-vez** independente da ordem. Se vier só `authorized` e **nunca** a cobrança → `ACTIVE` sem Payment/evento (esperado; na prática o MP cobra junto). 🔎
- **4.2** 🔎🐞 **A MAIS CRÍTICA.** A dedup é por `transaction_id`. No tópico `payment` usamos `payment['id']`; no `authorized_payment` usamos `ap['payment']['id']`. **Se `authorized_payment.payment.id` === o `id` do tópico `payment` (mesma cobrança), a dedup converge** e está OK. **Se divergirem, a cobrança entra 2×.** → **CONFERIR na doc/sandbox do MP** (é o item que mais importa validar). Sem UNIQUE no banco (§10.3), essa é a única barreira.
- **4.3** Ambos disparam: `SubscriptionReactivateEvent` (preapproval, vindo de SUSPENDED) + `RenewalPaymentEvent` (recorder). Eventos distintos, **ambos devem disparar**. `maybe_activate`/`set_active` sem lock → colisão **inofensiva** (idempotente, ambos setam ACTIVE). ✅

## §5 Assinatura — ciclo & eventos
- **5.1** Admin e webhook fazem o mesmo (status + evento). **Guards de transição** cobrem o duplo: se o admin cancela (status=CANCELLED), o webhook `cancelled` vê CANCELLED → **não** re-dispara. ✅
- **5.2** 🐞 **ARESTA P0/P1.** `CANCELLED` + `preapproval=authorized` tardio → `PreapprovalNotification` faz `set_active()` **sem checar terminal** → **reativa uma cancelada**. 🔎 o MP normalmente **não** reativa `cancelled` (só `paused→authorized`), mas uma **reentrega tardia** de um `authorized` antigo reativaria localmente. 🔧 **guard:** não reativar se local estiver CANCELLED/EXPIRED.
- **5.3** 🐞 mapa **incompleto:** falta o **fim natural** (`finished`/expiração por `end_date`) → hoje nunca vira `EXPIRED` via webhook. 🔎 confirmar os status de preapproval do MP e mapear o fim → `EXPIRED`. 🔧

## §6 Cobrança recorrente
- **6.1** "primeiro visto = initial". Reentrega fora de ordem pode rotular errado. **Aceitável** para relatório/evento (o que importa é registrar). Refletir a sequência real exigiria o billing-day do MP. ✅ (com ressalva)
- **6.2** Recusa → `Gateway_Failed_Event`, sem mexer no status. 🔎 o **MP suspende a preapproval sozinho** após X falhas → chega `preapproval=paused` → já cobrimos. **Não** duplicar lógica de suspensão local. ✅
- **6.3** `pending` no-op; `approved` registra. **Confiamos na reentrega** do MP do `approved` (o MP reentrega). Pix é fase futura. ✅ (sem polling por ora)

## §7 Idempotência
- **7.1** Pay-now: CAS atômico ✅. **Assinatura: check-then-insert racy** → entrega **simultânea** pode inserir 2×. 🐞 **P0.** Fecha com **UNIQUE em `transaction_id`** (não existe — §10.3) **ou lock** no recorder.
- **7.2** Guards (`if status !== X`) + GET como fonte de verdade. `refunded` antes de `approved`: o pay-now `confirm` exige `CREATED` → não re-confirma. Combinação inconsistente real = **§11.3** (approved após cancelado). 🐞
- **7.3** Atrasado → sempre **re-GET** (fonte de verdade) + idempotente. **Sem** carimbo de tempo máximo (ver replay §9.2). 🔧
- **7.4** 🐞 **Perdido: NÃO há reconciliador.** O webhook é o canal único (no pay-now há também o retorno do navegador). 🔧 **reconciliador** (varredura `payment/search` por `external_reference`, `GET /preapproval/{id}`) para `APPROVAL_PENDING`/`CREATED` parados. ⚖️ P1.

## §8 Concorrência
- **8.1** 🐞 **Assimetria P0:** pay-now usa CAS; recorder usa check-then-insert **sem lock e sem unique**. 🔧 padronizar: **UNIQUE `transaction_id`** (schema) é o mais limpo; alternativa = **lock nomeado** (`GET_LOCK`/transient) por `transaction_id` no recorder.
- **8.2** 🐞 `record()` **não-transacional** (Payment_Model → SubscriptionToPayment → link_payment_to_payer; só `attach_payer` está em transação). Falha no meio = **Payment órfão** sem vínculo. 🔧 envolver o `record()` inteiro em transação (tudo-ou-nada). P1.
- **8.3** 🐞 `maybe_activate` + `Gateway_Success` gated pelo MESMO SELECT racy → sob concorrência, **2 processos podem disparar o evento**. Mesma raiz de §7.1/§8.1 → UNIQUE/lock resolve. P0.

## §9 Segurança
- **9.1** Sim, depende só do `secret`. Manifest `id:<data.id>;request-id:<x-request-id>;ts:<ts>;`. 🔎 **conferir o template atual do MP** (mudança aqui = `401` silencioso).
- **9.2** 🐞 `ts` **não** é comparado ao tempo atual → **replay** de notificação válida é possível. Mitigação atual: GET + idempotência. ⚖️ **decisão:** aceitar OU adotar **idade máxima do `ts`** (±5 min). 🔧 recomendo a janela (barato, fecha o replay).
- **9.3** Sem janela de tempo e sem **nonce** (`x-request-id` não é armazenado/checado). ⚖️ implementar nonce+timestamp **ou** confiar na idempotência. Recomendo **timestamp** já; nonce opcional.
- **9.4** ⚖️ **DECISÃO DO DONO (pendente).** Fail-open por padrão (funciona out-of-box; endurece definindo `JFB_MP_WEBHOOK_SECRET`) **ou** enforce por padrão com gate de setup. Recomendo **fail-open + documentar forte o segredo** (menos atrito), mas é sua chamada para o release.
- **9.5** 🔧 **Sim, alinhar** o docblock do `SignatureValidator` (ainda diz fail-closed) ao código (fail-open). 1 edit — posso fazer já.
- **9.6** ✅ **JÁ FEITO (v2.0.16):** o diagnóstico `[payer_email | token]` agora só aparece com `WP_DEBUG`.
- **9.7** ✅ Público por design (o MP chama sem auth). **Não** mexer (segurança = assinatura + GET).
- **9.8** 🐞 **Multi-conta NÃO suportado.** O token do webhook é **global** (`WebhookConfig::access_token`). Se forms diferentes usarem **contas MP diferentes**, o GET de verificação usa o token global → um pagamento da conta A consultado com token B → **404** → tratado como "não é nosso" e ignorado. ⚖️ assume-se **1 conta MP por site**? Se multi-conta for requisito → resolver token **por recurso/form** dentro do webhook (P1).

## §10 Banco
- **10.1** ✅ `subscriptions_to_payments`: cascade em `subscription_id` apaga as junções, **não** os `Payment_Model` → **pagamentos preservados** (histórico financeiro). Intencional/aceitável.
- **10.2** 🐞 `billing_id` **sem índice e sem UNIQUE**. 🔧 **adicionar índice** (hoje é varredura nos webhooks) + considerar **UNIQUE** (impede 2 subs c/ a mesma preapproval — liga em §3.2). P1.
- **10.3** 🐞 **`transaction_id` é só `index`, NÃO `UNIQUE`** (no Payment_Model do **core**). Logo a idempotência repousa **só** nos checks de aplicação → §8.1 fica em aberto. 🔧 criar UNIQUE é o ideal, **mas é schema do CORE** (risco) → alternativa = lock no recorder. ⚖️
- **10.4** Reaper deve rodar como **WP-Cron/admin** (contexto com `manage_options`, ou delete direto via `$wpdb` controlado). 🔧

## §11 Reconciliação & terminais
- **11.1** `charged_back` colapsa em `REFUNDED`. 🔧 status próprio (disputa/`CHARGED_BACK`) seria melhor p/ o admin, mas REFUNDED é **aceitável** (ambos = dinheiro devolvido). ⚖️
- **11.2** 🐞 `amount mismatch` → linha fica `CREATED` **em silêncio**, sem alerta → o operador **não descobre**. 🔧 adicionar **nota/alerta**. Tolerância `0.01` ok p/ arredondamento (a moeda deve bater via `amount_code`). P1.
- **11.3** 🐞 **ARESTA P0 (seu exemplo).** Preapproval **CANCELLED** + cobrança aprovada depois → o recorder `maybe_activate` **reativa** (não checa terminal) **e registra o pagamento**. **Provavelmente NÃO desejado.** 🔧 guard: se terminal (CANCELLED/EXPIRED), **não reativar**; ⚖️ decidir se **registra** o pagamento (real) ou **ignora/estorna**.
- **11.4** 🐞 fallback `BRL` mascara moeda real em multi-moeda. 🔧 usar a moeda da **subscription/plano** em vez de `BRL` fixo quando o MP não devolver `currency_id`. P2.

## §12 Refund
- **12.1** Parcial é suportado, **mas** a linha vira `REFUNDED` **inteira** (não há coluna de "valor estornado"); não cria registro novo (muda o status do mesmo Payment). 🔧 ideal: parcial → manter `COMPLETED` + marcador `PARTIALLY_REFUNDED`. ⚖️
- **12.2** 🐞 **não** soma `refunds[]` já feitos → over-refund em parciais sucessivos não é bloqueado localmente. 🔎 o **MP rejeita** over-refund → a checagem local seria só UX. 🔧 opcional somar `GET /v1/payments/{id}.refunds[]`.
- **12.3** Idempotency `jfbmp-refund-{payment_id}-{amount}` → valores diferentes passam (permite **múltiplos parciais**). ✅ intencional (se a régra for "1 estorno por pagamento", mudar — mas parciais são úteis).
- **12.4** 🐞 **ASSIMETRIA P1.** Admin → `set_refunded()` na assinatura. Webhook (`reconcile_refund`) → só marca o **Payment** REFUNDED, **não** toca o status da assinatura. 🔧 alinhar: estorno de cobrança de assinatura (via MP) deveria refletir na assinatura também — **ou** decidir formalmente que refund afeta só o pagamento. ⚖️
- **12.5** 🐞 `set_refunded()` muda a **assinatura** para REFUNDED, mas **não cancela a preapproval no MP** → a assinatura local fica REFUNDED enquanto **o MP continua cobrando**. 🔧 decidir: refund de cobrança **cancela** a assinatura no MP, ou só marca o pagamento? ⚖️ P1.

## §13 Pay-now & fulfillment
- **13.1** 🐞 O retorno do navegador **não** usa o mesmo CAS: `process_after()` faz **check** (`if status !== CREATED → throw "already captured"`) e depois `update`. O webhook usa CAS atômico. Se o webhook vence, o retorno **lança** e não re-dispara — **mas** há uma **janela TOCTOU** pequena (entre o check e o update do retorno) onde o evento poderia sair 2×. 🔧 usar **CAS no retorno** (UPDATE WHERE CREATED, disparar só se 1 linha afetada) **ou** UNIQUE `transaction_id`. P1.
- **13.2** ✅ Pix **inerte/documentado**: `binary_mode:true` exclui Pix na preference; `PaymentFulfillment` serve ao pay-now (aba fechada) e está pronto p/ Pix futuro. **Nenhum caminho Pix meio-ligado ativo.** Boleto: jamais.
- **13.3** 🐞 Se o form **não** salva record, o fulfillment vira **no-op silencioso** → com aba fechada, as ações **nunca** rodam. 🔧 documentar a dependência de `Save_Record::add_hidden()` / avisar no editor. ⚖️ P2.

## §14 Operação & observabilidade
- **14.1** 🐞 Logs **só** com `WP_DEBUG` → produção sem rastro. 🔧 **trilho de auditoria mínimo sempre-ligado** (topic/data_id/resultado, sem dado sensível) p/ suporte. ⚖️ P1.
- **14.2** 🐞 **Drift de versão:** código `2.0.17`, docs citam `2.0.14`. 🔧 disciplina: **bump + atualizar referências nas docs no mesmo commit**. Posso normalizar.
- **14.3** ✅ Simulador (`data.id` falso → GET 404 → 200, sem reentrega). Confirmado/intencional.

## §15 Pendências — prioridade
1. Unificar `AuthorizedPaymentNotification` no recorder → ✅ **FEITO (v2.0.17)**.
2. Fail-open × enforce → ⚖️ **decisão do dono** (§9.4).
3. Idempotência ponta-a-ponta (§4.2 + §8.1) → **P0** (dinheiro).
4. Formatação de moeda → ✅ **FEITO (v2.0.17, Loader)**.
5. Single Payment Amount + export → **P2** (cosmético; Amount cru de propósito por causa do input de refund).
6. Último `api.stripe.com` ativo → ✅ **não há** (só comentário); `checkout-session.php` morto → **P2 remover**.
7. Persistir `payment_id` em `Payment_Meta` → **P2** (otimização do refund pay-now).

---

## 🎯 PLANO DE CONSOLIDAÇÃO sugerido (P0 → P2)

**P0 — onde o dinheiro circula** → ✅ **TODOS FEITOS (v2.0.18)**:
- ✅ **Dedup atômica da cobrança de assinatura** (§4.2/§7.1/§8.1/§8.3): **lock nomeado** por `transaction_id` no recorder (helper `Locks`, degradação segura); convergência confirmada (ambos os tópicos usam o id do pagamento real). *(UNIQUE no `transaction_id` do core fica como hardening opcional futuro — é schema do CORE.)*
- ✅ **Não reativar assinatura terminal** (§5.2/§11.3): `SubscriptionStatusGuard::is_terminal()` no `record()` (recorder) e no caso `authorized` do `PreapprovalNotification`. Decisão tomada: pagamento tardio em assinatura terminal é **registrado** (verdade financeira), **sem reativar e sem evento**.
- ✅ **Trava de double-submit** (§3.2): fingerprint da submissão + lock + transient de 90s reaproveitando o `init_point`.

**P1 — consistência & operação:**
- `record()` transacional (§8.2); refund webhook→status da assinatura (§12.4/§12.5); índice/UNIQUE em `billing_id` (§10.2); reconciliador/sweeper de órfãs e perdidos (§3.3/§7.4); alerta de `amount mismatch` (§11.2); trilho de auditoria mínimo (§14.1); multi-conta (§9.8) — decidir.
- Segurança: janela de `ts` (replay, §9.2/§9.3); **decisão fail-open** (§9.4); alinhar docblock (§9.5, rápido).

**P2 — limpeza & cosmético:**
- Remover `checkout-session.php` morto + docblocks legados (§1.1); moeda no single Payment/export (§15.5); `Payment_Meta` (§15.7); fallback de moeda real (§11.4); normalizar versões nas docs (§14.2).
