/**
 * Zorvex | Network — client-side state management.
 *
 * @package Zorvex
 */
window.ZorvexState = (() => {
  'use strict';

  const listeners = new Map();
  const state = {
    user: null,
    products: [],
    subscriptions: [],
    payments: [],
    loading: false,
    route: '/',
    telegram: null,
    initDataVerified: false,
  };

  function set(key, value) {
    if (state[key] === value) return;
    state[key] = value;
    emit(key, value);
  }

  function get(key) {
    return state[key];
  }

  function on(key, callback) {
    if (!listeners.has(key)) listeners.set(key, new Set());
    listeners.get(key).add(callback);
    return () => listeners.get(key).delete(callback);
  }

  function emit(key, value) {
    const set = listeners.get(key);
    if (set) set.forEach((cb) => cb(value, state));
  }

  function store(key, value) {
    try {
      localStorage.setItem('zorvex.' + key, JSON.stringify(value));
    } catch (e) { /* private mode */ }
  }

  function load(key) {
    try {
      const raw = localStorage.getItem('zorvex.' + key);
      return raw ? JSON.parse(raw) : null;
    } catch (e) {
      return null;
    }
  }

  return { get, set, on, store, load, state };
})();