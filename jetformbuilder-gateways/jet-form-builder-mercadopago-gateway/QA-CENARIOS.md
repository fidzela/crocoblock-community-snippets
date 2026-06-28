# 🧪 Q&A Manager — Cenários (WordPress + JetFormBuilder + JetEngine + Mercado Pago) — RESPONDIDO

> **O que é:** banco de cenários para estressar a aplicação inteira, **respondido**
> pela verdade do código (JFB core, lib Shared `Jet_FB_Paypal`, addon MP, addon
> Stripe de referência) + correlação com JFB/JetEngine/MP. Base de conhecimento.
>
> **Formato das respostas:** **R:** _[técnico]_ → _[prático]_ + marcador
> (✅ ok · 🐞 aresta · 🔧 melhoria · ⚖️ decisão do dono · 🔎 confirmar no MP/sandbox).
>
> **Versão:** v2.0.25. Estado e próximos passos no fim.

---

## §A — WordPress (infraestrutura)

- **A1.** WP-Cron depende de tráfego; com pouco acesso o reconciliador atrasa.
  **R:** O WP-Cron é "lazy" — dispara no próximo request após o horário. Em site de baixo tráfego, a run horária pode atrasar. **Prático:** aceitável, porque o reconciliador é REDE DE SEGURANÇA (o canal primário é o webhook + reentrega do MP por dias); o atraso só adia a recuperação de um caso já raro. ✅ Para sites críticos: `define('DISABLE_WP_CRON', true)` + cron real do sistema (`wp cron event run`). 🔧 detectar `DISABLE_WP_CRON` e avisar é melhoria barata.

- **A2.** Multisite: tabelas por-site ou compartilhadas? `GET_LOCK`/cron isolam por blog?
  **R:** As tabelas (`payments`/`subscriptions`/`recurring_cycles`/`payments_meta`) usam o **prefixo do `$wpdb`**, que em multisite é **por-site** (`wp_2_payments` etc.) — `Base_Db_Model::table()` resolve via `$wpdb`. Então os DADOS já isolam por site. **🐞 O `GET_LOCK` NÃO isola:** a chave `jfbmp_<md5>` é global no servidor MySQL → dois sites processando a MESMA `transaction_id` (impossível: ids do MP são únicos por conta) não colidem na prática, mas o lock é por-servidor. O cron `jfbmp_reconcile` é agendado **por-site** (cada blog tem seu WP-Cron). 🔧 prefixar o lock com `DB_NAME`/blog_id fecharia o teórico. 🔎 confirmar comportamento multisite real.

- **A3.** Object cache persistente (Redis): o transient da trava de double-submit some se o cache é limpo.
  **R:** Com object cache persistente, `set_transient` vive no Redis; um `flush` no meio da janela de 90s apaga a reivindicação. **Prático:** a brecha reabre SÓ se houver um flush DENTRO da janela de 90s E um reenvio idêntico no mesmo instante — improvável. Além disso, o `GET_LOCK` (independente do cache) ainda serializa os concorrentes. 🔧 fallback no banco (option atômica) eliminaria a dependência do cache. ⚖️ baixo risco.

- **A4.** `GET_LOCK` com MySQL em réplica/proxy/pool.
  **R:** `GET_LOCK` é **por conexão**. Um proxy (ProxySQL) que multiplexe/troque a conexão entre statements pode quebrar a semântica. **Prático:** o `Locks::acquire` **degrada seguro** — se o lock não vier, seguimos confiando no `already_processed`/CAS (a proteção de aplicação). Nunca trava. Na maioria das hospedagens (conexão direta) funciona pleno. ✅ (degradação documentada) 🔎 medir em hospedagens com proxy.

