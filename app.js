const apiFetch = (...args) => window.AtlasApi.fetch(...args);
const defaultStops=[['osaka','Osaka','10. — 11. April','10. APR','Ankommen, eintauchen, treiben lassen. Zwischen den dampfenden Garküchen von Dōtonbori und den ruhigen Gassen von Nakazakichō bekam die Reise ihren ersten, lebhaften Puls.',['1590559899731-a382839e5549','1545569341-9eb8b30979d9','1528164344705-47542687000d']],['nara','Nara','12. April','12. APR','Ein langsamer Tag zwischen Zedern, alten Hallen und neugierigen Hirschen. Der Kasuga-Taisha-Schrein im Regen fühlte sich an wie eine kleine Zeitreise.',['1528360983277-13d401cdc186','1490806843957-31f4c9a91c65','1542051841857-5f90071e7989']],['kyoto','Kyoto','14. — 18. April','14 — 18. APR','Fünf Tage, die nach Matcha, Regen und Zedernholz schmeckten. Morgens in Arashiyama, abends durch Gion: Kyoto zeigte sich jeden Tag in einer anderen, stilleren Farbe.',['1493976040374-85c8e12f0c0e','1524413840807-0c3cb6fa808d','1526481280695-3c687fd5432c']],['hakone','Hakone','19. April','19. APR','Nebel über dem See, eine Fahrt mit der Seilbahn und immer wieder der Blick zum Fuji. In Hakone wurde aus dem Tempo der Städte plötzlich Weite.',['1490806843957-31f4c9a91c65','1570459027562-4a916cc6113f','1530789253388-582c481c54b0']],['tokio','Tokio','20. — 24. April','20 — 24. APR','Der letzte Abschnitt: Neon, kleine Bars und lange Spaziergänge ohne Ziel. Tokio war laut, präzise und überraschend persönlich — ein perfekter letzter Akkord.',['1503899036084-c55cdd92da26','1540959733332-eab4deabeeaf','1513407030348-c983a97b98d8']]];
const app=document.querySelector('#app'),image=id=>/^(?:\.\/|https?:)/.test(id)?id:`https://images.unsplash.com/photo-${id}?auto=format&fit=crop&w=2000&q=90`,tripsKey='atlas-trips';


const baseTrip={id:'',title:'',folder:'',date:'',media:0,stops:[],cover:'1524413840807-0c3cb6fa808d'};let access={loggedIn:null,isAdmin:false};
;;
async function eraseTrip(id){let trip=trips().find(t=>t.id===id);if(!trip||!confirm(`„${trip.title}“ wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.`))return;let button=document.querySelector(`[data-delete-trip="${id}"]`);if(button){button.disabled=true;button.textContent='WIRD GELÖSCHT …'};try{if(trip.serverId){let r=await apiFetch('./api/api.php?action=trip',{method:'DELETE',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:trip.serverId})});if(!r.ok)throw new Error('Die Reisedoku konnte auf dem Server nicht gelöscht werden.')}localSave(JSON.parse(localStorage.getItem(tripsKey)||'[]').filter(t=>t.id!==id));remoteTrips=remoteTrips.filter(t=>t.id!==id);render()}catch(error){if(button){button.disabled=false;button.textContent='REISE LÖSCHEN'}alert(error.message)}}
let leafletLoader;function loadLeaflet(){if(window.L)return Promise.resolve(window.L);if(leafletLoader)return leafletLoader;leafletLoader=new Promise((resolve,reject)=>{let css=document.createElement('link');css.rel='stylesheet';css.href='https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';document.head.append(css);let script=document.createElement('script');script.src='https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';script.onload=()=>resolve(window.L);script.onerror=reject;document.head.append(script)});return leafletLoader}

// Named coastal features are separate from the satellite tiles and route markers.
let coastalGazetteerPromise;
function loadCoastalGazetteer() {
  if (!coastalGazetteerPromise) coastalGazetteerPromise = apiFetch('./coastal-labels.json?v=20260927-de-2')
    .then(response => { if (!response.ok) throw new Error('Ortsnamen konnten nicht geladen werden.'); return response.json(); })
    .then(data => data.features.map(([id, name, lat, lon, kind, german]) => ({ type: 'geonames', id, lat, lon,
      tags: kind === 'bay' ? { name, 'name:de': german, natural: 'bay' } : { name, 'name:de': german, place: kind } })))
    .catch(error => { coastalGazetteerPromise = null; throw error; });
  return coastalGazetteerPromise;
}
let regionalLabelIndex;
const regionalLabelTiles = new Map();
async function loadRegionalLabels(bounds) {
  if (!regionalLabelIndex) regionalLabelIndex = apiFetch('./map-labels/index.json?v=20261023-precision-3')
    .then(response => { if (!response.ok) throw new Error('Ortsnamen fehlen'); return response.json(); })
    .then(data => ({ ...data, available: new Set(data.tiles) }))
    .catch(error => { regionalLabelIndex = null; throw error; });
  const index = await regionalLabelIndex, keys = new Set(), step = index.gridSize;
  const west = ((bounds.getWest() + 180) % 360 + 360) % 360 - 180;
  const east = west + Math.min(360, bounds.getEast() - bounds.getWest());
  const regions = east > 180 ? [[west, 180], [-180, east - 360]] : [[west, east]];
  for (let y = Math.floor(Math.max(-90, bounds.getSouth()) / step); y <= Math.floor(Math.min(89.99999, bounds.getNorth()) / step); y++) {
    for (const [w, e] of regions) for (let x = Math.floor(w / step); x <= Math.floor(Math.min(179.99999, e) / step); x++) {
      const key = `${y}_${x}`;
      if (index.available.has(key)) keys.add(key);
    }
  }
  const results = await Promise.allSettled([...keys].map(key => {
    if (!regionalLabelTiles.has(key)) regionalLabelTiles.set(key, apiFetch(`./map-labels/${key}.json?v=20261023-precision-3`)
      .then(response => { if (!response.ok) throw new Error('Ortsnamen fehlen'); return response.json(); })
      .catch(error => { regionalLabelTiles.delete(key); throw error; }));
    return regionalLabelTiles.get(key);
  }));
  const points = new Map(), rivers = new Map();
  for (const result of results) {
    if (result.status !== 'fulfilled') continue;
    for (const [id, name, lat, lon, kind, german, minZoom, importance, maxZoom] of result.value.features) {
      points.set(id, { type: 'geonames', id, lat, lon, kind, minZoom, importance, maxZoom,
        tags: { name, 'name:de': german } });
    }
    for (const [id, name, german, minZoom, segments] of result.value.rivers) {
      if (!rivers.has(id)) rivers.set(id, { type: 'naturalearth', id, kind: 'river', minZoom, segments: [], tags: { name, 'name:de': german } });
      rivers.get(id).segments.push(...segments);
    }
    for(const feature of preciseMapLabels(result.value.precise||[]))points.set(feature.type+':'+feature.id,feature);
  }
  while (regionalLabelTiles.size > 64) regionalLabelTiles.delete(regionalLabelTiles.keys().next().value);
  return [...points.values(), ...rivers.values()];
}
// Choose a point on the visible river course, including segments crossing the
// whole viewport. A river's mouth alone would leave inland views unlabeled.
function riverLabelPosition(segments, bounds, centerLng) {
  let best = null, longest = -1;
  for (const [ax, ay, bx, by] of segments) {
    const shift = 360 * Math.round((centerLng - (ax + bx) / 2) / 360);
    const x = ax + shift, dx = bx - ax, dy = by - ay;
    let start = 0, end = 1, outside = false;
    for (const [p, q] of [[-dx, x - bounds.getWest()], [dx, bounds.getEast() - x], [-dy, ay - bounds.getSouth()], [dy, bounds.getNorth() - ay]]) {
      if (p === 0) { if (q < 0) outside = true; continue; }
      const ratio = q / p;
      if (p < 0) start = Math.max(start, ratio); else end = Math.min(end, ratio);
    }
    if (outside || start > end) continue;
    const length = (end - start) * Math.hypot(dx * Math.cos(ay * Math.PI / 180), dy);
    if (length > longest) {
      longest = length;
      const middle = (start + end) / 2;
      best = { lat: ay + dy * middle, lon: x + dx * middle };
    }
  }
  return best;
}
// An Overpass bounding-box centre need not be inside a lake. Join complete
// rings, then choose an interior water interval, excluding island holes.
function waterLabelPosition(paths) {
  const same = (a,b) => a[0]===b[0] && a[1]===b[1];
  const pending=paths.filter(p=>p.length>=2).map(p=>p.map(v=>[...v])), rings=[];
  while(pending.length){
    const ring=pending.pop();
    while(!same(ring[0],ring.at(-1))){
      const end=ring.at(-1),index=pending.findIndex(p=>same(end,p[0])||same(end,p.at(-1)));
      if(index<0)return null; // Never invent a closing edge across missing geometry.
      const next=pending.splice(index,1)[0];if(!same(end,next[0]))next.reverse();ring.push(...next.slice(1));
    }
    if(ring.length<4)return null;
    for(let i=1;i<ring.length;i++)ring[i][0]+=360*Math.round((ring[i-1][0]-ring[i][0])/360);
    rings.push(ring);
  }
  if(!rings.length)return null;
  const origin=rings[0][0][0];for(const ring of rings){const shift=360*Math.round((origin-ring[0][0])/360);for(const p of ring)p[0]+=shift;}
  const ys=rings.flatMap(r=>r.map(p=>p[1])),low=Math.min(...ys),high=Math.max(...ys);
  let best=null,width=0;
  for(let row=0;row<25;row++){
    const lat=low+(high-low)*(row+.5)/25, crossings=[];
    for(const ring of rings)for(let i=1;i<ring.length;i++){const a=ring[i-1],b=ring[i];if((a[1]>lat)!==(b[1]>lat))crossings.push(a[0]+(lat-a[1])*(b[0]-a[0])/(b[1]-a[1]));}
    crossings.sort((a,b)=>a-b);
    for(let i=1;i<crossings.length;i+=2){const length=crossings[i]-crossings[i-1];if(length>width){width=length;best={lat,lon:(crossings[i]+crossings[i-1])/2};}}
  }
  return best;
}
function preciseMapLabels(elements) {
  const result=[];
  for(const item of elements){
    const tags=item.tags||{},name=tags['name:de']||tags.name;if(!name)continue;
    const valid=p=>p&&Number.isFinite(p.lat)&&Number.isFinite(p.lon)&&Math.abs(p.lat)<=90&&Math.abs(p.lon)<=180;
    const peak=tags.natural==='peak'||tags.natural==='volcano',city=/^(city|town|village|hamlet)$/.test(tags.place||'');
    if(item.type==='node'&&valid(item)&&(peak||city)){result.push({...item,type:'osm-'+item.type,kind:peak?'mountain':'city',minZoom:12,maxZoom:22});continue;}
    if(tags.natural!=='water'&&!/^(river|stream)$/.test(tags.waterway||''))continue;
    const geometry=item.type==='way'?[item.geometry]:item.type==='relation'?(item.members||[]).filter(m=>m.type==='way').map(m=>m.geometry):[];
    if(!geometry.length||geometry.some(p=>!Array.isArray(p)||p.some(v=>!valid(v)))||geometry.reduce((n,p)=>n+p.length,0)>50000)continue;
    const paths=geometry.map(p=>p.map(v=>[v.lon,v.lat]));
    if(tags.natural==='water'){
      const position=waterLabelPosition(paths);if(position)result.push({type:'osm-'+item.type,id:item.id,tags,kind:'lake',minZoom:12,maxZoom:22,...position});
    }else{
      const segments=paths.flatMap(p=>p.slice(1).map((b,i)=>[...p[i],...b]));
      result.push({type:'osm-'+item.type,id:item.id,tags,kind:'river',minZoom:tags.waterway==='stream'?14:12,maxZoom:22,segments});
    }
  }
  return result;
}
function addCoastalLabels(map, L) {
  const pane = map.createPane('coastalLabels');
  pane.style.zIndex = '450';
  pane.style.pointerEvents = 'none';
  const layer = L.layerGroup().addTo(map);
  const cache = new Map();
  let gazetteer = [], gazetteerLoading = false, regionalFeatures = [];
  let timer, controller, version = 0, disposed = false, moving = false, failures = 0;
  const knownFeatures = () => {
    const features = new Map(gazetteer.map(feature => [feature.type + ':' + feature.id, feature]));
    for (const feature of regionalFeatures) features.set(feature.type + ':' + feature.id, feature);
    for (const entry of cache.values()) for (const feature of entry.elements) features.set(feature.type + ':' + feature.id, feature);
    return [...features.values()];
  };
  map.attributionControl?.addAttribution('Ortsnamen &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>');
  map.attributionControl?.addAttribution('<a href="https://www.geonames.org/">GeoNames</a> (CC BY 4.0)');
  map.attributionControl?.addAttribution('<a href="https://www.naturalearthdata.com/">Natural Earth</a>');
  function draw(elements) {
    layer.clearLayers();
    const occupied = [], names = [];
    const zoom = map.getZoom(), size = map.getSize();
    const bounds = map.getBounds(), centerLng = map.getCenter().lng;
    // Filter before projecting and sorting the gazetteer, especially on phones.
    const visible = elements.map(feature => feature.segments ? { ...feature, center: riverLabelPosition(feature.segments, bounds, centerLng) } : feature).filter(feature => {
      const kind = feature.kind || feature.tags?.natural || feature.tags?.place;
      const minZoom = feature.minZoom ?? (kind === 'bay' ? 15 : kind === 'islet' ? 12 : 9);
      // Gazetteer points and generalized river lines are overview data.
      // At street/detail scale omit unverified overview labels rather than imply precision.
      const overview=feature.type==='geonames'||feature.type==='naturalearth';
      if (overview && zoom > (feature.maxZoom===22?22:Math.min(feature.maxZoom??11,11))) return false;
      if (zoom < minZoom || feature.segments && !feature.center) return false;
      const position = feature.center || feature;
      const lng = position.lon + 360 * Math.round((centerLng - position.lon) / 360);
      return position.lat >= bounds.getSouth() && position.lat <= bounds.getNorth() && lng >= bounds.getWest() && lng <= bounds.getEast();
    });
    // Give major cities and islands priority; smaller features fill free space.
    const priority = feature => feature.kind === 'city' && feature.importance >= 100000 ? 0 : feature.tags?.place === 'island' ? 1 : feature.kind === 'city' ? 2 : feature.segments ? 3 : 4;
    const sorted = visible.sort((a, b) => priority(a) - priority(b) || (a.minZoom ?? 9) - (b.minZoom ?? 9) || (b.importance || 0) - (a.importance || 0));
    for (const feature of sorted) {
      const tags = feature.tags || {}, bay = tags.natural === 'bay';
      const kind = feature.kind || (bay ? 'bay' : tags.place);
      if (bay && zoom < 15 || tags.place === 'islet' && zoom < 12) continue;
      const name = tags['name:de'] || tags.name;
      const position = feature.center || feature;
      if (!name || !Number.isFinite(position.lat) || !Number.isFinite(position.lon)) continue;
      const lng = position.lon + 360 * Math.round((map.getCenter().lng - position.lon) / 360);
      const latlng = L.latLng(position.lat, lng), point = map.latLngToContainerPoint(latlng);
      if (point.x < 0 || point.y < 0 || point.x > size.x || point.y > size.y) continue;
      const label = `${kind === 'mountain' ? '△ ' : kind === 'city' ? '• ' : ''}${name}`;
      const width = Math.min(240, label.length * 8 + 16), height = 36;
      const box = { left: point.x - width / 2, right: point.x + width / 2, top: point.y - height / 2, bottom: point.y + height / 2 };
      if (occupied.some(b => box.left < b.right && box.right > b.left && box.top < b.bottom && box.bottom > b.top)) continue;
      if (names.some(n => n.name === name && n.point.distanceTo(point) < 120)) continue;
      const text = document.createElement('span');
      text.textContent = label;
      L.marker(latlng, { pane: 'coastalLabels', interactive: false, keyboard: false,
        icon: L.divIcon({ className: `coastal-label coastal-label-${kind || 'island'}`, html: text, iconSize: [width, height], iconAnchor: [width / 2, height / 2] })
      }).addTo(layer);
      occupied.push(box); names.push({ name, point });
    }
  }
  async function update(requestVersion) {
    if (disposed || controller || !map.getContainer().isConnected || map.getZoom() < 9) return;
    const bounds = map.getBounds(), bays = map.getZoom() >= 15, detail=map.getZoom()>=12, streams=map.getZoom()>=14;
    const south = Math.max(-85, bounds.getSouth()), north = Math.min(85, bounds.getNorth());
    const west = ((bounds.getWest() + 180) % 360 + 360) % 360 - 180;
    const east = west + Math.min(360, bounds.getEast() - bounds.getWest());
    const regions = east > 180 ? [[west, 180], [-180, east - 360]] : [[west, east]];
    const boxes = regions.map(([w, e]) => `${south.toFixed(5)},${w.toFixed(5)},${north.toFixed(5)},${e.toFixed(5)}`);
    const key = `${bays}:${detail}:${streams}:${boxes.join(';')}`;
    // Reuse a loaded larger viewport when zooming in.
    const covered = [...cache.values()].some(entry => (!bays || entry.bays) && (!detail||entry.detail) && (!streams||entry.streams) && south >= entry.south && north <= entry.north && regions.every(([w, e]) => entry.regions.some(([cw, ce]) => w >= cw && e <= ce)));
    if (covered) { draw(knownFeatures()); return; }
    const queries = boxes.map(box => `nwr["place"~"^(island|islet)$"]["name"](${box});${bays ? `nwr["natural"="bay"]["name"](${box});` : ''}`).join('');
    const preciseQueries=detail?boxes.map(box=>`node["natural"~"^(peak|volcano)$"]["name"](${box});node["place"~"^(city|town|village|hamlet)$"]["name"](${box});wr["natural"="water"]["name"](${box});way["waterway"~"^(${streams?'river|stream':'river'})$"]["name"](${box});`).join(''):'';
    const request = new AbortController(); controller = request;
    const timeout = setTimeout(() => request.abort(), 25000);
    try {
      const response = await apiFetch('https://overpass-api.de/api/interpreter', { method: 'POST', body: new URLSearchParams({ data: `[out:json][timeout:20];(${queries});out center;${detail?`(${preciseQueries});out geom;`:''}` }), signal: request.signal });
      if (!response.ok) throw new Error('Ortsnamen: HTTP ' + response.status);
      const data = await response.json();
      if (!Array.isArray(data.elements) || data.remark) throw new Error('Unvollständige Ortsnamen');
      if (disposed || !map.getContainer().isConnected) return;
      const coast=data.elements.filter(e=>/^(island|islet)$/.test(e.tags?.place||'')||e.tags?.natural==='bay');
      cache.set(key, { elements: [...coast,...preciseMapLabels(data.elements)], bays, detail, streams, south, north, regions });
      failures = 0;
      if (cache.size > 24) cache.delete(cache.keys().next().value);
      if (!moving && map.getZoom() >= 9) draw(knownFeatures());
    } catch (error) {
      // Keep loaded labels during outages and retry with bounded backoff.
      failures++;
      if (error.name !== 'AbortError') console.debug('Ortsnamen derzeit nicht verfügbar.');
    } finally {
      clearTimeout(timeout); controller = null;
      if (!disposed && !moving && map.getContainer().isConnected && map.getZoom() >= 9) {
        if (requestVersion !== version) timer = setTimeout(() => update(version), failures ? 30000 : 500);
        else if (failures > 0 && failures <= 2) timer = setTimeout(() => update(version), failures * 30000);
      }
    }
  }
  function schedule() {
    clearTimeout(timer); version++; moving = false;
    if (map.getZoom() < 7) { layer.clearLayers(); return; }
    draw(knownFeatures());
    const regionalVersion = version;
    loadRegionalLabels(map.getBounds()).then(features => {
      if (disposed || moving || regionalVersion !== version || !map.getContainer().isConnected) return;
      regionalFeatures = features;
      draw(knownFeatures());
    }).catch(() => {});
    // Load the locally hosted gazetteer independently of the optional live service.
    if (!gazetteer.length && !gazetteerLoading) {
      gazetteerLoading = true;
      loadCoastalGazetteer().then(features => {
        gazetteer = features;
        if (!disposed && !moving && map.getContainer().isConnected && map.getZoom() >= 9) draw(knownFeatures());
      }).catch(() => {}).finally(() => { gazetteerLoading = false; });
    }
    if (map.getZoom() >= 9 && !controller && failures <= 2) timer = setTimeout(() => update(version), failures ? 30000 : 500);
  }
  map.on('movestart', () => { clearTimeout(timer); moving = true; version++; });
  map.on('moveend', schedule);
  map.on('unload', () => { disposed = true; clearTimeout(timer); controller?.abort(); cache.clear(); });
  map.whenReady(schedule);
}

async function openStopMedia(tripId,stopId,title){let dialog=document.createElement('dialog'),card=document.createElement('section'),close=document.createElement('button'),heading=document.createElement('h2'),list=document.createElement('div');card.style.cssText='background:#f4f0e8;padding:32px;max-width:560px';close.textContent='×';close.style.cssText='float:right;border:0;background:none;font-size:28px;cursor:pointer';heading.textContent=title;list.textContent='Medien werden geladen …';close.onclick=()=>dialog.close();card.append(close,heading,list);dialog.append(card);dialog.addEventListener('close',()=>dialog.remove());document.body.append(dialog);dialog.showModal();try{let response=await apiFetch(`./api/api.php?action=media&trip=${encodeURIComponent(tripId)}&stop=${encodeURIComponent(stopId)}`,{credentials:'same-origin'}),data=await response.json();if(!response.ok)throw new Error();list.replaceChildren(...(data.media||[]).map(media=>{let link=document.createElement('a');link.href=media.web_url||'#';link.target='_blank';link.rel='noopener';link.textContent=`${media.media_type==='video'?'VIDEO':'FOTO'} · ${media.name}`;link.style.cssText='display:block;padding:12px 0;border-top:1px solid #d8d4ca;color:#26473e';return link}));if(!data.media?.length)list.textContent='Für diese Station sind keine Medien gespeichert.'}catch{list.textContent='Die Medien konnten nicht geladen werden.'}}
function stopTimeLabel(stop){if(!stop.starts_at)return '';let date=new Date(stop.starts_at.replace(' ','T'));if(Number.isNaN(date.getTime()))return '';return new Intl.DateTimeFormat(globalThis.ReiseI18n?.language==='en'?'en-GB':'de-DE',{weekday:'short',hour:'2-digit',minute:'2-digit',hour12:false}).format(date).replace('.', '')+(globalThis.ReiseI18n?.language==='en'?'':' Uhr')}
function stopDay(value){return String(value||'').match(/\d{4}-\d{2}-\d{2}/)?.[0]||''}
async function aiProposals(tripId, stops) {
  const button = document.querySelector('#aiProposals');
  if (button) { button.disabled = true; button.textContent = 'GEMINI ANALYSIERT …'; }
  try {
    const chosen = stops.filter(stop => stop.preview_id).slice(0, 8);
    const images = await Promise.all(chosen.map(async stop => {
      const response = await apiFetch(`./api/api.php?action=preview&trip=${encodeURIComponent(tripId)}&media=${encodeURIComponent(stop.preview_id)}`, { credentials: 'same-origin' });
      if (!response.ok) throw new Error();
      const blob = await response.blob();
      const data = await new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result).split(',')[1]);
        reader.onerror = reject;
        reader.readAsDataURL(blob);
      });
      return { data, mime_type: blob.type || 'image/jpeg' };
    }));
    const response = await apiFetch('./api/api.php?action=ai-proposals', {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        trip: tripId,
        stations: chosen.map(stop => ({ id: stop.id, name: stop.name || '', starts_at: stop.starts_at || '', latitude: stop.latitude ?? null, longitude: stop.longitude ?? null })),
        images
      })
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || 'Gemini konnte keine Vorschläge erstellen.');
    const text = data.proposals || {};
    (text.stationen || []).forEach((station, index) => {
      if (station.text && chosen[index]?.id) localStorage.setItem(`atlas-summary-${tripId}-${chosen[index].id}`, station.text);
    });
    const dialog = document.createElement('dialog');
    const card = document.createElement('section');
    card.style.cssText = 'background:#f4f0e8;padding:32px;max-width:720px';
    card.innerHTML = '<button style="float:right;border:0;background:none;font-size:28px">×</button><p class="eyebrow">GEMINI-TEXTVORSCHLÄGE</p><h2>Reise</h2><p></p><button class="primary" id="applyTripIntro">EINSTIEG ÜBERNEHMEN</button><h2>Stationen</h2>';
    card.querySelector('button').onclick = () => dialog.close();
    card.querySelector('p:last-of-type').textContent = text.reise || '';
    const applyIntro = card.querySelector('#applyTripIntro');
    if (!text.reise) applyIntro.remove();
    else applyIntro.onclick = () => {
      localStorage.setItem(`atlas-trip-intro-${tripId}`, text.reise);
      const intro = document.querySelector('.hero-copy .lede');
      if (intro) intro.textContent = text.reise;
      dialog.close();
    };
    (text.stationen || []).forEach(station => {
      const heading = document.createElement('h3');
      const paragraph = document.createElement('p');
      heading.textContent = station.ort || 'Station';
      paragraph.textContent = station.text || '';
      card.append(heading, paragraph);
    });
    dialog.append(card);
    dialog.addEventListener('close', () => dialog.remove());
    document.body.append(dialog);
    dialog.showModal();
  } catch (error) {
    alert(error.message || 'Gemini konnte keine Textvorschläge erstellen.');
  } finally {
    if (button) { button.disabled = false; button.textContent = 'KI-TEXTVORSCHLÄGE'; }
  }
}
async function addAiButton(tripId){if(!canEditTrip())return;let actions=document.querySelector('.hero-actions');if(!actions||document.querySelector('#aiProposals'))return;let stops=await apiFetch(`./api/api.php?action=stops&trip=${encodeURIComponent(tripId)}`,{credentials:'same-origin'}).then(r=>r.ok?r.json():null);if(!stops?.stops?.some(s=>s.preview_id))return;let button=document.createElement('button');button.id='aiProposals';button.className='primary';button.textContent='KI-TEXTVORSCHLÄGE';button.onclick=()=>aiProposals(tripId,stops.stops);actions.append(button)}
async function editTripTexts(tripId){let response=await apiFetch(`./api/api.php?action=stops&trip=${encodeURIComponent(tripId)}`,{credentials:'same-origin'}),data=await response.json().catch(()=>({}));if(!response.ok)throw new Error(data.error||'Texte konnten nicht geladen werden.');let dialog=document.createElement('dialog'),card=document.createElement('form');card.method='dialog';card.className='dialog-card';card.style.maxWidth='760px';card.innerHTML='<button class="close" value="cancel">×</button><p class="eyebrow">KI- UND STATIONSTEXTE</p><h2>Texte bearbeiten</h2><div class="text-editor-list"></div><button class="primary" value="default">ÄNDERUNGEN SPEICHERN</button>';let list=card.querySelector('.text-editor-list');(data.stops||[]).forEach(stop=>{let label=document.createElement('label'),area=document.createElement('textarea');label.textContent=stop.name||'Station';area.dataset.stop=stop.id;area.value=stop.summary||'';area.rows=4;area.style.cssText='display:block;width:100%;margin:7px 0 18px;padding:10px;border:1px solid #bfc3bb;background:#fbfaf6;font:14px/1.5 "DM Sans"';label.append(area);list.append(label)});card.addEventListener('submit',async event=>{event.preventDefault();let save=card.querySelector('.primary');save.disabled=true;try{await Promise.all([...list.querySelectorAll('textarea')].filter(area=>area.value.trim()).map(area=>apiFetch('./api/api.php?action=stop',{method:'PATCH',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:area.dataset.stop,trip:tripId,summary:area.value.trim()})}).then(r=>{if(!r.ok)throw new Error()})));dialog.close()}catch{alert('Ein Text konnte nicht gespeichert werden.')}finally{save.disabled=false}});dialog.append(card);dialog.addEventListener('close',()=>dialog.remove());document.body.append(dialog);dialog.showModal()}
function addTextEditorButton(tripId){if(!canEditTrip())return;let actions=document.querySelector('.hero-actions');if(!actions||document.querySelector('#editTripTexts'))return;let button=document.createElement('button');button.id='editTripTexts';button.className='primary';button.textContent='KI-TEXTE BEARBEITEN';button.onclick=()=>editTripTexts(tripId).catch(error=>alert(error.message));actions.append(button)}

function place(tripId,id){let t=trips().find(x=>x.id===tripId)||baseTrip,s=t.stops,i=s.findIndex(x=>x[0]===id);if(i<0)return trip(tripId);let spot=s[i],k=`atlas-summary-${tripId}-${id}`,summary=localStorage.getItem(k)||spot[4];app.replaceChildren(document.querySelector('#detailTemplate').content.cloneNode(true));detailPage.innerHTML=`<button class="back" id="back">← ZURÜCK ZUR KARTE</button><div class="place-header"><div><p class="eyebrow">STATION ${String(i+1).padStart(2,'0')} · REISEDOKU</p><h1>${spot[1]}</h1></div><div class="place-date">${spot[2].toUpperCase()}</div></div><div class="story-grid"><article class="story"><p>${summary}</p><button class="edit-link" id="edit">TEXT BEARBEITEN</button></article><div class="gallery">${spot[5].map((x,n)=>`<div class="media ${n===0?'large':''}" data-kind="${n===1?'VIDEO':'FOTO'}"><img src="${image(x)}" alt="Reiseaufnahme aus ${spot[1]}"></div>`).join('')}</div></div><nav class="pager"><button id="prev">${i?'← '+s[i-1][1]:'—'}</button><button class="map-link" id="mapLink">⌖ KARTE</button><button id="next">${i<s.length-1?s[i+1][1]+' →':'—'}</button></nav>`;back.onclick=mapLink.onclick=()=>go('#trip/'+tripId);prev.onclick=()=>i&&go(`#trip/${tripId}/place/${s[i-1][0]}`);next.onclick=()=>i<s.length-1&&go(`#trip/${tripId}/place/${s[i+1][0]}`);edit.onclick=()=>{let v=prompt(`Zusammenfassung für ${spot[1]}`,summary);if(v?.trim()){localStorage.setItem(k,v.trim());place(tripId,id)}}}

const loginDialog=document.querySelector('#loginDialog'),loginForm=document.querySelector('#loginForm'),loginNote=document.querySelector('#loginNote'),registerName=document.querySelector('#registerName');
document.addEventListener('click',event=>{if(!event.target.closest('#loginButton'))return;event.preventDefault();loginDialog.showModal()});
document.querySelector('#showRegister').onclick=()=>{let registering=loginForm.dataset.mode!=='register';loginForm.dataset.mode=registering?'register':'login';registerName.hidden=!registering;document.querySelector('#showRegister').textContent=registering?'ZURÜCK ZUR ANMELDUNG':'KONTO ERSTELLEN';loginForm.querySelector('.primary').textContent=registering?'KONTO ERSTELLEN':'ANMELDEN';loginNote.textContent=registering?'Passwort mit mindestens 12 Zeichen wählen.':''};
loginForm.addEventListener('submit',async event=>{event.preventDefault();let action=loginForm.dataset.mode==='register'?'register':'login',email=document.querySelector('#loginEmail').value,password=document.querySelector('#loginPassword').value,display_name=document.querySelector('#loginName').value;loginNote.textContent='Wird geprüft …';try{let response=await apiFetch('./api/auth.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action,email,password,display_name})}),data=await response.json().catch(()=>({}));if(!response.ok)throw new Error(data.error||'Anmeldung nicht möglich.');loginDialog.close();location.hash='#home';location.reload()}catch(error){loginNote.textContent=error.message}});
window.atlasLoginReady=true;

// Archiv-Synchronisation: Ist das PHP-Backend eingerichtet und ein Benutzer
// angemeldet, erscheinen dessen Reisedokus zusätzlich auf der Startseite.
let remoteTrips=[];
let pendingStationMedia = null;

document.querySelector('#archiveMenu')?.addEventListener('click', () => go('#library'));
const removeMapMenuIcons = () => document.querySelectorAll('.menu-action, .ship-action').forEach(icon => icon.remove());
new MutationObserver(removeMapMenuIcons).observe(document.body, { childList: true, subtree: true });
removeMapMenuIcons();

// Die früheren Entwurfsfassungen von renderRoute stehen weiter oben in dieser
// Datei. Diese finale Fassung ist bewusst die einzige, die von der App genutzt
// wird: Sie hält Liste und Karte aus derselben Serverantwort konsistent.

function findTripByAnyId(id) {
  return trips().find(entry => entry.id === id || entry.serverId === id);
}

function dayWeatherSymbol(code) {
  if (code === 0) return ['☀️', 'Sonnig'];
  if (code === 1) return ['🌤️', 'Überwiegend sonnig'];
  if (code === 2) return ['⛅', 'Teilweise bewölkt'];
  if (code === 3) return ['☁️', 'Bewölkt'];
  if ([45,48].includes(code)) return ['🌫️', 'Nebel'];
  if ([51,53,55,56,57].includes(code)) return ['🌦️', 'Nieselregen'];
  if ([61,63,65,66,67,80,81,82].includes(code)) return ['🌧️', 'Regen'];
  if ([71,73,75,77,85,86].includes(code)) return ['🌨️', 'Schnee'];
  if ([95,96,99].includes(code)) return ['⛈️', 'Gewitter'];
  return ['🌡️', 'Wetter unbekannt'];
}
function dayWeatherIcon(kind) {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('viewBox', '0 0 32 32'); svg.setAttribute('aria-hidden', 'true');
  svg.classList.add('day-weather-icon');
  const sun = '<g stroke="#e7a21b" fill="none"><circle cx="12" cy="12" r="5" fill="#ffd264"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l2 2m10 10 2 2M5 19l2-2M17 7l2-2"/></g>';
  const cloud = '<path d="M9 24a5 5 0 0 1-1-10 7 7 0 0 1 13-2 6 6 0 0 1 3 12Z" fill="#e8f0f6" stroke="#738ba0"/>';
  let shapes;
  if (kind === 'temperature') shapes = '<g stroke="#222" fill="none"><path d="M13 19V7a3 3 0 0 1 6 0v12a6 6 0 1 1-6 0Z"/><path d="M16 10v13m6-13h3m-3 5h3"/><circle cx="16" cy="24" r="2" fill="#222"/></g>';
  else if (kind === 'elevation') shapes = '<g stroke="#222" fill="none"><path d="m2 27 11-21 8 15 4-8 5 14ZM9 14l4 3 4-3M2 29h28"/></g>';
  else if (kind === 0) shapes = '<g transform="translate(4 4)">'+sun+'</g>';
  else if ([1,2].includes(kind)) shapes = sun+cloud;
  else if (kind === 3) shapes = cloud;
  else if ([45,48].includes(kind)) shapes = cloud+'<path stroke="#8da7b4" d="M4 27h24M8 30h18"/>';
  else if ([51,53,55,56,57,61,63,65,66,67,80,81,82].includes(kind)) shapes = cloud+'<path stroke="#3794d0" d="m10 27-1 3m8-3-1 3m8-3-1 3"/>';
  else if ([71,73,75,77,85,86].includes(kind)) shapes = cloud+'<g stroke="#5ca2c9"><path d="M10 26v5m-2-4 4 3m-4 0 4-3M23 26v5m-2-4 4 3m-4 0 4-3"/></g>';
  else if ([95,96,99].includes(kind)) shapes = cloud+'<path d="m17 20-5 7h5l-2 5 8-9h-6l3-3" fill="#f0b52c" stroke="#d39216"/>';
  else shapes = '<circle cx="16" cy="16" r="11" stroke="#8da7b4" fill="none"/><path d="M12 12a4 4 0 1 1 6 3c-2 1-2 2-2 4m0 3v1" stroke="#8da7b4" fill="none"/>';
  svg.innerHTML = '<g stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">'+shapes+'</g>';
  return svg;
}
async function renderDayWeather(page, tripId, day) {
  const section = document.createElement('section'); section.className = 'day-weather';
  section.setAttribute('aria-label', 'Wetter und Meereshöhe am Reisetag');
  section.hidden = true;
  const weatherHost = page.querySelector('.day-place-header > div, .unified-day-header');
  if (!weatherHost) return;
  weatherHost.append(section);
  try {
    const response = await apiFetch(`./api/api.php?action=day-weather&trip=${encodeURIComponent(tripId)}&day=${encodeURIComponent(day)}`, { credentials: 'same-origin' });
    const data = await response.json();
    if (!section.isConnected) return;
    if (!response.ok || !data.available) return;
    section.replaceChildren();
    const values = document.createElement('div'); values.className = 'day-weather-values';
    const number = value => new Intl.NumberFormat(globalThis.ReiseI18n?.language==='en'?'en-GB':'de-DE', { maximumFractionDigits: 0 }).format(value);
    const item = (symbol, label, value) => {
      const node = document.createElement('div'), icon = dayWeatherIcon(symbol), detail = document.createElement('strong');
      node.title = `${label}: ${value}`; node.setAttribute('aria-label', node.title);
      detail.textContent = value;
      node.append(icon, detail); values.append(node);
    };
    const temperature = data.temperature_min !== null && data.temperature_max !== null ? `${number(data.temperature_min)}–${number(data.temperature_max)} °C` : '–';
    item('temperature', 'TEMPERATUR · MIN / MAX', temperature);
    item('elevation', 'HÖHE ÜBER DEM MEER', data.elevation !== null ? `${number(data.elevation)} m` : '–');
    const [, weather] = dayWeatherSymbol(data.weather_code);
    const source = document.createElement('a'); source.href = 'https://open-meteo.com/'; source.target = '_blank'; source.rel = 'noopener'; source.append(dayWeatherIcon(data.weather_code));
    source.title = `${weather} · Standort: ${data.location || 'erste Station mit GPS'} · Wetter- und Höhendaten: Open-Meteo`;
    source.setAttribute('aria-label', source.title); values.append(source);
    section.append(values); section.hidden = false;
  } catch { /* Keep the compact heading clear when weather is unavailable. */ }
}
async function serverDayView(tripClientId, day) {
  const requestedHash = location.hash;
  const tripRecord = findTripByAnyId(tripClientId);
  if (!tripRecord?.serverId) return trip(tripClientId);
  try {
    const response = await apiFetch(`./api/api.php?action=stops&trip=${encodeURIComponent(tripRecord.serverId)}`, { credentials: 'same-origin' });
    const data = await response.json();
    if (location.hash !== requestedHash) return;
    if (!response.ok) throw new Error();
    const first = (data.stops || []).find(stop => stopDay(stop.starts_at) === day);
    if (first) return serverPlace(tripRecord.serverId, first.id);
  } catch {}
  app.replaceChildren(document.querySelector('#detailTemplate').content.cloneNode(true));
  const page = document.querySelector('#detailPage');
  page.innerHTML = '<div class="place-header day-place-header"><div><h1></h1></div></div><section class="day-summary"><div class="day-summary-copy"><p class="eyebrow">TAGESZUSAMMENFASSUNG</p><p class="day-summary-text">Noch keine Tageszusammenfassung vorhanden.</p></div><figure class="day-summary-photo" hidden><img alt=""><figcaption></figcaption></figure></section><div class="day-stops-choice"></div>';
  const headerLink = document.createElement('a');
  headerLink.className = 'header-back-link';
  headerLink.href = `#trip/${tripClientId}`;
  headerLink.textContent = `ZURÜCK ZUR KARTE: ${tripRecord.title || 'REISE'}`;
  headerTitle.replaceChildren(headerLink);
  page.querySelector('h1').textContent = new Intl.DateTimeFormat(globalThis.ReiseI18n?.language==='en'?'en-GB':'de-DE',{weekday:'long',day:'2-digit',month:'long',year:'numeric'}).format(new Date(`${day}T12:00:00`));
  await mountTripDayAdministration(page,tripRecord);
  const list = page.querySelector('.day-stops-choice'), summaryText = page.querySelector('.day-summary-text');
  try {
    const [response, summaryResponse] = await Promise.all([apiFetch(`./api/api.php?action=stops&trip=${encodeURIComponent(tripRecord.serverId)}`, { credentials: 'same-origin' }),apiFetch(`./api/api.php?action=day-summary&trip=${encodeURIComponent(tripRecord.serverId)}&day=${encodeURIComponent(day)}`, { credentials: 'same-origin' })]);
    const data = await response.json(), summaryData = await summaryResponse.json(); if (!response.ok || !summaryResponse.ok) throw new Error();
    renderJourneyDayStrip(page,tripRecord.serverId,[...new Set((data.stops||[]).map(stop=>stopDay(stop.starts_at)).filter(Boolean))].sort(),day);
    const currentSummary = summaryData.summary || '';
    summaryText.textContent = currentSummary || 'Noch keine Tageszusammenfassung vorhanden.';
    if (mayEditTrip(tripRecord.serverId) && !window.ReiseNativeUI?.isReadOnlyTrip?.(tripRecord.serverId)) {
      const editor = document.createElement('textarea');
      editor.className = 'trip-participants-input day-summary-editor';
      editor.rows = 7;
      editor.maxLength = 10000;
      editor.placeholder = 'Tageszusammenfassung eingeben …';
      editor.value = currentSummary;
      const save = document.createElement('button');
      save.className = 'edit-link';
      save.textContent = 'TAGESZUSAMMENFASSUNG SPEICHERN';
      save.onclick = async () => {
        const summary = editor.value.trim();
        if (!summary) { editor.focus(); return; }
        save.disabled = true;
        try {
          const result = await apiFetch('./api/api.php?action=day-summary', {
            method: 'PATCH', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ trip: tripRecord.serverId, day, summary })
          });
          const payload = await result.json().catch(() => ({}));
          if (!result.ok) throw new Error(payload.error || 'Die Tageszusammenfassung konnte nicht gespeichert werden.');
          summaryText.textContent = summary;
        } catch (error) { alert(error.message || 'Die Tageszusammenfassung konnte nicht gespeichert werden.'); }
        finally { save.disabled = false; }
      };
      summaryText.after(editor, save);
    }
    const allStops = data.stops || [];
    const days = [...new Set(allStops.map(stop => stopDay(stop.starts_at)).filter(Boolean))].sort();
    const stops = allStops.filter(stop => stopDay(stop.starts_at) === day);
    renderDayWeather(page, tripRecord.serverId, day);
    const photoStops = stops.filter(stop => stop.preview_id);
    if (summaryData.photo_media_id && !photoStops.some(stop => String(stop.preview_id) === String(summaryData.photo_media_id))) {
      photoStops.unshift({ preview_id: summaryData.photo_media_id, name: 'Bisher ausgewähltes Tagesbild' });
    }
    if (photoStops.length) {
      const photo = page.querySelector('.day-summary-photo');
      const image = photo.querySelector('img');
      const caption = photo.querySelector('figcaption');
      const showPhoto = stop => {
        image.src = `./api/api.php?action=preview&trip=${encodeURIComponent(tripRecord.serverId)}&media=${encodeURIComponent(stop.preview_id)}&size=large`;
        image.alt = `Aufnahme aus ${stop.name || 'einer Tagesstation'}`;
        caption.textContent = stop.name || 'Tagesaufnahme';
        photo.hidden = false;
      };
      let selectedPhoto = photoStops.find(stop => String(stop.preview_id) === String(summaryData.photo_media_id)) || photoStops[0];
      showPhoto(selectedPhoto);
      if (canEditTrip()) {
        const select = document.createElement('select');
        select.setAttribute('aria-label', 'Tagesbild auswählen');
        photoStops.forEach(stop => select.add(new Option(stop.name || `Station ${allStops.indexOf(stop) + 1}`, stop.preview_id)));
        select.value = selectedPhoto.preview_id;
        select.onchange = async () => {
          const nextPhoto = photoStops.find(stop => String(stop.preview_id) === select.value);
          if (!nextPhoto) return;
          showPhoto(nextPhoto); select.disabled = true;
          try {
            const result = await apiFetch('./api/api.php?action=day-summary-photo', { method: 'PATCH', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ trip: tripRecord.serverId, day, media: nextPhoto.preview_id }) });
            if (!result.ok) throw new Error();
            selectedPhoto = nextPhoto;
          } catch { alert('Das Tagesbild konnte nicht gespeichert werden.'); select.value = selectedPhoto.preview_id; showPhoto(selectedPhoto); }
          finally { select.disabled = false; }
        };
        photo.append(select);
      }
    }
    if (!stops.length) { list.textContent = 'Für diesen Tag sind keine Stationen vorhanden.'; return; }
    stops.forEach(stop => {
      const button = document.createElement('button'); button.className = 'day-choice-stop';
      const preview = stop.preview_id ? `./api/api.php?action=preview&trip=${encodeURIComponent(tripRecord.serverId)}&media=${encodeURIComponent(stop.preview_id)}&size=small` : '';
      button.innerHTML = `<span>${String(allStops.indexOf(stop) + 1).padStart(2, '0')}</span>${preview ? `<img src="${preview}" alt="">` : '<i></i>'}<div><b></b><small></small></div>`;
      button.querySelector('b').textContent = stop.name || 'Aufnahmen';
      button.querySelector('small').textContent = stopTimeLabel(stop);
      if (stop.summary?.trim()) {
        const stationText = document.createElement('span');
        stationText.className = 'day-stop-summary';
        stationText.textContent = stop.summary.trim();
        button.append(stationText);
      }
      button.onclick = () => go(`#trip/${tripClientId}/station/${stop.id}`);
      const entry = document.createElement('div'); entry.className = 'day-stop-entry'; entry.append(button);
      const mapLink = stationMapLink(tripRecord.serverId, stop);
      if (mapLink) entry.append(mapLink);
      list.append(entry);
    });
  } catch { list.textContent = 'Die Stationen konnten nicht geladen werden.'; }
}

