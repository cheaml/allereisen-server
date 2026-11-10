'use strict';
// 0.4: Hell / Dunkel / Wie System. Runs in <head> before the page is drawn, so a dark page never flashes light.
// The choice is stored per device; "Wie System" follows the operating system and changes with it.
(() => {
  const key = 'atlas-theme', root = document.documentElement, query = matchMedia('(prefers-color-scheme: dark)');
  const read = () => { try { const value = localStorage.getItem(key); return value === 'dark' || value === 'system' ? value : 'light'; } catch { return 'light'; } };
  const apply = () => {
    const choice = read(), dark = choice === 'dark' || (choice === 'system' && query.matches);
    root.dataset.theme = dark ? 'dark' : 'light'; root.dataset.themeChoice = choice; root.style.colorScheme = dark ? 'dark' : 'light';
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', dark ? '#121715' : '#f8eddc');
    window.dispatchEvent(new CustomEvent('ReiseThemeChanged', { detail: { choice, dark } }));
  };
  query.addEventListener?.('change', apply);
  window.ReiseTheme = Object.freeze({
    get choice() { return read(); }, get dark() { return root.dataset.theme === 'dark'; },
    set(choice) { try { if (choice === 'light') localStorage.removeItem(key); else localStorage.setItem(key, choice); } catch {} apply(); },
    labels: [['light', 'Hell'], ['dark', 'Dunkel'], ['system', 'Wie System']],
    /** Settings card "Darstellung" (app and website, area "Optionen"). */
    card() {
      const card = document.createElement('section'); card.className = 'import-card native-theme-card';
      const title = document.createElement('h2'); title.textContent = 'Darstellung';
      const select = document.createElement('select'); select.setAttribute('aria-label', 'Darstellung');
      for (const [value, label] of window.ReiseTheme.labels) { const option = document.createElement('option'); option.value = value; option.textContent = label; select.append(option); }
      select.value = read(); select.onchange = () => window.ReiseTheme.set(select.value);
      const hint = document.createElement('p'); hint.className = 'hint'; hint.textContent = '„Wie System“ folgt der Einstellung des Geräts und wechselt mit ihr.';
      card.append(title, select, hint); return card;
    },
  });
  apply();
})();
