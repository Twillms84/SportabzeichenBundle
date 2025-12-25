document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('autosave-form');
    if (!form) return;

    const saveRoute = form.getAttribute('data-global-route');
    const csrfToken = form.getAttribute('data-global-token');

    function updateMedalUI(cell, medal) {
        if (!cell) return;
        const medalClass = 'medal-' + (medal ? medal.toLowerCase() : 'none');
        const elements = cell.querySelectorAll('select, input');
        
        elements.forEach(el => {
            el.classList.remove('medal-gold', 'medal-silver', 'medal-bronze', 'medal-none');
            el.classList.add(medalClass);
        });
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

        // Falls Disziplin leer, nur UI zurücksetzen
        if (!disciplineId) {
            updateMedalUI(cell, 'none');
            return;
        }

        console.log(`[Autosave] Sende EP=${epId}, Disc=${disciplineId}, Wert=${leistung}`);

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

            if (!response.ok) {
                throw new Error('Netzwerk-Antwort war nicht OK');
            }

            const data = await response.json();
            console.log("[Server Response]", data);

            if (data.status === 'ok') {
                updateMedalUI(cell, data.medal);
            } else {
                console.error("Server-Fehler:", data.error);
            }
        } catch (e) {
            console.error("[Error]", e);
        }
    });
});