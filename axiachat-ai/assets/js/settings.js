(function() {
  // WordPress i18n functions
  var __ = wp.i18n.__;

  function forEachNode(list, callback) {
    if (!list || typeof callback !== 'function') {
      return;
    }
    Array.prototype.forEach.call(list, callback);
  }

  function initSecretToggles(root) {
    forEachNode(root.querySelectorAll('.aichat-toggle-secret'), function(btn) {
      btn.addEventListener('click', function() {
        var targetId = btn.getAttribute('data-target');
        var input = document.getElementById(targetId);
        if (!input) {
          return;
        }
        var icon = btn.querySelector('i');
        if (input.type === 'password') {
          input.type = 'text';
          if (icon) {
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');
          }
        } else {
          input.type = 'password';
          if (icon) {
            icon.classList.remove('bi-eye-slash');
            icon.classList.add('bi-eye');
          }
        }
      });
    });
  }

  function initTabs(root) {
    var container = root.querySelector('.aichat-settings-tabs');
    if (!container) {
      return;
    }
    var tabLinks = container.querySelectorAll('.nav-link');
    var tabPanes = container.querySelectorAll('.tab-pane');
    if (!tabLinks.length || !tabPanes.length) {
      return;
    }

    function activateTab(targetId) {
      forEachNode(tabLinks, function(link) {
        if (link.getAttribute('data-tab-target') === targetId) {
          link.classList.add('active');
          link.setAttribute('aria-selected', 'true');
        } else {
          link.classList.remove('active');
          link.setAttribute('aria-selected', 'false');
        }
      });
      forEachNode(tabPanes, function(pane) {
        if (pane.id === targetId) {
          pane.classList.add('active');
          pane.setAttribute('aria-hidden', 'false');
        } else {
          pane.classList.remove('active');
          pane.setAttribute('aria-hidden', 'true');
        }
      });
    }

    forEachNode(tabLinks, function(link) {
      link.addEventListener('click', function(event) {
        event.preventDefault();
        var targetId = link.getAttribute('data-tab-target');
        if (targetId) {
          activateTab(targetId);
        }
      });
    });

    var initialLink = container.querySelector('.nav-link.active');
    if (initialLink) {
      activateTab(initialLink.getAttribute('data-tab-target'));
    } else if (tabPanes[0]) {
      activateTab(tabPanes[0].id);
    }
  }

  function initPolicyReset() {
    var resetBtn = document.getElementById('aichat-reset-security-policy');
    var textarea = document.getElementById('aichat_security_policy');
    if (!resetBtn || !textarea) {
      return;
    }
    var data = window.aichatSettingsData || {};
    var defaultPolicy = data.defaultPolicy || '';
    var confirmMessage = data.resetConfirm || '';
    resetBtn.addEventListener('click', function() {
      if (defaultPolicy === '') {
        return;
      }
      if (!confirmMessage || window.confirm(confirmMessage)) {
        textarea.value = defaultPolicy;
      }
    });
  }

  function initConnectToggle() {
    var connectToggle = document.getElementById('aichat_addon_connect_enabled');
    if (!connectToggle) {
      return;
    }
    connectToggle.addEventListener('change', function() {
      if (!connectToggle.checked) {
        return;
      }
      var shouldShowGuide = connectToggle.getAttribute('data-guide-required') === '1';
      if (!shouldShowGuide) {
        return;
      }
      var guideUrl = connectToggle.getAttribute('data-guide-url');
      if (!guideUrl) {
        connectToggle.checked = false;
        return;
      }
      var message = connectToggle.getAttribute('data-guide-message');
      if (window.confirm(message || __('Visit Andromeda Connect installation guide?', 'axiachat-ai'))) {
        connectToggle.checked = false;
        window.location.href = guideUrl;
      } else {
        connectToggle.checked = false;
      }
    });
  }

  function initLogViewers() {
    var phpBtn = document.getElementById('aichat-debug-log-refresh');
    var aiBtn  = document.getElementById('aichat-debug-ai-log-refresh');

    function bind(button, type, textareaId) {
      if (!button) return;
      var textarea = document.getElementById(textareaId);
      if (!textarea) return;

      button.addEventListener('click', function() {
        if (!window.aichatSettingsData || !window.aichatSettingsData.ajaxUrl) {
          return;
        }
        button.disabled = true;
        button.classList.add('disabled');
        var originalHtml = button.innerHTML;
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' + (button.getAttribute('data-loading-label') || originalHtml);

        var formData = new FormData();
        formData.append('action', 'aichat_get_log_tail');
        formData.append('nonce', (window.aichatSettingsData && window.aichatSettingsData.nonce) || '');
        formData.append('log_type', type);

        fetch(window.aichatSettingsData.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          body: formData
        })
          .then(function(res) { return res.json(); })
          .then(function(data) {
            if (data && data.success) {
              textarea.value = data.data || '';
              textarea.classList.remove('d-none');
            } else {
              var msg = (data && data.data) ? data.data : __('Error loading log.', 'axiachat-ai');
              textarea.value = msg;
              textarea.classList.remove('d-none');
            }
          })
          .catch(function() {
            textarea.value = __('Error loading log.', 'axiachat-ai');
            textarea.classList.remove('d-none');
          })
          .finally(function() {
            button.disabled = false;
            button.classList.remove('disabled');
            button.innerHTML = originalHtml;
          });
      });
    }

    bind(phpBtn, 'general', 'aichat_debug_log_preview');
    bind(aiBtn, 'ai', 'aichat_debug_ai_log_preview');
  }

  function initClearLogButtons() {
    var buttons = document.querySelectorAll('.aichat-clear-log-btn');
    if (!buttons.length) return;

    buttons.forEach(function(btn) {
      btn.addEventListener('click', function() {
        if (!window.aichatSettingsData || !window.aichatSettingsData.ajaxUrl) return;

        var type = btn.getAttribute('data-log-type');
        var confirmMsg = (window.aichatSettingsData && window.aichatSettingsData.clearLogConfirm)
          ? window.aichatSettingsData.clearLogConfirm
          : __('Are you sure you want to clear this log file?', 'axiachat-ai');

        if (!window.confirm(confirmMsg)) return;

        btn.disabled = true;
        var originalHtml = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' + __('Clearing…', 'axiachat-ai');

        var formData = new FormData();
        formData.append('action', 'aichat_clear_log');
        formData.append('nonce', (window.aichatSettingsData && window.aichatSettingsData.nonce) || '');
        formData.append('log_type', type);

        fetch(window.aichatSettingsData.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          body: formData
        })
          .then(function(res) { return res.json(); })
          .then(function(data) {
            // Also clear the corresponding textarea if visible.
            var textareaId = type === 'ai' ? 'aichat_debug_ai_log_preview' : 'aichat_debug_log_preview';
            var textarea = document.getElementById(textareaId);
            if (textarea) {
              textarea.value = '';
            }
          })
          .catch(function() {
            // Silent fail — nothing to show.
          })
          .finally(function() {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
          });
      });
    });
  }

  function initEmailAlerts() {
    // Toggle visibility of email fields when checkbox changes
    var enableCb = document.getElementById('aichat_email_alerts_enabled');
    var fieldsWrap = document.getElementById('aichat-email-alerts-fields');
    if (enableCb && fieldsWrap) {
      enableCb.addEventListener('change', function() {
        fieldsWrap.style.display = enableCb.checked ? '' : 'none';
      });
    }

    // Advanced modal open
    var advBtn = document.getElementById('aichat-email-alerts-advanced-btn');
    var modalEl = document.getElementById('aichat-email-alerts-modal');
    if (!advBtn || !modalEl) return;

    var bsModal = null;
    // Use Bootstrap 5 Modal if available, otherwise simple toggle
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
      bsModal = new bootstrap.Modal(modalEl);
    }

    advBtn.addEventListener('click', function(e) {
      e.preventDefault();
      if (bsModal) {
        bsModal.show();
      } else {
        modalEl.classList.add('show');
        modalEl.style.display = 'block';
        modalEl.setAttribute('aria-hidden', 'false');
      }
    });

    // Apply button saves modal values to hidden fields
    var applyBtn = document.getElementById('aichat-email-alerts-modal-save');
    if (applyBtn) {
      applyBtn.addEventListener('click', function() {
        // Content
        var contentRadio = modalEl.querySelector('input[name="_aichat_modal_content"]:checked');
        if (contentRadio) {
          document.getElementById('aichat_email_alerts_content').value = contentRadio.value;
        }
        // Mode
        var modeRadio = modalEl.querySelector('input[name="_aichat_modal_mode"]:checked');
        if (modeRadio) {
          document.getElementById('aichat_email_alerts_mode').value = modeRadio.value;
        }
        // Idle minutes
        var idleInput = document.getElementById('aichat_modal_idle_minutes');
        if (idleInput) {
          var val = parseInt(idleInput.value, 10);
          if (isNaN(val) || val < 5) val = 5;
          if (val > 120) val = 120;
          document.getElementById('aichat_email_alerts_idle_minutes').value = val;
        }
        // Close modal
        if (bsModal) {
          bsModal.hide();
        } else {
          modalEl.classList.remove('show');
          modalEl.style.display = 'none';
          modalEl.setAttribute('aria-hidden', 'true');
        }
      });
    }

    // Cancel / close without applying (dismiss buttons handled by BS data-bs-dismiss)
    var cancelBtns = modalEl.querySelectorAll('[data-bs-dismiss="modal"]');
    if (!bsModal) {
      cancelBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
          modalEl.classList.remove('show');
          modalEl.style.display = 'none';
          modalEl.setAttribute('aria-hidden', 'true');
        });
      });
    }
  }

  document.addEventListener('DOMContentLoaded', function() {
    var settingsWrap = document.querySelector('.aichat-settings-wrap');
    if (!settingsWrap) {
      return;
    }
    initSecretToggles(settingsWrap);
    initTabs(settingsWrap);
    initPolicyReset();
    initConnectToggle();
    initLogViewers();
    initClearLogButtons();
    initEmailAlerts();
    initFreeKeyHowto(settingsWrap);
  });

  function initFreeKeyHowto(root) {
    var btn = root.querySelector('#aichat-free-key-howto-settings');
    if (!btn) return;
    btn.addEventListener('click', function(e) {
      e.preventDefault();
      var overlay = document.createElement('div');
      overlay.className = 'aichat-ec-modal-overlay';
      overlay.id = 'aichat-free-key-modal-settings';
      overlay.innerHTML = '<div class="aichat-ec-modal">'
        + '<button type="button" class="aichat-ec-modal-close" aria-label="Close">&times;</button>'
        + '<h3>\uD83D\uDD11 ' + __('How to get a free Google API key', 'axiachat-ai') + '</h3>'
        + '<ol>'
        + '<li><span class="aichat-ec-modal-step-title">' + __('Go to Google AI Studio', 'axiachat-ai') + '</span>'
        + __('Open', 'axiachat-ai') + ' <a href="https://aistudio.google.com" target="_blank" rel="noopener">aistudio.google.com</a> ' + __('and sign in with your Gmail account.', 'axiachat-ai') + '</li>'
        + '<li><span class="aichat-ec-modal-step-title">' + __('Get your API Key', 'axiachat-ai') + '</span>'
        + __('In the left sidebar, click', 'axiachat-ai') + ' <strong>"Get API key"</strong>.</li>'
        + '<li><span class="aichat-ec-modal-step-title">' + __('Create the key', 'axiachat-ai') + '</span>'
        + __('Click the blue button', 'axiachat-ai') + ' <strong>"Create API key in new project"</strong>. ' + __('Once the alphanumeric code appears, click Copy.', 'axiachat-ai') + '</li>'
        + '<li><span class="aichat-ec-modal-step-title">' + __('Paste it here', 'axiachat-ai') + '</span>'
        + __('Paste the key in the Google Gemini API Key field above and click Save changes.', 'axiachat-ai') + '</li>'
        + '</ol>'
        + '<div class="aichat-ec-modal-footer">'
        + '<a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener" class="button button-primary">' + __('Go to Google AI Studio', 'axiachat-ai') + ' \u2192</a>'
        + '</div></div>';
      document.body.appendChild(overlay);
      overlay.addEventListener('click', function(ev) {
        if (ev.target === overlay || ev.target.classList.contains('aichat-ec-modal-close')) {
          overlay.remove();
        }
      });
    });
  }

  /* ── Advanced tab: delete-data warning toggle ── */
  (function(){
    var cb   = document.getElementById('aichat_delete_data_on_uninstall');
    var warn = document.getElementById('aichat-delete-data-warning');
    if (cb && warn) {
      cb.addEventListener('change', function(){ warn.classList.toggle('d-none', !this.checked); });
    }
  })();

  /* ── Add-ons tab: footer preview ── */
  (function(){
    var input   = document.getElementById('aichat_footer_html');
    var preview = document.getElementById('footer-preview');
    if (input && preview) {
      input.addEventListener('input', function(){ preview.innerHTML = this.value; });
    }
  })();
})();