// Überschreibt den rein lokalen Titel-Handler aus dem ursprünglichen Entwurf.
// Die Änderung wird sofort dargestellt und bei einem Serverfehler zurückgesetzt.
document.addEventListener('click', async event => {
  const button = event.target.closest('#editTitle');
  if (!button || !canEditTrip()) return;
  event.preventDefault();
  event.stopImmediatePropagation();
  const match = location.hash.match(/^#trip\/([^/]+)/);
  const tripId = match && match[1];
  const trip = tripId && trips().find(entry => entry.id === tripId);
  if (!trip) return;
  const title = prompt('Titel der Reisedokumentation', trip.title)?.trim();
  if (!title || title === trip.title) return;

  const localRecords = localTrips();
  const localRecord = localRecords.find(entry => entry.id === tripId);
  const remoteRecord = remoteTrips.find(entry => entry.id === trip.serverId);
  const previousTitle = trip.title;
  if (localRecord) { localRecord.title = title; localSave(localRecords); }
  if (remoteRecord) remoteRecord.title = title;
  render();

  if (!trip.serverId) return;
  try {
    const response = await apiFetch('./api/api.php?action=trip', {
      method: 'PATCH', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: trip.serverId, title })
    });
    if (!response.ok) throw new Error();
  } catch {
    if (localRecord) { localRecord.title = previousTitle; localSave(localRecords); }
    if (remoteRecord) remoteRecord.title = previousTitle;
    render();
    alert('Der neue Titel konnte nicht auf dem Server gespeichert werden.');
  }
}, true);

document.addEventListener('click', async event => {
  const button = event.target.closest('#editTripIntro');
  if (!button || !canEditTrip()) return;
  const tripId = location.hash.match(/^#trip\/([^/]+)/)?.[1];
  const trip = tripId && trips().find(entry => entry.id === tripId);
  const lede = document.querySelector('.hero-copy .lede');
  if (!trip || !lede) return;
  const intro = prompt('Einstiegstext für diese Reise', lede.textContent)?.trim();
  if (!intro || intro === lede.textContent) return;
  const key = `atlas-trip-intro-${trip.serverId || tripId}`;
  localStorage.setItem(key, intro); lede.textContent = intro;
  if (!trip.serverId) return;
  try {
    const response = await apiFetch('./api/api.php?action=trip-intro', {
      method: 'PATCH', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: trip.serverId, intro })
    });
    if (!response.ok) throw new Error();
    const remote = remoteTrips.find(entry => entry.id === trip.serverId);
    if (remote) remote.intro = intro;
  } catch { alert('Der Einstiegstext konnte nicht auf dem Server gespeichert werden.'); }
}, true);

document.addEventListener('click', event => {
  const link = event.target.closest('.trip-open');
  if (link && !access.loggedIn) {
    event.preventDefault();
    event.stopImmediatePropagation();
    location.assign('./login.html');
  }
}, true);

async function previewCaptureDates(trip, trigger) {
  trigger.disabled = true; trigger.textContent = 'AUFNAHMEDATEN WERDEN GEPRÜFT …';
  const request = async body => {
    const response = await apiFetch('./api/api.php?action=capture-dates', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ trip: trip.id, ...body }) });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || 'Die Aufnahmedaten konnten nicht verarbeitet werden.');
    return data;
  };
  try {
    let data = await request({ mode: 'preview' });
    while (data.pending) {
      trigger.textContent = `ORIGINALDATEN PRÜFEN · ${data.total - data.remaining} VON ${data.total}`;
      data = await request({ mode: 'resolve', token: data.token });
    }
    const sourceLabel = source => ({ onedrive: 'OneDrive', exif: 'EXIF-Aufnahmedatum', filename: 'Dateiname', manual: 'manuell korrigiert', unknown: 'unbekannt' }[source] || 'unbekannt');
    const dialog = document.createElement('dialog'); dialog.className = 'capture-date-dialog';
    const card = document.createElement('section'); card.className = 'dialog-card';
    const heading = document.createElement('h2'); heading.textContent = `Aufnahmedaten · ${trip.title}`;
    const description = document.createElement('p');
    description.textContent = `${data.changes.length} Datumsänderungen. Neuer Reisezeitraum: ${data.starts_on || 'unbekannt'} bis ${data.ends_on || 'unbekannt'}. Vor dem Speichern wird eine vollständige Sicherung angelegt. Stationen werden nach Aufnahmetagen aufgeteilt; ihre Texte und Ortskorrekturen bleiben erhalten. Tagesbilder bleiben ausgewählt.`;
    card.append(heading, description);
    const detail = (title, lines) => {
      const details = document.createElement('details'), summary = document.createElement('summary'), list = document.createElement('ul');
      summary.textContent = title;
      lines.forEach(text => { const li = document.createElement('li'); li.textContent = text; list.append(li); });
      details.append(summary, list); card.append(details);
    };
    detail(`Geänderte Fotos und Videos (${data.changes.length})`, data.changes.map(item => `${item.name} · ${item.station}: ${item.before || 'ohne Datum'} → ${item.after || 'ohne Aufnahmedatum'} · Quelle: ${sourceLabel(item.source)}`));
    const evidence = data.evidence || [];
    const counts = evidence.reduce((counts, item) => { counts[item.source] = (counts[item.source] || 0) + 1; return counts; }, {});
    detail(`Verwendete Quellen · ${Object.entries(counts).map(([source, count]) => `${sourceLabel(source)}: ${count}`).join(' · ')}`, evidence.map(item => `${item.name}: ${item.date || 'ohne Datum'} · ${sourceLabel(item.source)}${item.note ? ` · ${item.note}` : ''}`));
    const conflicts = evidence.filter(item => item.conflict);
    let acknowledge = null;
    if (conflicts.length) {
      detail(`Abweichungen zum Dateinamen (${conflicts.length}) – bitte prüfen`, conflicts.map(item => `${item.name}: ${sourceLabel(item.source)} ${item.date}, Dateiname ${item.filename_date}`));
      const label = document.createElement('label'); acknowledge = document.createElement('input'); acknowledge.type = 'checkbox'; acknowledge.style.cssText = 'width:auto;margin-right:8px';
      label.append(acknowledge, document.createTextNode('Abweichungen geprüft: Metadaten haben Vorrang. Der Dateiname enthält keine Zeitzone.')); card.append(label);
    }
    detail(`Stationen nach der Korrektur (${data.stations.length})`, data.stations.map(stop => `${stop.name}: ${stop.starts_at || 'ohne Aufnahmedatum'}${stop.ends_at && stop.ends_at !== stop.starts_at ? ` bis ${stop.ends_at}` : ''}`));
    if (data.unknown.length) detail(`Ohne Aufnahmedatum (${data.unknown.length}) – werden undatiert geführt`, data.unknown);
    if (data.missing.length) detail(`In OneDrive nicht gefunden (${data.missing.length}) – bisheriges Datum bleibt erhalten`, data.missing);
    const choices = [];
    if (data.annotations.length) {
      const note = document.createElement('p'); note.textContent = 'Bitte die Zuordnung vorhandener Tagestexte und Tagesbilder prüfen. Jeder Eintrag braucht einen eigenen Zieltag. Ein bisheriger Tag darf beibehalten werden; seine Inhalte bleiben dann unter diesem Datum gespeichert.'; card.append(note);
      data.annotations.forEach(annotation => {
        const label = document.createElement('label'), select = document.createElement('select'), warning = document.createElement('small');
        label.textContent = `${annotation.before}: ${annotation.summary || '(nur Tagesbild)'}`;
        const empty = document.createElement('option'); empty.value = ''; empty.textContent = 'Zieltag wählen …'; select.append(empty);
        [...new Set([...data.days, annotation.before])].sort().forEach(day => { const option = document.createElement('option'); option.value = day; option.textContent = day === annotation.before ? `${day} (bisheriger Tag)` : day; select.append(option); });
        select.value = annotation.suggested || '';
        const warn = () => { warning.textContent = annotation.photo_media_id && annotation.photo_day !== select.value ? `Das ausgewählte Tagesbild hat das Aufnahmedatum ${annotation.photo_day || 'unbekannt'}. Die Bildauswahl bleibt erhalten.` : ''; };
        select.onchange = warn; warn(); label.append(select, warning); card.append(label); choices.push({ before: annotation.before, select });
      });
    }
    const error = document.createElement('p'); error.setAttribute('role', 'alert');
    const buttons = document.createElement('div'); buttons.className = 'capture-date-actions';
    const cancel = document.createElement('button'); cancel.className = 'secondary'; cancel.textContent = 'ABBRECHEN'; cancel.onclick = () => dialog.close();
    const apply = document.createElement('button'); apply.className = 'primary'; apply.textContent = 'SICHERN UND KORREKTUR ÜBERNEHMEN';
    apply.onclick = async () => {
      if (acknowledge && !acknowledge.checked) { error.textContent = 'Bitte die Abweichungen zum Dateinamen prüfen und bestätigen.'; return; }
      const values = choices.map(choice => choice.select.value);
      if (values.some(value => !value) || new Set(values).size !== values.length) { error.textContent = 'Bitte für jeden Tagesinhalt einen unterschiedlichen Zieltag wählen.'; return; }
      apply.disabled = true; cancel.disabled = true; error.textContent = 'Sicherung und Korrektur laufen …';
      try {
        const result = await request({ mode: 'apply', token: data.token, acknowledge_conflicts: acknowledge?.checked || false, day_choices: Object.fromEntries(choices.map(choice => [choice.before, choice.select.value])) });
        dialog.close(); alert(`${result.changed} Aufnahmedaten korrigiert. Die Sicherung wurde gespeichert (${result.backup}).`);
        window.location.reload();
      } catch (failure) { error.textContent = failure.message; apply.disabled = false; cancel.disabled = false; }
    };
    dialog.addEventListener('cancel', event => { if (apply.disabled) event.preventDefault(); });
    buttons.append(cancel, apply); card.append(error, buttons); dialog.append(card);
    dialog.addEventListener('close', () => dialog.remove()); document.body.append(dialog); dialog.showModal();
  } catch (error) { alert(error.message); }
  finally { trigger.disabled = false; trigger.textContent = 'AUFNAHMEDATEN AKTUALISIEREN'; }
}
function renderAdminAccounts(adminAccounts,page) {
  const accountList = adminAccounts.querySelector('.admin-account-list');
  const adminForm=document.createElement('form');adminForm.hidden=true;adminForm.className='admin-account-form';
  adminForm.innerHTML='<label>E-Mail-Adresse des neuen Admins<input name="email" type="email" maxlength="320" autocomplete="email" required></label><button class="primary">ADMIN HINZUFÜGEN</button><p>Admins dürfen alle Reisen bearbeiten und Freigaben verwalten. Nur Hauptadmins dürfen weitere Adminrechte vergeben. Das neue Konto muss sich mit dieser E-Mail-Adresse über Microsoft anmelden; es wird keine Einladungs-Mail versendet.</p>';
  adminAccounts.append(adminForm);const adminNote=document.createElement('p');adminNote.setAttribute('role','status');adminAccounts.append(adminNote);let adminCsrf='',adminBusy=false;
  const changeAdmin=async(method,email)=>{
    if(adminBusy)return;adminBusy=true;adminAccounts.querySelectorAll('button').forEach(b=>b.disabled=true);
    try{const response=await apiFetch('./api/api.php?action=admin-accounts',{method,credentials:'same-origin',headers:{'Content-Type':'application/json','X-Reise-Admin-CSRF':adminCsrf},body:JSON.stringify({email})});const data=await response.json();if(!response.ok)throw new Error(data.error||'Adminrechte konnten nicht gespeichert werden.');adminNote.textContent=data.message;adminForm.reset();await loadAdmins();}
    catch(error){adminNote.textContent=error.message;}finally{adminBusy=false;adminAccounts.querySelectorAll('button').forEach(b=>b.disabled=false);}
  };
  const loadAdmins=async()=>{
    try{const response=await apiFetch('./api/api.php?action=admin-accounts',{credentials:'same-origin',cache:'no-store'});const data=await response.json();if(!response.ok)throw new Error(data.error||'Admin-Accounts konnten nicht geladen werden.');if(!page.isConnected)return;
      adminCsrf=data.csrf_token||'';adminForm.hidden=!data.can_manage;accountList.replaceChildren();
      for(const account of data.accounts||[]){const row=document.createElement('div');row.className='admin-account-row';const name=document.createElement('strong');name.textContent=account.display_name||account.email;row.append(name);const detail=document.createElement('small');detail.textContent=(account.email||'')+(account.is_main?' · Geschütztes Hauptkonto':account.microsoft_linked?' · Microsoft verbunden':' · Microsoft-Anmeldung steht aus');row.append(detail);
        if(data.can_manage&&!account.protected){const remove=document.createElement('button');remove.className='edit-link';remove.type='button';remove.textContent='ADMINRECHTE ENTZIEHEN';remove.onclick=async()=>{if(await (window.ReiseConfirm||confirm)('Adminrechte für '+account.email+' entziehen? Das Konto und vorhandene Gastfreigaben bleiben erhalten.'))changeAdmin('DELETE',account.email);};row.append(remove);}accountList.append(row);}
    }catch(error){if(page.isConnected)accountList.textContent=error.message;}
  };
  adminForm.onsubmit=async event=>{event.preventDefault();const email=adminForm.elements.email.value.trim();if(email&&await (window.ReiseConfirm||confirm)(email+' Adminrechte für alle Reisen und Gastfreigaben erteilen?'))changeAdmin('POST',email);};loadAdmins();
}

function appendTripOpenActions(host, tripId) {
  for (const [label, suffix] of [['KARTE ÖFFNEN', ''], ['REISEÜBERSICHT ÖFFNEN', '/days']]) {
    const link = document.createElement('a'); link.className = 'trip-open'; link.textContent = label;
    link.href = '#trip/' + encodeURIComponent(tripId) + suffix; host.append(link);
  }
}
function mountWebTripAdministration(card, trip, directAdmin = false) {
  if(window.ReiseNativeUI?.isReadOnlyTrip?.(trip.id))return;
  if (!(directAdmin ? (access.isAdmin || (!access.isGuest && Number(trip.is_owner) === 1)) : canEditTrip(trip.id))) return;
      const management = document.createElement('details'); management.className = 'trip-management';
      if(String(window.ReiseWebUI?.manageTripId||'')===String(trip.id)){management.open=true;window.ReiseWebUI.manageTripId=null;setTimeout(()=>{if(card.isConnected)card.scrollIntoView({block:'start',behavior:'smooth'});},0);}
      const manageLabel = document.createElement('summary'); manageLabel.textContent = 'REISE VERWALTEN'; management.append(manageLabel);
      const managementSection = label => {
        const section = document.createElement('details'); section.className = 'trip-management-section';
        const summary = document.createElement('summary'); summary.textContent = label;
        const body = document.createElement('div'); body.className = 'trip-management-body';
        section.append(summary, body); management.append(section);
        return { section, body };
      };
      const basics = managementSection('Titel, Texte & Teilnehmer');
      const guests = managementSection('Gäste & Einladungen');
      const media = managementSection('Bilder & Aufnahmedaten');

      const mediaActions = document.createElement('div'); mediaActions.className = 'admin-trip-actions'; media.body.append(mediaActions);

      card.append(management); management.open = true;
      const titleLabel = document.createElement('label'); titleLabel.className = 'trip-participants-label'; titleLabel.textContent = 'TITEL DER REISE';
      const title = document.createElement('input'); title.type = 'text'; title.className = 'trip-main-location-input'; title.maxLength = 255; title.value = trip.title || '';
      const saveTitle = document.createElement('button'); saveTitle.className = 'edit-link'; saveTitle.textContent = 'TITEL SPEICHERN';
      saveTitle.onclick = async () => {
        const value = title.value.trim(); if (!value) { title.focus(); return; }
        saveTitle.disabled = true;
        try {
          const response = await apiFetch('./api/api.php?action=trip', { method: 'PATCH', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: trip.id, title: value }) });
          if (!response.ok) throw new Error();
          trip.title = value; document.querySelector('#overviewTitle').textContent = value;
          const cached = remoteTrips.find(record => String(record.id) === String(trip.id)); if (cached) cached.title = value;
        } catch { alert('Der Titel konnte nicht gespeichert werden.'); }
        finally { saveTitle.disabled = false; }
      };
      const introLabel = document.createElement('label'); introLabel.className = 'trip-participants-label'; introLabel.textContent = 'EINSTIEGSTEXT DER REISE';
      const intro = document.createElement('textarea'); intro.className = 'trip-participants-input'; intro.rows = 4; intro.maxLength = 5000; intro.placeholder = 'Einstiegstext für diese Reise'; intro.value = trip.intro || '';
      const saveIntro = document.createElement('button'); saveIntro.className = 'edit-link'; saveIntro.textContent = 'EINSTIEGSTEXT SPEICHERN';
      saveIntro.onclick = async () => {
        const value = intro.value.trim(); if (!value) { intro.focus(); return; }
        saveIntro.disabled = true;
        try {
          const response = await apiFetch('./api/api.php?action=trip-intro', { method: 'PATCH', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: trip.id, intro: value }) });
          if (!response.ok) throw new Error();
          trip.intro = value;
          document.querySelector('.overview-description').textContent = value;
          const cached = remoteTrips.find(record => String(record.id) === String(trip.id)); if (cached) cached.intro = value;
        } catch { alert('Der Einstiegstext konnte nicht gespeichert werden.'); }
        finally { saveIntro.disabled = false; }
      };
      basics.body.append(titleLabel, title, saveTitle, introLabel, intro, saveIntro);
      const participantsLabel = document.createElement('label'); participantsLabel.className = 'trip-participants-label'; participantsLabel.textContent = 'TEILNEHMER DER REISE';
      const participants = document.createElement('textarea'); participants.className = 'trip-participants-input'; participants.rows = 3; participants.placeholder = 'Zum Beispiel: Alex, Kim und Familie'; participants.value = trip.participants || '';
      const saveParticipants = document.createElement('button'); saveParticipants.className = 'edit-link'; saveParticipants.textContent = 'TEILNEHMER SPEICHERN';
      saveParticipants.onclick = async () => {
        saveParticipants.disabled = true;
        try {
          const response = await apiFetch('./api/api.php?action=trip-participants', { method: 'PATCH', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: trip.id, participants: participants.value }) });
          if (!response.ok) throw new Error(); trip.participants = participants.value.trim();
          const cached = remoteTrips.find(record => String(record.id) === String(trip.id)); if (cached) cached.participants = trip.participants;
        } catch { alert('Die Teilnehmer konnten nicht gespeichert werden.'); }
        finally { saveParticipants.disabled = false; }
      };
      basics.body.append(participantsLabel, participants, saveParticipants);
      const inviteLabel = document.createElement('label'); inviteLabel.className = 'trip-participants-label'; inviteLabel.textContent = 'GAST EINLADEN';
      const inviteEmail = document.createElement('input'); inviteEmail.type = 'email'; inviteEmail.className = 'trip-main-location-input'; inviteEmail.placeholder = 'gast@example.com'; inviteEmail.autocomplete = 'email';
      const sendInvite = document.createElement('button'); sendInvite.className = 'edit-link'; sendInvite.textContent = 'EINLADUNG PER E-MAIL SENDEN';
      const guestList = document.createElement('div'); guestList.className = 'all-trips-guest-list';
      let guestRequestVersion = 0;
      const loadTripGuests = async () => {
        const version = ++guestRequestVersion;
        try {
          const response = await apiFetch(`./api/api.php?action=trip-guests&trip=${encodeURIComponent(trip.id)}`, { credentials: 'same-origin', cache: 'no-store' });
          const data = await response.json().catch(() => ({}));
          if (!response.ok) throw new Error(data.error || 'Gäste konnten nicht geladen werden.');
          if (version !== guestRequestVersion) return;
          guestList.replaceChildren();
          if (!data.guests?.length) { guestList.textContent = 'Für diese Reise ist kein Gast freigegeben.'; return; }
          data.guests.forEach(guest => {
            const row = document.createElement('span');
            row.append(document.createTextNode(guest.email));
            if (Number(guest.has_archive_access)) { const note = document.createElement('small'); note.textContent = '· auch Archivzugriff'; row.append(note); }
            const removeGuest = document.createElement('button'); removeGuest.type = 'button'; removeGuest.textContent = '×'; removeGuest.title = `${guest.email} Zugriff auf diese Reise entziehen`;
            removeGuest.onclick = async () => {
              const archiveNotice = Number(guest.has_archive_access) ? '\nDie separate Freigabe für das gesamte Archiv bleibt bestehen und muss ebenfalls entfernt werden.' : '';
              if (!confirm(`${guest.email} den Zugriff auf „${trip.title}“ dauerhaft entziehen?${archiveNotice}`)) return;
              removeGuest.disabled = true;
              try {
                const result = await apiFetch('./api/api.php?action=trip-guests', { method: 'DELETE', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ trip: trip.id, email: guest.email }) });
                const payload = await result.json().catch(() => ({}));
                if (!result.ok) throw new Error(payload.error || 'Der Zugriff konnte nicht entfernt werden.');
                await loadTripGuests();
              } catch (error) { alert(error.message || 'Der Zugriff konnte nicht entfernt werden.'); removeGuest.disabled = false; }
            };
            row.append(removeGuest); guestList.append(row);
          });
        } catch (error) { if (version === guestRequestVersion) guestList.textContent = error.message || 'Gäste konnten nicht geladen werden.'; }
      };
      if (!access.isMicrosoft) { inviteEmail.disabled = true; sendInvite.disabled = true; sendInvite.title = 'Für den Versand bitte zuerst mit dem Microsoft-365-Administratorkonto anmelden.'; }
      sendInvite.onclick = async () => {
        const email = inviteEmail.value.trim(); if (!email) { inviteEmail.focus(); return; }
        sendInvite.disabled = true;
        try {
          const response = await apiFetch('./api/api.php?action=trip-invite', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ trip: trip.id, email }) });
          const data = await response.json().catch(() => ({})); if (!response.ok) throw new Error(data.error || 'Einladung konnte nicht gesendet werden.');
          inviteEmail.value = ''; await loadTripGuests(); alert(`Einladung wurde an ${email} gesendet.`);
        } catch (error) { alert(error.message || 'Einladung konnte nicht gesendet werden.'); }
        finally { sendInvite.disabled = false; }
      };
      guests.body.append(inviteLabel, inviteEmail, sendInvite, guestList);
      let guestsLoaded = false;
      guests.section.addEventListener('toggle', () => {
        if (guests.section.open && !guestsLoaded) { guestsLoaded = true; loadTripGuests(); }
      });
      if (String(trip.id) === window.ReisePendingGuestTrip) {
        management.open = true; guests.section.open = true;
        guestsLoaded = true; loadTripGuests();
        window.ReisePendingGuestTrip = null;
        try { sessionStorage.removeItem('reise-website-guest-trip'); } catch {}
        setTimeout(() => { guests.section.scrollIntoView({block:'center'}); inviteEmail.focus({preventScroll:true}); }, 0);
      }
      if (!Number(trip.has_gps)) {
        const locationLabel = document.createElement('label'); locationLabel.className = 'trip-participants-label'; locationLabel.textContent = 'HAUPTORT DER REISE';
        const location = document.createElement('input'); location.type = 'text'; location.className = 'trip-main-location-input'; location.maxLength = 255; location.placeholder = 'Zum Beispiel: Barcelona, Spanien'; location.value = trip.main_location || '';
        const locateLocation = document.createElement('button'); locateLocation.className = 'edit-link trip-geocode-button'; locateLocation.textContent = 'ORT SUCHEN & AUF WELTKARTE ZEIGEN';
        const locationHint = document.createElement('small'); locationHint.className = 'trip-location-hint';
        const attribution = document.createElement('a'); attribution.href = 'https://www.openstreetmap.org/copyright'; attribution.target = '_blank'; attribution.rel = 'noopener'; attribution.textContent = 'OpenStreetMap';
        locationHint.append(trip.main_latitude !== null && trip.main_latitude !== undefined ? 'Kartenpunkt gespeichert · ' : 'Ortssuche: ', attribution);
        locateLocation.onclick = async () => {
          if (!location.value.trim()) { location.focus(); return; }
          locateLocation.disabled = true; locateLocation.textContent = 'ORT WIRD GESUCHT …';
          try {
            const response = await apiFetch('./api/api.php?action=trip-main-location-geocode', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: trip.id, main_location: location.value }) });
            const data = await response.json().catch(() => ({})); if (!response.ok) throw new Error(data.error || 'Ort nicht gefunden.');
            trip.main_location = location.value.trim(); trip.main_latitude = data.latitude; trip.main_longitude = data.longitude;
            const cached = remoteTrips.find(record => String(record.id) === String(trip.id)); if (cached) Object.assign(cached, { main_location: trip.main_location, main_latitude: data.latitude, main_longitude: data.longitude });
            locationHint.firstChild.textContent = 'Kartenpunkt gespeichert · ';
            sessionStorage.setItem('atlas-focus-trip', String(trip.id));
            window.location.assign(`${window.location.pathname}?show-map=${encodeURIComponent(String(trip.id))}#home`);
          } catch (error) { alert(error.message || 'Der Ort konnte nicht gefunden werden.'); }
          finally { locateLocation.disabled = false; locateLocation.textContent = 'ORT SUCHEN & AUF WELTKARTE ZEIGEN'; }
        };
        const locationSection = managementSection('Hauptort & Karte');
        management.insertBefore(locationSection.section, media.section);
        locationSection.body.append(locationLabel, location, locateLocation, locationHint);
      }
      const sync = document.createElement('button'); sync.className = 'edit-link'; sync.textContent = 'ONEDRIVE-BILDER ABGLEICHEN';
      sync.disabled = trip.source_provider !== 'onedrive';
      sync.title = 'Mit dem verbundenen OneDrive-Konto abgleichen';
      sync.onclick = async () => {
        if (!confirm(`„${trip.title}“ mit dem OneDrive-Ordner abgleichen? Neue Fotos und Videos werden ergänzt. Gelöschte Medien werden auch aus den Stationen und dem Bilder-Cache entfernt.`)) return;
        sync.disabled = true; sync.textContent = 'ABGLEICH LÄUFT …';
        try {
          let syncToken = '', result;
          do {
            const response = await apiFetch('./api/api.php?action=sync-trip-media', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ trip: trip.id, sync_token: syncToken }) });
            result = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(result.error || 'Der OneDrive-Abgleich ist fehlgeschlagen.');
            if (result.pending) {
              if (!result.sync_token) throw new Error('Der Abgleich konnte nicht fortgesetzt werden.');
              syncToken = result.sync_token;
              sync.textContent = `NEUE AUFNAHMEN PRÜFEN … ${result.total - result.remaining}/${result.total}`;
            }
          } while (result.pending);
          stationMediaCache.invalidate();
          alert(`${result.added_media || 0} neue Medien und ${result.added_stations || 0} neue Stationen ergänzt. ${result.removed_media} gelöschte Medien, ${result.removed_stations} leere Stationen und ${result.removed_cached_thumbnails} zwischengespeicherte Minibilder entfernt. ${result.remaining_media} Medien sind jetzt in der Reise.`);
          render();
        } catch (error) { alert(error.message || 'Der OneDrive-Abgleich ist fehlgeschlagen.'); }
        finally { sync.disabled = false; sync.textContent = 'ONEDRIVE-BILDER ABGLEICHEN'; }
      };
      const datedAction = (button, timestamp) => {
        const group = document.createElement('span'); group.className = 'dated-trip-action';
        const note = document.createElement('small'); note.style.cssText = 'font-size:11px;color:var(--muted)';
        const date = timestamp ? new Date(timestamp.replace(' ', 'T') + 'Z') : null;
        note.textContent = date && !Number.isNaN(date.getTime())
          ? `Zuletzt: ${new Intl.DateTimeFormat(globalThis.ReiseI18n?.language==='en'?'en-GB':'de-AT', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Vienna' }).format(date)}`
          : 'Noch nicht protokolliert';
        group.append(button, note); mediaActions.append(group);
      };
      if (trip.source_provider === 'onedrive') datedAction(sync, trip.media_synced_at);
      const captureDates = document.createElement('button'); captureDates.className = 'edit-link'; captureDates.textContent = 'AUFNAHMEDATEN AKTUALISIEREN';
      captureDates.disabled = trip.source_provider !== 'onedrive';
      captureDates.onclick = () => previewCaptureDates(trip, captureDates);
      if (trip.source_provider === 'onedrive') datedAction(captureDates, trip.capture_dates_updated_at);

}
// Web app on the computer (replaces the Windows app): Edge and Chrome offer an install prompt, which the
// settings show as a button; Safari and other browsers get the step-by-step page instead.
let webInstallPrompt = null;
if (typeof window !== 'undefined') {
  window.addEventListener('beforeinstallprompt', event => { event.preventDefault(); webInstallPrompt = event; document.dispatchEvent(new Event('atlas-install-changed')); });
  window.addEventListener('appinstalled', () => { webInstallPrompt = null; document.dispatchEvent(new Event('atlas-install-changed')); });
}
const webAppInstalled = () => matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
function renderWebInstallCard() {
  const card = document.createElement('section'); card.className = 'import-card native-install-card';
  const title = document.createElement('h2'); title.textContent = 'Als App installieren';
  const text = document.createElement('p'); text.className = 'hint';
  const button = document.createElement('button'); button.type = 'button'; button.className = 'primary'; button.textContent = 'ALS APP INSTALLIEREN';
  const guide = document.createElement('a'); guide.className = 'edit-link'; guide.href = './installieren.html'; guide.textContent = 'Anleitung für iPhone, iPad und Computer';
  const update = () => {
    const installed = webAppInstalled();
    card.dataset.state = installed ? 'installed' : webInstallPrompt ? 'ready' : 'manual';
    text.textContent = installed ? 'AlleReisen läuft bereits als App in einem eigenen Fenster.'
      : webInstallPrompt ? 'Öffnet AlleReisen künftig in einem eigenen Fenster, mit Symbol im Startmenü bzw. Dock.'
      : 'In Edge oder Chrome: Symbol „App installieren“ in der Adresszeile. In Safari: Teilen → „Zum Home-Bildschirm“ bzw. „Zum Dock hinzufügen“.';
    button.hidden = installed || !webInstallPrompt; guide.hidden = installed;
    card.closest('.settings-page')?.dispatchEvent(new Event('change'));
  };
  button.onclick = async () => { const prompt = webInstallPrompt; if (!prompt) return; button.disabled = true; try { await prompt.prompt(); await prompt.userChoice; } catch {} finally { webInstallPrompt = null; button.disabled = false; update(); } };
  document.addEventListener('atlas-install-changed', () => { if (card.isConnected) update(); });
  card.append(title, text, button, guide); update(); return card;
}
// 0.3.8: website settings in the same drop-down areas as the app (native-ui.js groupSettings): one open at a time,
// a status line while closed, the last open area remembered. Cards are only moved, their behaviour is unchanged.
function groupWebSettings(page) {
  const key = 'atlas-settings-open';
  const pick = selector => [...page.querySelectorAll(':scope > ' + selector)];
  const areas = [
    ['account', 'Anmeldung & Konto', [...pick('.settings-account'), ...pick('.admin-accounts'), ...pick('.all-trips-guest-access')]],
    ['automation', 'Reise-Automatik', pick('.native-automation-link')],
    ['options', 'Optionen', [...pick('.native-theme-card'), ...pick('.native-language'), ...pick('.native-install-card')]]
  ];
  page.querySelectorAll(':scope > .settings-group').forEach(node => node.remove());
  let open = ''; try { open = localStorage.getItem(key) || ''; } catch {}
  const sections = [];
  for (const [area, title, cards] of areas) {
    if (!cards.length) continue;
    const details = document.createElement('details'); details.className = 'settings-area'; details.dataset.area = area; details.setAttribute('name', 'settings-area');
    const summary = document.createElement('summary'), text = document.createElement('span'), name = document.createElement('span'), status = document.createElement('span');
    text.className = 'settings-area-text'; name.className = 'settings-area-title'; name.textContent = title; status.className = 'settings-area-status'; text.append(name, status); summary.append(text);
    details.append(summary, ...cards); details.open = area === open; sections.push(details);
    details.addEventListener('toggle', () => { if (details.open) { sections.forEach(other => { if (other !== details) other.open = false; }); try { localStorage.setItem(key, area); } catch {} } else if (!sections.some(other => other.open)) { try { localStorage.removeItem(key); } catch {} } });
  }
  const grid = page.querySelector('#adminTripGrid'); if (grid) grid.before(...sections); else page.append(...sections);
  const set = (area, value) => { const node = page.querySelector(`.settings-area[data-area="${area}"] .settings-area-status`); if (node) node.textContent = value; };
  const update = () => {
    set('account', [access.user?.display_name || access.user?.email, access.isAdmin ? 'Admin' : access.isGuest ? 'Gast' : ''].filter(Boolean).join(' · '));
    set('automation', 'Neue Fotos automatisch zu Reisen');
    const install = page.querySelector('.native-install-card')?.dataset.state;
    set('options', [page.querySelector('.native-theme-card select')?.selectedOptions[0]?.textContent, page.querySelector('.native-language select')?.selectedOptions[0]?.textContent, install === 'installed' ? 'als App installiert' : install === 'ready' ? 'als App installierbar' : ''].filter(Boolean).join(' · '));
  };
  update(); page.addEventListener('change', update);
}
function administration() {
  if (window.ReiseNativeUI) { window.ReiseNativeUI.archive({ app, go }); return; }
  // Trip administration for owners (own trips); app administration sections only for administrators.
  if (!access.loggedIn) { go('#home'); return; }
  app.replaceChildren(); headerTitle.textContent = 'EINSTELLUNGEN';
  const page = document.createElement('section'); page.className = 'library-page settings-page';
  page.innerHTML = '<div class="library-head"><div><p class="eyebrow">FÜR ALLE REISEN</p><h1>Deine <em>Einstellungen.</em></h1></div></div><p class="settings-intro">Was hier steht, gilt für alle Reisen. Texte, Stationen, Strecken und Fotos einer einzelnen Reise änderst du in der Reise über „Reise bearbeiten“.</p><h2 class="settings-group">Konto</h2><div class="settings-account"></div><div id="adminTripGrid" hidden></div>';
  { const host = page.querySelector('.settings-account'); const card = renderArchiveConnectionStatus(page, webArchiveStatusRows()); card.querySelector('h2')?.remove(); host.append(card);
    const links = [['Abmelden', './api/logout.php'], ['Mit anderem Microsoft-Konto anmelden', './login.html'], ['Gastzugang öffnen', './gastzugang.html']];
    for (const [label, url] of links) { const link = document.createElement('a'); link.className = 'edit-link'; link.textContent = label; link.href = url; host.append(link); }
    if (!access.isLocal) renderAccountDeletion(host);
    if (window.ReiseI18n) { const group = document.createElement('h2'); group.className = 'settings-group'; group.textContent = 'Allgemein'; const card = document.createElement('section'); card.className = 'import-card native-language'; const title = document.createElement('h2'); title.textContent = 'Sprache'; const select = document.createElement('select'); select.setAttribute('aria-label', 'Sprache');
      for (const [value, label] of [['', 'Wie Browser'], ['de', 'Deutsch'], ['en', 'English']]) { const option = document.createElement('option'); option.value = value; option.textContent = label; select.append(option); }
      select.value = ReiseI18n.choice || ''; select.onchange = () => { ReiseI18n.set(select.value || null); location.reload(); }; card.append(title, select); host.after(group, card); }
  }
  if (access.isAdmin || access.canConfigureAutomation) { const group = document.createElement('h2'); group.className = 'settings-group'; group.textContent = 'Verwaltung'; page.querySelector('#adminTripGrid').before(group);
    if (access.canConfigureAutomation) { const card = document.createElement('section'); card.className = 'import-card native-automation-link'; card.innerHTML = '<h2>Reise-Automatik</h2><p>Legt fest, wann der Server aus neuen Fotos in OneDrive automatisch Reisen erstellt: Entfernung von zu Hause, ruhige Tage, kleine Fotos.</p><a class="primary" href="./api/travel-automation-setup.php">Reise-Automatik einstellen</a>'; page.querySelector('#adminTripGrid').before(card); } }
  const allTripsGuestAccess = document.createElement('section');
  allTripsGuestAccess.className = 'all-trips-guest-access';
  allTripsGuestAccess.innerHTML = '<div><p class="eyebrow">ARCHIV-ZUGRIFF</p><h2>Zugriff auf <em>alle Reisen.</em></h2><p>Diese Gäste sehen auch alle künftig importierten Reisen.</p></div><form id="allTripsGuestForm"><label for="allTripsGuestEmail">E-Mail-Adresse</label><div><input id="allTripsGuestEmail" type="email" autocomplete="email" placeholder="gast@example.com" required><button class="primary">HINZUFÜGEN</button></div></form><div class="all-trips-guest-list" id="allTripsGuestList" aria-live="polite"></div>';
  page.querySelector('#adminTripGrid').before(allTripsGuestAccess);
  const adminAccounts = document.createElement('section'); adminAccounts.className = 'admin-accounts';
  adminAccounts.innerHTML = '<div><p class="eyebrow">VERWALTUNG</p><h2>Accounts mit Adminrechten</h2></div><div class="admin-account-list" aria-live="polite">Admin-Accounts werden geladen …</div>';
  allTripsGuestAccess.before(adminAccounts);
  if (access.isAdmin) {
    renderAdminAccounts(adminAccounts,page);
    renderImportPermissions(page,adminAccounts);
    if(access.canApprove)renderTripReview(page,adminAccounts);
  } else { adminAccounts.remove(); allTripsGuestAccess.remove(); }
  const guestList = allTripsGuestAccess.querySelector('#allTripsGuestList');
  const renderAllTripsGuests = async () => {
    try {
      const response = await apiFetch('./api/api.php?action=all-trips-guests', { credentials: 'same-origin', cache: 'no-store' });
      const data = await response.json().catch(() => ({})); if (!response.ok) throw new Error(data.error || 'Die Gäste konnten nicht geladen werden.');
      guestList.replaceChildren(); const guests = data.guests || [];
      if (!guests.length) { guestList.textContent = 'Noch kein dauerhafter Archivzugriff vergeben.'; return; }
      guests.forEach(guest => { const row = document.createElement('span'); row.textContent = guest.email; const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = '×'; remove.title = `${guest.email} entfernen`; remove.onclick = async () => { if (!confirm(`${guest.email} den Zugriff auf alle Reisen entziehen?`)) return; const response = await apiFetch('./api/api.php?action=all-trips-guests', { method: 'DELETE', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: guest.email }) }); if (!response.ok) { alert('Der Zugriff konnte nicht entfernt werden.'); return; } renderAllTripsGuests(); }; row.append(remove); guestList.append(row); });
    } catch (error) { guestList.textContent = error.message || 'Die Gäste konnten nicht geladen werden.'; }
  };
  allTripsGuestAccess.querySelector('#allTripsGuestForm').onsubmit = async event => { event.preventDefault(); const input = allTripsGuestAccess.querySelector('#allTripsGuestEmail'); const email = input.value.trim(); if (!email) return; const button = event.currentTarget.querySelector('button'); button.disabled = true; try { const response = await apiFetch('./api/api.php?action=all-trips-guests', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email }) }); const data = await response.json().catch(() => ({})); if (!response.ok) throw new Error(data.error || 'Der Zugriff konnte nicht gespeichert werden.'); input.value = ''; await renderAllTripsGuests(); } catch (error) { alert(error.message || 'Der Zugriff konnte nicht gespeichert werden.'); } finally { button.disabled = false; } };
  if (access.isAdmin) renderAllTripsGuests();
  const importButton = document.createElement('button');
  importButton.className = 'primary admin-import-button';
  importButton.textContent = 'NEUE REISE IMPORTIEREN';
  importButton.disabled = false;
  importButton.title = 'OneDrive, lokale Dateien oder Dropbox auswählen';
  importButton.onclick = () => go('#import');
  const grid = page.querySelector('#adminTripGrid');
  if (window.ReiseTheme) grid.before(window.ReiseTheme.card());
  if (!access.isLocal) grid.before(renderWebInstallCard());
  groupWebSettings(page);
  if (window.ReiseWebUI) { app.append(page); return; }
  if (access.isAdmin || access.canImport) page.querySelector('.library-head').append(importButton);
  apiFetch('./api/api.php?action=trips', { credentials: 'same-origin', cache: 'no-store' }).then(async response => {
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || 'Die Reisen konnten nicht geladen werden.');
    return data;
  }).then(data => {
    remoteTrips = data.trips || [];
    const records = access.isAdmin ? remoteTrips : remoteTrips.filter(trip => Number(trip.is_owner) === 1);
    records.forEach(trip => {
      const card = document.createElement('article'); card.className = 'trip-card';
      const coverUrl = trip.cover_url || (trip.cover_media_id ? `./api/api.php?action=preview&trip=${encodeURIComponent(trip.id)}&media=${encodeURIComponent(trip.cover_media_id)}&size=large` : '');
      const cover = '<div class="trip-cover"></div>';
      card.innerHTML = `${cover}<div class="trip-card-copy"><p>REISEDOKUMENTATION</p><h3></h3><small>${trip.media_count || 0} Medien · ${({onedrive:'OneDrive',local:'Lokal',dropbox:'Dropbox',mixed:'Mehrere Quellen'})[trip.source_provider] || 'OneDrive'}</small><div class="admin-trip-actions"></div></div>`;
      card.querySelector('h3').textContent = trip.title;
      if(trip.publication_status==='pending'){const badge=document.createElement('p');badge.className='trip-pending';badge.textContent='Freigabe ausstehend';card.querySelector('.trip-card-copy').prepend(badge);}
      if (coverUrl) {
        const coverImage = document.createElement('img'); coverImage.className = 'trip-cover-image';
        coverImage.src = coverUrl; coverImage.alt = ''; coverImage.loading = 'lazy'; coverImage.decoding = 'async';
        card.querySelector('.trip-cover').append(coverImage);
      }
      const actions = card.querySelector('.admin-trip-actions');
      appendTripOpenActions(actions, trip.id);
      const remove = document.createElement('button'); remove.className = 'delete-trip'; remove.textContent = 'REISE LÖSCHEN';
      remove.onclick = () => eraseTrip(trip.id); card.querySelector('.trip-card-copy').append(remove);
      grid.append(card);
    });
    if (!records.length) grid.textContent = 'Keine Reisen vorhanden.';
    if (window.ReisePendingGuestTrip) {
      const note = document.createElement('p'); note.setAttribute('role','status');
      note.textContent = 'Diese Reise ist auf der Website nicht vorhanden oder für dieses Konto nicht zugänglich. Übertrage sie zuerst aus der App auf die Website oder melde dich mit dem passenden Administratorkonto an.';
      grid.before(note); window.ReisePendingGuestTrip = null;
      try { sessionStorage.removeItem('reise-website-guest-trip'); } catch {}
    }
  }).catch(() => { grid.textContent = 'Die Reisen konnten nicht geladen werden.'; });
  app.append(page);
}


