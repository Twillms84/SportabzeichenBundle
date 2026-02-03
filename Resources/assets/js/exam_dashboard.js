// assets/js/dashboard.js

document.addEventListener('DOMContentLoaded', function() {
    
    // Alle Löschen-Buttons suchen (erkennbar an der Klasse .btn-delete-exam)
    const deleteButtons = document.querySelectorAll('.btn-delete-exam');

    deleteButtons.forEach(function(button) {
        button.addEventListener('click', function(event) {
            // Standard-Verhalten (Formular absenden) verhindern
            event.preventDefault();

            // Daten aus den Data-Attributen lesen
            const examName = button.dataset.name;
            const formId = button.dataset.formId;

            // Die deutliche Sicherheitsabfrage
            const message = 
                `ACHTUNG: Möchten Sie die Prüfung "${examName}" wirklich löschen?\n\n` +
                `Das löscht auch ALLE bisher eingegebenen Ergebnisse und Teilnehmerdaten dieser Prüfung.\n` +
                `Dieser Schritt kann nicht rückgängig gemacht werden!`;

            if (confirm(message)) {
                // Wenn bestätigt, das versteckte Formular suchen und absenden
                const form = document.getElementById(formId);
                if (form) {
                    form.submit();
                } else {
                    console.error('Lösch-Formular nicht gefunden: ' + formId);
                }
            }
        });
    });
});