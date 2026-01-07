document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('autosave-form');
    if (!form) return;

    const saveRoute = form.getAttribute('data-global-route');
    const csrfToken = form.getAttribute('data-global-token');

    // --- 1. INITIALISIERUNG: Anforderungen anzeigen ---
    document.querySelectorAll('.js-discipline-select').forEach(select => {
        updateRequirementHints(select);
    });

    // --- 2. EVENT LISTENER: Zentraler Speicher-Prozess ---
    form.addEventListener('change', async function(event) {
        const el = event.target;
        
        // Nur reagieren, wenn das Element das data-save Attribut hat
        if (!el.hasAttribute('data-save')) return;

        const epId = el.getAttribute('data-ep-id');
        const kat = el.getAttribute('data-kategorie');
        const row = el.closest('tr');
        
        // Unterscheidung: Disziplin-Eingabe oder Schwimm-Nachweis?
        const isSwimming = el.getAttribute('data-type') === 'swimming';
        
        // Hilfsvariablen für Disziplinen
        let selectEl = null;
        let inputEl = null;
        let payload = {
            ep_id: epId,
            _token: csrfToken
        };

        if (isSwimming) {
            // Logik für den manuellen Schwimm-Switch
            payload.type = 'swimming';
            payload.leistung = el.checked ? 'on' : 'off';
        } else {
            // Logik für Standard-Leistungen
            const cell = el.closest('td');
            if (!cell) return;
            
            selectEl = cell.querySelector('select');
            inputEl = cell.querySelector('input[type="text"]');

            if (el.tagName === 'SELECT') {
                updateRequirementHints(el);
            }

            // Validierung: Ohne gewählte Disziplin kein Save
            if (!selectEl || !selectEl.value || !epId) return;

            payload.discipline_id = selectEl.value;
            payload.leistung = inputEl ? inputEl.value : '';
            
            // UI Feedback: Optisches Speichern-Signal
            if (inputEl) inputEl.style.opacity = '0.5';
        }

        // --- AJAX REQUEST ---
        try {
            const response = await fetch(saveRoute, {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(payload)
            });

            if (!response.ok) throw new Error('Server-Fehler 405 oder Ähnliches');

            const data = await response.json();
            
            if (inputEl) inputEl.style.opacity = '1';

            if (data.status === 'ok') {
                
                // 1. ZELLEN-FARBE UPDATE (Nur bei Disziplinen)
                if (!isSwimming && selectEl && inputEl) {
                    const resultColor = data.stufe ? data.stufe.toLowerCase() : 'none'; 
                    [selectEl, inputEl].forEach(element => {
                        element.classList.remove('medal-gold', 'medal-silber', 'medal-bronze', 'medal-none');
                        element.classList.add('medal-' + resultColor);
                    });
                }

                // 2. ANDERE ZELLEN DER GLEICHEN KATEGORIE LEEREN (Nur bei Disziplinen)
                if (!isSwimming && kat && row) {
                    const groupInputs = row.querySelectorAll(`[data-kategorie="${kat}"]`);
                    groupInputs.forEach(otherEl => {
                        const otherCell = otherEl.closest('td');
                        // Aktuelle Zelle ignorieren
                        if (otherEl !== el && (!selectEl || otherEl !== selectEl)) {
                            if (otherEl.tagName === 'INPUT') otherEl.value = '';
                            otherEl.classList.remove('medal-gold', 'medal-silber', 'medal-bronze');
                            otherEl.classList.add('medal-none');
                        }
                    });
                }
                
                // 3. LIVE SCHWIMM-UPDATE (Switch, Label & Datum)
                const swimSwitch = document.getElementById('swim-switch-' + epId);
                const swimLabel = document.getElementById('swimming-label-' + epId);
                const swimExpiry = document.getElementById('swimming-expiry-' + epId);

                if (typeof data.has_swimming !== 'undefined') {
                    if (swimSwitch) swimSwitch.checked = data.has_swimming;
                    
                    if (swimLabel) {
                        swimLabel.textContent = data.has_swimming ? 'Schwimmen: OK' : 'Schwimmen: Fehlt';
                        swimLabel.className = `small fw-semibold ${data.has_swimming ? 'text-success' : 'text-danger'}`;
                    }
                    
                    if (swimExpiry) {
                        swimExpiry.style.display = data.has_swimming ? 'block' : 'none';
                    }
                }

                // 4. GESAMTPUNKTE UPDATE
                const totalBadge = document.getElementById('total-points-' + epId);
                if (totalBadge) {
                    const valSpan = totalBadge.querySelector('.pts-val');
                    if (valSpan) valSpan.textContent = data.total_points;
                }

                // 5. MEDAILLEN BADGE UPDATE (Gesamtergebnis)
                const medalBadge = row.querySelector('.js-medal-badge');
                if (medalBadge) {
                    const rawMedalName = data.final_medal ? String(data.final_medal) : ''; 
                    const lowerMedalName = rawMedalName.toLowerCase();

                    if (lowerMedalName && lowerMedalName !== 'none') {
                        medalBadge.style.display = 'inline-block';
                        medalBadge.textContent = rawMedalName.charAt(0).toUpperCase() + rawMedalName.slice(1);
                        medalBadge.className = 'badge badge-mini js-medal-badge'; // Reset classes
                        
                        if (lowerMedalName === 'gold') medalBadge.classList.add('bg-warning', 'text-dark');
                        else if (lowerMedalName === 'silber') medalBadge.classList.add('bg-secondary', 'text-white');
                        else if (lowerMedalName === 'bronze') medalBadge.classList.add('bg-danger', 'text-white');
                    } else {
                        medalBadge.style.display = 'none';
                    }
                }
            }
        } catch (e) {
            console.error('Fehler beim Autosave:', e);
            if (inputEl) inputEl.style.backgroundColor = '#ffe6e6';
        }
    });

    // --- HELPER FUNKTIONEN ---

    function updateRequirementHints(select) {
        const parentTd = select.closest('td');
        if (!parentTd) return;

        const selectedOption = select.options[select.selectedIndex];
        const labelB = parentTd.querySelector('.js-val-b');
        const labelS = parentTd.querySelector('.js-val-s');
        const labelG = parentTd.querySelector('.js-val-g');
        const unitLabel = parentTd.querySelector('.js-unit-label');
        const input = parentTd.querySelector('input[data-type="leistung"]');

        if (!selectedOption || !selectedOption.value) {
            if(labelB) labelB.textContent = '-';
            if(labelS) labelS.textContent = '-';
            if(labelG) labelG.textContent = '-';
            if(unitLabel) unitLabel.textContent = '';
            if(input) input.disabled = true;
            return;
        }

        if(input) input.disabled = false;

        const b = selectedOption.getAttribute('data-bronze') || '-';
        const s = selectedOption.getAttribute('data-silber') || '-';
        const g = selectedOption.getAttribute('data-gold') || '-';
        const unit = selectedOption.getAttribute('data-unit') || '';

        if(labelB) labelB.textContent = b;
        if(labelS) labelS.textContent = s;
        if(labelG) labelG.textContent = g;
        if(unitLabel) unitLabel.textContent = unit;
    }
});