'use strict';
function renderImportPermissions(page, anchor) {
  const section = document.createElement('section'); section.className = 'admin-accounts';
  section.innerHTML = '<div><p class="eyebrow">VERWALTUNG</p><h2>Importrechte</h2><p>Microsoft-Benutzer dürfen mit Importrecht neue Reisen aus eigenen oder für sie freigegebenen OneDrive-Ordnern anlegen und ihre Reisen ergänzen. Administratoren besitzen dieses Recht über ihre Adminrolle.</p></div><div class="admin-account-list">Benutzer werden geladen …</div><p role="status"></p>';
  anchor.after(section);
  const list = section.querySelector('.admin-account-list'), status = section.querySelector('[role="status"]');
  let busy = false;
  const load = async () => {
    const response = await apiFetch('./api/api.php?action=import-permissions', { credentials: 'same-origin', cache: 'no-store' });
    const data = await response.json(); if (!response.ok) throw Error(data.error || 'Importrechte konnten nicht geladen werden.');
    if (!page.isConnected) return;
    list.replaceChildren();
    for (const account of data.accounts) {
      const row = document.createElement('div'); row.className = 'admin-account-row';
      const label = document.createElement('span'); label.textContent = (account.display_name || account.email) + ' · ' + account.email;
      if(account.microsoft_connected!==undefined&&!Number(account.microsoft_connected))label.textContent+=' · Importrecht vorgemerkt; Microsoft-Anmeldung ausstehend';
      const enabled = !!Number(account.is_admin) || !!Number(account.can_import);
      const button = document.createElement('button'); button.type = 'button'; button.className = 'edit-link';
      button.textContent = Number(account.is_admin) ? 'AKTIV DURCH ADMINROLLE' : enabled ? 'IMPORTRECHT ENTZIEHEN' : 'IMPORTRECHT ERTEILEN';
      button.disabled = !data.can_manage || !!Number(account.is_admin); button.setAttribute('aria-label', button.textContent + ': ' + account.email);
      button.onclick = async () => {
        if (busy) return; busy = true; section.querySelectorAll('button').forEach(b => { b.disabled = true; });
        try {
          const result = await apiFetch('./api/api.php?action=import-permissions', { method: 'PATCH', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Reise-Import-CSRF': data.csrf_token }, body: JSON.stringify({ user_id: Number(account.id), can_import: !enabled }) });
          const payload = await result.json(); if (!result.ok) throw Error(payload.error || 'Importrecht konnte nicht gespeichert werden.');
          status.textContent = payload.message;
        } catch (error) { status.textContent = error.message; }
        finally { busy = false; try { await load(); } catch (error) { status.textContent = error.message; } }
      };
      row.append(label, button); list.append(row);
    }
  };
  load().catch(error => { list.textContent = error.message; });
}
