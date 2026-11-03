'use strict';
(() => {
 const form=document.getElementById('deviceConnectForm');
 form?.addEventListener('submit',()=>{
  const button=form.querySelector('button');button.disabled=true;button.textContent='Verbindung wird vorbereitet …';
  document.getElementById('connectStatus').textContent='Deine Freigabe wird gesendet …';
 });
 const link=document.getElementById('returnToApp'),status=document.getElementById('status');
 if(link){
  link.addEventListener('click',()=>{status.textContent='Rückkehr zur App wird gestartet …';});
  setTimeout(()=>{window.location.href=link.href;},300);
 }
})();
