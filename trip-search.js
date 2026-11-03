'use strict';
(function (root) {
  const empty = () => ({ keyword: '', participant: '', year: 'all', query: '' });
  const normalize = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('de').replace(/ß/g, 'ss');
  const contains = (text, query) => normalize(query).trim().split(/\s+/).filter(Boolean).every(term => normalize(text).includes(term));
  const bounds = trip => {
    const start = String(trip.manual_starts_on || trip.starts_on || '').slice(0, 4);
    const end = String(trip.manual_ends_on || trip.ends_on || start).slice(0, 4);
    return /^\d{4}$/.test(start) ? [Number(start), /^\d{4}$/.test(end) ? Math.max(Number(start), Number(end)) : Number(start)] : [];
  };
  const years = trips => [...new Set(trips.flatMap(trip => {
    const [start, end] = bounds(trip); if (!start) return [];
    return Array.from({ length: Math.min(200, end - start + 1) }, (_, i) => String(start + i));
  }))].sort().reverse();
  const matches = (trip, state) => {
    const [start, end] = bounds(trip), year = Number(state.year);
    const text = [trip.title, trip.intro, trip.main_location, trip.participants, trip.search_text].filter(Boolean).join(' ');
    const unified = normalize(state.query).trim().split(/\s+/).filter(Boolean).every(term =>
      /^(?:19|20|21)\d{2}$/.test(term) ? start && Number(term) >= start && Number(term) <= end : contains(text, term));
    return (!state.year || state.year === 'all' || (start && year >= start && year <= end)) &&
      unified && contains(text, state.keyword) &&
      contains(trip.participants, state.participant);
  };
  const get = surface => { try { return { ...empty(), ...JSON.parse(sessionStorage.getItem('atlas-search-' + surface) || '{}') }; } catch { return empty(); } };
  const set = (surface, state) => { const value = { ...empty(), ...state }; try { sessionStorage.setItem('atlas-search-' + surface, JSON.stringify(value)); } catch {} return value; };
  function mount({ target, surface, availableYears, state = get(surface), onChange, live = true }) {
    const form = document.createElement('form'); form.className = 'trip-search'; form.setAttribute('role', 'search'); form.setAttribute('aria-label', surface === 'map' ? 'Reisen auf der Weltkarte filtern' : 'Reisen durchsuchen');
    const unified = surface === 'map';
    form.innerHTML = (unified
      ? '<label>Keywords, Jahr oder Teilnehmer<input type="search" name="query" placeholder="z. B. Asien 2018 Elena" maxlength="250"></label>'
      : '<label>Keywords<input type="search" name="keyword" placeholder="Titel, Beschreibung oder Ort" maxlength="250"></label><label>Jahr<select name="year"><option value="all">Alle Jahre</option></select></label><label>Teilnehmer<input type="search" name="participant" placeholder="Name eingeben" maxlength="250"></label>') + '<button type="button" class="secondary search-reset">Zurücksetzen</button>';
    if (unified) form.elements.query.value = state.query || [state.keyword, state.year !== 'all' ? state.year : '', state.participant].filter(Boolean).join(' ');
    else {
      for (const year of availableYears) form.elements.year.add(new Option(year, year));
      for (const name of ['keyword', 'participant', 'year']) form.elements[name].value = state[name] || (name === 'year' ? 'all' : '');
      if (!form.elements.year.value) { form.elements.year.value = 'all'; set(surface,{...state,year:'all'}); }
    }
    const apply = () => onChange(set(surface, unified ? { ...empty(), query: form.elements.query.value.trim() } : { keyword: form.elements.keyword.value.trim(), participant: form.elements.participant.value.trim(), year: form.elements.year.value }));
    form.onsubmit = event => { event.preventDefault(); apply(); };
    if (live) { if (unified) form.elements.query.oninput = apply; else { form.elements.keyword.oninput = form.elements.participant.oninput = apply; form.elements.year.onchange = apply; } }
    else { const submit = document.createElement('button'); submit.className = 'primary'; submit.textContent = 'Filter anwenden'; form.append(submit); }
    form.querySelector('.search-reset').onclick = () => { if (unified) form.elements.query.value = ''; else { form.elements.keyword.value = form.elements.participant.value = ''; form.elements.year.value = 'all'; } apply(); };
    target.append(form); return form;
  }
  const api = { empty, normalize, bounds, years, matches, get, set, mount };
  root.TripSearch = api;
  if (typeof module !== 'undefined') module.exports = api;
})(typeof window === 'undefined' ? globalThis : window);
