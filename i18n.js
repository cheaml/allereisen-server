'use strict';
// AlleReisen interface language (German source texts, English dictionary in i18n-en.js).
//
// The interface is written in German. When English is active, visible texts and the
// attributes title/placeholder/aria-label/alt are replaced in place as soon as they reach
// the document, before the browser paints. The German originals are remembered, so the
// language can be switched at runtime without reloading.
//
// Not translated: anything inside translate="no" (user content such as trip titles, station
// names, folder names, own texts), form field values and editable content.
//
// Language: saved choice (Settings) > device language reported by the app > browser language.
// German for German-speaking devices, English for every other language.
(() => {
  const STORE = 'reise-language', DEVICE = 'reise-device-language';
  const ATTRIBUTES = ['title', 'placeholder', 'aria-label', 'alt'];
  const SKIP = new Set(['SCRIPT', 'STYLE', 'TEXTAREA', 'NOSCRIPT', 'CODE', 'PRE']);
  const storage = {
    get(key) { try { return localStorage.getItem(key); } catch { return null; } },
    set(key, value) { try { value == null ? localStorage.removeItem(key) : localStorage.setItem(key, value); } catch { /* private mode */ } }
  };
  const pick = tag => /^de\b/i.test(String(tag || '')) ? 'de' : tag ? 'en' : null;
  const browserLanguage = () => pick((navigator.languages && navigator.languages[0]) || navigator.language) || 'de';
  const choice = () => { const saved = storage.get(STORE); return saved === 'de' || saved === 'en' ? saved : null; };
  let language = choice() || pick(storage.get(DEVICE)) || browserLanguage();

  const exact = new Map(), folded = new Map(), patterns = [];
  const norm = text => String(text).replace(/\s+/g, ' ').trim();
  const fold = text => norm(text).toLocaleLowerCase('de');
  const isUpper = text => /\p{Lu}{2}/u.test(text) && text === text.toLocaleUpperCase('de');
  const escape = text => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

  // Pattern placeholders: {a}..{f} any text (translated again if it is a known text), {n}/{m}/{k} numbers.
  function compile(source, target) {
    const names = [];
    const body = escape(norm(source)).replace(/\\\{([a-fnmk])\\\}/g, (_, name) => { names.push(name); return 'nmk'.includes(name) ? '([-+]?\\d[\\d.,\\s]*?)' : '(.+?)'; });
    return { expression: new RegExp('^' + body + '$', 'siu'), names, target };
  }
  let started = false;
  function register(dictionary) {
    for (const [source, target] of Object.entries(dictionary.exact || {})) { exact.set(norm(source), target); folded.set(fold(source), target); }
    for (const [source, target] of dictionary.patterns || []) patterns.push(compile(source, target));
    if (started && language !== 'de') element(document.documentElement);
  }

  // Translates one visible text (without surrounding whitespace). Returns null if unknown.
  function lookup(text, depth = 0) {
    if (!text || depth > 3) return null;
    if (exact.has(text)) return exact.get(text);
    const lower = folded.get(text.toLocaleLowerCase('de'));
    if (lower != null) return isUpper(text) ? lower.toLocaleUpperCase('en') : lower;
    for (const pattern of patterns) {
      const match = pattern.expression.exec(text);
      if (!match) continue;
      const values = {};
      pattern.names.forEach((name, index) => { const value = match[index + 1]; values[name] = 'nmk'.includes(name) ? value : (lookup(value, depth + 1) ?? value); });
      const result = pattern.target.replace(/\{([a-fnmk])\}/g, (_, name) => values[name] ?? '');
      return isUpper(text) && !isUpper(result) && /\p{L}/u.test(result) ? result.toLocaleUpperCase('en') : result;
    }
    // Decorations around a known text: "← ZURÜCK", "ÖFFNEN ↗", "Wird geladen …", "(Wien)".
    const framed = /^([^\p{L}\p{N}]+)?(.*?)([\s:↗…→←·).]+)?$/u.exec(text);
    if (framed && (framed[1] || framed[3]) && framed[2] && framed[2] !== text) {
      const inner = lookup(framed[2], depth + 1);
      if (inner != null) return (framed[1] || '') + inner + (framed[3] || '');
    }
    // Labels ending in a colon ("OneDrive-Konto:").
    if (text.endsWith(':') && text.length > 1) { const inner = lookup(text.slice(0, -1).trim(), depth + 1); if (inner != null) return inner + ':'; }
    // Lists joined with " · " ("12 Medien · OneDrive · Venedig").
    if (text.includes(' · ')) {
      const parts = text.split(' · '); let changed = false;
      const translated = parts.map(part => { const value = lookup(part.trim(), depth + 1); if (value == null) return part; changed = true; return value; });
      if (changed) return translated.join(' · ');
    }
    // "Label: value" ("Server-Konto: Demo · demo@example.org", "Letzte Übergabe an Website: Noch nicht erfasst").
    const labelled = /^([^:]{2,60}?):\s+(.+)$/su.exec(text);
    if (labelled && !/^https?$/i.test(labelled[1])) {
      const label = lookup(labelled[1], depth + 1), value = lookup(labelled[2], depth + 1);
      if (label != null || value != null) return (label ?? labelled[1]) + ': ' + (value ?? labelled[2]);
    }
    // Several sentences, each known on its own.
    const sentences = text.match(/[^.!?…]+[.!?…]+(?:\s+|$)|[^.!?…]+$/gu);
    if (sentences && sentences.length > 1) {
      let changed = false;
      const translated = sentences.map(sentence => { const core = sentence.trim(); const value = lookup(core, depth + 1); if (value == null) return core; changed = true; return value; });
      if (changed) return translated.join(' ');
    }
    return null;
  }
  function translate(text) {
    if (language === 'de' || text == null) return text;
    const value = String(text), core = norm(value);
    if (!core || !/\p{L}/u.test(core)) return value;
    const result = lookup(core);
    if (result == null) return value;
    const lead = /^\s*/.exec(value)[0], tail = /\s*$/.exec(value)[0];
    return lead + result + tail;
  }

  // ----- document translation -----
  const originalText = new WeakMap(), writtenText = new WeakMap();
  const originalAttributes = new WeakMap(), writtenAttributes = new WeakMap();
  const excluded = element => !element || SKIP.has(element.tagName) || !!element.closest('[translate="no"],[contenteditable=""],[contenteditable="true"],[data-i18n-skip]');
  function textNode(node) {
    const current = node.nodeValue;
    if (writtenText.get(node) === current) return;
    if (excluded(node.parentElement)) return;
    originalText.set(node, current);
    const next = translate(current);
    if (next !== current) { writtenText.set(node, next); node.nodeValue = next; }
    else writtenText.delete(node);
  }
  function attribute(element, name) {
    const current = element.getAttribute(name);
    if (current == null) return;
    let written = writtenAttributes.get(element); if (written?.[name] === current) return;
    // Attributes of form fields are interface texts (placeholder), their values are not touched.
    if (element.closest('[translate="no"],[data-i18n-skip]')) return;
    let originals = originalAttributes.get(element); if (!originals) originalAttributes.set(element, originals = {});
    originals[name] = current;
    const next = translate(current);
    if (!written) writtenAttributes.set(element, written = {});
    if (next !== current) { written[name] = next; element.setAttribute(name, next); } else delete written[name];
  }
  function element(root) {
    if (root.nodeType === Node.TEXT_NODE) { textNode(root); return; }
    if (root.nodeType !== Node.ELEMENT_NODE && root.nodeType !== Node.DOCUMENT_FRAGMENT_NODE) return;
    if (root.nodeType === Node.ELEMENT_NODE) { if (root.closest('[translate="no"]')) return; for (const name of ATTRIBUTES) if (root.hasAttribute(name)) attribute(root, name); }
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT, {
      acceptNode(node) {
        // Text inside SCRIPT/STYLE/TEXTAREA is rejected in textNode(); their attributes still count.
        if (node.nodeType === Node.ELEMENT_NODE) return node.getAttribute('translate') === 'no' || node.hasAttribute('data-i18n-skip') ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
        return NodeFilter.FILTER_ACCEPT;
      }
    });
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
      if (node.nodeType === Node.TEXT_NODE) textNode(node);
      else for (const name of ATTRIBUTES) if (node.hasAttribute(name)) attribute(node, name);
      if (node.tagName === 'TEMPLATE') element(node.content);
    }
  }
  // Puts the German originals back (switching to German at runtime).
  function restore(root) {
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT);
    for (let node = root; node; node = walker.nextNode()) {
      if (node.nodeType === Node.TEXT_NODE) { const original = originalText.get(node); if (original != null && writtenText.get(node) === node.nodeValue) node.nodeValue = original; writtenText.delete(node); }
      else if (node.nodeType === Node.ELEMENT_NODE) {
        const originals = originalAttributes.get(node), written = writtenAttributes.get(node);
        if (originals && written) for (const [name, value] of Object.entries(written)) if (node.getAttribute(name) === value) node.setAttribute(name, originals[name]);
        writtenAttributes.delete(node);
        if (node.tagName === 'TEMPLATE') restore(node.content);
      }
    }
  }
  const observer = new MutationObserver(records => {
    if (language === 'de') return;
    for (const record of records) {
      if (record.type === 'characterData') textNode(record.target);
      else if (record.type === 'attributes') { if (ATTRIBUTES.includes(record.attributeName)) attribute(record.target, record.attributeName); }
      else for (const node of record.addedNodes) if (node.isConnected) element(node);
    }
  });
  function applyDocument() {
    document.documentElement.lang = language;
    if (language === 'de') restore(document.documentElement); else element(document.documentElement);
    if (document.title) document.title = language === 'de' ? (originalTitle ?? document.title) : translate(originalTitle ?? document.title);
  }
  let originalTitle = null;
  function start() {
    started = true;
    originalTitle = document.title;
    applyDocument();
    observer.observe(document.documentElement, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ATTRIBUTES });
    // In the apps the device language comes from the system (iOS/Windows), not from the WebView.
    window.ReiseNative?.request('language').then(reply => device(reply?.language)).catch(() => { /* older app or website */ });
  }

  // Dialog texts from confirm()/alert()/prompt() on the website.
  for (const name of ['alert', 'confirm', 'prompt']) {
    const native = window[name];
    if (typeof native === 'function') window[name] = function (message, ...rest) { return native.call(this, translate(message), ...rest); };
  }

  function set(next, { remember = true } = {}) {
    const value = next === 'de' || next === 'en' ? next : null;
    if (remember) storage.set(STORE, value);
    const resolved = value || pick(storage.get(DEVICE)) || browserLanguage();
    if (resolved === language) return language;
    language = resolved;
    applyDocument();
    window.dispatchEvent(new CustomEvent('ReiseLanguageChanged', { detail: { language } }));
    return language;
  }
  // The app reports the device language (WebViews do not always expose it).
  function device(tag) {
    const value = pick(tag);
    if (!value) return language;
    storage.set(DEVICE, value);
    return choice() ? language : set(null, { remember: false });
  }

  window.ReiseI18n = Object.freeze({
    get language() { return language; },
    get choice() { return choice(); },
    get locale() { return language === 'en' ? 'en-GB' : 'de-AT'; },
    t: translate, register, set, device, translateTree: element
  });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true }); else start();
})();