const adminMenu = document.querySelector('#adminMenu');
if (adminMenu) adminMenu.onclick = () => go('#admin');
const logoutMenu = document.querySelector('#logoutMenu');
if (logoutMenu) logoutMenu.onclick = () => location.assign('./api/logout.php');
document.querySelector('.brand')?.addEventListener('click', event => {
  event.preventDefault();
  location.hash = '#home';
  render();
});

// Die Weltkarte bleibt frei von Inhaltsfenstern; die Navigation liegt im Header.

document.querySelector('#mapMenu')?.addEventListener('click', () => go('#home'));

// "Alle Reisen" ist das reine Reisearchiv. Änderungen an Reisedaten gehören
// ausschließlich in die Administration, damit Leser keine Verwaltungsaktionen
// neben den Reisen vorfinden.

// Die Sitzung muss vor dem Archiv geladen werden. Frühere parallele Abrufe
// konnten nach einem Login mit einer 401-Antwort enden und das Archiv leer
// lassen, obwohl die Sitzung kurz darauf gültig war.
async function initializeAuthenticatedArchive() {
  if (window.ReiseNativeUI) {
    access = { loggedIn: true, isAdmin: true, isMicrosoft: false, isLocal: true };
    if (adminMenu) adminMenu.hidden = false;
    if (logoutMenu) logoutMenu.hidden = true;
    const helpButton = document.querySelector('#helpMenu');
    if (helpButton) helpButton.hidden = false;
    render();
    return;
  }
  prepareWebsiteGuestLink();
  try {
    const sessionResponse = await apiFetch('./api/api.php?action=session', { credentials: 'same-origin', cache: 'no-store' });
    const session = sessionResponse.ok ? await sessionResponse.json() : null;
    access = { loggedIn: !!session?.logged_in, isAdmin: !!session?.is_admin, canImport: !!session?.can_import, canApprove: !!session?.can_approve_trips, isMicrosoft: !!session?.is_microsoft, isLocal: !!session?.local_archive,isGuest:!!session?.is_guest, user:session?.user||null,canConfigureAutomation:!!session?.travel_automation_available, homeLocation:session?.home_location||null };
    if (!access.loggedIn) { rememberSharedTrip(); location.assign(localStorage.getItem('atlas-last-auth-method') === 'guest' ? './gastzugang.html' : './login.html'); return; }
    localStorage.setItem('atlas-last-auth-method', session.is_guest ? 'guest' : 'microsoft');
    if (adminMenu) adminMenu.hidden = !access.isAdmin;
    if (logoutMenu) logoutMenu.hidden = !!session.local_archive;
    const onlineArchive = document.querySelector('#onlineArchiveMenu');
    if (session.local_archive) {
      document.querySelector('#archiveMenu').textContent = 'LOKALE REISEN';
      if (onlineArchive && session.online_archive_url) {
        try {
          const url = new URL(session.online_archive_url);
          if (url.protocol === 'https:') { onlineArchive.href = url.href.replace(/\/$/, '') + '/#library'; onlineArchive.hidden = false; }
        } catch {}
      }
    }
    const helpButton = document.querySelector('#helpMenu');
    if (helpButton) helpButton.hidden = false;

    const tripsResponse = await apiFetch('./api/api.php?action=trips', { credentials: 'same-origin', cache: 'no-store' });
    const payload = tripsResponse.ok ? await tripsResponse.json() : null;
    if (!payload?.trips) throw new Error('Archiv konnte nicht geladen werden.');
    remoteTrips = payload.trips;
    removeStaleTripAliases();
    discardImportedTripCache();
    if (window.ReisePendingGuestTrip && access.isAdmin) {
      if (window.ReiseWebUI) ReiseWebUI.archiveEditing = true;
      location.hash = '#trip/'+encodeURIComponent(window.ReisePendingGuestTrip)+'/days';
    }
    openSharedTrip();
    render();
  } catch (error) {
    const message = document.createElement('p');
    message.className = 'world-map-error';
    message.textContent = 'Das Reisearchiv konnte nicht geladen werden. Bitte melde dich erneut an.';
    app.append(message);
  }
}

function prepareWebsiteGuestLink() {
  const params = new URLSearchParams(location.search), supplied = params.get('invite-trip');
  let pending = supplied;
  try {
    if (supplied) sessionStorage.setItem('reise-website-guest-trip', supplied);
    else pending = sessionStorage.getItem('reise-website-guest-trip');
  } catch {}
  // The link selects a trip only. Permissions and invitations remain server-side.
  window.ReisePendingGuestTrip = pending && /^[a-zA-Z0-9-]{1,128}$/.test(pending) ? pending : null;
  if (supplied) {
    params.delete('invite-trip');
    history.replaceState(null, '', location.pathname + (params.size ? '?' + params : '') + location.hash);
  }
}


document.addEventListener('click', event => {
  const button = event.target.closest('#adminTripGrid .primary');
  if (!button) return;
  event.preventDefault(); event.stopImmediatePropagation();
  const title = button.closest('.trip-card')?.querySelector('h3')?.textContent || '';
  apiFetch('./api/api.php?action=trips', { credentials: 'same-origin' }).then(response => response.ok ? response.json() : null).then(data => {
    const trip = (data?.trips || []).find(record => record.title === title);
    if (!trip) return;
    sessionStorage.setItem('atlas-focus-trip', String(trip.id));
    go('#home');
  });
}, true);

let worldMapRenderVersion = 0;
async function loadWorldMapStops() {
  let lastError;
  for (let attempt = 0; attempt < 2; attempt++) {
    try {
      const response = await apiFetch('./api/api.php?action=map-stops', { credentials: 'same-origin', cache: 'no-store' });
      const data = await response.json();
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      numberTripStations(data.stops||[]);
      return data;
    } catch (error) {
      lastError = error;
      if (attempt === 0) await new Promise(resolve => setTimeout(resolve, 400));
    }
  }
  throw lastError;
}
let tripNavigationVersion = 0;
async function openServerTrip(tripId, stationId = '') {
  const id = String(tripId || '');
  if (!id) return;
  if (!/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i.test(id)) {
    go(`#trip/${encodeURIComponent(id)}`);
    return;
  }
  const requestVersion = ++tripNavigationVersion;
  let trip = remoteTrips.find(record => String(record.id) === id);
  if (!trip) {
    try {
      const response = await apiFetch('./api/api.php?action=trips', { credentials: 'same-origin', cache: 'no-store' });
      const data = await response.json();
      if (!response.ok) throw new Error();
      if (requestVersion !== tripNavigationVersion) return;
      remoteTrips = data.trips || [];
      trip = remoteTrips.find(record => String(record.id) === id);
    } catch { alert('Die Reise konnte nicht geladen werden.'); return; }
  }
  if (!trip || requestVersion !== tripNavigationVersion) { alert('Die Reise ist nicht mehr verfügbar.'); return; }
  go(`#trip/${trip.id}${stationId ? `/station/${encodeURIComponent(stationId)}` : ''}`);
}

// Weltkarte: Fotos werden erst in der Detail-Zoomstufe geladen. Dadurch bleibt
// die Übersicht schnell und klar, während im Reisegebiet die Bildmarker erscheinen.


function editMediaTime(tripId, stopId, item) {
  const dialog = document.createElement('dialog'), form = document.createElement('form');
  form.className = 'dialog-card media-time-editor';
  form.innerHTML = '<button type="button" class="close" aria-label="Schließen">×</button><p class="eyebrow">MANUELLE KORREKTUR</p><h2>Aufnahmezeit ändern</h2><p class="media-time-name"></p><label>Aufnahmedatum und Uhrzeit<input type="datetime-local" step="1" required></label><p>Bei geändertem Datum wird die Aufnahme dem passenden Reisetag zugeordnet. Die manuelle Zeit bleibt bei automatischen Aktualisierungen erhalten.</p><p class="media-time-error" role="alert"></p><button type="submit" class="primary">SPEICHERN</button>';
  form.querySelector('.media-time-name').textContent = item.name || 'Aufnahme';
  const input = form.querySelector('input'); input.value = item.taken_at?.replace(' ', 'T') || '';
  form.querySelector('.close').onclick = () => dialog.close();
  form.onsubmit = async event => {
    event.preventDefault(); if (!form.reportValidity()) return;
    const save = form.querySelector('[type="submit"]'), error = form.querySelector('.media-time-error');
    save.disabled = true; error.textContent = '';
    const value = input.value.length === 16 ? input.value + ':00' : input.value;
    try {
      const response = await apiFetch('./api/api.php?action=media-time', { method: 'PATCH', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ trip: tripId, media: item.id, taken_at: value, expected_taken_at: item.taken_at, expected_capture_source: item.capture_source ?? null }) });
      const result = await response.json(); if (!response.ok) throw new Error(result.error || 'Die Aufnahmezeit konnte nicht gespeichert werden.');
      dialog.close();
      if (location.hash === `#trip/${tripId}/station/${stopId}`) {
        if (result.stop_id && String(result.stop_id) !== String(stopId)) go(`#trip/${tripId}/station/${result.stop_id}`);
        else serverPlace(tripId, stopId);
      }
    } catch (failure) { error.textContent = failure.message || 'Die Aufnahmezeit konnte nicht gespeichert werden.'; }
    finally { save.disabled = false; }
  };
  dialog.append(form); dialog.addEventListener('close', () => dialog.remove()); document.body.append(dialog); dialog.showModal(); input.focus();
}

function stationMapLink(tripId, stop) {
  if (stop.latitude == null || stop.longitude == null || !Number.isFinite(Number(stop.latitude)) || !Number.isFinite(Number(stop.longitude))) return null;
  const link = document.createElement('a');
  link.className = 'station-map-link';
  link.href = `#trip/${encodeURIComponent(tripId)}/map/${encodeURIComponent(stop.id)}`;
  link.textContent = 'STANDORT AUF DER KARTE';
  link.setAttribute('aria-label', `${stop.name || 'Station'} im maximalen Kartenzoom öffnen`);
  return link;
}

let unifiedDayMap = null;
/** Administrator or owner of this trip, independent of the management mode (replaces former plain admin checks). */
function mayEditTrip(tripId) {
  if (access.isAdmin) return true;
  return !!tripId && !access.isGuest && (typeof remoteTrips !== 'undefined' ? remoteTrips : []).some(t => String(t.id) === String(tripId) && Number(t.is_owner) === 1);
}
/** Signed-in (non-guest) person owns at least one trip on the server. */
function ownsAnyTrip() {
  return !access.isGuest && (typeof remoteTrips !== 'undefined' ? remoteTrips : []).some(t => Number(t.is_owner) === 1);
}
// Trip administration: administrators edit every trip, signed-in owners their own trips
// (server flag is_owner; the server enforces the same rule).
function canEditTrip(tripId) {
  tripId ??= globalThis.location?.hash.match(/^#trip\/([^/]+)/)?.[1];
  const owner = !!tripId && !access.isGuest && (typeof remoteTrips !== 'undefined' ? remoteTrips : []).some(t => String(t.id) === String(tripId) && Number(t.is_owner) === 1);
  if (!access.isAdmin && !owner) return false;
  const controls=globalThis.ReiseNativeUI||globalThis.ReiseWebUI;
  if (!controls) return true;
  if(globalThis.ReiseNativeUI?.isReadOnlyTrip?.(tripId))return false;
  // Editing controls show only while the edit mode of this trip is on (see openTripEditor).
  return tripEditing(tripId);
}
function numberTripStations(stops) {
  const groups=new Map();
  for(const stop of stops){if(String(stop.id).startsWith('main-location-'))continue;const key=String(stop.trip_id||'');if(!groups.has(key))groups.set(key,[]);groups.get(key).push(stop);}
  for(const group of groups.values()){
    const days=[...new Set(group.map(stop=>String(stop.starts_at||'').slice(0,10)).filter(Boolean))].sort(),counts=new Map();
    group.forEach((stop,index)=>{const day=String(stop.starts_at||'').slice(0,10),station=(counts.get(day)||0)+1;counts.set(day,station);stop.stationOrder=index+1;stop.dayNumber=day?days.indexOf(day)+1:null;stop.stationInDay=station;stop.stationNumber=`${stop.dayNumber??'?'}-${station}`;});
  }
  return stops;
}
function firstStationInGroup(stops, ids) {
  const selected = new Set(ids.map(String));
  return stops.filter(stop => selected.has(String(stop.id))).sort((a,b) => String(a.starts_at||'9999').localeCompare(String(b.starts_at||'9999')) || Number(a.stationOrder||a.map_position||0)-Number(b.stationOrder||b.map_position||0))[0];
}
async function startStationGroupSlideshow(stops, ids, tripId) {
  const first=firstStationInGroup(stops,ids);if(!first)return;
  tripId=tripId||first.trip_id;
  const response=await apiFetch('./api/api.php?action=stops&trip='+encodeURIComponent(tripId),{credentials:'same-origin'});
  if(!response.ok)throw new Error('Die Stationen konnten nicht geladen werden.');
  const itinerary=numberTripStations((await response.json()).stops||[]),start=itinerary.findIndex(stop=>String(stop.id)===String(first.id));
  if(start<0)return;
  for(let i=start;i<itinerary.length;i++){
    const stop=itinerary[i],result=await apiFetch('./api/api.php?action=media&trip='+encodeURIComponent(tripId)+'&stop='+encodeURIComponent(stop.id),{credentials:'same-origin'});
    if(!result.ok)throw new Error('Die Aufnahmen konnten nicht geladen werden.');
    const media=(await result.json()).media||[];if(!media.length)continue;
    document.querySelector('.media-lightbox')?.close();go(`#trip/${tripId}/station/${stop.id}`);
    const day=stopDay(stop.starts_at),days=[...new Set(itinerary.map(stop=>stopDay(stop.starts_at)).filter(Boolean))].sort();
    openMediaLightbox(media.map(item=>({tripId,stopId:stop.id,mediaId:item.id,type:item.media_type,name:item.name,webUrl:item.web_url})),0,{currentStop:stop,allStops:itinerary,nextStationIds:itinerary.slice(i+1).filter(entry=>stopDay(entry.starts_at)===day).map(entry=>entry.id),nextDay:days[days.indexOf(day)+1]||'',stationText:stop.summary||''});return;
  }
  throw new Error('Für diese Stationen sind keine abspielbaren Aufnahmen vorhanden.');
}
function stationLabel(stop) {
  const number = stop.stationNumber;
  return number ? `Station ${number} · ${stop.name || 'Unbenannte Station'}` : stop.name || 'Station';
}
async function mountAppleStationMap(host, stops, tripId, { active = '', focus = '', onSelect, dayConnections = false } = {}) {
  const located = stops.filter(dayStationCoordinates);
  const map = await window.ReiseAppleMaps.mount(host, {
    markers: located.map(stop => ({ id: String(stop.id), title: stationLabel(stop), number: stop.stationNumber, latitude: Number(stop.latitude), longitude: Number(stop.longitude), tripId, mediaId: stop.preview_id || '', color: stationDotColor(tripRouteColor(tripId)) })),
    active, focus, onSelect, onDoubleSelect:id=>startStationGroupSlideshow(stops,[id],tripId).catch(error=>alert(error.message)), onCluster: ids => startStationGroupSlideshow(stops,ids,tripId).catch(error=>alert(error.message))
  });
  if (!map) return null;
  map.connections=new Map();
  const routeStops = dayConnections ? stops : located;
  const refresh = () => routeStops.slice(1).forEach((to, index) => {
    const from = routeStops[index];
    if (!dayStationCoordinates(from) || !dayStationCoordinates(to)) return;
    const start = [Number(from.latitude), Number(from.longitude)], end = [Number(to.latitude), Number(to.longitude)];
    map.connections.set(String(index),{tripId,fromId:String(from.id),toId:String(to.id)});
    resolveWaterRoute(start, end, tripId).then(points => {
      if (!map.removed && !points?.error) return map.route(String(index), points?.length >= 2 ? points : [start, end], tripRouteColor(tripId));
    }).catch(() => {});
  });
  refresh();
  const changed = event => { if (String(event.detail.trip) === String(tripId)) refresh(); };
  window.addEventListener('atlas-route-changed', changed);
  const remove = map.remove;
  map.remove = () => { window.removeEventListener('atlas-route-changed', changed); remove(); };
  return map;
}
function bindStationEntrySlideshow(button, tripId, stopId) {
  const target = `#trip/${tripId}/station/${stopId}`;
  let clickTimer;
  button.onclick = event => {
    clearTimeout(clickTimer);
    if (event.detail === 0) { go(target); return; }
    clickTimer = setTimeout(() => { if (button.isConnected) go(target); }, 350);
  };
  button.ondblclick = event => {
    event.preventDefault(); clearTimeout(clickTimer);
    pendingStationMedia = { tripId, stopId, first: true };
    if (location.hash === target) serverPlace(tripId, stopId);
    else go(target);
  };
}
function addDayStationConnections(map, L, stops, tripId) {
  const group = L.layerGroup().addTo(map);
  for (let index = 1; index < stops.length; index++) {
    const from = stops[index - 1], to = stops[index];
    if (!dayStationCoordinates(from) || !dayStationCoordinates(to)) continue;
    const start = [Number(from.latitude), Number(from.longitude)], end = [Number(to.latitude), Number(to.longitude)];
    if (start[0] === end[0] && start[1] === end[1]) continue;
    group.addLayer(addRouteDirectionArrows(map, [start, end], '#55745c', tripId, { weight: 2, opacity: .65, avoidPoints: stops.filter(dayStationCoordinates).map(stop => [Number(stop.latitude), Number(stop.longitude)]) }));
  }
  return group;
}
function dayStationCoordinates(stop) {
  return stop.latitude != null && stop.longitude != null && Number.isFinite(Number(stop.latitude)) && Number.isFinite(Number(stop.longitude)) && Math.abs(Number(stop.latitude)) <= 90 && Math.abs(Number(stop.longitude)) <= 180;
}
function focusActiveStationOnZoom(map, getStop) {
  let previousZoom = map.getZoom();
  let frame = 0;
  const remember = () => { previousZoom = map.getZoom(); };
  const focus = () => {
    const stop = getStop();
    if (map.getZoom() > previousZoom && dayStationCoordinates(stop || {})) {
      cancelAnimationFrame(frame);
      frame = requestAnimationFrame(() => { const active = getStop(); if (dayStationCoordinates(active || {})) map.panTo([Number(active.latitude), Number(active.longitude)], { animate: false }); });
    }
  };
  map.on('zoomstart', remember); map.on('zoomend', focus);
  map.once('unload', () => { cancelAnimationFrame(frame); map.off('zoomstart', remember); map.off('zoomend', focus); });
}
async function mountDayStationMap(page, tripId, day, stops, activeId) {
  const key = `${tripId}/${day}`;
  const host = page.querySelector('.day-map-host');
  if (unifiedDayMap?.key !== key) {
    unifiedDayMap?.locationCleanup?.();
    unifiedDayMap?.map?.remove();
    unifiedDayMap = { key, node: Object.assign(document.createElement('div'), { className: 'day-stations-map' }), markers: [] };
    unifiedDayMap.node.setAttribute('aria-label', 'Karte der Tagesstationen');
  }
  const state = unifiedDayMap;
  state.activeStop = stops.find(stop => String(stop.id) === String(activeId));
  host.append(state.node);
  const located = stops.filter(dayStationCoordinates);
  try {
    if (window.ReiseAppleMaps && await window.ReiseAppleMaps.available()) {
      if (!page.isConnected || unifiedDayMap !== state) return;
      if (!state.map || state.map.removed) {state.map = await mountAppleStationMap(state.node, stops, tripId, { active: activeId, dayConnections: true, onSelect: id => go(`#trip/${tripId}/station/${id}`) });if(state.map)installAppleRouteEditor(state.node,state.map,stops,tripId);}
      await state.map?.setActive(activeId);
      state.map?.invalidateSize();
      host.querySelector('.day-map-note')?.remove();
      if (!located.some(stop => String(stop.id) === String(activeId))) host.append(Object.assign(document.createElement('p'), { className: 'day-map-note', textContent: 'Die aktive Station hat keine gespeicherte Position.' }));
      return;
    }
    const L = await loadLeaflet();
    if (!page.isConnected || unifiedDayMap !== state) return;
    if (!state.map) {
      state.node.replaceChildren();
      state.map = L.map(state.node, { scrollWheelZoom: false, zoomControl: true, attributionControl: true });
      if (typeof addCoastalLabels === 'function') addCoastalLabels(state.map, L);
      L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, maxNativeZoom: 16, opacity: .78, attribution: 'Tiles &copy; Esri' }).addTo(state.map);
      located.forEach((stop,index) => {
        const marker = L.marker([Number(stop.latitude), Number(stop.longitude)], { keyboard: true }).addTo(state.map);
        const label = document.createElement('span'); label.textContent = stop.name || 'Station'; marker.bindTooltip(label);
        const open=() => state.selectLocation ? state.selectLocation(marker.getLatLng()) : go(`#trip/${tripId}/station/${stop.id}`);
        if(!window.ReiseIcons)marker.on('click',open);
        state.markers.push({marker,stop,tripId,open,number:stop.stationNumber||stops.indexOf(stop)+1,role:[index===0?'station-first':'',index===located.length-1?'station-last':''].filter(Boolean).join(' ')});
      });
      if (located.length) state.map.fitBounds(L.latLngBounds(located.map(stop => [Number(stop.latitude), Number(stop.longitude)])), { padding: [28,28], maxZoom: 14 });
      else state.map.setView([20,10], 2);
      if(window.ReiseIcons){state.adaptive=true;installAdaptiveStationMarkers(state.map,L,state.markers);}
      focusActiveStationOnZoom(state.map, () => state.selectLocation ? null : state.activeStop);
      addDayStationConnections(state.map, L, stops, tripId);
    }
    state.markers.forEach(({ marker, stop }) => {
      const active = String(stop.id) === String(activeId);
      if(!state.adaptive)marker.setIcon(L.divIcon({ className: `day-station-dot${active ? ' active' : ''}`, iconSize: [active ? 22 : 12, active ? 22 : 12], iconAnchor: [active ? 11 : 6, active ? 11 : 6] }));
      marker.setZIndexOffset(active ? 1000 : 0);
      const element = marker.getElement();element?.classList.toggle('station-active',active);element?.setAttribute('aria-label', `${stop.name || 'Station'}${active ? ', aktive Station' : ''}`);
      if (active) element?.setAttribute('aria-current', 'true'); else element?.removeAttribute('aria-current');
    });
    state.map.invalidateSize({ pan: false });
    if (!stops.some(stop => String(stop.id) === String(activeId) && dayStationCoordinates(stop))) {
      host.querySelector('.day-map-note')?.remove();
      host.append(Object.assign(document.createElement('p'), { className: 'day-map-note', textContent: 'Die aktive Station hat keine gespeicherte Position.' }));
    }
  } catch(error) { console.warn('Tageskarte konnte nicht initialisiert werden.',error);if (page.isConnected) state.node.textContent = 'Die Tageskarte konnte nicht geladen werden.'; }
}

