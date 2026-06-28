# 🧪 Q&A Manager — Cenários hipotéticos (WordPress + JetFormBuilder + JetEngine + Mercado Pago)

> **O que é isto:** um banco de QUESTIONAMENTOS (não respostas) gerado no papel de
> Q&A Manager, para estressar a aplicação inteira em cenários padrão/costumeiros de
> gateways de pagamento. Serve de insumo para o próximo ciclo de consolidação —
> cada bloco vira investigação no código raiz + doc do MP, como fizemos no
> `QA-RESPOSTAS.md`.
>
> **Como usar:** responder SEMPRE pela verdade do código raiz (JFB core, lib Shared
> `Jet_FB_Paypal`, addon) + correlação com o que o JFB/JetEngine/MP aceitam. Marcar
> cada resposta como ✅ ok · 🐞 aresta · 🔧 melhoria · ⚖️ decisão do dono · 🔎 conferir no MP.
>
> **Legenda de status atual (v2.0.23):** itens já cobertos aparecem como _(coberto: …)_.
> O resto é campo aberto.

---

## §A — WordPress (infraestrutura)

- **A1.** WP-Cron depende de tráfego para disparar. Se o site tem pouco acesso, o reconciliador (hourly) atrasa horas — isso é aceitável para a janela de recuperação? Deveríamos detectar `DISABLE_WP_CRON` e avisar o admin? _(coberto parcialmente: reconciliador existe; cadência depende do WP-Cron.)_
- **A2.** Em **multisite**, as tabelas (`subscriptions`/`payments`/`recurring_cycles`/`payments_meta`) são por-site ou compartilhadas? O cron e o `GET_LOCK` isolam por blog_id? O lock `jfbmp_*` colide entre sites do mesmo MySQL?
- **A3.** Com **object cache persistente** (Redis/Memcached), o transient da trava de double-submit vive no cache — se o cache é limpo no meio, a janela de 90s some. Isso reabre a brecha? Precisamos de fallback no banco?
- **A4.** `GET_LOCK`/`RELEASE_LOCK` em hospedagens com **MySQL em réplica/proxy** (connection pooling): o lock é por-conexão — um proxy que troca a conexão entre requests quebra a serialização?
- **A5.** Atualização do **WordPress core** que mude o comportamento de `wp_schedule_event`/`current_time`: algum ponto nosso assume timezone do servidor (ex.: `strtotime($created_at.' UTC')`)? O que acontece se o MySQL estiver em horário local?
- **A6.** Limite de `max_execution_time` curto (hospedagem compartilhada): o reconciliador (até 25 registros × 1-3 chamadas MP) pode estourar? Deveríamos medir o tempo e parar antes do limite?
- **A7.** Capabilities: o cron roda sem usuário (`user_id=0`). Algum caminho que rodamos no cron/webhook chama algo que exige `manage_options` (além do `before_delete`, que evitamos)?
- **A8.** REST: nosso endpoint de webhook é público. Um WAF/CDN (Cloudflare) que faça **cache de POST** ou rewrite de headers (`x-signature`, `x-request-id`) — como detectar e orientar?

## §B — JetFormBuilder (ciclo do formulário)

- **B1.** Se o form **não** tem a ação "Save Record", o pay-now (`PaymentFulfillment`) e a re-execução de assinatura viram no-op (sem record para reidratar). O editor deveria AVISAR que o gateway exige Save Record?
- **B2.** O `Gateway_Success_Event` re-executado fora da submissão roda TODAS as ações do form. Ações não-idempotentes (criar post, enviar e-mail) duplicam em reexecução. Quais ações do JFB são seguras de re-rodar e quais não?
- **B3.** Macros/dados dinâmicos (`%gateway_amount%`, campos do form) na reexecução: o `Tools::apply_context` restaura tudo que a ação precisa? Algum macro depende de estado de request que não é re-hidratado?
- **B4.** O `request_data` usado no fingerprint de double-submit inclui uploads/arquivos? Dois envios com o mesmo arquivo geram o mesmo hash? E campos com valores aleatórios (ex.: um token gerado no front) furam o fingerprint?
- **B5.** Conditional blocks / multi-step forms: o valor/plano pode mudar entre steps. O cenário lê o campo no momento certo do submit?
- **B6.** Se o admin muda a **moeda do gateway** no form depois de já haver assinaturas/pagamentos naquela moeda, o histórico fica inconsistente? A coluna `amount_code` por pagamento protege isso?
- **B7.** Dois gateways no MESMO form (ex.: MP + outro): o `Gateway_Manager` escolhe um por submit? O webhook do MP afeta só o pagamento do MP?
- **B8.** O editor é um bundle Vue compilado; nossas configs entram por constante/filtro. Se o JFB recompilar o editor numa atualização, algum hook nosso (`register.settings-page.tabs`) quebra?

