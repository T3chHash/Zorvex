/**
 * Zorvex | Network — mini-app bootstrap.
 *
 * Initialises the Telegram web app, validates identity via /api/me, wires the
 * router, and binds the bottom navigation.
 *
 * @package Zorvex
 */
(async () => {
  'use strict';

  const tg = window.Telegram && Telegram.WebApp;

  if (tg) {
    tg.ready();
    tg.expand();
    tg.setHeaderColor('#0a0e1a');
    tg.setBackgroundColor('#0a0e1a');

    const theme = tg.colorScheme === 'light' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', theme);
  }

  // Fetch identity.
  let user = null;
  const chip = document.getElementById('balance-chip');
  const balanceValue = document.getElementById('balance-value');

  const refreshBalance = (u) => {
    if (!u) return;
    const fa = (n) => Number(n || 0).toLocaleString('fa-IR');
    balanceValue.textContent = fa(u.balance) + ' تومان';
    chip.style.display = 'flex';
  };

  try {
    const data = await window.ZorvexApi.me();
    user = data;
    ZorvexState.set('user', data);
    refreshBalance(data);
  } catch (e) {
    chip.style.display = 'none';
  }

  // Bind product action buttons after every router render.
  const bindAfterRender = () => {
    if (window.ZorvexPages && window.ZorvexPages.renderProduct) {
      window.ZorvexPages.__bindProduct && window.ZorvexPages.__bindProduct();
    }
  };

  // Wire bottom tabs.
  const tabs = document.querySelectorAll('#tabbar .tab');
  tabs.forEach((tab) =>
    tab.addEventListener('click', () => window.ZorvexRouter.navigate(tab.dataset.route))
  );

  // Start router.
  window.ZorvexRouter.start();

  // Re-bind dynamic buttons on navigation.
  window.addEventListener('hashchange', () => setTimeout(bindAfterRender, 50));

  // Keep balance fresh when the user returns to the account page.
  ZorvexState.on('route', async () => {
    if (ZorvexState.get('route') === '/account' && user) {
      try {
        const d = await window.ZorvexApi.me();
        ZorvexState.set('user', d);
        refreshBalance(d);
      } catch (e) { /* offline */ }
    }
  });
})();