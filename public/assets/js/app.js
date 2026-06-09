(function(){
    const sidebar = document.getElementById('sidebar');
    const hamburger = document.getElementById('hamburger');
    const backdrop = document.getElementById('sidebarBackdrop');
    function closeMenu(){ sidebar && sidebar.classList.remove('open'); backdrop && backdrop.classList.remove('show'); }
    function openMenu(){ sidebar && sidebar.classList.add('open'); backdrop && backdrop.classList.add('show'); }
    if(hamburger){ hamburger.addEventListener('click', function(){ sidebar.classList.contains('open') ? closeMenu() : openMenu(); }); }
    if(backdrop){ backdrop.addEventListener('click', closeMenu); }

    const dz = document.getElementById('dropzone');
    if(dz){
        const input = dz.querySelector('input[type=file]');
        ['dragenter','dragover'].forEach(evt => dz.addEventListener(evt, function(e){ e.preventDefault(); dz.classList.add('dragover'); }));
        ['dragleave','drop'].forEach(evt => dz.addEventListener(evt, function(e){ e.preventDefault(); dz.classList.remove('dragover'); }));
        dz.addEventListener('drop', function(e){ if(e.dataTransfer.files.length){ input.files = e.dataTransfer.files; dz.querySelector('strong').textContent = e.dataTransfer.files[0].name; } });
        input.addEventListener('change', function(){ if(input.files.length){ dz.querySelector('strong').textContent = input.files[0].name; } });
    }

    const form = document.getElementById('uploadForm');
    if(form){
        form.addEventListener('submit', function(){
            const box = document.getElementById('progressBox');
            const bar = document.getElementById('progressBar');
            const pct = document.getElementById('progressPercent');
            const text = document.getElementById('progressText');
            if(box){ box.hidden = false; }
            let p = 0;
            const steps = ['Enviando PDF...', 'Extraindo texto...', 'Lendo etiquetas...', 'Agrupando produtos...', 'Somando unidades...'];
            const timer = setInterval(function(){
                p = Math.min(93, p + Math.floor(Math.random()*8)+2);
                if(bar) bar.style.width = p + '%';
                if(pct) pct.textContent = p + '%';
                if(text) text.textContent = steps[Math.min(steps.length-1, Math.floor(p/22))];
                if(p >= 93) clearInterval(timer);
            }, 320);
        });
    }
})();