function handleLibraryTripOpen(event) {
  const link = event.target.closest('.library-page .trip-open');
  if (!link) return;
  if (link.getAttribute('href')?.endsWith('/days')) return;
  event.preventDefault(); event.stopImmediatePropagation();
  const tripId = link.dataset.serverTripId || link.getAttribute('href')?.match(/^#trip\/([^/]+)/)?.[1];
  if (!tripId) return;
  openServerTrip(tripId);
}
document.addEventListener('click', handleLibraryTripOpen, true);

// Eine Weltkarten-URL enthält immer die Server-ID. Für diese Navigation darf
// kein lokaler Browserentwurf aufgelöst werden, sonst können Route und Titel
// aus unterschiedlichen Reisen stammen.

// Finale, eigenständige Kartenansicht für eine Reise. Sie hat einen festen
// Container und verwendet ausschließlich die Stationen dieser Reise.
// Uniform stages; hysteresis avoids flicker around the zoom thresholds.
function stationMarkerModes(points, zoom, previous = 'dot') {
  const mode=previous==='photo'&&zoom>=10.5?'photo':zoom>=11?'photo':previous!=='dot'&&zoom>=6.5?'number':zoom>=7?'number':'dot';
  return points.map(() => mode);
}

function installAdaptiveStationMarkers(map, L, entries) {
  let stage='dot';
  const previewUrl = entry => entry.stop.preview_id
    ? `./api/api.php?action=preview&trip=${encodeURIComponent(entry.tripId)}&media=${encodeURIComponent(entry.stop.preview_id)}&size=small` : '';
  const description = entry => [entry.role.includes('station-first') ? 'Start' : '', entry.role.includes('station-last') ? 'Ziel' : '',
    `Station ${entry.number}`, entry.stop.name || 'Unbenannte Station', (entry.stop.starts_at || '').slice(0, 10)].filter(Boolean).join(' · ');
  const update = () => {
    const visible = entries.filter(entry => map.hasLayer(entry.marker));
    const modes = stationMarkerModes(visible, map.getZoom(),stage);if(modes.length)stage=modes[0];
    visible.forEach((entry, index) => {
      // 0.4: dots far out, small numbered dots in the trip colour in between, photos close up.
      const mode = modes[index] === 'photo' && (!previewUrl(entry) || entry.imageFailed) ? 'number' : modes[index];
      if (entry.mode === mode) return;
      entry.mode = mode;
      const size = mode === 'photo' ? 54 : mode === 'number' ? Math.max(22,String(entry.number).length*7+10) : 12;
      const content = document.createElement('div');
      content.className = 'station-symbol';
      if (mode === 'photo') {
        const image = document.createElement('img'); image.alt = ''; image.src = previewUrl(entry);
        image.addEventListener('error', () => { entry.imageFailed = true; update(); }, { once: true });
        content.append(image);
      } else if (mode === 'number') content.textContent = entry.number;
      if (entry.dot) content.style.setProperty('--station-dot', entry.dot);
      entry.marker.setIcon(L.divIcon({ className: `route-station-marker marker-${mode} ${entry.role}`, html: content, iconSize: [size,size], iconAnchor: [size/2,size/2] }));
      entry.marker.getElement()?.setAttribute('aria-label', description(entry));
    });
  };
  entries.forEach(entry => {
    const tooltip = document.createElement('span'); tooltip.textContent = description(entry);
    entry.marker.bindTooltip(tooltip, { direction: 'top', offset: [0,-12] });
    let entryClickTimer;
    entry.marker.on('click',()=>{clearTimeout(entryClickTimer);entryClickTimer=setTimeout(()=>{if(map.getContainer().isConnected)entry.open();},420);});
    entry.marker.on('dblclick',event=>{clearTimeout(entryClickTimer);if(event.originalEvent)L.DomEvent.stop(event.originalEvent);if(String(entry.stop.id).startsWith('main-location-')){entry.open();return;}startStationGroupSlideshow(entries.map(item=>item.stop).filter(Boolean),[entry.stop.id],entry.tripId||entry.stop.trip_id).catch(error=>alert(error.message));});
    map.once('unload',()=>clearTimeout(entryClickTimer));
    entry.marker.on('move', update);
  });
  let frame = 0;
  const schedule = () => {
    if (!frame) frame = requestAnimationFrame(() => { frame = 0; update(); });
  };
  map.on('zoomend moveend resize layeradd layerremove', schedule);
  map.once('unload', () => {
    map.off('zoomend moveend resize layeradd layerremove', schedule);
    cancelAnimationFrame(frame);
  });
  update();
}

let waterRoutingWorker;
let waterRoutingRequestId = 0;
const waterRoutingPending = new Map();
const waterRoutingCache = new Map();
function computeWaterRoute(from, to) {
  const key = JSON.stringify([from, to]);
  if (waterRoutingCache.has(key)) return waterRoutingCache.get(key);
  const promise = new Promise(resolve => {
    try {
      if (!waterRoutingWorker) {
        waterRoutingWorker = new Worker('./water-routing-worker.js?v=20261002-4');
        waterRoutingWorker.onmessage = event => {
          const pending = waterRoutingPending.get(event.data.id);
          if (!pending) return;
          waterRoutingPending.delete(event.data.id);
          pending(event.data.error ? { error: true } : event.data.points);
        };
        waterRoutingWorker.onerror = () => {
          waterRoutingWorker.terminate(); waterRoutingWorker = null;
          waterRoutingPending.forEach(done => done({ error: true })); waterRoutingPending.clear(); waterRoutingCache.clear();
        };
      }
      const id = ++waterRoutingRequestId;
      waterRoutingPending.set(id, resolve);
      waterRoutingWorker.postMessage({ id, from, to });
    } catch { resolve({ error: true }); }
  });
  waterRoutingCache.set(key, promise);
  promise.then(result => { if (result?.error) waterRoutingCache.delete(key); });
  if (waterRoutingCache.size > 300) waterRoutingCache.delete(waterRoutingCache.keys().next().value);
  return promise;
}

const serverWaterRoutingCache = new Map();
let routeDatabasePromise;
function routeDatabase() {
  if (!globalThis.indexedDB) return Promise.resolve(null);
  if (!routeDatabasePromise) routeDatabasePromise = new Promise(resolve => {
    try {
      const request = indexedDB.open('atlas-water-routes', 1);
      request.onupgradeneeded = () => request.result.createObjectStore('routes');
      request.onsuccess = () => resolve(request.result);
      request.onerror = request.onblocked = () => resolve(null);
    } catch { resolve(null); }
  });
  return routeDatabasePromise;
}
async function persistentRoute(key, value) {
  const db = await routeDatabase();
  if (!db) return undefined;
  return new Promise(resolve => {
    try {
      const writing = value !== undefined;
      const transaction = db.transaction('routes', writing ? 'readwrite' : 'readonly');
      const store = transaction.objectStore('routes');
      const request = writing ? store.put(value, key) : store.get(key);
      let result;
      request.onsuccess = () => { result = request.result; };
      transaction.oncomplete = () => resolve(writing ? value : result);
      transaction.onerror = transaction.onabort = () => resolve(undefined);
    } catch { resolve(undefined); }
  });
}
let routeCacheRequests = 0;
const routeCacheWaiters = [];
async function requestRouteCache(url, options) {
  if (routeCacheRequests >= 6) await new Promise(resolve => routeCacheWaiters.push(resolve));
  else routeCacheRequests++;
  try {
    const response = await apiFetch(url, { ...options, signal: globalThis.AbortSignal?.timeout?.(8000) });
    return { ok: response.ok, data: await response.json() };
  } finally {
    const next = routeCacheWaiters.shift();
    if (next) next(); else routeCacheRequests--;
  }
}
function resolveWaterRoute(from, to, tripId, automatic = false) {
  if (!automatic && tripId && globalThis.AtlasManualRoutes) return globalThis.AtlasManualRoutes.resolve(tripId, from, to, () => resolveWaterRoute(from, to, tripId, true));
  if (!tripId) return computeWaterRoute(from, to);
  const version = '20261002-4';
  const key = JSON.stringify([version, tripId, from, to]);
  if (serverWaterRoutingCache.has(key)) return serverWaterRoutingCache.get(key);
  const promise = (async () => {
    const params = { trip: tripId, from, to, version };
    const stored = await persistentRoute(key);
    if (stored && (stored.points === null || Array.isArray(stored.points))) return stored.points;
    try {
      const query = new URLSearchParams({ trip: tripId, from: JSON.stringify(from), to: JSON.stringify(to), version: params.version });
      const { ok, data } = await requestRouteCache(`./api/api.php?action=route-cache&${query}`, { credentials: 'same-origin' });
      if (ok && data.hit && (data.points === null || Array.isArray(data.points))) {
        await persistentRoute(key, { points: data.points });
        return data.points;
      }
    } catch { /* A cache outage must not prevent local route calculation. */ }
    const points = await computeWaterRoute(from, to);
    if (!points?.error) {
      try {
        await requestRouteCache('./api/api.php?action=route-cache', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...params, points }) });
      } catch { /* Keep the calculated route usable if saving fails. */ }
      await persistentRoute(key, { points });
    }
    return points;
  })();
  serverWaterRoutingCache.set(key, promise);
  promise.then(result => { if (result?.error) serverWaterRoutingCache.delete(key); });
  if (serverWaterRoutingCache.size > 2000) serverWaterRoutingCache.delete(serverWaterRoutingCache.keys().next().value);
  return promise;
}
function addRouteDirectionArrows(map, points, color, tripId, style = {}) {
  const route = L.layerGroup().addTo(map);
  let unloaded = false;
  map.once('unload', () => { unloaded = true; });
  const markerId = `route-arrow-${map._leaflet_id}-${color.replace(/[^a-z0-9]/gi, '')}`;
  points.slice(0, -1).forEach((from, index) => {
    const to = points[index + 1];
    // One direct geographic segment avoids bends caused by screen-pixel
    // midpoint calculations when Leaflet changes its zoom.
    const segment = L.polyline([from, to], { color, weight: style.weight || 2.4, opacity: 0, lineCap: 'round', lineJoin: 'round' }).addTo(route);
    const fromPoint = map.project(from);
    const toPoint = map.project(to);
    const middle = map.unproject(fromPoint.add(toPoint.subtract(fromPoint).multiplyBy(.5)));
    const angle = Math.atan2(toPoint.y - fromPoint.y, toPoint.x - fromPoint.x) * 180 / Math.PI;
    const arrow = L.marker(middle, {
      interactive: false,
      keyboard: false,
      opacity: 0,
      zIndexOffset: style.avoidPoints ? -1000 : 0,
      icon: L.divIcon({ className: 'route-direction-arrow', html: `<span style="--direction:${angle}deg">&#10148;</span>`, iconSize: [20, 20], iconAnchor: [10, 10] })
    }).addTo(route);
    let ready = false;
    const avoidStations = () => {
      if (!ready || unloaded || !style.avoidPoints) return;
      const point = map.latLngToContainerPoint(arrow.getLatLng());
      const blocked = style.avoidPoints.some(position => point.distanceTo(map.latLngToContainerPoint(position)) < 28);
      arrow.setOpacity(blocked ? 0 : 1);
    };
    if (style.avoidPoints) { map.on('zoomend moveend', avoidStations); map.once('unload', () => map.off('zoomend moveend', avoidStations)); }
    let binding, generation = 0;
    const refresh = () => { const current = ++generation; return resolveWaterRoute(from, to, tripId).then(waterPoints => {
      if (unloaded || current !== generation) return;
      if (waterPoints?.error) { segment.setStyle({ opacity: 0 }); arrow.setOpacity(0); return; }
      binding?.update(waterPoints?.length >= 2 ? waterPoints : [from, to]);
      segment.setStyle({ opacity: style.opacity ?? .9 }); arrow.setOpacity(1);
      ready = true;
      if (!waterPoints || waterPoints.length < 2) waterPoints = [from, to];
      segment.setLatLngs(waterPoints);
      // The direction arrow follows the middle of the full water path.
      const projected = waterPoints.map(point => map.project(point));
      const lengths = projected.slice(1).map((point, i) => point.distanceTo(projected[i]));
      let remaining = lengths.reduce((sum, length) => sum + length, 0) / 2;
      let i = 0;
      while (i < lengths.length - 1 && remaining > lengths[i]) remaining -= lengths[i++];
      const a = projected[i], b = projected[i + 1];
      arrow.setLatLng(map.unproject(a.add(b.subtract(a).multiplyBy(lengths[i] ? remaining / lengths[i] : 0))));
      const direction = Math.atan2(b.y - a.y, b.x - a.x) * 180 / Math.PI;
      arrow.setIcon(L.divIcon({ className: 'route-direction-arrow', html: `<span style="--direction:${direction}deg">&#10148;</span>`, iconSize: [20, 20], iconAnchor: [10, 10] }));
      avoidStations();
    }); };
    if (tripId && globalThis.AtlasManualRoutes && typeof access !== 'undefined' && canEditTrip() && !map.getContainer().closest('.media-lightbox')) {
      binding = globalThis.AtlasManualRoutes.bind({ map, L, group: route, segment, from, to, tripId, refresh });
    }
    refresh();
    const svg = map.getRenderer(segment)?._container;
    if (!svg || svg.tagName.toLowerCase() !== 'svg') return;
    if (!svg.querySelector(`#${markerId}`)) {
      const namespace = 'http://www.w3.org/2000/svg';
      const defs = svg.querySelector('defs') || svg.insertBefore(document.createElementNS(namespace, 'defs'), svg.firstChild);
      const marker = document.createElementNS(namespace, 'marker');
      marker.setAttribute('id', markerId); marker.setAttribute('viewBox', '0 0 8 8');
      marker.setAttribute('refX', '7'); marker.setAttribute('refY', '4');
      marker.setAttribute('markerWidth', '4'); marker.setAttribute('markerHeight', '4');
      marker.setAttribute('orient', 'auto'); marker.setAttribute('markerUnits', 'strokeWidth');
      const tip = document.createElementNS(namespace, 'path');
      tip.setAttribute('d', 'M 0 0 L 8 4 L 0 8 z'); tip.setAttribute('fill', color);
      marker.append(tip); defs.append(marker);
    }
    // The direction marker above remains visible in the line centre, rather
    // than being covered by the station marker at the segment end.
    // In Leaflet-Projektionskoordinaten berechnet: So liegt die Spitze exakt
    // auf der gezeichneten Linie, auch bei langen oder schrägen Strecken.
  });
  return route;
}

// Alte Browser-Einträge können dieselbe ID wie eine aktuelle Server-Reise
// tragen, aber auf eine andere (bereits gelöschte) Reise zeigen. Solche
// Aliase führten dazu, dass Welt- und Reiseansicht unterschiedliche Routen
// geladen haben. Der Serverdatensatz hat immer Vorrang.
function removeStaleTripAliases() {
  const records = localTrips();
  const serverIds = new Set(remoteTrips.map(record => String(record.id)));
  const cleaned = records.filter(record => !(serverIds.has(String(record.id)) && String(record.serverId || '') !== String(record.id)));
  if (cleaned.length !== records.length) localSave(cleaned);
}

function discardImportedTripCache() {
  const records = localTrips();
  // Server-importierte Reisen werden immer frisch vom Backend bezogen. Nur
  // echte lokale Entwürfe ohne serverId dürfen im Browser verbleiben.
  const drafts = records.filter(record => !record.serverId);
  if (drafts.length !== records.length) localSave(drafts);
}


async function openStationLocationPicker(tripId, stop, stops = [], onSaved = () => {}) {
  const page = document.querySelector('.station-page'), host = page?.querySelector('.day-map-host');
  if (!host) return;
  unifiedDayMap?.locationCleanup?.();
  const day = stopDay(stop.starts_at);
  await mountDayStationMap(page, tripId, day, stops.filter(entry => stopDay(entry.starts_at) === day), stop.id);
  const state = unifiedDayMap;
  if (!page.isConnected || !state?.map || state.key !== `${tripId}/${day}`) return;
  const map = state.map, apple = !!state.node.appleMap, L = apple ? null : await loadLeaflet();
  const editor = document.createElement('section'); editor.className = 'station-location-editor';
  editor.innerHTML = '<p>Die gewünschte Stationsposition auf der Tageskarte anklicken. Erst „POSITION SPEICHERN“ übernimmt die Änderung.</p><p class="station-location-status" role="status"></p><div style="display:flex;gap:12px;flex-wrap:wrap"><button type="button" class="primary location-save" disabled>POSITION SPEICHERN</button><button type="button" class="location-cancel">ABBRECHEN</button></div>';
  const status = editor.querySelector('.station-location-status'), save = editor.querySelector('.location-save'), cancel = editor.querySelector('.location-cancel');
  let marker, selected, saving = false;
  const select = latlng => {
    if (saving) return;
    selected = { latitude: latlng.lat, longitude: ((latlng.lng + 180) % 360 + 360) % 360 - 180 };
    const point = [selected.latitude, selected.longitude];
    if (apple) map.setSelection(point);
    else if (marker) marker.setLatLng(point);
    else marker = L.marker(point, { zIndexOffset: 2000, interactive: false }).addTo(map);
    status.textContent = `Ausgewählte Position: ${selected.latitude.toFixed(6)}, ${selected.longitude.toFixed(6)}`;
    save.disabled = false;
  };
  const click = event => select(event.latlng);
  const cleanup = () => {
    if (apple) { map.onPick = null; map.setPicking(false); map.setSelection(null); }
    else map.off('click', click);
    marker?.remove(); editor.remove();
    state.selectLocation = null; state.locationCleanup = null;
    state.node.style.cursor = '';
  };
  state.selectLocation = select; state.locationCleanup = cleanup;
  state.node.style.cursor = 'crosshair';
  if (apple) { map.onPick = select; await map.setPicking(true); }
  else map.on('click', click);
  if (dayStationCoordinates(stop)) select({ lat: Number(stop.latitude), lng: Number(stop.longitude) });
  else status.textContent = 'Noch keine Position ausgewählt.';
  cancel.onclick = cleanup;
  save.onclick = async () => {
    if (!selected || saving) return;
    saving = true; save.disabled = true; cancel.disabled = true;
    status.textContent = 'Position wird gespeichert …';
    try {
      const response = await apiFetch('./api/api.php?action=stop-location', { method: 'PATCH', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: stop.id, trip: tripId, name: stop.name, ...selected, confirmed: true }) });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.error || 'Die Position konnte nicht gespeichert werden.');
      Object.assign(stop, selected); cleanup();
      if (unifiedDayMap === state && page.isConnected) {
        state.map.remove(); unifiedDayMap = null; onSaved();
      }
    } catch (error) { status.textContent = error.message || 'Die Position konnte nicht gespeichert werden.'; }
    finally { saving = false; save.disabled = false; cancel.disabled = false; }
  };
  host.append(editor);
  host.scrollIntoView({ behavior: 'smooth', block: 'center' });
  map.invalidateSize({ pan: false });
}
// Positionsänderungen erfolgen ausschließlich über diesen bewusst bestätigten
// Dialog. Kartenmarker selbst sind nicht verschiebbar und besitzen keinen
// Speicher-Handler.
document.addEventListener('click', async event => {
  const button = event.target.closest('.edit-location');
  if (!button || !canEditTrip()) return;
  event.preventDefault();
  event.stopImmediatePropagation();
  const match = location.hash.match(/^#trip\/([^/]+)\/station\/([^/]+)/);
  if (!match) return;
  const trip = findTripByAnyId(match[1]);
  if (!trip?.serverId) return;
  try {
    const response = await apiFetch(`./api/api.php?action=stops&trip=${encodeURIComponent(trip.serverId)}`, { credentials: 'same-origin' });
    const data = await response.json();
    const stop = (data.stops || []).find(item => item.id === match[2]);
    if (!response.ok || !stop) throw new Error();
    await openStationLocationPicker(trip.serverId, stop, data.stops || [], () => serverPlace(trip.serverId, stop.id));
  } catch { alert('Die Position konnte nicht gespeichert werden.'); }
}, true);

// Navigation und initiales Laden verwenden denselben finalen Renderpfad.

let renderFrame = 0;

// Der Karten-Ladezustand bleibt bis zum Eintreffen aller sichtbaren Kacheln
// über der Karte liegen – besonders wichtig bei langsameren Tablet-Verbindungen.

async function renderSlideshowStationMap(host, stops, activeStop, tripId, dayOverview = false) {
  const located = stops.filter(stop => stopDay(stop.starts_at) === stopDay(activeStop.starts_at) && dayStationCoordinates(stop));
  if (!located.length) { host.textContent = 'Für diese Tagesstationen sind keine Positionen gespeichert.'; return; }
  try {
    if (window.ReiseAppleMaps && await window.ReiseAppleMaps.available()) {
      if (!host.isConnected) return;
      host.stationMap = await mountAppleStationMap(host, stops.filter(stop => stopDay(stop.starts_at) === stopDay(activeStop.starts_at)), tripId, { active: dayOverview ? '' : activeStop.id, dayConnections: true });
      if(host.stationMap)installAppleRouteEditor(host,host.stationMap,stops.filter(stop=>stopDay(stop.starts_at)===stopDay(activeStop.starts_at)),tripId);
      if (!dayOverview && !dayStationCoordinates(activeStop)) {
        host.stationMapNote = Object.assign(document.createElement('p'), { className: 'day-map-note', textContent: 'Die neue Station hat keine gespeicherte Position.' }); host.after(host.stationMapNote);
      }
      return;
    }
    const L = await loadLeaflet();
    if (!host.isConnected) return;
    // Island slideshows: no +/− on the small map (mouse wheel or two fingers zoom).
    const islandMap = !!(window.ReiseWebUI || window.ReiseNativeUI);
    const map = L.map(host, { scrollWheelZoom: islandMap, zoomControl: !islandMap, attributionControl: true });
    host.stationMap = map;
    if (typeof addCoastalLabels === 'function') addCoastalLabels(map, L);
    const markers = [];
    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, maxNativeZoom: 16, opacity: .78, attribution: 'Tiles &copy; Esri' }).addTo(map);
    located.forEach(stop => {
      const active = !dayOverview && String(stop.id) === String(activeStop.id), size = active ? 22 : 12;
      const marker = L.marker([Number(stop.latitude), Number(stop.longitude)], { icon: L.divIcon({ className: `day-station-dot${active ? ' active' : ''}`, iconSize: [size,size], iconAnchor: [size/2,size/2] }) }).addTo(map);
      const label = document.createElement('span'); label.textContent = stop.name || 'Station'; marker.bindTooltip(label);
      marker.getElement()?.setAttribute('aria-label', `${stop.name || 'Station'}${active ? ', aktive Station' : ''}`);
      markers.push({marker,stop,active,tripId,number:stop.stationNumber||stops.indexOf(stop)+1,role:active?'station-active':'station-middle',open:()=>startStationGroupSlideshow(stops,[stop.id],tripId).catch(error=>alert(error.message))});
    });
    map.fitBounds(L.latLngBounds(located.map(stop => [Number(stop.latitude), Number(stop.longitude)])), { padding: [28,28], maxZoom: 14 });
    if (!dayOverview) focusActiveStationOnZoom(map, () => activeStop);
    addDayStationConnections(map, L, stops.filter(stop => stopDay(stop.starts_at) === stopDay(activeStop.starts_at)), tripId);
    markers.forEach(({marker,stop,active}) => marker.getElement()?.setAttribute('aria-label', `${stop.name || 'Station'}${active ? ', aktive Station' : ''}`));
    if (!dayOverview && !dayStationCoordinates(activeStop)) {
      host.stationMapNote = Object.assign(document.createElement('p'), { className: 'day-map-note', textContent: 'Die neue Station hat keine gespeicherte Position.' });
      host.after(host.stationMapNote);
    }
  } catch { if (host.isConnected) host.textContent = 'Die Stationskarte konnte nicht geladen werden.'; }
}

// Endpoint displacement, not route length. Missing endpoint GPS stays unknown.
function journeyDayDistance(stops, day) {
  const days = [...new Set(stops.map(s=>stopDay(s.starts_at)).filter(Boolean))].sort();
  const position = days.indexOf(day), current = stops.filter(s=>stopDay(s.starts_at)===day);
  if(position<0 || !current.length)return '';
  const previousDate=new Date(day+'T12:00:00Z');previousDate.setUTCDate(previousDate.getUTCDate()-1);
  const previous = position ? stops.filter(s=>stopDay(s.starts_at)===previousDate.toISOString().slice(0,10)) : [];
  const start = position ? previous.at(-1) : current[0], end = current.at(-1);
  const point = s => s && s.latitude!=null && s.longitude!=null && Number.isFinite(Number(s.latitude)) && Number.isFinite(Number(s.longitude)) && Math.abs(Number(s.latitude))<=90 && Math.abs(Number(s.longitude))<=180;
  if(!point(start)||!point(end))return 'Tagesstrecke (Luftlinie): keine vollständigen Positionsdaten';
  const rad=n=>Number(n)*Math.PI/180, dlat=rad(end.latitude-start.latitude), dlon=rad(end.longitude-start.longitude);
  const a=Math.sin(dlat/2)**2+Math.cos(rad(start.latitude))*Math.cos(rad(end.latitude))*Math.sin(dlon/2)**2;
  const km=6371.0088*2*Math.asin(Math.sqrt(Math.min(1,a)));
  return 'Tagesstrecke (Luftlinie): '+new Intl.NumberFormat(globalThis.ReiseI18n?.language==='en'?'en-GB':'de-AT',{maximumFractionDigits:1}).format(km)+' km';
}

function journeyStepLabel(summary) {
  try {
    const value=typeof summary?.steps==='string'?JSON.parse(summary.steps):summary?.steps;
    if(typeof value?.person!=='string'||!value.person.trim()||value.person.length>120||!Number.isSafeInteger(value.count)||value.count<0)return '';
    return new Intl.NumberFormat(globalThis.ReiseI18n?.language==='en'?'en-GB':'de-AT').format(value.count)+' Schritte · '+value.person;
  } catch{return '';}
}

