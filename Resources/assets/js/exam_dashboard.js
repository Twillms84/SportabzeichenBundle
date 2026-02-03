document.addEventListener('DOMContentLoaded', function () {
    // 1. Daten Store suchen
    const store = document.getElementById('dashboard-data-store');
    
    // Wenn kein Store da ist, brich ab (verhindert Fehler auf anderen Seiten)
    if (!store) return;

    // 2. Daten parsen
    let allStats = {};
    try {
        allStats = JSON.parse(store.dataset.stats);
    } catch (e) {
        console.error("Fehler beim Lesen der Statistik-Daten:", e);
        return;
    }

    // 3. Charts erstellen
    for (const [year, data] of Object.entries(allStats)) {
        const ctx = document.getElementById('chart-' + year);
        
        if (ctx) {
            // "Ohne Medaille" berechnen (verhindert negative Zahlen)
            const totalParticipants = Object.keys(data.unique_users).length;
            const noMedal = Math.max(0, totalParticipants - data.stats.Total);

            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Gold', 'Silber', 'Bronze', 'Teilgenommen (ohne)'],
                    datasets: [{
                        data: [
                            data.stats.Gold, 
                            data.stats.Silber, 
                            data.stats.Bronze, 
                            noMedal
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
                            position: 'right', // Legende rechts ist oft platzsparender
                            labels: {
                                boxWidth: 12,
                                font: { size: 11 }
                            }
                        }
                    }
                }
            });
        }
    }
});