document.addEventListener('DOMContentLoaded', function() {

    // ============================================================
    // 1. SUCHFUNKTION (Dein existierender Code)
    // ============================================================

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

    // Suche aktivieren
    attachTableSearch('searchTable', 'participantRows'); // Index
    attachTableSearch('searchMissing', 'missingRows');   // Missing (falls benötigt)


    // ============================================================
    // 2. SINGLE MODAL HANDLING (Neu für Performance)
    // ============================================================
    
    /* * Wir nutzen jQuery ($), da IServs Bootstrap-Modals darauf basieren.
     * Das Event 'show.bs.modal' wird gefeuert, SOFORT wenn man auf den Button klickt,
     * aber BEVOR das Modal sichtbar ist.
     */
    if (typeof $ !== 'undefined') {
        
        $('#genericEditModal').on('show.bs.modal', function (event) {
            
            // 'event.relatedTarget' ist der Button, der das Modal geöffnet hat
            var button = $(event.relatedTarget); 
            
            // 1. Daten aus den data-Attributen des Buttons holen
            var id = button.data('id');           // data-id="..."
            var name = button.data('name');       // data-name="..."
            var dob = button.data('dob');         // data-dob="..."
            var gender = button.data('gender');   // data-gender="..."

            var modal = $(this);

            // 2. Visuelle Elemente im Modal aktualisieren
            // Wir suchen das span mit der ID modalUserName und setzen den Text
            modal.find('#modalUserName').text(name);

            // 3. Formularfelder befüllen
            modal.find('#modalDob').val(dob);
            modal.find('#modalGender').val(gender);

            // 4. Formular-Action dynamisch bauen
            // Wir holen uns die Template-URL aus dem <form data-url-template="...">
            var form = modal.find('form');
            var urlTemplate = form.data('url-template');

            if (urlTemplate) {
                // Wir ersetzen den Platzhalter 'PLACEHOLDER_ID' durch die echte ID
                // Beispiel vorher: /admin/participants/PLACEHOLDER_ID/update
                // Beispiel nachher: /admin/participants/42/update
                var newUrl = urlTemplate.replace('PLACEHOLDER_ID', id);
                form.attr('action', newUrl);
            }
        });

    } else {
        console.warn('PulsR Sportabzeichen: jQuery nicht gefunden. Modal-Logik inaktiv.');
    }
});