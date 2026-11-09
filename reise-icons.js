'use strict';
(() => {
 const paths = {
  import:'<path d="M12 3v12m-4-4 4 4 4-4M4 15v6h16v-6"/>',
  duration:'<circle cx="12" cy="14" r="8"/><path d="M9 2h6M12 2v4M18 6l2-2M12 10v4l3 2"/>',
  share:'<path d="M12 3v12M7.5 7.5 12 3l4.5 4.5"/><path d="M8 10H6.5A1.5 1.5 0 0 0 5 11.5v8A1.5 1.5 0 0 0 6.5 21h11a1.5 1.5 0 0 0 1.5-1.5v-8a1.5 1.5 0 0 0-1.5-1.5H16"/>',
  world:'<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c5 5 5 13 0 18-5-5-5-13 0-18Z"/>',
  archive:'<circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/><path d="M9 6h12M9 12h12M9 18h12"/>',
  gear:'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
  settings:'<path d="M4 6h16M4 12h16M4 18h16"/><circle cx="9" cy="6" r="2"/><circle cx="15" cy="12" r="2"/><circle cx="9" cy="18" r="2"/>',
  help:'<circle cx="12" cy="12" r="9"/><path d="M9 9a3 3 0 0 1 6 0c0 2-3 2-3 4M12 17h.01"/>',
  map:'<path d="m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2Z"/><path d="M9 3v16M15 5v16"/>',
  days:'<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v4M17 3v4M3 11h18M7 15h2M13 15h2"/>',
  slides:'<rect x="3" y="4" width="18" height="15" rx="3"/><path d="m10 8 6 4-6 4ZM8 22h8"/>',
  more:'<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
  edit:'<path d="m15 4 5 5M4 20l5-1L21 7a2 2 0 0 0-4-4L5 15Z"/>',
  view:'<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
  route:'<circle cx="5" cy="5" r="2"/><circle cx="19" cy="19" r="2"/><path d="M7 5h8a4 4 0 0 1 0 8H9a3 3 0 0 0 0 6h8"/>',
  add:'<rect x="3" y="4" width="18" height="16" rx="3"/><path d="M12 8v8M8 12h8"/>',
  pause:'<path d="M8 5v14M16 5v14"/>',
  play:'<path d="m8 4 12 8-12 8Z"/>',
  minus:'<circle cx="10" cy="10" r="7"/><path d="m15 15 6 6M6 10h8"/>',
  plus:'<circle cx="10" cy="10" r="7"/><path d="m15 15 6 6M6 10h8M10 6v8"/>',
  reset:'<path d="M3 8V3h5M3 3l6 6M21 16v5h-5M21 21l-6-6"/>',
  fullscreen:'<path d="M3 9V3h6M15 3h6v6M21 15v6h-6M9 21H3v-6"/>',
  close:'<path d="m6 6 12 12M18 6 6 18"/>',
  original:'<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
  photos:'<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8" cy="8" r="2"/><path d="m3 17 6-6 4 4 3-3 5 5"/>',
  numbers:'<path d="m9 3-2 18M17 3l-2 18M3 9h18M3 15h18"/>',
  invite:'<rect x="3" y="5" width="18" height="14" rx="3"/><path d="m3 7 9 6 9-6"/>',
  swipe:'<path d="M3 12h18M7 8l-4 4 4 4M17 8l4 4-4 4"/>',
  previous:'<path d="m15 5-7 7 7 7"/>',
  next:'<path d="m9 5 7 7-7 7"/>',
  logout:'<path d="M10 4H4v16h6M9 12h12M17 8l4 4-4 4"/>',
  check:'<path d="m5 12 4 4L19 6"/>',
  account:'<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>'
 };
 const svg = name => '<svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'+(paths[name]||paths.more)+'</svg>';
 const shortLabels={'Reise importieren':'Import','Alle Reisen':'Reisen','Weitere Aktionen':'Mehr','Weitere Reiseaktionen':'Mehr','Reisemenü':'Menü','Diashow pausieren':'Pause','Diashow starten':'Start','Diashow schließen':'Schließen','Karte einblenden':'Karte','Karte ausblenden':'Karte','Original öffnen':'Original','Herauszoomen':'−','Hineinzoomen':'+','Zoom zurücksetzen':'Anpassen','Vollbild einschalten':'Vollbild','Vollbild ausschalten':'Vollbild','Kartenoptionen':'Karte'};
 const apply=(button,name,label)=>{button.classList.add('native-icon-button');button.dataset.icon=name;button.setAttribute('aria-label',label);button.title=label;button.innerHTML=svg(name);if(button.dataset.caption){const text=document.createElement('span');text.className='menu-caption';text.textContent=shortLabels[label]||label;button.append(text);}return button;};
 shortLabels['Bildschirmvollbild umschalten']='Vollbild';
 shortLabels['Bearbeiten einschalten']=shortLabels['Bearbeiten ausschalten']='Bearbeitungsmodus';
 const caption=button=>{button.dataset.caption='true';return apply(button,button.dataset.icon||'more',button.getAttribute('aria-label')||button.textContent);};
 window.ReiseIcons=Object.freeze({svg,apply,caption});
})();
