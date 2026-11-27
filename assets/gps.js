// Optional GPS on check-in and submit forms. Only runs if the tech ticks "Share my location".
document.querySelectorAll('form[data-gps]').forEach(function (form) {
  var box = form.querySelector('input[name=share_gps]');
  var status = form.querySelector('.gps-status');
  form.addEventListener('submit', function (ev) {
    if (!box || !box.checked || form.dataset.located === '1') return;
    ev.preventDefault();
    if (!navigator.geolocation) { status.textContent = 'This browser can\'t share location. Untick the box to continue without it.'; return; }
    status.textContent = 'Getting your location...';
    navigator.geolocation.getCurrentPosition(function (pos) {
      form.querySelector('[name=gps_lat]').value = pos.coords.latitude;
      form.querySelector('[name=gps_lng]').value = pos.coords.longitude;
      form.querySelector('[name=gps_acc]').value = pos.coords.accuracy;
      form.dataset.located = '1';
      status.textContent = 'Location found.';
      var btn = ev.submitter || form.querySelector('button[name=action]');
      if (btn) { var h = document.createElement('input'); h.type = 'hidden'; h.name = btn.name; h.value = btn.value; form.appendChild(h); }
      form.submit();
    }, function (err) {
      status.textContent = err.code === 1
        ? 'Location permission was denied. Allow location for this site in your browser settings, or untick the box to continue without it.'
        : 'Couldn\'t get your location. Try again outside or near a window, or untick the box to continue without it.';
    }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 60000 });
  });
});
