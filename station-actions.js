'use strict';
window.AtlasStationActions = Object.freeze({
 mount({page,tripId,stop,stops,media,go,onChanged=()=>{}}) {
  const section=document.createElement('section');section.className='station-management';
  const heading=document.createElement('h2');heading.textContent='Station verwalten';section.append(heading);
  const status=document.createElement('p');status.setAttribute('role','status');
  const request=async body=>{
   const query=body?'':'&trip='+encodeURIComponent(tripId)+'&source='+encodeURIComponent(stop.id);
   const response=await window.AtlasApi.fetch('./api/api.php?action=station-actions'+query,{credentials:'same-origin',cache:'no-store',...(body?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({trip:tripId,source:stop.id,confirmed:true,...body})}:{})});
   const data=await response.json();if(!response.ok)throw Error(data.error||'Änderung fehlgeschlagen.');return data;
  };
  let busy=false;
  const confirmAction=(message,acceptLabel)=>{
   if(!window.ReiseNativeUI)return Promise.resolve(confirm(message));
   return new Promise(resolve=>{
    const dialog=document.createElement('dialog');dialog.className='native-swipe-guide station-action-confirm';
    dialog.setAttribute('aria-label',acceptLabel+' bestätigen');
    const text=document.createElement('p');text.textContent=message;
    const cancel=document.createElement('button');cancel.type='button';cancel.className='edit-link';cancel.textContent='Abbrechen';cancel.onclick=()=>dialog.close();
    const accept=document.createElement('button');accept.type='button';accept.className='primary';accept.textContent=acceptLabel;
    let approved=false;accept.onclick=()=>{approved=true;dialog.close();};
    dialog.append(text,cancel,accept);dialog.addEventListener('close',()=>{dialog.remove();resolve(approved);},{once:true});document.body.append(dialog);dialog.showModal();cancel.focus();
   });
  };
  const run=async action=>{if(busy)return;busy=true;section.querySelectorAll('button,select,input').forEach(n=>n.disabled=true);status.textContent='';try{await action()}catch(error){status.textContent=error.message}finally{busy=false;section.querySelectorAll('button,select,input').forEach(n=>n.disabled=false)}};
  if(!media.length){
   const remove=document.createElement('button');remove.type='button';remove.className='edit-link';remove.textContent='LEERE STATION LÖSCHEN';
   remove.onclick=()=>run(async()=>{
    const preview=await request();if(!preview.empty)throw Error('Die Station enthält inzwischen Medien. Bitte neu laden.');
    const message=preview.empty_day?`Station „${stop.name||'Unbenannte Station'}“ löschen? Der Tag ${preview.day} ist ohne Medien. Auch seine ${preview.station_count} leeren Stationen, ihre Stationstexte und die Tagesnotizen werden entfernt.`:`Leere Station „${stop.name||'Unbenannte Station'}“ einschließlich Stationstext löschen?`;
    if(!await confirmAction(message,'Station löschen'))return;
    await request({operation:'delete',confirm_empty_day:preview.empty_day?preview.day:''});onChanged();go('#trip/'+tripId);
   });section.append(remove);
  }
  const day=String(stop.starts_at||'').slice(0,10),targets=stops.filter(s=>String(s.id)!==String(stop.id)&&day&&String(s.starts_at||'').slice(0,10)===day);
  if(!day){
   const label=document.createElement('label');label.textContent='Reisedatum dieser Station';
   const date=document.createElement('input');date.type='date';date.required=true;date.setAttribute('aria-label','Reisedatum dieser Station');label.append(date);
   const save=document.createElement('button');save.type='button';save.className='edit-link';save.textContent='DATUM ZUORDNEN';
   const hint=document.createElement('p');hint.textContent='Ordnet die Station einem Reisetag zu. Die Originaldateien und ihre Aufnahmezeiten bleiben unverändert.';
   save.onclick=()=>{if(!date.reportValidity())return;run(async()=>{const result=await request({operation:'date',day:date.value});onChanged();go('#trip/'+tripId+'/day/'+date.value);});};
   section.append(label,save,hint);
  }
  if(targets.length){
   const label=document.createElement('label');label.textContent='Zielstation zum Zusammenführen';
   const select=document.createElement('select');select.setAttribute('aria-label','Zielstation zum Zusammenführen');
   for(const target of targets){const option=document.createElement('option');option.value=target.id;option.textContent=(target.name||'Unbenannte Station')+' · '+String(target.starts_at||'').slice(11,16);select.append(option)}
   label.append(select);const merge=document.createElement('button');merge.type='button';merge.className='edit-link';merge.textContent='STATIONEN ZUSAMMENFÜHREN';
   merge.onclick=()=>run(async()=>{
    const target=targets.find(s=>String(s.id)===select.value);
    if(!await confirmAction(`„${stop.name||'Unbenannte Station'}“ mit „${target.name||'Unbenannte Station'}“ zusammenführen? Alle Medien werden der Zielstation zugeordnet und beide Stationstexte bleiben erhalten. Die Ausgangsstation wird entfernt.`,'Stationen zusammenführen'))return;
    const result=await request({operation:'merge',target:select.value});onChanged();go('#trip/'+tripId+'/station/'+result.station_id);
   });section.append(label,merge);
  }
  if(section.children.length===1)return;
  section.append(status);page.querySelector('.station-page-head').append(section);
 }
});
