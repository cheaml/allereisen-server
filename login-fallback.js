document.addEventListener('click', event => {
  const register = event.target.closest('#showRegister');
  if (register) {
    event.preventDefault(); event.stopImmediatePropagation();
    const form = document.querySelector('#loginForm'), enabled = form.dataset.mode !== 'register';
    form.dataset.mode = enabled ? 'register' : 'login';
    document.querySelector('#registerName').hidden = !enabled;
    register.textContent = enabled ? 'Zurück zur Anmeldung' : 'KONTO ERSTELLEN';
    form.querySelector('.primary').textContent = enabled ? 'KONTO ERSTELLEN' : 'ANMELDEN';
    return;
  }
  if (!event.target.closest('#loginButton')) return;
  event.preventDefault(); event.stopImmediatePropagation();
  document.querySelector('#loginDialog')?.showModal();
}, true);

document.addEventListener('submit', event => {
  const form = event.target.closest('#loginForm');
  if (!form) return;
  event.preventDefault(); event.stopImmediatePropagation();
  const note = document.querySelector('#loginNote');
  fetch('./api/auth.php', {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: form.dataset.mode === 'register' ? 'register' : 'login', email: document.querySelector('#loginEmail').value, password: document.querySelector('#loginPassword').value, display_name: document.querySelector('#loginName').value })
  }).then(async response => {
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || 'Anmeldung nicht möglich.');
    location.reload();
  }).catch(error => { if (note) note.textContent = error.message; });
}, true);
