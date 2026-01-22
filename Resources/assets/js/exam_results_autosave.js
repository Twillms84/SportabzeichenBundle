document.addEventListener('DOMContentLoaded', function() {
    
    // =========================================================
    // 1. ANSICHT FILTER
    // =========================================================
    const $viewSelector = $('#viewSelector'); 
    
    if ($viewSelector.length) {
        $viewSelector.on('change', function() {
            const selectedCategories = $(this).val() || [];
            $('#viewSelector option').each(function() {
                const category = $(this).val();
                const cells = document.querySelectorAll('.col-cat-' + category);
                if (selectedCategories.length === 0 || selectedCategories.includes(category)) {
                    cells.forEach(cell => cell.classList.remove('col-hidden'));
                } else {
                    cells.forEach(cell => cell.classList.add('col-hidden'));
                }
            });
            localStorage.setItem('sportabzeichen_view_selection', JSON.stringify(selectedCategories));
        });

        const savedSelection = localStorage.getItem('sportabzeichen_view_selection');
        if (savedSelection) {
            try {
                $viewSelector.val(JSON.parse(savedSelection)).trigger('change');
            } catch(e) { console.error('Storage Error', e); }
        }
    }

    // =========================================================
    // 2. AUTOSAVE FORMULAR LOGIK
    // =========================================================
    const form = document.getElementById('autosave-form');
    
    if (!form) return; 

    const disciplineRoute = form.getAttribute('data-discipline-route'); 
    const resultRoute = form.getAttribute('data-result-route');
    const swimmingRoute = form.getAttribute('data-swimming-route'); 
    const swimmingDeleteRoute = form.getAttribute('data-swimming-delete-route');
    const csrfToken = form.getAttribute('data-global-token');

    // --- INITIALISIERUNG ---
    document.querySelectorAll('.js-discipline-select').forEach(select => {
        updateRequirementHints(select);
        checkVerbandInput(select);
    });

    // --- CHANGE LISTENER ---
    form.addEventListener('change', async function(event) {
        const el = event.target;
        if (!el.hasAttribute('data-save')) return;

        const epId = el.getAttribute('data-ep-id');
        const type = el.getAttribute('data-type'); 
        const kat = el.getAttribute('data-kategorie');
        const row = el.closest('tr'); // Wir referenzieren hier die ganze Zeile
        const cell = el.closest('td');

        let targetRoute = '';
        let payload = { ep_id: epId, _token: csrfToken };

        // Suche Elemente im Kontext der Zeile (sicherer als Cell) oder Cell
        // (Select ist immer das Element, das wir gerade geändert haben oder suchen)
        const selectEl = row.querySelector(`select[data-ep-id="${epId}"]`);
        const inputEl  = row.querySelector(`input[data-type="leistung"][data-ep-id="${epId}"]`);

        // A) SCHWIMM-NACHWEIS
        if (type === 'swimming_select') {        
            targetRoute = swimmingRoute;
            payload.discipline_id = el.value;
        } 
        // B) NORMALE DISZIPLINEN
        else {
            if (!selectEl || !selectEl.value || !epId) return;

            targetRoute = (el.tagName === 'SELECT') ? disciplineRoute : resultRoute;

            if (el.tagName === 'SELECT') {
                // Sofortiges Update der UI (Client-Side Prediction aus Select-Attributen)
                updateRequirementHints(el); 
                checkVerbandInput(el);
            }

            payload.discipline_id = selectEl.value;
            payload.leistung = inputEl ? inputEl.value : '';

            if (inputEl && !inputEl.disabled) {
                inputEl.setAttribute('data-temp-disabled', 'true');
                inputEl.disabled = true;
                inputEl.style.opacity = '0.6';
            }
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

            const data = await response.json();
            
            if (inputEl && inputEl.hasAttribute('data-temp-disabled')) {
                inputEl.disabled = false;
                inputEl.removeAttribute('data-temp-disabled');
                inputEl.style.opacity = '1';
                inputEl.focus(); 
            }

            if (data.status === 'ok' || data.success) {
                // 1. Visuelles Update (Farben)
                if (type !== 'swimming_select' && selectEl) {
                    // Wir übergeben hier selectEl, um sicherzustellen, dass wir das richtige Element haben
                    handleDisciplineColors(data, row, kat, selectEl);

                    // --- 1.1 REQUIREMENTS UPDATE VOM SERVER ---
                    if (data.new_requirements) {
                        const req = data.new_requirements;
                        
                        // Wir suchen in der ganzen Zeile nach den Badges
                        const badgeB = row.querySelector('.req-val-b, .js-val-b');
                        const badgeS = row.querySelector('.req-val-s, .js-val-s');
                        const badgeG = row.querySelector('.req-val-g, .js-val-g');
                        
                        // Debugging: Falls Elemente fehlen, Warnung in Konsole
                        if (!badgeB && req.bronze) console.warn('Badge-Elemente in Zeile nicht gefunden! HTML prüfen.');

                        if(badgeB) badgeB.textContent = req.bronze;
                        if(badgeS) badgeS.textContent = req.silber;
                        if(badgeG) badgeG.textContent = req.gold;
                    }
                }
                
                // 2. Widgets Update
                updateUIWidgets(epId, row, data);
            } else {
                throw new Error(data.message || 'Fehler beim Speichern');
            }
        } catch (e) {
            console.error('Fehler:', e);
            if (inputEl) {
                inputEl.disabled = false;
                inputEl.style.backgroundColor = '#ffe6e6';
            }
        }
    });

    // --- CLICK LISTENER (Schwimmen) ---
    document.addEventListener('click', async function(event) {
        const btn = event.target.closest('.btn-delete-swimming');
        if (!btn) return;
        event.preventDefault();
        
        const epId = btn.getAttribute('data-ep-id');
        if (!swimmingDeleteRoute || !epId) return;
        if(!confirm('Schwimmnachweis wirklich entfernen?')) return;

        btn.style.opacity = '0.5'; 
        btn.disabled = true;

        try {
            const response = await fetch(swimmingDeleteRoute, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ ep_id: epId, _token: csrfToken })
            });
            const data = await response.json();
            if (data.status === 'ok' || data.success) {
                const row = btn.closest('tr'); 
                updateUIWidgets(epId, row, data);
            } else {
                alert('Fehler: ' + (data.message || 'Error'));
            }
        } catch (e) { console.error(e); } 
        finally { btn.style.opacity = '1'; btn.disabled = false; }
    });

    // =========================================================
    // 3. HELPER FUNCTIONS
    // =========================================================

    function checkVerbandInput(selectEl) {
        const row = selectEl.closest('tr');
        // Suche Input spezifisch für diese Zeile
        const inputEl = row.querySelector('input[data-type="leistung"]');
        if (!inputEl) return;

        const selectedOption = selectEl.options[selectEl.selectedIndex];
        if (!selectedOption || !selectedOption.value) {
            inputEl.disabled = true;
            inputEl.value = '';
            inputEl.classList.remove('bg-light', 'medal-gold', 'medal-silber', 'medal-bronze', 'medal-none');
            selectEl.classList.remove('medal-gold', 'medal-silber', 'medal-bronze', 'medal-none');
            return;
        }
        
        const unit = selectedOption.getAttribute('data-unit'); 
        const implicitPoints = parseInt(selectedOption.getAttribute('data-implicit-points') || 0);
        const isVerband = (unit === 'NONE' || unit === 'UNIT_NONE' || implicitPoints > 0);

        if (isVerband) {
            inputEl.value = ''; 
            inputEl.setAttribute('placeholder', '✓');
            inputEl.disabled = true; 
            inputEl.classList.add('bg-light');
            inputEl.classList.remove('medal-silber', 'medal-bronze', 'medal-none');
            inputEl.classList.add('medal-gold');
            selectEl.classList.remove('medal-silber', 'medal-bronze', 'medal-none');
            selectEl.classList.add('medal-gold');
        } else {
            inputEl.disabled = false;
            inputEl.setAttribute('placeholder', '');
            inputEl.classList.remove('bg-light');
            if(inputEl.value === '') {
                inputEl.classList.remove('medal-gold', 'medal-silber', 'medal-bronze');
                inputEl.classList.add('medal-none');
                selectEl.classList.remove('medal-gold', 'medal-silber', 'medal-bronze');
                selectEl.classList.add('medal-none');
            }
        }
    }

    function handleDisciplineColors(data, row, kat, el) {
        // Wir nutzen hier 'row', um Input und Select sicher zu finden
        const selectEl = row.querySelector('select[data-type="discipline"]');
        const inputEl = row.querySelector('input[data-type="leistung"]');
        
        const resultColor = data.stufe ? data.stufe.toLowerCase() : 'none'; 
        
        if(selectEl) {
            selectEl.classList.remove('medal-gold', 'medal-silber', 'medal-bronze', 'medal-none');
            selectEl.classList.add('medal-' + resultColor);
        }
        if(inputEl) {
            inputEl.classList.remove('medal-gold', 'medal-silber', 'medal-bronze', 'medal-none');
            inputEl.classList.add('medal-' + resultColor);
        }

        const isSelect = (el.tagName === 'SELECT');
        if (isSelect && selectEl) {
            checkVerbandInput(selectEl);
        }

        // Andere Felder der gleichen Kategorie leeren
        if (isSelect && kat) {
            row.closest('table').querySelectorAll(`tr[data-kategorie-row="${kat}"]`).forEach(otherRow => {
                 // Logik um andere Zeilen zu resetten, falls nötig (abhängig von Layout)
                 // Hier belassen wir es bei der ursprünglichen Logik, suchen aber globaler
                 const otherSelect = otherRow.querySelector('select');
                 // ... (Vereinfacht, da Struktur unbekannt. Bleibt wie zuvor meist row-basiert)
            });
            
            // Backup: Falls alle inputs in einer row sind:
             row.querySelectorAll(`[data-kategorie="${kat}"]`).forEach(otherEl => {
                if (otherEl === selectEl || otherEl === inputEl) return; // Skip self

                if (otherEl.tagName === 'INPUT') {
                    otherEl.value = '';
                    otherEl.disabled = true; 
                    otherEl.classList.remove('bg-light', 'medal-gold', 'medal-silber', 'medal-bronze');
                    otherEl.classList.add('medal-none');
                }
                if (otherEl.tagName === 'SELECT') {
                    otherEl.value = ''; 
                    otherEl.classList.remove('medal-gold', 'medal-silber', 'medal-bronze');
                    otherEl.classList.add('medal-none');
                    updateRequirementHints(otherEl);
                }
            });
        }
    }

    function updateUIWidgets(epId, row, data) {
        const totalBadge = document.getElementById('total-points-' + epId);
        if (totalBadge && data.total !== undefined) {
            totalBadge.textContent = data.total;
            totalBadge.classList.add('text-success');
            setTimeout(() => totalBadge.classList.remove('text-success'), 1000);
        }

        const medalBadge = document.getElementById('final-medal-' + epId);
        if (medalBadge) {
            const medal = data.medal ? String(data.medal).toLowerCase() : 'none';
            const labelSpan = medalBadge.querySelector('.js-medal-label');
            medalBadge.classList.remove('bg-warning', 'bg-secondary', 'bg-danger', 'bg-light', 'bg-opacity-25', 'border-warning', 'border-secondary', 'border-danger', 'text-muted');
            
            let labelText = '-';
            if (medal === 'gold') {
                medalBadge.classList.add('bg-warning', 'bg-opacity-25', 'border-warning');
                labelText = 'Gold';
            } else if (medal === 'silver' || medal === 'silber') {
                medalBadge.classList.add('bg-secondary', 'bg-opacity-25', 'border-secondary');
                labelText = 'Silber';
            } else if (medal === 'bronze') {
                medalBadge.classList.add('bg-danger', 'bg-opacity-25', 'border-danger');
                labelText = 'Bronze';
            } else {
                medalBadge.classList.add('bg-light', 'text-muted', 'border');
            }
            if(labelSpan) labelSpan.textContent = labelText;
        }

        const wrapper = document.getElementById('swimming-wrapper-' + epId);
        const swimIcon = document.getElementById('swim-icon-' + epId);
        const hasSwimming = (data.has_swimming === true);

        if(swimIcon) {
            if(hasSwimming) {
                swimIcon.classList.remove('text-danger', 'opacity-50');
                swimIcon.classList.add('text-success');
            } else {
                swimIcon.classList.remove('text-success');
                swimIcon.classList.add('text-danger', 'opacity-50');
            }
        }

        if (wrapper) {
            const badgeCont = wrapper.querySelector('.swim-badge-container');
            const dropCont  = wrapper.querySelector('.swim-dropdown-container');
            const infoText  = wrapper.querySelector('.swim-info-text');
            const select    = wrapper.querySelector('select');

            if (hasSwimming) {
                if(badgeCont) badgeCont.classList.remove('d-none');
                if(dropCont)  dropCont.classList.add('d-none');
                if(infoText) {
                    const txt = data.swimming_met_via || data.met_via || 'Erledigt';
                    infoText.textContent = txt;
                    infoText.title = txt + (data.expiry ? ' (bis ' + data.expiry + ')' : '');
                }
            } else {
                if(badgeCont) badgeCont.classList.add('d-none');
                if(dropCont)  dropCont.classList.remove('d-none');
                if(select)    select.value = ""; 
            }
        }
    }

    function updateRequirementHints(select) {
        // --- ÄNDERUNG: Suche in der ganzen Zeile (TR), nicht nur in der Zelle (TD) ---
        const row = select.closest('tr');
        if (!row) return;

        const opt = select.options[select.selectedIndex];
        
        // Selektoren für die Badges innerhalb der Zeile
        const labels = {
            b: row.querySelector('.req-val-b, .js-val-b'),
            s: row.querySelector('.req-val-s, .js-val-s'),
            g: row.querySelector('.req-val-g, .js-val-g'),
            unit: row.querySelector('.req-unit, .js-unit-label') 
        };
        
        // Input Referenz (in der gleichen Zeile)
        const input = row.querySelector('input[data-type="leistung"]');

        if (!opt || !opt.value) {
            Object.values(labels).forEach(l => l && (l.textContent = (l === labels.unit ? '' : '-')));
            if(input) input.disabled = true;
            return;
        }

        const prettyUnit = opt.getAttribute('data-unit-label') || '';
        const implicitPoints = opt.getAttribute('data-implicit-points');
        const unitRaw = opt.getAttribute('data-unit');
        const isVerband = (unitRaw === 'NONE' || unitRaw === 'UNIT_NONE' || implicitPoints > 0);

        if (isVerband) {
            if(labels.b) labels.b.textContent = '';
            if(labels.s) labels.s.textContent = '';
            if(labels.g) labels.g.textContent = '';
            if(labels.unit) labels.unit.textContent = '';
        } else {
            if(labels.b) labels.b.textContent = opt.getAttribute('data-bronze') || '-';
            if(labels.s) labels.s.textContent = opt.getAttribute('data-silber') || '-';
            if(labels.g) labels.g.textContent = opt.getAttribute('data-gold') || '-';
            
            if(labels.unit) {
                labels.unit.textContent = prettyUnit;
            }
        }
    }
});