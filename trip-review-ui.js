'use strict';
function renderTripReview(page, anchor) {
  const section = document.createElement('section'); section.className = 'admin-accounts trip-review';
  section.innerHTML = '<div><p class="eyebrow">FREIGABE</p><h2>Importierte Reisen prüfen</h2><p>Reisen anderer Importberechtigter werden erst nach deiner Freigabe für ihre berechtigten Betrachter sichtbar.</p></div><div class="admin-account-list">Reisen werden geladen …</div><p role="status"></p>';
  anchor.before(section);
  const list = section.querySelector('.admin-account-list'), status = section.querySelector('[role="status"]'); let busy = false;
  const load = async () => {
    const response = await apiFetch('./api/api.php?action=trip-review', { credentials: 'same-origin', cache: 'no-store' });
    const data = await response.json(); if (!response.ok) throw Error(data.error || 'Freigaben konnten nicht geladen werden.');
    if (!page.isConnected) return; list.replaceChildren();
    if (!data.trips.length) { list.textContent = 'Keine Reisen warten auf Freigabe.'; return; }
    for (const trip of data.trips) {
      const row = document.createElement('div'); row.className = 'admin-account-row';
      const title = document.createElement('strong'); title.textContent = trip.title;
      const details = document.createElement('small'); details.textContent = 'Importiert von ' + (trip.importer_name || trip.importer_email) + ' · ' + trip.media_count + ' Medien';
      const preview = document.createElement('a'); preview.className = 'edit-link'; preview.href = '#trip/' + encodeURIComponent(trip.id); preview.textContent = 'REISE PRÜFEN';
      const approve = document.createElement('button'); approve.type = 'button'; approve.className = 'primary'; approve.textContent = 'REISE FREIGEBEN';
      approve.onclick = async () => {
        if (busy) return; busy = true; section.querySelectorAll('button').forEach(button => { button.disabled = true; });
        try {
          const response = await apiFetch('./api/api.php?action=trip-review', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Reise-Review-CSRF': data.csrf_token }, body: JSON.stringify({ trip: trip.id }) });
          const payload = await response.json(); if (!response.ok) throw Error(payload.error || 'Freigabe konnte nicht gespeichert werden.');
          status.textContent = payload.message;
          const cached = remoteTrips.find(record => String(record.id) === String(trip.id)); if (cached) cached.publication_status = 'approved';
        } catch (error) { status.textContent = error.message; }
        finally { busy = false; try { await load(); } catch (error) { status.textContent = error.message; } }
      };
      row.append(title, details, preview, approve); list.append(row);
    }
  };
  load().catch(error => { list.textContent = error.message; });
}
