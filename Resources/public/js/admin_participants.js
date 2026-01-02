document.addEventListener('DOMContentLoaded', function() {

    /**
     * Hilfsfunktion: Verbindet ein Suchfeld mit einer Tabelle
     * @param {string} inputId - ID des Input-Feldes
     * @param {string} tbodyId - ID des Table-Body
     */
    function attachTableSearch(inputId, tbodyId) {
        const input = document.getElementById(inputId);
        const tbody = document.getElementById(tbodyId);

        // Abbruch, wenn Elemente auf dieser Seite nicht existieren
        if (!input || !tbody) {
            return;
        }

        input.addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const rows = tbody.getElementsByTagName('tr');

            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                // Textinhalt der Zeile prüfen
                const text = row.textContent.toLowerCase();
                // Anzeige umschalten
                row.style.display = text.includes(filter) ? "" : "none";
            }
        });
    }

    // 1. Suche für die Teilnehmer-Verwaltung (Index)
    attachTableSearch('searchTable', 'participantRows');

    // 2. Suche für "Fehlende Benutzer" (Missing)
    attachTableSearch('searchMissing', 'missingRows');
});