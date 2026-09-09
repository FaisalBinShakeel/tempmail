/**
 * TempMail front-end. No libraries, no build step.
 *
 * The page is fully functional server-rendered; everything here is an
 * enhancement layered on top of working forms and links.
 */
(function () {
  'use strict';

  var body = document.body;
  var state = {
    address: body.dataset.address || '',
    expiresAt: parseServerDate(body.dataset.expiresAt),
    extensions: parseInt(body.dataset.extensions || '0', 10),
    maxExtensions: parseInt(body.dataset.maxExtensions || '3', 10),
    lastId: parseInt(body.dataset.lastId || '0', 10),
    clockOffset: 0,          // serverNow - clientNow, in ms
    pollTimer: null,
    countdownTimer: null,
    expired: false,
    openMessageId: 0
  };

  var el = {
    notice: document.getElementById('notice'),
    list: document.getElementById('message-list'),
    empty: document.getElementById('empty-state'),
    count: document.getElementById('inbox-count'),
    countdown: document.getElementById('countdown'),
    extendBtn: document.getElementById('extend-btn'),
    extendNote: document.getElementById('extend-note'),
    copyBtn: document.getElementById('copy-btn'),
    clearLink: document.getElementById('clear-link'),
    customForm: document.getElementById('custom-form'),
    customPrefix: document.getElementById('custom-prefix'),
    customStatus: document.getElementById('custom-status'),
    modal: document.getElementById('modal'),
    modalSubject: document.getElementById('modal-subject'),
    modalMeta: document.getElementById('modal-meta'),
    modalFrame: document.getElementById('modal-frame'),
    modalOtp: document.getElementById('modal-otp'),
    modalRaw: document.getElementById('modal-raw'),
    modalRawToggle: document.getElementById('modal-raw-toggle'),
    modalClose: document.getElementById('modal-close'),
    modalDelete: document.getElementById('modal-delete'),
    modalConfirm: document.getElementById('modal-confirm'),
    modalDeleteYes: document.getElementById('modal-delete-yes'),
    modalDeleteNo: document.getElementById('modal-delete-no')
  };

  // ------------------------------------------------------------- utilities --

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  /** MySQL DATETIME strings are server-local; treat them as such consistently. */
  function parseServerDate(value) {
    if (!value) { return 0; }
    var parsed = Date.parse(value.replace(' ', 'T'));
    return isNaN(parsed) ? 0 : parsed;
  }

  function serverNow() {
    return Date.now() + state.clockOffset;
  }

  function notice(message, kind) {
    if (!el.notice) { return; }
    el.notice.textContent = message;
    el.notice.className = 'flash flash-' + (kind || 'ok');
    el.notice.hidden = false;
    window.clearTimeout(el.notice._timer);
    el.notice._timer = window.setTimeout(function () { el.notice.hidden = true; }, 6000);
  }

  /**
   * Thin fetch wrapper. Resolves to {ok, status, data}; never throws for HTTP
   * errors so callers can show a readable message for 403 / 429 / 500.
   */
  function api(path, options) {
    options = options || {};
    var init = {
      method: options.method || 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    };
    if (init.method !== 'GET') {
      init.headers['Content-Type'] = 'application/json';
      init.headers['X-CSRF-Token'] = csrfToken();
      init.body = JSON.stringify(options.body || {});
    }

    return fetch(path, init).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (data) {
        return { ok: response.ok, status: response.status, data: data };
      });
    }).catch(function () {
      return { ok: false, status: 0, data: { error: 'Network error. Check your connection.' } };
    });
  }

  /** Shared handling for the failures every endpoint can return. */
  function handleFailure(result) {
    var message = (result.data && result.data.error) || 'Something went wrong.';
    if (result.status === 403 && result.data && result.data.expired) {
      state.expired = true;
      stopPolling();
      if (el.countdown) {
        el.countdown.textContent = 'This inbox has expired';
        el.countdown.classList.add('urgent');
      }
    }
    notice(message, 'error');
  }

  // -------------------------------------------------------------- messages --

  function messageElement(msg) {
    var li = document.createElement('li');
    li.className = 'message new' + (msg.is_read ? '' : ' unread');
    li.dataset.id = String(msg.id);

    var link = document.createElement('a');
    link.className = 'message-link';
    link.href = '?msg=' + encodeURIComponent(msg.id);

    var top = document.createElement('span');
    top.className = 'message-top';

    var sender = document.createElement('span');
    sender.className = 'sender';
    var dot = document.createElement('span');
    dot.className = 'dot';
    dot.setAttribute('aria-hidden', 'true');
    sender.appendChild(dot);
    // textContent, never innerHTML: sender names and subjects are attacker-controlled.
    sender.appendChild(document.createTextNode(msg.sender_name || msg.sender_email || 'Unknown sender'));

    var time = document.createElement('span');
    time.className = 'time';
    time.textContent = msg.relative_time || '';

    top.appendChild(sender);
    top.appendChild(time);

    var subject = document.createElement('span');
    subject.className = 'subject';
    subject.textContent = (msg.subject && msg.subject.trim()) ? msg.subject : '(no subject)';

    link.appendChild(top);
    link.appendChild(subject);
    li.appendChild(link);

    window.setTimeout(function () { li.classList.remove('new'); }, 1800);
    return li;
  }

  function refreshCounts() {
    if (!el.list) { return; }
    var total = el.list.children.length;
    var unread = el.list.querySelectorAll('.message.unread').length;

    if (el.count) { el.count.textContent = total > 0 ? '(' + total + ')' : ''; }
    if (el.empty) { el.empty.hidden = total > 0; }

    var base = document.title.replace(/^\(\d+\)\s*/, '');
    document.title = unread > 0 ? '(' + unread + ') ' + base : base;
  }

  function applyMessages(data) {
    if (data.server_time) {
      state.clockOffset = parseServerDate(data.server_time) - Date.now();
    }
    if (data.expires_at) {
      state.expiresAt = parseServerDate(data.expires_at);
    }
    if (typeof data.extensions === 'number') {
      state.extensions = data.extensions;
      syncExtendButton();
    }

    var list = data.messages || [];
    if (!list.length || !el.list) { return; }

    // The API returns newest first; insert in reverse so the newest lands on top.
    for (var i = list.length - 1; i >= 0; i--) {
      var msg = list[i];
      if (msg.id > state.lastId) { state.lastId = msg.id; }
      if (el.list.querySelector('[data-id="' + String(msg.id).replace(/[^0-9]/g, '') + '"]')) { continue; }
      el.list.insertBefore(messageElement(msg), el.list.firstChild);
    }
    refreshCounts();
  }

  function poll() {
    if (state.expired || !state.address) { return Promise.resolve(); }
    return api('api/messages.php?since=' + encodeURIComponent(state.lastId)).then(function (result) {
      if (!result.ok) {
        if (result.status === 403 || result.status === 429) { handleFailure(result); }
        return;
      }
      applyMessages(result.data);
    });
  }

  function startPolling() {
    stopPolling();
    if (state.expired || !state.address) { return; }
    state.pollTimer = window.setInterval(poll, 5000);
  }

  function stopPolling() {
    if (state.pollTimer) {
      window.clearInterval(state.pollTimer);
      state.pollTimer = null;
    }
  }

  // ------------------------------------------------------------- countdown --

  function formatRemaining(ms) {
    var total = Math.max(0, Math.floor(ms / 1000));
    var hours = Math.floor(total / 3600);
    var minutes = Math.floor((total % 3600) / 60);
    var seconds = total % 60;
    var pad = function (n) { return n < 10 ? '0' + n : String(n); };
    return hours > 0
      ? hours + ':' + pad(minutes) + ':' + pad(seconds)
      : pad(minutes) + ':' + pad(seconds);
  }

  function tickCountdown() {
    if (!el.countdown || !state.expiresAt) { return; }
    var remaining = state.expiresAt - serverNow();

    if (remaining <= 0) {
      state.expired = true;
      stopPolling();
      el.countdown.textContent = 'This inbox has expired';
      el.countdown.classList.add('urgent');
      notice('This inbox has expired. Generate a new address to keep going.', 'error');
      window.clearInterval(state.countdownTimer);
      return;
    }

    el.countdown.textContent = 'Expires in ' + formatRemaining(remaining);
    el.countdown.classList.toggle('urgent', remaining < 5 * 60 * 1000);
  }

  function syncExtendButton() {
    if (!el.extendBtn) { return; }
    var left = Math.max(0, state.maxExtensions - state.extensions);
    el.extendBtn.disabled = left === 0;
    if (el.extendNote) { el.extendNote.textContent = left + ' left'; }
  }

  // --------------------------------------------------------------- actions --

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      var area = document.createElement('textarea');
      area.value = text;
      area.setAttribute('readonly', '');
      area.style.position = 'fixed';
      area.style.top = '-1000px';
      document.body.appendChild(area);
      area.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      document.body.removeChild(area);
      ok ? resolve() : reject(new Error('copy failed'));
    });
  }

  function flashCopied(button, label) {
    var original = button.textContent;
    button.textContent = label;
    button.classList.add('copied');
    window.setTimeout(function () {
      button.textContent = original;
      button.classList.remove('copied');
    }, 1600);
  }

  function reloadSoon() {
    window.setTimeout(function () { window.location.href = window.location.pathname; }, 400);
  }

  function generateNew() {
    return api('api/generate.php', { method: 'POST' }).then(function (result) {
      if (!result.ok) { handleFailure(result); return; }
      notice('New address ready.', 'ok');
      reloadSoon();
    });
  }

  function extendInbox() {
    return api('api/extend.php', { method: 'POST' }).then(function (result) {
      if (!result.ok) { handleFailure(result); return; }
      state.expiresAt = parseServerDate(result.data.expires_at);
      state.extensions = result.data.extensions;
      state.clockOffset = parseServerDate(result.data.server_time) - Date.now();
      syncExtendButton();
      tickCountdown();
      notice('Added one hour.', 'ok');
    });
  }

  function createCustom(prefix) {
    return api('api/create-custom.php', { method: 'POST', body: { prefix: prefix } })
      .then(function (result) {
        if (!result.ok) { handleFailure(result); return; }
        notice('Address ' + result.data.address + ' is yours.', 'ok');
        reloadSoon();
      });
  }

  function clearInbox() {
    return api('api/clear.php', { method: 'POST' }).then(function (result) {
      if (!result.ok) { handleFailure(result); return; }
      if (el.list) { el.list.textContent = ''; }
      // lastId stays where it is: deleted ids must not come back on the next poll.
      refreshCounts();
      notice(result.data.deleted === 1 ? '1 email deleted.' : result.data.deleted + ' emails deleted.', 'ok');
    });
  }

  function deleteMessage(id) {
    return api('api/delete.php', { method: 'POST', body: { id: id } }).then(function (result) {
      if (!result.ok) { handleFailure(result); return; }
      var row = el.list && el.list.querySelector('[data-id="' + String(id).replace(/[^0-9]/g, '') + '"]');
      if (row) { row.parentNode.removeChild(row); }
      refreshCounts();
      closeModal();
      notice('Email deleted.', 'ok');
    });
  }

  // ---------------------------------------------------------------- reader --

  function openMessage(id) {
    if (!el.modal) { return; }
    api('api/message.php?id=' + encodeURIComponent(id)).then(function (result) {
      if (!result.ok) { handleFailure(result); return; }
      var msg = result.data;
      state.openMessageId = msg.id;

      el.modalSubject.textContent = (msg.subject && msg.subject.trim()) ? msg.subject : '(no subject)';
      el.modalMeta.textContent = (msg.sender_name || msg.sender_email || 'Unknown sender')
        + ' <' + (msg.sender_email || '') + '> · ' + (msg.relative_time || '');

      // FR-4.2 — the body only ever goes into the sandboxed iframe.
      el.modalFrame.srcdoc = msg.iframe_doc || '';

      if (msg.otp) {
        el.modalOtp.textContent = 'Copy code: ' + msg.otp;
        el.modalOtp.dataset.code = msg.otp;
        el.modalOtp.hidden = false;
      } else {
        el.modalOtp.hidden = true;
        el.modalOtp.dataset.code = '';
      }

      el.modalRaw.textContent = (msg.raw_headers || '') + '\n\n' + (msg.body_text || '');
      el.modalRaw.hidden = true;
      el.modalConfirm.hidden = true;

      var row = el.list && el.list.querySelector('[data-id="' + String(msg.id).replace(/[^0-9]/g, '') + '"]');
      if (row) { row.classList.remove('unread'); }
      refreshCounts();

      el.modal.hidden = false;
      document.documentElement.style.overflow = 'hidden';
      el.modalClose.focus();
    });
  }

  function closeModal() {
    if (!el.modal) { return; }
    el.modal.hidden = true;
    el.modalFrame.removeAttribute('srcdoc');
    state.openMessageId = 0;
    document.documentElement.style.overflow = '';
  }

  // -------------------------------------------------------------- wiring ----

  function wireForms() {
    Array.prototype.forEach.call(document.querySelectorAll('form[data-js]'), function (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (form.dataset.js === 'generate') { generateNew(); }
        if (form.dataset.js === 'extend') { extendInbox(); }
      });
    });
  }

  function wireCopy() {
    if (!el.copyBtn) { return; }
    el.copyBtn.addEventListener('click', function () {
      copyText(el.copyBtn.dataset.copy || state.address).then(function () {
        flashCopied(el.copyBtn, 'Copied');
      }).catch(function () {
        notice('Could not copy automatically — select the address and copy it.', 'error');
      });
    });
  }

  function wireCustom() {
    if (!el.customForm || !el.customPrefix) { return; }
    var timer = null;

    function setStatus(text, kind) {
      el.customStatus.textContent = text;
      el.customStatus.className = 'hint' + (kind ? ' ' + kind : '');
    }

    el.customPrefix.addEventListener('input', function () {
      var value = el.customPrefix.value.trim().toLowerCase();
      window.clearTimeout(timer);

      if (value === '') { setStatus(''); return; }
      if (value.length < 3 || value.length > 30) {
        setStatus('Use between 3 and 30 characters.', 'bad');
        return;
      }
      if (!/^[a-z0-9][a-z0-9._-]*[a-z0-9]$/.test(value)) {
        setStatus('Letters, numbers, dot, underscore and dash only.', 'bad');
        return;
      }

      setStatus('Checking…');
      timer = window.setTimeout(function () {
        api('api/check-availability.php?prefix=' + encodeURIComponent(value)).then(function (result) {
          if (!result.ok) { handleFailure(result); setStatus(''); return; }
          if (result.data.available) {
            setStatus(result.data.address + ' is available.', 'good');
          } else {
            setStatus(result.data.reason || 'That address is not available.', 'bad');
          }
        });
      }, 400);
    });

    el.customForm.addEventListener('submit', function (event) {
      event.preventDefault();
      var value = el.customPrefix.value.trim().toLowerCase();
      if (value === '') { setStatus('Enter an address first.', 'bad'); return; }
      createCustom(value);
    });
  }

  function wireClear() {
    if (!el.clearLink) { return; }
    el.clearLink.addEventListener('click', function (event) {
      event.preventDefault();
      if (document.getElementById('js-clear-confirm')) { return; }

      var bar = document.createElement('div');
      bar.className = 'confirm-bar';
      bar.id = 'js-clear-confirm';

      var label = document.createElement('span');
      label.textContent = 'Delete every email in this inbox?';

      var yes = document.createElement('button');
      yes.type = 'button';
      yes.className = 'btn danger';
      yes.textContent = 'Yes, clear';
      yes.addEventListener('click', function () {
        bar.parentNode.removeChild(bar);
        clearInbox();
      });

      var no = document.createElement('button');
      no.type = 'button';
      no.className = 'btn subtle';
      no.textContent = 'Cancel';
      no.addEventListener('click', function () { bar.parentNode.removeChild(bar); });

      bar.appendChild(label);
      bar.appendChild(yes);
      bar.appendChild(no);
      el.list.parentNode.insertBefore(bar, el.list);
      yes.focus();
    });
  }

  function wireList() {
    if (!el.list) { return; }
    el.list.addEventListener('click', function (event) {
      var link = event.target.closest ? event.target.closest('.message-link') : null;
      if (!link) { return; }
      var item = link.closest('.message');
      if (!item) { return; }
      event.preventDefault();
      openMessage(parseInt(item.dataset.id, 10));
    });
  }

  function wireModal() {
    if (!el.modal) { return; }

    el.modalClose.addEventListener('click', closeModal);
    el.modal.addEventListener('click', function (event) {
      if (event.target === el.modal) { closeModal(); }
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !el.modal.hidden) { closeModal(); }
    });

    el.modalRawToggle.addEventListener('click', function () {
      el.modalRaw.hidden = !el.modalRaw.hidden;
    });

    el.modalDelete.addEventListener('click', function () { el.modalConfirm.hidden = false; });
    el.modalDeleteNo.addEventListener('click', function () { el.modalConfirm.hidden = true; });
    el.modalDeleteYes.addEventListener('click', function () {
      if (state.openMessageId) { deleteMessage(state.openMessageId); }
    });

    el.modalOtp.addEventListener('click', function () {
      var code = el.modalOtp.dataset.code || '';
      if (!code) { return; }
      copyText(code).then(function () {
        flashCopied(el.modalOtp, 'Code copied');
      }).catch(function () {
        notice('Could not copy the code automatically.', 'error');
      });
    });
  }

  function wireVisibility() {
    // FR-3.4 — no polling for a tab nobody is looking at.
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') {
        stopPolling();
      } else {
        poll();
        startPolling();
      }
    });
    window.addEventListener('focus', function () {
      if (!state.pollTimer && document.visibilityState === 'visible') {
        poll();
        startPolling();
      }
    });
  }

  // ----------------------------------------------------------------- boot ---

  wireForms();
  wireCopy();
  wireCustom();
  wireClear();
  wireList();
  wireModal();
  wireVisibility();
  refreshCounts();
  syncExtendButton();

  if (state.expiresAt) {
    tickCountdown();
    state.countdownTimer = window.setInterval(tickCountdown, 1000);
  }

  if (state.address && document.visibilityState === 'visible') {
    startPolling();
  }

  // A server-rendered message view means JS was late to the party; leave it be.
})();
