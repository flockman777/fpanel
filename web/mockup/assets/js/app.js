/* fpanel mockup - client runtime (dummy data only, does not call bin/f-*) */
(function () {
  "use strict";

  const BASE = (document.currentScript && document.currentScript.dataset.base) || "";

  /* render icons: use the trimmed bundle (assets/js/icons.js), fall back to full lucide */
  const paintIcons = () => {
    const f = window.fpanelIcons || window.lucide;
    f && f.createIcons && f.createIcons();
  };

  const Theme = {
    mode: localStorage.getItem("fpanel.theme") || "light",
    init() { this.apply(); },
    apply() {
      document.documentElement.classList.toggle("dark", this.mode === "dark");
      document.documentElement.style.colorScheme = this.mode;
    },
    toggle() {
      this.mode = this.mode === "dark" ? "light" : "dark";
      localStorage.setItem("fpanel.theme", this.mode);
      this.apply();
      paintIcons();
    },
  };

  const I18n = {
    lang: localStorage.getItem("fpanel.lang") || "en",
    dict: {},
    fallback: {},
    ready: false,

    async load(lang) {
      this.lang = lang;
      localStorage.setItem("fpanel.lang", lang);
      document.documentElement.lang = lang;
      try {
        this.dict = await this._fetch(BASE + "i18n/" + lang + ".json");
        this.fallback = lang === "en" ? {} : await this._fetch(BASE + "i18n/en.json");
      } catch (e) {
        this.dict = {};
      }
      this.ready = true;
      this.apply();
      paintIcons();
    },

    async _fetch(url) {
      const r = await fetch(url, { cache: "no-cache" });
      if (!r.ok) throw new Error("i18n load failed: " + url);
      return r.json();
    },

    t(key, vars) {
      let s = this.dict[key] != null ? this.dict[key] : this.fallback[key];
      if (s == null) return key;
      if (vars) for (const k in vars) s = s.split("{" + k + "}").join(vars[k]);
      return s;
    },

    /* fill elements with data-i18n* attributes (static in HTML) */
    apply(root) {
      const sel = (a) => (root || document).querySelectorAll(a);
      sel("[data-i18n]").forEach((el) => (el.textContent = this.t(el.dataset.i18n)));
      sel("[data-i18n-html]").forEach((el) => (el.innerHTML = this.t(el.dataset.i18nHtml)));
      sel("[data-i18n-placeholder]").forEach((el) => el.setAttribute("placeholder", this.t(el.dataset.i18nPlaceholder)));
      sel("[data-i18n-title]").forEach((el) => (el.setAttribute("title", this.t(el.dataset.i18nTitle))));
      sel("[data-i18n-aria]").forEach((el) => (el.setAttribute("aria-label", this.t(el.dataset.i18nAria))));
    },

    set(lang) { if (lang !== this.lang) this.load(lang); },
  };

  /* search store: client-side feature filter from window.FPANEL_FEATURES */
  const Search = {
    q: "",
    open: false,
    features() { return window.FPANEL_FEATURES || []; },
    results() {
      const q = this.q.trim().toLowerCase();
      if (!q) return [];
      const I = Alpine.store("i18n");
      return this.features()
        .map((f) => ({ href: f.href, icon: f.icon, label: f.tile, category: f.cat, plain: f }))
        .map((f) => ({ ...f, hay: (I.t(f.label) + " " + I.t(f.category) + " " + f.label).toLowerCase() }))
        .filter((f) => f.hay.indexOf(q) !== -1)
        .slice(0, 8)
        .map(({ hay, ...rest }) => rest);
    },
  };

  /* The Alpine CDN build runs start() in a queueMicrotask, so alpine:init may
     have passed before app.js (defer) runs. Boot is made idempotent + fallback. */
  let booted = false;
  function boot() {
    if (booted || !window.Alpine) return;
    booted = true;
    Alpine.store("search", Search);
    Alpine.store("admin", { open: false });
    Alpine.store("theme", Theme);
    Alpine.store("i18n", I18n);
    /* call via the store proxy so Alpine reactivity stays in sync */
    const i18n = Alpine.store("i18n");
    const theme = Alpine.store("theme");
    theme.init();
    i18n.load(i18n.lang);
    document.documentElement.classList.add("fpanel-ready");
    paintIcons();
  }

  function onReady() {
    boot();
    paintIcons();
  }

  document.addEventListener("alpine:init", boot);
  if (window.Alpine) queueMicrotask(boot);
  if (document.readyState === "complete") onReady();
  else document.addEventListener("DOMContentLoaded", onReady);
  window.addEventListener("load", onReady);
})();
