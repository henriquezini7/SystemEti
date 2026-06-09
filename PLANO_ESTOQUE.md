# SystemETI — Plano do Módulo de ESTOQUE (controle 100%)

## 🎯 Objetivo
Saber, a qualquer momento, **quanto tem de cada produto em cada depósito**. O estoque
**entra** (estoque inicial + reposições, com dia/data/hora) e **sai automaticamente**
quando as etiquetas/pedidos são despachados — descontando do depósito.

## 🧠 Conceito (com o exemplo do usuário)
```
Depósito X  ── saldo por produto ──►  Perfume Yara 100ml: 50 un, Asad Elixir: 20 un...

Lote/etiquetas processados (saída):
  Etiqueta 1  → 10 pedidos / 30 produtos  ─┐
  Etiqueta 2  →  5 pedidos /  5 produtos   ├─►  DESCONTAM do Depósito X
  Etiqueta 3  →  1 pedido  /  2 produtos  ─┘     (baixa de estoque)

Toda ENTRADA no depósito registra: dia · data · hora · usuário · nota
```

## 📦 Modelo de dados (JSON, sem banco — igual ao resto)
- **`deposits.json`** — depósitos: `{id, name, created_at}`
- **`stock.json`** — saldo atual: `{deposit_id, product_key, product_name, sku, balance, updated_at}`
- **`stock_movements.json`** — histórico de TUDO: `{id, deposit_id, product_key, type, qty, balance_after, ref, datetime, user, note}`
  - `type`: `inicial` | `entrada` | `saida` | `ajuste`
  - `datetime`: dia/data/hora (ISO) — exigência atendida
  - `ref`: de onde veio (relatório #N, bipagem, manual)
- Produto identificado pela chave **nome+SKU** (a mesma `store_product_key` que já usamos).

## 🔄 Fluxo
### Entrada de estoque (sempre com dia/data/hora)
1. **Estoque inicial** — cadastra o saldo de partida de cada produto (manual, em lote, ou importando de um relatório/planilha).
2. **Reposição** — adiciona quantidade a um produto quando chega mercadoria nova.
3. Cada entrada gera um movimento `entrada`/`inicial` com **data e hora** + usuário + nota.

### Saída de estoque (baixa automática)
- Quando uma etiqueta é **bipada como enviada** (despacho confirmado), os produtos daquela
  etiqueta **descontam** do depósito (movimento `saida`).
- *(Alternativa: descontar já na entrada do PDF — "reserva". A decidir.)*
- Reaproveita os `products` que cada etiqueta já tem registrado.

### Alertas
- Estoque **baixo** (abaixo de um mínimo) e **negativo** (vendeu mais do que tinha) — sinal de furo/erro.

## 📊 Relatórios (controle 100%)
1. **Saldo atual** por depósito e por produto (com busca).
2. **Entradas de produtos** por período (dia/semana/mês), com dia/data/hora — o que você pediu.
3. **Movimentações** por produto: entrou × saiu × saldo, linha do tempo.
4. **Exportar Excel** de tudo.

## 🖥️ Telas
**Sistema (painel desktop):**
- `Estoque` — saldo atual + busca + filtro por depósito + alertas.
- `Entrada de estoque` — formulário (depósito · produto · qtd · nota) + estoque inicial em lote.
- `Movimentações` — histórico com data/hora.
- `Relatório de estoque` — entradas/saídas/saldo por período + export.
- `Depósitos` — criar/editar depósitos.

**App (mobile):**
- `Estoque` — consulta rápida de saldo.
- Entrada de estoque por **câmera** (bipa produto + informa qtd) — opcional.
- A baixa de saída acontece **automática** na bipagem de envio que já existe.

## 🔗 Integração com o que já existe
- Usa a mesma chave de produto (`store_product_key`) → casa com os produtos lidos dos PDFs.
- A baixa engata em `store_scan_register` (bipagem) — sem retrabalho.
- O catálogo de produtos do estoque é alimentado pelos produtos dos PDFs.

## ❓ Decisões de design (preciso confirmar antes de construir)
1. **Quando descontar o estoque?** Na **bipagem/envio** (baixa real, recomendado) ou já na **entrada do PDF** (reserva)?
2. **Um depósito ou vários?** Começar com 1 "Depósito Principal" e permitir adicionar outros?
3. **Estoque inicial** — cadastro manual, em lote (colar lista), ou importar de planilha/relatório?
4. **Chave do produto** — nome+SKU (atual). Quando houver SKU, priorizar SKU (mais confiável)?

## 🛠️ Fases de implementação
- **Fase E1** — Depósitos + saldo + entrada de estoque (inicial/reposição) com data/hora + tela de Estoque.
- **Fase E2** — Baixa automática na bipagem + movimentações + alertas.
- **Fase E3** — Relatórios de entrada/saída/saldo por período + export Excel.
- **Fase E4** — Estoque no app mobile (consulta + entrada por câmera).
