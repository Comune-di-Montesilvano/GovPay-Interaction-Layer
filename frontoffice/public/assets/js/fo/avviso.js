(function(){
  const cf = document.getElementById('codiceFiscale');
  if(!cf) return;
  const up = function(){ cf.value = (cf.value || '').toUpperCase(); };
  cf.addEventListener('input', up);
  cf.addEventListener('blur', up);
})();
