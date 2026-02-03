document.addEventListener('DOMContentLoaded', function () {
    // 1. Daten aus dem HTML-Attribut auslesen
    const dataStore = document.getElementById('dashboard-data-store');
    
    if (!dataStore) return; // Nichts zu tun, wenn keine Daten da sind

    // JSON parsen
    const yearlyStats = JSON.parse(dataStore.dataset.stats);

    // 2. Durch alle Jahre iterieren
    for (const [year, data] of Object.entries(yearlyStats)) {
        
        // Canvas Element suchen: id="chart-2023", id="chart-2024" usw.
        const ctx = document.getElementById('chart-' + year);

        if (ctx) {
            new Chart(ctx, {
                type: 'doughnut', // Oder 'pie', 'bar'
                data: {
                    labels: ['Gold', 'Silber', 'Bronze', 'Ohne Medaille'],
                    datasets: [{
                        data: [
                            data.stats.Gold,
                            data.stats.Silber,
                            data.stats.Bronze,
                            // "Ohne Medaille" berechnen: Alle Teilnehmer - (Gold+Silber+Bronze)
                            (Object.keys(data.unique_users).length - data.stats.Total)
                        ],
                        backgroundColor: [
                            '#FFD700', // Gold
                            '#C0C0C0', // Silber
                            '#CD7F32', // Bronze
                            '#e9ecef'  // Grau (Rest)
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
                            labels: {
                                boxWidth: 12
                            }
                        }
                    }
                }
            });
        }
    }
});