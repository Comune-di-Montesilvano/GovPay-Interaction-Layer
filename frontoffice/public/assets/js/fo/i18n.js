/*
 * Dati della pagina per gli script del frontoffice: la CSP non ammette script inline, quindi
 * token CSRF, stringhe tradotte e dati di pagina arrivano da un blocco JSON non eseguibile
 * (partials/fo-data.html.twig, <script type="application/json" id="fo-data">).
 *   GovPayUi.t(testo)  traduzione per textContent/title/alert (chiave = testo italiano)
 *   GovPayUi.th(testo) traduzione con escape HTML, per stringhe concatenate in innerHTML
 *   GovPayUi.csrf      token CSRF della sessione
 *   GovPayUi.data      intero oggetto (es. data.qr nell'avviso del bollo)
 */
window.GovPayUi = window.GovPayUi || {};
(function (ui) {
  var data = {};
  var el = document.getElementById('fo-data');
  if (el) {
    try { data = JSON.parse(el.textContent || '{}') || {}; } catch (_) { data = {}; }
  }
  var dict = data.t || {};
  var esc = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

  ui.data = data;
  ui.csrf = data.csrf || '';
  ui.t = function (testo) {
    return Object.prototype.hasOwnProperty.call(dict, testo) ? String(dict[testo]) : testo;
  };
  ui.th = function (testo) {
    return ui.t(testo).replace(/[&<>"']/g, function (c) { return esc[c]; });
  };
})(window.GovPayUi);
