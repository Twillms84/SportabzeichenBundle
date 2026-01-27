// exam_results_scoring.js
document.addEventListener('DOMContentLoaded', function() {
    console.log("Scoring Script geladen...");

    const form = document.getElementById('autosave-form');
    if (!form) return;

    form.addEventListener('change', function(event) {
        const target = event.target;
        if (!target.hasAttribute('data-save')) return;

        console.log("Änderung erkannt an:", target.id, "Wert:", target.value);

        // Hier rufen wir deine Speicher-Funktion auf (die in exam_results_autosave.js liegen sollte)
        // WICHTIG: Nach dem erfolgreichen AJAX-Request muss die Farbe aktualisiert werden.
    });
});

// Diese Funktion sollte am Ende deines AJAX-Success Callbacks aufgerufen werden
function updateMedalUI(epId, discId, medal, points, inputId) {
    console.log(`Update UI: EP=${epId}, Disc=${discId}, Medal=${medal}, Pts=${points}`);

    // Finde das Input-Feld und das dazugehörige Select-Feld
    const inputField = document.getElementById(inputId);
    // Das Select-Feld hat in deinem Twig eine ID, die wir finden müssen (z.B. über die Zelle)
    const cell = inputField.closest('td');
    const selectField = cell.querySelector('select');

    // 1. Alle alten Medaillen-Klassen entfernen
    const classesToRemove = ['medal-gold', 'medal-silver', 'medal-bronze', 'medal-none'];
    [inputField, selectField].forEach(el => {
        if (el) {
            el.classList.remove(...classesToRemove);
            // 2. Neue Klasse hinzufügen
            const newClass = 'medal-' + medal.toLowerCase();
            el.classList.add(newClass);
            console.log(`Klasse ${newClass} an ${el.tagName} gesetzt.`);
        }
    });
}