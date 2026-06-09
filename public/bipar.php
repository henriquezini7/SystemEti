<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/mobile.php';
require_once __DIR__ . '/../app/store.php';
$user = auth_user();
if (!$user) { redirect('entrar.php'); }
$mode = (($_GET['mode'] ?? 'sent') === 'return') ? 'return' : 'sent';
$csrf = csrf_token();
m_head('Bipagem', ['back' => 'home.php']);
?>
<div class="scan-head">
  <h2>Leitura de Etiqueta</h2>
  <p>Posicione o QR Code ou Código de Barras no leitor</p>
</div>

<div class="scan-stage">
  <div id="reader"></div>
  <div class="frame"><span class="corner tl"></span><span class="corner tr"></span><span class="corner bl"></span><span class="corner br"></span><span class="laser"></span></div>
</div>

<div class="scan-auto"><span class="dot"></span> Leitura automática ativada</div>
<div id="camResult" class="scan-result"></div>

<button class="m-btn light" id="startBtn">Ativar câmera</button>
<div class="m-link" id="manualToggle">Digitar código manualmente</div>
<form id="manualForm" style="display:none" onsubmit="return false">
  <div class="m-field"><input class="m-input" id="manualCode" placeholder="Digite o código / rastreio" autocomplete="off"></div>
  <button class="m-btn primary" id="manualSend">Confirmar</button>
</form>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(function(){
  const csrf=<?= json_encode($csrf) ?>, mode=<?= json_encode($mode) ?>;
  const resultEl=document.getElementById('camResult');
  const startBtn=document.getElementById('startBtn');
  let scanner=null,last='',lastT=0,busy=false,running=false;

  function beep(ok){try{const c=new(window.AudioContext||window.webkitAudioContext)();const o=c.createOscillator(),g=c.createGain();o.connect(g);g.connect(c.destination);o.frequency.value=ok?880:220;g.gain.value=.09;o.start();setTimeout(()=>{o.stop();c.close();},130);}catch(e){}}
  function vibrate(ok){try{navigator.vibrate&&navigator.vibrate(ok?80:[60,40,60]);}catch(e){}}
  function show(h,c){resultEl.className='scan-result '+c;resultEl.innerHTML=h;}

  async function send(code){
    const now=Date.now();
    if(busy)return; if(code===last&&(now-lastT)<2500)return;
    last=code;lastT=now;busy=true;
    try{
      const fd=new FormData();fd.append('_csrf',csrf);fd.append('mode',mode);fd.append('code',code);
      const r=await fetch('/scan_api.php',{method:'POST',body:fd});
      const j=await r.json();
      beep(!!j.ok);vibrate(!!j.ok);
      const extra=j.tracking?('<small>'+((j.recipient||'')+' · '+j.tracking)+'</small>'+(j.products?'<small>'+j.products.split('\n')[0]+'</small>':'')):'';
      show('<strong>'+(j.message||('Lido: '+code))+'</strong>'+extra, j.ok?'ok':'bad');
    }catch(e){beep(false);show('<strong>Erro de conexão. Tente de novo.</strong>','bad');}
    finally{setTimeout(()=>{busy=false;},700);}
  }

  async function start(){
    if(running||!window.Html5Qrcode)return;
    const F=Html5QrcodeSupportedFormats;
    const fmts=[F.QR_CODE,F.CODE_128,F.CODE_39,F.CODE_93,F.EAN_13,F.EAN_8,F.ITF,F.UPC_A,F.CODABAR];
    scanner=new Html5Qrcode("reader",{formatsToSupport:fmts,verbose:false});
    try{await scanner.start({facingMode:"environment"},{fps:10,qrbox:{width:240,height:150}},send,()=>{});running=true;startBtn.style.display='none';}
    catch(e){show('<strong>Permita o acesso à câmera (use HTTPS).</strong>','bad');}
  }
  startBtn.addEventListener('click',start);
  // tenta iniciar automaticamente
  window.addEventListener('load',()=>{setTimeout(start,400);});

  // manual
  document.getElementById('manualToggle').addEventListener('click',function(){
    const f=document.getElementById('manualForm');f.style.display=f.style.display==='none'?'block':'none';
  });
  document.getElementById('manualSend').addEventListener('click',function(){
    const v=document.getElementById('manualCode').value.trim();if(v){last='';send(v);document.getElementById('manualCode').value='';}
  });
})();
</script>
<?php m_foot('bipar'); ?>
