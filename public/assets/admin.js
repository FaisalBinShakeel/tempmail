/**
 * Admin panel enhancement: copy a fix snippet to the clipboard.
 * Everything else on the page is server-rendered and works without this.
 */
(function () {
  'use strict';

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

  document.addEventListener('click', function (event) {
    var button = event.target.closest ? event.target.closest('[data-copy-fix]') : null;
    if (!button) { return; }

    var block = button.parentNode.querySelector('[data-copy-source]');
    if (!block) { return; }

    copyText(block.textContent).then(function () {
      var original = button.textContent;
      button.textContent = 'Copied';
      window.setTimeout(function () { button.textContent = original; }, 1600);
    }).catch(function () {
      button.textContent = 'Select and copy manually';
    });
  });
})();
