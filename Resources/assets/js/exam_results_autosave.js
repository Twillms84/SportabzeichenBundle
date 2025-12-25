document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('autosave-form');
    if (!form) return;

    const saveRoute = form.getAttribute('data-global-route');
    const csrfToken = form.getAttribute('data-global-token');

    // Hilfsfunktion: Medaillen-Farben aktualisieren
    function updateMedalUI(cell, medal) {
        if (!cell) return;
        const medalClass = 'medal-' + (medal ? medal.toLowerCase() : 'none');
        const elements = cell.querySelectorAll('select, input');
        
        elements.forEach(el => {
            el.classList.remove('medal-gold', 'medal-silber', 'medal-bronze', 'medal-none');
            el.classList.add(medalClass);
        });
    }

    // NEU: Hilfsfunktion: Gesamtpunkte in der Zeile neu berechnen
    function updateRowTotal(epId) {
        const badge = document.getElementById(`total-points-${epId}`);
        if (!badge) return;

        let total = 0;
        // Suche alle Inputs dieses Teilnehmers und summiere die Daten-Attribute
        const allInputs = document.querySelectorAll(`input[data-ep-id="${epId}"]`);
        allInputs.forEach(input => {
            const pts = parseInt(input.getAttribute('data-current-points')) || 0;
            total += pts;
        });

        badge.textContent = `${total} Pkt.`;
    }

    form.addEventListener('change', async function(event) {
        const el = event.target;
        if (!el.hasAttribute('data-save')) return;

        const cell = el.closest('td');
        const selectEl = cell.querySelector('select');
        const inputEl = cell.querySelector('input[type="text"]');

        const epId = el.getAttribute('data-ep-id');
        const disciplineId = selectEl.value;
        const leistung = inputEl.value;

        // Falls Disziplin leer, UI zurücksetzen und Punkte auf 0
        if (!disciplineId) {
            updateMedalUI(cell, 'none');
            inputEl.setAttribute('data-current-points', '0');
            updateRowTotal(epId);
            return;
        }

        try {
            const response = await fetch(saveRoute, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    ep_id: epId,
                    discipline_id: disciplineId,
                    leistung: leistung.replace(',', '.'),
                    _token: csrfToken
                })
            });

            if (!response.ok) throw new Error('Netzwerk-Antwort war nicht OK');

            const data = await response.json();

            if (data.status === 'ok') {
                // 1. Farben aktualisieren
                updateMedalUI(cell, data.medal);
                
                // 2. Punkte am Input-Feld für die JS-Berechnung zwischenspeichern
                inputEl.setAttribute('data-current-points', data.points || 0);
                
                // 3. Zeilensumme aktualisieren
                updateRowTotal(epId);
            } else {
                console.error("Server-Fehler:", data.error);
            }
        } catch (e) {
            console.error("[Error]", e);
        }
    });
});