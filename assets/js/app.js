// Frontend utilities
(function(){
  const onReady = function(){
  const btn = document.getElementById('debug-button');
  if(btn){
    btn.addEventListener('click', function(e){
      e.preventDefault();
      const out = document.getElementById('pendenze-output');
      if(out) out.textContent = 'Eseguita azione di debug al ' + new Date().toLocaleString();
    });
  }
  };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', onReady);
  } else {
    onReady();
  }
})();