- **A5.** Atualização do WP / timezone do MySQL.
  **R:** Pontos sensíveis a tempo: `Reconciler::too_new/too_old` (`strtotime($created_at.' UTC')`) e o `set_transient` (TTL relativo, imune a TZ). Se o MySQL estiver em horário local (não UTC), o `created_at` (CURRENT_TIMESTAMP) seria local e a comparação UTC teria skew. **Prático:** não é correção — só otimização (os handlers são idempotentes); um skew faz no máximo uma chamada redundante ao MP. ✅ (com nota) 🔧 usar `current_time('timestamp', true)` consistente.

- **A6.** `max_execution_time` curto vs reconciliador.
  **R:** O cap de **25 registros/run × 1-3 chamadas MP** (~22s no pior caso) pode roçar um limite de 30s em hospedagem apertada. **Prático:** o cap protege; mas em hospedagem muito restrita poderia cortar a run no meio (sem corromper — os já processados ficam; o resto vai na próxima). 🔧 medir o tempo decorrido e parar antes do limite seria mais robusto. ✅ aceitável hoje.

- **A7.** Capabilities no cron (sem usuário).
  **R:** Verificado: `Base_Db_Model::before_insert/before_update` **não** exigem capability; só `before_delete` exige `manage_options`. Por isso o reconciliador e o `Pending_Effects` usam **só insert/update** (estado `pending`→`done`, nunca delete). ✅ Não há caminho nosso no cron/webhook que precise de `manage_options`.

- **A8.** WAF/CDN cacheando POST ou removendo headers (`x-signature`).
  **R:** Um CDN que cacheie o POST do webhook ou remova headers customizados quebra a validação (`x-signature` ausente → `SignatureValidator` retorna false; sem segredo → fail-open processa). **Prático:** o `SignatureValidator` já **loga** quando o `x-signature` chega vazio (diagnóstico). 🔧/🔎 documentar no WEBHOOK-SETUP.md a necessidade de excluir a rota `/wp-json/jfb-mercadopago/v1/webhook` de cache/regras de WAF.

## §B — JetFormBuilder (ciclo do formulário)

- **B1.** Form sem "Save Record" → fulfillment/reexecução viram no-op.
  **R:** No cenário Subscription/pay-now nós **forçamos** `Save_Record::add_hidden()`, então SEMPRE há record para reidratar. Se o dono remover a ação Save Record manualmente, `PaymentFulfillment`/`execute_event_for_subscription` não acham record → no-op (sem efeito). **Prático:** hoje confiamos no add_hidden forçado. 🔧 avisar no editor "este gateway exige Save Record" seria cinto-e-suspensório. ✅ coberto pelo add_hidden.

- **B2.** Reexecução de ações não-idempotentes (duplica e-mail/post).
  **R:** O `Gateway_Success_Event` re-executado roda TODAS as ações. Ações como "Send Email"/"Insert Post" **não são idempotentes** → re-rodar duplicaria. **Por isso** a reexecução de `Pending_Effects` é **manual/consciente** (hook `rerun-effects`), nunca automática. **Prático:** o dono decide reexecutar sabendo do risco. 🐞 estrutural do JFB (não nosso) — ações não carregam marca de "já executei". ⚖️

- **B3.** Macros (`%gateway_amount%`) na reexecução fora da submissão.
  **R:** `PaymentFulfillment::restore_context` reidrata: usuário, form_id, valores do record (`Tools::apply_context`), e **as opções de gateway** (`Gateway_Manager::set_gateways_options_by_form_id`) — exatamente para os macros de gateway resolverem. **Prático:** macros de campo e de gateway resolvem; um macro que dependa de estado de request volátil (não salvo no record) poderia faltar. ✅ (com ressalva rara) — foi a origem dos "Empty row" transitórios que sumiram no resubmit.

