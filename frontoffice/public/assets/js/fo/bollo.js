(function () {
  // ── Segmented control tipo soggetto ──────────────────────────────────────
  var tipoInput = document.getElementById('soggettoPagatore_tipo');
  var pfFields  = document.getElementById('pf-fields');
  var pgFields  = document.getElementById('pg-fields');
  var pfBtn     = document.getElementById('btn-tipo-pf');
  var pgBtn     = document.getElementById('btn-tipo-pg');

  function switchTipo(tipo) {
    if (!tipoInput) return;
    tipoInput.value = tipo;
    if (tipo === 'G') {
      pfFields && (pfFields.style.display = 'none');
      pgFields && (pgFields.style.display = '');
      pfBtn && pfBtn.classList.remove('on');
      pgBtn && pgBtn.classList.add('on');
      document.querySelectorAll('#pf-fields input').forEach(function (el) { el.disabled = true; });
      document.querySelectorAll('#pg-fields input').forEach(function (el) { el.disabled = false; });
    } else {
      pfFields && (pfFields.style.display = '');
      pgFields && (pgFields.style.display = 'none');
      pfBtn && pfBtn.classList.add('on');
      pgBtn && pgBtn.classList.remove('on');
      document.querySelectorAll('#pf-fields input').forEach(function (el) { el.disabled = false; });
      document.querySelectorAll('#pg-fields input').forEach(function (el) { el.disabled = true; });
    }
  }

  if (pfBtn) pfBtn.addEventListener('click', function () { switchTipo('F'); });
  if (pgBtn) pgBtn.addEventListener('click', function () { switchTipo('G'); });
  switchTipo(tipoInput ? tipoInput.value : 'F');

  // Auto-uppercase
  ['nome', 'anagrafica', 'identificativo', 'anagrafica-pg', 'identificativo-pg', 'provinciaResidenza'].forEach(function (id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', function () { el.value = el.value.toUpperCase(); });
    el.addEventListener('blur',  function () { el.value = el.value.toUpperCase(); });
  });

  // ── Documenti dinamici ────────────────────────────────────────────────────
  var list        = document.getElementById('documentiList');
  var addBtn      = document.getElementById('btn-add-doc');
  var limitMsg    = document.getElementById('doc-limit-msg');
  var totalSpan   = document.getElementById('bolloTotal');
  var MAX_DOCS    = 5;

  function getRows() {
    return list ? Array.from(list.querySelectorAll('.bollo-doc-row')) : [];
  }

  function updateTotal() {
    var n = getRows().length;
    if (totalSpan) {
      var total = (n * 16).toFixed(2).replace('.', ',');
      totalSpan.textContent = '€ ' + total + (n > 1 ? ' (' + n + ' bollI)' : '');
    }
    if (addBtn) addBtn.style.display = n >= MAX_DOCS ? 'none' : '';
    if (limitMsg) limitMsg.style.display = n >= MAX_DOCS ? '' : 'none';
    updateRemoveButtons();
  }

  function updateRemoveButtons() {
    var rows = getRows();
    rows.forEach(function (row) {
      var btn = row.querySelector('.btn-remove-doc');
      if (btn) btn.style.display = rows.length > 1 ? '' : 'none';
    });
  }

  function reindex() {
    getRows().forEach(function (row, i) {
      var label    = row.querySelector('label[for-doc]');
      var titleLbl = row.querySelector('.doc-label');
      var textInp  = row.querySelector('.doc-titolo');
      var fileInp  = row.querySelector('.doc-file');
      if (titleLbl) titleLbl.textContent = GovPayUi.t("Documento") + ' ' + (i + 1);
      if (textInp)  textInp.name = 'documenti[' + i + ']';
      if (fileInp)  fileInp.name = 'file_bollo[' + i + ']';
    });
  }

  function bindRowEvents(row) {
    // File toggle
    var toggle   = row.querySelector('.file-toggle');
    var fileWrap = row.querySelector('.file-wrap');
    if (toggle && fileWrap) {
      toggle.addEventListener('change', function () {
        fileWrap.style.display = toggle.checked ? '' : 'none';
        var fileInp = fileWrap.querySelector('input[type="file"]');
        if (fileInp && !toggle.checked) fileInp.value = '';
      });
    }
    // Remove button
    var removeBtn = row.querySelector('.btn-remove-doc');
    if (removeBtn) {
      removeBtn.addEventListener('click', function () {
        if (getRows().length > 1) {
          row.remove();
          reindex();
          updateTotal();
        }
      });
    }
  }

  // Bind eventi sulla prima riga (già nel DOM)
  getRows().forEach(function (row) { bindRowEvents(row); });
  updateTotal();

  // Aggiungi documento
  if (addBtn) {
    addBtn.addEventListener('click', function () {
      var rows = getRows();
      if (rows.length >= MAX_DOCS) return;
      var idx = rows.length;

      var row = document.createElement('div');
      row.className = 'bollo-doc-row';
      row.style.cssText = 'margin-bottom:var(--sp-3);padding:var(--sp-3);background:var(--bg-2);border-radius:var(--radius);border:1px solid var(--border-1);';
      row.innerHTML =
        '<div class="field" style="margin-bottom:var(--sp-2);">' +
          '<label style="font-size:13px;font-weight:500;margin-bottom:4px;display:block;" class="doc-label">' + GovPayUi.th("Documento") + ' ' + (idx + 1) + '</label>' +
          '<input class="input doc-titolo" type="text" name="documenti[' + idx + ']" placeholder="' + GovPayUi.th("Es. Certificato anagrafico prot. 12345") + '">' +
        '</div>' +
        '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:var(--sp-2);">' +
          '<div class="field" style="margin-bottom:0;flex:1;">' +
            '<label style="font-size:12px;color:var(--fg-3);cursor:pointer;display:inline-flex;align-items:center;gap:6px;">' +
              '<input type="checkbox" class="file-toggle" style="margin:0;"> ' + GovPayUi.th("Allega file per legarlo alla marca") +
            '</label>' +
            '<div class="file-wrap" style="display:none;margin-top:6px;">' +
              '<input class="input doc-file" type="file" name="file_bollo[' + idx + ']" accept=".pdf,.doc,.docx,.txt,.xml,.p7m" style="font-size:13px;">' +
              '<p class="hint" style="margin:4px 0 0;">' + GovPayUi.th("Il file non viene salvato, serve solo per calcolare l'impronta.") + '</p>' +
            '</div>' +
          '</div>' +
          '<button type="button" class="btn btn-ghost btn-sm btn-remove-doc" title="' + GovPayUi.th("Rimuovi") + '" style="color:var(--ms-danger,#c0392b);">' +
            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
          '</button>' +
        '</div>';

      list.appendChild(row);
      bindRowEvents(row);
      updateTotal();
      row.querySelector('.doc-titolo').focus();
    });
  }

})();
