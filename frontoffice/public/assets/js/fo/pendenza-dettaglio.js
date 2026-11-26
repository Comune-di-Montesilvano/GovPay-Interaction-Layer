(function(){
  document.addEventListener('click', function(e) {
    const btn = e.target.closest('.download-voce-bollo-xml-btn');
    if (!btn) return;
    const b64      = btn.dataset.testo;
    const iubd     = btn.dataset.iubd || btn.dataset.iuv || 'bollo';
    const filename = 'marca-da-bollo-' + iubd + '.xml';
    try {
      const binary = atob(b64);
      const bytes  = new Uint8Array(binary.length);
      for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
      const blob = new Blob([bytes], { type: 'application/xml' });
      const url  = URL.createObjectURL(blob);
      const a    = document.createElement('a');
      a.href = url; a.download = filename;
      document.body.appendChild(a); a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    } catch(err) {
      alert('Errore download marca da bollo: ' + err.message);
    }
  });
})();