- **B4.** Fingerprint de double-submit com uploads / valores aleatórios.
  **R:** O fingerprint = `md5(form_id + user_id + json(request_data sem chaves voláteis))`. Uploads entram como referências no `request_data` (mesmo arquivo → mesma referência → mesmo hash, ok). **🐞 Um campo com valor ALEATÓRIO gerado no front a cada render** (token, timestamp) faria dois cliques terem hashes diferentes → a trava não pegaria. **Prático:** o `GET_LOCK` ainda serializa concorrentes; e a maioria dos forms não tem campo aleatório. 🔧 permitir o dono excluir campos do fingerprint via filtro.

- **B5.** Multi-step / conditional: valor/plano muda entre steps.
  **R:** O cenário lê o campo (`plan_field`/`price_field`) **no submit final** (`get_from_field_or_manual` lê `request_data` no momento do `after_actions`). **Prático:** o que vale é o valor enviado no submit — conditional blocks que alteram o campo antes do submit são respeitados. ✅

- **B6.** Trocar a moeda do gateway com pagamentos antigos naquela moeda.
  **R:** Cada `Payment_Model` guarda seu próprio `amount_code` (moeda) no momento da cobrança; trocar a moeda do gateway **não** reescreve o histórico. **Prático:** o histórico fica consistente (cada linha sabe sua moeda); a coluna "Gross" formata por `amount_code` da linha. ✅

- **B7.** Dois gateways no mesmo form.
  **R:** O `Gateway_Manager` do JFB resolve **um** gateway por submit (o configurado na ação Gateway). Nosso webhook só age em pagamentos cujo `external_reference`/`gateway_id` é 'mercadopago'. **Prático:** MP e outro gateway coexistem (tabelas do CORE com `gateway_id`). ✅

- **B8.** Editor é bundle Vue compilado; atualização do JFB pode quebrar hooks.
  **R:** A aba de planos registra via `jet.fb.register.settings-page.tabs` e usa componentes `cx-vui-*`. Se o JFB **renomear** esse filtro ou os componentes numa major, a aba quebraria. **Prático:** é o acoplamento inevitável de estender um SPA de terceiro; mitigado por usar os pontos PÚBLICOS deles (filtro + componentes) e não internos. 🔎 monitorar mudanças do SPA do JFB em updates major.

## §C — JetEngine (leitura/relations) — ⚠️ corrige uma afirmação anterior

- **C1/C2.** JetEngine lê `payments`/`subscriptions` via relations do CORE? A formatação `Money` vale no listing?
  **R (verificado por varredura):** **NÃO há integração turnkey.** O JFB core **não registra** Query Builder source, relation, CCT nem listing source para as tabelas de gateway. Para listar pagamentos/assinaturas no JetEngine, o dono precisa de uma **Query Builder CUSTOM (SQL)**. **🐞 E o `Money` NÃO se aplica fora das nossas colunas de admin** (`GrossColumn`/`BillingCycleColumn`): um listing do JetEngine lendo `amount_value` mostra o **valor CRU `1000.00`** (ponto, sem símbolo/vírgula). **Prático/correção honesta:** quando eu disse antes "o JetEngine lê pelas relations do CORE", isso vale para o MODELO DE DADOS (estão em tabelas do CORE, legíveis), **mas não há uma ponte pronta** — é leitura manual, e a formatação por moeda fica de fora. 🔧 expor um helper/Query source + um filtro de formatação para o JetEngine seria a evolução para "100% integrado" na CAMADA DE LEITURA.

- **C3.** Relations JetEngine entre um CPT do dono e a assinatura/pagamento.
  **R:** Teriam de ser montadas manualmente pelo dono (ligando por `user_id` ou pelo Form Record). Com `user_id=0` (convidado), a ligação por usuário não existe → liga-se pelo Form Record (`subscriptions_to_records`/`payments_to_records`). **Prático:** convidado é rastreável pelo record, não pelo usuário. ⚖️/🔧

