'use strict';
async function renderMediaImporter({ app, access, go, onImported }) {
  if (!(access.isAdmin || access.canImport)) { go('#library'); return; }
  const api = window.AtlasApi;
  const page = document.createElement('section'); page.className = 'import-page media-import-page';
  page.innerHTML = `<form class="import-card"><p class="eyebrow">REISEARCHIV</p><h1>Medien <em>importieren.</em></h1>
    <label>Reisetitel<input name="title" maxlength="255" required autocomplete="off"></label>
    <label>Medienquelle<select name="provider"><option value="onedrive">OneDrive</option><option value="local">Lokale Dateien</option><option value="dropbox">Dropbox</option></select></label>
    <aside class="source-microsoft-help hint" aria-label="Microsoft-Freigabe für den Import"><strong>Vor dem ersten OneDrive-Import</strong><p>Wähle „ONEDRIVE VERBINDEN“, melde dich mit dem Microsoft-Konto des gewünschten OneDrive an und erlaube AlleReisen den angefragten Lesezugriff auf deine Dateien und für dich freigegebene Dateien.</p><p>Auch mit Microsoft 365 Family verbindet jedes Familienmitglied sein eigenes privates Microsoft-Konto. Falls Microsoft nach dem Kontotyp fragt, wähle „Persönliches Konto“. Bestätige anschließend die angefragten Berechtigungen für AlleReisen. Eine eigene App-Registrierung und eigene App-Zugangsdaten sind nicht nötig. Ordner anderer Familienmitglieder müssen zuerst ausdrücklich für dich in OneDrive freigegeben werden; das Family-Abo allein gibt darauf keinen Zugriff.</p></aside>
    <label>Reise<select name="trip"><option value="">Neue Reise anlegen</option></select></label>
    <label class="source-folder">Ordnerpfad<input name="folder" autocomplete="off"></label>
    <button class="secondary source-browse" type="button" hidden aria-expanded="false">ORDNER AUSWÄHLEN</button>
    <section class="source-folder-browser" aria-label="OneDrive-Ordner auswählen" hidden>
      <p class="source-browser-path" aria-label="Aktueller OneDrive-Pfad"></p>
      <p class="source-browser-status hint" role="status" aria-live="polite"></p>
      <ul class="source-folder-list" aria-label="OneDrive-Ordnerbaum"></ul>
      <div class="source-folder-actions"><button class="primary source-folder-use" type="button">DIESEN ORDNER VERWENDEN</button><button class="secondary source-folder-close" type="button">SCHLIESSEN</button></div>
    </section>
    <p class="source-hint hint"></p><button class="secondary source-connect" type="button" hidden>DROPBOX VERBINDEN</button>
    <p class="source-status" role="status" aria-live="polite">Medienquellen werden geladen …</p>
    <div class="import-actions"><button class="primary source-submit" disabled>IMPORT STARTEN</button><button class="secondary source-cancel" type="button">ZURÜCK ZUM ARCHIV</button></div>
  </form>`;
  app.replaceChildren(page); document.querySelector('#headerTitle').textContent = 'MEDIEN IMPORTIEREN';
  const form = page.querySelector('form'), fields = form.elements, status = page.querySelector('.source-status');
  const submit = page.querySelector('.source-submit'), connect = page.querySelector('.source-connect');
  const browseButton = page.querySelector('.source-browse'), browser = page.querySelector('.source-folder-browser');
  const folderList = page.querySelector('.source-folder-list'), browserStatus = page.querySelector('.source-browser-status');
  const useFolder = page.querySelector('.source-folder-use');
  let sources, csrf, running = false, browsePath = '', browseVersion = 0, browsing = false;
  const update = () => {
    const provider = fields.provider.value, source = sources.find(s => s.id === provider);
    page.querySelector('.source-microsoft-help').hidden = provider !== 'onedrive' || !!source.connected;
    page.querySelector('.source-folder').hidden = false;
    fields.folder.required = provider !== 'dropbox';
    fields.title.required = !fields.trip.value; fields.title.disabled = !!fields.trip.value;
    connect.hidden = provider === 'local' || !source.configured || (source.connected && provider !== 'onedrive');
    // Already connected OneDrive: the button only switches to another Microsoft account.
    connect.textContent = provider === 'onedrive' && source.connected ? 'ANDERES ONEDRIVE-KONTO VERBINDEN' : provider.toUpperCase() + ' VERBINDEN';
    submit.disabled = running || browsing || !source.configured || !source.connected;
    browseButton.hidden = provider !== 'onedrive';
    browseButton.disabled = running || !source.configured || !source.connected;
    page.querySelector('.source-hint').textContent = provider === 'local'
      ? 'Die Originale bleiben im ausgewählten Ordner auf diesem Gerät. Gespeichert werden Metadaten und JPEG-Vorschauen, auch für HEIC. Lokal ist in der lokalen Anwendung verfügbar.'
      : provider === 'onedrive' ? 'Wähle deinen Reiseordner über „ORDNER AUSWÄHLEN“ oder gib den Pfad ein. Geteilte Ordner erscheinen über ihre Verknüpfung unter „Meine Dateien“ in OneDrive.'
      : 'Dropbox-Ordner, zum Beispiel /Reisen/Japan. Ein leerer Pfad importiert aus dem Stammordner.';
    const cancelled = provider === 'onedrive' && new URLSearchParams(location.search).get('connection') === 'cancelled';
    status.textContent = !source.configured ? `${source.label} muss zuerst auf dem Server eingerichtet werden.` : cancelled ? 'Die OneDrive-Freigabe wurde nicht abgeschlossen. Bitte mit deinem eigenen persönlichen Microsoft-Konto anmelden, die angefragten Berechtigungen für AlleReisen bestätigen und OneDrive erneut verbinden. Dafür ist keine eigene App-Registrierung nötig.' : !source.connected ? `${source.label} ist noch nicht verbunden.` : '';
    fields.folder.placeholder = provider === 'local' ? 'C:\\Fotos\\Japan' : provider === 'dropbox' ? '/Reisen/Japan' : 'Reisen/Japan';
  };
  const closeBrowser = () => {
    browseVersion++; browsing = false; browser.hidden = true; browseButton.setAttribute('aria-expanded', 'false');
    if (sources) submit.disabled = running || !sources.find(s => s.id === fields.provider.value)?.connected;
  };
  const browse = async path => {
    const version = ++browseVersion;
    browsing = true; browser.hidden = false; browseButton.setAttribute('aria-expanded', 'true');
    submit.disabled = true; useFolder.disabled = true; folderList.replaceChildren(); browsePath = '';
    browserStatus.textContent = 'Ordner werden geladen …';
    const currentPath = page.querySelector('.source-browser-path'); currentPath.textContent = 'OneDrive';
    let selected;
    const active = () => page.isConnected && version === browseVersion && fields.provider.value === 'onedrive';
    const select = node => {
      if (!active()) return;
      selected?.summary.removeAttribute('aria-current'); selected = node;
      node.summary.setAttribute('aria-current', 'location'); browsePath = node.path;
      currentPath.textContent = node.path ? 'Ausgewählt: ' + node.path : 'OneDrive';
      useFolder.disabled = browsing || !browsePath;
    };
    const createNode = (name, folderPath, parent) => {
      const item = document.createElement('li'), details = document.createElement('details'), summary = document.createElement('summary');
      details.className = 'source-tree-node'; summary.className = 'source-tree-folder'; summary.textContent = name;
      const children = document.createElement('ul'); children.className = 'source-tree-children';
      const node = { path: folderPath, summary, details, children, nodes: [], loaded: false, pending: null };
      details.append(summary, children); item.append(details); parent.append(item);
      summary.addEventListener('click', () => select(node));
      details.addEventListener('toggle', () => { if (details.open && active()) { select(node); load(node); } });
      return node;
    };
    const load = async node => {
      if (node.loaded) return;
      if (node.pending) return node.pending;
      node.pending = (async () => {
        const note = document.createElement('li'); note.className = 'source-tree-note'; note.textContent = 'Ordner werden geladen …'; node.children.replaceChildren(note);
        node.details.setAttribute('aria-busy', 'true');
        try {
          const result = await api.json('onedrive-folders', { query: { folder: node.path } });
          if (!active()) return;
          node.children.replaceChildren(); node.nodes = result.folders.map(folder => createNode(folder.name, folder.path, node.children)); node.loaded = true;
          if (!node.nodes.length) { note.textContent = 'Keine Unterordner'; node.children.append(note); }
          browserStatus.textContent = 'Ordner aufklappen und den gewünschten Ordner verwenden.';
        } catch (error) {
          if (!active()) return;
          note.textContent = error.message + ' ';
          const retry = document.createElement('button'); retry.type = 'button'; retry.className = 'secondary'; retry.textContent = 'ERNEUT VERSUCHEN';
          retry.onclick = () => load(node); note.append(retry); browserStatus.textContent = error.message;
        } finally { node.details.removeAttribute('aria-busy'); node.pending = null; }
      })();
      return node.pending;
    };
    try {
      let node = createNode('OneDrive', '', folderList); node.details.open = true; await load(node);
      if (!node.loaded) return;
      for (const segment of path.split('/').filter(Boolean)) {
        if (!active()) return;
        const next = node.nodes.find(child => child.summary.textContent === segment);
        if (!next) { browserStatus.textContent = 'Bitte den gewünschten Ordner im Baum auswählen.'; break; }
        node = next; node.details.open = true; select(node); await load(node);
        if (!node.loaded) return;
      }
      if (active() && browsePath) selected?.summary.scrollIntoView({block: 'nearest'});
    } finally {
      if (page.isConnected && version === browseVersion) { browsing = false; useFolder.disabled = !browsePath; submit.disabled = running || !sources.find(s => s.id === fields.provider.value)?.connected; }
    }
  };
  browseButton.onclick = () => browse(fields.folder.value.trim().replaceAll('\\', '/').replace(/^\/+|\/+$/g, ''));
  useFolder.onclick = () => {
    if (browsing || !browsePath) return;
    fields.folder.value = browsePath;
    if (!fields.title.value) fields.title.value = browsePath.split('/').at(-1);
    closeBrowser(); fields.folder.focus();
  };
  page.querySelector('.source-folder-close').onclick = closeBrowser;
  page.querySelector('.source-cancel').onclick = () => { if (!running) go('#library'); };
  const beforeUnload = event => { if (running) { event.preventDefault(); event.returnValue = ''; } };
  window.addEventListener('beforeunload', beforeUnload);
  const observer = new MutationObserver(() => { if (!page.isConnected) { window.removeEventListener('beforeunload', beforeUnload); observer.disconnect(); } });
  observer.observe(app, { childList: true });
  try {
    const [capabilities, archive] = await Promise.all([api.json('media-sources'), api.json('trips')]);
    if (!page.isConnected) return;
    sources = capabilities.sources; if (!access.isAdmin) { sources = sources.filter(s => s.id === 'onedrive'); [...fields.provider.options].forEach(o => { if (o.value !== 'onedrive') o.remove(); }); } csrf = capabilities.csrf_token;
    for (const trip of archive.trips.filter(trip => Number(trip.can_import))) { const option = document.createElement('option'); option.value = trip.id; option.textContent = trip.title; fields.trip.append(option); }
    const requested = new URLSearchParams(location.search).get('source') || localStorage.getItem('atlas-media-source') || 'onedrive';
    fields.provider.value = sources.some(s => s.id === requested) ? requested : 'onedrive';
    fields.provider.onchange = () => { closeBrowser(); update(); };
    fields.trip.onchange = update;
    update();
    connect.onclick = async () => {
      connect.disabled = true;
      try {
        const response = await api.fetch(`./api/${fields.provider.value}.php`, { method: 'POST', headers: { 'X-Atlas-CSRF': csrf } });
        const data = await response.json(); if (!response.ok) throw new Error(data.error);
        location.assign(data.url);
      } catch (error) { status.textContent = error.message; connect.disabled = false; }
    };
    form.onsubmit = async event => {
      event.preventDefault(); if (running || browsing) return;
      closeBrowser();
      const provider = fields.provider.value;
      running = true; [...fields].forEach(field => field.disabled = true); status.textContent = 'Import läuft …';
      localStorage.setItem('atlas-media-source', provider);
      try {
        let record;
          const body = { title: fields.title.value, source_folder: fields.folder.value.trim(), trip: fields.trip.value };
          let result = await api.json(provider === 'local' ? 'local-import' : provider === 'dropbox' ? 'dropbox-import' : 'import', { method: 'POST', csrf, body });
          while (result.pending) { status.textContent = `Aufnahmedaten prüfen: ${result.total - result.remaining} von ${result.total}`; result = await api.json('import', { method: 'POST', csrf, body: { ...body, import_token: result.import_token } }); }
          record = result.trip;
          if(record?.publication_status==='pending')alert('Die Reise wurde importiert und wartet auf Freigabe durch den Hauptadmin. Bis dahin können nur du und die Hauptadmins sie ansehen.');
        if (page.isConnected) onImported(record);
      } catch (error) { status.textContent = error.message; }
      finally { running = false; [...fields].forEach(field => field.disabled = false); fields.title.disabled = !!fields.trip.value; }
    };
  } catch (error) { status.textContent = error.message; }
}
