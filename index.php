<?php
session_start();
require_once 'db.php';
require_once 'functions.php';

// Get available districts and years
$districts = getDistricts();
$years = getYears();

// Check if user has selected district and year
$selectedDistrict = $_GET['district'] ?? $_SESSION['district'] ?? '';
$selectedYear = $_GET['year'] ?? $_SESSION['year'] ?? '';

// Save selections to session
if ($selectedDistrict) $_SESSION['district'] = $selectedDistrict;
if ($selectedYear) $_SESSION['year'] = $selectedYear;

// Get dashboard data if both selections exist
$dashboardData = null;
if ($selectedDistrict && $selectedYear) {
    $dashboardData = getDashboardData($selectedDistrict, $selectedYear);
}
?>
<!DOCTYPE html>
<html lang="ta">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MGNREGA டாஷ்போர்டு - எங்கள் குரல், எங்கள் உரிமை</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .bg-primary-gradient {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .bg-success-gradient {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        .bg-warning-gradient {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        .bg-danger-gradient {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        }
        .stat-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .btn-action {
            border-radius: 10px;
            font-weight: 600;
            padding: 12px 24px;
            transition: all 0.3s ease;
        }
        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .section-title {
            border-left: 5px solid #667eea;
            padding-left: 15px;
            margin-bottom: 25px;
        }
        .language-badge {
            background: linear-gradient(45deg, #FF9933, #FF9933, #138808, #138808);
            color: white;
            font-weight: bold;
        }
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        .nav-pills .nav-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }
        .info-card {
            border-left: 4px solid #007bff;
        }
        .loading-spinner {
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="bg-light">
    <!-- Header -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary-gradient shadow">
        <div class="container">
            <a class="navbar-brand fw-bold" href="#">
                <i class="bi bi-graph-up me-2"></i>
                MGNREGA டாஷ்போர்டு
            </a>
            <span class="badge language-badge">தமிழ்</span>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a href="index.php" class="nav-link active">
                            <i class="bi bi-house me-1"></i>முகப்பு
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="sync.php" class="nav-link">
                            <i class="bi bi-arrow-repeat me-1"></i>தரவு ஒத்திசைவு
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="location.php" class="nav-link">
                            <i class="bi bi-geo-alt me-1"></i>மாவட்டத்தைக் கண்டறிய
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <!-- Selection Controls -->
        <div class="card stat-card mb-4">
            <div class="card-header bg-white">
                <h3 class="section-title mb-0">
                    <i class="bi bi-geo-alt me-2"></i>
                    உங்கள் மாவட்டம் மற்றும் ஆண்டைத் தேர்ந்தெடுக்கவும்
                </h3>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label fw-semibold">மாவட்டம்</label>
                        <select name="district" class="form-select form-select-lg" required>
                            <option value="">-- தேர்ந்தெடுக்கவும் --</option>
                            <?php foreach ($districts as $district): ?>
                                <option value="<?= htmlspecialchars($district) ?>" <?= $selectedDistrict === $district ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($district) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">நிதியாண்டு</label>
                        <select name="year" class="form-select form-select-lg" required>
                            <option value="">-- தேர்ந்தெடுக்கவும் --</option>
                            <?php foreach ($years as $year): ?>
                                <option value="<?= htmlspecialchars($year) ?>" <?= $selectedYear === $year ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($year) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-success btn-action w-100">
                            <i class="bi bi-eye me-2"></i>காட்டு
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($dashboardData): ?>
            <!-- Summary Statistics -->
            <div class="row g-4 mb-4">
                <!-- Households Worked -->
                <div class="col-md-3">
                    <div class="card stat-card bg-success-gradient text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="card-title mb-0">வேலை செய்த குடும்பங்கள்</h5>
                                <i class="bi bi-house display-6"></i>
                            </div>
                            <h2 class="display-4 fw-bold"><?= formatNumber($dashboardData['overview']['total_households']) ?></h2>
                            <p class="card-text opacity-75 mb-0">Households Worked</p>
                        </div>
                    </div>
                </div>

                <!-- Total Individuals -->
                <div class="col-md-3">
                    <div class="card stat-card bg-primary-gradient text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="card-title mb-0">மொத்த நபர்கள்</h5>
                                <i class="bi bi-people display-6"></i>
                            </div>
                            <h2 class="display-4 fw-bold"><?= formatNumber($dashboardData['overview']['total_individuals']) ?></h2>
                            <p class="card-text opacity-75 mb-0">Total Individuals</p>
                        </div>
                    </div>
                </div>

                <!-- Women Participation -->
                <div class="col-md-3">
                    <div class="card stat-card bg-warning-gradient text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="card-title mb-0">பெண்கள் பங்கேற்பு</h5>
                                <i class="bi bi-gender-female display-6"></i>
                            </div>
                            <h2 class="display-4 fw-bold"><?= formatNumber($dashboardData['overview']['total_women_days']) ?></h2>
                            <p class="card-text opacity-75 mb-0">Women Persondays</p>
                        </div>
                    </div>
                </div>

                <!-- Total Expenditure -->
                <div class="col-md-3">
                    <div class="card stat-card bg-danger-gradient text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="card-title mb-0">மொத்த செலவு</h5>
                                <i class="bi bi-currency-rupee display-6"></i>
                            </div>
                            <h2 class="display-4 fw-bold">₹<?= formatNumber($dashboardData['overview']['total_expenditure']) ?></h2>
                            <p class="card-text opacity-75 mb-0">Total Expenditure</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Additional Metrics -->
            <div class="row g-4 mb-4">
                <!-- Average Employment Days -->
                <div class="col-md-4">
                    <div class="card stat-card h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <i class="bi bi-calendar-week text-primary display-6"></i>
                                <div>
                                    <h5 class="card-title mb-1">சராசரி வேலை நாட்கள்</h5>
                                    <p class="text-muted mb-0">Avg Employment Days</p>
                                </div>
                            </div>
                            <h2 class="display-4 fw-bold text-primary"><?= number_format($dashboardData['overview']['avg_employment_days'], 1) ?></h2>
                            <small class="text-muted">days per household</small>
                        </div>
                    </div>
                </div>

                <!-- Completed Works -->
                <div class="col-md-4">
                    <div class="card stat-card h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <i class="bi bi-check-circle text-success display-6"></i>
                                <div>
                                    <h5 class="card-title mb-1">நிறைவடைந்த பணிகள்</h5>
                                    <p class="text-muted mb-0">Completed Works</p>
                                </div>
                            </div>
                            <h2 class="display-4 fw-bold text-success"><?= formatNumber($dashboardData['projects']['completed_works']) ?></h2>
                            <small class="text-muted">projects completed</small>
                        </div>
                    </div>
                </div>

                <!-- Budget Utilization -->
                <div class="col-md-4">
                    <div class="card stat-card h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <i class="bi bi-pie-chart text-purple display-6"></i>
                                <div>
                                    <h5 class="card-title mb-1">பட்ஜெட் பயன்பாடு</h5>
                                    <p class="text-muted mb-0">Budget Utilization</p>
                                </div>
                            </div>
                            <h2 class="display-4 fw-bold text-purple"><?= number_format($dashboardData['budget']['budget_utilization_percent'], 1) ?>%</h2>
                            <small class="text-muted">of allocated budget</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="card stat-card mb-4">
                <div class="card-header bg-white">
                    <ul class="nav nav-pills card-header-pills" id="chartTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="households-tab" data-bs-toggle="tab" data-bs-target="#households" type="button">
                                <i class="bi bi-house me-2"></i>மாதாந்திர குடும்பங்கள்
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="individuals-tab" data-bs-toggle="tab" data-bs-target="#individuals" type="button">
                                <i class="bi bi-people me-2"></i>மாதாந்திர நபர்கள்
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="scst-tab" data-bs-toggle="tab" data-bs-target="#scst" type="button">
                                <i class="bi bi-pie-chart me-2"></i>SC/ST பங்கேற்பு
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="projects-tab" data-bs-toggle="tab" data-bs-target="#projects" type="button">
                                <i class="bi bi-clipboard-check me-2"></i>திட்ட நிலை
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="chartTabsContent">
                        <!-- Monthly Households -->
                        <div class="tab-pane fade show active" id="households" role="tabpanel">
                            <div class="chart-container">
                                <canvas id="monthlyHouseholdsChart"></canvas>
                            </div>
                        </div>
                        
                        <!-- Monthly Individuals -->
                        <div class="tab-pane fade" id="individuals" role="tabpanel">
                            <div class="chart-container">
                                <canvas id="monthlyIndividualsChart"></canvas>
                            </div>
                        </div>
                        
                        <!-- SC/ST Participation -->
                        <div class="tab-pane fade" id="scst" role="tabpanel">
                            <div class="chart-container">
                                <canvas id="scstChart"></canvas>
                            </div>
                        </div>
                        
                        <!-- Project Status -->
                        <div class="tab-pane fade" id="projects" role="tabpanel">
                            <div class="chart-container">
                                <canvas id="projectsChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="row g-4">
               
                <div class="col-md-4">
                    <div class="card stat-card border-success">
                        <div class="card-body text-center">
                            <i class="bi bi-geo-alt display-4 text-success mb-3"></i>
                            <h5 class="card-title">மாவட்டத்தைக் கண்டறிய</h5>
                            <p class="text-muted mb-3">உங்கள் இருப்பிடத்தின் அடிப்படையில் மாவட்டத்தைக் கண்டறியவும்</p>
                            <a href="location.php" class="btn btn-success btn-action w-100">
                                <i class="bi bi-search me-2"></i>தேடல் தொடங்கு
                            </a>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card stat-card border-info">
                        <div class="card-body text-center">
                            <i class="bi bi-share display-4 text-info mb-3"></i>
                            <h5 class="card-title">அறிக்கையைப் பகிரவும்</h5>
                            <p class="text-muted mb-3">இந்த தரவை மற்றவர்களுடன் பகிர்ந்து கொள்ளவும்</p>
                            <button class="btn btn-info btn-action w-100" onclick="shareReport()">
                                <i class="bi bi-share-fill me-2"></i>பகிர்
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <script>
            // Chart Data from PHP
            const monthlyData = <?= json_encode($dashboardData['monthly']) ?>;
            const scstData = <?= json_encode($dashboardData['scst']) ?>;
            const projectData = <?= json_encode($dashboardData['projects']) ?>;

            // Extract monthly data
            const months = monthlyData.map(d => d.month);
            const households = monthlyData.map(d => parseInt(d.total_households) || 0);
            const individuals = monthlyData.map(d => parseInt(d.total_individuals) || 0);

            // Monthly Households Chart
            new Chart(document.getElementById('monthlyHouseholdsChart'), {
                type: 'bar',
                data: {
                    labels: months,
                    datasets: [{
                        label: 'குடும்பங்கள்',
                        data: households,
                        backgroundColor: 'rgba(40, 167, 69, 0.7)',
                        borderColor: 'rgba(40, 167, 69, 1)',
                        borderWidth: 2,
                        borderRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { mode: 'index', intersect: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(0,0,0,0.1)' }
                        },
                        x: {
                            grid: { display: false }
                        }
                    }
                }
            });

            // Monthly Individuals Chart
            new Chart(document.getElementById('monthlyIndividualsChart'), {
                type: 'line',
                data: {
                    labels: months,
                    datasets: [{
                        label: 'நபர்கள்',
                        data: individuals,
                        borderColor: 'rgba(13, 110, 253, 1)',
                        backgroundColor: 'rgba(13, 110, 253, 0.1)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 3,
                        pointBackgroundColor: 'rgba(13, 110, 253, 1)',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { mode: 'index', intersect: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(0,0,0,0.1)' }
                        },
                        x: {
                            grid: { display: false }
                        }
                    }
                }
            });

            // SC/ST Chart
            new Chart(document.getElementById('scstChart'), {
                type: 'pie',
                data: {
                    labels: ['SC வேலை நாட்கள்', 'ST வேலை நாட்கள்'],
                    datasets: [{
                        data: [
                            parseInt(scstData.sc_days) || 0,
                            parseInt(scstData.st_days) || 0
                        ],
                        backgroundColor: [
                            'rgba(220, 53, 69, 0.8)',
                            'rgba(255, 193, 7, 0.8)'
                        ],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { padding: 20 }
                        }
                    }
                }
            });

            // Projects Chart
            new Chart(document.getElementById('projectsChart'), {
                type: 'doughnut',
                data: {
                    labels: ['நிறைவடைந்தது', 'நடப்பில் உள்ளது'],
                    datasets: [{
                        data: [
                            parseInt(projectData.completed_works) || 0,
                            parseInt(projectData.ongoing_works) || 0
                        ],
                        backgroundColor: [
                            'rgba(40, 167, 69, 0.8)',
                            'rgba(13, 110, 253, 0.8)'
                        ],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { padding: 20 }
                        }
                    },
                    cutout: '60%'
                }
            });

            // Share Report Function
            function shareReport() {
                const district = '<?= $selectedDistrict ?>';
                const year = '<?= $selectedYear ?>';
                const text = `MGNREGA அறிக்கை - ${district} மாவட்டம் (${year})\n\n` +
                           `வேலை செய்த குடும்பங்கள்: <?= formatNumber($dashboardData['overview']['total_households']) ?>\n` +
                           `மொத்த நபர்கள்: <?= formatNumber($dashboardData['overview']['total_individuals']) ?>\n` +
                           `மொத்த செலவு: ₹<?= formatNumber($dashboardData['overview']['total_expenditure']) ?>\n\n` +
                           `மேலும் விவரங்களுக்கு: ${window.location.href}`;
                
                if (navigator.share) {
                    navigator.share({
                        title: `MGNREGA Report - ${district}`,
                        text: text,
                        url: window.location.href
                    });
                } else {
                    navigator.clipboard.writeText(text).then(() => {
                        alert('அறிக்கை கிளிப்போர்டில் நகலெடுக்கப்பட்டது!');
                    });
                }
            }
            </script>

        <?php else: ?>
            <!-- No Data Selected -->
            <div class="card stat-card text-center py-5">
                <div class="card-body">
                    <i class="bi bi-graph-up display-1 text-muted mb-4"></i>
                    <h3 class="card-title text-gray-800 mb-3">தயவு செய்து மாவட்டம் மற்றும் ஆண்டைத் தேர்ந்தெடுக்கவும்</h3>
                    <p class="text-muted mb-4">Please select a district and year to view the dashboard</p>
                    <div class="row justify-content-center">
                        <div class="col-md-6">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>தகவல்:</strong> மாவட்டம் மற்றும் ஆண்டைத் தேர்ந்தெடுத்த பிறகு, டாஷ்போர்டு தரவு காட்டப்படும்.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <footer class="bg-dark text-white py-4 mt-5">
        <div class="container text-center">
            <p class="mb-2">
                <i class="bi bi-cpu me-1"></i>
                MGNREGA டாஷ்போர்டு - தரவு வெளிப்படைத்தன்மை மூலம் கிராமப்புற இந்தியாவை மேம்படுத்துதல்
            </p>
            <p class="mb-0 small opacity-75">
                &copy; 2024 MGNREGA Dashboard. All rights reserved.
            </p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>