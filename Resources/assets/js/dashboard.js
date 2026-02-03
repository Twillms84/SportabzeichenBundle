/* Resources/public/js/dashboard.js */

document.addEventListener("DOMContentLoaded", function() {
    // 1. Daten aus dem HTML-Element holen (Brücke zwischen Twig und JS)
    const dataStore = document.getElementById('dashboard-data-store');
    
    // Abbrechen, wenn das Element nicht existiert (z.B. keine Daten vorhanden)
    if (!dataStore) return;

    // 2. JSON parsen
    const rawJson = dataStore.getAttribute('data-stats');
    if (!rawJson) return;

    const yearlyData = JSON.parse(rawJson);

    // 3. Charts initialisieren
    Object.keys(yearlyData).forEach(function(year) {
        const data = yearlyData[year];
        const stats = data.stats;
        
        // Canvas Element suchen
        const ctx = document.getElementById('chart-' + year);
        if (ctx) {
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Gold', 'Silber', 'Bronze', 'Ohne Abzeichen'],
                    datasets: [{
                        data: [stats.Gold, stats.Silber, stats.Bronze, stats.Ohne],
                        backgroundColor: [
                            '#FFD700', // Gold
                            '#C0C0C0', // Silber
                            '#cd7f32', // Bronze
                            '#e9ecef'  // Ohne (Hellgrau)
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { boxWidth: 12, padding: 15 }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    let value = context.raw;
                                    let total = context.chart._metasets[context.datasetIndex].total;
                                    let percentage = Math.round((value / total) * 100) + '%';
                                    return label + value + ' (' + percentage + ')';
                                }
                            }
                        }
                    },
                    layout: {
                        padding: 10
                    }
                }
            });
        }
    });
});