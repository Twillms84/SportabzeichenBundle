document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('autosave-form');
    if (!form) return;

    // Routen aus dem Formular-Tag lesen
    const disciplineRoute = form.getAttribute('data-discipline-route'); 
    const resultRoute = form.getAttribute('data-result-route');
    // WICHTIG: Stelle sicher, dass data-swimming-route im HTML gesetzt ist!
    const swimmingRoute = form.getAttribute('data-swimming-route'); 
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
        const type = el.getAttribute('data-type'); // 'discipline', 'leistung', 'swimming_select'
        const kat = el.getAttribute('data-kategorie');
        const cell = el.closest('td');
        const row = el.closest('tr');
        
        let targetRoute = '';
        let payload = {
            ep_id: epId,
            _token: csrfToken
        };

        // A) LOGIK FÜR SCHWIMM-NACHWEIS (Dropdown)
        if (type === 'swimming_select') {
            if (!el.value) return; // Nichts tun, wenn "Bitte wählen"
            
            targetRoute = swimmingRoute;
            payload.discipline_id = el.value;
            // payload.type = 'swimming_manual'; // Nicht zwingend nötig, da eigene Route
        } 
        // B) LOGIK FÜR NORMALE DISZIPLINEN / LEISTUNGEN
        else {
            const selectEl = cell.querySelector('select');
            const inputEl = cell.querySelector('input[type="text"]');
            
            if (!selectEl || !selectEl.value || !epId) return;

            // Route bestimmen
            targetRoute = (el.tagName === 'SELECT') ? disciplineRoute : resultRoute;

            if (el.tagName === 'SELECT') {
                updateRequirementHints(el);
            }

            payload.discipline_id = selectEl.value;
            payload.leistung = inputEl ? inputEl.value : '';

            // UI Feedback (nur bei Textfeldern sinnvoll)
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
                
                // 1. VERBANDS-LOGIK & FARBEN (Nur für Disziplinen, nicht Schwimm-Dropdown)
                if (type !== 'swimming_select' && cell) {
                    handleDisciplineColors(data, cell, row, kat, el);
                }

                // 2. GLOBALER UI UPDATE (Punkte, Medaille, Schwimm-Badge)
                updateUIWidgets(epId, row, data);
            }
        } catch (e) {
            console.error('Fehler:', e);
            if (el.type === 'text') el.style.backgroundColor = '#ffe6e6';
        }
    });

    // --- HELPER FUNCTIONS ---

    // Ausgelagerte Farb-Logik für bessere Lesbarkeit
    function handleDisciplineColors(data, cell, row, kat, el) {
        const selectEl = cell.querySelector('select');
        const inputEl = cell.querySelector('input[type="text"]');
        const isSelect = (el.tagName === 'SELECT');

        if (data.points === 3 && data.stufe === 'gold' && isSelect) {
            const selectedOption = selectEl.options[selectEl.selectedIndex];
            if (selectedOption.getAttribute('data-calc') === 'VERBAND') {
                if(inputEl) {
                    inputEl.value = ''; 
                    inputEl.disabled = true;
                    inputEl.placeholder = 'Verband';
                }
            }
        } else if (isSelect && inputEl) {
            inputEl.disabled = false;
            inputEl.placeholder = '';
        }

        const resultColor = data.stufe ? data.stufe.toLowerCase() : 'none'; 
        [selectEl, inputEl].forEach(element => {
            if(element) {
                element.classList.remove('medal-gold', 'medal-silber', 'medal-bronze', 'medal-none');
                element.classList.add('medal-' + resultColor);
            }
        });

        // Andere Felder der gleichen Kategorie zurücksetzen
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

    function updateUIWidgets(epId, row, data) {
        // A. Gesamtpunkte Update
        const totalBadge = document.getElementById('total-points-' + epId);
        if (totalBadge && data.total_points !== undefined) {
            const valSpan = totalBadge.querySelector('.pts-val');
            if (valSpan) valSpan.textContent = data.total_points;
        }

        // B. Medaille Update
        const medalBadge = row.querySelector('.js-medal-badge');
        if (medalBadge) {
            const medal = data.final_medal ? String(data.final_medal).toLowerCase() : 'none';
            if (medal !== 'none' && medal !== '') {
                medalBadge.classList.remove('d-none');
                medalBadge.style.display = 'inline-block';
                medalBadge.textContent = medal.charAt(0).toUpperCase() + medal.slice(1);
                
                medalBadge.className = 'badge badge-mini js-medal-badge'; 
                const classes = { 'gold': 'bg-warning text-dark', 'silber': 'bg-secondary text-white', 'bronze': 'bg-danger text-white' };
                if (classes[medal]) medalBadge.className += ' ' + classes[medal];
            } else {
                medalBadge.style.display = 'none';
                medalBadge.classList.add('d-none');
            }
        }

        // C. SCHWIMM-BEREICH UPDATE (Live-Logik)
        const wrapper = document.getElementById('swimming-wrapper-' + epId);
        
        // Debugging (kannst du später entfernen)
        console.log('Update UI für EP:', epId, 'Swimming:', data.has_swimming, 'Points:', data.total_points);

        if (wrapper) {
            // FALL 1: Schwimmnachweis VORHANDEN (frisch gespeichert oder schon da)
            if (data.has_swimming === true) {
                const metVia = data.swimming_met_via || 'Nachweis erbracht';
                let expiryStr = '';
                if (data.swimming_expiry) {
                    const parts = data.swimming_expiry.split('-');
                    if(parts.length === 3) expiryStr = ` (bis ${parts[2]}.${parts[1]}.${parts[0]})`;
                }

                wrapper.innerHTML = `
                    <div class="text-success small" data-status="done">
                        <i class="fa fa-check-circle"></i> <strong>Schwimmen: OK</strong><br>
                        <span class="text-muted" style="font-size: 0.75rem;">
                            ${metVia}${expiryStr}
                        </span>
                    </div>
                `;
            } 
            // FALL 2: KEIN Nachweis -> Entscheiden ob Schloss oder Dropdown
            else {
                // Prüfen ob wir das HTML für Dropdown/Schloss noch haben oder wiederherstellen müssen
                // Falls vorher "Schwimmen OK" da war und wir jetzt zurückfallen (Löschung), müssen wir das HTML neu bauen
                if (!wrapper.querySelector('.swim-dropdown-container')) {
                    wrapper.innerHTML = `
                        <div class="swimming-action-area" data-ep-id="${epId}">
                            <div class="swim-dropdown-container d-none">
                                <select class="form-select form-select-sm mt-1 swimming-select-compact" 
                                        style="background-color: #fff3cd; border-color: #ffecb5;"
                                        data-save data-type="swimming_select" data-ep-id="${epId}">
                                    <option value="">-- Nachweis wählen --</option>
                                    </select>
                            </div>
                            <div class="swim-locked-container text-muted small d-none">
                                <i class="fa fa-lock"></i> Schwimmen (Punkte fehlen)
                            </div>
                        </div>
                    `;
                    // Hinweis: Das dynamische Wiederherstellen des Selects ist komplex, da wir die <options> brauchen.
                    // Wenn Fall 2 (Löschen) eintritt, ist ein Reload oft sicherer, wenn du die Optionen nicht im JS hast.
                }

                const dropdownContainer = wrapper.querySelector('.swim-dropdown-container');
                const lockContainer = wrapper.querySelector('.swim-locked-container');
                
                // Punkte sicher als Zahl
                const points = parseInt(data.total_points, 10) || 0;

                if (dropdownContainer && lockContainer) {
                    if (points >= 4) {
                        dropdownContainer.classList.remove('d-none');
                        lockContainer.classList.add('d-none');
                    } else {
                        dropdownContainer.classList.add('d-none');
                        lockContainer.classList.remove('d-none');
                    }
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