<?php
require_once __DIR__ . '/../app/helpers.php';
?><!doctype html><meta charset="utf-8"><title>Instalação</title><body style="font-family:Arial;padding:30px"><h2>SystemETI</h2><p>Instale ou atualize pelo terminal:</p><pre>cd /root
rm -rf painel_etiquetas_v5_update
mkdir painel_etiquetas_v5_update
unzip -o painel_etiquetas_v5.zip -d painel_etiquetas_v5_update
cd painel_etiquetas_v5_update
sudo bash update_v5.sh</pre></body>