function openMediaLightbox(items, initialIndex, { nextStationIds = [], nextDay = '', allStops = [], currentStop = null, stationText = '', showMap = false } = {}) {
  const dialog = document.createElement('dialog'); dialog.className = 'media-lightbox';
  const nativeViewer = !!globalThis.ReiseNativeUI;
  const islandViewer = nativeViewer || !!globalThis.ReiseWebUI;
  if(islandViewer)dialog.classList.add('island-slideshow');
  const swipeViewer = nativeViewer ? document.body.classList.contains('native-ios') || globalThis.ReiseNativeUI.touchNavigation !== false : globalThis.ReiseWebUI?.touchNavigation === true;
  const close = document.createElement('button'); close.className = 'media-lightbox-close'; close.type = 'button'; close.textContent = '×'; close.setAttribute('aria-label', 'Diashow schließen'); close.onclick = () => dialog.close(); dialog.append(close);
  let index = initialIndex;
  let switchingPage = false;
  let playing = true, timer, zoom = 1, currentMedia, dragging;
  let phase = 'media', storyDuration = 10000, overlay, mapOverlay, dayFirstStop, dayFirstMedia, holdUntil = 0, dayHasText = false, dayEntryIndex = 0;
  const tripId = items[0].tripId;
  const initialStopId = currentStop?.id;
  let pendingDayDate = currentStop ? stopDay(currentStop.starts_at) : '';
  let pendingStationDate = currentStop;
  const dayStepLabels = new Map();
  const removeMap = () => { mapOverlay?.stationMap?.remove(); mapOverlay?.stationMapNote?.remove(); mapOverlay?.remove(); mapOverlay = null; dialog.classList.remove('has-slide-map', 'has-day-map'); };
  const removeOverlay = () => { removeMap(); overlay?.remove(); overlay = null; };
  const refreshMap = async () => {
    removeMap(); dialog.classList.toggle('media-map-enabled', showMap);
    const stop = phase === 'day' ? dayFirstStop : currentStop;
    if (!showMap || !stop || phase === 'end') return;
    const host = document.createElement('div');
    host.className = 'day-stations-map ' + (phase === 'day' ? 'media-slide-day-map' + (dayHasText ? '' : ' media-slide-day-map-full') : 'media-station-intro-map');
    host.setAttribute('aria-label', 'Tageskarte mit Stationsmarker'); mapOverlay = host; dialog.append(host);
    await renderSlideshowStationMap(host, allStops.length ? allStops : [stop], stop, tripId, phase === 'day');
    // The station map sits on the bottom bar (portrait) or at the left edge (landscape); the photo moves aside.
    if (islandViewer && mapOverlay === host && host.classList.contains('media-station-intro-map')) dialog.classList.add('has-slide-map');
    if (islandViewer && mapOverlay === host && host.classList.contains('media-slide-day-map') && !host.classList.contains('media-slide-day-map-full')) dialog.classList.add('has-day-map');
  };
  // Island slideshows (app, website): the map is switched with the map button in the bottom bar; the choice is remembered.
  if (islandViewer && !showMap) { try { showMap = localStorage.getItem('atlas-slideshow-map') === '1'; } catch {} }
  const stopCurrentMedia = () => {

    if (currentMedia instanceof HTMLImageElement) currentMedia.onload = currentMedia.onerror = null;
    if (currentMedia instanceof HTMLVideoElement) {
      clearTimeout(currentMedia.reiseWaitTimer);
      currentMedia.onended = currentMedia.onplay = currentMedia.onpause = currentMedia.onerror = currentMedia.onwaiting = currentMedia.oncanplay = null;
      currentMedia.pause();
      if(nativeViewer){if(globalThis.ReiseNativeVideo)globalThis.ReiseNativeVideo.release(currentMedia);else{currentMedia.hidden=true;currentMedia.removeAttribute('poster');currentMedia.removeAttribute('src');currentMedia.load();}}
    }
  };
  const textOverlay = (text, className = '') => {
    removeOverlay(); overlay = document.createElement('section'); overlay.className = `media-slide-story ${className}`;
    const copy = document.createElement('div'); copy.className = 'media-slide-story-copy'; copy.textContent = text; overlay.append(copy);
    if(text.trim()&&!className.includes('media-slide-end'))overlay.append(Object.assign(document.createElement('p'),{className:'media-reading-hint',textContent:'10 Sekunden Lesepause · Tippen zum längeren Lesen'}));
    dialog.append(overlay); return overlay;
  };
  const toolbar = document.createElement('div'); toolbar.className = 'media-slide-controls';
  toolbar.hidden = true;
  let controlsTimer, mediaCaption, suppressTapUntil = 0;
  let photoSeconds=5;try{const saved=Number(localStorage.getItem('atlas-photo-seconds'));if(Number.isInteger(saved)&&saved>=3&&saved<=10)photoSeconds=saved;}catch{}
  const setControlsVisible = visible => {
    const restoreFocus=islandViewer&&!visible&&dialog.contains(document.activeElement)&&document.activeElement.closest('.media-slide-controls,.media-playback-island');
    toolbar.hidden = !visible;
    if(restoreFocus&&dialog.open)dialog.focus({preventScroll:true});
    dialog.classList.toggle('controls-hidden', !visible);
    if (mediaCaption) mediaCaption.hidden = !visible;
    if(islandViewer){if(visible)keepControlsVisible();else clearTimeout(controlsTimer);}
  };
  const keepControlsVisible = () => {
    clearTimeout(controlsTimer);
    if (!playing && !islandViewer) return;
    controlsTimer = setTimeout(function hideControls() {
      if(dialog.querySelector('.media-photo-duration:focus')){keepControlsVisible();return;}
      if (islandViewer || (playing && !dialog.querySelector('button:focus-visible'))) setControlsVisible(false);
    }, islandViewer ? 15000 : 5000);
  };
  dialog.addEventListener('click', event => {
    if (Date.now() < suppressTapUntil) return;
    if(islandViewer&&!event.target.closest('button,input,textarea,select,a,summary,.leaflet-container,.apple-map-host,.media-playback-island,.media-slide-controls,.media-zoom-island')&&!event.target.closest('video')?.controls){playing=!playing;updatePlayback();setControlsVisible(true);}
    if (!islandViewer && event.target.closest('.media-slide-viewport, .media-slide-story')) setControlsVisible(true);
    if (!toolbar.hidden) keepControlsVisible();
  });
  dialog.addEventListener('ReiseMapInteraction', () => { if (!islandViewer) { setControlsVisible(true); keepControlsVisible(); } });
  for (const type of ['keydown', 'input', 'wheel', 'pointerdown']) {
    dialog.addEventListener(type, () => { if (!toolbar.hidden) keepControlsVisible(); }, { capture: true });
  }
  dialog.addEventListener('pointermove', () => { if (dragging && !toolbar.hidden) keepControlsVisible(); });
  const control = (label, action) => { const button = document.createElement('button'); button.type = 'button'; button.textContent = label; button.onclick = action; toolbar.append(button); return button; };
  const clearTimer = () => { clearTimeout(timer); timer = null; };
  const schedule = () => {
    clearTimer();
    if (!playing || !dialog.open || switchingPage) return;
    if (phase !== 'media') { timer = setTimeout(advance, storyDuration); return; }
    if (currentMedia instanceof HTMLImageElement && currentMedia.complete && currentMedia.naturalWidth) timer = setTimeout(advance, photoSeconds*1000);
  };
  const setToggle = value => { if(islandViewer){const label=value?'Diashow läuft':'Diashow pausiert';toggle.dataset.playing=String(value);toggle.setAttribute('aria-label',label);toggle.title=label;return;} const label = value ? 'Diashow pausieren' : 'Diashow starten'; toggle.textContent=value?'Ⅱ PAUSE':'▶ START'; toggle.setAttribute('aria-label',label); };
  const reportVideoStatus = (text, failed = false) => {
    let status=dialog.querySelector('.media-video-status');
    if(!text){status?.remove();return;}
    if(!status){status=document.createElement('p');status.className='media-video-status';dialog.append(status);}
    status.setAttribute('role',failed?'alert':'status');status.textContent=text;
  };
  const videoFailureText = error => {
    const detail=error?.name!=='NotSupportedError'&&typeof error?.message==='string'&&error.message.length<=500&&!/https?:\/\//i.test(error.message)?error.message:'';
    const mediaError=currentMedia instanceof HTMLVideoElement?currentMedia.error?.code:0;
    const explanation=mediaError===2?'Die Videodaten konnten nicht übertragen werden. Bitte die Internetverbindung prüfen und das Video erneut öffnen.'
      :mediaError===3?'Das Video konnte nicht decodiert werden. Bitte prüfen, ob die Originaldatei direkt in OneDrive bzw. Fotos abspielbar ist.'
      :mediaError===4||error?.name==='NotSupportedError'?'Das Videoformat oder die Videoquelle wird von der Wiedergabe nicht unterstützt.'
      :'Bitte das Video erneut öffnen. Falls der Fehler bleibt, die Originaldatei direkt in OneDrive bzw. Fotos prüfen.';
    const fileName=currentMedia?.dataset.fileName;
    return 'Video konnte nicht geladen werden. '+(fileName?'Datei: '+fileName+'. ':'')+(detail||explanation)+' Du kannst weiterwischen oder die Diashow schließen.';
  };
  const updatePlayback = () => {
    setToggle(playing);
    dialog.querySelectorAll('.media-day-date').forEach(label=>label.style.animationPlayState=playing?'running':'paused');
    clearTimer();
    if (phase === 'day' || phase === 'end') {
      if (phase === 'day' && currentMedia instanceof HTMLVideoElement && playing && currentMedia.dataset.preparing !== 'true') currentMedia.play().catch(() => {});
      else currentMedia?.pause?.();
      schedule(); return;
    }
    if (currentMedia instanceof HTMLVideoElement) {
      if(currentMedia.dataset.preparing==='true')return;
      if (phase === 'station') {currentMedia.pause();schedule();return;}
      if (playing && currentMedia.ended && dialog.open && phase === 'media') {
        timer = setTimeout(advance, Math.max(0, holdUntil - Date.now()));
        return;
      }
      if (playing && dialog.open && !currentMedia.ended) {
        const video = currentMedia;
        video.play().catch(error => {
          if(currentMedia!==video||!dialog.open||!playing||phase!=='media'||video.dataset.preparing==='true'||error?.name==='AbortError')return;
          playing=false;clearTimer();setToggle(false);setControlsVisible(true);
          if(error?.name==='NotAllowedError')reportVideoStatus('Die automatische Videowiedergabe wurde blockiert. Tippe auf das Bild, um die Wiedergabe zu starten.');
          else reportVideoStatus(videoFailureText(error?.name==='NotSupportedError'?error:undefined),true);
        });
      }
      else currentMedia.pause();
    } else schedule();
  };
  const toggle = islandViewer ? document.createElement('span') : control('Ⅱ PAUSE', () => { playing = !playing; updatePlayback(); setControlsVisible(true); keepControlsVisible(); });
  if(islandViewer){toggle.className='media-playback-status';toggle.setAttribute('role','status');toggle.setAttribute('aria-live','polite');toggle.innerHTML='<svg aria-hidden="true" viewBox="0 0 24 24" width="24" height="24"><circle cx="12" cy="12" r="8" fill="currentColor" stroke="#ffffffcc" stroke-width="1.5"/></svg>';}
  const applyZoom = () => {
    if (!currentMedia) return;
    currentMedia.style.transform = `scale(${zoom})`; currentMedia.style.transformOrigin = 'center';
    currentMedia.parentElement.classList.toggle('zoomed', zoom > 1);
    if (islandViewer && globalThis.ReiseIcons) { ReiseIcons.apply(zoomLabel,'reset','Zoom zurücksetzen'); zoomLabel.title=`Zoom zurücksetzen (${Math.round(zoom*100)} %)`; } else zoomLabel.textContent = `${Math.round(zoom * 100)} %`;
  };
  control('−', () => { zoom = Math.max(1, zoom - .5); applyZoom(); }).setAttribute('aria-label', 'Herauszoomen');
  const zoomLabel = control('100 %', () => { zoom = 1; applyZoom(); }); zoomLabel.setAttribute('aria-label', 'Zoom zurücksetzen');
  control('+', () => { zoom = Math.min(4, zoom + .5); applyZoom(); }).setAttribute('aria-label', 'Hineinzoomen');
  dialog.append(toolbar);
  const mapToggle = control('🗺', () => {
    showMap = !showMap; mapToggle.setAttribute('aria-pressed', String(showMap)); mapToggle.setAttribute('aria-label', showMap ? 'Karte ausblenden' : 'Karte einblenden');
    if(islandViewer&&globalThis.ReiseIcons)ReiseIcons.apply(mapToggle,'map',showMap?'Karte ausblenden':'Karte einblenden');
    dialog.classList.toggle('media-map-enabled', showMap);
    if (!showMap) removeMap();
    if (!showMap && phase === 'day' && !dayHasText && !switchingPage) { removeMap(); advance(); }
    else if (!switchingPage) refreshMap();
    setControlsVisible(true); keepControlsVisible();
  });
  mapToggle.className = 'media-map-toggle'; mapToggle.setAttribute('aria-pressed', String(showMap)); mapToggle.setAttribute('aria-label', showMap ? 'Karte ausblenden' : 'Karte einblenden');
  mapToggle.innerHTML = '<svg aria-hidden="true" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"><path d="m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2Z"/><path d="M9 3v16M15 5v16"/></svg>';
  if (islandViewer && globalThis.ReiseIcons) {
    const names=['minus','reset','plus']; [...toolbar.querySelectorAll('button')].slice(0,3).forEach((button,index)=>ReiseIcons.apply(button,names[index],button.getAttribute('aria-label')||button.textContent));
    ReiseIcons.apply(close,'close','Diashow schließen'); ReiseIcons.apply(mapToggle,'map',showMap?'Karte ausblenden':'Karte einblenden');

  }
  if (nativeViewer && swipeViewer) {
    for (const label of ['Herauszoomen','Zoom zurücksetzen','Hineinzoomen']) toolbar.querySelector(`[aria-label="${label}"]`)?.remove();
  }
  if(islandViewer){
    const zoomIsland=document.createElement('div');zoomIsland.className='media-zoom-island';zoomIsland.setAttribute('aria-label','Bildgröße');
    for(const label of ['Hineinzoomen','Herauszoomen']){const button=toolbar.querySelector(`[aria-label="${label}"]`);if(button)zoomIsland.append(button);}
    toolbar.querySelector('[aria-label="Zoom zurücksetzen"]')?.remove();
    // Photo zoom sits in the bottom bar (added below); iOS zooms with two fingers.
    dialog.append(close);
    const playback=document.createElement('div');playback.className='media-playback-island';playback.setAttribute('aria-label','Fotoanzeige und Karte');const playbackStatus=document.createElement('div');playbackStatus.className='media-status-island';playbackStatus.append(toggle);dialog.append(playbackStatus);
    const durationLabel=document.createElement('label');
    const duration=document.createElement('select');duration.className='media-photo-duration';duration.setAttribute('aria-label','Anzeigedauer pro Foto');
    for(let seconds=3;seconds<=10;seconds++)duration.add(new Option(seconds+' s',String(seconds)));duration.value=String(photoSeconds);
    duration.onchange=()=>{photoSeconds=Number(duration.value);try{localStorage.setItem('atlas-photo-seconds',String(photoSeconds));}catch{}duration.blur();schedule();keepControlsVisible();};
    const durationIcon=document.createElement('span');durationIcon.className='media-duration-icon';durationIcon.innerHTML=window.ReiseIcons?.svg('duration')||'◷';durationIcon.setAttribute('aria-hidden','true');durationLabel.title='Anzeigedauer pro Foto';
    durationLabel.append(durationIcon,duration);playback.append(durationLabel);dialog.append(playback);
    for(const label of ['Herauszoomen','Hineinzoomen']){const button=zoomIsland.querySelector(`[aria-label="${label}"]`);if(button){button.classList.add('media-bar-zoom');playback.append(button);}}
    // Apps: open the full-resolution original (own source or, for other people's trips, via the server).
    if(nativeViewer&&globalThis.ReiseNative){
      const originalButton=document.createElement('button');originalButton.type='button';originalButton.className='media-original-button';
      if(globalThis.ReiseIcons)ReiseIcons.apply(originalButton,'original','Original öffnen');else{originalButton.textContent='Original';originalButton.setAttribute('aria-label','Original öffnen');}
      originalButton.onclick=async()=>{
        const item=items[index];if(phase!=='media'||!item?.mediaId)return;
        playing=false;updatePlayback();keepControlsVisible();
        const requestId='original-'+Date.now(),mb=value=>(value/1048576).toFixed(1).replace('.',',');
        const progress=event=>{const detail=event.detail;if(detail?.requestId!==requestId)return;
          reportVideoStatus(detail.total?'Original wird geladen … '+Math.min(99,Math.floor(detail.received*100/detail.total))+' % ('+mb(detail.received)+' von '+mb(detail.total)+' MB)':'Original wird geladen … '+mb(detail.received)+' MB');};
        window.addEventListener('ReiseVideoProgress',progress);originalButton.disabled=true;reportVideoStatus('Original wird geladen …');
        try{const result=await ReiseNative.request('openOriginal',{tripId:item.tripId,mediaId:item.mediaId,requestId});reportVideoStatus(result?.savedTo?'Original gespeichert: '+result.savedTo:'');}
        catch(error){reportVideoStatus(error?.message||'Das Original konnte nicht geladen werden.',true);}
        finally{window.removeEventListener('ReiseVideoProgress',progress);originalButton.disabled=false;}
      };
      playback.append(originalButton);
    }
    // Share the trip from the slideshow too (same invitation sheet as in the bottom menu).
    const shareTripId=items.find(item=>item?.tripId)?.tripId;
    if(shareTripId&&typeof shareTrip==='function'){
      const shareButton=document.createElement('button');shareButton.type='button';shareButton.className='media-share-button';
      if(globalThis.ReiseIcons)ReiseIcons.apply(shareButton,'share','Teilen');else{shareButton.textContent='Teilen';shareButton.setAttribute('aria-label','Teilen');}
      shareButton.onclick=()=>{playing=false;updatePlayback();keepControlsVisible();shareTrip(String(shareTripId)).catch(error=>alert(error.message));};
      playback.append(shareButton);
    }
    // Map switch in the bottom bar (remembered); the bar height places the map directly on top of it.
    mapToggle.addEventListener('click',()=>{try{localStorage.setItem('atlas-slideshow-map',showMap?'1':'0');}catch{}});
    playback.append(mapToggle);
    // 0.4: "Übersicht" in the bar replaces the close button at the top: it ends the slideshow at the trip
    // overview of the day being shown (Esc on the computer and the back gesture on the iPhone still close it).
    const overviewTrip=items.find(item=>item?.tripId)?.tripId;
    const overviewButton=document.createElement('button');overviewButton.type='button';overviewButton.className='media-overview-button';
    if(globalThis.ReiseIcons)ReiseIcons.apply(overviewButton,'days','Übersicht');else{overviewButton.textContent='Übersicht';overviewButton.setAttribute('aria-label','Übersicht');}
    overviewButton.onclick=()=>{const day=currentStop?stopDay(currentStop.starts_at):'';dialog.close();
      if(overviewTrip&&typeof go==='function')go(`#trip/${encodeURIComponent(overviewTrip)}`+(day?`/day/${day}`:''));};
    playback.append(overviewButton);
    const barSpace=()=>{if(dialog.isConnected)dialog.style.setProperty('--slide-bar-space',Math.max(0,dialog.getBoundingClientRect().bottom-playback.getBoundingClientRect().top)+'px');};
    if(typeof ResizeObserver==='function'){const watch=new ResizeObserver(barSpace);watch.observe(dialog);watch.observe(playback);dialog.addEventListener('close',()=>watch.disconnect());}
    requestAnimationFrame(barSpace);
    if(!toolbar.children.length)toolbar.classList.add('media-empty-toolbar');
  }
  const previous = document.createElement('button'), next = document.createElement('button');
  previous.className = 'media-lightbox-nav previous'; next.className = 'media-lightbox-nav next';
  previous.textContent = '‹'; if(!(nativeViewer&&swipeViewer))dialog.append(previous, next);
  const show = (nextIndex, stationIntro = false) => {
    clearTimer();
    stopCurrentMedia(); dialog.querySelector('.media-video-status')?.remove();
    index = nextIndex;
    phase = 'media'; removeOverlay();
    zoom = 1;
    dialog.querySelector('figure, .media-lightbox-fallback')?.remove();
    const data = items[index];
    const figure = document.createElement('figure');
    const dayDate = pendingDayDate;
    const stationDate = pendingStationDate;
    const revealDayDate = () => {
      if(stationText.trim()) { pendingDayDate=''; pendingStationDate=null; return; }
      if ((!dayDate && !stationDate) || figure.querySelector('.media-day-date')) return;
      const dateValue = dayDate || stopDay(stationDate?.starts_at);
      const date = new Date(dateValue + 'T12:00:00');
      const label = document.createElement('time'); label.className = 'media-day-date'; label.dateTime = dayDate;
      label.style.animationPlayState=playing?'running':'paused';
      const dateText = Number.isFinite(date.getTime()) ? new Intl.DateTimeFormat(globalThis.ReiseI18n?.language==='en'?'en-GB':'de-AT', { weekday:'long', day:'numeric', month:'long', year:'numeric' }).format(date) : 'Ohne Aufnahmedatum';
      label.dateTime = dateValue || '';
      label.textContent = dayDate ? dateText : dateText + ' · Station ' + (stationDate?.stationNumber || allStops.filter(s=>stopDay(s.starts_at)===dateValue).findIndex(s=>String(s.id)===String(stationDate?.id))+1 || 1);
      if(dayDate){const distance=journeyDayDistance(allStops,dateValue);if(distance)label.textContent+='\n'+distance;if(dayStepLabels.get(dateValue))label.textContent+='\n'+dayStepLabels.get(dateValue);}
      figure.append(label); pendingDayDate = ''; pendingStationDate = null;
    };
    const source = stationMediaCache.source(data);
    const viewport = document.createElement('div'); viewport.className = 'media-slide-viewport'; figure.append(viewport);
    if (data.type === 'video') {
      const video = stationMediaCache.video(data); video.dataset.fileName=data.name||''; video.controls = !nativeViewer; video.preload = 'metadata'; video.playsInline = true;
      video.loop = false; video.muted = false;
      video.setAttribute('playsinline','');video.setAttribute('webkit-playsinline','');video.setAttribute('disablepictureinpicture','');
      if(!nativeViewer)video.poster = `./api/api.php?action=preview&trip=${encodeURIComponent(data.tripId)}&media=${encodeURIComponent(data.mediaId)}`;
      if (nativeViewer && globalThis.ReiseNativeVideo) { video.dataset.preparing='true';video.removeAttribute('src'); }
      else if (!video.getAttribute('src')) video.src = source;
      try { video.currentTime = 0; } catch {}
      video.onended = () => {
        if (!playing || !dialog.open) return;
        if (phase === 'station') return;
        timer = setTimeout(advance, Math.max(0, holdUntil - Date.now()));
      };
      video.onplay = () => { playing = true; setToggle(true); };
      video.onpause = () => { if (phase==='media'&&!video.ended&&video.dataset.preparing!=='true') { playing = false; clearTimer(); setToggle(false); } };
      const videoStatus=(text,failed=false)=>{if(currentMedia===video&&dialog.open)reportVideoStatus(text,failed);};
      const clearVideoWait=()=>clearTimeout(video.reiseWaitTimer);
      video.onwaiting=()=>{clearVideoWait();videoStatus('Video lädt … Weiterwischen bleibt möglich.');video.reiseWaitTimer=setTimeout(()=>{if(currentMedia!==video||!dialog.open)return;playing=false;video.pause();setToggle(false);setControlsVisible(true);videoStatus('Der Videoplayer wartet weiterhin auf abspielbare Daten. Bitte weiterwischen oder das Video erneut öffnen.',true);},30000);};
      video.oncanplay=()=>{clearVideoWait();videoStatus('');};
      video.addEventListener('playing',()=>{clearVideoWait();videoStatus('');});
      video.onerror = () => {clearVideoWait();if(currentMedia!==video)return;playing=false;updatePlayback();setControlsVisible(true);videoStatus(videoFailureText(),true);};
      currentMedia = video; viewport.append(video);
      if(nativeViewer&&globalThis.ReiseNativeVideo){
        queueMicrotask(()=>videoStatus('Video wird vorbereitet … Weiterwischen bleibt möglich.'));
        const preparing=(received,total)=>{if(video.dataset.preparing!=='true')return;const mb=value=>Math.max(1,Math.round(value/1048576));videoStatus(total?'Video wird vorbereitet … '+Math.min(99,Math.floor(received*100/total))+' % ('+mb(received)+' von '+mb(total)+' MB). Weiterwischen bleibt möglich.':'Video wird vorbereitet … '+mb(received)+' MB geladen. Weiterwischen bleibt möglich.');};
        ReiseNativeVideo.attach(video,data,preparing).then(handled=>{if(currentMedia!==video||!dialog.open)return;video.dataset.preparing='false';if(!handled){video.src=source;video.load();}videoStatus('');updatePlayback();}).catch(error=>{if(currentMedia!==video||!dialog.open)return;video.dataset.preparing='false';playing=false;setToggle(false);setControlsVisible(true);videoStatus(videoFailureText(error),true);});
      }
    } else {
      const image = document.createElement('img'); image.draggable = false; image.alt = data.name || 'Reiseaufnahme'; image.onload = () => { revealDayDate(); schedule(); };
      image.src = source; currentMedia = image;
      let previewFallback = false;
      image.onerror = () => {
        if (!previewFallback) {
          previewFallback = true;
          image.src = `./api/api.php?action=preview&trip=${encodeURIComponent(data.tripId)}&media=${encodeURIComponent(data.mediaId)}&size=large`;
          return;
        }
        playing = false; updatePlayback();
        const fallback = document.createElement('div'); fallback.className = 'media-lightbox-fallback';
        fallback.textContent = 'Das Foto konnte derzeit nicht geladen werden.';
        if (data.webUrl) { const link = document.createElement('a'); link.href = data.webUrl; link.target = '_blank'; link.rel = 'noopener'; link.textContent = 'IN ONEDRIVE ÖFFNEN'; fallback.append(link); }
        figure.replaceWith(fallback);
      };
      viewport.append(image);
    }
    const caption = document.createElement('figcaption');
    mediaCaption = caption; caption.hidden = toolbar.hidden;
    caption.textContent = (data.name || (data.type === 'video' ? 'Reisevideo' : 'Reiseaufnahme')) + ' · ' + (index + 1) + '/' + items.length;
    figure.append(caption); dialog.append(figure);
    if (data.type === 'video' || currentMedia.complete && currentMedia.naturalWidth) revealDayDate();
    if ((index === 0 || stationIntro) && currentStop && (stationText.trim() || showMap)) {
      const panel = textOverlay(stationText || '', `media-station-intro${stationText.trim() ? '' : ' media-station-map-only'}`);
      const title = document.createElement('h2'); title.textContent = stationLabel(currentStop); panel.append(title);

    } else if ((index === 0 || stationIntro) && stationText.trim()) textOverlay(stationText);
    if (overlay) { phase = 'station'; storyDuration = stationText.trim() ? 10000 : 5000; }
    refreshMap();
    holdUntil = 0;
    applyZoom(); updatePlayback();
    viewport.onpointerdown = event => {
      if (zoom <= 1 || event.target.closest('button')) return;
      dragging = { x: event.clientX, y: event.clientY, left: viewport.scrollLeft, top: viewport.scrollTop };
      viewport.setPointerCapture(event.pointerId);
    };
    viewport.onpointermove = event => {
      if (!dragging) return;
      if(Math.hypot(event.clientX-dragging.x,event.clientY-dragging.y)>6)suppressTapUntil=Date.now()+500;
      viewport.scrollLeft = dragging.left - event.clientX + dragging.x;
      viewport.scrollTop = dragging.top - event.clientY + dragging.y;
    };
    viewport.onpointerup = viewport.onpointercancel = () => { dragging = null; };
    previous.disabled = index === 0 && !(islandViewer && allStops.findIndex(stop=>String(stop.id)===String(currentStop?.id))>0);
    const atStationEnd = index === items.length - 1;
    const hasNextPage = nextStationIds.length > 0 || !!nextDay;
    next.disabled = atStationEnd && !hasNextPage && !islandViewer;
    next.classList.toggle('next-page', atStationEnd && hasNextPage);
    next.textContent = '›';
    next.setAttribute('aria-label', atStationEnd && nextStationIds.length ? 'Erstes Medium der nächsten Station öffnen' : atStationEnd && nextDay ? 'Nächsten Reisetag öffnen' : 'Nächstes Medium');
    previous.setAttribute('aria-label',index>0?'Vorheriges Medium':'Vorherige Station');
    if(islandViewer&&globalThis.ReiseIcons){previous.innerHTML=ReiseIcons.svg('previous');next.innerHTML=ReiseIcons.svg('next');}
    previous.hidden = !islandViewer && index === 0 && items.length === 1 && previous.disabled;
    next.hidden = items.length === 1 && !hasNextPage && !islandViewer;
  };
  const loadStationMedia = async stopId => {
    const response = await apiFetch('./api/api.php?action=media&trip=' + encodeURIComponent(tripId) + '&stop=' + encodeURIComponent(stopId), { credentials: 'same-origin' });
    if (!response.ok) throw Error('Medien konnten nicht geladen werden.');
    return (await response.json()).media || [];
  };
  const enterStation = (stop, media, entryIndex = 0, stationIntro = false) => {
    if (!dialog.open || !stop || !media?.length) return;
    pendingStationDate = stop;
    currentStop = stop; stationText = stop.summary || ''; phase = 'media';
    const position = allStops.findIndex(entry => String(entry.id) === String(stop.id));
    const day = stopDay(stop.starts_at);
    nextStationIds = allStops.slice(position + 1).filter(entry => stopDay(entry.starts_at) === day).map(entry => String(entry.id));
    const days = [...new Set(allStops.map(entry => stopDay(entry.starts_at)).filter(Boolean))].sort();
    nextDay = days[days.indexOf(day) + 1] || '';
    items = media.map(item => ({ tripId, stopId: stop.id, mediaId: item.id, type: item.media_type, name: item.name, webUrl: item.web_url }));
    stationMediaCache.begin(tripId, stop.id, media, dialog);
    show(entryIndex, stationIntro);
  };
  const showEnd = () => {
    phase = 'end'; storyDuration = 10000; clearTimer(); stopCurrentMedia(); textOverlay('ENDE', 'media-slide-end');
    previous.disabled = false; next.disabled = true; next.hidden = !islandViewer; updatePlayback();
  };
  const showDay = async (day, stop, media, entryIndex = 0) => {
    pendingDayDate = day;
    switchingPage = true; clearTimer(); stopCurrentMedia();
    phase = 'day'; dayFirstStop = stop; dayFirstMedia = media; dayEntryIndex = entryIndex; zoom = 1;
    dialog.querySelector('figure, .media-lightbox-fallback')?.remove();
    removeOverlay(); currentMedia = null;
    dialog.setAttribute('aria-busy', 'true');
    previous.disabled = true; next.disabled = true; next.hidden = false; next.textContent = '›'; next.classList.toggle('next-page', true);
    const loading = document.createElement('div'); loading.className = 'media-slide-loading'; loading.textContent = 'Tagesübersicht wird geladen …'; mapOverlay = loading; dialog.append(loading);
    try {
    const request = action => apiFetch('./api/api.php?action=' + action + '&trip=' + encodeURIComponent(tripId) + '&day=' + encodeURIComponent(day), { credentials: 'same-origin', signal: globalThis.AbortSignal?.timeout?.(15000) }).then(response => response.ok ? response.json() : {}).catch(() => ({}));
    const [summary, weather] = await Promise.all([request('day-summary'), request('day-weather')]);
    if (!dialog.open) return;
    dayStepLabels.set(day,journeyStepLabel(summary));
    const hasDayText = Boolean(String(summary.summary || '').trim()); dayHasText = hasDayText;
    storyDuration = hasDayText ? 10000 : 3000;
    if(hasDayText)pendingDayDate = '';
    removeOverlay();
    if (!hasDayText && !showMap) { switchingPage = false; enterStation(stop, media, entryIndex, true); return; }
    // Keep the day introduction connected to its first station's photos.
    // Prefer a photo; video-only stations use a silent looping background.
    const dayPhoto = media.find(item => item.media_type === 'photo');
    if (dayPhoto) {
      const backdrop = document.createElement('figure'); backdrop.className = 'media-day-backdrop';
      backdrop.setAttribute('aria-hidden', 'true');
      const image = document.createElement('img'); image.alt = ''; image.draggable = false;
      image.onerror = () => backdrop.remove();
      image.src = './api/api.php?action=preview&trip=' + encodeURIComponent(tripId) + '&media=' + encodeURIComponent(dayPhoto.id) + '&size=large';
      backdrop.append(image); dialog.append(backdrop);
    } else {
      const dayVideo = media.find(item => item.media_type === 'video');
      if (dayVideo) {
        const backdrop = document.createElement('figure'); backdrop.className = 'media-day-backdrop';backdrop.setAttribute('aria-hidden','true');
        const data = {tripId,stopId:stop.id,mediaId:dayVideo.id,type:'video',name:dayVideo.name};
        const video = stationMediaCache.video(data);video.controls=false;video.muted=true;video.loop=true;video.playsInline=true;
        video.setAttribute('playsinline','');video.setAttribute('webkit-playsinline','');
        video.onended=video.onplay=video.onpause=null;
        video.poster='./api/api.php?action=preview&trip='+encodeURIComponent(tripId)+'&media='+encodeURIComponent(dayVideo.id);
        currentMedia=video;backdrop.append(video);dialog.append(backdrop);
        const source=stationMediaCache.source(data);
        if(nativeViewer&&globalThis.ReiseNativeVideo){
          video.dataset.preparing='true';video.removeAttribute('src');
          ReiseNativeVideo.attach(video,data).then(handled=>{if(currentMedia!==video||!dialog.open)return;video.dataset.preparing='false';if(!handled){video.src=source;video.load();}updatePlayback();}).catch(()=>{if(currentMedia===video){stopCurrentMedia();currentMedia=null;backdrop.remove();}});
        }else video.src=source;
      }
    }
    let panel;
    if (hasDayText) {
      const date = new Intl.DateTimeFormat(globalThis.ReiseI18n?.language==='en'?'en-GB':'de-DE', {weekday:'long',day:'2-digit',month:'long',year:'numeric'}).format(new Date(day + 'T12:00:00'));
      panel = textOverlay(date + '\n\n' + summary.summary, 'media-slide-day');
      const distance = journeyDayDistance(allStops, day);
      if(distance)panel.append(Object.assign(document.createElement('p'),{className:'journey-day-metrics',textContent:distance}));
      const steps=journeyStepLabel(summary);if(steps)panel.append(Object.assign(document.createElement('p'),{className:'journey-day-metrics',textContent:steps}));
    }
    await refreshMap();
    if (!dialog.open) return;
    if (!hasDayText && !showMap) { switchingPage = false; enterStation(stop, media, entryIndex, true); return; }
    if (panel && weather.available) {
      const values = document.createElement('div'); values.className = 'day-weather-values';
      const number = value => new Intl.NumberFormat(globalThis.ReiseI18n?.language==='en'?'en-GB':'de-DE',{maximumFractionDigits:0}).format(value);
      for (const [kind, value, label] of [['temperature', weather.temperature_min != null && weather.temperature_max != null ? number(weather.temperature_min) + '–' + number(weather.temperature_max) + ' °C' : '–', 'Temperatur'], ['elevation', weather.elevation != null ? number(weather.elevation) + ' m' : '–', 'Meereshöhe']]) {
        const entry = document.createElement('div'), text = document.createElement('strong'); text.textContent = value; entry.title = label; entry.append(dayWeatherIcon(kind), text); values.append(entry);
      }
      const icon = dayWeatherIcon(weather.weather_code); icon.setAttribute('aria-label', dayWeatherSymbol(weather.weather_code)[1]); values.append(icon); panel.append(values);
    }
    previous.disabled = !(islandViewer&&allStops.findIndex(entry=>String(entry.id)===String(stop.id))>0);previous.hidden=!islandViewer&&previous.disabled;next.hidden = false; next.disabled = false; next.textContent = '›';
    next.textContent = '›'; next.classList.toggle('next-page', true);
    next.setAttribute('aria-label', 'Erste Station nach der Tagesübersicht öffnen');
    } finally {
      switchingPage = false; dialog.removeAttribute('aria-busy');
      if (dialog.open) updatePlayback();
    }
  };
  const advance = async () => {
    if (switchingPage || !dialog.open) return;
    if (phase === 'end') { dialog.close(); return; }
    if (phase === 'day') { enterStation(dayFirstStop, dayFirstMedia, dayEntryIndex, true); return; }
    if (phase === 'station') {
      removeOverlay(); phase = 'media';
      refreshMap();
      // Reading time is additional: resume the first photo/video afterwards.
      updatePlayback(); return;
    }
    if (index < items.length - 1) { show(index + 1); return; }
    clearTimer(); switchingPage = true; previous.disabled = true; next.disabled = true; next.textContent = '›';
    try {
      for (const stopId of nextStationIds) {
        const media = await loadStationMedia(stopId);
        if (!dialog.open) return;
        if (!media.length) continue;
        const stop = allStops.find(entry => String(entry.id) === String(stopId));
        if (stop) {
          if (stopDay(stop.starts_at) !== stopDay(currentStop?.starts_at)) await showDay(stopDay(stop.starts_at), stop, media);
          else { switchingPage = false; enterStation(stop, media); }
          return;
        }
      }
      if (nextDay) {
        const days = [...new Set(allStops.map(entry => stopDay(entry.starts_at)).filter(day => day && day >= nextDay))].sort();
        for (const day of days) for (const stop of allStops.filter(entry => stopDay(entry.starts_at) === day)) {
          const media = await loadStationMedia(stop.id);
          if (!dialog.open) return;
          if (media.length) { switchingPage = false; await showDay(day, stop, media); return; }
        }
      }
      switchingPage = false; showEnd();
    } catch {
      switchingPage = false;
      if (dialog.open) { playing = false; show(index); alert('Die nächste Station konnte nicht geladen werden.'); }
    } finally { switchingPage = false; }
  };
  const retreat = async () => {
    if (switchingPage || !dialog.open) return;
    if (phase === 'end') { show(index); return; }
    if (phase !== 'day' && index > 0) { show(index-1); return; }
    const anchor=phase==='day'?dayFirstStop:currentStop, position=allStops.findIndex(stop=>String(stop.id)===String(anchor?.id));
    if (position<=0) return;
    clearTimer(); switchingPage=true;
    try { for(let n=position-1;n>=0;n--){const stop=allStops[n],media=await loadStationMedia(stop.id);if(!dialog.open)return;if(!media.length)continue;const last=media.length-1;
      if(stopDay(stop.starts_at)!==stopDay(anchor.starts_at))await showDay(stopDay(stop.starts_at),stop,media,last);else {switchingPage=false;enterStation(stop,media,last,true);}return;
    } } catch { if(dialog.open){playing=false;updatePlayback();setControlsVisible(true);} } finally {switchingPage=false;if(dialog.open)updatePlayback();}
  };
  previous.onclick = islandViewer ? retreat : () => phase === 'end' ? show(index) : index > 0 && show(index - 1);
  next.onclick = advance;
  // Only unzoomed media accept a one-finger swipe; maps, video controls and
  // zoomed images keep their native gestures. Never advance on pointercancel.
  let swipe;
  dialog.addEventListener('pointerdown', event => {
    if (event.pointerType !== 'touch' || !event.isPrimary) { swipe = null; return; }
    if (zoom !== 1 || event.target.closest('button,input,textarea,select,summary,.day-stations-map,.apple-map-host')
        || event.target.closest('video')?.controls
        || (!islandViewer && !event.target.closest('.media-slide-viewport'))) return;
    swipe = { id: event.pointerId, x: event.clientX, y: event.clientY };
  });
  dialog.addEventListener('pointercancel', () => { swipe = null; });
  // 0.3.8: two fingers zoom the photo itself (the app no longer lets the whole page zoom); the point between
  // the fingers stays in place. One finger then moves the zoomed photo as before.
  let pinch = null;
  const fingers = event => Math.hypot(event.touches[0].clientX - event.touches[1].clientX, event.touches[0].clientY - event.touches[1].clientY) || 1;
  dialog.addEventListener('touchstart', event => {
    if (event.touches.length !== 2 || !(currentMedia instanceof HTMLImageElement)) return;
    const viewport = event.target.closest?.('.media-slide-viewport'); if (!viewport) return;
    pinch = { viewport, distance: fingers(event), zoom }; swipe = null; dragging = null;
  }, { passive: true });
  dialog.addEventListener('touchmove', event => {
    if (!pinch || event.touches.length !== 2) return;
    event.preventDefault();
    const { viewport } = pinch, box = viewport.getBoundingClientRect(), before = zoom;
    const midX = (event.touches[0].clientX + event.touches[1].clientX) / 2 - box.left, midY = (event.touches[0].clientY + event.touches[1].clientY) / 2 - box.top;
    zoom = Math.min(4, Math.max(1, pinch.zoom * fingers(event) / pinch.distance)); applyZoom();
    if (zoom > 1 && before > 0) { const ratio = zoom / before; viewport.scrollLeft = (viewport.scrollLeft + midX) * ratio - midX; viewport.scrollTop = (viewport.scrollTop + midY) * ratio - midY; }
  }, { passive: false });
  const endPinch = event => { if (!pinch || event.touches.length >= 2) return; pinch = null; suppressTapUntil = Date.now() + 500; if (zoom < 1.05) { zoom = 1; applyZoom(); } };
  dialog.addEventListener('touchend', endPinch, { passive: true }); dialog.addEventListener('touchcancel', endPinch, { passive: true });
  dialog.addEventListener('pointerup', event => {
    const start = swipe; swipe = null;
    if (!start || start.id !== event.pointerId || zoom !== 1) return;
    const dx = event.clientX - start.x, dy = event.clientY - start.y;
    if (Math.abs(dx) < 60 || Math.abs(dx) < Math.abs(dy) * 1.5) return;
    suppressTapUntil = Date.now() + 500;
    if (islandViewer) { if(dx<0)advance();else retreat(); }
    else if (dx < 0 && !next.disabled) next.onclick();
    else if (dx > 0 && !previous.disabled) previous.onclick();
    if (!islandViewer) { setControlsVisible(true); keepControlsVisible(); }
  });
  const firstDayStop = currentStop && allStops.find(stop => stopDay(stop.starts_at) === stopDay(currentStop.starts_at));
  const startsWithDay = initialIndex === 0 && firstDayStop && String(firstDayStop.id) === String(currentStop.id);
  if (!startsWithDay) show(index);
  const handleViewerKey = event => {
    if (!dialog.open || event.target instanceof HTMLMediaElement || event.target.closest?.('input,textarea,select,[contenteditable="true"]')) return;
    if (event.key === 'ArrowLeft' && (islandViewer || !previous.disabled)) { event.preventDefault(); previous.onclick(); }
    if (event.key === 'ArrowRight' && (islandViewer || !next.disabled)) { event.preventDefault(); advance(); }
    if (event.code === 'Space' && (event.target === dialog || (islandViewer&&event.target===document.body))) { event.preventDefault(); playing = !playing; updatePlayback(); }
  };
  const keyboardTarget=islandViewer?document:dialog;keyboardTarget.addEventListener('keydown',handleViewerKey);
  const foreground = () => { if (document.hidden) { playing = false; updatePlayback(); setControlsVisible(true); } };
  document.addEventListener('visibilitychange', foreground);
  dialog.addEventListener('close', () => {
    clearTimer();
    stopCurrentMedia();
    removeOverlay();
    clearTimeout(controlsTimer);
    if (currentMedia instanceof HTMLVideoElement) { currentMedia.onended = currentMedia.onplay = currentMedia.onpause = null; }
    currentMedia?.pause?.();
    document.removeEventListener('visibilitychange', foreground);
    if(islandViewer)document.removeEventListener('keydown',handleViewerKey);
    document.documentElement?.classList.toggle('media-slideshow-open', false);
    document.body.classList.toggle('media-slideshow-open', false);
    if (document.fullscreenElement === dialog) document.exitFullscreen?.().catch(() => {});
    dialog.remove();
    if (initialStopId && currentStop?.id && String(initialStopId) !== String(currentStop.id)) go(`#trip/${tripId}/station/${currentStop.id}`);
  });
  document.documentElement?.classList.toggle('media-slideshow-open', true);
  document.body.classList.toggle('media-slideshow-open', true);
  // Focus the dialog itself: autofocusing the close button showed a white keyboard focus ring on iOS.
  dialog.autofocus = true; dialog.tabIndex = -1;
  document.body.append(dialog); dialog.showModal(); if (document.activeElement !== dialog) dialog.focus({ preventScroll: true });
  setControlsVisible(true);keepControlsVisible();
  dialog.requestFullscreen?.().catch(() => {});
  if (startsWithDay) {
    const firstMedia = items.map(item => ({ id: item.mediaId, media_type: item.type, name: item.name, web_url: item.webUrl }));
    showDay(stopDay(currentStop.starts_at), currentStop, firstMedia);
  } else updatePlayback();
}
document.addEventListener('click', event => {
  const link = event.target.closest('[data-media-lightbox]'); if (!link) return;
  event.preventDefault();
  const stationPage = link.closest('.station-page');
  const links = [...(stationPage || link.closest('.station-photo-grid') || link.parentElement).querySelectorAll('[data-media-lightbox]')];
  const items = links.map(entry => ({ tripId: entry.dataset.tripId, stopId: entry.dataset.stopId, mediaId: entry.dataset.mediaId, type: entry.dataset.mediaType, name: entry.dataset.mediaName, webUrl: entry.dataset.mediaUrl }));
  openMediaLightbox(items, Math.max(0, links.indexOf(link)), { nextStationIds: stationPage?.nextStationIds || [], nextDay: stationPage?.nextDay || '', allStops: stationPage?.slideshowStops || [], currentStop: stationPage?.slideshowStop || null, stationText: stationPage?.querySelector('.station-note')?.textContent || '' });
});
addEventListener('hashchange', () => render());

let adminHelpView = 'viewer';
function solutionHelpCards() {
  return `<section class="help-card"><span>LÖSUNGEN / IOS</span><h2>iOS-App beziehen</h2><p>AlleReisen gibt es als iOS-App für iPhone und iPad im App Store.</p><p>Voraussetzung: iOS beziehungsweise iPadOS ab Version 16. Die App nutzt Fotoalben einschließlich iCloud-Fotos und OneDrive. Vorhandene Texte, Reisedaten und Vorschauen bleiben auf dem Gerät verfügbar; Originale aus iCloud oder OneDrive können eine Internetverbindung benötigen.</p></section>
<section class="help-card"><span>LÖSUNGEN / COMPUTER</span><h2>Als App am Computer</h2><p>Am Computer installierst du AlleReisen direkt aus Edge oder Chrome: in den Einstellungen unter „Optionen“ auf „Als App installieren“ tippen, oder in der Adresszeile das Symbol „App installieren“ wählen. AlleReisen öffnet sich dann in einem eigenen Fenster mit eigenem Symbol im Startmenü bzw. Dock.</p><p>Die Web-App lädt deine Reisen vom Server und braucht Internet. Mit der Maus wechselst du Fotos über die Pfeile, mit der Tastatur über die Pfeiltasten; Esc schließt die Diashow. Die bisherige Windows-App aus dem Microsoft Store funktioniert weiter, wird aber nicht mehr weiterentwickelt.</p></section>
<section class="help-card"><span>LÖSUNGEN / WEB</span><h2>Web auf einem eigenen Server</h2><p>Web läuft im Browser und benötigt bei den Betrachtern keine App-Installation. Für einen eigenen Server sind die Web-Dateien als Open-Source-Installationspaket (AGPL-3.0) mit Datenbankschema, Konfigurationsvorlage und Installationsanleitung beziehbar. Ein gewöhnliches Homepage-Baukasten-Angebot oder rein statischer Web-Speicher reicht nicht aus.</p><p>Die drei Lösungen können einzeln oder gemeinsam genutzt werden. OneDrive-Reisen lassen sich zwischen den Apps und Web abgleichen. Lokale Ordner und iCloud-Fotoalben bleiben Quellen der jeweiligen App; sie werden nicht als Web-Reisen veröffentlicht. In Web werden auch Reisezugriffe und Einladungen verwaltet.</p></section>
<section class="help-card"><span>WEB / PROVIDER-VORAUSSETZUNGEN</span><h2>Was dein Web-Provider anbieten muss</h2><p>Eine eigene Domain oder Subdomain mit HTTPS, PHP ab 8.4 sowie MySQL ab 8.0 oder MariaDB ab 10.11 mit InnoDB und utf8mb4. Benötigte PHP-Erweiterungen: PDO/MySQL, cURL, OpenSSL, mbstring, EXIF, fileinfo, session und JSON. Für HEIC/HEIF-Fotos ist zusätzlich Imagick mit ImageMagick und funktionierendem libheif-Decoder erforderlich.</p><p>Apache 2.4 muss die mitgelieferten .htaccess-Schutzregeln und Header erlauben; bei Nginx muss der Provider gleichwertige Regeln einrichten. PHP benötigt Schreibzugriff auf Cache und temporäre Ordner sowie ausgehende HTTPS-Verbindungen zu Microsoft und den verwendeten Karten-/Wetterdiensten. Als Ausgangspunkt sind 256 MB PHP-Arbeitsspeicher und mindestens 120 Sekunden Laufzeit für Importe vorgesehen; große Reisen benötigen gegebenenfalls mehr.</p><p>Erforderlich sind außerdem genügend Speicher für Karten, Vorschauen und Sicherungen sowie Zugang zum Hochladen der Dateien und Einrichten der Datenbank. Für automatische Import- und Übernahmejobs werden PHP über die Kommandozeile und planbare Cronjobs benötigt. Lass den Provider diese Punkte vorab bestätigen; nach der Einrichtung müssen Anmeldung, Zugriffsschutz und Medienanzeige geprüft werden.</p></section>
<section class="help-card"><span>WEB / EINRICHTUNG</span><h2>Eigene Konten und Dienste</h2><p>Für die eigene Web-Installation werden eine Microsoft-App-Registrierung mit passenden Rücksprungadressen der eigenen HTTPS-Domain und eigene Zugangsdaten eingerichtet. OneDrive benötigt ein passendes Microsoft-Konto. Karten, Wetter und weitere optionale Dienste werden für die eigene Installation konfiguriert; mögliche Kosten und Nutzungsbedingungen sind vorab zu klären. Zugangsdaten und Schlüssel dürfen nicht öffentlich abrufbar sein.</p><p>Das Web-Paket enthält keine Hosting-Laufzeit, persönlichen Zugangsdaten oder Originalfotos. Der Provider stellt Web-Server, PHP und Datenbank bereit. Die technische Installationsanleitung und die Installationsprüfung liegen dem Paket bei.</p></section>`;
}

