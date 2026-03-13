/**
 * =====================================================
 * GESTION_COLIS - CONFIGURATION DES GRAPHIQUES
 * Chart.js initialization et configurations
 * =====================================================
 */

let charts = {};

// Configuration globale de Chart.js
Chart.defaults.color = '#64748B';
Chart.defaults.borderColor = 'rgba(0, 180, 216, 0.2)';
Chart.defaults.font.family = "'Inter', sans-serif";

// Couleurs du thème (THÈME CLAIR)
const themeColors = {
    cyan: '#00B4D8',
    blue: '#0096C7',
    purple: '#7C3AED',
    green: '#10B981',
    orange: '#F59E0B',
    red: '#EF4444',
    background: 'rgba(0, 180, 216, 0.1)'
};

// =====================================================
// INITIALISATION DES GRAPHIQUES
// =====================================================

function initCharts(data = {}) {
    initMonthlyChart(data.monthly || []);
    initStatusChart(data.status || []);
    initPerformanceChart(data.performance || []);
    initPieChart(data.distribution || []);
}

function initMonthlyChart(monthlyData) {
    const ctx = document.getElementById('monthlyChart');
    if (!ctx) return;
    
    // Vérifier si le graphique existe déjà et le détruire
    if (charts.monthly) {
        charts.monthly.destroy();
    }
    
    // Préparer les données
    const months = ['', 'Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 
                   'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
    
    const labels = monthlyData.map(item => months[item.mois] || `Mois ${item.mois}`);
    const values = monthlyData.map(item => item.total);
    
    // Créer le graphique
    charts.monthly = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Nombre de Colis',
                data: values,
                borderColor: themeColors.cyan,
                backgroundColor: themeColors.background,
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: themeColors.cyan,
                pointBorderColor: '#FFFFFF',
                pointBorderWidth: 2,
                pointRadius: 6,
                pointHoverRadius: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        color: '#E2E8F0',
                        usePointStyle: true,
                        padding: 20
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.9)',
                    titleColor: '#00B4D8',
                    bodyColor: '#E2E8F0',
                    borderColor: 'rgba(0, 180, 216, 0.3)',
                    borderWidth: 1,
                    padding: 12,
                    displayColors: false
                }
            },
            scales: {
                x: {
                    ticks: {
                        color: '#94A3B8'
                    },
                    grid: {
                        color: 'rgba(0, 229, 255, 0.1)'
                    }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        color: '#94A3B8',
                        stepSize: 1
                    },
                    grid: {
                        color: 'rgba(0, 229, 255, 0.1)'
                    }
                }
            },
            interaction: {
                intersect: false,
                mode: 'index'
            },
            animation: {
                duration: 1500,
                easing: 'easeOutQuart'
            }
        }
    });
}

function initStatusChart(statusData) {
    const ctx = document.getElementById('statusChart');
    if (!ctx) return;
    
    if (charts.status) {
        charts.status.destroy();
    }
    
    const statusColors = {
        'en_attente': themeColors.orange,
        'en_livraison': themeColors.cyan,
        'livré': themeColors.green,
        'retourné': themeColors.red,
        'annulé': themeColors.purple
    };
    
    const labels = statusData.map(item => formatStatus(item.statut));
    const values = statusData.map(item => item.total);
    const colors = statusData.map(item => statusColors[item.statut] || themeColors.blue);
    
    charts.status = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: values,
                backgroundColor: colors,
                borderColor: '#FFFFFF',
                borderWidth: 3,
                hoverBorderWidth: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'right',
                    labels: {
                        color: '#E2E8F0',
                        padding: 15,
                        usePointStyle: true,
                        font: {
                            size: 12
                        }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.9)',
                    titleColor: '#00B4D8',,
                    bodyColor: '#E2E8F0',
                    borderColor: 'rgba(0, 180, 216, 0.3)',
                    borderWidth: 1,
                    padding: 12
                }
            },
            cutout: '60%',
            animation: {
                animateRotate: true,
                animateScale: true,
                duration: 1500
            }
        }
    });
}

