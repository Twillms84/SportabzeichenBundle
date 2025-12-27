document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('autosave-form');
    if (!form) {
        console.error("Autosave-Formular nicht gefunden!");
        return;
    }

    const saveRoute = form.getAttribute('data-global-route');
    const csrfToken = form.getAttribute('data-global-token');

    console.log("Autosave geladen. Route:", saveRoute);

    // Wir lauschen auf 'change' Events im gesamten Formular
    form.addEventListener('change', async function(event) {
        const el = event.target;
        
        // Nur reagieren, wenn das Element das Attribut 'data-save' hat
        if (!el.hasAttribute('data-save')) return;

        const epId = el.getAttribute('data-ep-id');
        const cell = el.closest('td');
        const selectEl = cell.querySelector('select');
        const inputEl = cell.querySelector('input[type="text"]');

        // Validierung: Wir brauchen eine gewählte Disziplin und eine ID
        if (!selectEl || !selectEl.value || !epId) {
            console.warn("Speichern abgebrochen: Disziplin fehlt oder keine EP-ID.");
            return;
        }

        console.log("Speichere für EP-ID:", epId, "Disziplin:", selectEl.value, "Leistung:", inputEl.value);

        try {
            const response = await fetch(saveRoute, {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    ep_id: epId,
                    discipline_id: selectEl.value,
                    leistung: inputEl.value,
                    _token: csrfToken // Token mitsenden, falls Symfony es prüft
                })
            });

            if (!response.ok) throw new Error('Server-Antwort war nicht OK');

            const data = await response.json();
            
            if (data.status === 'ok') {
                console.log("Speichern erfolgreich:", data);

                // 1. Farben der aktuellen Zelle (Input & Select)
                [selectEl, inputEl].forEach(element => {
                    element.classList.remove('medal-gold', 'medal-silber', 'medal-bronze', 'medal-none');
                    element.classList.add('medal-' + (data.medal || 'none'));
                });

                // 2. Gesamtpunkte Badge
                const totalBadge = document.getElementById('total-points-' + epId);
                if (totalBadge) {
                    totalBadge.textContent = (data.total_points || 0) + ' Pkt.';
                    // Farbe des Badges basierend auf der finalen Medaille
                    totalBadge.className = 'badge ' + getBadgeClass(data.final_medal);
                }

                // 3. Schwimm-Status Badge
                const swimmingBadge = document.getElementById('swimming-status-' + epId);
                if (swimmingBadge) {
                    swimmingBadge.className = data.has_swimming ? 'badge bg-success' : 'badge bg-danger';
                    swimmingBadge.textContent = data.has_swimming ? '🏊 OK' : '❌ Schwimmen';
                }

                // 4. Zeilen-Hintergrund aktualisieren
                const row = document.getElementById('row-' + epId);
                if (row) {
                    row.className = row.className.replace(/medal-row-\w+/g, '');
                    row.classList.add('medal-row-' + (data.final_medal || 'none'));
                }
            } else {
                console.error("Fehler vom Server:", data.error);
            }
        } catch (e) {
            console.error('Verbindungsfehler beim Speichern:', e);
        }
    });

    // Hilfsfunktion für Badge-Farben
    function getBadgeClass(medal) {
        switch(medal) {
            case 'gold': return 'bg-warning text-dark';
            case 'silber': return 'bg-secondary';
            case 'bronze': return 'bg-danger';
            default: return 'bg-info text-dark';
        }
    }
});