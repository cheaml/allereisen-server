'use strict';
(() => {
 const node=(tag,label,className)=>{const item=document.createElement(tag);if(label)item.textContent=label;if(className)item.className=className;return item;};
 const button=(label,icon,action)=>{const item=node('button',label,'journey-action');item.type='button';window.ReiseIcons?.apply(item,icon,label);item.onclick=action;return item;};
 const api=async url=>{const response=await window.AtlasApi.fetch(url,{credentials:'same-origin'});if(!response.ok)throw Error('Die Reisedaten konnten nicht geladen werden.');return response.json();};
 const ui={archiveEditing:false,touchNavigation:matchMedia('(pointer:coarse)').matches,
  bindPageSwipe(page,{previous,next}){
   let start,suppress=0;
   page.addEventListener('pointerdown',event=>{start=null;if(event.pointerType!=='touch'||!event.isPrimary||document.querySelector('dialog[open]')||event.target.closest('a,button,input,textarea,select,summary,.leaflet-container,.apple-map-host'))return;start={id:event.pointerId,x:event.clientX,y:event.clientY};});
   page.addEventListener('pointercancel',()=>start=null);
   page.addEventListener('pointerup',event=>{const p=start;start=null;if(!p||p.id!==event.pointerId||!page.isConnected||document.querySelector('dialog[open]'))return;const dx=event.clientX-p.x,dy=event.clientY-p.y;if(Math.abs(dx)<70||Math.abs(dx)<Math.abs(dy)*1.7)return;suppress=Date.now()+500;if(dx<0)next?.();else previous?.();});
   page.addEventListener('click',event=>{if(Date.now()<suppress){event.preventDefault();event.stopPropagation();}},true);
  },
  navigation({hash,app,go,openMediaLightbox,refresh,access}){
   document.body.classList.add('journey-web');document.body.classList.toggle('journey-world',!hash||hash==='#home');document.title='AlleReisen';
   const match=hash.match(/^#trip\/([^/]+)/),tripId=match?.[1];


   document.querySelector('.journey-nav')?.remove();
   const nav=node('nav',null,'journey-nav');nav.setAttribute('aria-label','Reisenavigation');
   const action=(label,icon,work,target)=>{const item=button(label,icon,work);if(target===hash)return item;nav.append(item);return item;};
   const secondary=(label,icon,work)=>action(label,icon,work);
   if(tripId){
    action('Weltkarte','world',()=>go('#home'),'#home');
    action('Alle Reisen','archive',()=>go('#library'),'#library');
    action('Reisekarte','map',()=>go('#trip/'+tripId),'#trip/'+tripId);
    const slides=action('Diashow','slides',async()=>{
     slides.disabled=true;try{const data=await api('./api/api.php?action=stops&trip='+encodeURIComponent(tripId)),stops=data.stops||[],requested=hash.match(/\/(?:station|place)\/([^/]+)/)?.[1],day=hash.match(/\/day\/([^/]+)/)?.[1],start=Math.max(0,stops.findIndex(stop=>requested?String(stop.id)===decodeURIComponent(requested):day?stop.starts_at?.slice(0,10)===day:false));
      for(let i=start;i<stops.length;i++){const stop=stops[i],media=await api('./api/api.php?action=media&trip='+encodeURIComponent(tripId)+'&stop='+encodeURIComponent(stop.id));if(!nav.isConnected)return;if(!media.media?.length)continue;
       openMediaLightbox(media.media.map(item=>({tripId,stopId:stop.id,mediaId:item.id,type:item.media_type,name:item.name,webUrl:item.web_url})),0,{currentStop:stop,allStops:stops,nextStationIds:stops.slice(i+1).map(stop=>stop.id),stationText:stop.summary||''});return;
      }throw Error('Diese Reise enthält noch keine abspielbaren Medien.');
     }catch(error){alert(error.message);}finally{slides.disabled=false;}
    });
    if(window.mayEditTrip?.(tripId)){const active=!!window.tripEditing?.(tripId),edit=action(active?'Bearbeiten aktiv':'Bearbeiten','edit',()=>window.openTripEditor(tripId).catch(error=>alert(error.message)));edit.setAttribute('aria-pressed',String(active));}
    action('Teilen','share',()=>window.shareTrip?.(tripId).catch(error=>alert(error.message)));
    secondary('Hilfe','help',()=>go('#hilfe'));
   }else{
    action('Weltkarte','world',()=>go('#home'),'#home');action('Alle Reisen','archive',()=>{if(hash==='#library')refresh();else go('#library');},'#library');
    if(access.loggedIn&&!access.isLocal)action('Einstellungen','gear',()=>go('#settings'),'#settings');

    action('Hilfe','help',()=>go('#hilfe'),'#hilfe');
    // Zoom buttons only with a mouse; on phones and tablets the map zooms with two fingers (keeps the menu at six entries).
    if(!hash||hash==='#home'){if(matchMedia('(pointer:fine)').matches){action('Herauszoomen','minus',()=>window.worldMapZoom?.(-1));action('Hineinzoomen','plus',()=>window.worldMapZoom?.(1));}action('Filter','settings',()=>window.openMapOptions());}
   }
   // In a trip the menu matches the app (Weltkarte … Hilfe); import, online trips and sign-out are on the overview pages.
   if(!tripId&&(access.isAdmin||access.canImport)){if(!hash||hash==='#home')action('Reise importieren','import',()=>go('#import'));else secondary('Reise importieren','import',()=>go('#import'));}
   const online=document.querySelector('#onlineArchiveMenu');if(!tripId&&hash&&hash!=='#home'&&online&&!online.hidden)secondary('Online-Reisen','original',()=>online.click());
   const logout=document.querySelector('#logoutMenu');if(!tripId&&hash&&hash!=='#home'&&logout&&!logout.hidden)secondary('Abmelden','logout',()=>logout.click());
   if(ui.archiveEditing){const badge=node('span','Verwaltungsmodus','journey-editing');badge.setAttribute('role','status');nav.append(badge);}
   nav.querySelectorAll('button,summary').forEach(item=>window.ReiseIcons?.caption(item));


   app.before(nav);
  }
 };
 window.ReiseWebUI=ui;
})();