## §C — JetEngine (leitura/relations)

- **C1.** O JetEngine lê `payments`/`subscriptions` via relations do CORE. Se o dono cria uma **Query Builder** sobre essas tabelas e a estrutura mudar numa atualização do JFB, a query quebra silenciosamente?
- **C2.** Listings do JetEngine que exibem valor de pagamento: pegam o `amount_value` cru (formato US) ou passam pela nossa `Money`? A formatação por moeda vale só nas colunas do admin (Gross/BillingCycle) — e fora delas (JetEngine), o valor sai "1,000.00"?
- **C3.** Relations JetEngine entre um CPT do dono e a assinatura/pagamento: como são ligadas? Pelo `user_id`? Pelo Form Record? O que acontece se o `user_id` for 0 (convidado)?
- **C4.** Se o dono usa JetEngine para DISPARAR ações ao mudar status de assinatura, ele "enxerga" os nossos eventos (`SubscriptionCancelEvent` etc.) ou só o status no banco?
- **C5.** Dynamic Visibility / Profile Builder do JetEngine condicionando conteúdo a "assinatura ativa": como ele consulta o status? Em tempo real (banco) — e o banco pode estar atrás do MP até o webhook/reconciliador rodar.

## §D — Mercado Pago (API, webhooks, idempotência)

- **D1.** 🔎 `authorized_payment.payment.id` == `payment.id` do tópico `payment`? (a dedup por `transaction_id` depende disso). Confirmar na doc/sandbox.
- **D2.** 🔎 O MP regenera o `ts` da `x-signature` a CADA retry, ou reusa o original? (decide se uma janela de replay é segura — hoje adiada).
- **D3.** 🔎 A política de retry do MP (15min→30min→6h→48h→96h…) tem teto total? Depois de quantos dias o MP desiste? (define a janela que o reconciliador precisa cobrir).
- **D4.** 🔎 Em **assinatura**, o MP entrega a cobrança como `payment` (confirmado) E/OU `subscription_authorized_payment`? Em quais configurações cada um? Os dois podem chegar com `payment.id` divergente?
- **D5.** 🔎 Idempotency-Key do MP em `/preapproval`: é suportada? Se sim, usá-la fecharia o double-submit na fonte (sem trava local)? Como compõe com nosso external_reference por-linha?
- **D6.** 🔎 Estorno parcial sucessivo: o MP soma os `refunds[]` e bloqueia over-refund? Nossa idempotency-key `jfbmp-refund-{id}-{amount}` permite múltiplos parciais — alinhado ao MP?
- **D7.** 🔎 Moedas: criar plano em CLP/MXN/COP de uma conta BR — o MP rejeita por moeda da conta? O `auto_recurring.currency_id` precisa casar com o país da conta?
- **D8.** 🔎 `binary_mode:true` na preference exclui Pix/boleto? Confirmar que não há caminho assíncrono meio-ligado.
- **D9.** 🔎 Novos status de pagamento do MP (`in_process`, `in_mediation`, `authorized` sem capture): hoje caem em no-op. Algum deles exige tratamento (ex.: `in_mediation` = disputa)?
- **D10.** 🔎 `merchant_order` / `chargebacks` / `point_integration_wh`: chegam na nossa URL? Hoje viram 200 ignorado. Disputa precisa de status próprio?
- **D11.** 🔎 Diferença entre Webhooks v2 (painel) e IPN (`notification_url`): os apelidos (`preapproval`/`authorized_payment`) cobrem todas as variações regionais?