function renderHelp() {
  if (window.ReiseNativeUI) { window.ReiseNativeUI.help({ app, go }); return; }
  app.replaceChildren();
  headerTitle.textContent = 'HILFE';
  const page = document.createElement('section');
  page.className = 'help-page';
  const viewerView = !access.isAdmin || adminHelpView === 'viewer';
  const role = viewerView ? 'Betrachter' : 'Admin';
  const viewerCards = `
      <section class="help-card"><span>03 / DEIN ZUGRIFF</span><h2>Freigegebene Reisen</h2><p>Du siehst nur die Reisen, die für deine E-Mail-Adresse freigegeben wurden. Ein Einladungslink gilt einmalig. Für einen späteren Besuch kannst du über die Gastzugangsseite einen neuen Link anfordern. Wer Archivzugriff über Microsoft hat, sieht alle Reisen.</p></section>
      <section class="help-card"><span>04 / NUR ANSICHT</span><h2>Inhalte ansehen</h2><p>Als Betrachter kannst du Karten, Stationen und Medien öffnen. Änderungen an Reisen und Freigaben sind Admins vorbehalten. Wenn dir eine erwartete Reise fehlt, wende dich an deine Kontaktperson für diese Seite.</p></section>`;
  page.innerHTML = `
    <button class="back help-back" type="button">← ZURÜCK ZUR WELTKARTE</button>
    ${access.isAdmin ? `<div class="help-view-switch" role="group" aria-label="Hilfeansicht"><button type="button" data-help-view="admin" aria-pressed="${!viewerView}">ADMIN-ANSICHT</button><button type="button" data-help-view="viewer" aria-pressed="${viewerView}">BETRACHTER-ANSICHT</button></div>` : ''}
    <header class="help-heading"><p class="eyebrow">ANLEITUNG · ${role.toUpperCase()}</p><h1>Hilfe für <em>${role}.</em></h1><p>So nutzt du das Reisearchiv mit deinen Zugriffsrechten.</p></header>
    <div class="help-grid">
      ${solutionHelpCards()}
      <section class="help-card"><span>01 / KARTE</span><h2>Reisen auf der Weltkarte finden</h2><p>Die Weltkarte zeigt die Reisen, die du sehen darfst. Zoome mit den Kartenknöpfen, dem Mausrad oder zwei Fingern. Beim Zoomen mit dem Mausrad richtet sich die Karte auf die Position des Mauszeigers aus. Stationen erscheinen zunächst als Punkte, näher als Tag-Station (z. B. 2-3 für Tag 2, Station 3) und aus der Nähe automatisch als Miniaturen, wenn ein Vorschaubild vorhanden ist. Die Stationszählung beginnt an jedem Reisetag bei 1; ? kennzeichnet einen fehlenden Reisetag. Über „Reisejahr“ kannst du die Karte filtern.</p></section>
      <section class="help-card"><span>02 / ANSEHEN</span><h2>Eine Reise öffnen</h2><p>Ein einfacher Klick auf eine Station in der Weltkarte öffnet die Reiseübersicht mit den Reisetagen. Ein Doppelklick startet die Diashow ab dieser Station. Auf Touchgeräten gilt entsprechend einmal beziehungsweise zweimal tippen. Du kannst Reisen auch über „Alle Reisen“ öffnen. In der Reise kannst du Stationen und Reisetage öffnen sowie Fotos und Videos ansehen.</p></section>
      ${viewerView ? viewerCards : `
      <section class="help-card"><span>03 / VERWALTUNG</span><h2>Reisen bearbeiten</h2><p>Unter „Alle Reisen“ aktivierst du als Admin mit „Reisen verwalten“ den Verwaltungsmodus für alle Reisen. „Verwaltung schließen“ beendet ihn; nach dem Neuladen ist er ausgeschaltet. Unter „Alle Reisen“ wählst du „Karte öffnen“ oder „Reiseübersicht öffnen“. Im Verwaltungsmodus bleibt dort pro Reise nur „Reise löschen“. In der Reiseübersicht bearbeitest du Titel, Beschreibung und Teilnehmer, verwaltest Gäste und Einladungen und startest den Foto- und Videoabgleich. Dort kannst du auch bei Reisen ohne GPS einen Hauptort setzen. Verwaltungsbereiche sind nur für Admins sichtbar. In den Reiseansichten kannst du Stationsnamen, Texte und Positionen korrigieren. Undatierte Stationen findest du auf der Reisekarte. Aktiviere „Reisen verwalten“ und wähle unter Station verwalten ein Reisedatum. Originaldateien und Aufnahmezeiten bleiben unverändert.</p></section>
      <section class="help-card"><span>04 / FREIGABEN</span><h2>Zugriff vergeben und entziehen</h2><p>Im Bereich „Zugriff auf alle Reisen“ verwaltest du dauerhafte Archivfreigaben. Für eine einzelne Reise trägst du die E-Mail-Adresse bei „Gast einladen“ ein. Der Gast erhält einen Einmallink. Mit × neben der Adresse entziehst du die Reisefreigabe; eine zusätzliche Archivfreigabe entfernst du separat.</p></section>
      <section class="help-card"><span>05 / MICROSOFT</span><h2>Import und Einladungen</h2><p>Für den OneDrive-Import und den Versand von Einladungen musst du mit dem Microsoft-Administratorkonto angemeldet sein. Gäste benötigen kein Microsoft-Konto.</p></section>`}
    </div>
    <button class="primary help-archive" type="button">ALLE REISEN ÖFFNEN</button>
    <p class="eyebrow website-version"></p>`;
  page.querySelector('.website-version').textContent = 'AlleReisen · Web / Server · Version ' + (document.querySelector('meta[name="application-version"]')?.content || 'unbekannt');
  app.append(page);
  page.querySelectorAll('[data-help-view]').forEach(button => button.onclick = () => { adminHelpView = button.dataset.helpView; renderHelp(); });
  page.querySelector('.help-back').onclick = () => go('#home');
  page.querySelector('.help-archive').onclick = () => go('#library');
}


const helpMenu = document.querySelector('#helpMenu');

if (helpMenu) helpMenu.onclick = () => go('#hilfe');

