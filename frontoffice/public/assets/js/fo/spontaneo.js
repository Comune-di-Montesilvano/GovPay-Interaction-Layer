(function(){
  // Segmented control tipo soggetto
  var segTipo = document.getElementById('seg-tipo');
  var tipoInput = document.getElementById('soggettoPagatore_tipo');
  var pfFields = document.getElementById('pf-fields');
  var pgFields = document.getElementById('pg-fields');
  var pfBtn = document.getElementById('btn-tipo-pf');
  var pgBtn = document.getElementById('btn-tipo-pg');

  function switchTipo(tipo) {
    if (!tipoInput) return;
    tipoInput.value = tipo;
    if (tipo === 'G') {
      pfFields && (pfFields.style.display = 'none');
      pgFields && (pgFields.style.display = '');
      pfBtn && pfBtn.classList.remove('on');
      pgBtn && pgBtn.classList.add('on');
      // Disable PF inputs so they don't submit
      document.querySelectorAll('#pf-fields input').forEach(function(el){ el.disabled = true; });
      document.querySelectorAll('#pg-fields input').forEach(function(el){ el.disabled = false; });
    } else {
      pfFields && (pfFields.style.display = '');
      pgFields && (pgFields.style.display = 'none');
      pfBtn && pfBtn.classList.add('on');
      pgBtn && pgBtn.classList.remove('on');
      document.querySelectorAll('#pf-fields input').forEach(function(el){ el.disabled = false; });
      document.querySelectorAll('#pg-fields input').forEach(function(el){ el.disabled = true; });
    }
  }

  if (pfBtn) pfBtn.addEventListener('click', function(){ switchTipo('F'); });
  if (pgBtn) pgBtn.addEventListener('click', function(){ switchTipo('G'); });

  // Init
  switchTipo(tipoInput ? tipoInput.value : 'F');

  // Auto-uppercase CF/PIVA
  ['nome','identificativo','identificativo-pg','anagrafica','anagrafica-pg'].forEach(function(id){
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', function(){ el.value = el.value.toUpperCase(); });
    el.addEventListener('blur', function(){ el.value = el.value.toUpperCase(); });
  });

  // Causale counter
  var causale = document.getElementById('causale');
  var counter = document.getElementById('causaleCounter');
  if (causale && counter) {
    var sync = function(){ counter.textContent = (causale.value || '').length + '/140'; };
    causale.addEventListener('input', sync);
    sync();
  }

  // Dynamic importo default / block based on service selection
  var servicesDataEl = document.getElementById('service-options-data');
  var servicesData = servicesDataEl ? JSON.parse(servicesDataEl.textContent) : [];
  var serviceSelect = document.getElementById('idTipoPendenza');
  var importoInput = document.getElementById('importo');

  var importoHint = document.getElementById('importo-hint');

  // Il suggerimento "importo prefissato" e' la descrizione accessibile del campo solo quando e'
  // visibile: aria-describedby leggerebbe anche il testo nascosto.
  function mostraHintImporto(visibile) {
    if (!importoHint) return;
    importoHint.style.display = visibile ? 'flex' : 'none';
    if (visibile) importoInput.setAttribute('aria-describedby', 'importo-hint');
    else importoInput.removeAttribute('aria-describedby');
  }

  function updateImporto() {
    if (!serviceSelect || !importoInput) return;
    var selectedId = serviceSelect.value;
    var service = servicesData.find(function(s) { return s.id === selectedId; });
    if (service) {
      if (service.importo_bloccato) {
        importoInput.value = service.importo_prefissato ? parseFloat(service.importo_prefissato).toFixed(2) : '';
        importoInput.readOnly = true;
        mostraHintImporto(true);
      } else {
        importoInput.readOnly = false;
        mostraHintImporto(false);
        if (service.importo_prefissato) {
          importoInput.value = parseFloat(service.importo_prefissato).toFixed(2);
        }
      }
    } else {
      importoInput.readOnly = false;
      mostraHintImporto(false);
    }
  }

  if (serviceSelect) {
    serviceSelect.addEventListener('change', updateImporto);
    // Initialize if there's a pre-filled value
    if (serviceSelect.value) {
      var service = servicesData.find(function(s) { return s.id === serviceSelect.value; });
      if (service && (service.importo_bloccato || !importoInput.value)) {
        updateImporto();
      }
    }
  }
})();
