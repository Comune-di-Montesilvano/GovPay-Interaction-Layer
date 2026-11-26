(function () {
  var parseJsonSafely = (window.GovPayUi && window.GovPayUi.parseJsonSafely)
    ? window.GovPayUi.parseJsonSafely
    : function(response) { return response.json(); };

  function formatEuro(cents) {
    var euros = cents / 100;
    return '€ ' + euros.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  }

  function recalcTotal() {
    var rows = document.querySelectorAll('#cart-tbody tr[data-importo]');
    var total = 0;
    rows.forEach(function(r) { total += parseInt(r.dataset.importo || '0', 10); });
    var totalEl = document.getElementById('cart-total');
    var countEl = document.getElementById('cart-items-count');
    if (totalEl) totalEl.textContent = formatEuro(total);
    if (countEl) {
      var n = rows.length;
      countEl.textContent = n + ' ' + (n === 1 ? GovPayUi.t("avviso") : GovPayUi.t("avvisi"));
    }

    var form = document.getElementById('cart-checkout-form');
    if (form) {
      form.querySelectorAll('.cart-hidden-input').forEach(function(el) { el.remove(); });
      rows.forEach(function(row) {
        var rowId = row.id.replace('row-', '');
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'pendenze[]';
        inp.value = rowId;
        inp.className = 'cart-hidden-input';
        form.appendChild(inp);
      });
    }

    var btn = document.getElementById('btn-checkout');
    if (btn) btn.disabled = rows.length === 0;
  }

  function updateNavBadge(count) {
    document.querySelectorAll('.cart-badge').forEach(function(b) {
      b.textContent = count;
      b.style.display = count > 0 ? '' : 'none';
    });
  }

  document.querySelectorAll('.btn-remove').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var id = this.dataset.id;
      if (!id) return;
      var body = new URLSearchParams({ idPendenza: id, _csrf: GovPayUi.csrf });
      fetch('/carrello/rimuovi', { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': GovPayUi.csrf } })
        .then(function(r) { return parseJsonSafely(r); })
        .then(function(data) {
          if (data && data.error) { alert(data.error); return; }
          var row = document.getElementById('row-' + id);
          if (row) row.remove();
          recalcTotal();
          updateNavBadge(data.count || 0);
        })
        .catch(function(err) {
          alert(err && err.message ? err.message : GovPayUi.t("Servizio temporaneamente non disponibile. Riprova tra poco."));
          window.location.reload();
        });
    });
  });
})();
