/* Water-only routes for the archive maps. Natural Earth polygons are public domain.
   The shortest path is computed on a local metric grid, then straightened within
   that grid. This is a map illustration, not a nautical navigation chart. */
function waterRoute(from, to, data) {
  const metres = 111195;
  const latitude = (from[0] + to[0]) / 2;
  const longitudeScale = metres * Math.cos(latitude * Math.PI / 180);
  if (longitudeScale < 1000 || Math.abs(from[1] - to[1]) > 180) return null;
  const project = p => [(p[1] - from[1]) * longitudeScale, (p[0] - from[0]) * metres];
  const unproject = p => [from[0] + p[1] / metres, from[1] + p[0] / longitudeScale];
  const target = project(to), distance = Math.hypot(...target);
  if (distance < 1) return [from, to];
  // Do not infer maritime travel for continental/intercontinental connections.
  if (distance > 750000) return null;
  const insideRing = (p, ring) => {
    let inside = false;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
      const a = ring[i], b = ring[j];
      if ((a[1] > p[1]) !== (b[1] > p[1]) && p[0] < (b[0] - a[0]) * (p[1] - a[1]) / (b[1] - a[1]) + a[0]) inside = !inside;
    }
    return inside;
  };
  for (const marginFactor of [.3, 1]) {
    const margin = Math.max(1500, distance * marginFactor);
    const minX = Math.min(0, target[0]) - margin, minY = Math.min(0, target[1]) - margin;
    const maxX = Math.max(0, target[0]) + margin, maxY = Math.max(0, target[1]) + margin;
    const step = Math.max(25, Math.max(maxX - minX, maxY - minY) / 280);
    const width = Math.ceil((maxX - minX) / step) + 1, height = Math.ceil((maxY - minY) / step) + 1;
    const lower = unproject([minX, minY]), upper = unproject([maxX, maxY]);
    const select = polygons => polygons.filter(p => p.bounds[0] <= upper[1] && p.bounds[2] >= lower[1] && p.bounds[1] <= upper[0] && p.bounds[3] >= lower[0]).map(p => p.rings.map(r => r.map(point => project([point[1], point[0]]))));
    const land = select(data.land), lakes = select(data.lakes);
    const candidates = data.detail ? [data.detail, ...(data.detail.regions || [])] : [];
    const detail = candidates.find(d => lower[1] >= d.bounds[0] && lower[0] >= d.bounds[1] && upper[1] <= d.bounds[2] && upper[0] <= d.bounds[3]);
    const detailed = detail && detail.coast.length && lower[1] >= detail.bounds[0] && lower[0] >= detail.bounds[1] && upper[1] <= detail.bounds[2] && upper[0] <= detail.bounds[3];
    const coast = detailed ? detail.coast.map(line => line.map(p => project([p[1], p[0]]))) : [];
    if (data.requiresDetail && !detailed) continue;
    const inPolygons = (p, polygons) => polygons.some(rings => insideRing(p, rings[0]) && !rings.slice(1).some(ring => insideRing(p, ring)));
    const coastWater = p => {
      let best = Infinity, water = true;
      coast.forEach(line => {
        for (let i = 1; i < line.length; i++) {
          const a = line[i - 1], b = line[i], dx = b[0] - a[0], dy = b[1] - a[1];
          const t = Math.max(0, Math.min(1, ((p[0] - a[0]) * dx + (p[1] - a[1]) * dy) / (dx * dx + dy * dy || 1)));
          const distance = (p[0] - a[0] - t * dx) ** 2 + (p[1] - a[1] - t * dy) ** 2;
          // OSM coastline ways are directed with land on their left.
          if (distance < best) { best = distance; water = dx * (p[1] - a[1]) - dy * (p[0] - a[0]) <= 0; }
        }
      });
      return water;
    };
    const isWater = p => (detailed ? coastWater(p) : !inPolygons(p, land)) || inPolygons(p, lakes);
    const cells = new Uint8Array(width * height); cells.fill(1);
    const paint = (polygons, value) => polygons.forEach(rings => {
      for (let row = 0; row < height; row++) {
        const y = minY + row * step, intersections = [];
        rings.forEach(ring => {
          for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
            const a = ring[i], b = ring[j];
            if ((a[1] > y) !== (b[1] > y)) intersections.push(a[0] + (y - a[1]) * (b[0] - a[0]) / (b[1] - a[1]));
          }
        });
        intersections.sort((a, b) => a - b);
        for (let i = 0; i + 1 < intersections.length; i += 2) {
          const start = Math.max(0, Math.ceil((intersections[i] - minX) / step));
          const end = Math.min(width - 1, Math.floor((intersections[i + 1] - minX) / step));
          if (start <= end) cells.fill(value, row * width + start, row * width + end + 1);
        }
      }
    });
    if (detailed) {
      for (let row = 0; row < height; row++) {
        const y = minY + row * step, crossings = [];
        coast.forEach(line => {
          for (let i = 1; i < line.length; i++) {
            const a = line[i - 1], b = line[i];
            if ((a[1] > y) !== (b[1] > y)) crossings.push(a[0] + (y - a[1]) * (b[0] - a[0]) / (b[1] - a[1]));
          }
        });
        crossings.sort((a, b) => a - b);
        let water = coastWater([minX, y]), crossing = 0;
        while (crossing < crossings.length && crossings[crossing] <= minX) crossing++;
        for (let col = 0; col < width; col++) {
          while (crossing < crossings.length && crossings[crossing] < minX + col * step) { water = !water; crossing++; }
          cells[row * width + col] = water ? 1 : 0;
        }
      }
    } else paint(land, 0);
    paint(lakes, 1);
    // Block every cell touched by a shoreline. This prevents diagonal shortcuts
    // through thin peninsulas/islands even when no grid centre lies on the land.
    [...(detailed ? coast.map(line => [line]) : land), ...lakes].forEach(rings => rings.forEach(ring => {
      for (let i = 1; i < ring.length; i++) {
        const a = ring[i - 1], b = ring[i];
        if (Math.max(a[0], b[0]) < minX || Math.min(a[0], b[0]) > maxX || Math.max(a[1], b[1]) < minY || Math.min(a[1], b[1]) > maxY) continue;
        const count = Math.ceil(Math.max(Math.abs(b[0] - a[0]), Math.abs(b[1] - a[1])) / (step * .4));
        for (let k = 0; k <= count; k++) {
          const ratio = count ? k / count : 0;
          const x = Math.round((a[0] + (b[0] - a[0]) * ratio - minX) / step);
          const y = Math.round((a[1] + (b[1] - a[1]) * ratio - minY) / step);
          if (x >= 0 && x < width && y >= 0 && y < height) cells[y * width + x] = 0;
        }
      }
    }));
    const coordinate = id => [minX + (id % width) * step, minY + Math.floor(id / width) * step];
    const snap = point => {
      let best = -1, bestDistance = Infinity;
      const x = Math.round((point[0] - minX) / step), y = Math.round((point[1] - minY) / step);
      // GPS positions in harbours/on the shoreline may sit just on land in the
      // generalised source. Limit snapping to 350 m; inland stations stay direct.
      const radius = Math.ceil(350 / step);
      for (let dy = -radius; dy <= radius; dy++) for (let dx = -radius; dx <= radius; dx++) {
        const col = x + dx, row = y + dy;
        if (col < 0 || col >= width || row < 0 || row >= height) continue;
        const id = row * width + col;
        if (!cells[id]) continue;
        const p = coordinate(id), d = Math.hypot(p[0] - point[0], p[1] - point[1]);
        if (d <= 350 && d < bestDistance) { best = id; bestDistance = d; }
      }
      return best;
    };
    const start = snap([0, 0]), finish = snap(target);
    if (start < 0 || finish < 0) return null;
    const visible = (a, b) => {
      const ax = a % width, ay = Math.floor(a / width), bx = b % width, by = Math.floor(b / width);
      const count = Math.ceil(Math.max(Math.abs(bx - ax), Math.abs(by - ay)) * 4);
      let previousX = ax, previousY = ay;
      for (let i = 0; i <= count; i++) {
        const t = count ? i / count : 0, x = Math.round(ax + (bx - ax) * t), y = Math.round(ay + (by - ay) * t);
        if (!cells[y * width + x] || !cells[y * width + previousX] || !cells[previousY * width + x]) return false;
        previousX = x; previousY = y;
      }
      return true;
    };
    const costs = new Float64Array(cells.length); costs.fill(Infinity); costs[start] = 0;
    const previous = new Int32Array(cells.length); previous.fill(-1);
    const closed = new Uint8Array(cells.length), heap = [];
    const heuristic = id => Math.hypot(id % width - finish % width, Math.floor(id / width) - Math.floor(finish / width));
    const push = (id, score) => {
      const entry = { id, score }; let i = heap.length; heap.push(entry);
      while (i > 0) { const parent = (i - 1) >> 1; if (heap[parent].score <= score) break; heap[i] = heap[parent]; i = parent; } heap[i] = entry;
    };
    const pop = () => {
      const first = heap[0], last = heap.pop();
      if (heap.length) { let i = 0; while (i * 2 + 1 < heap.length) { let child = i * 2 + 1; if (child + 1 < heap.length && heap[child + 1].score < heap[child].score) child++; if (heap[child].score >= last.score) break; heap[i] = heap[child]; i = child; } heap[i] = last; }
      return first.id;
    };
    push(start, heuristic(start));
    while (heap.length) {
      const id = pop(); if (closed[id]) continue; closed[id] = 1;
      if (id === finish) break;
      const x = id % width, y = Math.floor(id / width);
      for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) {
        if ((!dx && !dy) || x + dx < 0 || x + dx >= width || y + dy < 0 || y + dy >= height) continue;
        const next = id + dy * width + dx;
        if (!cells[next] || closed[next] || !cells[y * width + x + dx] || !cells[(y + dy) * width + x]) continue;
        const cost = costs[id] + Math.hypot(dx, dy);
        if (cost >= costs[next]) continue;
        costs[next] = cost; previous[next] = id; push(next, cost + heuristic(next));
      }
    }
    if (!closed[finish]) continue;
    const path = []; for (let id = finish; id !== -1; id = previous[id]) path.push(id); path.reverse();
    const simple = [path[0]];
    for (let i = 0; i < path.length - 1;) {
      let next = i + 1;
      while (next + 1 < path.length && visible(path[i], path[next + 1])) next++;
      simple.push(path[next]); i = next;
    }
    const result = simple.map(id => unproject(coordinate(id)));
    // Keep exact endpoints for positions already in water. Shore points get
    // short endpoint connectors to the nearest water cell, never long detours.
    if (isWater([0, 0])) result[0] = from; else result.unshift(from);
    if (isWater(target)) result[result.length - 1] = to; else result.push(to);
    return result;
  }
  return null;
}

