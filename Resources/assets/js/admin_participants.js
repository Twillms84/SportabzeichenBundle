// src/Resources/public/js/admin_participants.js

document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    // Wir prüfen sicherheitshalber, ob jQuery geladen wurde
    if (typeof jQuery === 'undefined') {
        console.warn('PulsR Sportabzeichen: jQuery wurde nicht gefunden! Modals funktionieren eventuell nicht.');
        return;
    }

    var $ = jQuery; // Lokale Referenz

    // ------------------------------------------------------------------
    // 1. Live-Suche (Vanilla JS - funktioniert auch ohne jQuery)
    // ------------------------------------------------------------------
    var searchInput = document.getElementById('searchTable');
    var tbody = document.getElementById('participantRows');

    if (searchInput && tbody) {
        searchInput.addEventListener('keyup', function () {
            var value = this.value.toLowerCase();
            var rows = tbody.getElementsByTagName('tr');

            for (var i = 0; i < rows.length; i++) {
                var row = rows[i];
                var text = row.textContent || row.innerText;
                row.style.display = text.toLowerCase().indexOf(value) > -1 ? '' : 'none';
            }
        });
    }

    // ------------------------------------------------------------------
    // 2. Modal-Logik (Benötigt jQuery für Bootstrap Events)
    // ------------------------------------------------------------------
    var $editModal = $('#genericEditModal');

    if ($editModal.length) {
        $editModal.on('show.bs.modal', function (event) {
            var $button = $(event.relatedTarget);
            var $modal = $(this);
            var $form = $modal.find('form');

            // Daten holen
            var id = $button.data('id');
            var name = $button.data('name');
            var dob = $button.data('dob');
            $modal.find('#modalGender').val(gender);
            
            // URL anpassen
            var urlTemplate = $form.data('url-template');
            if (urlTemplate && id) {
                $form.attr('action', urlTemplate.replace('PLACEHOLDER_ID', id));
            }

            // UI befüllen
            $modal.find('#modalUserName').text(name);
            $modal.find('#modalDob').val(dob);
            
            // WICHTIG: Sicherstellen, dass der Wert im Select existiert
            $modal.find('#modalGender').val(gender);
        });

        // Reset beim Schließen
        $editModal.on('hidden.bs.modal', function () {
            $(this).find('form')[0].reset();
            $(this).find('#modalUserName').text('');
        });
    }
});