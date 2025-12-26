document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('autosave-form');
    if (!form) return;

    // Wir holen die Route aus dem data-Attribut des Formulars
    const saveRoute = form.getAttribute('data-global-route');

    // Hilfsfunktion: Medaillen-Farben aktualisieren
    function updateMedalUI(cell, medal) {
        const medalClass = 'medal-' + (medal ? medal.toLowerCase() : 'none');
        const elements = cell.querySelectorAll('select, input');
        elements.forEach(el => {
            el.classList.remove('medal-gold', 'medal-silber', 'medal-bronze', 'medal-none');
            if (medalClass !== 'medal-none') el.classList.add(medalClass);
        });
    }

    // Hilfsfunktion: Gesamtpunkte in der Zeile neu berechnen
    function updateRowTotal(epId) {
        const badge = document.getElementById(`total-points-${epId}`);
        if (!badge) return;

        let total = 0;
        const allInputs = document.querySelectorAll(`input[data-ep-id="${epId}"]`);
        allInputs.forEach(input => {
            total += parseInt(input.getAttribute('data-current-points')) || 0;
        });
        badge.textContent = `${total} Pkt.`;
    }

    form.addEventListener('change', async function(event) {
        const el = event.target;
        if (!el.hasAttribute('data-save')) return;

        const cell = el.closest('td');
        const selectEl = cell.querySelector('select');
        const inputEl = cell.querySelector('input[data-type="leistung"]');

        const epId = el.getAttribute('data-ep-id');
        const disciplineId = selectEl.value;
        const leistung = inputEl.value;

        if (!disciplineId) {
            updateMedalUI(cell, 'none');
            inputEl.setAttribute('data-current-points', '0');
            updateRowTotal(epId);
            return;
        }

        // Optisches Feedback (Blass werden während Speichern)
        cell.style.opacity = '0.5';

        try {
            const response = await fetch(saveRoute, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    ep_id: epId,
                    discipline_id: disciplineId,
                    leistung: leistung.replace(',', '.')
                })
            });

            const data = await response.json();
            cell.style.opacity = '1';

            if (data.status === 'ok') {
                updateMedalUI(cell, data.medal);
                inputEl.setAttribute('data-current-points', data.points || 0);
                updateRowTotal(epId);
            }
        } catch (e) {
            cell.style.opacity = '1';
            console.error("Speicherfehler:", e);
        }
    });
});