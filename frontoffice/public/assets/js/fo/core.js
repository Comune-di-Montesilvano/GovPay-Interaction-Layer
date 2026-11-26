window.GovPayUi = window.GovPayUi || {};
window.GovPayUi.parseJsonSafely = function (response) {
  var contentType = (response.headers.get('content-type') || '').toLowerCase();
  if (contentType.indexOf('application/json') !== -1) {
    return response.json().catch(function () {
      throw new Error(GovPayUi.t("Servizio temporaneamente non disponibile. Riprova tra poco."));
    });
  }

  return response.text().then(function (raw) {
    var trimmed = (raw || '').trim();
    if (trimmed && trimmed.charAt(0) === '{') {
      try {
        return JSON.parse(trimmed);
      } catch (_) {
        throw new Error(GovPayUi.t("Servizio temporaneamente non disponibile. Riprova tra poco."));
      }
    }

    throw new Error(GovPayUi.t("Servizio temporaneamente non disponibile. Riprova tra poco."));
  });
};

/* ── Global Cart AJAX ── */
(function () {
  var parseJsonSafely = window.GovPayUi.parseJsonSafely;

  function updateCartBadges(count) {
    document.querySelectorAll('.cart-badge').forEach(function (el) {
      el.textContent = count;
      el.style.display = count > 0 ? '' : 'none';
    });
  }

  var btn = document.getElementById('btn-cart-add');
  if (btn) {
    if (btn.dataset.added === '1') {
      var lbl = document.getElementById('btn-cart-label');
      if (lbl) lbl.textContent = GovPayUi.t("Nel carrello") + ' ✓';
      btn.disabled = true;
    }

    btn.addEventListener('click', function () {
      var id = btn.dataset.id;
      if (!id || btn.disabled) return;
      btn.disabled = true;
      var lbl = document.getElementById('btn-cart-label');

      var body = new URLSearchParams({ idPendenza: id, _csrf: GovPayUi.csrf });
      fetch('/carrello/aggiungi', {
        method: 'POST',
        body: body,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': GovPayUi.csrf }
      })
      .then(function (r) { return parseJsonSafely(r); })
      .then(function (data) {
        if (data.error) {
          btn.disabled = false;
          alert(data.error);
          return;
        }
        if (lbl) lbl.textContent = GovPayUi.t("Nel carrello") + ' ✓';
        updateCartBadges(data.count || 0);
      })
      .catch(function (err) {
        btn.disabled = false;
        if (lbl) lbl.textContent = GovPayUi.t("Servizio non disponibile");
        alert(err && err.message ? err.message : GovPayUi.t("Servizio temporaneamente non disponibile. Riprova tra poco."));
      });
    });
  }
})();