if (typeof module !== 'undefined') module.exports = { waterRoute };
else {
  let dataPromise;
  let localCoastPromise;
  const coastCache = new Map();
  const loadCoast = (from, to) => {
    const x = Math.floor(from[1] * 4), y = Math.floor(from[0] * 4);
    if (Math.abs(from[1] - to[1]) > .25 || Math.abs(from[0] - to[0]) > .25) return Promise.resolve(null);
    const key = `${x},${y}`;
    if (!coastCache.has(key)) coastCache.set(key, fetch(`./api/water-coast.php?x=${x}&y=${y}`, { credentials: 'same-origin' }).then(response => response.ok ? response.json() : null).catch(() => null));
    return coastCache.get(key);
  };
  self.onmessage = async event => {
    const { id, from, to } = event.data;
    try {
      // The archive's detailed local coast is small enough to load immediately,
      // independently of the global dataset and external coastline service.
      if (!localCoastPromise) localCoastPromise = fetch('./water-routing-rogoznica.json?v=20261002-4').then(response => response.ok ? response.json() : null).catch(() => null);
      const local = await localCoastPromise;
      const coversEndpoints = d => [from,to].every(p => p[1] >= d.bounds[0] && p[1] <= d.bounds[2] && p[0] >= d.bounds[1] && p[0] <= d.bounds[3]);
      if (local && [local, ...(local.regions || [])].some(coversEndpoints)) {
        const points = waterRoute(from, to, { land: [], lakes: [], detail: local, requiresDetail: true });
        if (points) { self.postMessage({ id, points }); return; }
      }
      if (!dataPromise) dataPromise = (async () => {
        if (typeof DecompressionStream !== 'undefined') {
          try {
            const response = await fetch('./water-routing-data.json.gz?v=20261002-4');
            if (!response.ok) throw Error('Water data unavailable');
            return await new Response(response.body.pipeThrough(new DecompressionStream('gzip'))).json();
          } catch { /* Older browsers/proxies can fall back to plain JSON. */ }
        }
        const response = await fetch('./water-routing-data.json?v=20261002-4');
        if (!response.ok) throw Error('Water data unavailable');
        return response.json();
      })().catch(error => { dataPromise = null; throw error; });
      const data = await dataPromise;
      const stored = data.detail;
      const withinStored = stored && [stored, ...(stored.regions || [])].some(coversEndpoints);
      const detail = withinStored ? stored : await loadCoast(from, to);
      self.postMessage({ id, points: waterRoute(from, to, { ...data, detail: detail || stored }) });
    } catch { self.postMessage({ id, points: null, error: true }); }
  };
}
