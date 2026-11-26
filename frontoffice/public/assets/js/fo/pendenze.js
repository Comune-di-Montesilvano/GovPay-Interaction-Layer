(function () {
  var MAX_SEL = 5;
  var bar = document.getElementById('carrello-bar');
  var hint = document.getElementById('carrello-hint');
  var countEl = document.getElementById('bar-count');
  var totalEl = document.getElementById('bar-total');
  var deselBtn = document.getElementById('deseleziona-btn');
  var checkboxes = Array.from(document.querySelectorAll('.pendenza-checkbox'));

  function formatEuro(cents) {
    var euros = cents / 100;
    return '€ ' + euros.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  }

  function update() {
    var selected = checkboxes.filter(function(cb) { return cb.checked; });
    var count = selected.length;
    var totalCents = selected.reduce(function(s, cb) { return s + parseInt(cb.dataset.importo || '0', 10); }, 0);

    countEl.textContent = count;
    totalEl.textContent = formatEuro(totalCents);

    var displayEl = document.getElementById('bar-text-display');
    if (displayEl) {
      displayEl.textContent = count === 1
        ? document.getElementById('bar-text-singular').textContent
        : document.getElementById('bar-text-plural').textContent;
    }

    if (count > 0) {
      bar.classList.add('visible');
      if (hint) hint.style.display = 'flex';
      document.body.style.paddingBottom = '80px';
    } else {
      bar.classList.remove('visible');
      document.body.style.paddingBottom = '';
    }

    checkboxes.forEach(function(cb) {
      if (!cb.checked) cb.disabled = count >= MAX_SEL;
    });
  }

  checkboxes.forEach(function(cb) { cb.addEventListener('change', update); });

  if (deselBtn) deselBtn.addEventListener('click', function() {
    checkboxes.forEach(function(cb) { cb.checked = false; cb.disabled = false; });
    update();
  });

  if (checkboxes.length > 0 && hint) hint.style.display = 'flex';

  update();
})();

(function () {
  var svgCheck = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 13 4 4L19 7"/></svg>';
  var svgCart = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="20" r="1.5"/><circle cx="17" cy="20" r="1.5"/><path d="M3 4h2l3 12h11l2-8H7"/></svg>';
  var svgTrash = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>';

  function updateBadges(count) {
    document.querySelectorAll('.cart-badge').forEach(function (el) {
      el.textContent = count;
      el.style.display = count > 0 ? '' : 'none';
    });
    // Disabilita tutti i bottoni aggiungi se carrello pieno
    var full = count >= 5;
    document.querySelectorAll('.btn-cart-row:not([disabled])').forEach(function (b) {
      if (!b.classList.contains('btn-outline')) { // non è già "nel carrello"
        b.disabled = full;
      }
    });
  }

  // Aggiungi al carrello
  document.querySelectorAll('.btn-cart-row').forEach(function (btn) {
    if (btn.classList.contains('btn-outline')) return; // già nel carrello, skip
    btn.addEventListener('click', function () {
      var id = btn.dataset.id;
      if (!id || btn.disabled) return;
      btn.disabled = true;
      fetch('/carrello/aggiungi', {
        method: 'POST',
        body: new URLSearchParams({ idPendenza: id, _csrf: GovPayUi.csrf }),
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': GovPayUi.csrf }
      })
      .then(function (r) { return window.GovPayUi.parseJsonSafely(r); })
      .then(function (data) {
        if (data.error) { btn.disabled = false; alert(data.error); return; }
        // Trasforma in bottone "nel carrello"
        btn.classList.remove('btn-primary');
        btn.classList.add('btn-outline');
        btn.innerHTML = svgCheck + '<span class="sr-only">' + GovPayUi.th("Nel carrello ✓") + '</span>';
        btn.title = GovPayUi.t("Nel carrello");
        // Inserisci bottone rimuovi accanto
        var rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'btn btn-sm btn-ghost btn-cart-remove';
        rm.dataset.id = id;
        rm.title = GovPayUi.t("Rimuovi dal carrello");
        rm.style.color = 'var(--c-danger)';
        rm.innerHTML = svgTrash + '<span class="sr-only">' + GovPayUi.th("Rimuovi") + '</span>';
        btn.insertAdjacentElement('afterend', rm);
        attachRemove(rm);
        updateBadges(data.count || 0);
      })
      .catch(function (err) {
        btn.disabled = false;
        alert(err && err.message ? err.message : GovPayUi.t("Servizio temporaneamente non disponibile. Riprova tra poco."));
      });
    });
  });

  function attachRemove(btn) {
    btn.addEventListener('click', function () {
      var id = btn.dataset.id;
      if (!id || btn.disabled) return;
      btn.disabled = true;
      fetch('/carrello/rimuovi', {
        method: 'POST',
        body: new URLSearchParams({ idPendenza: id, _csrf: GovPayUi.csrf }),
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': GovPayUi.csrf }
      })
      .then(function (r) { return window.GovPayUi.parseJsonSafely(r); })
      .then(function (data) {
        if (data.error) { btn.disabled = false; alert(data.error); return; }
        // Ripristina bottone aggiungi
        var addBtn = document.querySelector('.btn-cart-row[data-id="' + id + '"]');
        if (addBtn) {
          addBtn.classList.remove('btn-outline');
          addBtn.classList.add('btn-primary');
          addBtn.innerHTML = svgCart + '<span class="sr-only">' + GovPayUi.th("Aggiungi al carrello") + '</span>';
          addBtn.title = GovPayUi.t("Aggiungi al carrello");
          addBtn.disabled = false;
        }
        btn.remove();
        updateBadges(data.count || 0);
      })
      .catch(function (err) {
        btn.disabled = false;
        alert(err && err.message ? err.message : GovPayUi.t("Servizio temporaneamente non disponibile. Riprova tra poco."));
      });
    });
  }

  // Attacca remove ai bottoni già presenti (item già in carrello al caricamento pagina)
  document.querySelectorAll('.btn-cart-remove').forEach(attachRemove);
})();
