// Frontend utilities
(function(){
  if (!window.bootstrap) {
    var activeModalStack = [];

    function closestModal(el) {
      while (el && el !== document.body) {
        if (el.classList && el.classList.contains('modal')) return el;
        el = el.parentElement;
      }
      return null;
    }

    class CompatModal {
      constructor(el) {
        this.el = el;
      }

      show() {
        if (!this.el) return;
        this.el.classList.add('show');
        this.el.style.display = 'block';
        this.el.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
        
        // Add backdrop if it doesn't exist
        var backdrop = document.querySelector('.modal-backdrop');
        if (!backdrop) {
          backdrop = document.createElement('div');
          backdrop.className = 'modal-backdrop fade show';
          document.body.appendChild(backdrop);
        }
      }

      hide() {
        if (!this.el) return;
        this.el.classList.remove('show');
        this.el.style.display = 'none';
        this.el.setAttribute('aria-hidden', 'true');
        
        // Remove backdrop
        var backdrop = document.querySelector('.modal-backdrop');
        if (backdrop) {
          backdrop.remove();
        }
        
        // Remove modal-open if no other modal is showing
        if (document.querySelectorAll('.modal.show').length === 0) {
          document.body.classList.remove('modal-open');
        }
      }

      static getOrCreateInstance(el) {
        if (!el._compatModal) {
          el._compatModal = new CompatModal(el);
        }
        return el._compatModal;
      }
    }

    class CompatToast {
      constructor(el, opts) {
        this.el = el;
        this.delay = (opts && opts.delay) || 4000;
        this._timer = null;
      }

      show() {
        if (!this.el) return;
        this.el.classList.add('show');
        clearTimeout(this._timer);
        this._timer = setTimeout(() => this.hide(), this.delay);
      }

      hide() {
        if (!this.el) return;
        this.el.classList.remove('show');
      }

      static getOrCreateInstance(el, opts) {
        if (!el._compatToast) {
          el._compatToast = new CompatToast(el, opts || {});
        }
        return el._compatToast;
      }
    }

    class CompatPopover {
      constructor(el) {
        this.el = el;
      }
    }

    window.bootstrap = {
      Modal: CompatModal,
      Toast: CompatToast,
      Popover: CompatPopover,
    };

    document.addEventListener('click', function(event) {
      var dismiss = event.target.closest('[data-bs-dismiss="modal"], [data-bs-dismiss="toast"]');
      if (!dismiss) return;
      var isToast = dismiss.getAttribute('data-bs-dismiss') === 'toast';
      if (isToast) {
        var toast = dismiss.closest('.toast');
        if (toast && toast._compatToast) {
          toast._compatToast.hide();
        } else if (toast) {
          toast.classList.remove('show');
        }
        return;
      }
      var modal = closestModal(dismiss);
      if (modal) {
        CompatModal.getOrCreateInstance(modal).hide();
      }
    });

    document.addEventListener('click', function(event) {
      var dismissAlert = event.target.closest('[data-bs-dismiss="alert"]');
      if (!dismissAlert) return;
      var alert = dismissAlert.closest('.alert');
      if (!alert) return;
      event.preventDefault();
      alert.remove();
    });

    document.addEventListener('click', function(event) {
      var modalToggle = event.target.closest('[data-bs-toggle="modal"]');
      if (!modalToggle) return;
      var target = modalToggle.getAttribute('data-bs-target') || modalToggle.getAttribute('href');
      if (!target || target.charAt(0) !== '#') return;
      var modal = document.querySelector(target);
      if (!modal) return;
      event.preventDefault();
      CompatModal.getOrCreateInstance(modal).show();
    });

    document.addEventListener('click', function(event) {
      var shownModal = event.target.closest('.modal.show');
      if (!shownModal) return;
      if (event.target === shownModal) {
        CompatModal.getOrCreateInstance(shownModal).hide();
      }
    });

    document.addEventListener('click', function(event) {
      var toggler = event.target.closest('[data-bs-toggle="collapse"]');
      if (!toggler) return;
      var target = toggler.getAttribute('data-bs-target') || toggler.getAttribute('href');
      if (!target || target.charAt(0) !== '#') return;
      var panel = document.querySelector(target);
      if (!panel) return;
      event.preventDefault();
      var willShow = !panel.classList.contains('show');
      panel.classList.toggle('show', willShow);
      toggler.setAttribute('aria-expanded', willShow ? 'true' : 'false');
    });

    document.addEventListener('keydown', function(event) {
      if (event.key !== 'Escape') return;
      var shown = document.querySelector('.modal.show');
      if (!shown) return;
      CompatModal.getOrCreateInstance(shown).hide();
    });
  }

  const onReady = function(){
  const body = document.body;

  const applySidebarState = function(isCollapsed) {
    if (!body.classList.contains('bo-shell-auth')) return;
    body.classList.toggle('bo-sidebar-collapsed', isCollapsed);
    const toggle = document.getElementById('boSidebarToggle');
    if (toggle) {
      toggle.setAttribute('aria-expanded', String(!isCollapsed));
    }
  };

  const closeSidebarMobile = function() {
    body.classList.remove('bo-sidebar-open');
  };

  const toggle = document.getElementById('boSidebarToggle');
  if (toggle && body.classList.contains('bo-shell-auth')) {
    try {
      const saved = localStorage.getItem('bo-sidebar-collapsed');
      if (saved === '1' && window.matchMedia('(min-width: 992px)').matches) {
        applySidebarState(true);
      }
    } catch (e) {
      // Ignore localStorage availability errors.
    }

    toggle.addEventListener('click', function(){
      if (window.matchMedia('(max-width: 991px)').matches) {
        body.classList.toggle('bo-sidebar-open');
        return;
      }

      const nextCollapsed = !body.classList.contains('bo-sidebar-collapsed');
      applySidebarState(nextCollapsed);
      try {
        localStorage.setItem('bo-sidebar-collapsed', nextCollapsed ? '1' : '0');
      } catch (e) {
        // Ignore localStorage availability errors.
      }
    });

    window.addEventListener('resize', function() {
      if (window.matchMedia('(min-width: 992px)').matches) {
        closeSidebarMobile();
      }
    });
  }

  document.querySelectorAll('[data-bo-sidebar-close]').forEach(function(el) {
    el.addEventListener('click', closeSidebarMobile);
  });

  document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
      closeSidebarMobile();
    }
  });

  const btn = document.getElementById('debug-button');
  if(btn){
    btn.addEventListener('click', function(e){
      e.preventDefault();
      const out = document.getElementById('pendenze-output');
      if(out) out.textContent = 'Eseguita azione di debug al ' + new Date().toLocaleString();
    });
  }

  // Smart header search
  (function() {
    var form  = document.getElementById('bo-smart-search');
    var input = document.getElementById('bo-smart-search-input');
    var badge = document.getElementById('bo-search-badge');
    if (!form || !input || !badge) return;

    var TYPES = {
      cf:       { label: 'CF',       cls: 'is-cf' },
      piva:     { label: 'P.IVA',    cls: 'is-piva' },
      iuv:      { label: 'IUV',      cls: 'is-iuv' },
      pendenza: { label: 'Pendenza', cls: 'is-pendenza' },
      flusso:   { label: 'Flusso',   cls: 'is-flusso' },
    };

    function detect(val) {
      var v = val.trim().toUpperCase();
      if (!v) return null;
      if (/^[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z]$/.test(v)) return 'cf';
      if (/^\d{11}$/.test(v)) return 'piva';
      if (/^\d{15,18}$/.test(v)) return 'iuv';
      if (/^GIL-[\w\-]+$/i.test(v) || /^\d{4}-\d+$/.test(v)) return 'pendenza';
      if (v.length >= 5 && (/[^A-Z0-9]/.test(v) || v.length > 16)) return 'flusso';
      return null;
    }

    function buildUrl(type, val) {
      var trimmed = val.trim();
      var encoded = (type === 'flusso' || type === 'pendenza')
        ? encodeURIComponent(trimmed)
        : encodeURIComponent(trimmed.toUpperCase());
      var map = {
        cf:     '/pendenze/ricerca?q=1&idDebitore=' + encoded,
        piva:   '/pendenze/ricerca?q=1&idDebitore=' + encoded,
        iuv:    '/pendenze/ricerca?q=1&iuv=' + encoded,
        pendenza: '/pendenze/ricerca?q=1&idPendenza=' + encoded,
        flusso: '/pagamenti/ricerca-flussi?q=1&idFlusso=' + encoded,
      };
      return map[type] || null;
    }

    function updateBadge(type) {
      badge.className = 'bo-search-badge';
      if (!type) {
        badge.textContent = '';
        return;
      }
      badge.textContent = TYPES[type].label;
      badge.classList.add('is-visible', TYPES[type].cls);
    }

    input.addEventListener('input', function() {
      updateBadge(detect(input.value));
    });

    form.addEventListener('submit', function(e) {
      e.preventDefault();
      var type = detect(input.value);
      if (!type) return;
      var url = buildUrl(type, input.value);
      if (url) window.location.href = url;
    });
  })();
  };

  // Reusable Premium DatePicker Helper with dropdowns, typing support, and quick ranges
  window.initBackofficeDatePicker = function(elDa, elA, hidDa, hidA) {
    if (!elDa || !elA || !hidDa || !hidA || typeof Litepicker === 'undefined') return;

    const toIt = (iso) => {
      if (!iso) return '';
      const [y, m, d] = iso.split('-');
      return d && m && y ? `${d}/${m}/${y}` : iso;
    };

    const formatDateIso = (date) => {
      const y = date.getFullYear();
      const m = String(date.getMonth() + 1).padStart(2, '0');
      const d = String(date.getDate()).padStart(2, '0');
      return y + '-' + m + '-' + d;
    };

    const parseItDate = (str) => {
      if (!str) return null;
      const match = str.trim().match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
      if (!match) return null;
      const d = parseInt(match[1], 10);
      const m = parseInt(match[2], 10) - 1;
      const y = parseInt(match[3], 10);
      const date = new Date(y, m, d);
      if (date.getFullYear() === y && date.getMonth() === m && date.getDate() === d) {
        return date;
      }
      return null;
    };

    if (hidDa.value) elDa.value = toIt(hidDa.value);
    if (hidA.value)  elA.value  = toIt(hidA.value);

    elDa.removeAttribute('readonly');
    elA.removeAttribute('readonly');

    const picker = new Litepicker({
      element: elDa,
      elementEnd: elA,
      singleMode: false,
      format: 'DD/MM/YYYY',
      lang: 'it-IT',
      autoApply: true,
      resetButton: true,
      numberOfMonths: 2,
      numberOfColumns: 2,
      dropdowns: {
        minYear: 2020,
        maxYear: new Date().getFullYear() + 2,
        months: true,
        years: true,
      },
      tooltipText: { one: 'giorno', other: 'giorni' },
      setup(p) {
        p.on('selected', (d1, d2) => {
          hidDa.value = d1.format('YYYY-MM-DD');
          hidA.value  = d2.format('YYYY-MM-DD');
        });
        p.on('clear:selection', () => {
          hidDa.value = '';
          hidA.value  = '';
          elDa.value  = '';
          elA.value   = '';
        });
      },
    });

    const syncFromInputs = () => {
      const d1 = parseItDate(elDa.value);
      const d2 = parseItDate(elA.value);
      if (d1 && d2) {
        picker.setDateRange(d1, d2);
        hidDa.value = formatDateIso(d1);
        hidA.value = formatDateIso(d2);
      } else if (d1) {
        picker.setDateRange(d1, d1);
        hidDa.value = formatDateIso(d1);
        hidA.value = formatDateIso(d1);
      }
    };

    elDa.addEventListener('blur', syncFromInputs);
    elA.addEventListener('blur', syncFromInputs);
    elDa.addEventListener('change', syncFromInputs);
    elA.addEventListener('change', syncFromInputs);

    window.setBackofficeQuickRange = function(type) {
      const today = new Date();
      let d1 = new Date();
      let d2 = new Date();

      switch(type) {
        case 'today':
          d1 = today;
          d2 = today;
          break;
        case 'yesterday':
          const yesterday = new Date();
          yesterday.setDate(today.getDate() - 1);
          d1 = yesterday;
          d2 = yesterday;
          break;
        case 'last7':
          const l7 = new Date();
          l7.setDate(today.getDate() - 6);
          d1 = l7;
          d2 = today;
          break;
        case 'last30':
          const l30 = new Date();
          l30.setDate(today.getDate() - 29);
          d1 = l30;
          d2 = today;
          break;
        case 'thisMonth':
          d1 = new Date(today.getFullYear(), today.getMonth(), 1);
          d2 = new Date(today.getFullYear(), today.getMonth() + 1, 0);
          break;
        case 'lastMonth':
          d1 = new Date(today.getFullYear(), today.getMonth() - 1, 1);
          d2 = new Date(today.getFullYear(), today.getMonth(), 0);
          break;
        case 'thisYear':
          d1 = new Date(today.getFullYear(), 0, 1);
          d2 = new Date(today.getFullYear(), 11, 31);
          break;
        case 'lastYear':
          d1 = new Date(today.getFullYear() - 1, 0, 1);
          d2 = new Date(today.getFullYear() - 1, 11, 31);
          break;
        case 'clear':
          picker.clearSelection();
          return;
      }

      elDa.value = toIt(formatDateIso(d1));
      elA.value = toIt(formatDateIso(d2));
      hidDa.value = formatDateIso(d1);
      hidA.value = formatDateIso(d2);
      picker.setDateRange(d1, d2);
    };

    return picker;
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', onReady);
  } else {
    onReady();
  }
})();