## §E — Concorrência & integridade do dinheiro

- **E1.** Retorno do navegador (pay-now) usa check-then-update; o webhook usa CAS. Há uma janela TOCTOU pequena no retorno onde o evento poderia sair 2×. Fechar com CAS no retorno também? _(conhecido)_
- **E2.** O `record()` é transacional só no par Payment+vínculo; `attach_payer`/`link_payment_to_payer` ficam fora. Uma falha lá deixa o pagamento sem pagador vinculado — o reconciliador conserta? Precisa? _(coberto: transação no par crítico; enriquecimento best-effort.)_
- **E3.** `billing_id` (preapproval) não tem índice nem UNIQUE. Dois caminhos criando a mesma sub (improvável, mas…) — o que protege? _(conhecido: §10.2.)_
- **E4.** O lock `GET_LOCK` degrada para "sem lock" se o host não suportar. Em qual % de hospedagens isso acontece? Deveríamos ter um fallback (ex.: `add_option` atômico)?
- **E5.** Double-submit por **dois navegadores/abas** do mesmo usuário com dados idênticos em < 90s: a trava colapsa — é o comportamento desejado, ou são duas intenções legítimas?
- **E6.** Refund concorrente: admin clica Refund enquanto o webhook de refund chega. Os dois chamam `Subscription_Refund_Closer` — a idempotência (terminal) cobre, mas o cancel no MP roda 2×? O MP é idempotente nisso?

## §F — Observabilidade & suporte

- **F1.** A auditoria (`[JFB MercadoPago Audit]`) vai pro `error_log`. Em hospedagem sem acesso ao log, como o dono vê? Deveríamos persistir num CPT/tabela consultável no admin?
- **F2.** Não há um identificador único impresso para o cliente final (ex.: no e-mail de confirmação) que ligue a jornada toda. Adicionar o `external_reference`/`payment.id` na mensagem ajudaria o suporte?
- **F3.** "Efeitos pendentes" hoje é flag em `payments_meta` + audit. Falta uma COLUNA/aviso no admin de Payments para o dono ver sem ler log. Vale uma coluna?
- **F4.** Timeline de um pagamento: temos notas de status da assinatura + audit + Form Record. Falta um event-store por pagamento. Quanto disso é necessário vs. excesso?
- **F5.** Métricas: quantos webhooks/hora, quantos reconciliados, quantos efeitos pendentes — exportar para um painel? Ou o log basta?

## §G — Segurança

- **G1.** Fail-open do webhook sem segredo é a decisão atual. Para o RELEASE público, deveria ser enforce-by-default com um gate de setup? ⚖️
- **G2.** Sem janela de `ts` (replay) e sem nonce (`x-request-id` não armazenado). A idempotência+GET mitiga, mas um atacante pode forçar N consultas de recursos da própria conta (DoS leve). Limitar? ⚖️/🔎
- **G3.** O Access Token nunca vai ao cliente (confirmado). Há QUALQUER caminho (editor, REST, erro com `WP_DEBUG`) onde ele possa vazar? Auditar todos os pontos que leem `secret`.
- **G4.** O endpoint `rerun-effects` é um hook (qualquer código no site pode disparar). Se exposto via REST no futuro, precisa de `manage_options` + nonce.
- **G5.** Logs de auditoria: garantimos que NUNCA registram token/assinatura/cartão. Há revisão automatizada disso (um teste que falha se um campo sensível entrar no audit)?

## §H — Recuperação & ciclo de vida

