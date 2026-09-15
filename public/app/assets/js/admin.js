/**
 * Zorvex | Network — admin panel controller.
 *
 * Sessionless admin access: token entered in the header is sent via
 * `X-Admin-Token` header, validated server-side on every API call.
 *
 * @package Zorvex
 */
(function () {
  'use strict';

  const BASE = window.ZORVEX_API_BASE || '';

  let tab = 'stats';

  function token() {
    return document.getElementById('admin-token').value.trim();
  }

  async function api(method, path, body) {
    const headers = { Accept: 'application/json' };
    const t = token();
    if (t) headers['X-Admin-Token'] = t;
    if (body) headers['Content-Type'] = 'application/json';

    const res = await fetch(BASE + path, {
      method,
      headers,
      body: body ? JSON.stringify(body) : undefined,
    });

    let json = {};
    try { json = await res.json(); } catch (e) { /* noop */ }

    if (!res.ok || !json.ok) {
      throw new Error(json.error || 'خطای سرور');
    }
    return json.data;
  }

  function toast(message, type) {
    const el = document.createElement('div');
    el.className = type === 'error' ? 'toast-err' : 'toast-ok';
    el.textContent = message;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 3000);
  }

  function badge(status) {
    if (status === 'active' || status === 'completed') return '<span class="badge ok">فعال</span>';
    if (status === 'pending') return '<span class="badge warn">در انتظار</span>';
    return '<span class="badge err">' + status + '</span>';
  }

  function money(n) {
    return Number(n || 0).toLocaleString('fa-IR');
  }

  async function renderStats() {
    const s = await api('GET', '/api/admin/stats');
    const grid = document.getElementById('stat-grid');
    const items = [
      ['👥 کاربران', money(s.users)],
      ['🖥 سرورها', money(s.servers)],
      ['📦 اشتراکها', money(s.subscriptions)],
      ['💰 درآمد (تومان)', money(s.revenue)],
    ];
    grid.innerHTML = items.map(([label, value]) =>
      `<div class="stat-card"><div class="label">${label}</div><div class="value">${value}</div></div>`
    ).join('');
  }

  async function renderServers() {
    const { servers } = await api('GET', '/api/admin/servers');
    const content = document.getElementById('admin-content');

    if (!servers.length) {
      content.innerHTML = '<div class="empty">سروری ثبت نشده است.</div>';
      return;
    }

    content.innerHTML = `
      <table>
        <thead><tr><th>نام</th><th>نوع</th><th>آدرس</th><th>وضعیت</th><th>عملیات</th></tr></thead>
        <tbody>
          ${servers.map(s => `
            <tr>
              <td>${s.name}</td>
              <td>${s.type}</td>
              <td dir="ltr">${s.url}</td>
              <td>${badge(s.status)}</td>
              <td>
                <button class="btn ghost" data-check="${s.id}">بررسی سلامت</button>
                <button class="btn ghost" data-del="${s.id}">حذف</button>
              </td>
            </tr>`).join('')}
        </tbody>
      </table>`;

    content.querySelectorAll('[data-check]').forEach((b) =>
      b.addEventListener('click', async () => {
        try {
          const { healthy } = await api('GET', '/api/admin/servers/' + b.dataset.check + '/health');
          toast(healthy ? 'سرور سالم است ✅' : 'سرور در دسترس نیست ❌', healthy ? 'ok' : 'error');
        } catch (e) { toast(e.message, 'error'); }
      })
    );

    content.querySelectorAll('[data-del]').forEach((b) =>
      b.addEventListener('click', async () => {
        if (!confirm('حذف سرور؟ اشتراکهای آن معلق میشود.')) return;
        try {
          await api('DELETE', '/api/admin/servers/' + b.dataset.del);
          toast('سرور حذف شد');
          renderServers();
        } catch (e) { toast(e.message, 'error'); }
      })
    );
  }

  async function renderUsers() {
    const { items, total } = await api('GET', '/api/admin/users?limit=100');
    const content = document.getElementById('admin-content');

    if (!items.length) {
      content.innerHTML = '<div class="empty">کاربری یافت نشد.</div>';
      return;
    }

    content.innerHTML = `
      <div class="label" style="color:var(--muted);margin-bottom:12px;">مجموع: ${money(total)}</div>
      <table>
        <thead><tr><th>ID تلگرام</th><th>نام</th><th>موجودی</th><th>وضعیت</th></tr></thead>
        <tbody>
          ${items.map(u => `
            <tr>
              <td dir="ltr">${u.telegram_id}</td>
              <td>${u.first_name} ${u.last_name} (${u.username ? '@' + u.username : '—'})</td>
              <td>${money(u.balance)}</td>
              <td>${badge(u.status)}</td>
            </tr>`).join('')}
        </tbody>
      </table>`;
  }

  async function renderPayments() {
    const { payments } = await api('GET', '/api/admin/payments/pending');
    const content = document.getElementById('admin-content');

    if (!payments.length) {
      content.innerHTML = '<div class="empty">پرداخت در انتظار بررسی ندارید. 🎉</div>';
      return;
    }

    content.innerHTML = `
      <table>
        <thead><tr><th>شناسه</th><th>مبلغ</th><th>روش</th><th>وضعیت</th><th>عملیات</th></tr></thead>
        <tbody>
          ${payments.map(p => `
            <tr>
              <td dir="ltr">${p.order_id}</td>
              <td>${money(p.amount)}</td>
              <td>${p.method}</td>
              <td>${badge(p.status)}</td>
              <td>
                <button class="btn" data-ok="${p.id}">تأیید</button>
                <button class="btn ghost" data-no="${p.id}">رد</button>
              </td>
            </tr>`).join('')}
        </tbody>
      </table>`;

    content.querySelectorAll('[data-ok]').forEach((b) =>
      b.addEventListener('click', async () => {
        try {
          await api('POST', '/api/admin/payments/' + b.dataset.ok + '/approve', {});
          toast('پرداخت تأیید شد');
          renderPayments();
        } catch (e) { toast(e.message, 'error'); }
      })
    );

    content.querySelectorAll('[data-no]').forEach((b) =>
      b.addEventListener('click', async () => {
        try {
          await api('POST', '/api/admin/payments/' + b.dataset.no + '/reject', {});
          toast('پرداخت رد شد');
          renderPayments();
        } catch (e) { toast(e.message, 'error'); }
      })
    );
  }

  async function render() {
    if (tab === 'stats') return renderStats();
    if (tab === 'servers') return renderServers();
    if (tab === 'users') return renderUsers();
    if (tab === 'payments') return renderPayments();
  }

  document.querySelectorAll('.admin-tabs button').forEach((btn) =>
    btn.addEventListener('click', () => {
      document.querySelectorAll('.admin-tabs button').forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');
      tab = btn.dataset.tab;
      render().catch((e) => toast(e.message, 'error'));
    })
  );

  document.getElementById('admin-token').addEventListener('input', () => {
    render().catch(() => { /* token not set yet */ });
  });

  render().catch((e) => toast(e.message, 'error'));
})();