- **C4.** JetEngine disparando ações ao mudar status: enxerga nossos eventos?
  **R:** Nossos eventos (`SubscriptionCancelEvent` etc.) são eventos do **JFB** (rodam ações do FORM). O JetEngine não "escuta" esses eventos; ele leria o **status no banco**. **Prático:** para o JetEngine reagir, o dono observaria a mudança via cron/hook próprio sobre a tabela. 🔧

- **C5.** Dynamic Visibility "assinatura ativa": consulta em tempo real.
  **R:** Consultaria o status no banco — que pode estar **atrás do MP** até o webhook/reconciliador rodar. **Prático:** janela de atraso = tempo do webhook (segundos) ou, no pior caso, do reconciliador (até 1h). Para acesso imediato pós-pagamento, confiar no `Gateway_Success_Event` (que roda na cobrança) é mais seguro que ler o status. ⚖️

## §D — Mercado Pago (API, webhooks) — 🔎 confirmar em doc/sandbox

- **D1.** `authorized_payment.payment.id` == `payment.id` do tópico `payment`?
  **R:** Nosso código JÁ usa o **id do pagamento real** nos dois caminhos (`payment['id']` e `ap['payment']['id']`) como `transaction_id`, então a dedup converge **se** os ids forem o mesmo recurso — que é o esperado no modelo do MP (o authorized_payment referencia o payment gerado). 🔎 **confirmar em sandbox** (é a suposição de maior alavancagem da idempotência de assinatura).

- **D2.** O MP regenera o `ts` da `x-signature` por retry?
  **R:** Desconhecido sem teste. **Por isso adiamos a janela de replay** — uma janela rígida descartaria retries legítimos se o `ts` for reusado. 🔎 confirmar; se regenera, uma janela de ±5min fecha o replay com segurança.

- **D3.** Teto total de retry do MP.
  **R (doc):** 15min→30min→6h→48h→96h→96h… por **vários dias**; a doc não fixa um teto explícito. **Prático:** o reconciliador cobre o que esgotar essa janela. 🔎 confirmar o número final de tentativas.

- **D4.** Cobrança de assinatura: `payment` E/OU `subscription_authorized_payment`?
  **R:** No painel do dono chegou só `payment` (confirmado em teste). O recorder é **ponto único** para os dois, idempotente por `transaction_id`. 🔎 confirmar em quais configs cada um chega e se os ids podem divergir (liga em D1).

- **D5.** Idempotency-Key do MP em `/preapproval` fecharia o double-submit na fonte?
  **R:** Hoje a idempotência do MP é por `external_reference` (distinto por linha local), então não funde dois submits. **Se** `/preapproval` aceitar `X-Idempotency-Key`, uma chave derivada do fingerprint fundiria — mas criaria 2 linhas locais com o MESMO billing_id (mexe em §10.2). 🔎 avaliar; a trava local já resolve.

- **D6.** Over-refund parcial: o MP soma os `refunds[]`?
  **R:** Esperado que o MP rejeite over-refund. Nossa idempotency-key `jfbmp-refund-{id}-{amount}` permite parciais distintos. 🔎 confirmar; checagem local de soma seria só UX.

- **D7.** Plano em CLP/MXN/COP de conta BR.
  **R:** O MP valida a moeda contra o país/conta — criar plano em moeda estrangeira da conta tende a falhar (foi o que o dono observou). **Prático:** a moeda deve casar com a conta; outras moedas funcionam para contas DAQUELE país. ✅/🔎

- **D8.** `binary_mode:true` exclui Pix/boleto?
  **R:** No nosso código `binary_mode:true` está na preference (paridade com a base) e a fase Pix está documentada/inerte. 🔎 confirmar exatamente o efeito do binary_mode sobre meios assíncronos.

- **D9/D10.** Status novos (`in_process`/`in_mediation`) e tópicos extras (`merchant_order`/`chargebacks`).
  **R:** Status fora de `approved/refunded/...` caem em **no-op seguro** (200, sem efeito). `charged_back` JÁ é tratado como refund. **Prático:** `in_mediation` (disputa) hoje vira no-op — 🔧 poderia ter status próprio. Tópicos não-tratados → 200 ignorado + log. 🔎 mapear quais merecem tratamento.

