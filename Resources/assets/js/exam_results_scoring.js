(function($) {
    console.log("[Scoring] Scoring-Modul aktiv.");

    $(document).on('autosave:success', function(e, data) {
        // Das betroffene Element (input oder select)
        const $el = $(data.element);
        
        // Die vom Server berechnete Medaille (gold, silver, bronze, none)
        const medal = data.response.medal; 

        console.log(`[Scoring] Ergebnis empfangen: ${medal} für Feld`, data.element);

        // 1. Alle alten Medaillen-Klassen entfernen, damit sie sich nicht überlagern
        $el.removeClass('medal-gold medal-silver medal-bronze medal-none');

        // 2. Die neue Klasse basierend auf der Server-Antwort setzen
        if (medal && medal !== 'none') {
            $el.addClass(`medal-${medal}`);
        } else {
            // Falls keine Medaille erreicht wurde oder das Feld geleert wurde
            $el.addClass('medal-none');
        }

        // 3. Optional: Falls du das Dropdown ebenfalls färben willst, 
        // wenn das Input-Feld sich ändert:
        if ($el.data('type') === 'leistung') {
            // Finde das zugehörige Dropdown in der gleichen Tabellenzelle
            const $dropdown = $el.closest('td').find('select.discipline-selector');
            $dropdown.removeClass('medal-gold medal-silver medal-bronze medal-none');
            $dropdown.addClass(`medal-${medal || 'none'}`);
        }
    });
})(jQuery);