// One render path for navigation, archive and all media sources.
function localTrips() { try { const records = JSON.parse(localStorage.getItem(tripsKey) || '[]'); return Array.isArray(records) ? records : []; } catch { return []; } }
function localSave(records) { localStorage.setItem(tripsKey, JSON.stringify(records)); }
function save(records) { localSave(records); }
function trips() { return remoteTrips.map(t => ({ ...baseTrip, ...t, serverId: t.id, date: t.starts_on ? `${t.starts_on} — ${t.ends_on || ""}` : "Gespeicherte Reisedoku", media: t.media_count, folder: t.source_folder, cover: t.cover_media_id ? `./api/api.php?action=preview&trip=${encodeURIComponent(t.id)}&media=${encodeURIComponent(t.cover_media_id)}&size=large` : baseTrip.cover })); }
function go(target) {
  if ((/^#trip\//.test(target) || target === '#import' || target === '#library') && !access.loggedIn) {
    location.assign('./login.html');
    return;
  }
  location.hash = target;
}
function render() {
  if (renderFrame) cancelAnimationFrame(renderFrame);
  const id = renderFrame = requestAnimationFrame(async () => {
    // Leaving the native (Apple Maps) world map: hide it before the new page paints (max. 150 ms).
    if (location.hash && location.hash !== '#home' && document.querySelector('#worldMap.apple-map-host') && globalThis.ReiseAppleMaps?.hideAll) {
      await Promise.race([globalThis.ReiseAppleMaps.hideAll(), new Promise(resolve => setTimeout(resolve, 150))]);
      if (renderFrame !== id) return;
    }
    renderFrame = 0; renderPage();
  });
}
function openMapOptions() {
  document.querySelector('.map-options-dialog')?.close();
  const dialog=document.createElement('dialog');dialog.className='map-options-dialog';dialog.setAttribute('aria-label','Reisen filtern');
  const heading=document.createElement('h2');heading.textContent='Reisen filtern';dialog.append(heading);
  const originalYear=document.querySelector('#worldMap .world-year-filter select');
  const availableYears=originalYear?[...originalYear.options].map(o=>o.value).filter(y=>y!=='all'):TripSearch.years(remoteTrips);
  TripSearch.mount({target:dialog,surface:'map',availableYears,state:{...TripSearch.get('map'),year:originalYear?.value||TripSearch.get('map').year},live:false,onChange:state=>{
    const current=document.querySelector('#worldMap .world-year-filter select');
    if(current){current.value=state.year;current.dispatchEvent(new Event('change',{bubbles:true}));}
    else render();
    dialog.close();
  }});
  const close=document.createElement('button');close.type='button';close.textContent='Schließen';close.onclick=()=>dialog.close();dialog.append(close);dialog.addEventListener('close',()=>dialog.remove());document.body.append(dialog);dialog.showModal();
}
function worldTripMatches(stop,state) {
  const record=remoteTrips.find(t=>String(t.id)===String(stop.trip_id))||{title:stop.trip_title,starts_on:stop.trip_starts_on||stop.starts_at};
  return TripSearch.matches(record,state);
}
function updateWorldSearchStatus(node,stops,focused) {
  let status=node.querySelector('.world-search-status');if(!status){status=document.createElement('div');status.className='world-search-status';status.setAttribute('role','status');node.append(status);}
  const count=new Set(stops.map(stop=>String(stop.trip_id))).size,state=TripSearch.get('map');
  status.textContent=count?count+' '+(count===1?'Reise':'Reisen')+(focused?' · gezielte Auswahl':state.keyword||state.participant||state.query||state.year!=='all'?' · Filter aktiv':''):'Keine Reisen gefunden';
  // The app's world header line shows the same count below the name.
  const sub=document.querySelector('.world-topbar .page-topbar-sub');if(sub)sub.textContent=status.textContent;
}

function renderPage() {
  const hash = location.hash;
  window.ReiseNativeUI?.navigation?.({ hash, app, go, openMediaLightbox, refresh: render });
  { const editTrip = hash.match(/^#trip\/([^/]+)/)?.[1] || null; if (!editTrip) setTripEditing(null, false); if (access.loggedIn !== null) mountTripEditButton(editTrip); }
  if (access.loggedIn === null) return;
  if(access.loggedIn)window.ReiseWebUI?.navigation?.({hash,app,go,openMediaLightbox,refresh:render,access});
  if (window.ReiseNativeUI && hash === '#settings') { window.ReiseNativeUI.settings({ app, go }); return; }
  if (!access.loggedIn) { location.assign(localStorage.getItem('atlas-last-auth-method') === 'guest' ? './gastzugang.html' : './login.html'); return; }
  helpMenu?.removeAttribute('aria-current');
  if((hash==='#connections'||hash==='#settings')&&!window.ReiseNativeUI){administration();return;}
  if (hash === '#hilfe') { helpMenu?.setAttribute('aria-current', 'page'); renderHelp(); return; }
  if (hash === '#admin') { go('#settings'); return; }
  if (hash === '#library') { library(); return; }
  if (hash === '#import' || (window.ReiseNativeUI && /^#import\/(local|onedrive)$/.test(hash))) { if (access.isAdmin || access.canImport) importer(); else go('#home'); return; }
  const day = hash.match(/^#trip\/([^/]+)\/day\/([^/]+)/);
  const station = hash.match(/^#trip\/([^/]+)\/(?:station|place)\/([^/]+)/);
  const journey = hash.match(/^#trip\/([^/]+)/);
  if (/^#trip\/[^/]+\/days$/.test(hash)) renderTripDayOverview(journey[1]);
  else if (day) serverDayView(day[1], day[2]);
  else if (station) serverPlace(station[1], station[2]);
  else if (journey) trip(journey[1]);
  else home();
}
function home(nativeReady = false) {
  if (window.ReiseNativeUI && !nativeReady) { window.ReiseNativeUI.home({app,go,renderMap:()=>home(true)}); return; }
  app.replaceChildren(); headerTitle.textContent = 'WELTKARTE';
  const page = document.createElement('section'); page.className = 'world-page';
  page.innerHTML = '<div id="worldMap" class="world-map" aria-label="Weltkarte mit allen Reisestationen"></div><div class="world-map-loading" role="status"><span class="spinner" aria-hidden="true"></span><span>KARTE WIRD GELADEN …</span></div>';
  // Settings for all trips also from the world map: the same floating button as on Alle Reisen (top right).
  if(window.ReiseNativeUI){
    const bar=document.createElement('header');bar.className='page-topbar world-topbar';
    bar.innerHTML='<svg class=\"app-mark\" viewBox=\"0 0 1024 1024\" aria-hidden=\"true\"><defs><linearGradient id=\"appMarkBg\" x1=\"0\" y1=\"0\" x2=\"1\" y2=\"1\"><stop offset=\"0\" stop-color=\"#5b4636\"/><stop offset=\"1\" stop-color=\"#b8573f\"/></linearGradient><clipPath id=\"appMarkClip\"><rect width=\"1024\" height=\"1024\" rx=\"230\"/></clipPath></defs><g clip-path=\"url(#appMarkClip)\"><rect width=\"1024\" height=\"1024\" fill=\"url(#appMarkBg)\"/><path d=\"M0 640C110 610 170 700 250 660S330 560 300 480 160 380 0 420z\" fill=\"#26473e\"/><path d=\"M0 1024V800C120 760 200 840 330 820S470 900 440 1024z\" fill=\"#26473e\"/><path d=\"M1024 0H640c30 60 120 70 170 40s150 0 214 60z\" fill=\"#26473e\"/><path d=\"M380 0c20 150-60 250 0 400s-40 300 30 624M0 260c200-40 380 40 560 10s330-90 464-40M520 1024c40-160 200-220 504-260\" fill=\"none\" stroke=\"#e7b98a\" stroke-width=\"14\" stroke-opacity=\".55\"/><g fill=\"#f6efe2\" transform=\"translate(-9 0)\"><path d=\"M288 820L490 296h36L334 820z\"/><path d=\"M496 296h56l232 524h-96z\"/><path d=\"M384 614h252l14 38H370z\"/><rect x=\"240\" y=\"808\" width=\"146\" height=\"34\" rx=\"5\"/><rect x=\"640\" y=\"808\" width=\"186\" height=\"34\" rx=\"5\"/></g><path d=\"M512 286c-28-36-70-70-70-116a70 70 0 0 1 140 0c0 46-42 80-70 116z\" fill=\"#ffd98a\"/><circle cx=\"512\" cy=\"170\" r=\"26\" fill=\"#26473e\"/></g></svg><div class="page-topbar-text"><span class="page-topbar-title">AlleReisen</span><span class="page-topbar-sub" role="status"></span></div>';
    page.append(bar); // "Einstellungen" is in the bottom menu of the app.
  }else {/* website: "Einstellungen" is in the bottom menu */}
  app.append(page); renderWorldMap();
}
function renderArchiveConnectionStatus(page, rows, open) {
  const card=document.createElement('section');card.className='archive-connection-status';card.setAttribute('aria-label','Anmeldung und Verbindungen');
  const heading=document.createElement('h2');heading.textContent='Anmeldung & Verbindungen';card.append(heading);
  const list=document.createElement('div');list.className='archive-status-rows';card.append(list);
  for(const row of rows){const item=document.createElement('div');item.className='archive-status-row';item.dataset.state=row.state||'neutral';item.title=row.label+': '+row.text;const title=document.createElement('strong'),value=document.createElement('span');title.textContent=row.label+': ';value.textContent=row.text;item.append(title,value);list.append(item);}
  if(open){const action=document.createElement('button');action.type='button';action.className='edit-link';action.textContent='Anmeldung & Verbindungen öffnen';action.onclick=open;card.append(action);}
  page.querySelector('.archive-connection-status')?.remove();const head=page.querySelector('.library-head');if(head)head.after(card);else page.prepend(card);return card;
}
function webArchiveStatusRows() {
  const guest=!!access.isGuest||(!access.isAdmin&&!access.isMicrosoft&&!access.isLocal);
  return [{label:'Konto',text:access.loggedIn?[access.user?.display_name,access.user?.email].filter(Boolean).join(' · ')||'Kontoname nicht verfügbar':'Nicht angemeldet'}, {label:'Anmeldung',text:access.isLocal?'Lokales Archiv':guest?'Gastzugang aktiv':access.loggedIn?(access.isMicrosoft?'Microsoft-Anmeldung aktiv':'Angemeldet'):'Nicht angemeldet',state:access.loggedIn?'ok':'neutral'},
    {label:'Fotozugriff',text:access.isLocal?'Quellen dieses Archivs':access.isAdmin&&access.isMicrosoft?'Über das Microsoft-Konto des Reiseinhabers':'Über die Medienfreigabe des Reiseinhabers'},
    {label:'Website',text:access.isLocal?'Lokales Archiv – Website optional':location.origin,state:access.isLocal?'neutral':'ok'},
    {label:'Abgleich',text:access.isLocal?'Website-Verbindung über Online-Reisen öffnen':'Änderungen werden direkt auf dieser Website gespeichert'}];
}
function renderWebArchiveStatus(page) {renderArchiveConnectionStatus(page,webArchiveStatusRows(),()=>go('#settings'));}
function webRoleText() { return access.isAdmin ? 'Admin' : access.canImport ? 'Import' : access.isGuest ? 'Gast' : 'Ansehen'; }
function renderWebAccountLine(page) {
  const line=document.createElement('p');line.className='archive-account-line';
  const name=[access.user?.display_name,access.user?.email].find(Boolean)||'Angemeldet';
  const button=document.createElement('button');button.type='button';button.className='archive-account-button';
  button.textContent=access.isLocal?'Lokales Archiv':'Angemeldet: '+name+' ('+webRoleText()+') · '+location.host;
  button.onclick=()=>go('#settings');line.append(button);
  // Same row as the "ÜBERSICHT" label above the title.
  const eyebrow=page.querySelector('.library-head .eyebrow');
  if(eyebrow){const row=document.createElement('div');row.className='library-eyebrow-row';eyebrow.replaceWith(row);row.append(eyebrow,line);}else page.prepend(line);
}
function renderWebConnectionsPage() {
  app.replaceChildren();const page=document.createElement('section');page.className='detail-page connections-page';
  const heading=document.createElement('h1');heading.textContent='Anmeldung & Verbindungen';page.append(heading);
  const card=renderArchiveConnectionStatus(page,webArchiveStatusRows());card.querySelector('h2').remove();heading.after(card);
  const links=[['Microsoft-Anmeldung öffnen','./login.html'],['Gastzugang öffnen','./gastzugang.html'],['Zur Reiseliste','#library']];
  if(access.canConfigureAutomation)links.unshift(['Fotoautomatik einstellen','./api/travel-automation-setup.php']);
  for(const [label,url] of links){const link=document.createElement('a');link.className='edit-link';link.textContent=label;link.href=url;page.append(link);}
  if(access.loggedIn&&!access.isLocal)renderAccountDeletion(page);
  app.append(page);
}
// Deleting the own server account (Apple/store requirement; also available to guests).
function renderAccountDeletion(page) {
  const box=document.createElement('details');box.className='import-card account-deletion';
  const summary=document.createElement('summary');summary.textContent='Konto löschen';box.append(summary);
  const body=document.createElement('div');box.append(body);page.append(box);
  const text=(tag,content,className)=>{const node=document.createElement(tag);node.textContent=content;if(className)node.className=className;body.append(node);return node;};
  let loaded=false;
  box.addEventListener('toggle',async()=>{
    if(!box.open||loaded)return;loaded=true;body.replaceChildren();const status=text('p','Wird geladen …','native-form-status');
    let info;
    try{const response=await apiFetch('./api/api.php?action=account-deletion',{credentials:'same-origin',cache:'no-store'});info=await response.json();if(!response.ok)throw new Error(info.error||'Angaben konnten nicht geladen werden.');}
    catch(error){status.textContent=error.message;loaded=false;return;}
    status.remove();
    text('p','Gelöscht werden dein Konto '+info.email+', deine '+info.trips+' eigenen Reisen mit allen Stationen, Texten und '+info.media+' Foto- und Videoverweisen, deine Verbindungen zu OneDrive und Dropbox, die Verbindungen deiner Apps sowie Freigaben, die andere dir gegeben haben.');
    text('p','Nicht gelöscht werden die Originalfotos und -videos in deinem OneDrive oder Dropbox und die Reisen anderer Personen. Reisen, die du in einer App nur auf dem Gerät gespeichert hast, entscheidest du in der App.');
    text('p','Die Erlaubnis für AlleReisen kannst du zusätzlich in deinem Microsoft-Konto widerrufen: account.live.com/consent/Manage','hint');
    if(info.blocked){text('p',info.blocked,'native-form-status');return;}
    const label=document.createElement('label');label.textContent='Zur Bestätigung deine E-Mail-Adresse eingeben';
    const input=document.createElement('input');input.type='email';input.autocomplete='off';input.spellcheck=false;label.append(input);body.append(label);
    const button=document.createElement('button');button.type='button';button.className='primary danger';button.textContent='KONTO ENDGÜLTIG LÖSCHEN';button.disabled=true;body.append(button);
    const result=text('p','','native-form-status');result.setAttribute('role','status');
    input.addEventListener('input',()=>{button.disabled=input.value.trim().toLowerCase()!==String(info.email).toLowerCase();});
    button.addEventListener('click',async()=>{
      button.disabled=true;input.disabled=true;result.textContent='Konto wird gelöscht …';
      try{
        const response=await apiFetch('./api/api.php?action=account-delete',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Reise-Account-Csrf':info.csrf_token||''},body:JSON.stringify({confirm_email:input.value.trim()})});
        const data=await response.json().catch(()=>({}));if(!response.ok)throw new Error(data.error||'Das Konto konnte nicht gelöscht werden.');
        result.textContent='Dein Konto wurde gelöscht ('+data.trips+' Reisen). Du wirst abgemeldet.';
        try{localStorage.removeItem('atlas-last-auth-method');}catch{}
        setTimeout(()=>location.assign('./login.html'),2500);
      }catch(error){result.textContent=error.message;input.disabled=false;button.disabled=input.value.trim().toLowerCase()!==String(info.email).toLowerCase();}
    });
  });
}
function renderJourneyDayStrip(page, tripId, days, currentDay) {
  if(!days.length)return;
  const strip=document.createElement('nav');strip.className='journey-day-strip';strip.setAttribute('aria-label','Alle Reisetage');
  for(const type of ['pointerdown','pointerup','pointercancel'])strip.addEventListener(type,event=>event.stopPropagation());
  const queue=[],controller=new AbortController();let running=0,closed=false,observer;
  const cleanup=()=>{closed=true;controller.abort();observer?.disconnect();window.removeEventListener('hashchange',cleanup);};window.addEventListener('hashchange',cleanup,{once:true});
  const run=()=>{while(running<2&&queue.length&&!closed&&page.isConnected){const work=queue.shift();running++;work().finally(()=>{running--;run();});}};
  const schedule=button=>{if(button.dataset.loaded)return;button.dataset.loaded='true';queue.push(async()=>{
    const get=async action=>{try{const response=await apiFetch(`./api/api.php?action=${action}&trip=${encodeURIComponent(tripId)}&day=${encodeURIComponent(button.dataset.day)}`,{credentials:'same-origin',signal:controller.signal});return response.ok?await response.json():null;}catch{return null;}};
    const [summary,weather]=await Promise.all([get('day-summary'),get('day-weather')]);if(closed||!page.isConnected)return;
    button.querySelector('.journey-day-excerpt').textContent=summary?.summary?.trim()||'Kein Tagestext';
    const forecast=button.querySelector('.journey-day-weather');forecast.replaceChildren();
    if(weather?.available){const icon=dayWeatherIcon(weather.weather_code);icon.setAttribute('aria-label',dayWeatherSymbol(weather.weather_code)[1]);forecast.append(icon);const temp=document.createElement('span');temp.textContent=weather.temperature_min!=null&&weather.temperature_max!=null?`${Math.round(weather.temperature_min)}–${Math.round(weather.temperature_max)} °C`:dayWeatherSymbol(weather.weather_code)[1];forecast.append(temp);}else forecast.textContent='Wetter nicht verfügbar';
  });run();};
  for(const day of days){const button=document.createElement('button');button.type='button';button.dataset.day=day;button.className='journey-day-card';
    const date=document.createElement('strong');date.textContent=new Intl.DateTimeFormat(globalThis.ReiseI18n?.language==='en'?'en-GB':'de-AT',{weekday:'short',day:'2-digit',month:'2-digit',year:'numeric'}).format(new Date(day+'T12:00:00'));
    const excerpt=document.createElement('span');excerpt.className='journey-day-excerpt';excerpt.textContent='Tagestext wird geladen …';const weather=document.createElement('span');weather.className='journey-day-weather';weather.textContent='Wetter wird geladen …';button.append(date,excerpt,weather);
    if(day===currentDay)button.setAttribute('aria-current','date');button.onclick=()=>go(`#trip/${tripId}/day/${day}`);button.onfocus=()=>schedule(button);strip.append(button);
  }
  const header=page.querySelector('.unified-day-header,.day-place-header');
  if(header)header.before(strip);else page.prepend(strip);
  const current=strip.querySelector('[aria-current]');if(current)strip.scrollLeft=Math.max(0,current.offsetLeft-strip.offsetLeft-(strip.clientWidth-current.offsetWidth)/2);
  if('IntersectionObserver' in window){observer=new IntersectionObserver(entries=>{for(const entry of entries)if(entry.isIntersecting)schedule(entry.target);},{root:strip,rootMargin:'0px 160px'});strip.querySelectorAll('button').forEach(button=>observer.observe(button));}
  else [...strip.children].slice(Math.max(0,days.indexOf(currentDay)-1),Math.max(0,days.indexOf(currentDay)-1)+3).forEach(schedule);
  if(current)schedule(current);
}
function library() {
  if ((access.isAdmin || ownsAnyTrip()) && !window.ReiseWebUI) { administration(); return; }
  app.replaceChildren(); headerTitle.textContent = access.isLocal ? 'LOKALE REISEN' : 'ALLE REISEN';
  const page = document.createElement('section'); page.className = 'library-page';
  page.innerHTML = '<div class="library-head"><div><p class="eyebrow">ÜBERSICHT</p><h1>' + (access.isLocal ? 'Lokale' : 'Alle') + ' <em>Reisen.</em></h1></div><span class="media-count"></span></div><div class="trip-grid"></div>';
  
  renderWebAccountLine(page);
  const records = trips(); page.querySelector('.media-count').textContent = records.length + ' REISEN';
  const searchHost=document.createElement('div');page.querySelector('.trip-grid').before(searchHost);
  for (const record of records) {
    const card = document.createElement('article'); card.className = 'trip-card';
    card.innerHTML = '<div class="trip-cover"><img class="trip-cover-image" loading="lazy" alt=""></div><div class="trip-card-copy"><p></p><h3></h3><small></small><div class="admin-trip-actions"></div></div>';
    card.dataset.tripId=String(record.id);
    card.querySelector('img').src = image(record.cover); card.querySelector('h3').textContent = record.title; if(record.publication_status==='pending'){const badge=document.createElement('p');badge.className='trip-pending';badge.textContent='Freigabe ausstehend';card.querySelector('.trip-card-copy').prepend(badge);}
    card.querySelector('p').textContent = record.date; card.querySelector('small').textContent = record.media + ' Medien';
    appendTripOpenActions(card.querySelector('.admin-trip-actions'), record.id);
    page.querySelector('.trip-grid').append(card);
  }
  const result=document.createElement('p');result.className='trip-search-results';result.setAttribute('role','status');searchHost.after(result);
  const applySearch=state=>{const matching=new Set(records.filter(record=>TripSearch.matches(record,state)).map(record=>String(record.id)));page.querySelectorAll('.trip-card').forEach(card=>{card.hidden=!matching.has(card.dataset.tripId);});page.querySelector('.media-count').textContent=matching.size+' / '+records.length+' REISEN';result.textContent=!records.length?'Noch keine Reisen vorhanden.':!matching.size?'Keine Reisen gefunden. Ändere die Suche oder setze die Filter zurück.':matching.size+' von '+records.length+' Reisen';};
  TripSearch.mount({target:searchHost,surface:'list',availableYears:TripSearch.years(records),onChange:applySearch});applySearch(TripSearch.get('list'));
  app.append(page);
}
function renderDaySteps(page, summary, host) {
  const text=journeyStepLabel(summary);
  if(!text)return;
  const value=document.createElement('p');value.className='journey-day-metrics journey-day-steps';
  value.textContent=text;host.append(value);
}
async function mountTripDayAdministration(page, tripRecord) {
  if (!page.isConnected) return;
  const host=document.createElement('header');host.className='trip-day-heading page-topbar trip-topbar';
  const copy=document.createElement('div');copy.className='trip-day-heading-copy';
  const title=document.createElement('h1');title.id='overviewTitle';
  const description=document.createElement('p');description.className='overview-description';
  const participants=document.createElement('p');participants.className='overview-participants';
  const show=()=>{title.textContent=tripRecord.title;description.textContent=tripRecord.intro||'';participants.textContent=tripRecord.participants?'Teilnehmer: '+tripRecord.participants:'Teilnehmer: noch nicht angegeben';};show();
  const overviewLabel=document.createElement('p');overviewLabel.className='eyebrow';overviewLabel.textContent='REISEÜBERSICHT';
  copy.className+=' page-topbar-text';copy.append(overviewLabel,title,description,participants);host.append(copy);page.prepend(host);
  if (!mayEditTrip(tripRecord.id) || tripRecord.read_only || window.ReiseNativeUI?.isReadOnlyTrip?.(tripRecord.id)) return;
  // Trip-level editing lives in the "Reise bearbeiten" sheet (top-right button).
}
// Edit mode per trip: station tools, route editing and photo changes appear on the trip pages
// only while it is switched on in the "Reise bearbeiten" sheet (importers and admins).
function tripEditing(tripId) {
  try { return !!tripId && sessionStorage.getItem('reise-trip-editing') === String(tripId); } catch { return false; }
}
function setTripEditing(tripId, enabled) {
  try { if (enabled) sessionStorage.setItem('reise-trip-editing', String(tripId)); else sessionStorage.removeItem('reise-trip-editing'); } catch {}
}
function rememberSharedTrip() {
  if (window.ReiseNative || !/^#trip\/[^/]+/.test(location.hash)) return;
  try { localStorage.setItem('reise-shared-trip', JSON.stringify({ hash: location.hash, at: Date.now() })); } catch {}
}
// After sign-in: open the trip from a shared link, or say why it cannot be shown.
function openSharedTrip() {
  if (window.ReiseNative) return;
  let stored = null;
  try { stored = JSON.parse(localStorage.getItem('reise-shared-trip') || 'null'); localStorage.removeItem('reise-shared-trip'); } catch {}
  const target = stored && Date.now() - Number(stored.at) < 3600000 && /^#trip\/[^/]+/.test(String(stored.hash)) ? String(stored.hash) : /^#trip\/[^/]+/.test(location.hash) ? location.hash : null;
  if (!target) return;
  const id = decodeURIComponent(target.match(/^#trip\/([^/]+)/)[1]);
  const known = remoteTrips.some(record => String(record.id) === id) || localTrips().some(record => String(record.id) === id);
  if (!known) { alert(shareText('Diese Reise ist für dein Konto nicht freigegeben. Bitte die Person, die sie geteilt hat, dich zur Reise einzuladen.')); location.hash = '#library'; return; }
  if (location.hash !== target) location.hash = target;
}
async function tripRecordFor(tripId) {
  let record = (typeof remoteTrips !== 'undefined' ? remoteTrips : []).find(t => String(t.id) === String(tripId));
  if (record) return record;
  const response = await apiFetch('./api/api.php?action=trips', { credentials: 'same-origin', cache: 'no-store' });
  const data = await response.json().catch(() => ({})); if (!response.ok) throw Error(data.error || 'Reise konnte nicht geladen werden.');
  remoteTrips = data.trips || remoteTrips; record = remoteTrips.find(t => String(t.id) === String(tripId));
  if (!record) throw Error('Reise nicht gefunden.');
  return record;
}
const shareText=value=>window.ReiseI18n?.t?.(value)??value;
// Shares the link to a trip (Signal, WhatsApp, Mail …). Only people invited to the trip can open it; access stays server-side.
// Sends the link to a trip via the system share sheet (Signal, WhatsApp, Mail …).
async function sendTripLink({ title, text, url, tripId }) {
  if (window.ReiseNative) { const result = await ReiseNative.request('shareTrip', { tripId, title, text, url: url || '' }); if (result && result.shared === false && result.message) alert(shareText(result.message)); return; }
  url ||= new URL(location.pathname, location.origin).href + '#trip/' + encodeURIComponent(tripId);
  if (navigator.share) { try { await navigator.share({ title, text, url }); } catch (error) { if (error?.name !== 'AbortError') throw error; } return; }
  await navigator.clipboard.writeText(text + ' ' + url); alert(shareText('Link kopiert. Du kannst ihn jetzt in Signal, WhatsApp oder einer E-Mail einfügen.'));
}
async function tripAdminRequest(operation, method, body, tripId) {
  if (window.ReiseNative) return ReiseNative.request('websiteAdmin', { operation, method, body, ...(tripId ? { trip: tripId } : {}) });
  const response = await apiFetch('./api/api.php?action=' + operation + (method === 'GET' && tripId ? '&trip=' + encodeURIComponent(tripId) : ''), { method, credentials: 'same-origin', cache: 'no-store', headers: { 'Content-Type': 'application/json' }, ...(method === 'GET' ? {} : { body: JSON.stringify(body) }) });
  const data = await response.json().catch(() => ({})); if (!response.ok) throw Error(data.error || 'Anfrage fehlgeschlagen.'); return data;
}
// Share = invite: the owner (or an admin) enters the e-mail address, the server adds the person as a guest of this trip
// and returns a personal link (7 days, single use) to send via Signal or WhatsApp. People already invited get the plain link.
async function shareTrip(tripId) {
  const record = await tripRecordFor(tripId).catch(() => null);
  const title = record?.title || shareText('Reise'), id = String(record?.serverId || tripId);
  const plainText = title + ' – ' + shareText('Reise mit Karte und Diashow. Öffnen können sie alle, die zu dieser Reise eingeladen sind.');
  const mayInvite = mayEditTrip(tripId) && !window.ReiseNativeUI?.isReadOnlyTrip?.(tripId);
  if (!mayInvite) return sendTripLink({ title, text: plainText, tripId: id });
  document.querySelector('.share-sheet')?.remove();
  const el = (tag, text, className) => { const node = document.createElement(tag); if (text) node.textContent = shareText(text); if (className) node.className = className; return node; };
  const dialog = el('dialog', '', 'share-sheet'); dialog.setAttribute('aria-labelledby', 'shareSheetTitle');
  const heading = el('h2', 'Reise teilen'); heading.id = 'shareSheetTitle';
  const name = el('p', '', 'share-sheet-trip'); name.textContent = title;
  const form = el('form'); const label = el('label', 'E-Mail-Adresse der Person, die du einlädst');
  const email = el('input'); email.type = 'email'; email.required = true; email.autocomplete = 'email'; email.inputMode = 'email'; email.placeholder = 'name@beispiel.at';
  const known = el('datalist'); known.id = 'shareSheetEmails'; email.setAttribute('list', known.id); label.append(email, known);
  const hint = el('p', 'Die Person wird als Gast dieser Reise eingetragen. Du bekommst einen persönlichen Link (7 Tage gültig, einmal nutzbar), den du über Signal, WhatsApp oder Mail schickst.', 'hint');
  const invite = el('button', 'Einladungslink erstellen', 'primary'); invite.type = 'submit';
  const status = el('p', '', 'native-form-status'); status.setAttribute('role', 'status');
  form.append(label, hint, invite, status);
  const plain = el('button', 'Nur Link senden (für bereits Eingeladene)', 'edit-link'); plain.type = 'button';
  const close = el('button', 'Abbrechen', 'edit-link'); close.type = 'button';
  const ready = el('div', '', 'share-sheet-ready'); ready.hidden = true;
  dialog.append(heading, name, form, ready, plain, close); document.body.append(dialog); dialog.showModal();
  dialog.addEventListener('close', () => dialog.remove());
  close.onclick = () => dialog.close();
  plain.onclick = () => { dialog.close(); sendTripLink({ title, text: plainText, tripId: id }).catch(error => alert(error.message)); };
  tripAdminRequest('guest-emails', 'GET', {}, null).then(data => { for (const address of data.emails || []) { const option = document.createElement('option'); option.value = address; known.append(option); } }).catch(() => {});
  form.onsubmit = async event => {
    event.preventDefault(); invite.disabled = true; status.textContent = shareText('Einladung wird erstellt …');
    try {
      const address = email.value.trim().toLowerCase();
      const result = await tripAdminRequest('trip-invite', 'POST', { trip: id, email: address, deliver: 'link' }, id);
      if (!result?.url) throw Error(shareText('Der Server hat keinen Einladungslink geliefert. Bitte den Server aktualisieren.'));
      const text = title + ' – ' + shareText('Einladung zur Reise mit Karte und Diashow. Der Link gilt 7 Tage und nur für dich:');
      const send = () => sendTripLink({ title, text, url: result.url, tripId: id }).catch(error => alert(error.message));
      form.hidden = true; plain.hidden = true; close.textContent = shareText('Fertig'); ready.hidden = false; status.textContent = '';
      const done = el('p', '', 'share-sheet-done'); done.textContent = shareText('Einladung ist bereit für') + ' ' + address;
      const sendButton = el('button', 'Über Signal, WhatsApp … senden', 'primary'); sendButton.type = 'button'; sendButton.onclick = send;
      const copy = el('button', 'Link kopieren', 'edit-link'); copy.type = 'button';
      copy.onclick = () => navigator.clipboard.writeText(text + ' ' + result.url).then(() => { copy.textContent = shareText('Kopiert'); }).catch(error => alert(error.message));
      ready.replaceChildren(done, sendButton, copy);
      if (window.ReiseNative) send();
    } catch (error) {
      status.textContent = /nicht gefunden/i.test(String(error.message)) ? shareText('Diese Reise ist noch nicht auf dem Server. Übertrage sie zuerst (Einstellungen → Server), dann kannst du sie teilen.') : error.message;
    } finally { invite.disabled = false; }
  };
  setTimeout(() => email.focus(), 50);
}
function mountTripEditButton(tripId) {
  document.querySelector('.trip-edit-open')?.remove();
  document.body.classList.toggle('trip-editing', !!tripId && tripEditing(tripId));
  // App and website have "Bearbeiten" in the bottom menu; no floating button.
  if (!tripId || window.ReiseNativeUI || window.ReiseWebUI || !mayEditTrip(tripId)) return;
  const button = document.createElement('button'); button.type = 'button'; button.className = 'trip-edit-open';
  button.textContent = tripEditing(tripId) ? 'Bearbeiten aktiv' : 'Bearbeiten';
  button.setAttribute('aria-pressed', String(tripEditing(tripId)));
  button.onclick = () => openTripEditor(tripId).catch(error => alert(error.message));
  document.body.append(button);
  document.body.style.setProperty('--trip-edit-width', button.offsetWidth + 'px');
}
async function openTripEditor(tripId) {
  if (document.querySelector('.trip-editor-sheet')) return;
  const record = await tripRecordFor(tripId);
  const dialog = document.createElement('dialog'); dialog.className = 'trip-editor-sheet'; dialog.setAttribute('aria-labelledby', 'tripEditorTitle');
  const head = document.createElement('div'); head.className = 'trip-editor-head';
  const heading = document.createElement('h2'); heading.id = 'tripEditorTitle'; heading.textContent = 'Reise bearbeiten';
  const close = document.createElement('button'); close.type = 'button'; close.className = 'edit-link'; close.textContent = 'Schließen'; close.onclick = () => dialog.close();
  head.append(heading, close);
  const subtitle = document.createElement('p'); subtitle.className = 'trip-editor-subtitle'; subtitle.textContent = record.title || '';
  // Edit mode switch: shows the editing controls directly on day, station and map pages.
  const mode = document.createElement('section'); mode.className = 'trip-editor-mode';
  const modeLabel = document.createElement('label'); const toggle = document.createElement('input'); toggle.type = 'checkbox'; toggle.checked = tripEditing(tripId);
  modeLabel.append(toggle, document.createTextNode(' Bearbeitungsmodus auf den Reiseseiten'));
  const modeHint = document.createElement('p'); modeHint.className = 'hint';
  modeHint.textContent = 'Blendet auf Tages-, Stations- und Kartenseiten die Werkzeuge ein: Stationen umbenennen, zusammenführen und verschieben, Strecken zwischen Stationen ändern, Aufnahmezeit und Station einzelner Fotos ändern. Ausgeschaltet sieht die Reise aus wie für alle anderen.';
  toggle.onchange = () => { setTripEditing(tripId, toggle.checked); dialog.close(); render(); };
  mode.append(modeLabel, modeHint);
  const tools = document.createElement('div'); tools.className = 'trip-editor-tools';
  dialog.append(head, subtitle, mode, tools);
  document.body.append(dialog);
  dialog.addEventListener('close', () => { dialog.remove(); mountTripEditButton(tripId); });
  dialog.showModal();
  if (window.ReiseNativeUI) {
    await ReiseNativeUI.tripAdministration({ host: tools, tripId, go, directAdmin: true });
    if (window.ReiseNativeUI.openHealthSteps && window.ReiseNative) {
      try { const state = await ReiseNative.request('state'); if (String(state.platform || '').toLowerCase() === 'ios') {
        const steps = document.createElement('button'); steps.type = 'button'; steps.className = 'edit-link'; steps.textContent = 'Schrittanzahl abfragen';
        steps.onclick = () => ReiseNativeUI.openHealthSteps({ tripId }).catch(error => alert(error.message)); tools.prepend(steps);
      } } catch {}
    }
  } else mountWebTripAdministration(tools, record, true);
  tools.querySelectorAll('details.trip-management').forEach(details => { details.open = true; details.querySelector(':scope > summary')?.remove(); });
  const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'edit-link trip-editor-delete'; remove.textContent = 'Reise löschen';
  remove.onclick = async () => {
    if (window.ReiseNativeUI?.deleteTrip) { if (await ReiseNativeUI.deleteTrip(tripId)) { dialog.close(); go('#library'); } return; }
    dialog.close(); await eraseTrip(record.id); if (!remoteTrips.some(t => String(t.id) === String(tripId))) go('#library');
  };
  const removeNote = document.createElement('p'); removeNote.className = 'hint'; removeNote.textContent = 'Löscht die Reise mit Texten und Stationen. Die Originalfotos und Videos bleiben an ihrem Speicherort.';
  const danger = document.createElement('section'); danger.className = 'trip-editor-danger'; danger.append(remove, removeNote);
  if (dialog.open) dialog.append(danger);
}
async function renderTripDayOverview(tripId) {
  app.innerHTML='<section class="detail-page"><p role="status">Tagesübersicht wird geladen …</p></section>';
  const loading=app.firstElementChild;
  try {
    const response=await apiFetch('./api/api.php?action=trips',{credentials:'same-origin',cache:'no-store'});
    if(!response.ok)throw Error('Die Reise konnte nicht geladen werden.');
    const data=await response.json();if(!loading.isConnected)return;remoteTrips=data.trips||[];
    if(!remoteTrips.some(t=>String(t.id)===String(tripId)))throw Error('Diese Reise ist nicht zugänglich.');
    const stopsResponse=await apiFetch('./api/api.php?action=stops&trip='+encodeURIComponent(tripId),{credentials:'same-origin'});
    if(!stopsResponse.ok)throw Error('Die Reisetage konnten nicht geladen werden.');
    const result=await stopsResponse.json();if(!loading.isConnected)return;
    const days=[...new Set((result.stops||[]).map(stop=>stopDay(stop.starts_at)).filter(Boolean))].sort();
    if(days.length){go('#trip/'+tripId+'/day/'+days[0]);return;}
    if(result.stops?.length){go('#trip/'+tripId+'/station/'+encodeURIComponent(result.stops[0].id));return;}
    loading.textContent='Diese Reise enthält noch keine Reisetage.';
    await mountTripDayAdministration(loading,remoteTrips.find(t=>String(t.id)===String(tripId)));
  }catch(error){if(loading.isConnected)loading.querySelector('[role=status]')?.replaceChildren(document.createTextNode(error.message));}
}
function trip(tripId, refreshed = false) {
  if (window.ReiseNativeUI && !refreshed) {
    apiFetch('./api/api.php?action=trips').then(response => response.json()).then(data => {
      if (!location.hash.startsWith('#trip/' + tripId)) return;
      remoteTrips = data.trips || []; trip(tripId, true);
    }).catch(() => { app.textContent = 'Die Reise konnte nicht geladen werden.'; });
    return;
  }
  const serverTrip = remoteTrips.find(record => String(record.id) === String(tripId));
  if (!serverTrip && /^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i.test(String(tripId))) {
    app.replaceChildren(Object.assign(document.createElement('section'), { className: 'detail-page', textContent: 'Reise wird geladen ...' }));
    apiFetch('./api/api.php?action=trips', { credentials: 'same-origin' }).then(response => response.ok ? response.json() : null).then(data => {
      const resolved = (data?.trips || []).find(record => String(record.id) === String(tripId));
      if (!resolved || (location.hash !== `#trip/${tripId}` && !location.hash.startsWith(`#trip/${tripId}/map/`))) return;
      remoteTrips = data.trips;
      trip(String(resolved.id));
    }).catch(() => { app.textContent = 'Die Reise konnte nicht geladen werden.'; });
    return;
  }
  if (!serverTrip) { app.textContent = 'Diese Reise ist nicht im Archiv vorhanden.'; return; }
  app.replaceChildren(document.querySelector('#mapTemplate').content.cloneNode(true));
  headerTitle.textContent = serverTrip.title || 'Reise';
  const heading = document.querySelector('#tripTitle');
  if (heading) heading.textContent = serverTrip.title || 'Reise';
  const intro = document.querySelector('.hero-copy .lede');
  if (intro) intro.textContent = serverTrip.intro || 'Die Bilder, Orte und Zeiten dieser Reise erzählen ihre Geschichte.';
  if (!canEditTrip()) {
    document.querySelector('#editTitle')?.remove();
    document.querySelector('#editTripIntro')?.remove();
  }
  if(serverTrip.publication_status==='pending'){const note=document.createElement('p');note.className='trip-pending';note.textContent='Freigabe ausstehend. Diese Reise ist derzeit nur für den Importeur und die Hauptadmins sichtbar.';document.querySelector('.hero-copy')?.append(note);}
  renderRoute(String(serverTrip.id));
}
// World map: each trip gets its own bright colour that stands out on satellite imagery.
const worldRouteColors=['#ff5a5f','#ffd166','#4cc9f0','#7bf1a8','#ff7ae0','#ff9f1c','#b9a4ff','#00f5d4'];
// 0.3.8: stations are plain dots, a little darker than their connecting line (cream lines: warm beige).
const stationDotColor=line=>{const m=/^#([0-9a-f]{6})$/i.exec(line||'');if(!m)return '#d6c29c';const n=parseInt(m[1],16);return '#'+[16,8,0].map(shift=>Math.round(((n>>shift)&255)*.78).toString(16).padStart(2,'0')).join('');};
// 0.4: every trip keeps one colour, derived from its id: the same on the world map, the trip map and the day maps,
// whatever filter is active (before, the colour followed the trip's position in the visible list).
const tripRouteColor=tripId=>{let hash=2166136261;for(const char of String(tripId??''))hash=Math.imul(hash^char.codePointAt(0),16777619)>>>0;return worldRouteColors[hash%worldRouteColors.length];};
const worldTripColor=(stops,tripId)=>tripRouteColor(tripId);
async function renderAppleWorldMap(node, allStops) {
  const located = allStops.filter(dayStationCoordinates);
  const years = TripSearch.years(remoteTrips).length?TripSearch.years(remoteTrips):[...new Set(located.map(stop=>String(stop.trip_starts_on||stop.starts_at||'').slice(0,4)).filter(y=>/^\d{4}$/.test(y)))].sort().reverse();
  let focusTrip = sessionStorage.getItem('atlas-focus-trip') || new URLSearchParams(location.search).get('show-map');
  const filter = document.createElement('label'); filter.className = 'world-year-filter';
  filter.innerHTML = '<span>REISEJAHR</span><select aria-label="Reisejahr auswählen"><option value="all">ALLE JAHRE</option></select>';
  const select = filter.querySelector('select'); years.forEach(year => select.add(new Option(year, year)));
  select.value = years.includes(node.dataset.year||TripSearch.get('map').year) ? (node.dataset.year||TripSearch.get('map').year) : 'all';
  node.append(filter);
  let current, generation = 0, drawing = Promise.resolve();
  const draw = () => {
    const version = ++generation;
    const run = async () => {
      if(version!==generation || !node.isConnected)return;
      const stops = located.filter(stop => focusTrip ? String(stop.trip_id) === String(focusTrip) : worldTripMatches(stop,{...TripSearch.get('map'),year:select.value}));
      updateWorldSearchStatus(node,stops,focusTrip);
      const markers = stops.map(stop => ({ id: String(stop.id), title: `${stop.trip_title || 'Reise'} · ${stationLabel(stop)}`, number: stop.stationNumber, latitude: Number(stop.latitude), longitude: Number(stop.longitude), tripId: stop.trip_id, mediaId: stop.preview_id || '', color: stationDotColor(worldTripColor(stops, stop.trip_id)) }));
      if(current && !current.removed)await current.setMarkers(markers);
      else current=await window.ReiseAppleMaps.mount(node,{world:true,markers});
      const map=current;
      if(!map || version!==generation || !node.isConnected)return;
      if (!map.worldRouteRefreshInstalled) {
        map.worldRouteRefreshInstalled = true;
        const changed = () => { if(node.isConnected && !map.removed)draw().catch(()=>{}); };
        window.addEventListener('atlas-route-changed', changed);
        const remove = map.remove.bind(map);
        map.remove = () => { window.removeEventListener('atlas-route-changed', changed); remove(); };
      }
      map.onSelect=id=>{const stop=stops.find(stop=>String(stop.id)===id);if(stop)openServerTrip(stop.trip_id);};
      map.onDoubleSelect=id=>{const stop=stops.find(stop=>String(stop.id)===id);if(!stop)return;if(String(id).startsWith('main-location-'))openServerTrip(stop.trip_id);else startStationGroupSlideshow(stops,[id],stop.trip_id).catch(error=>alert(error.message));};
      map.onCluster=ids=>startStationGroupSlideshow(stops,ids).catch(error=>alert(error.message));
      map.connections=new Map();
      map.onRoute=key=>{const connection=map.connections.get(key);if(connection&&mayEditTrip(connection.tripId))openAppleRouteEditor(stops.filter(stop=>String(stop.trip_id)===String(connection.tripId)),connection.tripId,connection).catch(error=>alert(error.message));};
      const groups = new Map(),routes=[];
      for (const stop of stops) { const key=String(stop.trip_id);if(!groups.has(key))groups.set(key,[]);groups.get(key).push(stop); }
      for(const [trip,group] of groups){
        group.sort((a,b)=>Number(a.map_position)-Number(b.map_position)||String(a.starts_at||'').localeCompare(String(b.starts_at||'')));
        group.slice(1).forEach((to,index)=>{routes.push({trip,index,from:group[index],to});map.connections.set(trip+'/'+index,{tripId:trip,fromId:String(group[index].id),toId:String(to.id)});});
      }
      const tripColors=new Map([...groups.keys()].map(trip=>[trip,tripRouteColor(trip)]));
      // Do not start thousands of routing jobs or retain results from an old year.
      let next=0;
      const worker=async()=>{while(next<routes.length && version===generation && !map.removed && node.isConnected){
        const {trip,index,from,to}=routes[next++],a=[Number(from.latitude),Number(from.longitude)],b=[Number(to.latitude),Number(to.longitude)];
        try{const points=await resolveWaterRoute(a,b,trip);if(version===generation && !map.removed && node.isConnected && !points?.error)await map.route(trip+'/'+index,points?.length>=2?points:[a,b],tripColors.get(trip));}catch{}
      }};
      for(let i=0;i<Math.min(4,routes.length);i++)void worker();
    };
    const result=drawing.then(run);drawing=result.catch(()=>{});return result;
  };
  select.onchange=()=>{node.dataset.year=select.value;TripSearch.set('map',{...TripSearch.get('map'),year:select.value});focusTrip=null;draw().catch(()=>{if(node.isConnected)node.textContent='Die Weltkarte konnte nicht geladen werden.';});};
  await draw();
  if(focusTrip){sessionStorage.removeItem('atlas-focus-trip');if(new URLSearchParams(location.search).has('show-map'))history.replaceState(null,'',`${location.pathname}${location.hash}`);focusTrip=null;}
}
function installAppleRouteEditor(host, map, stops, tripId) {
  if(map.routeEditorInstalled||!mayEditTrip(tripId)||stops.filter(dayStationCoordinates).length<2||!globalThis.AtlasManualRoutes)return;
  map.routeEditorInstalled=true;
  map.onRoute=key=>{const connection=map.connections?.get(key);if(connection&&canEditTrip(tripId))return openAppleRouteEditor(stops,tripId,connection).catch(error=>alert(error.message));};
}
async function openAppleRouteEditor(stops,tripId,connection) {
  if(!connection)return;
  const located=stops.filter(dayStationCoordinates);
  if(!mayEditTrip(tripId)||window.ReiseNativeUI?.isReadOnlyTrip?.(tripId)||located.length<2||!globalThis.AtlasManualRoutes)return;
  document.querySelector('.apple-route-dialog')?.close();
  document.querySelector('.media-lightbox [aria-label="Diashow pausieren"]')?.click();
    const dialog = document.createElement('dialog'); dialog.className = 'apple-route-dialog';
    const connectionIndex=located.findIndex((stop,index)=>String(stop.id)===connection.fromId&&String(located[index+1]?.id)===connection.toId);
    if(connectionIndex<0)return;
    dialog.innerHTML = '<h2>Verbindungsstrecke bearbeiten</h2><p class="apple-route-endpoints"></p><p>Die Verbindung beginnt bei den beiden Stationen. Jeder neue Zwischenpunkt teilt den nächstgelegenen Abschnitt und verbindet seine beiden Nachbarn. Start und Ziel bleiben fest.</p><div class="apple-route-map"></div><div class="apple-route-points"></div><p role="status"></p><div class="apple-route-actions"></div>';
    dialog.querySelector('.apple-route-endpoints').textContent=(located[connectionIndex].name||'Station')+' → '+(located[connectionIndex+1].name||'Station');
    const node = dialog.querySelector('.apple-route-map'), list = dialog.querySelector('.apple-route-points'), status = dialog.querySelector('[role="status"]'), actions = dialog.querySelector('.apple-route-actions');
    let editor, draft, from, to, busy = false, version = 0;
    const action = (label, callback) => { const b = document.createElement('button'); b.type = 'button'; b.textContent = label; b.onclick = callback; actions.append(b); return b; };
    const redraw = () => {
      if (!editor || !draft) return;
      editor.route('draft', draft).catch(error => status.textContent = error.message);
      editor.setWaypoints?.(draft.slice(1,-1));
      list.replaceChildren();
      draft.slice(1, -1).forEach((point, index) => {
        const row = document.createElement('div'); row.className = 'apple-route-point';
        for (let coordinate = 0; coordinate < 2; coordinate++) {
          const input = document.createElement('input'); input.type = 'number'; input.step = 'any'; input.value = point[coordinate].toFixed(6); input.setAttribute('aria-label', (coordinate ? 'Längengrad' : 'Breitengrad') + ' Zwischenpunkt ' + (index + 1));
          input.onchange = () => { const value = Number(input.value); if (input.value && Number.isFinite(value) && Math.abs(value) <= (coordinate ? 180 : 90)) { draft[index + 1][coordinate] = value; redraw(); } else { input.value = point[coordinate].toFixed(6); status.textContent = 'Bitte gültige Koordinaten eingeben.'; } }; row.append(input);
        }
        const remove = document.createElement('button'); remove.textContent = 'Entfernen'; remove.onclick = () => { if (!busy) { draft.splice(index + 1, 1); redraw(); } }; row.append(remove); list.append(row);
      });
      status.textContent = (draft.length - 2) + ' Zwischenpunkte';
    };
    const choose = async () => {
      const request = ++version; editor?.remove(); editor = null; draft = null; list.replaceChildren();
      const a = located[connectionIndex], b = located[connectionIndex + 1]; from = [Number(a.latitude), Number(a.longitude)]; to = [Number(b.latitude), Number(b.longitude)];
      actions.querySelectorAll('button').forEach(b => { if (b !== close) b.disabled = true; });
      status.textContent = 'Strecke wird geladen …';
      try {
        const points = await AtlasManualRoutes.draft(tripId, from, to);
        if (request !== version || !dialog.isConnected) return;
        if (points?.error) throw new Error('Die Strecke konnte nicht geladen werden.');
        draft = (points?.length >= 2 ? points : [from, to]).map(p => [...p]);
        if (draft.length > 200) draft = draft.filter((_, i) => i === 0 || i === draft.length - 1 || i % Math.ceil(draft.length / 198) === 0);
        draft[0] = [...from]; draft[draft.length - 1] = [...to];
        editor = await window.ReiseAppleMaps.mount(node, { markers: [a, b].map(stop => ({ id: String(stop.id), title: stop.name || 'Station', latitude: Number(stop.latitude), longitude: Number(stop.longitude) })), onPick: p => {
          if (busy || !draft || draft.length >= 200) return;
          const point = [p.lat, p.lng], scale = Math.cos(p.lat * Math.PI / 180); let nearest = Infinity, after = 0;
          for (let n = 0; n < draft.length - 1; n++) { const start = draft[n], end = draft[n + 1], dx = (end[1] - start[1]) * scale, dy = end[0] - start[0]; const t = Math.max(0, Math.min(1, ((point[1] - start[1]) * scale * dx + (point[0] - start[0]) * dy) / (dx * dx + dy * dy || 1))); const distance = Math.hypot((point[1] - start[1]) * scale - t * dx, point[0] - start[0] - t * dy); if (distance < nearest) { nearest = distance; after = n; } }
          draft.splice(after + 1, 0, point); editor.setSelection(null); redraw();
        } });
        if (request !== version || !dialog.isConnected) { editor?.remove(); return; }
        await editor?.setPicking(true); redraw();
        actions.querySelectorAll('button').forEach(b => b.disabled = false);
      } catch (error) { status.textContent = error.message; }
    };
    const commit = async reset => {
      if (busy || !draft) return; busy = true; dialog.querySelectorAll('button,input').forEach(b => b.disabled = true);
      try { await AtlasManualRoutes.save(tripId, from, to, reset ? null : draft); dialog.close(); }
      catch (error) { status.textContent = error.message; }
      finally { busy = false; dialog.querySelectorAll('button,input').forEach(b => b.disabled = false); }
    };
    action('SPEICHERN', () => commit(false)); const close = action('ABBRECHEN', () => { if (!busy) dialog.close(); }); action('AUTOMATISCHE STRECKE WIEDERHERSTELLEN', () => commit(true));
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    dialog.addEventListener('close', () => { version++; editor?.remove(); dialog.remove(); });
    document.body.append(dialog); dialog.showModal(); await choose();
}

function fitWorldMapLabels(map, L, stops, markers, titles) {
  if (!stops.length) return;
  // Minimal gap between the outermost trips and the screen edge (same value in installWorldMapViewport).
  const host = map.getContainer(), size = map.getSize(), margin = 12;
  // Controls drawn above the world map. Stations and trip names must not end up underneath.
  const obstacles = frame => [...host.querySelectorAll('.leaflet-control-zoom,.leaflet-control-scale,.leaflet-control-attribution,.world-search-status,.world-year-filter'), ...document.querySelectorAll('.journey-nav,.native-journey-nav')]
    .map(element => element.getBoundingClientRect())
    .filter(rect => rect.width > 0 && rect.height > 0 && rect.right > frame.left && rect.left < frame.right && rect.bottom > frame.top && rect.top < frame.bottom);
  const bounds = L.latLngBounds(stops.map(stop => [Number(stop.latitude), Number(stop.longitude)]));
  const padding = { left: margin, right: margin, top: margin, bottom: margin };
  for (const marker of titles) {
    const element = marker.getElement(), text = element?.querySelector('span');
    if (!element || !text) continue;
    text.style.maxWidth = Math.max(60, size.x - 2 * margin - 28) + 'px';
    text.style.whiteSpace = 'normal'; text.style.overflowWrap = 'anywhere';
    element.style.width = 'max-content';
    // Point labels inward so eastern trips do not extend beyond the map.
    element.style.marginLeft = map.latLngToContainerPoint(marker.getLatLng()).x > size.x / 2
      ? -(text.getBoundingClientRect().width + 12) + 'px' : '12px';
  }
  const rectangles = () => {
    const result = titles.map(marker => marker.getElement()?.querySelector('span')?.getBoundingClientRect()).filter(Boolean);
    // Adaptive markers grow after zooming (dot 12 px, number >= 40 px, photo 54 px; thresholds as in
    // stationMarkerModes). Fit with the size the marker will have at this zoom, not the current one.
    const zoom = map.getZoom();
    for (const marker of markers) {
      const element = marker.marker.getElement(); if (!element) continue;
      const actual = element.getBoundingClientRect();
      const expected = zoom >= 10.5 ? 54 : zoom >= 6.5 ? Math.max(40, String(marker.number ?? '').length * 8 + 12) : 12;
      const half = Math.max(actual.width, actual.height, expected) / 2, cx = (actual.left + actual.right) / 2, cy = (actual.top + actual.bottom) / 2;
      const rect = { left: cx - half, right: cx + half, top: cy - half, bottom: cy + half }; result.push(rect);
      const style = getComputedStyle(element, '::after');
      if (!style.content || style.content === 'none' || style.content === 'normal') continue;
      const number = value => parseFloat(value) || 0;
      const width = number(style.width) + number(style.paddingLeft) + number(style.paddingRight);
      const height = number(style.height) + number(style.paddingTop) + number(style.paddingBottom);
      const left = rect.left + number(style.left) - width / 2;
      const top = style.top !== 'auto' ? rect.top + number(style.top) : rect.bottom - number(style.bottom) - height;
      result.push({ left, right: left + width, top, bottom: top + height });
    }
    return result;
  };
  for (let pass = 0; pass < 8; pass++) {
    const frame = host.getBoundingClientRect(), rects = rectangles();
    const overflow = {
      left: Math.max(0, ...rects.map(rect => frame.left + margin + 2 - rect.left)),
      right: Math.max(0, ...rects.map(rect => rect.right - frame.right + margin + 2)),
      top: Math.max(0, ...rects.map(rect => frame.top + margin + 2 - rect.top)),
      bottom: Math.max(0, ...rects.map(rect => rect.bottom - frame.bottom + margin + 2))
    };
    // Push only the side where a station or name actually lies below a control.
    const middle = (frame.top + frame.bottom) / 2, gap = 4;
    for (const obstacle of obstacles(frame)) for (const rect of rects) {
      if (rect.right <= obstacle.left - gap || rect.left >= obstacle.right + gap || rect.bottom <= obstacle.top - gap || rect.top >= obstacle.bottom + gap) continue;
      if ((obstacle.top + obstacle.bottom) / 2 < middle) overflow.top = Math.max(overflow.top, obstacle.bottom + gap - rect.top);
      else overflow.bottom = Math.max(overflow.bottom, rect.bottom - obstacle.top + gap);
    }
    if (Object.values(overflow).every(value => value < 1)) break;
    // Never let accumulated label padding consume the complete viewport.
    // A negative fit area produces an invalid zoom on narrow/short screens.
    for (const side of Object.keys(padding)) padding[side] = Math.min((side==='left'||side==='right'?size.x:size.y)*.4,padding[side]+Math.ceil(overflow[side]));
    map.fitBounds(bounds, { paddingTopLeft: [padding.left, padding.top], paddingBottomRight: [padding.right, padding.bottom], maxZoom: stops.length===1?10:19, animate: false });
  }
}
function installWorldMapViewport(map,L,stops,markers,titles) {
  const host=map.getContainer();let frame=0,lastWidth=0,lastHeight=0;
  const fit=()=>{
    frame=0;if(!host.isConnected||host.clientWidth<64||host.clientHeight<64)return;
    lastWidth=host.clientWidth;lastHeight=host.clientHeight;
    map.stop();
    map.invalidateSize({pan:false});
    if(stops.length){map.fitBounds(stops.map(stop=>[Number(stop.latitude),Number(stop.longitude)]),{padding:[12,12],maxZoom:stops.length===1?10:19,animate:false});fitWorldMapLabels(map,L,stops,markers,titles);}
  };
  const resized=()=>{if(host.clientWidth===lastWidth&&host.clientHeight===lastHeight)return;cancelAnimationFrame(frame);frame=requestAnimationFrame(fit);};
  const observer=new ResizeObserver(resized);observer.observe(host);
  window.visualViewport?.addEventListener('resize',resized);
  map.once('unload',()=>{observer.disconnect();cancelAnimationFrame(frame);window.visualViewport?.removeEventListener('resize',resized);});
  fit();return fit;
}
function limitWorldMapZoom(map, L) {
  // noWrap on the tile layer already prevents repeated worlds. A hard
  // maxBounds constraint pans the camera during fitting when a tall viewport
  // cannot fit inside one projected world, racing the initial/rotation fit.
  const update = () => {
    const viewport = map.getSize(), globe = map.options.crs.getProjectedBounds(0).getSize();
    if (!viewport.x || !viewport.y) return;
    const snap = map.options.zoomSnap || 0.01;
    const zoom = Math.log2(Math.min(viewport.x / globe.x, viewport.y / globe.y));
    const minimum = Math.floor(zoom / snap) * snap;
    // setMinZoom() would zoom up with an animation; its transition end resets the view to that
    // zoom and undoes the trip fit made meanwhile (large screens: world view instead of trips).
    if (map.getZoom() < minimum) map.setZoom(minimum, { animate: false });
    map.setMinZoom(minimum);
  };
  map.on('resize', update);
  update();
}
async function renderWorldMap() {
  const node = document.querySelector('#worldMap');
  if (!node || node._leaflet_id) return;
  node.classList.add('is-loading');
  const renderVersion = ++worldMapRenderVersion;
  try {
    // Die Reisedaten werden vor dem ersten Zeichnen geladen. So erscheint weder
    // eine leere Weltansicht noch ein nachträglicher Zoom auf bereits sichtbare
    // Reisen.
    const [data,catalog] = await Promise.all([
      loadWorldMapStops(),
      window.ReiseNativeUI ? apiFetch('./api/api.php?action=trips').then(async response=>{if(!response.ok)throw new Error('Reisekatalog konnte nicht geladen werden.');return response.json();}) : Promise.resolve(null)
    ]);
    if(catalog)remoteTrips=catalog.trips||[];
    if (window.ReiseAppleMaps && await window.ReiseAppleMaps.available()) {
      if (!node.isConnected || renderVersion !== worldMapRenderVersion) return;
      await renderAppleWorldMap(node, data.stops || []);
      node.classList.remove('is-loading'); node.closest('.world-page')?.querySelector('.world-map-loading')?.remove();
      return;
    }
    const L = await loadLeaflet();
    if (!node.isConnected || renderVersion !== worldMapRenderVersion || node._leaflet_id) return;
    const allStops = (data.stops || []).filter(stop => stop.latitude !== null && stop.longitude !== null && Number.isFinite(Number(stop.latitude)) && Number.isFinite(Number(stop.longitude)));
    const years = TripSearch.years(remoteTrips).length?TripSearch.years(remoteTrips):[...new Set(allStops.map(stop=>String(stop.trip_starts_on||stop.starts_at||'').slice(0,4)).filter(y=>/^\d{4}$/.test(y)))].sort().reverse();
    const selectedYear = years.includes(node.dataset.year||TripSearch.get('map').year) ? (node.dataset.year||TripSearch.get('map').year) : 'all';
    // The administration page can hand over a trip ID when it opens the map.
    // That focus must take precedence over the normal archive/year overview.
    const focusTripId = sessionStorage.getItem('atlas-focus-trip') || new URLSearchParams(location.search).get('show-map');
    const stops = focusTripId
      ? allStops.filter(stop => String(stop.trip_id) === String(focusTripId))
      : allStops.filter(stop=>worldTripMatches(stop,{...TripSearch.get('map'),year:selectedYear}));
    // Fit the currently visible trips to the map; fractional zoom avoids a
    // full zoom-level gap between the outermost stations and the viewport.
    // A neutral initial view avoids displaying stale map coordinates before the
    // available stops are known. The view is fitted to the stops below.
    // One resize owner: the viewport observer invalidates size and fits trips
    // together, without a later competing Leaflet resize pan.
    // Website and app: zoom buttons are in the bottom menu (worldMapZoom).
    const menuZoom = !!(window.ReiseWebUI || window.ReiseNativeUI);
    const map = L.map(node, { zoomControl: !menuZoom, attributionControl: true, worldCopyJump: false, zoomSnap: 0.01, trackResize:false }).setView([18, 8], 2);
    window.worldMapZoom = delta => { if (!node.isConnected) return null; const zoom = Math.max(map.getMinZoom(), Math.min(map.getMaxZoom(), Math.round(map.getZoom()) + delta)); map.setZoom(zoom); return zoom; };
    limitWorldMapZoom(map, L);
    addCoastalLabels(map, L);
    L.control.scale({ position: 'bottomright', metric: true, imperial: false, maxWidth: 120 }).addTo(map);
    const tiles = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, maxNativeZoom: 16, noWrap: true, bounds: [[-85.0511, -180], [85.0511, 180]], attribution: 'Tiles &copy; Esri' });
    const revealMap = () => { if (node.isConnected) { node.classList.remove('is-loading'); node.closest('.world-page')?.querySelector('.world-map-loading')?.remove(); } };
    tiles.once('load', revealMap);
    tiles.addTo(map);
    // Bei einem einzelnen fehlenden Kachelbild bleibt die Karte trotzdem
    // benutzbar, ohne die Marker vor dem Kartenhintergrund zu zeigen.
    setTimeout(revealMap, 4000);
    const filter = document.createElement('label');
    filter.className = 'world-year-filter';
    filter.innerHTML = '<span>REISEJAHR</span><select aria-label="Reisejahr auswählen"><option value="all">ALLE JAHRE</option></select>';
    const select = filter.querySelector('select');
    years.forEach(year => select.add(new Option(year, year)));
    select.value = selectedYear;
    select.onchange = () => { node.dataset.year = select.value;TripSearch.set('map',{...TripSearch.get('map'),year:select.value}); map.remove(); node.replaceChildren(); renderWorldMap(); };
    L.DomEvent.disableClickPropagation(filter); L.DomEvent.disableScrollPropagation(filter);
    node.append(filter);updateWorldSearchStatus(node,stops,focusTripId);
    const colors = worldRouteColors;
    const groups = new Map(); stops.forEach(stop => { const id = String(stop.trip_id); if (!groups.has(id)) groups.set(id, []); groups.get(id).push(stop); });
    // Trip names are placed at the station farthest from home (config home_location);
    // without a configured home, at the station farthest from the trip's first station.
    const homeSetting = typeof access !== 'undefined' ? access?.homeLocation : null;
    const home = homeSetting && Number.isFinite(homeSetting.latitude) && Number.isFinite(homeSetting.longitude) ? L.latLng(homeSetting.latitude, homeSetting.longitude) : null;
    const stationRoles = new Map();
    const addStationRole = (stop, role) => {
      const id = String(stop.id);
      stationRoles.set(id, `${stationRoles.get(id) || ''} ${role}`.trim());
    };
    let colorIndex = 0;
    const titleMarkers = [];
    groups.forEach((group, tripId) => {
      // `position` is the curated itinerary order. Timestamps may be identical
      // or missing, which would otherwise make a route jump back and forth.
      group.sort((a, b) => Number(a.map_position) - Number(b.map_position) || String(a.starts_at || '').localeCompare(String(b.starts_at || '')));
      addStationRole(group[0], 'station-first');
      addStationRole(group[group.length - 1], 'station-last');
      group.forEach((stop, index) => { stop.mapNumber = stop.stationNumber || index + 1; });
      const color = tripRouteColor(tripId); colorIndex++;
      const routePoints = group.map(stop => [Number(stop.latitude), Number(stop.longitude)]);
      const origin = home || L.latLng(Number(group[0].latitude), Number(group[0].longitude));
      const farthest = group.reduce((best, stop) =>
        origin.distanceTo([Number(stop.latitude), Number(stop.longitude)]) > origin.distanceTo([Number(best.latitude), Number(best.longitude)]) ? stop : best
      );
      
      const route = addRouteDirectionArrows(map, routePoints, color, tripId);
      
      const label = document.createElement('span');
      label.style.setProperty('--route-color', color);
      label.textContent = farthest.trip_title || 'Reise';
      const titleMarker = L.marker([Number(farthest.latitude), Number(farthest.longitude)], { interactive: false, icon: L.divIcon({ className: 'world-trip-label', html: label, iconSize: null, iconAnchor: [-12, 8] }) });
      map.addLayer(titleMarker);
      titleMarkers.push(titleMarker);
    });
    const markerRole = stop => stationRoles.get(String(stop.id)) || '';
    const dotIcon = stop => L.divIcon({ className: `world-stop-marker ${markerRole(stop)}`, iconSize: [18,18], iconAnchor: [9,9] });
    const markers = stops.map(stop => {
      const isMainLocation = String(stop.id || '').startsWith('main-location-') || String(stop.name || '').startsWith('Hauptort');
      const marker = L.marker([Number(stop.latitude), Number(stop.longitude)], { icon: dotIcon(stop), zIndexOffset: markerRole(stop).includes('station-first') ? 10000 : 0 });
      map.addLayer(marker);
      const tooltip = document.createElement('span');
      tooltip.textContent = `${stop.trip_title} · ${isMainLocation ? 'HAUPTORT: ' : ''}${stop.name || 'Station'}`;
      marker.bindTooltip(tooltip, { direction: 'top', offset: [0,-10] });
      return { marker, stop, number: stop.mapNumber, role: markerRole(stop), tripId: stop.trip_id, dot: stationDotColor(worldTripColor(stops, stop.trip_id)), open: () => openServerTrip(stop.trip_id) };
    });
    // This applies only to the world overview. Individual trip maps have their
    // own fitBounds handling remains unchanged. Include labels in the
    // shared 30-pixel margin on the world overview.
    installAdaptiveStationMarkers(map, L, markers);
    {
      installWorldMapViewport(map,L,stops,markers,titleMarkers);
      const center = map.getCenter(), zoom = map.getZoom();
      if (document.fonts?.status === 'loading') document.fonts.ready.then(() => {
        if (node.isConnected && map.getZoom() === zoom && map.getCenter().equals(center)) fitWorldMapLabels(map, L, stops, markers, titleMarkers);
      });
    }
    if (focusTripId) {
      sessionStorage.removeItem('atlas-focus-trip');
      // Do not reapply a one-time administration focus after a later refresh.
      if (new URLSearchParams(location.search).has('show-map')) {
        history.replaceState(null, '', `${location.pathname}${location.hash}`);
      }
    }


  } catch { node.classList.remove('is-loading'); node.closest('.world-page')?.querySelector('.world-map-loading')?.remove(); node.textContent = 'Die Weltkarte konnte nicht geladen werden.'; }
}
async function serverPlace(tripId, stopId) {
  let tripRecord = remoteTrips.find(record => String(record.id) === String(tripId));
  if (!tripRecord) {
    const response = await apiFetch('./api/api.php?action=trips', { credentials: 'same-origin' });
    const data = await response.json(); remoteTrips = data.trips || [];
    tripRecord = remoteTrips.find(record => String(record.id) === String(tripId));
  }
  if (!tripRecord) { go('#home'); return; }
  // Retain the same map and its viewport while switching stations of one day.
  unifiedDayMap?.locationCleanup?.();
  unifiedDayMap?.node.remove();
  app.replaceChildren();
  const page = document.createElement('section'); page.className = 'station-page';
  page.innerHTML = '<button class="back">← ZURÜCK ZUR WELTKARTE</button><header class="station-page-head"><h1></h1><p class="station-day-intro"></p></header><div class="station-layout"><article><section class="station-note-section"><p class="eyebrow">NOTIZ ZUR STATION</p><p class="station-note"></p></section><p class="eyebrow">AUFNAHMEN DIESER STATION</p><div class="station-photo-grid"></div></article><aside class="station-day"><h2></h2><div></div></aside></div>';
  page.classList.add('unified-day-page');
  const dayHeader = document.createElement('header'); dayHeader.className = 'unified-day-header';
  dayHeader.innerHTML = '<h2></h2><section class="unified-day-summary"><p class="eyebrow">TAGESZUSAMMENFASSUNG</p></section>';
  dayHeader.querySelector('.unified-day-summary').append(page.querySelector('.station-day-intro'));
  const overview = document.createElement('section'); overview.className = 'unified-day-overview';
  overview.append(page.querySelector('.station-day'));
  const summarySection = dayHeader.querySelector('.unified-day-summary');
  const summaryCopy = document.createElement('div'); summaryCopy.className = 'unified-day-summary-copy';
  summaryCopy.append(...summarySection.childNodes);
  const mapHost = document.createElement('div'); mapHost.className = 'day-map-host';
  summarySection.append(summaryCopy, mapHost);
  page.querySelector('.station-page-head').before(dayHeader, overview);
  app.append(page); page.querySelector('.back').onclick = () => go(`#trip/${tripRecord.id}`);
  await mountTripDayAdministration(page,tripRecord);if(!page.isConnected)return;
  try {
    const [stopsResponse, mediaResponse] = await Promise.all([
      apiFetch(`./api/api.php?action=stops&trip=${encodeURIComponent(tripRecord.id)}`, { credentials: 'same-origin' }),
      apiFetch(`./api/api.php?action=media&trip=${encodeURIComponent(tripRecord.id)}&stop=${encodeURIComponent(stopId)}`, { credentials: 'same-origin' })
    ]);
    const stopsData = await stopsResponse.json(), mediaData = await mediaResponse.json();
    numberTripStations(stopsData.stops || []);
    if (!page.isConnected) return;
    const stop = (stopsData.stops || []).find(entry => String(entry.id) === String(stopId));
    if (!stopsResponse.ok || !mediaResponse.ok || !stop) throw new Error();
    stationMediaCache.begin(tripRecord.id, stop.id, mediaData.media || [], page);
    const day = stopDay(stop.starts_at);
    const stopIndex = (stopsData.stops || []).findIndex(entry => String(entry.id) === String(stopId));
    page.nextStationIds = (stopsData.stops || []).slice(stopIndex + 1).filter(entry => stopDay(entry.starts_at) === day).map(entry => String(entry.id));
    const days = [...new Set((stopsData.stops || []).map(entry => stopDay(entry.starts_at)).filter(Boolean))].sort();
    renderJourneyDayStrip(page,tripRecord.id,days,day);
    const dayIndex = days.indexOf(day);
    page.nextDay = dayIndex >= 0 ? days[dayIndex + 1] || '' : '';
    page.slideshowStops = stopsData.stops || [];
    page.slideshowStop = stop;
    headerTitle.textContent = tripRecord.title || 'Reise';
    page.querySelector('.station-page-head h1').textContent = stationLabel(stop);
    const currentMapLink = stationMapLink(tripRecord.id, stop);
    if (currentMapLink) page.querySelector('.station-page-head h1').after(currentMapLink);
    const noteSection = page.querySelector('.station-note-section'), stationNote = page.querySelector('.station-note');
    stationNote.textContent = stop.summary || '';
    if (!stop.summary && !canEditTrip()) noteSection.remove();
    if (canEditTrip()) {
      if (!String(stop.name || '').startsWith('Hauptort')) {
        const nameEditor = document.createElement('div'); nameEditor.className = 'station-name-editor';
        const nameInput = document.createElement('input'); nameInput.type = 'text'; nameInput.maxLength = 255; nameInput.value = stop.name || ''; nameInput.setAttribute('aria-label', 'Stationsname');
        const saveName = document.createElement('button'); saveName.type = 'button'; saveName.className = 'edit-link'; saveName.textContent = 'NAMEN SPEICHERN';
        const saveStationName = async name => {
          if (saveName.disabled || !name) { nameInput.focus(); return false; }
          saveName.disabled = true;
          try {
            const response = await apiFetch('./api/api.php?action=stop-name', { method: 'PATCH', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: stop.id, trip: tripRecord.id, name }) });
            const data = await response.json().catch(() => ({})); if (!response.ok) throw new Error(data.error || 'Name konnte nicht gespeichert werden.');
            stop.name = data.name; nameInput.value = data.name;
            page.querySelector('.station-page-head h1').textContent = stationLabel(stop);
            const selectedName = page.querySelector('.station-day button.selected b'); if (selectedName) selectedName.textContent = stationLabel(stop);
            return true;
          } catch (error) { alert(error.message || 'Name konnte nicht gespeichert werden.'); return false; }
          finally { saveName.disabled = false; }
        };
        saveName.onclick = () => saveStationName(nameInput.value.trim());
        const loadSuggestions = document.createElement('button'); loadSuggestions.type = 'button'; loadSuggestions.className = 'edit-link'; loadSuggestions.textContent = 'NAMENSVORSCHLÄGE LADEN';
        const suggestions = document.createElement('div'); suggestions.className = 'station-name-suggestions';
        const showSuggestions = data => {
          const enteredIsland = suggestions.querySelector('.station-island-manual input')?.value.trim() || '';
          suggestions.replaceChildren();
          const options = Array.isArray(data.suggestions) ? data.suggestions : [];
          const note = document.createElement('p'); note.textContent = data.note || 'Kein passender Ortsvorschlag gefunden.';
          if (!options.length) suggestions.append(note);
          options.forEach(item => {
            const button = document.createElement('button'); button.type = 'button'; button.className = 'station-name-suggestion';
            button.textContent = `${item.label}: ${item.name}`; button.onclick = () => { nameInput.value = item.name; nameInput.focus(); }; suggestions.append(button);
          });
          if (options.length) suggestions.append(note);
          const manual = document.createElement('div'); manual.className = 'station-island-manual';
          const label = document.createElement('label'); label.textContent = 'INSELNAME, FALLS BEKANNT';
          const islandInput = document.createElement('input'); islandInput.type = 'text'; islandInput.maxLength = 150; islandInput.placeholder = 'z. B. Vis'; islandInput.value = enteredIsland || data.island || ''; label.append(islandInput);
          const useIsland = async prefix => {
            let island = islandInput.value.trim();
            if (!island) {
              const entered = prompt('Welche Insel ist in der Nähe?', '');
              if (entered === null) return;
              island = entered.trim();
            }
            island = island.replace(/^(?:Insel|Otok)\s+/i, '').trim();
            if (!island) { hint.textContent = 'Bitte zuerst einen Inselnamen eingeben.'; islandInput.focus(); return; }
            islandInput.value = island;
            const proposedName = `${prefix} nahe der Insel ${island}`;
            nameInput.value = proposedName;
            sea.disabled = true; sailing.disabled = true;
            try { if (await saveStationName(proposedName)) hint.textContent = `Gespeichert: ${proposedName}`; }
            finally { sea.disabled = false; sailing.disabled = false; }
          };
          const sea = document.createElement('button'); sea.type = 'button'; sea.className = 'station-name-suggestion'; sea.textContent = 'AUF SEE SPEICHERN'; sea.onclick = () => useIsland('Auf See');
          const sailing = document.createElement('button'); sailing.type = 'button'; sailing.className = 'station-name-suggestion'; sailing.textContent = 'SEGELN SPEICHERN'; sailing.onclick = () => useIsland('Segeln');
          const hint = document.createElement('small'); hint.textContent = 'Nur wählen, wenn Ort und Aktivität zur Reise passen. Diese Schaltflächen speichern den Namen direkt.';
          manual.append(label, sea, sailing, hint); suggestions.append(manual);
          if (options.length) {
            const attribution = document.createElement('a'); attribution.href = data.attribution_url === 'https://www.geonames.org/' ? data.attribution_url : 'https://www.openstreetmap.org/copyright'; attribution.target = '_blank'; attribution.rel = 'noopener'; attribution.textContent = data.attribution_url === 'https://www.geonames.org/' ? 'Ortsdaten: GeoNames (CC BY 4.0)' : 'Ortsdaten: OpenStreetMap'; suggestions.append(attribution);
          }
        };
        loadSuggestions.onclick = async () => {
          loadSuggestions.disabled = true; showSuggestions({ suggestions: [], island: '', note: 'Ortsvorschläge werden geladen …' });
          try {
            const response = await apiFetch(`./api/api.php?action=stop-name-suggestions&trip=${encodeURIComponent(tripRecord.id)}&stop=${encodeURIComponent(stop.id)}`, { credentials: 'same-origin' });
            const data = await response.json().catch(() => ({})); if (!response.ok) throw new Error(data.error || 'Vorschläge konnten nicht geladen werden.');
            if (!page.isConnected) return;
            showSuggestions(data);
          } catch (error) { if (page.isConnected) showSuggestions({ suggestions: [], island: '', note: error.message || 'Vorschläge konnten nicht geladen werden.' }); }
          finally { loadSuggestions.disabled = false; }
        };
        nameEditor.append(nameInput, saveName, loadSuggestions, suggestions);
        page.querySelector('.station-page-head').append(nameEditor);
      }
      const editLocation = document.createElement('button');
      editLocation.type = 'button'; editLocation.className = 'edit-link';
      editLocation.textContent = stop.latitude === null || stop.longitude === null ? 'POSITION AUF KARTE SETZEN' : 'POSITION KORRIGIEREN';
      editLocation.onclick = () => openStationLocationPicker(tripRecord.id, stop, stopsData.stops || [], () => serverPlace(tripRecord.id, stop.id));
      page.querySelector('.station-page-head').append(editLocation);
      stationNote.hidden = true;
      const textarea = document.createElement('textarea'); textarea.value = stop.summary || ''; textarea.rows = 5;
      textarea.placeholder = 'Eigene Notiz zu dieser Station …'; textarea.className = 'station-note-editor';
      const saveNote = document.createElement('button'); saveNote.className = 'primary'; saveNote.textContent = 'NOTIZ SPEICHERN';
      saveNote.onclick = async () => {
        const summary = textarea.value.trim(); if (!summary) { textarea.focus(); return; }
        saveNote.disabled = true;
        try {
          const response = await apiFetch('./api/api.php?action=stop', { method: 'PATCH', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: stop.id, trip: tripRecord.id, summary }) });
          if (!response.ok) throw new Error(); stationNote.textContent = summary;
        } catch { alert('Die Notiz konnte nicht gespeichert werden.'); }
        finally { saveNote.disabled = false; }
      };
      noteSection.append(textarea, saveNote);

    }
    const back = page.querySelector('.back');
    back.textContent = '← ZURÜCK ZUR REISEKARTE';
    back.onclick = () => go(`#trip/${tripRecord.id}`);
    const navigationUI=window.ReiseNativeUI||window.ReiseWebUI;
    if (navigationUI) { back.remove();navigationUI.bindPageSwipe?.(page,{previous:()=>{const target=stopsData.stops[stopIndex-1];if(target)go(`#trip/${tripRecord.id}/station/${target.id}`);},next:()=>{const target=stopsData.stops[stopIndex+1];if(target)go(`#trip/${tripRecord.id}/station/${target.id}`);}}); }
    dayHeader.querySelector('h2').textContent = day ? new Intl.DateTimeFormat(globalThis.ReiseI18n?.language==='en'?'en-GB':'de-DE',{weekday:'long',day:'2-digit',month:'long',year:'numeric'}).format(new Date(`${day}T12:00:00`)) : 'Ohne Aufnahmedatum';
    page.querySelector('.station-day h2').textContent = 'STATIONEN DIESES TAGES';
    const summaryTarget = page.querySelector('.station-day-intro'); summaryTarget.textContent = 'Tageszusammenfassung wird geladen …';
    let summary = '', summaryData = {};
    try {
      const summaryResponse = await apiFetch(`./api/api.php?action=day-summary&trip=${encodeURIComponent(tripRecord.id)}&day=${encodeURIComponent(day)}`, { credentials: 'same-origin' });
      summaryData = await summaryResponse.json(); if (summaryResponse.ok) summary = summaryData.summary || '';
    } catch {}
    summaryTarget.textContent = summary || 'Für diesen Tag liegt noch keine Tageszusammenfassung vor.';
    renderDaySteps(page,summaryData,summaryCopy);
    if (!page.isConnected) return;
    if (canEditTrip(tripRecord.id) && day) {
      const details = document.createElement('details'); details.className = 'day-summary-edit';
      details.append(Object.assign(document.createElement('summary'), { textContent: 'Tageszusammenfassung bearbeiten' }));
      const input = document.createElement('textarea'); input.value = summary; input.rows = 5; input.maxLength = 10000; input.setAttribute('aria-label', 'Tageszusammenfassung');
      const save = document.createElement('button'); save.className = 'primary'; save.textContent = 'TAGESZUSAMMENFASSUNG SPEICHERN';
      save.onclick = async () => {
        if (!input.value.trim()) { input.focus(); return; } save.disabled = true;
        try { const response = await apiFetch('./api/api.php?action=day-summary', { method: 'PATCH', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ trip: tripRecord.id, day, summary: input.value.trim() }) }); if (!response.ok) throw new Error(); summaryTarget.textContent = input.value.trim(); details.open = false; }
        catch { alert('Die Tageszusammenfassung konnte nicht gespeichert werden.'); } finally { save.disabled = false; }
      };
      details.append(input, save); summaryCopy.append(details);
    }
    renderDayWeather(page, tripRecord.id, day);
    const dayStops = (stopsData.stops || []).filter(entry => stopDay(entry.starts_at) === day);
    const distance = journeyDayDistance(stopsData.stops || [], day);
    if(distance)summaryCopy.append(Object.assign(document.createElement('p'),{className:'journey-day-metrics',textContent:distance}));
    mountDayStationMap(page, tripRecord.id, day, dayStops, stopId);
    (stopsData.stops || []).filter(entry => stopDay(entry.starts_at) === day).forEach(entry => {
      const button = document.createElement('button');
      const preview = entry.preview_id ? `./api/api.php?action=preview&trip=${encodeURIComponent(tripRecord.id)}&media=${encodeURIComponent(entry.preview_id)}&size=small` : '';
      const previewSlot = document.createElement('span');
      previewSlot.className = 'station-preview'; previewSlot.setAttribute('aria-hidden', 'true'); previewSlot.textContent = '◇';
      if (preview) {
        const image = document.createElement('img'); image.loading = 'lazy'; image.alt = '';
        image.onerror = () => { previewSlot.textContent = '◇'; };
        image.src = preview; previewSlot.replaceChildren(image);
      }
      button.append(previewSlot);
      const name = document.createElement('b'); name.textContent = stationLabel(entry); name.title = name.textContent;
      name.style.fontSize = name.textContent.length > 40 ? '12px' : name.textContent.length > 24 ? '13px' : '14px'; button.append(name);
      if (String(entry.id) === String(stopId)) button.className = 'selected';
      button.type = 'button'; button.setAttribute('aria-current', String(entry.id) === String(stopId) ? 'true' : 'false');
      const time = document.createElement('small');
      const when = entry.starts_at ? new Intl.DateTimeFormat(globalThis.ReiseI18n?.language==='en'?'en-GB':'de-AT',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}).format(new Date(entry.starts_at.replace(' ','T'))) : '';
      time.textContent = when; button.append(time);
      bindStationEntrySlideshow(button, tripRecord.id, entry.id);
      const row = document.createElement('div'); row.className = 'station-day-entry'; row.append(button);
      const mapLink = stationMapLink(tripRecord.id, entry);
      if (mapLink) row.append(mapLink);
      page.querySelector('.station-day > div').append(row);
    });
    if (canEditTrip() && globalThis.AtlasStationActions) globalThis.AtlasStationActions.mount({ page, tripId: tripRecord.id, stop, stops: stopsData.stops || [], media: mediaData.media || [], go, onChanged: () => { unifiedDayMap?.locationCleanup?.(); unifiedDayMap?.map?.remove(); unifiedDayMap = null; } });
    const media = mediaData.media || [], photoGrid = page.querySelector('.station-photo-grid');
    const addPhoto = (item, target) => {
      const link = document.createElement('a'); link.href = item.web_url || '#'; link.dataset.mediaLightbox = 'true'; link.dataset.mediaId = item.id; link.dataset.tripId = tripRecord.id; link.dataset.stopId = stop.id; link.dataset.mediaType = item.media_type; link.dataset.mediaName = item.name || ''; link.dataset.mediaUrl = item.web_url || '';
      const isVideo = item.media_type === 'video';
      const image = document.createElement('img'); image.loading = 'lazy'; image.alt = isVideo ? '' : item.name || 'Reiseaufnahme'; image.src = `./api/api.php?action=preview&trip=${encodeURIComponent(tripRecord.id)}&media=${encodeURIComponent(item.id)}&size=large`;
      link.append(image);
      if (isVideo) {
        link.classList.add('station-video-tile');
        link.setAttribute('aria-label', `Video abspielen: ${item.name || 'Reisevideo'}`);
        const play = document.createElement('span'); play.className = 'station-video-play'; play.textContent = '▶'; play.setAttribute('aria-hidden', 'true');
        const label = document.createElement('span'); label.className = 'station-video-label'; label.textContent = 'VIDEO'; label.setAttribute('aria-hidden', 'true');
        link.append(play, label);
      }
      const card = document.createElement('div'); card.className = 'station-photo-card'; card.append(link);
      const time = document.createElement('small'); time.className = 'station-media-time';
      time.textContent = item.taken_at ? new Intl.DateTimeFormat(globalThis.ReiseI18n?.language==='en'?'en-GB':'de-AT', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(new Date(item.taken_at.replace(' ', 'T'))) : 'Ohne Aufnahmezeit';
      if (item.capture_source === 'manual') time.textContent += ' · manuell';
      card.append(time);
      if (canEditTrip()) {
        const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'edit-link media-time-edit'; edit.textContent = 'AUFNAHMEZEIT ÄNDERN';
        edit.onclick = () => editMediaTime(tripRecord.id, stop.id, item); card.append(edit);
        const pick = document.createElement('label'); pick.className = 'media-remove-pick';
        const box = document.createElement('input'); box.type = 'checkbox'; box.value = String(item.id); box.onchange = () => updateMediaRemoveBar(page, tripRecord.id);
        pick.append(box, document.createTextNode(' Auswählen')); card.append(pick);
      }
      target.append(card);
    };
    if (String(stop.name || '').startsWith('Hauptort')) {
      photoGrid.classList.add('station-photo-days');
      const days = new Map(); media.forEach(item => { const day = stopDay(item.taken_at) || 'Ohne Datum'; if (!days.has(day)) days.set(day, []); days.get(day).push(item); });
      days.forEach((items, day) => {
        const section = document.createElement('section'); section.className = 'station-photo-day';
        const heading = document.createElement('h3'); heading.textContent = day === 'Ohne Datum' ? day : new Intl.DateTimeFormat(globalThis.ReiseI18n?.language==='en'?'en-GB':'de-DE',{weekday:'long',day:'2-digit',month:'long',year:'numeric'}).format(new Date(`${day}T12:00:00`));
        const grid = document.createElement('div'); grid.className = 'station-photo-grid'; items.forEach(item => addPhoto(item, grid)); section.append(heading, grid); photoGrid.append(section);
      });
    } else media.forEach(item => addPhoto(item, photoGrid));
    if (canEditTrip()) updateMediaRemoveBar(page, tripRecord.id);
    if (pendingStationMedia && String(pendingStationMedia.tripId) === String(tripRecord.id) && String(pendingStationMedia.stopId) === String(stop.id) && page.isConnected && location.hash === `#trip/${tripRecord.id}/station/${stop.id}`) {
      const mediaId = String(pendingStationMedia.mediaId), first = pendingStationMedia.first;
      pendingStationMedia = null;
      const firstLink = first ? page.querySelector('[data-media-lightbox]') : [...page.querySelectorAll('[data-media-lightbox]')].find(entry => entry.dataset.mediaId === mediaId);
      firstLink?.click();
      if (first && !firstLink) alert('Für diese Station sind keine Fotos oder Videos vorhanden.');
    }
  } catch {
    if (pendingStationMedia && String(pendingStationMedia.tripId) === String(tripRecord.id) && String(pendingStationMedia.stopId) === String(stopId)) pendingStationMedia = null;
    page.querySelector('.station-photo-grid').textContent = 'Die Stationsdaten konnten nicht geladen werden.';
  }
}
// Edit mode: remove selected photos/videos from this trip. Only the reference goes; originals stay.
function updateMediaRemoveBar(page, tripId) {
  let bar = page.querySelector('.media-remove-bar');
  const chosen = [...page.querySelectorAll('.media-remove-pick input:checked')].map(box => box.value);
  if (!bar) {
    bar = document.createElement('div'); bar.className = 'media-remove-bar'; bar.setAttribute('role', 'region'); bar.setAttribute('aria-label', 'Aufnahmen aus der Reise entfernen');
    const count = document.createElement('span'); count.className = 'media-remove-count';
    const all = document.createElement('button'); all.type = 'button'; all.className = 'edit-link'; all.textContent = 'Alle auswählen';
    all.onclick = () => { const boxes = [...page.querySelectorAll('.media-remove-pick input')], on = boxes.some(b => !b.checked); boxes.forEach(b => { b.checked = on; }); updateMediaRemoveBar(page, tripId); };
    const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'primary media-remove-run'; remove.textContent = 'Aus Reise entfernen';
    remove.onclick = () => removeSelectedMedia(page, tripId, remove);
    const move = document.createElement('button'); move.type = 'button'; move.className = 'media-move-run'; move.textContent = 'In andere Reise verschieben';
    move.onclick = () => moveSelectedMedia(page, tripId, move);
    const hint = document.createElement('p'); hint.className = 'hint'; hint.textContent = 'Die Originale bleiben in OneDrive bzw. im Ordner. Die Fotoautomatik fügt entfernte Aufnahmen nicht wieder hinzu. Bei Reisen aus einem eigenen Ordner das Foto zusätzlich aus dem Ordner verschieben, sonst kommt es beim nächsten Abgleich zurück.';
    bar.append(count, all, move, remove, hint); page.querySelector('.station-photo-grid')?.before(bar);
  }
  bar.querySelector('.media-remove-count').textContent = chosen.length ? chosen.length + ' ausgewählt' : 'Aufnahmen auswählen, um sie zu entfernen oder in eine andere Reise zu verschieben';
  bar.querySelector('.media-remove-run').disabled = !chosen.length;
  bar.querySelector('.media-move-run').disabled = !chosen.length;
}
async function removeSelectedMedia(page, tripId, button) {
  const ids = [...page.querySelectorAll('.media-remove-pick input:checked')].map(box => box.value);
  if (!ids.length) return;
  const ask = window.ReiseConfirm ? window.ReiseConfirm : async message => confirm(message);
  if (!await ask(ids.length + (ids.length === 1 ? ' Aufnahme' : ' Aufnahmen') + ' aus dieser Reise entfernen? Die Originale bleiben erhalten.')) return;
  button.disabled = true;
  try {
    const response = await apiFetch('./api/api.php?action=media-remove', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ trip: tripId, media: ids }) });
    const result = await response.json().catch(() => ({})); if (!response.ok) throw Error(result.error || 'Die Aufnahmen konnten nicht entfernt werden.');
    if (typeof stationMediaCache !== 'undefined') stationMediaCache.invalidate?.();
    const emptied = ids.length >= page.querySelectorAll('.media-remove-pick input').length;
    if (emptied) go('#trip/' + tripId + '/days'); else render();
  } catch (error) { alert(error.message); button.disabled = false; }
}
// Edit mode: move selected photos/videos to another trip. In the target they join a station of the same
// day nearby or get a new station named after the day; originals stay where they are.
async function moveSelectedMedia(page, tripId, button) {
  const ids = [...page.querySelectorAll('.media-remove-pick input:checked')].map(box => box.value);
  if (!ids.length || document.querySelector('.media-move-dialog')) return;
  const native = globalThis.ReiseNativeUI?.moveTargets;
  const targets = (native ? await native(tripId) : remoteTrips.filter(t => String(t.id) !== String(tripId) && mayEditTrip(t.id)))
    .sort((a, b) => String(b.starts_on || '').localeCompare(String(a.starts_on || '')) || String(a.title).localeCompare(String(b.title)));
  const dialog = document.createElement('dialog'); dialog.className = 'media-move-dialog'; dialog.setAttribute('aria-labelledby', 'mediaMoveTitle');
  const heading = document.createElement('h2'); heading.id = 'mediaMoveTitle'; heading.textContent = 'In andere Reise verschieben';
  const intro = document.createElement('p'); intro.textContent = ids.length === 1 ? '1 Aufnahme kommt in die gewählte Reise: zu einer Station desselben Tages in der Nähe, sonst in eine neue Station mit dem Datum. Die Originale bleiben, wo sie sind.' : ids.length + ' Aufnahmen kommen in die gewählte Reise: zu einer Station desselben Tages in der Nähe, sonst in eine neue Station mit dem Datum. Die Originale bleiben, wo sie sind.';
  const label = document.createElement('label'); label.textContent = 'Zielreise';
  const select = document.createElement('select'); select.className = 'media-move-target';
  targets.forEach(t => { const option = document.createElement('option'); option.value = String(t.id); option.textContent = t.title + (t.starts_on ? ' · ' + String(t.starts_on).slice(0, 4) : ''); select.append(option); });
  label.append(select);
  const hint = document.createElement('p'); hint.className = 'hint';
  hint.textContent = native ? 'Zur Auswahl stehen Reisen auf diesem Gerät mit derselben Fotoquelle.' : 'Zur Auswahl stehen Reisen, die du bearbeiten darfst. Reisen aus einem eigenen Ordner gleichen sich mit dem Ordner ab: Dort das Foto zusätzlich in den Ordner der Zielreise verschieben.';
  const actions = document.createElement('div'); actions.className = 'media-move-actions';
  const cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'edit-link'; cancel.textContent = 'Abbrechen'; cancel.onclick = () => dialog.close();
  const run = document.createElement('button'); run.type = 'button'; run.className = 'primary media-move-confirm'; run.textContent = 'Verschieben'; run.disabled = !targets.length;
  if (!targets.length) { select.disabled = true; hint.textContent = 'Es gibt keine andere Reise, in die du diese Aufnahmen verschieben kannst.'; }
  actions.append(cancel, run); dialog.append(heading, intro, label, hint, actions);
  dialog.addEventListener('close', () => dialog.remove());
  document.body.append(dialog); dialog.showModal();
  run.onclick = async () => {
    run.disabled = true; button.disabled = true;
    try {
      const response = await apiFetch('./api/api.php?action=media-move', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ trip: tripId, target: select.value, media: ids }) });
      const result = await response.json().catch(() => ({})); if (!response.ok) throw Error(result.error || 'Die Aufnahmen konnten nicht verschoben werden.');
      if (typeof stationMediaCache !== 'undefined') stationMediaCache.invalidate?.();
      dialog.close();
      const emptied = ids.length >= page.querySelectorAll('.media-remove-pick input').length;
      if (emptied) go('#trip/' + tripId + '/days'); else render();
    } catch (error) { alert(error.message); run.disabled = false; button.disabled = false; }
  };
}
async function renderRoute(serverId) {
  const previous = document.querySelector('.route-map');
  const stopsNode = document.querySelector('#stops');
  if (!previous || !stopsNode) return;
  const panel = previous.parentElement;
  if (panel) panel.style.position = 'relative';
  const mapNode = document.createElement('div');
  mapNode.id = 'liveMap'; mapNode.className = 'route-map';
  mapNode.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;min-height:480px;';
  previous.replaceWith(mapNode);
  try {
    const response = await apiFetch(`./api/api.php?action=stops&trip=${encodeURIComponent(serverId)}`, { credentials: 'same-origin' });
    const data = await response.json();
    if (!response.ok || !mapNode.isConnected) throw new Error();
    const stops = numberTripStations(data.stops || []);
    stopsNode.replaceChildren(...stops.map((stop, index) => {
      const button = document.createElement('button');
      button.className = 'stop';
      button.innerHTML = `<span class="stop-num">${stop.stationNumber}</span><div><div class="stop-name"></div><div class="stop-date"></div></div>`;
      button.querySelector('.stop-name').textContent = stop.name || 'Unbenannte Station';
      button.querySelector('.stop-date').textContent = (stop.starts_at || '').slice(0, 10);
      const tripId = location.hash.match(/^#trip\/([^/]+)/)?.[1], day = stopDay(stop.starts_at);
      button.onclick = () => go(day ? `#trip/${tripId}/day/${day}` : `#trip/${tripId}/station/${encodeURIComponent(stop.id)}`);
      return button;
    }));
    // The endpoint already returns stations in their saved itinerary order.
    // Keep that order instead of re-sorting equal/uncertain timestamps.
    const geo = stops.filter(stop => stop.latitude !== null && stop.longitude !== null && Number.isFinite(Number(stop.latitude)) && Number.isFinite(Number(stop.longitude)));
    if (!geo.length) {
      // Without GPS there are no station markers to open the dated media.
      // Keep the real itinerary accessible independently of the trip's map point.
      if (window.ReiseNativeUI && stops.length) {
        const summary = mapNode.closest('.map-page')?.querySelector('.hero-copy');
        if (summary) {
          const heading = document.createElement('h2'); heading.textContent = 'Reisetage & Fotos';
          stopsNode.classList.add('native-no-gps-itinerary');
          stopsNode.setAttribute('aria-label', 'Reisetage und Fotos öffnen');
          summary.append(heading, stopsNode);
        }
      }
      const main = window.ReiseNativeUI ? data.main_location : null;
      if (main && dayStationCoordinates(main)) {
        if (window.ReiseAppleMaps && await window.ReiseAppleMaps.available()) {
          await window.ReiseAppleMaps.mount(mapNode, { markers: [{ id: String(main.id), title: 'Hauptort: ' + (main.name || 'Reise'), latitude: Number(main.latitude), longitude: Number(main.longitude) }], onSelect: () => stopsNode.querySelector('button')?.click() }); return;
        }
        const L = await loadLeaflet(); if (!mapNode.isConnected) return;
        const map = L.map(mapNode, { scrollWheelZoom: true }).setView([Number(main.latitude), Number(main.longitude)], 10);
        addCoastalLabels(map, L);
        L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, maxNativeZoom: 16, attribution: 'Tiles &copy; Esri' }).addTo(map);
        const label = document.createElement('span'); label.textContent = 'Hauptort: ' + (main.name || 'Reise');
        const marker = L.marker([Number(main.latitude), Number(main.longitude)]).addTo(map).bindTooltip(label, { permanent: true });
        if (stops.length) {
          marker.on('click', () => stopsNode.querySelector('button')?.click());
          marker.getElement()?.setAttribute('aria-label', 'Fotos dieser Reise öffnen');
        }
      } else mapNode.textContent = 'Für diese Reise sind keine GPS-Daten vorhanden.';
      return;
    }
    if (window.ReiseAppleMaps && await window.ReiseAppleMaps.available()) {
      if (!mapNode.isConnected) return;
      const focus = location.hash.match(/^#trip\/[^/]+\/map\/([^/]+)$/)?.[1];
      const map = await mountAppleStationMap(mapNode, stops, serverId, { focus: focus ? decodeURIComponent(focus) : '', onSelect: id => go(`#trip/${encodeURIComponent(serverId)}/station/${encodeURIComponent(id)}`) });
      if (map) installAppleRouteEditor(mapNode, map, stops, serverId);
      return;
    }
    const L = await loadLeaflet();
    if (!mapNode.isConnected) return;
    const map = L.map(mapNode, { zoomControl: true, scrollWheelZoom: true, attributionControl: true, preferCanvas: false });
    addCoastalLabels(map, L);
    L.control.scale({ position: 'bottomright', metric: true, imperial: false, maxWidth: 120 }).addTo(map);
    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, maxNativeZoom: 16, attribution: 'Tiles &copy; Esri' }).addTo(map);
    const points = geo.map(stop => [Number(stop.latitude), Number(stop.longitude)]);
    map.setView(points[0], 6, { animate: false });
    let routeArrows = addRouteDirectionArrows(map, points, tripRouteColor(serverId), serverId);
    const routeMarkers = [];
    geo.forEach((stop, index) => {
      const stationRole = [index === 0 ? 'station-first' : '', index === geo.length - 1 ? 'station-last' : ''].filter(Boolean).join(' ');
      const icon = L.divIcon({ className: 'route-station-marker', iconSize: [14,14], iconAnchor: [7,7] });
      const marker = L.marker([Number(stop.latitude), Number(stop.longitude)], { icon, draggable: canEditTrip(), zIndexOffset: index === 0 ? 10000 : 0 }).addTo(map);
      routeMarkers.push({ marker, stop, number: stop.stationNumber, role: stationRole, tripId: serverId, dot: stationDotColor(tripRouteColor(serverId)),
        open: () => go(`#trip/${encodeURIComponent(serverId)}/station/${encodeURIComponent(stop.id)}`) });
      if (canEditTrip()) {
        marker.on('dragstart', () => marker.__previousPosition = marker.getLatLng());
        marker.on('dragend', async () => {
          const point = marker.getLatLng();
          if (!confirm(`Neue Position für „${stop.name || 'Station'}“ speichern?`)) {
            marker.setLatLng(marker.__previousPosition); return;
          }
          marker.dragging.disable();
          try {
            const response = await apiFetch('./api/api.php?action=stop-location', {
              method: 'PATCH', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ id: stop.id, trip: serverId, name: stop.name || 'Station', latitude: point.lat, longitude: point.lng, confirmed: true })
            });
            if (!response.ok) throw new Error();
            stop.latitude = point.lat; stop.longitude = point.lng;
            points[index] = [point.lat, point.lng];
            map.removeLayer(routeArrows);
            routeArrows = addRouteDirectionArrows(map, points, tripRouteColor(serverId), serverId);
            marker.bindTooltip(stop.name || 'Station', { direction: 'top', offset: [0, -8] });
          } catch {
            marker.setLatLng(marker.__previousPosition);
            alert('Die Position konnte nicht gespeichert werden.');
          } finally { marker.dragging.enable(); }
        });
      }
    });
    installAdaptiveStationMarkers(map, L, routeMarkers);
    const focusId = location.hash.match(/^#trip\/[^/]+\/map\/([^/]+)$/)?.[1];
    const focusStop = focusId ? geo.find(stop => String(stop.id) === decodeURIComponent(focusId)) : null;
    const summary = mapNode.closest('.map-page')?.querySelector('.hero-copy');
    let overviewZoom;
    const updateSummaryVisibility = () => {
      if (summary && overviewZoom !== undefined) summary.style.display = map.getZoom() > overviewZoom ? 'none' : '';
    };
    map.on('zoomend', updateSummaryVisibility);
    const fit = () => {
      if (overviewZoom === undefined) overviewZoom = Math.min(14, map.getBoundsZoom(L.latLngBounds(points), false, L.point(112, 112)));
      if (focusStop) map.setView([Number(focusStop.latitude), Number(focusStop.longitude)], Math.min(16, map.getMaxZoom()), { animate: false });
      else map.fitBounds(points, { padding: [56, 56], maxZoom: 14 });
      updateSummaryVisibility();
    };
    requestAnimationFrame(() => { map.invalidateSize({ pan: false }); fit(); });
    setTimeout(() => { if (mapNode.isConnected) { map.invalidateSize({ pan: false }); fit(); } }, 250);
  } catch {
    mapNode.textContent = 'Die Stationskarte konnte nicht geladen werden.';
  }
}
function importer() { renderMediaImporter({ app, access, go, onImported: record => { const index = remoteTrips.findIndex(trip => trip.id === record.id); if (index >= 0) remoteTrips[index] = record; else remoteTrips.unshift(record); go('#trip/' + record.id); } }); }