- **D11.** Webhooks v2 vs IPN — apelidos cobrem variações regionais?
  **R:** O `Dispatcher` aceita ambos (`subscription_preapproval|preapproval`, `subscription_authorized_payment|authorized_payment`). **Prático:** cobre os dois esquemas que o MP usa. 🔎 validar se há variação regional de nomenclatura não coberta.

## §E — Concorrência & integridade do dinheiro

- **E1.** TOCTOU no retorno do navegador (pay-now).
  **R:** O retorno usa check-then-update; o webhook usa CAS atômico (`UPDATE WHERE status='CREATED'`). Há uma janela pequena no retorno onde o evento poderia sair 2×. **Prático:** o webhook (vencedor do CAS) garante exactly-once no caminho dominante; o retorno só dispara se vencer a transição. 🔧 CAS também no retorno fecharia 100%. 🐞 conhecido, baixo impacto.

- **E2.** `record()` transacional só no par crítico.
  **R (coberto v2.0.19):** Payment+vínculo entram em transação; `attach_payer`/`link_payment_to_payer` ficam best-effort fora. Falha no enriquecimento deixa o pagamento sem pagador vinculado — **o reconciliador NÃO conserta isso** (ele reprocessa status/cobrança, não re-vincula pagador). 🔧 um "re-attach payer" no reconciliador seria a evolução. ✅ o crítico (dinheiro) é atômico.

- **E3.** `billing_id` sem índice/UNIQUE.
  **R:** Sem UNIQUE, nada no banco impede 2 subs com o mesmo billing_id; a trava de double-submit + a criação 1:1 (1 preapproval por submit) protegem na aplicação. 🔧 índice melhora a busca dos webhooks; UNIQUE seria cinto-e-suspensório. ⚖️ (schema da lib Shared).

- **E4.** `GET_LOCK` indisponível: fallback?
  **R:** Degrada para "sem lock" (segue no `already_processed`/CAS). 🔎 medir em quantas hospedagens falta; 🔧 `add_option` atômico como fallback universal.

- **E5.** Double-submit por duas abas/navegadores, dados idênticos, < 90s.
  **R:** A trava colapsa (mesmo fingerprint) → reusa o redirect. **Prático:** dois cliques idênticos do MESMO usuário em 90s são quase sempre acidente; é o comportamento desejado. ⚖️ se forem 2 intenções legítimas (raríssimo), o 2º reusaria o 1º checkout.

- **E6.** Refund concorrente (admin + webhook).
  **R:** Ambos chamam `Subscription_Refund_Closer`, **idempotente** (guard terminal). O cancel no MP (`Update_Preapproval`) pode rodar 2× — 🔎 confirmar que o MP é idempotente ao cancelar uma preapproval já cancelada (provável no-op/erro tratado). ✅ no lado local.

## §F — Observabilidade & suporte

- **F1.** Auditoria vai pro `error_log`; hospedagem sem acesso ao log.
  **R:** `WebhookConfig::audit` usa `error_log` (sempre-ligado) + é **filtrável**. Sem acesso ao log, o dono não vê. 🔧 persistir num CPT/tabela consultável no admin (ou enviar a um logger via o filtro `webhook-audit`) é a evolução natural de observabilidade. ⚖️

- **F2.** Identificador único impresso ao cliente.
  **R:** Temos `external_reference` (`jfbmp-sub-<id>` / pay-now) + `transaction_id` (payment id do MP). Hoje não os imprimimos numa mensagem ao cliente. 🔧 expor via macro para o e-mail de confirmação liga a jornada ponta-a-ponta para o suporte.

