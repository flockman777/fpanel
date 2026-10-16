(function () {
  function ready(f) { document.readyState !== 'loading' ? f() : document.addEventListener('DOMContentLoaded', f); }
  ready(function () {
    // theme
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-theme-toggle]');
      if (!b) return;
      var dark = document.documentElement.classList.toggle('dark');
      localStorage.setItem('fpanel.theme', dark ? 'dark' : 'light');
    });
    // dropdown
    document.addEventListener('click', function (e) {
      var t = e.target.closest('[data-dd]');
      document.querySelectorAll('[data-dd-menu]').forEach(function (m) {
        if (!t || m !== t.nextElementSibling) m.hidden = true;
      });
      if (t) { var m = t.nextElementSibling; if (m && m.hasAttribute('data-dd-menu')) m.hidden = !m.hidden; }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') document.querySelectorAll('[data-dd-menu]').forEach(function (m) { m.hidden = true; });
    });
    // confirmation for destructive actions
    document.addEventListener('submit', function (e) {
      var f = e.target;
      if (f.dataset.confirm && !window.confirm(f.dataset.confirm)) e.preventDefault();
    });
    // show/hide password
    var pwBtn = document.querySelector('[data-pw-toggle]');
    if (pwBtn) {
      pwBtn.addEventListener('click', function () {
        var input = document.querySelector('[data-pw]');
        if (!input) return;
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        pwBtn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      });
    }

    // global search: features, domains, and the user's own databases
    var sForm = document.querySelector('[data-search]');
    if (sForm) {
      var sInput = sForm.querySelector('[data-search-input]');
      var sMenu  = sForm.querySelector('[data-search-menu]');
      var sList  = sForm.querySelector('[data-search-results]');
      var sEmpty = sForm.querySelector('[data-search-empty]');
      var timer = null, seq = 0, items = [];

      var esc = function (t) {
        return String(t).replace(/[&<>"']/g, function (c) {
          return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
      };

      var close = function () { sMenu.hidden = true; };

      var paint = function (list) {
        items = list;
        if (!list.length) {
          sList.innerHTML = '';
          sEmpty.hidden = false;
          sMenu.hidden = false;
          return;
        }
        sEmpty.hidden = true;
        sList.innerHTML = list.map(function (r, i) {
          return '<li><a href="' + esc(r.href) + '" data-si="' + i + '" class="flex items-center gap-3 px-3 py-2 hover:bg-slate-50 dark:hover:bg-slate-800">' +
            '<span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">' +
            '<i data-lucide="' + esc(r.icon) + '" class="h-4 w-4"></i></span>' +
            '<span class="min-w-0"><span class="block truncate text-sm font-medium">' + esc(r.title) + '</span>' +
            '<span class="block truncate text-xs text-slate-500">' + esc(r.sub) + '</span></span></a></li>';
        }).join('');
        sMenu.hidden = false;
        if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
      };

      sInput.addEventListener('input', function () {
        var q = sInput.value.trim();
        clearTimeout(timer);
        if (q.length < 2) { close(); return; }
        var mine = ++seq;
        timer = setTimeout(function () {
          fetch('/api/search?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (mine === seq) paint(d.results || []); })
            .catch(function () { close(); });
        }, 140);
      });

      sInput.addEventListener('focus', function () { if (sInput.value.trim().length >= 2) sMenu.hidden = false; });
      sInput.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { close(); sInput.blur(); return; }
        if (!items.length) return;
        var cur = sForm.querySelector('[data-si].bg-slate-100, [data-si].dark\:bg-slate-800');
        var idx = cur ? parseInt(cur.getAttribute('data-si'), 10) : -1;
        var next = null;
        if (e.key === 'ArrowDown') next = Math.min(items.length - 1, idx + 1);
        if (e.key === 'ArrowUp')   next = Math.max(0, idx - 1);
        if (next === null) return;
        e.preventDefault();
        var links = sForm.querySelectorAll('[data-si]');
        links.forEach(function (a) { a.className = 'flex items-center gap-3 px-3 py-2 hover:bg-slate-50 dark:hover:bg-slate-800'; });
        links[next].className = 'flex items-center gap-3 px-3 py-2 bg-slate-100 dark:bg-slate-800';
        links[next].scrollIntoView({ block: 'nearest' });
      });

      document.addEventListener('click', function (e) { if (!sForm.contains(e.target)) close(); });
      document.addEventListener('keydown', function (e) {
        if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)) {
          e.preventDefault();
          sInput.focus();
        }
      });
    }

    // navigation sidebar (mobile)
    var nav = document.getElementById('fp-nav');
    var backdrop = document.querySelector('[data-nav-backdrop]');
    if (nav && backdrop) {
      var setNav = function (open) {
        nav.classList.toggle('-translate-x-full', !open);
        backdrop.hidden = !open;
        document.body.classList.toggle('overflow-hidden', open);
        var btn = document.querySelector('[data-nav-open]');
        if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      };
      var openBtn = document.querySelector('[data-nav-open]');
      var closeBtn = document.querySelector('[data-nav-close]');
      if (openBtn) openBtn.addEventListener('click', function () { setNav(true); });
      if (closeBtn) closeBtn.addEventListener('click', function () { setNav(false); });
      backdrop.addEventListener('click', function () { setNav(false); });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setNav(false); });
    }
    // hide the "PHP version" row when the type is not php
    var sel = document.querySelector('[data-type-select]');
    var phpRow = document.querySelector('[data-php-row]');
    if (sel && phpRow) {
      var sync = function () { phpRow.style.display = sel.value === 'php' ? '' : 'none'; };
      sel.addEventListener('change', sync);
      sync();
    }
  });
})();
