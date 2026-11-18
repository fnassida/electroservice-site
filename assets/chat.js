// Live chat on the job page: sends without reloading and checks for new messages every few seconds.
(function () {
  var log = document.getElementById('chatlog');
  if (!log) return;
  var job = log.dataset.job, thread = log.dataset.thread;
  var form = document.getElementById('chatform');

  function lastId() {
    var all = log.querySelectorAll('.msg');
    return all.length ? all[all.length - 1].dataset.id : 0;
  }
  function localTime(iso) {
    var d = new Date(iso.replace(' ', 'T'));
    return isNaN(d) ? iso : d.toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  }
  function fixTimes(root) {
    root.querySelectorAll('time[datetime]').forEach(function (t) { t.textContent = localTime(t.getAttribute('datetime')); });
  }
  function atBottom() { return log.scrollHeight - log.scrollTop - log.clientHeight < 40; }

  function add(m) {
    if (log.querySelector('.msg[data-id="' + m.id + '"]')) return;
    var empty = log.querySelector('.empty'); if (empty) empty.remove();
    var div = document.createElement('div');
    div.className = 'msg' + (m.mine ? ' mine' : '');
    div.dataset.id = m.id;
    var meta = document.createElement('div'); meta.className = 'meta';
    meta.textContent = m.name + (m.via === 'sms' ? ' by text' : '') + ', ';
    var t = document.createElement('time'); t.setAttribute('datetime', m.at); t.textContent = localTime(m.at);
    meta.appendChild(t);
    var body = document.createElement('div'); body.className = 'body'; body.textContent = m.body;
    div.appendChild(meta); div.appendChild(body);
    log.appendChild(div);
  }

  function poll() {
    if (document.hidden) return;
    var stick = atBottom();
    fetch('/portal/jobs/messages.php?id=' + job + '&thread=' + thread + '&after=' + lastId(), { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : { messages: [] }; })
      .then(function (d) { d.messages.forEach(add); if (stick && d.messages.length) log.scrollTop = log.scrollHeight; })
      .catch(function () {});
  }

  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(form);
      if (!String(fd.get('body')).trim()) return;
      fd.append('ajax', '1');
      fd.append('after', lastId());
      var btn = form.querySelector('button'); btn.disabled = true;
      fetch('/portal/jobs/messages.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error(); return r.json(); })
        .then(function (d) { d.messages.forEach(add); form.body.value = ''; log.scrollTop = log.scrollHeight; })
        .catch(function () { form.submit(); })
        .finally(function () { btn.disabled = false; form.body.focus(); });
    });
    form.body.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
    });
  }

  fixTimes(log);
  log.scrollTop = log.scrollHeight;
  setInterval(poll, 4000);
  document.addEventListener('visibilitychange', poll);
})();
