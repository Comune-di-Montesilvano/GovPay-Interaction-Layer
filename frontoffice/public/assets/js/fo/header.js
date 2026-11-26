(function(){
  document.addEventListener('click', function (e) {
    document.querySelectorAll('details.lang-menu[open], details.fo-login-menu[open]').forEach(function (d) {
      if (!d.contains(e.target)) { d.removeAttribute('open'); }
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('details.lang-menu[open], details.fo-login-menu[open]').forEach(function (d) {
        d.removeAttribute('open'); d.querySelector('summary').focus();
      });
    }
  });
  var btn = document.getElementById('fo-user-btn');
  var menu = document.getElementById('fo-user-dropdown');
  if (btn && menu) {
    btn.addEventListener('click', function(e){
      e.stopPropagation();
      var open = menu.classList.toggle('open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      menu.setAttribute('aria-hidden', open ? 'false' : 'true');
    });
    document.addEventListener('click', function(){
      menu.classList.remove('open');
      btn.setAttribute('aria-expanded', 'false');
      menu.setAttribute('aria-hidden', 'true');
    });
  }

  // Hamburger menu toggle for mobile
  var navBtn = document.getElementById('nav-toggle-btn');
  var navMenu = document.getElementById('fo-nav-menu');
  if (navBtn && navMenu) {
    navBtn.addEventListener('click', function(e){
      e.stopPropagation();
      var open = navMenu.classList.toggle('open');
      navBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      navBtn.classList.toggle('active');
    });
    document.addEventListener('click', function(e){
      if (!navMenu.contains(e.target) && e.target !== navBtn && !navBtn.contains(e.target)) {
        navMenu.classList.remove('open');
        navBtn.classList.remove('active');
        navBtn.setAttribute('aria-expanded', 'false');
      }
    });
  }
})();