- **F3.** "Efeitos pendentes" sem coluna no admin.
  **R (coberto parcial v2.0.23):** a flag está em `payments_meta` (consultável) + auditada + contada no log horário do reconciliador. 🔧 falta uma COLUNA/aviso na tabela de Payments para visão sem ler log. Melhoria de UX.

- **F4.** Timeline completa de um pagamento.
  **R:** Temos `SubscriptionNoteModel` (notas de status) + auditoria por webhook + Form Record. Não há event-store por pagamento. **Prático:** dá para reconstruir o essencial; um event-store completo é provavelmente excesso para o escopo. ⚖️

- **F5.** Métricas (webhooks/h, reconciliados, pendentes).
  **R:** O log do reconciliador já emite `subscriptions_checked/paynow_checked/effects_pending` por run. 🔧 um painel exigiria persistir; o log basta para suporte reativo. ⚖️

## §G — Segurança

- **G1.** Fail-open por padrão — enforce no release público?
  **R:** Decisão do dono. Recomendação: manter fail-open + **documentar forte** o `JFB_MP_WEBHOOK_SECRET` (menos atrito out-of-box; cada handler re-verifica via GET autenticado). Para um release público amplo, enforce-by-default com gate de setup é mais seguro. ⚖️ ver SEGURANCA-REVERSAO.md.

- **G2.** Sem janela `ts`/nonce → DoS leve de consultas.
  **R:** Um atacante com um `data.id` válido forçaria N GETs de recursos da PRÓPRIA conta (o token é nosso) — sem injetar estado. **Prático:** mitigado por idempotência; o custo é GETs ao MP. 🔧 rate-limit por IP/`data.id` + janela de `ts` (quando D2 confirmar). ⚖️

- **G3.** Vazamento do Access Token.
  **R (verificado):** o token nunca vai ao cliente — a aba passa só `hasToken` (bool); CRUD usa a chave server-side; o diagnóstico inline com prefixo do token é **gated por `WP_DEBUG`**. 🔧 um teste automatizado que falhe se um campo sensível entrar no `audit`/resposta seria a garantia contínua (liga G5).

- **G4.** Hook `rerun-effects` exposto a qualquer código do site.
  **R:** É um `do_action` interno — qualquer plugin/snippet pode disparar. **Prático:** quem já roda código no site tem acesso de qualquer forma; não é vetor externo. 🔧 se virar REST endpoint, exigir `manage_options` + nonce.

- **G5.** Garantir que o audit nunca registra dado sensível.
  **R:** Hoje é por disciplina (os call sites passam só topic/ids/valores). 🔧 um teste que varre as chamadas de `audit()` e falha em chaves proibidas (token/secret/card) tornaria a garantia automática. ⚖️ boa prática.

## §H — Recuperação & ciclo de vida

- **H1.** Restaurar backup antigo: status sim, mas e os PAGAMENTOS perdidos no intervalo?
  **R (coberto parcial v2.0.21):** o reconciliador reativa o STATUS (via `PreapprovalNotification`) e recupera a cobrança **aprovada mais recente** por `external_reference` (`Search_Payments`). **🐞 Renovações intermediárias perdidas no intervalo NÃO são todas reconstruídas** (só a mais recente). **Prático:** o caso comum (status + cobrança inicial) é coberto; backfill de N renovações exigiria iterar `payment/search` paginado. 🔧 evolução.

- **H2.** Reconciliador newest-first + LIMIT 200: backlog enorme não alcança os antigos-dentro-da-janela.
  **R (conhecido):** ordem id DESC + limite 200 → se os 200 do topo não resolverem, os mais antigos não são lidos. **Prático:** abandonados não precisam reconciliar; o pago-mas-perdido recente está no topo. 🔧 ordenar oldest-first para drenar de fato. Trade-off documentado.

- **H3.** Pausa meses + reativa: lacuna visível.
  **R:** Mesma assinatura (mesmo `billing_id`); os meses sem cobrança aparecem como ausência de Payments naquele período. **Prático:** a lacuna é fiel à realidade (não houve cobrança). ✅

