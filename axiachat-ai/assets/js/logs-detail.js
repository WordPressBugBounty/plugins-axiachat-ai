/**
 * Logs detail page – image preview & delete-confirm handlers.
 * Enqueued only on the single-conversation log view.
 */
(function(){
  'use strict';

  // Image preview: open full-size image in new window.
  document.addEventListener('click', function(e) {
    var a = e.target.closest('.aichat-log-img-preview[data-full-src]');
    if (!a) return;
    e.preventDefault();
    var src   = a.getAttribute('data-full-src');
    var title = (a.getAttribute('data-full-title') || '').replace(/[<>&"]/g, '');
    var w = window.open('', '_blank');
    if (!w) return;
    var d = w.document;
    d.open();
    d.write('<!DOCTYPE html><html><head><title>' + title + '</title>');
    d.write('</head><body style="margin:0;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#1a1a1a"><img src="' + src + '" style="max-width:100%;max-height:100vh;object-fit:contain" /></body></html>');
    d.close();
  });

  // Delete-conversation form: confirm before submit.
  var form = document.getElementById('aichat-delete-conv-form');
  if (form) {
    form.addEventListener('submit', function(e) {
      var msg = form.getAttribute('data-confirm') || 'Are you sure?';
      if (!confirm(msg)) e.preventDefault();
    });
  }
})();
