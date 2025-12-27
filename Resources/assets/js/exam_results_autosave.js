document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('autosave-form');
    if (!form) return;

    const saveRoute = form.getAttribute('data-global-route');

    form.addEventListener('change', async function(event) {
        const el = event.target;
        if (!el.hasAttribute('data-save')) return;

        const cell = el.closest('td');
        const selectEl = cell.querySelector('select');
        const inputEl = cell.querySelector('input[type="text"]');
        const epId = el.getAttribute('data-ep-id');

        if (!selectEl.value) return;

        try {
            const response = await fetch(saveRoute, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    ep_id: epId,
                    discipline_id: selectEl.value,
                    leistung: inputEl.value
                })
            });

            const data = await response.json();
            if (data.status === 'ok') {
                // 1. Einzel-Medaillen Farbe (Gold/Silber/Bronze)
                [selectEl, inputEl].forEach(element => {
                    element.classList.remove('medal-gold', 'medal-silber', 'medal-bronze', 'medal-none');
                    element.classList.add('medal-' + data.medal);
                });

                // 2. Gesamtpunkte Badge aktualisieren
                const totalBadge = document.getElementById('total-points-' + epId);
                if (totalBadge) {
                    totalBadge.textContent = data.total_points + ' Pkt.';
                    totalBadge.className = 'badge ' + 
                        (data.final_medal === 'gold' ? 'bg-warning text-dark' : 
                        (data.final_medal === 'silber' ? 'bg-secondary' : 
                        (data.final_medal === 'bronze' ? 'bg-danger' : 'bg-info text-dark')));
                }

                // 3. Schwimm-Status Badge aktualisieren
                const swimmingBadge = document.getElementById('swimming-status-' + epId);
                if (swimmingBadge) {
                    swimmingBadge.className = data.has_swimming ? 'badge bg-success' : 'badge bg-danger';
                    swimmingBadge.textContent = data.has_swimming ? '🏊 OK' : '❌ Schwimmen';
                }

                // 4. Zeilen-Hintergrund (Gesamtmedaille)
                const row = document.getElementById('row-' + epId);
                row.className = row.className.replace(/medal-row-\w+/g, '');
                row.classList.add('medal-row-' + data.final_medal);
            }
        } catch (e) { console.error('Save failed', e); }
    });
});