initializeAuthenticatedArchive();

// Bottom menu and floating buttons yield while a map is moved or a page is scrolled by hand (app and website):
// they slide to their edge and stay there until pulled back in at the handle (bottom) or the tab (right edge),
// or until another page opens. Over the native Apple map this also means no menu cut-outs while it moves.
(() => {
  if (typeof document === 'undefined') return;
  let timer = 0, start = null, yielding = false, handle = null, userInput = 0;
  const menu = () => document.querySelector('.native-journey-nav,.journey-nav');
  // 0.3.8 app (iOS, Windows, Android): the menu yields only on the world map and the trip map; on "Alle Reisen",
  // the trip overview and the settings it stays fixed (also while a small day map there is moved).
  const native = () => !!window.ReiseNativeUI;
  const mapPage = () => !!document.querySelector('#app > .world-page,#app > .map-page');
  const onMap = target => target instanceof Element && !!target.closest('.leaflet-container,.apple-map-host') && !target.closest('dialog,button,a,input,select,.leaflet-control') && (!native() || mapPage());
  const restore = () => { clearTimeout(timer); yielding = false; document.body.classList.remove('menu-yielding'); };
  // 0.3.4: no automatic return; only the handle, the tab or a page change bring the menu back.
  const later = () => {};
  const yieldMenu = () => {
    // iOS with the native bar (native-bar.js): the bar lies above the Apple map and stays, like in Apple Maps.
    // 0.4: the website keeps its menu fixed everywhere (on the computer it holds the map zoom buttons).
    if (!native()) return;
    if (!menu() || document.querySelector('dialog[open]') || document.body.classList.contains('native-bar')) return;
    if (!handle) {
      handle = document.createElement('button'); handle.type = 'button'; handle.className = 'menu-yield-handle'; handle.setAttribute('aria-label', 'Menü einblenden');
      // Tab at the right edge for the floating buttons (Einstellungen, Reise bearbeiten).
      const tab = document.createElement('button'); tab.type = 'button'; tab.className = 'menu-yield-tab'; tab.setAttribute('aria-label', 'Knöpfe einblenden');
      // Tap, or pull the tab inwards (handle up, side tab to the left), brings everything back.
      for (const [node, pulled] of [[handle, (dx, dy) => dy < -16], [tab, (dx) => dx < -16]]) {
        let from = null;
        node.onclick = restore;
        node.addEventListener('pointerdown', event => { from = { x: event.clientX, y: event.clientY }; try { node.setPointerCapture(event.pointerId); } catch {} });
        node.addEventListener('pointermove', event => { if (from && pulled(event.clientX - from.x, event.clientY - from.y)) { from = null; restore(); } });
        node.addEventListener('pointerup', () => { from = null; });
      }
      document.body.append(handle, tab);
    }
    clearTimeout(timer); yielding = true; document.body.classList.add('menu-yielding');
  };
  document.addEventListener('pointerdown', event => { start = onMap(event.target) ? { x: event.clientX, y: event.clientY } : null; }, true);
  document.addEventListener('pointermove', event => { if (start && !yielding && Math.hypot(event.clientX - start.x, event.clientY - start.y) > 8) yieldMenu(); }, true);
  for (const type of ['pointerup', 'pointercancel']) document.addEventListener(type, () => { start = null; later(); }, true);
  document.addEventListener('wheel', event => { userInput = Date.now(); if (onMap(event.target)) { yieldMenu(); later(); } }, { capture: true, passive: true });
  // Scrolling a page by hand (trip overview, all trips, settings …) also lets the menu yield. Scrolling done by
  // the app itself (page change, jump to a station) does not: it needs a recent touch, wheel or key.
  for (const type of ['touchmove', 'pointerdown', 'keydown']) document.addEventListener(type, () => { userInput = Date.now(); }, { capture: true, passive: true });
  document.addEventListener('scroll', event => {
    if (native() || Date.now() - userInput > 800) return;
    const target = event.target instanceof Element ? event.target : null;
    if (target?.closest('dialog,.leaflet-container,.apple-map-host')) return;
    yieldMenu(); later();
  }, { capture: true, passive: true });
  // Native Apple maps report their own gestures (the WebView does not see these touches).
  document.addEventListener('ReiseMapMove', event => { if (!event.target.closest?.('dialog') && (!native() || mapPage())) yieldMenu(); });
  document.addEventListener('ReiseMapMoveEnd', later);
  window.addEventListener('hashchange', restore);
})();