- **H4.** Cancela e reassina o mesmo plano: agrupamento por cliente?
  **R:** Vira NOVA assinatura (novo `billing_id`). Hoje não há agrupamento "mesmo cliente, nova assinatura" no admin além do `user_id`/e-mail do pagador. 🔧 uma visão por pagador seria melhoria.

- **H5.** Plano excluído com assinaturas vivas: admin exibe?
  **R:** Sim — assinaturas são independentes do plano (termos inline na preapproval; ciclo local em `recurring_cycles`). "Excluir" plano = `status=cancelled` no MP (não apaga). ✅

## §I — Compatibilidade & evolução

- **I1.** Multi-gateway: `gateway_id='mercadopago'` fixo.
  **R:** O `SubscriptionPaymentRecorder` fixa o `gateway_id`; os `WebhookEvents` são MP por natureza; `Money::is_mercadopago` já é gateway-safe. **Prático:** já COEXISTE com outros gateways (tabelas do CORE com `gateway_id`). Parametrizar exigiria tornar o recorder agnóstico — mas como cada gateway tem seu addon, não é necessário. ✅

- **I2.** Multi-conta MP.
  **R (decisão do dono):** **1 conta MP por site** por ora. O webhook usa token global (`WebhookConfig::access_token`). Para multi-conta, o webhook precisaria descobrir a conta (ex.: sufixo no `external_reference` ou metadata) e resolver o token por recurso. ⚖️ fora de escopo atual.

- **I3.** Migração de schema do CORE.
  **R:** Lemos colunas por nome (`amount_value`, `status`, `billing_id`…). Uma renomeação numa major do JFB quebraria silenciosamente. **Prático:** mitigado por NÃO termos tabelas próprias (zero dívida de migração nossa); dependemos do schema do CORE. 🔧 um teste de fumaça que leia uma linha de cada tabela pegaria a quebra cedo.

- **I4.** Atualização do plugin durante uma cobrança.
  **R:** O guard `is_readable(vendor/autoload.php)` protege o boot durante a re-extração. Um webhook que chegue EXATAMENTE no instante da troca recebe um boot abortado (sem fatal) → não-200 implícito → **o MP reentrega**. ✅ coberto pela reentrega.

- **I5.** Downgrade para versão sem `payments_meta`/reconciliador.
  **R:** A tabela `payments_meta` e o cron `jfbmp_reconcile` ficariam órfãos (a tabela é do CORE, persiste; o cron sem handler vira no-op e expira). 🔧 uma rotina de uninstall limparia o agendamento. ⚖️ impacto baixo.

## §J — Experiência & negócio

- **J1.** Fecha a aba após autorizar.
  **R:** Estado é webhook-driven; ao voltar vê o estado real. **Prático:** a tela de retorno mostra a mensagem; o `PaymentFulfillment` roda as ações mesmo com aba fechada (pay-now). 🔧 a mensagem de retorno poderia ser mais explícita ("confirmando seu pagamento"). ✅

- **J2.** Várias tentativas: distinguir tentativa de concluído; órfãs poluem.
  **R:** Status por linha (CREATED/APPROVAL_PENDING vs COMPLETED) distingue. **🐞 Pay-now CREATED abandonado acumula** (nada limpa). O reconciliador ignora os > 7 dias, mas não os apaga. 🔧 um sweeper de CREATED/APPROVAL_PENDING antigos (admin, com capability) limparia o relatório.

- **J3.** Refund parcial encerra a assinatura?
  **R (decisão atual):** "estorno encerra" trata QUALQUER refund de cobrança de assinatura como encerramento (cancela no MP + CANCELLED). **Para um PARCIAL isso pode ser agressivo.** ⚖️ refinar: parcial NÃO encerra; só total encerra. Decisão do dono.

