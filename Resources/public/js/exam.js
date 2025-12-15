document.addEventListener('DOMContentLoaded', () => {
    const table = document.querySelector('table');
    const saveBtn = document.querySelector('#save-btn');

    // Punkteberechnung + Farbhinterlegung
    function calculatePoints(row) {
        let total = 0;
        row.querySelectorAll('.result-input').forEach(input => {
            const val = input.value.toLowerCase();
            input.classList.remove('bg-warning', 'bg-secondary', 'bg-success', 'text-white', 'text-dark');

            if (val.includes('gold')) {
                total += 3;
                input.classList.add('bg-warning', 'text-dark');
            } else if (val.includes('silber')) {
                total += 2;
                input.classList.add('bg-secondary', 'text-white');
            } else if (val.includes('bronze')) {
                total += 1;
                input.classList.add('bg-success', 'text-white');
            }
        });
        row.querySelector('.points').textContent = total;
    }

    // Eventlistener für Live-Berechnung
    table.addEventListener('input', e => {
        if (e.target.classList.contains('result-input')) {
            const row = e.target.closest('tr');
            calculatePoints(row);
        }
    });

    // Daten speichern via Fetch
    saveBtn.addEventListener('click', async () => {
        const rows = document.querySelectorAll('tbody tr[data-id]');
        const payload = [];

        rows.forEach(row => {
            const participant_id = row.dataset.id;
            const klasse = row.querySelector('.klasse-select').value;
            const swim = row.querySelector('.swim-check').checked;

            const entry = {
                participant_id,
                klasse,
                schwimmnachweis: swim
            };

            ['ausdauer', 'kraft', 'koordination', 'schnelligkeit'].forEach(cat => {
                const dis = row.querySelector(`.discipline-select[data-cat="${cat}"]`).value;
                const val = row.querySelector(`.result-input[data-cat="${cat}"]`).value;
                entry[`${cat}_disziplin`] = dis;
                entry[`${cat}_wert`] = val;
            });

            payload.push(entry);
        });

        const resp = await fetch(saveBtn.dataset.saveUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        if (resp.ok) {
            alert('✅ Daten erfolgreich gespeichert!');
        } else {
            alert('❌ Fehler beim Speichern der Daten!');
        }
    });
});
