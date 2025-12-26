document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('autosave-form');
    if (!form) return;

    const saveRoute = form.getAttribute('data-global-route');
    const csrfToken = form.getAttribute('data-global-token');

    // 1. Hilfsfunktion: Medaillen-Farben am Element umschalten
    function updateMedalUI(cell, medal) {
        if (!cell) return;
        const elements = cell.querySelectorAll('select, input');
        const medalClass = (medal && medal !== 'none') ? 'medal-' + medal.toLowerCase() : '';
        
        elements.forEach(el => {
            // Alle alten Medaillen-Klassen entfernen
            el.classList.remove('medal-gold', 'medal-silber', 'medal-bronze');
            // Nur hinzufügen, wenn eine Medaille erreicht wurde
            if (medalClass) {
                el.classList.add(medalClass);
            }
        });
    }

    // 2. Hilfsfunktion: Schwimmnachweis in der Zeile live prüfen
    function updateSwimmingProofLive(epId) {
        const row = document.querySelector(`tr[data-ep-id="${epId}"]`);
        if (!row) return;

        const birthYear = parseInt(row.getAttribute('data-birth-year'));
        const examYear = 2025; // Kannst du auch dynamisch machen
        
        let hasProof = false;

        // Wir gehen durch alle Spalten der Kategorie
        row.querySelectorAll('.col-discipline').forEach(col => {
            const select = col.querySelector('select');
            const input = col.querySelector('input');
            if (!select || !input) return;

            const selectedOption = select.options[select.selectedIndex];
            const isSwimmingDisc = selectedOption.getAttribute('data-is-swimming') === '1';
            const points = parseInt(input.getAttribute('data-current-points')) || 0;

            // Wenn es eine Schwimmdisziplin ist UND Punkte (mind. Bronze) da sind
            if (isSwimmingDisc && points > 0) {
                hasProof = true;
            }
        });

        // Badge Container finden und Badge austauschen
        const badgeContainer = row.querySelector('.my-1');
        let validityYear = (examYear - birthYear < 18) ? (birthYear + 18) : (examYear + 5);
        
        const oldBadge = badgeContainer.querySelector('.badge.bg-success, .badge.bg-danger');
        if (oldBadge) {
            if (hasProof) {
                oldBadge.className = "badge bg-success";
                oldBadge.innerHTML = `🏊 bis ${validityYear}`;
                oldBadge.style.fontSize = "0.65rem";
            } else {
                oldBadge.className = "badge bg-danger";
                oldBadge.innerHTML = `❌ Schwimm-Nachweis`;
                oldBadge.style.fontSize = "0.65rem";
            }
        }
    }

    // 3. Hilfsfunktion: Gesamtpunkte
    function updateRowTotal(epId) {
        const badge = document.getElementById(`total-points-${epId}`);
        if (!badge) return;

        let total = 0;
        // Wir suchen NUR in der Zeile (tr) dieses Teilnehmers
        const row = document.querySelector(`tr[data-ep-id="${epId}"]`);
        if (!row) return;

        // Wir summieren nur Inputs, die NICHT zur Kategorie Schwimmen gehören
        row.querySelectorAll('input[data-type="leistung"]').forEach(input => {
            const cell = input.closest('td');
            const select = cell.querySelector('select');
            const categoryName = select ? select.options[select.selectedIndex].text : '';

            // Falls du Schwimmen im Namen hast, ignorieren wir die Punkte hier
            if (!categoryName.toLowerCase().includes('schwimm')) {
                total += parseInt(input.getAttribute('data-current-points')) || 0;
            }
        });

        badge.textContent = `${total} Pkt.`;
        
        // Optisches Feedback für Gold (>= 11), Silber (>= 8), Bronze (>= 4)
        badge.className = 'badge total-points-badge ' + 
            (total >= 11 ? 'bg-warning text-dark' : (total >= 8 ? 'bg-secondary' : (total >= 4 ? 'bg-danger' : 'bg-info text-dark')));
    }           

    // EVENT LISTENER
    form.addEventListener('change', async function(event) {
        const el = event.target;
        if (!el.hasAttribute('data-save')) return;

        const cell = el.closest('td');
        const selectEl = cell.querySelector('select');
        const inputEl = cell.querySelector('input[type="text"]');
        const epId = el.getAttribute('data-ep-id');

        // Falls Disziplin geleert wurde
        if (!selectEl.value) {
            updateMedalUI(cell, 'none');
            inputEl.setAttribute('data-current-points', '0');
            updateRowTotal(epId);
            updateSwimmingProofLive(epId);
            return;
        }

        try {
            const response = await fetch(saveRoute, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({
                    ep_id: epId,
                    discipline_id: selectEl.value,
                    leistung: inputEl.value.replace(',', '.'),
                    _token: csrfToken
                })
            });

            const data = await response.json();
            if (data.status === 'ok') {
                updateMedalUI(cell, data.medal);
                inputEl.setAttribute('data-current-points', data.points || 0);
                
                // LIVE UPDATE TRIGGER
                updateRowTotal(epId);
                updateSwimmingProofLive(epId); 
            }
        } catch (e) { console.error(e); }
    });
});