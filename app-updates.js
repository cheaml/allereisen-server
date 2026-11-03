// Home-screen apps can resume an old live document even with no-store headers.
// Compare the entry page when returning; keep unsaved edits until they close.
(() => {
  const standalone = window.navigator.standalone || window.matchMedia('(display-mode: standalone)').matches;
  if (!standalone) return;
  const current = document.querySelector('script[src*="app.js?"]')?.getAttribute('src');
  if (!current) return;
  let checking = false, lastCheck = 0, pending = false, reloading = false;
  const editing = () => document.querySelector('dialog[open]') || document.activeElement?.matches('input, textarea, select, [contenteditable="true"]');
  const apply = () => {
    if (pending && !reloading && document.visibilityState === 'visible' && !editing()) {
      reloading = true;
      window.location.reload();
    }
  };
  const check = async () => {
    if (document.visibilityState !== 'visible') return;
    if (pending) { apply(); return; }
    if (checking || Date.now() - lastCheck < 30000) return;
    checking = true; lastCheck = Date.now();
    try {
      const response = await fetch('./index.html', { cache: 'no-store', credentials: 'same-origin' });
      if (!response.ok) return;
      const latest = new DOMParser().parseFromString(await response.text(), 'text/html')
        .querySelector('script[src*="app.js?"]')?.getAttribute('src');
      if (latest && latest !== current) { pending = true; apply(); }
    } catch { /* Retry on the next foreground check when the network returns. */ }
    finally { checking = false; }
  };
  window.addEventListener('pageshow', check);
  document.addEventListener('visibilitychange', check);
  document.addEventListener('close', apply, true);
  document.addEventListener('focusout', () => setTimeout(apply, 0));
  setInterval(check, 60000);
})();
