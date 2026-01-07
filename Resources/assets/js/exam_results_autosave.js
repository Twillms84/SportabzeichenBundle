document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('autosave-form');
    if (!form) return;

    // Wiederherstellung der zwei Routen aus dem alten Skript
    const disciplineRoute = form.getAttribute('data-discipline-route'); 
    const resultRoute = form.getAttribute('data-result-route');
    const csrfToken = form.getAttribute('data-global-token');

    // --- 1. INITIALISIERUNG ---
    document.querySelectorAll('.js-discipline-select').forEach(select => {
        updateRequirementHints(select);
    });

    // --- 2. EVENT LISTENER ---
    form.addEventListener('change', async function(event) {
        const el = event.target;
        if (!el.hasAttribute('data-save')) return;

        const epId = el.getAttribute('data-ep-id');
        const kat = el.getAttribute('data-kategorie');
        const row = el.closest('tr');
        const isSwimming = el.getAttribute('data-type') === 'swimming';
        
        let selectEl = null;
        let inputEl = null;
        let targetRoute = "";

        // Logik zur Routen-Wahl (Wichtig!)
        if (isSwimming) {
            targetRoute = resultRoute; // Schwimmen wird meist über die Ergebnis-Route gehandelt
        } else {
            const cell = el.closest('td');
            if (!cell) return;
            selectEl = cell.querySelector('select');
            inputEl = cell.querySelector('input[type="text"]');
            
            // Wenn Select geändert -> DisciplineRoute, wenn Input geändert -> ResultRoute
            targetRoute = (el.tagName === 'SELECT') ? disciplineRoute : resultRoute;
        }

        // Validierung
        if (!isSwimming && (!selectEl || !selectEl.value)) return;

        // Payload Aufbau
        let payload = {
            ep_id: epId,
            _token: csrfToken
        };

        if (isSwimming) {
            payload.type = 'swimming';
            payload.leistung = el.checked ? 'on' : 'off';
        } else {
            payload.discipline_id = selectEl.value;
            payload.leistung = inputEl ? inputEl.value : '';
            if (inputEl) inputEl.style.opacity = '0.5';
            if (el.tagName === 'SELECT') updateRequirementHints(el);
        }

        try {
            const response = await fetch(targetRoute, {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(payload)
            });

            if (!response.ok) throw new Error('Server-Fehler ' + response.status);

            const data = await response.json();
            if (inputEl) inputEl.style.opacity = '1';

            if (data.status === 'ok') {
                // UI Updates (Farben, Kategorien leeren, Schwimm-Status)
                handleUIUpdates(data, isSwimming, el, selectEl, inputEl, kat, row, epId);
            }
        } catch (e) {
            console.error('Fehler beim Autosave:', e);
            if (inputEl) inputEl.style.backgroundColor = '#ffe6e6';
        }
    });

    // Hilfsfunktion für die UI-Aktualisierung (übersichtlicher)
    function handleUIUpdates(data, isSwimming, el, selectEl, inputEl, kat, row, epId) {
        // 1. Farben bei Disziplinen
        if (!isSwimming && selectEl && inputEl) {
            const color = data.stufe ? data.stufe.toLowerCase() : 'none';
            [selectEl, inputEl].forEach(e => {
                e.classList.remove('medal-gold', 'medal-silber', 'medal-bronze', 'medal-none');
                e.classList.add('medal-' + color);
            });
        }

        // 2. Kategorie-Bereinigung
        if (!isSwimming && kat && row) {
            row.querySelectorAll(`[data-kategorie="${kat}"]`).forEach(other => {
                if (other !== el && other !== selectEl) {
                    if (other.tagName === 'INPUT') other.value = '';
                    other.classList.remove('medal-gold', 'medal-silber', 'medal-bronze');
                    other.classList.add('medal-none');
                }
            });
        }

        // 3. Schwimm-UI
        const swimLabel = document.getElementById('swimming-label-' + epId);
        if (typeof data.has_swimming !== 'undefined' && swimLabel) {
            swimLabel.textContent = data.has_swimming ? 'Schwimmen: OK' : 'Schwimmen: Fehlt';
            swimLabel.className = `small fw-semibold ${data.has_swimming ? 'text-success' : 'text-danger'}`;
        }

        // 4. Punkte & Medaille
        const pts = document.querySelector('#total-points-' + epId + ' .pts-val');
        if (pts) pts.textContent = data.total_points;
        
        const medalBadge = row.querySelector('.js-medal-badge');
        if (medalBadge && data.final_medal) {
            medalBadge.textContent = data.final_medal;
            medalBadge.style.display = 'inline-block';
        }
    }

    function updateRequirementHints(select) {
        const parentTd = select.closest('td');
        if (!parentTd) return;
        const opt = select.options[select.selectedIndex];
        const labels = { b: '.js-val-b', s: '.js-val-s', g: '.js-val-g', u: '.js-unit-label' };
        const input = parentTd.querySelector('input[data-type="leistung"]');
        
        if (!opt || !opt.value) {
            if(input) input.disabled = true;
            return;
        }
        if(input) input.disabled = false;
        for (let key in labels) {
            const lab = parentTd.querySelector(labels[key]);
            if (lab) lab.textContent = opt.getAttribute('data-' + (key === 'u' ? 'unit' : (key === 'b' ? 'bronze' : (key === 's' ? 'silber' : 'gold')))) || '-';
        }
    }
});