- **J4.** Cliente pagou mas não recebeu o acesso (efeito pendente).
  **R:** A flag `Pending_Effects` identifica; a reexecução é manual (hook). **Prático:** falta um caminho UX para o dono ver e clicar "reprocessar". 🔧 liga em F3 (coluna no admin + botão).

- **J5.** Impostos/recibo/nota.
  **R:** O MP emite o comprovante do lado dele. Não espelhamos dado fiscal localmente. 🔎/⚖️ se houver requisito fiscal, definir o que persistir (ex.: id do comprovante MP em `payments_meta`).

## §K — Filosofia & contrato

- **K1.** Fonte da verdade documentada?
  **R:** Sim — comentários + `BASE-CONHECIMENTO.md` + `QA-RESPOSTAS.md`: **MP = verdade do ESTADO** (sempre GET; nunca confiar no corpo do webhook); **DB local = registro durável** (histórico, vínculos, idempotência). Em divergência de estado, MP vence. ✅

- **K2.** Invariantes que nunca podem mudar.
  **R:** (1) Access Token server-side; (2) nunca confiar no corpo do webhook sem GET; (3) idempotência por `transaction_id` (+lock/CAS); (4) gravar nas tabelas do CORE (nunca modelo paralelo); (5) exactly-once via transição atômica; (6) cobrança de assinatura pode chegar como `payment`. ✅ completo.

- **K3.** Detalhe de implementação vs regra de negócio.
  **R:** Substituíveis (implementação): `GET_LOCK` vs UNIQUE, o fingerprint do double-submit, transient de 90s, `error_log` vs logger, nomes de classe, WP-Cron vs Action Scheduler. Fixas (negócio): os 6 invariantes de K2. ✅ fronteira clara.

---

## 📌 Estado atual do projeto (pós-respostas, v2.0.25)

**Maduro / coberto:** ciclo pay-now e assinatura ponta-a-ponta; idempotência (already_processed + lock + CAS); guard de terminal; refund encerra a assinatura; `record()` transacional no par crítico; reconciliador (recuperação); re-sync de termos; flag de efeitos pendentes; auditoria sempre-ligada; aba de planos nativa (cx-vui); segurança do token; formatação por moeda no admin.

**Arestas/limitações conhecidas (não bloqueiam uso):**
- 🐞 leitura no **JetEngine** não é turnkey e **não formata moeda** (valor cru no listing) — §C.
- 🐞 backfill de renovações perdidas só pega a mais recente — §H1.
- 🐞 pay-now CREATED abandonado acumula (sem sweeper) — §J2.
- 🐞 fingerprint fura com campo aleatório — §B4.
- ⚖️ refund parcial encerra a assinatura (agressivo) — §J3.
- 🔎 **suposições do MP não confirmadas em sandbox** (D1/D2/D4 são as de maior alavancagem).

## 🎯 Próximos passos sugeridos (priorizado)

**P0 — confirmar suposições do MP (sandbox):** D1 (id convergente), D4 (qual tópico chega), D2 (`ts` por retry). São a base da idempotência e da segurança; baratas de validar, alto impacto.

**P1 — fechar arestas de produto:**
- Refund parcial NÃO encerra (só total) — §J3 (decisão + 1 ajuste).
- Sweeper de CREATED/APPROVAL_PENDING abandonado — §J2.
- Coluna "efeitos pendentes" + botão reprocessar no admin — §F3/§J4.
- Backfill completo de renovações no reconciliador — §H1.

**P2 — integração & robustez fina:**
- Ponte de leitura para o **JetEngine** (Query source + filtro de formatação de moeda) — §C.
- Fallback de lock sem `GET_LOCK` (`add_option`) — §E4; janela de `ts` (após D2) — §G2.
- Auditoria persistível (CPT/logger) — §F1; teste anti-vazamento no audit — §G5.
- Índice em `billing_id` — §E3; rotina de uninstall — §I5.
