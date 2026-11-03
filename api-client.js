'use strict';
(() => {
  class ApiError extends Error {
    constructor(message, status = 0) { super(message); this.name = 'ApiError'; this.status = status; }
  }
  async function request(url, options = {}) {
    const target = new URL(url, location.href);
    const isApi = target.origin === location.origin && /\/api\/(?:api|auth|dropbox|onedrive)\.php$/.test(target.pathname);
    const headers = new Headers(options.headers);
    if (isApi && typeof options.body === 'string' && !headers.has('Content-Type')) headers.set('Content-Type', 'application/json');
    try {
      return await fetch(url, { ...(isApi ? { credentials: 'same-origin', cache: 'no-store' } : {}), ...options, headers });
    } catch (error) {
      if (error.name === 'AbortError') throw error;
      throw new ApiError('Die Verbindung ist unterbrochen. Bitte erneut versuchen.');
    }
  }
  async function json(action, { method = 'GET', query = {}, body, csrf, signal } = {}) {
    const params = new URLSearchParams({ action, ...query });
    const headers = csrf ? { 'X-Atlas-CSRF': csrf } : {};
    const response = await request('./api/api.php?' + params, { method, headers, signal, ...(body === undefined ? {} : { body: body instanceof FormData ? body : JSON.stringify(body) }) });
    let payload;
    try { payload = await response.json(); } catch { throw new ApiError('Der Server hat keine gültige Antwort geliefert.', response.status); }
    if (!response.ok) throw new ApiError(payload.error || `Anfrage fehlgeschlagen (HTTP ${response.status}).`, response.status);
    return payload;
  }
  window.AtlasApi = Object.freeze({ fetch: request, json, ApiError });
})();
