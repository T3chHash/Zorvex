/**
 * Zorvex | Network — lightweight client-side router (hash based).
 *
 * Routes:
 *   /            Home (products)
 *   /product/:id Product detail & purchase
 *   /services    My subscriptions
 *   /account     Profile, balance, payments history
 *   /support     Support contact
 *
 * @package Zorvex
 */
window.ZorvexRouter = (() => {
  'use strict';

  const routes = new Map();
  let current = null;

  function add(pattern, handler) {
    routes.set(pattern, handler);
  }

  function parse() {
    const hash = window.location.hash.replace(/^#/, '') || '/';
    return hash.startsWith('/') ? hash : '/' + hash;
  }

  function match(path) {
    // Explicit registered patterns first.
    for (const [pattern, handler] of routes) {
      if (pattern === '/**') continue; // catch-all handled last.

      const keys = [];
      const regex = pattern
        .replace(/:[^/]+/g, (m) => {
          keys.push(m.slice(1));
          return '([^/]+)';
        })
        .replace(/\//g, '\\/');
      const m = path.match(new RegExp('^' + regex + '$'));
      if (m) {
        const params = {};
        keys.forEach((k, i) => (params[k] = decodeURIComponent(m[i + 1])));
        return { handler, params };
      }
    }

    // Catch-all.
    const fallback = routes.get('/**');
    if (fallback) return { handler: fallback, params: {} };

    return { handler: () => {}, params: {} };
  }

  async function navigate(path) {
    if (window.location.hash !== '#' + path) {
      window.location.hash = path;
      return Promise.resolve(current); // hashchange triggers render
    }
    return render(path);
  }

  async function render(path) {
    const route = match(path || parse());
    current = path || parse();
    ZorvexState.set('route', current);
    const out = document.getElementById('view');
    if (!out) return;

    try {
      const html = await route.handler(route.params, out);
      if (typeof html === 'string') out.innerHTML = html;
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (e) {
      out.innerHTML = `<div class="error-state">
        <div>${e && e.message ? e.message : 'خطای غیرمنتظره'}</div>
        <button class="btn" onclick="location.reload()">تلاش مجدد</button>
      </div>`;
    }

    // Highlight active tab.
    document.querySelectorAll('#tabbar .tab').forEach((b) =>
      b.classList.toggle('active', b.dataset.route === normalize(path))
    );
  }

  function normalize(path) {
    if (path === '/product' || path.indexOf('/product/') === 0) return '/';
    return path;
  }

  function start() {
    window.addEventListener('hashchange', () => render());
    return render();
  }

  return { add, start, navigate, render, parse, match, current: () => current };
})();