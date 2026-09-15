/**
 * Zorvex | Network — page renderers for the client-side router.
 *
 * @package Zorvex
 */
(() => {
  'use strict';

  const fa = (n) => Number(n || 0).toLocaleString('fa-IR');

  function money(n) {
    return fa(n) + ' تومان';
  }

  function bytes(n) {
    const v = Number(n || 0);
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    let x = v;
    while (x >= 1024 && i < units.length - 1) { x /= 1024; i++; }
    return x.toFixed(x >= 100 || i === 0 ? 0 : 1) + ' ' + units[i];
  }

  function statusBadge(status) {
    const map = {
      active: '<span class="badge ok">فعال</span>',
      pending: '<span class="badge warn">در انتظار</span>',
      expired: '<span class="badge err">منقضی</span>',
      suspended: '<span class="badge err">معلق</span>',
      completed: '<span class="badge ok">موفق</span>',
      failed: '<span class="badge err">ناموفق</span>',
      cancelled: '<span class="badge neut">لغو شده</span>',
    };
    return map[status] || status;
  }

  // ---------------------------------------------------------------- Home
  async function renderHome() {
    if (!ZorvexState.get('products').length) {
      try {
        const { products } = await window.ZorvexApi.products();
        ZorvexState.set('products', products);
      } catch (e) { /* auth error shows in app bootstrap */ }
    }

    const products = ZorvexState.get('products');
    if (!products.length) {
      return `<div class="empty">در حال حاضر محصولی برای خرید نیست 🙏</div>`;
    }

    return `
      <h1 class="page-title">سرویس‌های Zorvex</h1>
      <p class="page-sub">سرعت بالا • اتصال پایدار • پشتیبانی ۲۴/۷</p>
      <div class="product-grid">
        ${products.map((p) => `
          <div class="product-card glass">
            <div class="product-head">
              <span class="product-name">${p.name}</span>
              <span class="product-server">سرور #${p.server_id}</span>
            </div>
            <div class="product-meta">
              <span>⏱ ${p.duration_days} روز</span>
              <span>📶 ${bytes(p.traffic)}</span>
            </div>
            <div class="product-price">${money(p.price)}</div>
            <button class="btn w-full" onclick="location.hash='#/product/${p.id}'">خرید</button>
          </div>`).join('')}
      </div>`;
  }

  // -------------------------------------------------------------- Product
  async function renderProduct(params) {
    const products = ZorvexState.get('products');
    const p = products.find((x) => String(x.id) === String(params.id));
    if (!p) return `<div class="empty">محصول یافت نشد.</div>`;

    const methods = [
      { id: 'crypto', label: '💎 کریپتو (USDT)' },
      { id: 'card_to_card', label: '🏦 کارت به کارت' },
      { id: 'zarinpal', label: '🌐 درگاه آنلاین' },
    ];

    return `
      <div class="product-detail glass">
        <div class="product-head">
          <span class="product-name">${p.name}</span>
          <button class="btn ghost small" onclick="location.hash='#/'">بازگشت</button>
        </div>
        <div class="product-meta">
          <span>⏱ ${p.duration_days} روز</span>
          <span>📶 ${bytes(p.traffic)}</span>
        </div>
        <div class="product-desc">${p.description || ''}</div>
        <div class="product-price big">${money(p.price)}</div>
        <div class="method-list">
          ${methods.map((m) => `
            <button class="method-card" data-product="${p.id}" data-method="${m.id}">
              ${m.label}
              <span class="chev">←</span>
            </button>`).join('')}
        </div>
      </div>`;
  }

  // function attached by app.js for startPayment
  window.ZorvexPages__bindProduct = () => {
    document.querySelectorAll('.method-card').forEach((card) =>
      card.addEventListener('click', async () => {
        const { product, method } = card.dataset;
        try {
          const { invoice } = await window.ZorvexApi.startPayment(Number(product), method);
          window.ZorvexApi
            ? window.location.hash = '#/pay/' + invoice.uuid + '/' + method
            : null;
        } catch (e) {
          alert(e.message || 'خطا در شروع پرداخت');
        }
      })
    );
  };

  // ---------------------------------------------------------------- Pay
  async function renderPay(params) {
    const uuid = params.uuid;
    const method = params.method;
    const label = { crypto: 'کریپتو (USDT)', card_to_card: 'کارت به کارت', zarinpal: 'درگاه آنلاین' }[method] || method;

    return `
      <div class="product-detail glass">
        <h1 class="page-title">پرداخت</h1>
        <p>روش: <b>${label}</b></p>
        <p>شناسه: <code dir="ltr">${uuid}</code></p>
        <div class="pay-actions">
          <button class="btn ghost" onclick="location.hash='#/'">بازگشت به خرید</button>
          <button class="btn" onclick="ZorvexState.set('pending_uuid','${uuid}');location.hash='#/account'">پیگیری در حساب</button>
        </div>
      </div>`;
  }

  // ------------------------------------------------------------ Services
  async function renderServices() {
    try {
      const { subscriptions } = await window.ZorvexApi.subscriptions();
      ZorvexState.set('subscriptions', subscriptions);
    } catch (e) { /* ignored */ }

    const list = ZorvexState.get('subscriptions');

    if (!list.length) {
      return `
        <h1 class="page-title">سرویس‌های من</h1>
        <div class="empty">هنوز سرویسی خریداری نکرده‌اید.</div>
        <button class="btn w-full" onclick="location.hash='#/'">همین حالا خرید</button>`;
    }

    return `
      <h1 class="page-title">سرویس‌های من</h1>
      <div class="sub-list">
        ${list.map((s) => `
          <div class="sub-card glass">
            <div class="sub-row">
              <span class="product-name">@${s.username}</span>
              ${statusBadge(s.status)}
            </div>
            <div class="sub-row muted">
              <span>🚀 استفاده: ${bytes(s.traffic_used)} از ${s.traffic_limit ? bytes(s.traffic_limit) : '∞'}</span>
              <span>${s.remaining_days >= 0 ? '⏳ ' + fa(s.remaining_days) + ' روز مانده' : '—'}</span>
            </div>
            ${s.traffic_limit ? `<div class="usage-bar"><div style="width:${s.traffic_used_percent || 0}%"></div></div>` : ''}
            ${s.config_link ? `<button class="btn small" onclick="location.href='${s.config_link}'">📜 دریافت کانفیگ</button>` : ''}
          </div>`).join('')}
      </div>`;
  }

  // ------------------------------------------------------------- Account
  async function renderAccount() {
    const user = ZorvexState.get('user');
    let payments = ZorvexState.get('payments');
    if (!payments.length) {
      try {
        const data = await window.ZorvexApi.paymentHistory();
        payments = data.payments || [];
        ZorvexState.set('payments', payments);
      } catch (e) { /* ignored */ }
    }

    const pending = ZorvexState.get('pending_uuid');

    return `
      <h1 class="page-title">حساب کاربری</h1>
      <div class="account-card glass">
        <div class="avatar">${(user.first_name || '؟').slice(0, 1) || '🙂'}</div>
        <div class="account-name">${user.first_name || ''} ${user.last_name || ''}</div>
        <div class="account-handle">${user.username ? '@' + user.username : ''}</div>
        <div class="balance-pill">💰 ${money(user.balance)}</div>
      </div>

      <h2 class="section-title">تراکنش‌های اخیر</h2>
      <div class="tx-list">
        ${payments.length ? payments.map((t) => `
          <div class="tx-item glass">
            <span>${t.order_id}</span>
            <span class="muted">${money(t.amount)}</span>
            ${statusBadge(t.status)}
          </div>`).join('') : '<div class="empty">تراکنشی ثبت نشده است.</div>'}
      </div>

      ${pending ? `<p class="muted">پرداخت در انتظار: <code dir="ltr">${pending}</code></p>` : ''}`;
  }

  // ------------------------------------------------------------ Support
  async function renderSupport() {
    return `
      <h1 class="page-title">پشتیبانی</h1>
      <div class="support-card glass">
        <p>برای رفع مشکل، دریافت راهنمایی یا پیگیری سفارش با تیم ما در ارتباط باشید.</p>
        <a class="btn support-btn" href="https://t.me/${window.ZORVEX_SUPPORT_USER || 'zorvex_support'}">گفتگو در تلگرام</a>
      </div>`;
  }

  function registerRoutes() {
    const router = window.ZorvexRouter;
    router.add('/product/:id', renderProduct);
    router.add('/pay/:uuid/:method', renderPay);

    router.add('/services', renderServices);
    router.add('/account', renderAccount);
    router.add('/support', renderSupport);

    // Home as catch-all fallback.
    router.add('/**', renderHome);
    router.add('/', renderHome);
  }

  // Expose helpers + registration.
  window.ZorvexPages = { renderHome, registerRoutes, renderProduct, statusBadge, money, bytes, fa };

  registerRoutes();
})();