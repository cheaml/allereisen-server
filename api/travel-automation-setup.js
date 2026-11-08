// Automation settings page (travel-automation-setup.php): home places by search and map.
// A file of its own: the site-wide CSP (.htaccess) allows scripts from 'self' and unpkg, not inline.
(()=>{
 const form=document.querySelector('form.settings-form'),picker=document.querySelector('.place-picker');if(!form||!picker)return;
 const field=name=>form.elements[name],targets={home:{name:'homeName',lat:'latitude',lon:'longitude'}};
 for(let i=1;i<picker.querySelector('.place-target').options.length;i++)targets[i]={name:'extraHomeName'+i,lat:'extraHomeLatitude'+i,lon:'extraHomeLongitude'+i};
 const status=picker.querySelector('.place-status'),results=picker.querySelector('.place-results'),target=picker.querySelector('.place-target'),query=picker.querySelector('.place-query');
 let map=null,layer=null;
 const ask=async params=>{const response=await fetch('travel-automation-setup.php?'+new URLSearchParams(params),{credentials:'same-origin',headers:{Accept:'application/json'}});const data=await response.json().catch(()=>({}));if(!response.ok)throw new Error(data.error||'Die Ortssuche ist gerade nicht erreichbar.');return data;};
 const point=key=>{const t=targets[key],lat=parseFloat(field(t.lat).value),lon=parseFloat(field(t.lon).value);return Number.isFinite(lat)&&Number.isFinite(lon)&&Math.abs(lat)<=90&&Math.abs(lon)<=180?[lat,lon]:null;};
 const label=key=>field(targets[key].name).value||(key==='home'?'Heimatort':'Weiterer Heimatort '+key);
 const rename=(key,name)=>{const input=field(targets[key].name);if(!name||input.value&&input.dataset.auto!=='1')return;input.value=name.slice(0,input.maxLength>0?input.maxLength:60);input.dataset.auto='1';};
 const draw=()=>{
  if(!map)return;layer.clearLayers();const radius=parseFloat(field('radiusKm').value)*1000;
  for(const key of Object.keys(targets)){
   const at=point(key);if(!at)continue;const active=key===target.value;
   if(Number.isFinite(radius)&&radius>0)L.circle(at,{radius,color:active?'#b8573f':'#26473e',weight:active?2:1,fillOpacity:.08,interactive:false}).addTo(layer);
   const marker=L.marker(at,{draggable:active,title:label(key),icon:L.divIcon({className:'place-pin'+(active?' active':''),iconSize:[22,22],iconAnchor:[11,11]})}).addTo(layer);
   if(active)marker.on('dragend',()=>{const p=marker.getLatLng();pick(p.lat,p.lng);});
  }
 };
 const set=(key,lat,lon)=>{const t=targets[key];field(t.lat).value=lat.toFixed(6);field(t.lon).value=lon.toFixed(6);if(key!=='home'){const extra=form.querySelector('details');if(extra)extra.open=true;}draw();};
 const pick=async(lat,lon)=>{
  const key=target.value;set(key,lat,lon);status.textContent='Ort gesetzt, Name wird gesucht …';
  try{const reply=await ask({place:'reverse',lat:lat.toFixed(6),lon:lon.toFixed(6)});rename(key,reply.label);draw();status.textContent='Gesetzt: '+label(key)+' ('+lat.toFixed(5)+', '+lon.toFixed(5)+'). Unten speichern, um es zu übernehmen.';}
  catch{status.textContent='Gesetzt ('+lat.toFixed(5)+', '+lon.toFixed(5)+'). Unten speichern, um es zu übernehmen.';}
 };
 const find=async()=>{
  const text=query.value.trim();results.replaceChildren();if(text.length<2){status.textContent='Bitte mindestens zwei Zeichen eingeben.';return;}
  status.textContent='Suche läuft …';
  try{
   const {places}=await ask({place:'search',q:text});
   status.textContent=places.length?'Ort antippen, um ihn zu übernehmen.':'Nichts gefunden. Anders schreiben oder auf der Karte tippen.';
   for(const place of places){
    const item=document.createElement('li'),choose=document.createElement('button'),name=document.createElement('strong'),detail=document.createElement('small');
    choose.type='button';name.textContent=place.label;detail.textContent=place.detail+' · '+place.latitude.toFixed(5)+', '+place.longitude.toFixed(5);choose.append(name,detail);
    choose.onclick=()=>{const key=target.value,input=field(targets[key].name);input.dataset.auto='1';input.value='';rename(key,place.label);set(key,place.latitude,place.longitude);results.replaceChildren();map?.setView([place.latitude,place.longitude],13);status.textContent='Übernommen: '+place.label+'. Unten speichern, um es zu übernehmen.';};
    item.append(choose);results.append(item);
   }
  }catch(error){status.textContent=error.message;}
 };
 picker.querySelector('.place-find').onclick=find;
 query.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();find();}});
 target.onchange=()=>{status.textContent='';results.replaceChildren();draw();const at=point(target.value);if(at&&map)map.setView(at,12);};
 form.addEventListener('input',event=>{const name=event.target.name||'';if(/Name/.test(name))event.target.dataset.auto='0';if(/atitude|ongitude|^radiusKm$/.test(name))draw();});
 picker.hidden=false;
 const css=document.createElement('link');css.rel='stylesheet';css.href='https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';css.integrity='sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=';css.crossOrigin='';document.head.append(css);
 const script=document.createElement('script');script.src='https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';script.integrity='sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=';script.crossOrigin='';
 script.onload=()=>{
  const home=point('home');map=L.map(picker.querySelector('.place-map')).setView(home||[47.6,13.8],home?11:6);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{subdomains:'abc',maxZoom:19,referrerPolicy:'strict-origin-when-cross-origin',attribution:'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'}).addTo(map);
  layer=L.layerGroup().addTo(map);map.on('click',event=>pick(event.latlng.lat,event.latlng.lng));draw();
 };
 script.onerror=()=>{picker.querySelector('.place-map').hidden=true;};
 document.head.append(script);
})();

// Review cases: the station choice follows the chosen trip.
for(const choice of document.querySelectorAll('.review-trip'))choice.addEventListener('change',async()=>{
 const stops=choice.form.querySelector('.review-stop');stops.replaceChildren(new Option('erste Station',''));if(!choice.value)return;
 try{const response=await fetch('travel-automation-setup.php?stops='+encodeURIComponent(choice.value),{credentials:'same-origin',headers:{Accept:'application/json'}});const data=await response.json();for(const [id,label] of data.stops||[])stops.append(new Option(label,id));}catch{}
});

// Buttons with far-reaching effects ask first (no inline handlers: the site CSP forbids them).
for(const button of document.querySelectorAll('button[data-confirm]'))button.addEventListener('click',event=>{if(!confirm(button.dataset.confirm))event.preventDefault();});