- **H1.** Restaurar backup antigo do WP: o reconciliador reativa as assinaturas que o MP diz ativas — mas e os PAGAMENTOS individuais perdidos entre o backup e agora? São recuperados? Como? _(coberto parcialmente: status sim; cobranças via Search_Payments — só a aprovada mais recente.)_
- **H2.** Reconciliador com newest-first + LIMIT 200: num backlog enorme (> 200 recentes), os mais antigos-dentro-da-janela podem não ser alcançados se os 200 do topo não resolverem. Ordenar oldest-first para drenar? _(conhecido: trade-off documentado.)_
- **H3.** Assinatura pausada meses e reativada: o histórico de cobranças continua na mesma linha (mesmo `billing_id`). Os meses sem cobrança ficam visíveis como lacuna? Precisa?
- **H4.** Cancelar e reassinar o mesmo plano: vira NOVA assinatura (novo `billing_id`). O dono consegue ver no admin que é o "mesmo cliente, nova assinatura"? Falta um agrupamento por usuário/e-mail?
- **H5.** Plano excluído (cancelado no MP) com assinaturas vivas: as cobranças seguem (independentes do plano). O admin consegue exibir essas assinaturas mesmo sem o plano existir? _(coberto: sim, são independentes.)_

## §I — Compatibilidade & evolução

- **I1.** Multi-gateway simultâneo: `SubscriptionPaymentRecorder` fixa `gateway_id='mercadopago'`. Parametrizar exigiria o quê? Quais outras classes assumem MP implicitamente?
- **I2.** Multi-conta MP: hoje token global no webhook (1 conta/site, por decisão). Se virar requisito, como o webhook descobre a conta? (external_reference carregando um sufixo de conta? metadata?)
- **I3.** Migração de schema do CORE (JFB muda `payments`/`subscriptions`): nosso código lê colunas por nome — quais quebrariam? Há um teste de fumaça que pegaria?
- **I4.** Atualização do plugin durante uma cobrança em andamento (vendor re-extraído): o guard `is_readable` cobre o boot; e uma requisição de webhook que chega EXATAMENTE durante a troca?
- **I5.** Downgrade do plugin para uma versão anterior (sem `payments_meta`/reconciliador): os dados novos (flags, cron) ficam órfãos? Precisa de rotina de limpeza?

## §J — Experiência & negócio

- **J1.** Cliente fecha a aba após autorizar: o estado é webhook-driven. A mensagem de retorno explica claramente "estamos confirmando seu pagamento"? Ou parece erro?
- **J2.** Várias tentativas de pagamento do mesmo cliente: o admin distingue tentativa (CREATED/APPROVAL_PENDING) de concluído (COMPLETED)? As órfãs poluem o relatório — limpeza/sweeper de CREATED abandonado vale?
- **J3.** Reembolso parcial: hoje a linha vira REFUNDED inteira e a assinatura é CANCELADA (decisão "estorno encerra"). Para um parcial isso é correto, ou parcial deveria NÃO encerrar? ⚖️
- **J4.** Comunicação ao cliente em falha de efeito (efeitos pendentes): o cliente pagou mas não recebeu o acesso/e-mail. Há um caminho para notificar/reprocessar com segurança?
- **J5.** Impostos/recibo/nota: o MP emite? Precisamos espelhar algum dado fiscal localmente?

## §K — Filosofia & contrato

- **K1.** Fonte da verdade: MP (estado, via GET) vs. DB local (registro durável). Está documentado o suficiente para um dev novo saber QUANDO confiar em cada um?
- **K2.** Invariantes que NUNCA podem mudar: token server-side; nunca confiar no corpo do webhook sem GET; idempotência por `transaction_id`; gravar no CORE (não criar modelo paralelo); exactly-once via transição atômica. Falta algum?
- **K3.** O que é detalhe de implementação (substituível) vs. regra de negócio (fixa)? Ex.: `GET_LOCK` vs UNIQUE, fingerprint de double-submit, transient de 90s, `error_log` vs logger — todos substituíveis. Confirmar a fronteira.

---

### Próximo passo sugerido
Rodar este Q&A como o `QA-RESPOSTAS.md`: responder cada item pela verdade do código + doc do MP, marcar (✅/🐞/🔧/⚖️/🔎) e extrair um P0/P1/P2. Os 🔎 do bloco §D (Mercado Pago) são os de maior alavancagem — confirmam suposições que sustentam a idempotência e a segurança.
