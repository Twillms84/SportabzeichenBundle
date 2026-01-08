document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('autosave-form');
    if (!form) return;

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
        const type = el.getAttribute('data-type'); // 'discipline', 'leistung' oder 'swimming'
        const kat = el.getAttribute('data-kategorie');
        const cell = el.closest('td');
        const row = el.closest('tr');
        
        // Bestimme Route: 'swimming' nutzt resultRoute oder eine eigene, 
        // hier nutzen wir resultRoute für den Switch-Sync
        const isSelect = (el.tagName === 'SELECT');
        const targetRoute = isSelect ? disciplineRoute : resultRoute;

        if (isSelect) {
            updateRequirementHints(el);
        }

        // Bei Leistungen oder Disziplin-Wechsel brauchen wir die Werte
        let payload = {
            ep_id: epId,
            _token: csrfToken
        };

        if (type === 'swimming') {
            payload.swimming = el.checked ? 1 : 0;
            payload.type = 'swimming_toggle'; 
        } else {
            const selectEl = cell.querySelector('select');
            const inputEl = cell.querySelector('input[type="text"]');
            if (!selectEl.value || !epId) return;
            
            payload.discipline_id = selectEl.value;
            payload.leistung = inputEl.value;
            
            // UI Feedback nur für Text-Inputs
            if (inputEl) inputEl.style.opacity = '0.5';
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

            if (!response.ok) throw new Error('Server-Fehler');
            const data = await response.json();
            
            // UI Feedback zurücksetzen
            const inputEl = cell ? cell.querySelector('input[type="text"]') : null;
            if (inputEl) inputEl.style.opacity = '1';

            if (data.status === 'ok' || data.success) {
                
                // 1. VERBANDS-LOGIK & FARBEN (nur wenn es kein reiner Schwimm-Switch-Klick war)
                if (type !== 'swimming' && cell) {
                    const selectEl = cell.querySelector('select');
                    const inputEl = cell.querySelector('input[type="text"]');

                    if (data.points === 3 && data.stufe === 'gold' && isSelect) {
                        const selectedOption = selectEl.options[selectEl.selectedIndex];
                        if (selectedOption.getAttribute('data-calc') === 'VERBAND') {
                            inputEl.value = ''; 
                            inputEl.disabled = true;
                            inputEl.placeholder = 'Verband';
                        }
                    } else if (isSelect) {
                        inputEl.disabled = false;
                        inputEl.placeholder = '';
                    }

                    const resultColor = data.stufe ? data.stufe.toLowerCase() : 'none'; 
                    [selectEl, inputEl].forEach(element => {
                        element.classList.remove('medal-gold', 'medal-silber', 'medal-bronze', 'medal-none');
                        element.classList.add('medal-' + resultColor);
                    });

                    // Kategorien bereinigen
                    if (isSelect && kat) {
                        row.querySelectorAll(`[data-kategorie="${kat}"]`).forEach(otherEl => {
                            if (otherEl.closest('td') !== cell) {
                                if (otherEl.tagName === 'INPUT') otherEl.value = '';
                                otherEl.classList.remove('medal-gold', 'medal-silber', 'medal-bronze');
                                otherEl.classList.add('medal-none');
                            }
                        });
                    }
                }

                // 2. GLOBALER UI UPDATE (Punkte, Medaille UND Schwimm-Status)
                updateUIWidgets(epId, row, data);
            }
        } catch (e) {
            console.error('Fehler:', e);
            if (el.type === 'text') el.style.backgroundColor = '#ffe6e6';
        }
    });

    // --- HELPER ---

    function updateUIWidgets(epId, row, data) {
        // A. Gesamtpunkte
        const totalBadge = document.getElementById('total-points-' + epId);
        if (totalBadge && data.total_points !== undefined) {
            const valSpan = totalBadge.querySelector('.pts-val');
            if (valSpan) valSpan.textContent = data.total_points;
        }

        // B. Medaille
        const medalBadge = row.querySelector('.js-medal-badge');
        if (medalBadge) {
            const medal = data.final_medal ? String(data.final_medal).toLowerCase() : 'none';
            if (medal !== 'none' && medal !== '') {
                medalBadge.style.display = 'inline-block';
                medalBadge.textContent = medal.charAt(0).toUpperCase() + medal.slice(1);
                medalBadge.className = 'badge badge-mini js-medal-badge'; 
                
                const classes = { 'gold': 'bg-warning text-dark', 'silber': 'bg-secondary text-white', 'bronze': 'bg-danger text-white' };
                if (classes[medal]) medalBadge.className += ' ' + classes[medal];
            } else {
                medalBadge.style.display = 'none';
            }
        }

        // C. NEU: Schwimm-Nachweis Live Update
        // Der Controller muss data.has_swimming (bool) zurückgeben
        if (data.has_swimming !== undefined) {
            const swimSwitch = document.getElementById('swim-switch-' + epId);
            const swimLabel = document.getElementById('swimming-label-' + epId);
            
            if (swimSwitch) {
                swimSwitch.checked = data.has_swimming;
                // NEU: Sperren wenn via Disziplin
                const metVia = data.swimming_met_via || '';
                if (metVia.startsWith('DISCIPLINE:')) {
                    swimSwitch.disabled = true;
                    swimSwitch.style.cursor = 'not-allowed';
                    if (swimLabel) swimLabel.title = "Nachweis durch Disziplin erbracht";
                } else {
                    swimSwitch.disabled = false;
                    swimSwitch.style.cursor = 'pointer';
                    if (swimLabel) swimLabel.title = "";
                }

                if (swimLabel) {
                    swimLabel.textContent = data.has_swimming ? 'Schwimmen: OK' : 'Schwimmen: Fehlt';
                    swimLabel.classList.toggle('text-success', data.has_swimming);
                    swimLabel.classList.toggle('text-danger', !data.has_swimming);
                }
            }
        }
    }

    function updateRequirementHints(select) {
        const parentTd = select.closest('td');
        const opt = select.options[select.selectedIndex];
        if (!parentTd || !opt) return;

        const labels = {
            b: parentTd.querySelector('.js-val-b'),
            s: parentTd.querySelector('.js-val-s'),
            g: parentTd.querySelector('.js-val-g'),
            unit: parentTd.querySelector('.js-unit-label')
        };
        const input = parentTd.querySelector('input[data-type="leistung"]');

        if (!opt.value) {
            Object.values(labels).forEach(l => l && (l.textContent = l === labels.unit ? '' : '-'));
            if(input) input.disabled = true;
            return;
        }

        if(input) input.disabled = (opt.getAttribute('data-calc') === 'VERBAND');
        
        if(labels.b) labels.b.textContent = opt.getAttribute('data-bronze') || '-';
        if(labels.s) labels.s.textContent = opt.getAttribute('data-silber') || '-';
        if(labels.g) labels.g.textContent = opt.getAttribute('data-gold') || '-';
        if(labels.unit) labels.unit.textContent = opt.getAttribute('data-unit') || '';
    }
});