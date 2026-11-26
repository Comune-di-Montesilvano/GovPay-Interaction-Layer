(function(){
  var qrString = GovPayUi.data.qr || '';
  var stampa = document.getElementById('btn-print');
  if (stampa) stampa.addEventListener('click', function () { window.print(); });
  var el = document.getElementById('qrcode');
  if (el && typeof QRCode !== 'undefined') {
    new QRCode(el, {
      text: qrString,
      width: 160,
      height: 160,
      colorDark: '#000000',
      colorLight: '#ffffff',
      correctLevel: QRCode.CorrectLevel.M
    });
    // Il nome accessibile e' sul contenitore (role=img): immagine e canvas generati sono decorativi.
    Array.prototype.forEach.call(el.querySelectorAll('img'), function (img) { img.alt = ''; });
  } else if (el) {
    el.innerHTML = '<p style="font-size:11px;color:#c00;">' + GovPayUi.th("QR non disponibile. Usa il numero avviso sopra.") + '</p>';
  }
})();
