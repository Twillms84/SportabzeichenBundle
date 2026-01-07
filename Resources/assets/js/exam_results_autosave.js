document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('autosave-form');
    if (!form) return;

    // Wir holen die Basis-URL (z.B. /sportabzeichen/exams/results/exam)
    // Im Twig solltest du data-discipline-route und data-result-route setzen
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
        const cell = el.closest('td');
        const row = el.closest('tr');
        
        const selectEl = cell.querySelector('select');
        const inputEl = cell.querySelector('input[type="text"]');

        // Bestimme die Route basierend auf dem Element-Typ
        const isSelect = (el.tagName === 'SELECT');
        const targetRoute = isSelect ? disciplineRoute : resultRoute;

        if (isSelect) {
            updateRequirementHints(el);
        }

        if (!selectEl.value || !epId) return;

        try {
            // UI Feedback
            inputEl.style.opacity = '0.5';

            const response = await fetch(targetRoute, {
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
            
            inputEl.style.opacity = '1';

            if (data.status === 'ok') {
                // 1. VERBANDS-LOGIK (UI Sperre)
                // Wenn der Controller sagt, es ist eine Verbandsdisziplin (3 Punkte fix)
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

                // 2. FARB-UPDATE
                const resultColor = data.stufe ? data.stufe.toLowerCase() : 'none'; 
                [selectEl, inputEl].forEach(element => {
                    element.classList.remove('medal-gold', 'medal-silber', 'medal-bronze', 'medal-none');
                    element.classList.add('medal-' + resultColor);
                });

                // 3. KATEGORIE BEREINIGEN (Nur bei Disziplin-Wechsel relevant)
                if (isSelect && kat) {
                    row.querySelectorAll(`[data-kategorie="${kat}"]`).forEach(otherEl => {
                        if (otherEl.closest('td') !== cell) {
                            if (otherEl.tagName === 'INPUT') otherEl.value = '';
                            otherEl.classList.remove('medal-gold', 'medal-silber', 'medal-bronze');
                            otherEl.classList.add('medal-none');
                        }
                    });
                }

                // 4. STATISTIK UPDATE (Punkte & Medaille)
                updateUIWidgets(epId, row, data);
            }
        } catch (e) {
            console.error('Fehler:', e);
            inputEl.style.backgroundColor = '#ffe6e6';
        }
    });

    // --- HELPER ---

    function updateUIWidgets(epId, row, data) {
        // Gesamtpunkte
        const totalBadge = document.getElementById('total-points-' + epId);
        if (totalBadge) {
            const valSpan = totalBadge.querySelector('.pts-val');
            if (valSpan) valSpan.textContent = data.total_points;
        }

        // Medaille
        const medalBadge = row.querySelector('.js-medal-badge');
        if (medalBadge) {
            const medal = data.final_medal ? String(data.final_medal).toLowerCase() : 'none';
            if (medal !== 'none') {
                medalBadge.style.display = 'inline-block';
                medalBadge.textContent = medal.charAt(0).toUpperCase() + medal.slice(1);
                medalBadge.className = 'badge badge-mini js-medal-badge'; // Reset
                
                const classes = { 'gold': 'bg-warning text-dark', 'silber': 'bg-secondary text-white', 'bronze': 'bg-danger text-white' };
                if (classes[medal]) medalBadge.className += ' ' + classes[medal];
            } else {
                medalBadge.style.display = 'none';
            }
        }
    }

    // 3. LIVE SCHWIMM-UPDATE (NEU)
        const swimIcon = row.querySelector('.js-swimming-status-' + epId) || row.querySelector('.js-swimming-status');
        if (swimIcon && typeof data.has_swimming !== 'undefined') {
            if (data.has_swimming) {
                // Anzeige als "Erfüllt" (z.B. grüner Haken oder Wellen-Icon)
                swimIcon.innerHTML = '<span class="text-success" title="Schwimmnachweis erbracht">OK</span>'; // Oder dein Icon-HTML
                swimIcon.classList.add('is-verified');
            } else {
                // Anzeige als "Fehlt"
                swimIcon.innerHTML = '<span class="text-danger" title="Schwimmnachweis fehlt">✘</span>';
                swimIcon.classList.remove('is-verified');
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