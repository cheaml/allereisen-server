const guestStatus = new URLSearchParams(location.search);
if (guestStatus.has('contact')) document.querySelector('#contactNote').hidden = false;
else if (guestStatus.has('sent')) document.querySelector('#sentNote').hidden = false;
