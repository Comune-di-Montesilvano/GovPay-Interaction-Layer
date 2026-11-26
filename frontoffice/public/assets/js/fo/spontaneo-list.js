(function(){
  function initServiceSearch(){
    var input = document.getElementById('serviceSearch');
    if (!input) return;

    function safeLower(s){ return (s || '').toString().toLowerCase(); }
    function foldAccents(s){
      try { return s.normalize('NFD').replace(/[̀-ͯ]/g, ''); } catch(e){ return s; }
    }
    function tokenize(s){
      var x = foldAccents(safeLower(s)).trim().replace(/[^a-z0-9]+/g, ' ').trim();
      if (!x) return [];
      return x.split(/\s+/).filter(Boolean);
    }
    function bestScore(qt, idT, lblT, descT){
      var best = 0;
      lblT.forEach(function(t){ if(t===qt) best=Math.max(best,60); else if(t.indexOf(qt)===0) best=Math.max(best,50); });
      idT.forEach(function(t){ if(t===qt) best=Math.max(best,40); else if(t.indexOf(qt)===0) best=Math.max(best,30); });
      descT.forEach(function(t){ if(t===qt) best=Math.max(best,10); else if(t.indexOf(qt)===0) best=Math.max(best,8); });
      return best;
    }

    var tiles = Array.prototype.slice.call(document.querySelectorAll('#svc-grid [data-service-label]'));
    var emptyMsg = document.getElementById('svc-empty');
    var entries = tiles.map(function(tile, i){
      return {
        el: tile,
        origIdx: i,
        idT: tokenize(tile.getAttribute('data-service-id') || ''),
        lblT: tokenize(tile.getAttribute('data-service-label') || ''),
        descT: tokenize(tile.getAttribute('data-service-desc') || ''),
        lblSort: foldAccents(safeLower(tile.getAttribute('data-service-label') || '')).trim()
      };
    });

    function applyFilter(){
      var qT = tokenize(input.value || '');
      var grid = document.getElementById('svc-grid');
      if (!grid) return;
      var visibleCount = 0;

      entries.forEach(function(e){
        var score = 0, matched = 0;
        if (qT.length > 0) {
          qT.forEach(function(qt){
            if (qt.length < 2) return;
            var s = bestScore(qt, e.idT, e.lblT, e.descT);
            if (s > 0){ matched++; score += s; }
          });
          score = matched > 0 ? matched * 1000 + score : 0;
        }
        e._score = score;
        e._matched = matched;
      });

      if (qT.length === 0) {
        entries.sort(function(a,b){ return a.origIdx - b.origIdx; });
        entries.forEach(function(e){ e.el.style.display = ''; grid.appendChild(e.el); visibleCount++; });
      } else {
        var visible = entries.filter(function(e){ return e._score > 0; });
        var hidden = entries.filter(function(e){ return e._score === 0; });
        visible.sort(function(a,b){
          if (b._matched !== a._matched) return b._matched - a._matched;
          if (b._score !== a._score) return b._score - a._score;
          return a.lblSort < b.lblSort ? -1 : a.lblSort > b.lblSort ? 1 : a.origIdx - b.origIdx;
        });
        visible.forEach(function(e){ e.el.style.display = ''; grid.appendChild(e.el); });
        hidden.forEach(function(e){ e.el.style.display = 'none'; });
        visibleCount = visible.length;
      }

      if (emptyMsg) emptyMsg.style.display = visibleCount === 0 ? '' : 'none';
    }

    input.addEventListener('input', applyFilter);
    applyFilter();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initServiceSearch);
  } else {
    initServiceSearch();
  }
})();
