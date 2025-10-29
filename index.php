<?php
session_start();

// Database config
$db_host = 'db.gtkomvrvyzravwrbfgdm.supabase.co';
$db_name = 'postgres'; 
$db_user = 'postgres';
$db_pass = 'mgnrega@12345';

// Try DB first; if it fails, fall back to local JSON
$pdo = null;
$db_error = null;
$use_json = false;

try {
	$pdo = new PDO("pgsql:host=$db_host;port=5432;dbname=$db_name;sslmode=require", $db_user, $db_pass);
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {
	$db_error = $e->getMessage();
	$use_json = true;
}

// Get districts and years
$districts = [];
$years = [];

if (!$use_json) {
	try {
		$districts = $pdo->query("SELECT DISTINCT district_name FROM mgnrega ORDER BY district_name")->fetchAll(PDO::FETCH_COLUMN);
		$years = $pdo->query("SELECT DISTINCT fin_year FROM mgnrega ORDER BY fin_year DESC")->fetchAll(PDO::FETCH_COLUMN);
	} catch (Throwable $e) {
		$db_error = $e->getMessage();
		$use_json = true;
	}
}

$json_error = null;
$json_rows = [];
if ($use_json) {
	$json_path = __DIR__ . DIRECTORY_SEPARATOR . 'mgnrega_tn_all_records.json';
	if (is_file($json_path)) {
		$decoded = json_decode(file_get_contents($json_path), true);
		if (is_array($decoded)) {
			$json_rows = $decoded;
			$districts = array_values(array_unique(array_map(function($r){ return $r['district_name'] ?? ''; }, $json_rows)));
			sort($districts);
			$years = array_values(array_unique(array_map(function($r){ return $r['fin_year'] ?? ''; }, $json_rows)));
			rsort($years);
		} else {
			$json_error = 'Failed to parse JSON data.';
		}
	} else {
		$json_error = 'Data file mgnrega_tn_all_records.json not found.';
	}
}

// Handle selections
$district = $_GET['district'] ?? '';
$year = $_GET['year'] ?? '';
$compare = isset($_GET['compare']) && $_GET['compare'] === '1';

$data = [];
if ($district && $year) {
    $prev_year = $compare ? previousFinYear($year) : '';
    $data_prev = [];
    $monthly_prev = [];
    if (!$use_json) {
        // Get main data (DB)
        $stmt = $pdo->prepare("
        SELECT 
            total_households_worked,
            total_individuals_worked,
            women_persondays,
            wages,
            average_wage_rate_per_day_per_person,
            number_of_completed_works,
            number_of_ongoing_works,
            sc_persondays,
            st_persondays,
            total_no_of_hhs_completed_100_days_of_wage_employment
        FROM mgnrega 
        WHERE district_name = ? AND fin_year = ?
        LIMIT 1
    ");
        $stmt->execute([$district, $year]);
        $data = $stmt->fetch() ?: [];
        
        // Get monthly data (DB)
        $stmt = $pdo->prepare("
        SELECT month, total_households_worked, total_individuals_worked, wages
        FROM mgnrega 
        WHERE district_name = ? AND fin_year = ?
        ORDER BY 
            CASE month
                WHEN 'Apr' THEN 1 WHEN 'May' THEN 2 WHEN 'Jun' THEN 3
                WHEN 'Jul' THEN 4 WHEN 'Aug' THEN 5 WHEN 'Sep' THEN 6
                WHEN 'Oct' THEN 7 WHEN 'Nov' THEN 8 WHEN 'Dec' THEN 9
                WHEN 'Jan' THEN 10 WHEN 'Feb' THEN 11 WHEN 'Mar' THEN 12
            END
    ");
        $stmt->execute([$district, $year]);
        $monthly_data = $stmt->fetchAll() ?: [];

        if ($prev_year) {
            // Previous year (DB)
            $stmt = $pdo->prepare("
        SELECT 
            total_households_worked,
            total_individuals_worked,
            women_persondays,
            wages,
            average_wage_rate_per_day_per_person,
            number_of_completed_works,
            number_of_ongoing_works,
            sc_persondays,
            st_persondays,
            total_no_of_hhs_completed_100_days_of_wage_employment
        FROM mgnrega 
        WHERE district_name = ? AND fin_year = ?
        LIMIT 1
    ");
            $stmt->execute([$district, $prev_year]);
            $data_prev = $stmt->fetch() ?: [];

            $stmt = $pdo->prepare("
        SELECT month, total_households_worked, total_individuals_worked, wages
        FROM mgnrega 
        WHERE district_name = ? AND fin_year = ?
        ORDER BY 
            CASE month
                WHEN 'Apr' THEN 1 WHEN 'May' THEN 2 WHEN 'Jun' THEN 3
                WHEN 'Jul' THEN 4 WHEN 'Aug' THEN 5 WHEN 'Sep' THEN 6
                WHEN 'Oct' THEN 7 WHEN 'Nov' THEN 8 WHEN 'Dec' THEN 9
                WHEN 'Jan' THEN 10 WHEN 'Feb' THEN 11 WHEN 'Mar' THEN 12
            END
    ");
            $stmt->execute([$district, $prev_year]);
            $monthly_prev = $stmt->fetchAll() ?: [];
        }
    } else {
        // Compute from JSON
        $filtered = array_values(array_filter($json_rows, function($r) use ($district, $year) {
            return ($r['district_name'] ?? null) === $district && ($r['fin_year'] ?? null) === $year;
        }));

        $monthOrder = ['Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec','Jan','Feb','Mar'];
        $monthly_map = [];
        foreach ($monthOrder as $m) {
            $monthly_map[$m] = [
                'month' => $m,
                'total_households_worked' => 0,
                'total_individuals_worked' => 0,
                'wages' => 0,
            ];
        }

        $totals = [
            'total_households_worked' => 0,
            'total_individuals_worked' => 0,
            'women_persondays' => 0,
            'wages' => 0,
            'number_of_completed_works' => 0,
            'number_of_ongoing_works' => 0,
            'sc_persondays' => 0,
            'st_persondays' => 0,
            'total_no_of_hhs_completed_100_days_of_wage_employment' => 0,
        ];
        $wage_rate_sum = 0;
        $wage_rate_count = 0;

        foreach ($filtered as $row) {
            $m = $row['month'] ?? null;
            if ($m && isset($monthly_map[$m])) {
                $monthly_map[$m]['total_households_worked'] += (int)($row['total_households_worked'] ?? 0);
                $monthly_map[$m]['total_individuals_worked'] += (int)($row['total_individuals_worked'] ?? 0);
                $monthly_map[$m]['wages'] += (float)($row['wages'] ?? 0);
            }
            $totals['total_households_worked'] += (int)($row['total_households_worked'] ?? 0);
            $totals['total_individuals_worked'] += (int)($row['total_individuals_worked'] ?? 0);
            $totals['women_persondays'] += (int)($row['women_persondays'] ?? 0);
            $totals['wages'] += (float)($row['wages'] ?? 0);
            $totals['number_of_completed_works'] += (int)($row['number_of_completed_works'] ?? 0);
            $totals['number_of_ongoing_works'] += (int)($row['number_of_ongoing_works'] ?? 0);
            $totals['sc_persondays'] += (int)($row['sc_persondays'] ?? 0);
            $totals['st_persondays'] += (int)($row['st_persondays'] ?? 0);
            $totals['total_no_of_hhs_completed_100_days_of_wage_employment'] += (int)($row['total_no_of_hhs_completed_100_days_of_wage_employment'] ?? 0);

            if (isset($row['average_wage_rate_per_day_per_person']) && is_numeric($row['average_wage_rate_per_day_per_person'])) {
                $wage_rate_sum += (float)$row['average_wage_rate_per_day_per_person'];
                $wage_rate_count++;
            }
        }

        $monthly_data = array_values($monthly_map);
        $data = $filtered ? array_merge($totals, [
            'average_wage_rate_per_day_per_person' => $wage_rate_count ? round($wage_rate_sum / $wage_rate_count, 2) : 0,
        ]) : [];

        if ($prev_year) {
            $filteredPrev = array_values(array_filter($json_rows, function($r) use ($district, $prev_year) {
                return ($r['district_name'] ?? null) === $district && ($r['fin_year'] ?? null) === $prev_year;
            }));
            $monthly_map_prev = [];
            foreach ($monthOrder as $m) {
                $monthly_map_prev[$m] = [
                    'month' => $m,
                    'total_households_worked' => 0,
                    'total_individuals_worked' => 0,
                    'wages' => 0,
                ];
            }
            $totalsPrev = $totals;
            $totalsPrev = array_map(function($v){ return 0; }, $totalsPrev);
            $wrs = 0; $wrc = 0;
            foreach ($filteredPrev as $row) {
                $m = $row['month'] ?? null;
                if ($m && isset($monthly_map_prev[$m])) {
                    $monthly_map_prev[$m]['total_households_worked'] += (int)($row['total_households_worked'] ?? 0);
                    $monthly_map_prev[$m]['total_individuals_worked'] += (int)($row['total_individuals_worked'] ?? 0);
                    $monthly_map_prev[$m]['wages'] += (float)($row['wages'] ?? 0);
                }
                $totalsPrev['total_households_worked'] += (int)($row['total_households_worked'] ?? 0);
                $totalsPrev['total_individuals_worked'] += (int)($row['total_individuals_worked'] ?? 0);
                $totalsPrev['women_persondays'] += (int)($row['women_persondays'] ?? 0);
                $totalsPrev['wages'] += (float)($row['wages'] ?? 0);
                $totalsPrev['number_of_completed_works'] += (int)($row['number_of_completed_works'] ?? 0);
                $totalsPrev['number_of_ongoing_works'] += (int)($row['number_of_ongoing_works'] ?? 0);
                $totalsPrev['sc_persondays'] += (int)($row['sc_persondays'] ?? 0);
                $totalsPrev['st_persondays'] += (int)($row['st_persondays'] ?? 0);
                $totalsPrev['total_no_of_hhs_completed_100_days_of_wage_employment'] += (int)($row['total_no_of_hhs_completed_100_days_of_wage_employment'] ?? 0);
                if (isset($row['average_wage_rate_per_day_per_person']) && is_numeric($row['average_wage_rate_per_day_per_person'])) { $wrs += (float)$row['average_wage_rate_per_day_per_person']; $wrc++; }
            }
            $monthly_prev = array_values($monthly_map_prev);
            $data_prev = $filteredPrev ? array_merge($totalsPrev, [ 'average_wage_rate_per_day_per_person' => $wrc ? round($wrs / $wrc, 2) : 0, ]) : [];
        }
    }
}

function formatNum($n) {
    if ($n >= 10000000) return round($n/10000000,1).'Cr';
    if ($n >= 100000) return round($n/100000,1).'L';
    if ($n >= 1000) return round($n/1000,1).'K';
    return $n;
}

function previousFinYear($fy) {
    // Handles formats like "2023-24" or "2023-2024" or numeric years
    if (preg_match('/^(\d{4})\s*[-]\s*(\d{2,4})$/', $fy, $m)) {
        $start = (int)$m[1];
        $endPart = $m[2];
        $end = (int)(strlen($endPart) === 2 ? ((int)$m[1] + ((int)$endPart) - ((int)substr($m[1], 2))) : $endPart);
        $prevStart = $start - 1;
        $prevEnd = $end - 1;
        // Return in same compact style as input (YYYY-YY when possible)
        if (strlen($endPart) === 2) {
            return $prevStart . '-' . substr((string)$prevEnd, 2);
        }
        return $prevStart . '-' . $prevEnd;
    }
    if (is_numeric($fy)) {
        return (string)((int)$fy - 1);
    }
    return '';
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>MGNREGA Simple View</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        body { background: #f0f2f5; padding: 15px; font-family: Arial, sans-serif; }
        .card { border-radius: 10px; margin-bottom: 15px; border: none; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .chart-box { background: white; padding: 15px; border-radius: 10px; margin-bottom: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .big-number { font-size: 24px; font-weight: bold; margin-bottom: 5px; }
        .card-label { font-size: 14px; color: #666; }
        .chart-container { position: relative; height: 280px; width: 100%; }
        @media (max-width: 576px) { .chart-container { height: 220px; } }
        .chart-box canvas { width: 100% !important; height: 100% !important; }
    </style>
</head>
<body>
    <div class="container">
        <h3 class="text-center mb-1">MGNREGA Dashboard</h3>
        <p class="text-center text-muted mb-3" style="font-size:14px;">See how your district is doing in jobs and wages. Simple charts, easy words.</p>
        <div class="d-flex justify-content-end mb-2">
            <div class="d-flex align-items-center gap-2">
                <label for="lang" class="text-muted" style="font-size:13px;">Language</label>
                <select id="lang" class="form-select form-select-sm" style="width:auto;">
                    <option value="en" selected>English</option>
                    <option value="ta">தமிழ் (Soon)</option>
                    <option value="hi">हिंदी (Soon)</option>
                </select>
            </div>
        </div>
		<?php if ($use_json): ?>
			<div class="alert alert-warning py-2" role="alert">
				Showing data from local JSON file<?php if ($db_error) { echo ' (DB error: '.htmlspecialchars($db_error).')'; } ?>.
				<?php if ($json_error) { echo ' Note: '.htmlspecialchars($json_error); } ?>
			</div>
		<?php elseif ($db_error): ?>
			<div class="alert alert-danger py-2" role="alert">
				Database error: <?= htmlspecialchars($db_error) ?>
			</div>
		<?php endif; ?>
        
        <!-- Simple Selection -->
        <div class="card">
            <div class="card-body">
                <form method="GET" class="row g-2" id="filterForm">
                    <div class="col-md-5">
                        <select name="district" class="form-select" required>
                            <option value="">Select District</option>
                            <?php foreach($districts as $d): ?>
                                <option value="<?= $d ?>" <?= $district==$d?'selected':'' ?>><?= $d ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <select name="year" class="form-select" required>
                            <option value="">Select Year</option>
                            <?php foreach($years as $y): ?>
                                <option value="<?= $y ?>" <?= $year==$y?'selected':'' ?>><?= $y ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-grid">
                        <button class="btn btn-primary">Show Data</button>
                    </div>
                    <div class="col-12 d-flex gap-2 align-items-center mt-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="1" id="compareCheck" name="compare" <?= $compare?'checked':'' ?>>
                            <label class="form-check-label" for="compareCheck" style="font-size:14px;">Compare with previous year</label>
                        </div>
                        <button type="button" id="geoBtn" class="btn btn-outline-secondary btn-sm">Use my location</button>
                        <span id="geoStatus" class="text-muted" style="font-size:12px;"></span>
                    </div>
                </form>
            </div>
        </div>

        <?php if(!empty($data)): ?>
        <!-- 4 Main Cards -->
        <div class="row">
            <div class="col-md-3">
                <div class="card text-white bg-primary">
                    <div class="card-body text-center">
                        <div class="big-number">👨‍👩‍👧‍👦 <?= formatNum($data['total_households_worked']) ?></div>
                        <div class="card-label">Families Worked <span tabindex="0" style="color:#eee;cursor:help;" data-bs-toggle="popover" data-bs-content="Total number of families who got work under MGNREGA in your selected year and district. [Hindi: जिन परिवारों को काम मिला]">?</span></div>
                        <div class="small text-white-50" style="font-size:13px;">A family means all persons living together and benefiting from the job scheme.</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-white bg-success">
                    <div class="card-body text-center">
                        <div class="big-number">🧑‍🌾 <?= formatNum($data['total_individuals_worked']) ?></div>
                        <div class="card-label">People Worked <span tabindex="0" style="color:#eee;cursor:help;" data-bs-toggle="popover" data-bs-content="Total number of individuals who got work. Children and adults included. [Hindi: लोगों को काम मिला]">?</span></div>
                        <div class="small text-white-50" style="font-size:13px;">Each person counted even if worked for just one day.</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-white bg-warning">
                    <div class="card-body text-center">
                        <div class="big-number">👩 <?= formatNum($data['women_persondays']) ?></div>
                        <div class="card-label">Women Days <span tabindex="0" style="color:#555;cursor:help;" data-bs-toggle="popover" data-bs-content="Number of days women worked (these are ‘person-days’). [Hindi: महिलाओं के कार्य दिवस]">?</span></div>
                        <div class="small text-white-50" style="font-size:13px;">Each day a woman worked, for any hours, is counted here.</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-white bg-danger">
                    <div class="card-body text-center">
                        <div class="big-number">₹<?= formatNum($data['wages']) ?></div>
                        <div class="card-label">Total Wages <span tabindex="0" style="color:#eee;cursor:help;" data-bs-toggle="popover" data-bs-content="Full amount of money paid this year. [Hindi: मजदूरी की कुल राशि]">?</span></div>
                        <div class="small text-white-50" style="font-size:13px;">Includes regular and additional wages given.</div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Help for charts -->
        <div class="row mb-1"><div class="col-12 text-center pb-2">
        <span class="text-muted" style="font-size:15px;">What do these charts mean?
            <button class="btn btn-link btn-sm p-0" tabindex="0" data-bs-toggle="popover" title="Chart Help" data-bs-content="Bar and line charts make differences easy! 
Each month’s data is shown, so you see change over time. Compare years with the red line/bar. If you want, switch language (coming soon!).">?</button>
        </span>
        </div></div>

        <!-- Export & Summary -->
        <div class="row mb-2">
            <div class="col-12 d-flex justify-content-end gap-2">
                <button class="btn btn-outline-secondary btn-sm" onclick="downloadMonthlyCsv()">Download CSV</button>
            </div>
        </div>

        <?php if (!empty($data)): ?>
        <div class="row mb-3">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Indicator</th>
                                        <th>This Year (<?= htmlspecialchars($year) ?>)</th>
                                        <?php if (!empty($data_prev)): ?><th>Prev Year (<?= htmlspecialchars($prev_year) ?>)</th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Avg Wage (₹)</td>
                                        <td><?= formatNum($data['average_wage_rate_per_day_per_person'] ?? 0) ?></td>
                                        <?php if (!empty($data_prev)): ?><td><?= formatNum($data_prev['average_wage_rate_per_day_per_person'] ?? 0) ?></td><?php endif; ?>
                                    </tr>
                                    <tr>
                                        <td>100 Days Families</td>
                                        <td><?= formatNum($data['total_no_of_hhs_completed_100_days_of_wage_employment'] ?? 0) ?></td>
                                        <?php if (!empty($data_prev)): ?><td><?= formatNum($data_prev['total_no_of_hhs_completed_100_days_of_wage_employment'] ?? 0) ?></td><?php endif; ?>
                                    </tr>
                                    <tr>
                                        <td>Completed Works</td>
                                        <td><?= formatNum($data['number_of_completed_works'] ?? 0) ?></td>
                                        <?php if (!empty($data_prev)): ?><td><?= formatNum($data_prev['number_of_completed_works'] ?? 0) ?></td><?php endif; ?>
                                    </tr>
                                    <tr>
                                        <td>Ongoing Works</td>
                                        <td><?= formatNum($data['number_of_ongoing_works'] ?? 0) ?></td>
                                        <?php if (!empty($data_prev)): ?><td><?= formatNum($data_prev['number_of_ongoing_works'] ?? 0) ?></td><?php endif; ?>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 5 Simple Charts -->
        <div class="row mt-3">
            <!-- Chart 1: Monthly Families -->
            <div class="col-md-6">
                <div class="chart-box">
                    <h6>Families Worked by Month</h6>
                    <div class="chart-container">
                        <canvas id="familiesChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Chart 2: Monthly People -->
            <div class="col-md-6">
                <div class="chart-box">
                    <h6>People Worked by Month</h6>
                    <div class="chart-container">
                        <canvas id="peopleChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Chart 3: Projects -->
            <div class="col-md-6">
                <div class="chart-box">
                    <h6>Work Status</h6>
                    <div class="chart-container">
                        <canvas id="projectsChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Chart 4: SC/ST -->
            <div class="col-md-6">
                <div class="chart-box">
                    <h6>SC/ST Participation</h6>
                    <div class="chart-container">
                        <canvas id="scstChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Chart 5: Performance -->
            <div class="col-md-12">
                <div class="chart-box">
                    <h6>Performance Indicators</h6>
                    <div class="chart-container" style="height: 240px;">
                        <canvas id="performanceChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Additional Charts -->
        <div class="row mt-3">
            <!-- Chart 6: Monthly Wages -->
            <div class="col-md-6">
                <div class="chart-box">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">Wages by Month</h6>
                        <button class="btn btn-link btn-sm p-0" onclick="saveCanvasPng('wagesChart','wages-by-month')">Save PNG</button>
                    </div>
                    <div class="chart-container">
                        <canvas id="wagesChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Chart 7: Cumulative Families -->
            <div class="col-md-6">
                <div class="chart-box">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">Cumulative Families (Year-to-Date)</h6>
                        <button class="btn btn-link btn-sm p-0" onclick="saveCanvasPng('cumulativeFamiliesChart','cumulative-families')">Save PNG</button>
                    </div>
                    <div class="chart-container">
                        <canvas id="cumulativeFamiliesChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <script>
            // Geolocation to auto-select district (best effort)
            const geoBtn = document.getElementById('geoBtn');
            const geoStatus = document.getElementById('geoStatus');
            if (geoBtn) {
                geoBtn.addEventListener('click', () => {
                    geoStatus.textContent = 'Finding your district...';
                    if (!navigator.geolocation) {
                        geoStatus.textContent = 'Location not supported on this device.';
                        return;
                    }
                    navigator.geolocation.getCurrentPosition(async (pos) => {
                        try {
                            const { latitude, longitude } = pos.coords;
                            const resp = await fetch('geo.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ latitude, longitude })
                            });
                            const data = await resp.json();
                            if (data.error) { throw new Error(data.error); }
                            const districtName = data.district || '';
                            const select = document.querySelector('select[name="district"]');
                            if (districtName && select) {
                                // Try exact, else case-insensitive match
                                let found = false;
                                for (const opt of select.options) {
                                    if (opt.value === districtName || opt.text.toLowerCase() === districtName.toLowerCase()) { opt.selected = true; found = true; break; }
                                }
                                if (found) {
                                    geoStatus.textContent = `Detected: ${districtName}`;
                                    document.getElementById('filterForm').requestSubmit();
                                } else {
                                    geoStatus.textContent = `Detected ${districtName}, not in list.`;
                                }
                            } else {
                                geoStatus.textContent = 'Could not detect district.';
                            }
                        } catch (e) {
                            geoStatus.textContent = 'Location lookup failed. Try again.';
                        }
                    }, () => {
                        geoStatus.textContent = 'Permission denied.';
                    }, { enableHighAccuracy: true, timeout: 8000 });
                });
            }
            // Enable Bootstrap popovers for help hints
            const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
            popoverTriggerList.forEach(function (el) { new bootstrap.Popover(el, { trigger: 'focus hover' }); });
            // Monthly data
            const months = <?= json_encode(array_column($monthly_data, 'month')) ?>;
            const families = <?= json_encode(array_column($monthly_data, 'total_households_worked')) ?>;
            const people = <?= json_encode(array_column($monthly_data, 'total_individuals_worked')) ?>;
            const wages = <?= json_encode(array_column($monthly_data, 'wages')) ?>;
            const compareEnabled = <?= $compare ? 'true' : 'false' ?>;
            const monthsPrev = <?= isset($monthly_prev) ? json_encode(array_column($monthly_prev, 'month')) : '[]' ?>;
            const familiesPrev = <?= isset($monthly_prev) ? json_encode(array_column($monthly_prev, 'total_households_worked')) : '[]' ?>;
            const peoplePrev = <?= isset($monthly_prev) ? json_encode(array_column($monthly_prev, 'total_individuals_worked')) : '[]' ?>;

            // Chart 1: Families by month
            new Chart(document.getElementById('familiesChart'), {
                type: 'bar',
                data: {
                    labels: months,
                    datasets: [
                        {
                            label: 'This Year',
                            data: families,
                            backgroundColor: '#007bff'
                        }
                        <?php if ($compare): ?>,
                        {
                            label: 'Prev Year',
                            data: familiesPrev,
                            backgroundColor: 'rgba(220,53,69,0.6)'
                        }
                        <?php endif; ?>
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false }
            });
            
            // Chart 2: People by month
            new Chart(document.getElementById('peopleChart'), {
                type: 'line',
                data: {
                    labels: months,
                    datasets: [
                        {
                            label: 'This Year',
                            data: people,
                            borderColor: '#28a745',
                            backgroundColor: 'rgba(40,167,69,0.15)',
                            fill: true,
                            tension: 0.3
                        }
                        <?php if ($compare): ?>,
                        {
                            label: 'Prev Year',
                            data: peoplePrev,
                            borderColor: 'rgba(220,53,69,0.8)',
                            backgroundColor: 'rgba(220,53,69,0.15)',
                            fill: true,
                            tension: 0.3
                        }
                        <?php endif; ?>
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false }
            });

            // Chart 6: Wages by month
            new Chart(document.getElementById('wagesChart'), {
                type: 'line',
                data: {
                    labels: months,
                    datasets: [
                        {
                            label: 'This Year',
                            data: wages,
                            borderColor: '#0d6efd',
                            backgroundColor: 'rgba(13,110,253,0.15)',
                            fill: true,
                            tension: 0.25
                        }
                        <?php if ($compare): ?>,
                        {
                            label: 'Prev Year',
                            data: <?= isset($monthly_prev) ? json_encode(array_column($monthly_prev, 'wages')) : '[]' ?>,
                            borderColor: 'rgba(255,193,7,0.9)',
                            backgroundColor: 'rgba(255,193,7,0.15)',
                            fill: true,
                            tension: 0.25
                        }
                        <?php endif; ?>
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false }
            });

            // Chart 7: Cumulative Families
            function cumulative(arr) { const out = []; let s = 0; for (let i=0;i<arr.length;i++){ s += Number(arr[i]||0); out.push(s);} return out; }
            const familiesCum = cumulative(families);
            const familiesPrevCum = <?= $compare && isset($monthly_prev) ? 'cumulative('.json_encode(array_column($monthly_prev, 'total_households_worked')).')' : '[]' ?>;
            new Chart(document.getElementById('cumulativeFamiliesChart'), {
                type: 'line',
                data: {
                    labels: months,
                    datasets: [
                        {
                            label: 'This Year',
                            data: familiesCum,
                            borderColor: '#20c997',
                            backgroundColor: 'rgba(32,201,151,0.12)',
                            fill: true,
                            tension: 0.25
                        }
                        <?php if ($compare): ?>,
                        {
                            label: 'Prev Year',
                            data: familiesPrevCum,
                            borderColor: 'rgba(108,117,125,0.9)',
                            backgroundColor: 'rgba(108,117,125,0.12)',
                            fill: true,
                            tension: 0.25
                        }
                        <?php endif; ?>
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false }
            });

            // Utilities: Save chart as PNG
            function saveCanvasPng(id, file) {
                const canvas = document.getElementById(id);
                if (!canvas) return;
                const a = document.createElement('a');
                a.href = canvas.toDataURL('image/png');
                a.download = file + '.png';
                a.click();
            }

            // CSV export
            window.downloadMonthlyCsv = function() {
                const headers = ['Month','Families','People','Wages'<?php if ($compare): ?>,'FamiliesPrev','PeoplePrev','WagesPrev'<?php endif; ?>];
                const rows = [headers];
                for (let i=0;i<months.length;i++) {
                    const row = [months[i], families[i]||0, people[i]||0, wages[i]||0];
                    <?php if ($compare): ?>
                    row.push((familiesPrev[i]||0), (peoplePrev[i]||0), (<?= isset($monthly_prev) ? json_encode(array_column($monthly_prev, 'wages')) : '[]' ?>[i]||0));
                    <?php endif; ?>
                    rows.push(row);
                }
                const csv = rows.map(r => r.join(',')).join('\n');
                const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = 'mgnrega-monthly.csv';
                link.click();
            }
            
            // Chart 3: Projects
            new Chart(document.getElementById('projectsChart'), {
                type: 'doughnut',
                data: {
                    labels: ['Completed', 'Ongoing'],
                    datasets: [{
                        data: [<?= $data['number_of_completed_works'] ?>, <?= $data['number_of_ongoing_works'] ?>],
                        backgroundColor: ['#28a745', '#17a2b8']
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
            });
            
            // Chart 4: SC/ST
            new Chart(document.getElementById('scstChart'), {
                type: 'bar',
                data: {
                    labels: ['SC', 'ST'],
                    datasets: [{
                        label: 'Work Days',
                        data: [<?= $data['sc_persondays'] ?>, <?= $data['st_persondays'] ?>],
                        backgroundColor: ['#dc3545', '#ffc107']
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
            });
            
            // Chart 5: Performance
            new Chart(document.getElementById('performanceChart'), {
                type: 'bar',
                data: {
                    labels: ['Avg Wage (₹)', '100 Days Families'],
                    datasets: [
                        {
                            label: 'This Year',
                            data: [
                                <?= $data['average_wage_rate_per_day_per_person'] ?>,
                                <?= $data['total_no_of_hhs_completed_100_days_of_wage_employment'] ?>
                            ],
                            backgroundColor: ['#6f42c1', '#e83e8c']
                        }
                        <?php if (!empty($data_prev)): ?>,
                        {
                            label: 'Prev Year',
                            data: [
                                <?= $data_prev['average_wage_rate_per_day_per_person'] ?? 0 ?>,
                                <?= $data_prev['total_no_of_hhs_completed_100_days_of_wage_employment'] ?? 0 ?>
                            ],
                            backgroundColor: ['rgba(108,117,125,0.8)', 'rgba(23,162,184,0.8)']
                        }
                        <?php endif; ?>
                    ]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false,
                    indexAxis: 'y'
                }
            });
        </script>
        <?php endif; ?>
    </div>
</body>
</html> 