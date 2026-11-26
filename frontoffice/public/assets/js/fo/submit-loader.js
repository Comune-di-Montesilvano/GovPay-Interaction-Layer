(function () {
  /* Traccia l'ultimo bottone submit cliccato per gestire formaction multipli */
  var _lastSubmitBtn = null;
  document.addEventListener('mousedown', function (e) {
    var b = e.target.closest('button[type="submit"],input[type="submit"]');
    if (b) _lastSubmitBtn = b;
  }, true);

  document.addEventListener('submit', function (e) {
    var form = e.target;
    var btn = (_lastSubmitBtn && form.contains(_lastSubmitBtn))
      ? _lastSubmitBtn
      : form.querySelector('button[type="submit"]:not([disabled]),input[type="submit"]:not([disabled])');
    _lastSubmitBtn = null;

    if (!btn || btn.disabled) return;
    /* Escludi bottoni con data-no-loader */
    if (btn.hasAttribute('data-no-loader')) return;

    btn.disabled = true;
    btn.style.cursor = 'not-allowed';
    btn.style.opacity = '0.85';

    /* Sostituisce il contenuto con spinner + testo */
    var loadingText = btn.getAttribute('data-loading-text')
      || btn.textContent.trim()
      || GovPayUi.t("Attendere...");
    btn.innerHTML = '<span class="fo-submit-spinner" aria-hidden="true"></span>';
    btn.appendChild(document.createTextNode(' ' + loadingText));
  }, false);
})();
