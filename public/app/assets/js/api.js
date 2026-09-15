/**
 * Zorvex | Network — API client.
 *
 * Forwards the Telegram web_app init data to the backend on every request so
 * the server can cryptographically validate the caller's identity.
 *
 * @package Zorvex
 */
window.ZorvexApi = (() => {
  'use strict';

  const BASE = window.ZORVEX_API_BASE || '';

  function initData() {
    if (window.Telegram && Telegram.WebApp && Telegram.WebApp.initData) {
      return Telegram.WebApp.initData;
    }
    // Outside the Telegram client (dev/preview) allow a hand-injected token.
    return window.ZORVEX_DEV_INIT_DATA || '';
  }

  async function request(method, path, body) {
    const headers = { Accept: 'application/json' };
    const data = initData();
    if (data) headers['X-Telegram-Init-Data'] = data;
    if (body) headers['Content-Type'] = 'application/json';

    const res = await fetch(BASE + path, {
      method,
      headers,
      body: body ? JSON.stringify(body) : undefined,
    });

    let json;
    try {
      json = await res.json();
    } catch (e) {
      throw new Error('پاسخ نامعتبر از سرور دریافت شد');
    }

    if (!res.ok || !json.ok) {
      const error = new Error((json && json.error) || 'خطای ارتباط با سرور');
      error.code = json ? json.code : res.status;
      throw error;
    }

    return json.data;
  }

  function get(path) {
    return request('GET', path);
  }

  function post(path, body) {
    return request('POST', path, body);
  }

  async function me() {
    return get('/api/me');
  }

  async function products() {
    return get('/api/products');
  }

  async function subscriptions() {
    return get('/api/subscriptions');
  }

  async function startPayment(productId, method) {
    return post('/api/payments/start', { product_id: productId, method });
  }

  async function verifyPayment(orderId) {
    return post('/api/payments/verify', { order_id: orderId });
  }

  async function paymentHistory() {
    return get('/api/payments/history');
  }

  return {
    get, post,
    me,
    products,
    subscriptions,
    startPayment,
    verifyPayment,
    paymentHistory,
    initData,
  };
})();