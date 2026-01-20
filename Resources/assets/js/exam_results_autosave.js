document.addEventListener('DOMContentLoaded', function() {
    
    // =========================================================
    // 1. ANSICHT FILTER (Select2 Logic)
    // =========================================================
    const $viewSelector = $('#viewSelector');

    if ($viewSelector.length) {
        $viewSelector.on('change', function() {
            const selectedCategories = $(this).val() || [];
            
            $('#viewSelector option').each(function() {
                const category = $(this).val();
                const cells = document.querySelectorAll('.col-cat-' + category);
                
                if (selectedCategories.includes(category)) {
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
            } catch(e) { console.error(e); }
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

    // --- INITIALISIERUNG BEI SEITENAUFRUF ---
    document.querySelectorAll('.js-discipline-select').forEach(select => {
        updateRequirementHints(select);
        // WICHTIG: Beim Laden sofort prüfen, ob Felder gesperrt sein müssen
        checkVerbandInput(select);
    });

    // --- CHANGE LISTENER (Speichern & UI Logik) ---
    form.addEventListener('change', async function(event) {
        const el = event.target;
        // Wir reagieren nur auf Elemente mit data-save Attribut
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

        // A) SCHWIMM-NACHWEIS SELECT
        if (type === 'swimming_select') {        
            targetRoute = swimmingRoute;
            payload.discipline_id = el.value;
        } 
        // B) NORMALE DISZIPLINEN & LEISTUNGEN
        else {
            const selectEl = cell.querySelector('select');
            const inputEl = cell.querySelector('input[type="text"]');
            
            if (!selectEl || !selectEl.value || !epId) return;

            // Route wählen: Wurde das Select geändert oder das Textfeld?
            targetRoute = (el.tagName === 'SELECT') ? disciplineRoute : resultRoute;

            if (el.tagName === 'SELECT') {
                updateRequirementHints(el);
                // WICHTIG: Sofort UI anpassen (Sperren/Text setzen)
                checkVerbandInput(el); 
            }

            payload.discipline_id = selectEl.value;
            // Wert aus dem Input nehmen (kann Zahl sein oder "DLRG" String)
            payload.leistung = inputEl ? inputEl.value : '';

            // Visuelles Feedback: Input kurz ausgrauen während des Speicherns
            if (inputEl && !inputEl.disabled) inputEl.style.opacity = '0.5';
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
            
            const inputEl = cell ? cell.querySelector('input[type="text"]') : null;
            if (inputEl) inputEl.style.opacity = '1';

            if (data.status === 'ok' || data.success) {
                // 1. Farben & Inputs der aktuellen Zelle aktualisieren
                if (type !== 'swimming_select' && cell) {
                    handleDisciplineColors(data, cell, row, kat, el);
                }
                
                // 2. WICHTIG: Gesamtergebnis (Punkte/Medaille) sofort aktualisieren
                // Dies löst das Problem, dass Änderungen erst nach Refresh sichtbar waren.
                updateUIWidgets(epId, row, data);
            }
        } catch (e) {
            console.error('Fehler:', e);
            if (el.type === 'text') el.style.backgroundColor = '#ffe6e6'; // Rot bei Fehler
        }
    });

    // --- CLICK LISTENER (Schwimmen Löschen Button) ---
    document.addEventListener('click', async function(event) {
        const btn = event.target.closest('.btn-delete-swimming');
        if (!btn) return;

        event.preventDefault();
        const epId = btn.getAttribute('data-ep-id');
        
        if (!swimmingDeleteRoute || !epId) return;

        btn.style.opacity = '0.5';

        try {
            const response = await fetch(swimmingDeleteRoute, {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ ep_id: epId, _token: csrfToken })
            });

            const data = await response.json();

            if (data.status === 'ok' || data.success) {
                const row = btn.closest('tr');
                updateUIWidgets(epId, row, data);
            }
        } catch (e) {
            console.error('Fehler beim Löschen:', e);
            alert('Fehler beim Entfernen des Nachweises.');
        } finally {
            btn.style.opacity = '1';
        }
    });

    // =========================================================
    // 3. HELPER FUNCTIONS
    // =========================================================

    /**
     * Prüft, ob die gewählte Disziplin eine "Nur-Haken" Disziplin ist (Verband).
     * Wenn data-unit="NONE" ist, wird das Eingabefeld gesperrt und der Name eingetragen.
     */
    function checkVerbandInput(selectEl) {
        const cell = selectEl.closest('td');
        const inputEl = cell.querySelector('input[type="text"]');
        if (!inputEl) return;

        const selectedOption = selectEl.options[selectEl.selectedIndex];
        if (!selectedOption) return; // Falls "Bitte wählen" aktiv ist
        
        // Werte aus den data-Attributen holen
        const unit = selectedOption.getAttribute('data-unit'); 
        const verbandName = selectedOption.getAttribute('data-verband');

        // Prüfen auf NONE Varianten
        const isUnitNone = (unit === 'NONE' || unit === 'UNIT_NONE');

        if (isUnitNone) {
            // FALL 1: Verbandsabzeichen (z.B. DLRG) -> Input sperren
            // Wir schreiben den Namen (z.B. "DLRG") in das Feld.
            // Das Backend ignoriert diesen String und setzt 1.0 (Gold).
            inputEl.value = verbandName || 'Nachweis';
            inputEl.disabled = true;
            inputEl.classList.add('bg-light');
        } else {
            // FALL 2: Normale Disziplin oder Turnen (Unit = PIECES) -> Input frei
            inputEl.disabled = false;
            inputEl.classList.remove('bg-light');
            
            // Falls vorher ein automatischer Text drin stand, leeren wir das Feld,
            // damit der User eine Zahl eingeben kann.
            if (inputEl.value === verbandName || inputEl.value === 'Nachweis') {
                inputEl.value = '';
            }
        }
    }

    /**
     * Aktualisiert die Farben (Gold/Silber/Bronze) der Inputs basierend auf der Antwort.
     * Bereinigt auch Konflikte (andere Felder der gleichen Kategorie leeren).
     */
    function handleDisciplineColors(data, cell, row, kat, el) {
        const selectEl = cell.querySelector('select');
        const inputEl = cell.querySelector('input[type="text"]');
        const isSelect = (el.tagName === 'SELECT');

        // 1. Farben setzen
        const resultColor = data.stufe ? data.stufe.toLowerCase() : 'none'; 
        [selectEl, inputEl].forEach(element => {
            if(element) {
                element.classList.remove('medal-gold', 'medal-silber', 'medal-bronze', 'medal-none');
                element.classList.add('medal-' + resultColor);
            }
        });

        // 2. Sicherstellen, dass Verbands-Sperre erhalten bleibt
        if (isSelect) {
            checkVerbandInput(selectEl);
        }

        // 3. Konflikte bereinigen (nur EINE Disziplin pro Kategorie erlaubt)
        if (isSelect && kat) {
            row.querySelectorAll(`[data-kategorie="${kat}"]`).forEach(otherEl => {
                // Wenn es ein Element in einer anderen Zelle (gleiche Zeile, gleiche Kategorie) ist
                if (otherEl.closest('td') !== cell) {
                    
                    if (otherEl.tagName === 'INPUT') {
                        otherEl.value = ''; // Wert löschen
                        otherEl.disabled = false; // Wieder freigeben
                        otherEl.classList.remove('bg-light');
                    }
                    if (otherEl.tagName === 'SELECT') {
                        otherEl.value = ''; // Auswahl zurücksetzen
                    }
                    
                    // Farbe entfernen
                    otherEl.classList.remove('medal-gold', 'medal-silber', 'medal-bronze');
                    otherEl.classList.add('medal-none');
                }
            });
        }
    }

    /**
     * Aktualisiert die Gesamtpunkte, Medaille und den Schwimm-Status im DOM.
     * Benutzt die IDs, die im Twig-Template definiert wurden.
     */
    function updateUIWidgets(epId, row, data) {
        
        // A. Gesamtpunkte aktualisieren
        const totalBadge = document.getElementById('total-points-' + epId);
        if (totalBadge && data.total_points !== undefined) {
            totalBadge.textContent = data.total_points;
            
            // Kleiner visueller Effekt (kurz grün aufleuchten lassen optional)
            totalBadge.style.transition = 'color 0.5s';
            const oldColor = totalBadge.style.color;
            totalBadge.style.color = '#28a745'; // Success Green
            setTimeout(() => totalBadge.style.color = oldColor, 1000);
        }

        // B. Medaille (Gesamt) aktualisieren
        const medalBadge = document.getElementById('final-medal-' + epId);
        if (medalBadge) {
            const medal = data.final_medal ? String(data.final_medal).toLowerCase() : 'none';
            const labelEl = medalBadge.querySelector('.badge-label-small'); // Falls vorhanden

            // Reset Classes
            medalBadge.className = 'result-badge-box'; 
            // Hier deine CSS Klassen für die Badges anwenden:
            if (medal === 'gold') {
                medalBadge.classList.add('bg-gold');
                if(labelEl) labelEl.textContent = 'Gold';
                medalBadge.textContent = 'Gold'; // Fallback Text
            } else if (medal === 'silver' || medal === 'silber') {
                medalBadge.classList.add('bg-silver');
                if(labelEl) labelEl.textContent = 'Silber';
                medalBadge.textContent = 'Silber';
            } else if (medal === 'bronze') {
                medalBadge.classList.add('bg-bronze');
                if(labelEl) labelEl.textContent = 'Bronze';
                medalBadge.textContent = 'Bronze';
            } else {
                medalBadge.classList.add('medal-none');
                if(labelEl) labelEl.textContent = '-';
                medalBadge.textContent = '-';
            }
        }

        // C. Schwimm-Bereich aktualisieren (Badge vs. Dropdown)
        const wrapper = document.getElementById('swimming-wrapper-' + epId);
        if (wrapper) {
            const badgeCont = wrapper.querySelector('.swim-badge-container');
            const dropCont  = wrapper.querySelector('.swim-dropdown-container');
            const infoText  = wrapper.querySelector('.swim-info-text');
            const select    = wrapper.querySelector('select');

            const hasSwimming = (data.has_swimming === true);

            if (hasSwimming) {
                // Zeige Badge, verstecke Dropdown
                if(badgeCont) badgeCont.classList.remove('d-none');
                if(dropCont)  dropCont.classList.add('d-none');
                
                // Info Text aktualisieren (z.B. "Gültig bis 2025")
                if(infoText && (data.swimming_met_via || data.met_via)) {
                    infoText.textContent = data.swimming_met_via || data.met_via;
                    infoText.title = data.swimming_met_via || data.met_via;
                }
            } else {
                // Zeige Dropdown, verstecke Badge
                if(badgeCont) badgeCont.classList.add('d-none');
                if(dropCont)  dropCont.classList.remove('d-none');
                if(select)    select.value = ""; // Reset Auswahl
            }
        }
    }

    /**
     * Aktualisiert die Hinweise (Bronze/Silber/Gold Werte) unter dem Input.
     */
    function updateRequirementHints(select) {
        const parentTd = select.closest('td');
        const opt = select.options[select.selectedIndex];
        if (!parentTd || !opt) return;

        // Versuchen, die Elemente zu finden (Anpassung an dein HTML nötig)
        const labels = {
            b: parentTd.querySelector('.req-val-b') || parentTd.querySelector('.js-val-b'),
            s: parentTd.querySelector('.req-val-s') || parentTd.querySelector('.js-val-s'),
            g: parentTd.querySelector('.req-val-g') || parentTd.querySelector('.js-val-g'),
            unit: parentTd.querySelector('.req-unit') || parentTd.querySelector('.input-group-text-unit')
        };

        const input = parentTd.querySelector('input[data-type="leistung"]');

        // Wenn "Bitte wählen..."
        if (!opt.value) {
            Object.values(labels).forEach(l => l && (l.textContent = (l === labels.unit ? '' : '-')));
            if(input) input.disabled = true;
            return;
        }

        // Werte aus data-Attributen lesen
        if(labels.b) labels.b.textContent = opt.getAttribute('data-bronze') || '-';
        if(labels.s) labels.s.textContent = opt.getAttribute('data-silber') || '-';
        if(labels.g) labels.g.textContent = opt.getAttribute('data-gold') || '-';
        
        if(labels.unit) {
            // Einheit anzeigen, außer bei NONE
            const u = opt.getAttribute('data-unit');
            labels.unit.textContent = (u === 'NONE' || u === 'UNIT_NONE') ? '' : (u || '');
        }
    }
});