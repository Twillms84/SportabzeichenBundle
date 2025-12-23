// Resources/assets/js/exam_results_scoring.js
(function($) {
    $(document).on('autosave:success', function(e, data) {
        const $el = $(data.element);
        const medal = data.response.medal; // gold, silver, bronze, none

        // CSS Klassen für Medaillen
        const colors = {
            'gold': '#ffd700',
            'silver': '#c0c0c0',
            'bronze': '#cd7f32',
            'none': 'transparent'
        };

        // Umrandung oder Hintergrund des Feldes anpassen
        $el.css({
            'border-left': `5px solid ${colors[medal] || 'transparent'}`,
            'transition': 'all 0.3s'
        });
        
        console.log(`[Scoring] Medaille für Feld: ${medal}`);
    });
})(jQuery);