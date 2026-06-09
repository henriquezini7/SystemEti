# SystemETI — Plano de Desenvolvimento
### Controle gigante de entrada e saída de etiquetas (Mercado Livre + Shopee) — à prova de furo

## 🎯 Objetivo
Toda etiqueta que **ENTRA** (PDF) tem que **SAIR** (bipagem), com lista completa, histórico do dia e conferência que não deixa furo. Entrada conta o que era esperado; saída dá baixa; o painel mostra o que falta.

---

## 🔄 Ciclo completo
1. **ENTRADA (inclusão das etiquetas)**
   - Upload do PDF (ML / Shopee / Jadlog), seja **texto** ou **imagem**.
   - Sistema lê e verifica os pedidos.
   - Monta a lista: **pedido · vendedor (remetente) · destinatário · unidades · produtos · SKU · rastreio**.
   - Bloqueia PDF duplicado (hash) e código repetido.
2. **VERIFICAÇÃO / LISTA**
   - Totais: pedidos, unidades, etiquetas.
   - Separação por produto (quantos de cada).
   - Acumulado do dia e geral.
3. **SAÍDA (bipagem)**
   - Bipa cada etiqueta → dá baixa (**pendente → enviada**).
   - Gera histórico do dia + auditoria.
   - Travas: "não cadastrada" e "duplicado".
4. **CONTROLE 100% (sem furo)**
   - Painel: **Esperados × Bipados × Faltantes (%)** por dia.
   - Lista do que falta sair.
   - Alertas de divergência.

---

## ✅ Estado atual (verificado no ar — https://eti-allava.up.railway.app)
| Item | Status |
|---|---|
| Deploy Railway + volume persistente | ✅ |
| Login / sessão / CSRF | ✅ |
| Entrada por PDF **de texto** (Shopee: 95 pedidos lidos certo) | ✅ |
| Lista pedido/produto/SKU/destinatário/unidades | ✅ |
| Bipagem/saída (modo enviado): pendente→enviada | ✅ |
| Travas: duplicado e "não cadastrada" | ✅ |
| Histórico/auditoria + Pendentes/Enviadas/Devolvidas | ✅ |
| Entrada por PDF **de imagem** | ❌ (precisa OCR) |
| Painel de conferência 100% dedicado | ⚠️ parcial (existe Pendentes, falta o painel completo) |

---

## 🧩 OCR — validado
Os PDFs de vocês são **imagem** (`/Font=0`). O `pdftotext` lê 0. Mas o **Tesseract (OCR)** lê muito bem essas páginas (são imagens digitais nítidas, não scan):
- Rastreio `AD541156638BR` → 100%
- Produto, `Venda:`, `Quantidade:`, destinatário → alta precisão.
- A lista de produtos do ML fica concentrada em **1-2 páginas finais** → dá pra OCR só elas (rápido).

---

## 🛠️ Fases de desenvolvimento

### Fase 0 — Segurança e limpeza (rápido)
- Trocar senha padrão (`admin@local`/`admin123`).
- Revogar/rotacionar tokens expostos (Supabase service_role, Railway, Vercel).
- Limpar dados de teste do ambiente.

### Fase 1 — OCR na entrada (o coração) ⭐ ✅ CONCLUÍDA
Validado no ar: PDF de imagem ML (08-06) → **17 pedidos/produtos/destinatários lidos** + bipagem do código lido por OCR funcionando. Tesseract `--psm 6` mantém colunas na mesma linha; leitor `parseMercadoLivreOcrList`.

- Docker: adicionar `tesseract-ocr` + `tesseract-ocr-por` (poppler/pdftoppm já existe).
- Parser: se `pdftotext` vier vazio/esparso → renderizar páginas (pdftoppm ~250 dpi) → OCR → texto.
- Otimização: para ML, OCR só a(s) página(s) "Identificação/Produtos" (lista compacta) → rápido.
- Novo leitor OCR (regex por linha: rastreio + produto na mesma linha, `Venda:`/`Quantidade:`/destinatário nas seguintes).
- **Não afeta** PDFs de texto (OCR só dispara quando não há texto).
- Testar com 02-06, 03-06, 08-06.

### Fase 2 — Painel de Controle / Conferência 100% 🔧 EM ANDAMENTO
- ✅ Tela **Conferência** (`conferencia.php`): **Esperadas | Enviadas | Faltam | %** + barra de progresso.
- ✅ Lista "Faltam sair" (pendentes) em destaque.
- ✅ Alerta de furo: códigos bipados que não estavam cadastrados.
- ⬜ Exportar o controle (Excel/TXT) no formato da planilha atual de vocês.

### Fase APP — Bipagem por câmera (código de barras + QR Code) 📱
- O app/bipagem vai ler **código de barras e QR Code pela câmera** (não só leitor USB/digitação).
- Web: dá pra usar a câmera do celular no navegador (biblioteca JS de leitura de barcode/QR) — já adianta o "app" sem instalar nada.
- Nativo (futuro): app dedicado lendo câmera + modo offline.

### Fase 3 — Robustez de produção
- Resolver o servidor single-thread (`php -S`): migrar para **nginx + php-fpm** (multiusuário) ou processar OCR em **segundo plano** com status — pra OCR pesado não travar o painel.
- Backup automático do volume (dados em JSON).
- Vários conferentes bipando ao mesmo tempo.

### Fase 4 — Refinos
- SKU sem espaço (junta `1794130379 4` → `17941303794`).
- Dedup de produtos com nome quase-igual.
- Relatórios por período; impressão.

---

## ⚠️ Pontos de atenção
- **Velocidade do OCR**: mitigada lendo só as páginas de lista; ainda assim, lote grande pode levar ~1 min.
- **Single-thread atual**: durante OCR o site trava para outros — resolver na Fase 3 (ou já na 1 se for bipar e dar entrada ao mesmo tempo).
- **Precisão**: rastreio para conferência física usa o código de barras real (scanner), então erro de OCR não afeta a saída.
