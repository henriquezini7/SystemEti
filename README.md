# SystemETI — Conferidor de Etiquetas ML/Shopee

Painel PHP sem MySQL, com armazenamento em JSON local.

## Principais recursos

- Upload de PDF Mercado Livre, Shopee e Jadlog/DANFE.
- Leitura de etiquetas, pedidos, rastreios, vendas, Pack ID, SKU e produtos quando o PDF contém essas informações.
- Relatório do PDF, relatório do dia e total geral acumulado.
- Nova página **Separação**: lista produto por produto com total de pedidos e total de unidades.
- Exportação TXT/CSV da separação.
- Impressão da lista de separação.
- Botões para apagar relatório individual, apagar dia e limpar histórico.
- Roda na porta 3037 via Nginx ou serviço PHP fallback.

## Instalação/Atualização

```bash
cd /root
rm -rf painel_etiquetas_v11_update
mkdir painel_etiquetas_v11_update
unzip -o painel_etiquetas_v11.zip -d painel_etiquetas_v11_update
cd painel_etiquetas_v11_update
sudo bash update_v11.sh
```

Acesse: `http://IP-DA-VPS:3037`

Login inicial:

- E-mail: `admin@local`
- Senha: `admin123`

Troque a senha em Configurações.


## v12 - Bipagem de envio e devolução

- Toda etiqueta lida nos PDFs é registrada em `storage/data/labels_registry.json`.
- A tela `Bipagem` permite conferir envio com leitor de código de barras.
- A mesma tela em modo devolução registra retorno/devolução.
- A tela `Etiquetas` mostra pendentes, enviadas e devolvidas.
- Código não encontrado no PDF fica em auditoria como etiqueta não cadastrada.


## v14 - Modo inteligente refinado

- Leitor SkyDrops/Shopee com checklist de carregamento (Produto / Variação / Qnt / SKU).
- Leitor Shopee/DACE no modelo ITEM DESCRIÇÃO QUANTIDADE VALOR.
- Correção de detecção: Shopee/SkyDrops/glstore não cai como Jadlog só por ter DANFE/DACE.
- Correção de rastreio: não confunde cabeçalho QUANTIDADE com código.
- Leitor Mercado Livre Flex com Identificação Produto no singular.
- Mantém módulo de bipagem e etiquetas registradas.


## v14
- Bloqueio de PDF duplicado por hash SHA-256.
- Se o mesmo PDF for enviado outra vez, o painel abre o relatório original e não soma novamente.
- Duplicados antigos são marcados como ignorados dos totais e da bipagem.