function initPerformanceChart(performanceData) {
    const ctx = document.getElementById('performanceChart');
    if (!ctx) return;
    
    if (charts.performance) {
        charts.performance.destroy();
    }
    
    const labels = performanceData.map(item => {
        const date = new Date(item.date);
        return date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
    });
    
    charts.performance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Livraisons',
                data: performanceData.map(item => item.total),
                backgroundColor: 'rgba(0, 229, 255, 0.8)',
                borderColor: themeColors.cyan,
                borderWidth: 2,
                borderRadius: 6,
                borderSkipped: false
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        color: '#E2E8F0',
                        usePointStyle: true
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.9)',
                    titleColor: '#00B4D8',,
                    bodyColor: '#E2E8F0',
                    borderColor: 'rgba(0, 180, 216, 0.3)',
                    borderWidth: 1,
                    padding: 12
                }
            },
            scales: {
                x: {
                    ticks: {
                        color: '#94A3B8'
                    },
                    grid: {
                        display: false
                    }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        color: '#94A3B8',
                        stepSize: 1
                    },
                    grid: {
                        color: 'rgba(0, 229, 255, 0.1)'
                    }
                }
            },
            animation: {
                duration: 1000,
                easing: 'easeOutQuart'
            }
        }
    });
}

function initPieChart(distributionData) {
    const ctx = document.getElementById('pieChart');
    if (!ctx) return;
    
    if (charts.pie) {
        charts.pie.destroy();
    }
    
    charts.pie = new Chart(ctx, {
        type: 'pie',
        data: {
            labels: distributionData.map(item => item.label),
            datasets: [{
                data: distributionData.map(item => item.value),
                backgroundColor: [
                    themeColors.cyan,
                    themeColors.blue,
                    themeColors.purple,
                    themeColors.green,
                    themeColors.orange
                ],
                borderColor: '#FFFFFF',
                borderWidth: 3,
                hoverBorderWidth: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                    labels: {
                        color: '#E2E8F0',
                        padding: 20,
                        usePointStyle: true
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.9)',
                    titleColor: '#00B4D8',,
                    bodyColor: '#E2E8F0',
                    borderColor: 'rgba(0, 180, 216, 0.3)',
                    borderWidth: 1,
                    padding: 12
                }
            },
            animation: {
                animateRotate: true,
                animateScale: true,
                duration: 1500
            }
        }
    });
}

// =====================================================
// FONCTIONS UTILITAIRES
// =====================================================

function formatStatus(statut) {
    const statusMap = {
        'en_attente': 'En Attente',
        'en_livraison': 'En Livraison',
        'livré': 'Livré',
        'retourné': 'Retourné',
        'annulé': 'Annulé'
    };
    return statusMap[statut] || statut;
}

function formatStatutClass(statut) {
    return statut.replace('_', '-');
}

// Animation des nombres
function animateValue(element, start, end, duration = 1500) {
    if (!element) return;
    
    const range = end - start;
    const startTime = performance.now();
    
    function update(currentTime) {
        const elapsed = currentTime - startTime;
        const progress = Math.min(elapsed / duration, 1);
        const current = Math.floor(start + (range * easeOutQuart(progress)));
        element.textContent = current.toLocaleString();
        
        if (progress < 1) {
            requestAnimationFrame(update);
        }
    }
    
    requestAnimationFrame(update);
}

function easeOutQuart(x) {
    return 1 - Math.pow(1 - x, 4);
}

// Rafraîchir tous les graphiques
function refreshCharts(newData) {
    Object.values(charts).forEach(chart => {
        if (chart) chart.destroy();
    });
    charts = {};
    initCharts(newData);
}

// Exporter pour utilisation globale
window.ChartConfig = {
    init: initCharts,
    refresh: refreshCharts,
    formatStatus,
    formatStatutClass,
    animateValue,
    themeColors
};
