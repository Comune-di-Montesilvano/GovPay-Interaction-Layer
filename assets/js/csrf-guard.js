/* CSRF guard backoffice: legge <meta name="csrf-token"> e lo aggiunge a form POST, fetch, XHR (jQuery incluso). */
(function () {
  'use strict';
  var meta = document.querySelector('meta[name="csrf-token"]');
  var token = meta ? meta.getAttribute('content') : '';
  if (!token) { return; }

  function sameOrigin(url) {
    try { return new URL(url, window.location.href).origin === window.location.origin; }
    catch (e) { return true; }
  }
  function isSafe(method) {
    return /^(GET|HEAD|OPTIONS)$/i.test(method || 'GET');
  }

  function injectField(form) {
    if (!form || String(form.method).toLowerCase() !== 'post') { return; }
    if (!sameOrigin(form.getAttribute('action') || window.location.href)) { return; }
    if (form.querySelector('input[name="csrf_token"]')) { return; }
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'csrf_token';
    input.value = token;
    form.appendChild(input);
  }

  document.addEventListener('submit', function (ev) { injectField(ev.target); }, true);

  // form.submit() programmatico non emette l'evento submit.
  var nativeSubmit = HTMLFormElement.prototype.submit;
  HTMLFormElement.prototype.submit = function () {
    injectField(this);
    return nativeSubmit.apply(this, arguments);
  };

  if (window.fetch) {
    var nativeFetch = window.fetch;
    window.fetch = function (input, init) {
      try {
        var req = (typeof Request !== 'undefined' && input instanceof Request) ? input : null;
        var url = req ? req.url : String(input);
        var method = (init && init.method) || (req && req.method) || 'GET';
        if (!isSafe(method) && sameOrigin(url)) {
          init = init || {};
          var headers = new Headers(init.headers || (req ? req.headers : undefined));
          if (!headers.has('X-CSRF-Token')) { headers.set('X-CSRF-Token', token); }
          init.headers = headers;
        }
      } catch (e) { /* lascia passare la richiesta originale */ }
      return nativeFetch.call(this, input, init);
    };
  }

  var nativeOpen = XMLHttpRequest.prototype.open;
  var nativeSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function (method, url) {
    this.__csrfMethod = method;
    this.__csrfUrl = url;
    return nativeOpen.apply(this, arguments);
  };
  XMLHttpRequest.prototype.send = function () {
    try {
      if (!isSafe(this.__csrfMethod) && sameOrigin(String(this.__csrfUrl))) {
        this.setRequestHeader('X-CSRF-Token', token);
      }
    } catch (e) { /* ignore */ }
    return nativeSend.apply(this, arguments);
  };
})();
