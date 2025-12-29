document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('autosave-form');
    if (!form) return;

    const saveRoute = form.getAttribute('data-global-route');
    const csrfToken = form.getAttribute('data-global-token');

    form.addEventListener('change', async function(event) {
        const el = event.target;
        
        // Nur reagieren, wenn data-save vorhanden ist
        if (!el.hasAttribute('data-save')) return;

        const epId = el.getAttribute('data-ep-id');
        const kat = el.getAttribute('data-kategorie'); // Wichtig: Damit wissen wir, welche Gruppe wir bereinigen müssen
        const cell = el.closest('td');
        const row = el.closest('tr');
        
        const selectEl = cell.querySelector('select');
        const inputEl = cell.querySelector('input[type="text"]');

        // Validierung
        if (!selectEl || !selectEl.value || !epId) return;

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
                    _token: csrfToken
                })
            });

            if (!response.ok) throw new Error('Server-Fehler');

            const data = await response.json();
            
            if (data.status === 'ok') {
                
                // 1. AUFRÄUMEN: Alle Inputs der gleichen Kategorie (außer dem aktuellen) leeren
                // Das ist nötig, weil der Server die anderen Disziplinen gelöscht hat.
                if (kat) {
                    const groupInputs = row.querySelectorAll(`[data-kategorie="${kat}"]`);
                    groupInputs.forEach(otherEl => {
                        const otherCell = otherEl.closest('td');
                        // Wenn es eine andere Zelle ist als die aktuelle:
                        if (otherCell !== cell) {
                            // Input leeren
                            if (otherEl.tagName === 'INPUT') otherEl.value = '';
                            // Select resetten (optional, meist will man aber sehen was gewählt war, daher lassen wir select oft so, aber Farbe weg)
                            // CSS Klassen entfernen
                            otherEl.classList.remove('medal-gold', 'medal-silber', 'medal-bronze');
                            otherEl.classList.add('medal-none');
                        }
                    });
                }

                // 2. AKTUALISIEREN: Farben der aktuellen Zelle setzen
                // Hier war der Fehler: PHP sendet 'stufe', nicht 'medal' für das Einzelergebnis
                const resultColor = data.stufe || 'none'; 
                
                [selectEl, inputEl].forEach(element => {
                    element.classList.remove('medal-gold', 'medal-silber', 'medal-bronze', 'medal-none');
                    element.classList.add('medal-' + resultColor);
                });

                // 3. Gesamtpunkte Update
                const totalBadge = document.getElementById('total-points-' + epId);
                if (totalBadge) {
                    // Update Text (z.B. innere span oder direkt textContent)
                    // Da im Twig oft verschachtelt, hier sicher gehen:
                    if(totalBadge.querySelector('.pts-val')) {
                         totalBadge.querySelector('.pts-val').textContent = data.total_points;
                    } else {
                         totalBadge.textContent = data.total_points + ' Pkt.';
                    }
                }

                // 4. Schwimm-Status Badge Update
                const swimmingBadge = document.getElementById('swimming-status-' + epId);
                if (swimmingBadge) {
                    swimmingBadge.className = data.has_swimming ? 'badge bg-success' : 'badge bg-danger';
                    swimmingBadge.textContent = data.has_swimming ? '🏊 OK' : '❌ Schwimmen';
                }

                // 5. Zeilen-Hintergrund für End-Medaille
                // Hier nutzen wir data.final_medal
                if (row) {
                    row.className = row.className.replace(/medal-row-\w+/g, '');
                    row.classList.add('medal-row-' + (data.final_medal || 'none'));
                }
            }
        } catch (e) {
            console.error('Fehler beim Autosave:', e);
        }
    });
});