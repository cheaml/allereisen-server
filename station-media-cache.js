// Temporary, bounded memory cache. Private media is never saved for offline use.
const stationMediaCache = (() => {
  const entries = new Map(), limit = 64 * 1024 * 1024;
  let bytes = 0, generation = 0, controller, station = '';
  const url = item => `./api/api.php?action=${/\.(heic|heif)$/i.test(item.name || '') ? 'preview&size=fullscreen' : 'media-stream'}&trip=${encodeURIComponent(item.tripId)}&media=${encodeURIComponent(item.mediaId)}`;
  const clear = () => {
    controller?.abort(); generation++;
    for (const entry of entries.values()) {
      if (entry.url) URL.revokeObjectURL(entry.url);
      if (entry.video) { entry.video.pause(); entry.video.removeAttribute('src'); entry.video.load(); }
    }
    entries.clear(); bytes = 0;
  };
  const warm = async (item, epoch) => {
    const key = url(item);
    if (entries.has(key)) return;
    if (item.type === 'video') {
      if (globalThis.ReiseNativeVideo) return; // Native apps prepare only the current inline video on demand.
      if ([...entries.values()].filter(entry => entry.video).length >= 2) return;
      const video = document.createElement('video'); video.preload = 'metadata'; video.playsInline = true;
      video.src = key; video.load(); entries.set(key, { video }); return;
    }
    if (bytes >= limit) return;
    try {
      const response = await fetch(key, { credentials: 'same-origin', signal: controller.signal });
      if (!response.ok) return;
      const blob = await response.blob();
      if (epoch !== generation || bytes + blob.size > limit) return;
      entries.set(key, { url: URL.createObjectURL(blob) }); bytes += blob.size;
    } catch { /* The viewer can retry failed preloads directly. */ }
  };
  const begin = (tripId, stopId, media, page) => {
    if (!page.isConnected) return;
    const key = `${tripId}/${stopId}`;
    if (station === key) return;
    clear(); station = key; controller = new AbortController();
    const epoch = generation, queue = media.map(item => ({ tripId, mediaId: item.id, type: item.media_type, name: item.name }));
    const run = async () => {
      while (queue.length && page.isConnected && epoch === generation) await warm(queue.shift(), epoch);
    };
    run(); run();
  };
  window.addEventListener('hashchange', () => {
    if (station && location.hash !== `#trip/${station.replace('/', '/station/')}`) { clear(); station = ''; }
  });
  window.addEventListener('pagehide', () => { clear(); station = ''; });
  return { begin, invalidate: () => { clear(); station = ''; }, source: item => entries.get(url(item))?.url || url(item), video: item => entries.get(url(item))?.video || document.createElement('video'), url };
})();
