/**
 * Error Notice – inline‑extracted companion script.
 *
 * Toggle error‑details table and dismiss‑all button for the
 * provider‑error admin notice.
 *
 * No localized data needed: uses ajaxurl (WP global) + data-nonce attribute.
 */
(function(){
    /* Toggle details table */
    document.querySelector('.aichat-toggle-error-details')?.addEventListener('click', function(e){
        e.preventDefault();
        var table = document.querySelector('.aichat-error-table');
        if(table){
            var hidden = table.style.display === 'none';
            table.style.display = hidden ? '' : 'none';
            this.textContent = hidden ? 'Hide details \u25B4' : 'Show details \u25BE';
        }
    });
    /* Dismiss button */
    document.querySelector('.aichat-dismiss-errors')?.addEventListener('click', function(){
        var btn = this;
        btn.disabled = true;
        btn.textContent = 'Dismissing\u2026';
        fetch(ajaxurl, {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'action=aichat_dismiss_provider_errors&nonce=' + encodeURIComponent(btn.dataset.nonce)
        }).then(function(r){ return r.json(); }).then(function(data){
            if(data.success){
                var notice = document.querySelector('.aichat-provider-error-notice');
                if(notice) notice.remove();
                /* Remove badge counts from menu */
                document.querySelectorAll('#adminmenu .update-plugins').forEach(function(b){
                    if(b.closest('a')?.href?.includes('aichat-')) b.remove();
                });
            } else {
                btn.disabled = false;
                btn.textContent = 'Dismiss All';
                alert('Could not dismiss errors.');
            }
        }).catch(function(){
            btn.disabled = false;
            btn.textContent = 'Dismiss All';
        });
    });
})();
