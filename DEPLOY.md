# SystemETI — Deploy no Railway

App PHP (Apache + poppler-utils) que grava em `storage/` via JSON.
No Railway o disco é efêmero, então **é obrigatório um volume persistente** montado em `/var/www/html/storage`, senão os relatórios e etiquetas somem a cada deploy.

## Pré-requisitos
- Conta Railway com um **token que possa criar projeto novo** (Account Token), de preferência **recém-gerado** (os tokens antigos foram expostos em texto e devem ser revogados).
- Railway CLI instalado.

## 1. Instalar a CLI
```powershell
# via npm (se tiver Node):
npm i -g @railway/cli
# ou via Scoop:
scoop install railway
```

## 2. Autenticar
```powershell
$env:RAILWAY_API_TOKEN = "<SEU_NOVO_ACCOUNT_TOKEN>"   # NÃO commitar
railway whoami
```

## 3. Criar projeto SEPARADO (não usar o nfe-microservice)
```powershell
railway init --name SystemETI
```

## 4. Criar o volume persistente
```powershell
railway volume add --mount-path /var/www/html/storage
```

## 5. Deploy (sobe a pasta local, build via Dockerfile)
```powershell
railway up
```

## 6. Gerar domínio público
```powershell
railway domain
```

## Pós-deploy (importante)
1. Acesse o domínio e entre com `admin@local` / `admin123`.
2. Vá em **Configurações** e **troque a senha** imediatamente (a padrão é pública).
3. Confira em Configurações se `pdftotext` e `pdfinfo` aparecem como "instalado".

## Observações
- Roda em **1 instância** (sessões e JSON em disco). Não escalar horizontalmente sem antes migrar o storage para banco (ex.: Supabase).
- O build usa `Dockerfile` na raiz; o Railway detecta automaticamente.
