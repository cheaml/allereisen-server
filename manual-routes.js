'use strict';
(() => {
  const catalogs = new Map();
  const editableMaps = new WeakSet();
  let editor = null;
  const point = value => {
    if (!Array.isArray(value) || value.length !== 2 || value.some(n => typeof n !== 'number' || !Number.isFinite(n)) || Math.abs(value[0]) > 90 || Math.abs(value[1]) > 180) throw new Error('Ungültige Routenposition.');
    return value.map(n => Math.round(n * 1e7) / 1e7);
  };
  const key = (from, to) => JSON.stringify([point(from), point(to)]);
  async function request(trip, body) {
    const response = await window.AtlasApi.fetch('./api/api.php?action=manual-routes' + (body ? '' : '&trip=' + encodeURIComponent(trip)), {
      credentials: 'same-origin', cache: 'no-store', signal: AbortSignal.timeout(15000),
      ...(body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ trip, ...body }) } : {})
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Die Strecke konnte nicht gespeichert werden.');
    return data;
  }
  function load(trip) {
    if (!catalogs.has(trip)) {
      const pending = request(trip).then(data => new Map((data.routes || []).map(route => [key(route.from, route.to), route.points])));
      catalogs.set(trip, pending);
      pending.catch(() => { if (catalogs.get(trip) === pending) catalogs.delete(trip); });
    }
    return catalogs.get(trip);
  }
  async function resolve(trip, from, to, automatic) {
    try {
      const routes = await load(trip), saved = routes.get(key(from, to));
      if (saved) return saved;
    } catch { /* Existing automatic connections remain available during an outage. */ }
    return automatic();
  }
  async function save(trip, from, to, points) {
    from = point(from); to = point(to);
    if (points !== null) {
      if (!Array.isArray(points) || points.length < 2 || points.length > 200) throw new Error('Bitte höchstens 198 Zwischenpunkte verwenden.');
      points = points.map(point);
      if (JSON.stringify(points[0]) !== JSON.stringify(from) || JSON.stringify(points.at(-1)) !== JSON.stringify(to)) throw new Error('Start und Ziel müssen an den Stationen bleiben.');
    }
    await request(trip, { from, to, points });
    // Update the local catalog too, so existing maps immediately use the correction.
    const routes = await load(trip).catch(() => new Map());
    if (points === null) routes.delete(key(from, to)); else routes.set(key(from, to), points);
    catalogs.set(trip, Promise.resolve(routes));
    window.dispatchEvent(new CustomEvent('atlas-route-changed', { detail: { trip } }));
  }
  async function draft(trip,from,to) {
    const saved=(await load(trip)).get(key(from,to));
    return (saved||[point(from),point(to)]).map(p=>[...p]);
  }
  function bind({ map, L, group, segment, from, to, tripId, refresh }) {
    if (!editableMaps.has(map)) {
      editableMaps.add(map);
      const hint = L.control({ position: 'bottomleft' });
      hint.onAdd = () => { const node = document.createElement('div'); node.className = 'manual-route-hint'; node.textContent = 'Verbindungsstrecke anklicken, um den Verlauf zu bearbeiten'; L.DomEvent.disableClickPropagation(node); return node; };
      hint.addTo(map);
    }
    const hit = L.polyline([from, to], { weight: 18, opacity: 0, bubblingMouseEvents: false }).addTo(group);
    hit.bindTooltip('Verbindungsstrecke bearbeiten', { sticky: true });
    const changed = event => { if (event.detail.trip === tripId) refresh(); };
    window.addEventListener('atlas-route-changed', changed);
    const open = async () => {
      const saved = (await load(tripId).catch(() => new Map())).get(key(from, to));
      if (!map.hasLayer(group)) return;
      editor?.close();
      // Automatic routing vertices are not user waypoints. Start a new edit
      // with the two neighboring stations; retain only saved manual points.
      let draft = (saved||[from,to]).map(p=>[...p]);
      if (draft.length > 200) draft = draft.filter((_, i) => i === 0 || i === draft.length - 1 || i % Math.ceil(draft.length / 198) === 0);
      draft[0] = [...from]; draft[draft.length - 1] = [...to];
      if (draft.length < 2) draft = [[...from], [...to]];
      const handles = L.layerGroup().addTo(map);
      const line = L.polyline(draft, { color: '#d5f054', weight: 5, bubblingMouseEvents: false }).addTo(map);
      const panel = L.control({ position: 'topright' });
      const container = document.createElement('section'); container.className = 'manual-route-editor';
      const heading = document.createElement('h2'); heading.textContent = 'Verbindungsstrecke bearbeiten';
      const hint = document.createElement('p'); hint.textContent = 'Karte anklicken: Zwischenpunkt setzen. Punkte ziehen: Verlauf ändern. Zwischenpunkt anklicken: entfernen. Start und Ziel bleiben fest.';
      const status = document.createElement('p'); status.setAttribute('role', 'status');
      const actions = document.createElement('div'); actions.className = 'manual-route-actions';
      let busy = false, closed = false;
      const button = (label, action) => { const node = document.createElement('button'); node.type = 'button'; node.textContent = label; node.onclick = action; actions.append(node); return node; };
      const close = () => {
        if (closed) return; closed = true;
        map.off('click', insert); map.off('unload', close); group.off('remove', close);
        handles.remove(); line.remove(); panel.remove();
        if (editor?.close === close) editor = null;
      };
      const commit = async reset => {
        if (busy) return; busy = true; actions.querySelectorAll('button').forEach(b => b.disabled = true);
        status.textContent = 'Strecke wird gespeichert …';
        try { await save(tripId, from, to, reset ? null : draft); close(); }
        catch (error) { if (!closed) status.textContent = error.message; }
        finally { busy = false; if (!closed) actions.querySelectorAll('button').forEach(b => b.disabled = false); }
      };
      button('SPEICHERN', () => commit(false));
      button('ABBRECHEN', close);
      button('AUTOMATISCHE STRECKE WIEDERHERSTELLEN', () => commit(true));
      container.append(heading, hint, actions, status); panel.onAdd = () => container; panel.addTo(map);
      L.DomEvent.disableClickPropagation(container); L.DomEvent.disableScrollPropagation(container);
      const redraw = () => {
        handles.clearLayers(); line.setLatLngs(draft);
        draft.forEach((position, index) => {
          const fixed = index === 0 || index === draft.length - 1;
          const marker = L.marker(position, { draggable: !fixed, bubblingMouseEvents: false, zIndexOffset: 20000,
            title: fixed ? index === 0 ? 'Startstation (fest)' : 'Zielstation (fest)' : 'Zwischenpunkt ziehen oder zum Entfernen anklicken',
            icon: L.divIcon({ className: 'manual-route-handle' + (fixed ? ' fixed' : ''), iconSize: [18, 18], iconAnchor: [9, 9] }) }).addTo(handles);
          if (!fixed) {
            marker.on('drag', () => { if (busy) return; const p = marker.getLatLng(); draft[index] = [p.lat, p.lng]; line.setLatLngs(draft); });
            marker.on('click', () => { if (busy) return; draft.splice(index, 1); redraw(); });
          }
        });
        status.textContent = (draft.length - 2) + ' Zwischenpunkte';
      };
      const insert = event => {
        if (busy || draft.length >= 200) return;
        const p = map.latLngToLayerPoint(event.latlng); let distance = Infinity, after = 0;
        for (let i = 0; i < draft.length - 1; i++) {
          const d = L.LineUtil.pointToSegmentDistance(p, map.latLngToLayerPoint(draft[i]), map.latLngToLayerPoint(draft[i + 1]));
          if (d < distance) { distance = d; after = i; }
        }
        draft.splice(after + 1, 0, [event.latlng.lat, event.latlng.lng]); redraw();
      };
      map.on('click', insert); map.once('unload', close); group.once('remove', close); redraw(); editor = { close };
    };
    hit.on('click', open);
    const element = hit.getElement();
    if (element) {
      element.setAttribute('tabindex', '0'); element.setAttribute('role', 'button'); element.setAttribute('aria-label', 'Verbindungsstrecke bearbeiten');
      element.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); open(); } });
    }
    const cleanup = () => { window.removeEventListener('atlas-route-changed', changed); };
    map.once('unload', cleanup);
    group.once('remove', cleanup);
    const controls=window.ReiseNativeUI||window.ReiseWebUI;
    if(String(controls?.pendingRouteEditTripId)===String(tripId)){delete controls.pendingRouteEditTripId;open().catch(error=>alert(error.message));}
    return { update: points => hit.setLatLngs(points) };
  }
  window.AtlasManualRoutes = Object.freeze({ resolve, save, bind, draft });
})();
