<?php
require_once 'db.php';
require_once 'functions.php';

// API Configuration - Check if constants are already defined
if (!defined('API_BASE_URL')) {
    define('API_BASE_URL', 'https://api.data.gov.in/resource/ee03643a-ee4c-48c2-ac30-9f2ff26ab722');
}

if (!defined('API_KEY')) {
    define('API_KEY', '579b464db66ec23bdd00000152d92961e3f6411f76d03b4c1277a084');
}

if (!defined('STATE_NAME')) {
    define('STATE_NAME', 'TAMIL NADU');
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'fetch_api') {
        try {
            $district = $_POST['district'] ?? '';
            $year = $_POST['year'] ?? '';
            $result = fetchFromAPI($district, $year);
            echo json_encode(['success' => true, 'message' => $result['message'], 'count' => $result['count']]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'sync') {
        try {
            $result = syncDataFromFile();
            echo json_encode(['success' => true, 'message' => $result]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'clear') {
        try {
            clearAllData();
            echo json_encode(['success' => true, 'message' => 'All data cleared successfully']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'create_views') {
        try {
            $result = createDatabaseViews();
            echo json_encode(['success' => true, 'message' => 'Database views created successfully', 'count' => $result]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'check_views') {
        try {
            $viewsStatus = checkViewsExist();
            echo json_encode(['success' => true, 'views' => $viewsStatus]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'get_districts_years') {
        try {
            $districts = getAllDistrictsFromDB();
            $years = getAllYearsFromDB();
            echo json_encode(['success' => true, 'districts' => $districts, 'years' => $years]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

// Fetch data from API and save locally
function fetchFromAPI($district, $year) {
    $dataDir = __DIR__ . '/data';
    if (!file_exists($dataDir)) {
        mkdir($dataDir, 0755, true);
    }
    
    $allRecords = [];
    $offset = 0;
    $limit = 1000;
    $totalFetched = 0;
    
    do {
        $url = API_BASE_URL . '?api-key=' . API_KEY . 
               '&format=json' .
               '&filters[state_name]=' . urlencode(STATE_NAME) .
               ($district ? '&filters[district_name]=' . urlencode($district) : '') .
               ($year ? '&filters[fin_year]=' . urlencode($year) : '') .
               '&limit=' . $limit .
               '&offset=' . $offset;
        
        $response = @file_get_contents($url);
        if ($response === false) {
            throw new Exception("Failed to fetch data from API. Please check your internet connection.");
        }
        
        $data = json_decode($response, true);
        if (!isset($data['records']) || !is_array($data['records'])) {
            break;
        }
        
        $records = $data['records'];
        $allRecords = array_merge($allRecords, $records);
        $totalFetched += count($records);
        
        $offset += $limit;
        
        // Break if we got fewer records than the limit (last page)
        if (count($records) < $limit) {
            break;
        }
        
        // Safety limit to prevent infinite loops
        if ($offset > 100000) {
            break;
        }
        
        // Small delay to avoid rate limiting
        usleep(100000); // 0.1 second
        
    } while (true);
    
    if ($totalFetched === 0) {
        throw new Exception("No data found for the selected filters");
    }
    
    // Save to JSON file with timestamp
    $filename = 'mgnrega_';
    if ($district) $filename .= preg_replace('/[^a-zA-Z0-9]/', '_', $district) . '_';
    if ($year) $filename .= preg_replace('/[^a-zA-Z0-9]/', '_', $year) . '_';
    $filename .= date('Y-m-d_His') . '.json';
    
    $filepath = $dataDir . '/' . $filename;
    file_put_contents($filepath, json_encode($allRecords, JSON_PRETTY_PRINT));
    
    logActivity('API Fetch', "Fetched $totalFetched records and saved to $filename");
    
    return [
        'message' => "Successfully fetched $totalFetched records and saved to $filename",
        'count' => $totalFetched,
        'file' => $filename
    ];
}

// Sync data from JSON file to database
function syncDataFromFile() {
    $dataDir = __DIR__ . '/data';
    
    // Get all JSON files
    $files = glob($dataDir . '/*.json');
    if (empty($files)) {
        throw new Exception("No JSON files found in data directory. Please fetch data from API first.");
    }
    
    // Get the most recent file
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    $jsonFile = $files[0];
    
    $jsonData = file_get_contents($jsonFile);
    $data = json_decode($jsonData, true);
    
    if ($data === null) {
        throw new Exception("Invalid JSON format in data file: " . basename($jsonFile));
    }
    
    if (!is_array($data)) {
        $data = [$data];
    }
    
    if (empty($data)) {
        throw new Exception("No data found in JSON file");
    }
    
    $inserted = insertBatchData($data);
    
    logActivity('Data Sync', "Inserted $inserted records from " . basename($jsonFile));
    
    return "Successfully synced $inserted records from " . basename($jsonFile);
}

// Insert data in batches with proper error handling
function insertBatchData($data, $batchSize = 500) {
    $db = getDB();
    $conn = $db->getConnection();
    
    $normalizedData = array_map('normalizeFieldNames', $data);
    if (empty($normalizedData)) {
        return 0;
    }
    
    // Get columns from first record
    $columns = array_keys($normalizedData[0]);
    $columnsStr = implode(", ", $columns);
    
    $insertedCount = 0;
    $errorCount = 0;
    
    for ($i = 0; $i < count($normalizedData); $i += $batchSize) {
        $batch = array_slice($normalizedData, $i, $batchSize);
        $placeholders = [];
        $values = [];
        
        foreach ($batch as $record) {
            $placeholders[] = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
            foreach ($columns as $col) {
                $values[] = $record[$col];
            }
        }
        
        try {
            $sql = "INSERT INTO " . TABLE_NAME . " ($columnsStr) VALUES " . implode(', ', $placeholders);
            $stmt = $conn->prepare($sql);
            $stmt->execute($values);
            
            $insertedCount += count($batch);
        } catch (PDOException $e) {
            $errorCount += count($batch);
            error_log("Batch insert failed: " . $e->getMessage());
        }
    }
    
    if ($errorCount > 0) {
        logActivity('Data Sync Warning', "Inserted $insertedCount, Failed $errorCount records", 'WARNING');
    }
    
    return $insertedCount;
}

// Normalize field names to match database columns
function normalizeFieldNames($record) {
    $fieldMapping = [
        'Approved_Labour_Budget' => 'approved_labour_budget',
        'Average_Wage_rate_per_day_per_person' => 'average_wage_rate_per_day_per_person',
        'Average_days_of_employment_provided_per_Household' => 'average_days_of_employment_provided_per_household',
        'Differently_abled_persons_worked' => 'differently_abled_persons_worked',
        'Material_and_skilled_Wages' => 'material_and_skilled_wages',
        'Number_of_Completed_Works' => 'number_of_completed_works',
        'Number_of_GPs_with_NIL_exp' => 'number_of_gps_with_nil_exp',
        'Number_of_Ongoing_Works' => 'number_of_ongoing_works',
        'Persondays_of_Central_Liability_so_far' => 'persondays_of_central_liability_so_far',
        'SC_persondays' => 'sc_persondays',
        'SC_workers_against_active_workers' => 'sc_workers_against_active_workers',
        'ST_persondays' => 'st_persondays',
        'ST_workers_against_active_workers' => 'st_workers_against_active_workers',
        'Total_Adm_Expenditure' => 'total_adm_expenditure',
        'Total_Exp' => 'total_exp',
        'Total_Households_Worked' => 'total_households_worked',
        'Total_Individuals_Worked' => 'total_individuals_worked',
        'Total_No_of_Active_Job_Cards' => 'total_no_of_active_job_cards',
        'Total_No_of_Active_Workers' => 'total_no_of_active_workers',
        'Total_No_of_HHs_completed_100_Days_of_Wage_Employment' => 'total_no_of_hhs_completed_100_days_of_wage_employment',
        'Total_No_of_JobCards_issued' => 'total_no_of_jobcards_issued',
        'Total_No_of_Workers' => 'total_no_of_workers',
        'Total_No_of_Works_Takenup' => 'total_no_of_works_takenup',
        'Women_Persondays' => 'women_persondays',
        'percent_of_Category_B_Works' => 'percent_of_category_b_works',
        'percent_of_Expenditure_on_Agriculture_Allied_Works' => 'percent_of_expenditure_on_agriculture_allied_works',
        'percent_of_NRM_Expenditure' => 'percent_of_nrm_expenditure',
        'percentage_payments_gererated_within_15_days' => 'percentage_payments_generated_within_15_days',
    ];

    $normalized = [];
    foreach ($record as $key => $value) {
        $newKey = $fieldMapping[$key] ?? strtolower($key);
        if ($value === '' || strtoupper($value) === 'NA') {
            $normalized[$newKey] = null;
        } else {
            $normalized[$newKey] = $value;
        }
    }
    return $normalized;
}

// Clear all data from database
function clearAllData() {
    $db = getDB();
    $db->execute("TRUNCATE TABLE " . TABLE_NAME . " RESTART IDENTITY CASCADE");
    logActivity('Data Clear', 'All data cleared from database');
}

// Get all districts from database
function getAllDistrictsFromDB() {
    $db = getDB();
    try {
        $result = $db->fetchAll("
            SELECT DISTINCT district_name 
            FROM " . TABLE_NAME . " 
            WHERE district_name IS NOT NULL AND district_name != ''
            ORDER BY district_name
        ");
        return array_column($result, 'district_name');
    } catch (Exception $e) {
        error_log("Error fetching districts: " . $e->getMessage());
        return [];
    }
}

// Get all years from database
function getAllYearsFromDB() {
    $db = getDB();
    try {
        $result = $db->fetchAll("
            SELECT DISTINCT fin_year 
            FROM " . TABLE_NAME . " 
            WHERE fin_year IS NOT NULL AND fin_year != ''
            ORDER BY fin_year DESC
        ");
        return array_column($result, 'fin_year');
    } catch (Exception $e) {
        error_log("Error fetching years: " . $e->getMessage());
        return [];
    }
}

// Enhanced database views creation (without v_yearly_summary)
function createDatabaseViews() {
    $db = getDB();
    
    $views = [
        // Records per district and year
        "CREATE OR REPLACE VIEW v_records_per_district_year AS
        SELECT 
            district_name, 
            fin_year, 
            COUNT(*) AS record_count,
            ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER (PARTITION BY fin_year), 2) as percentage_of_year
        FROM " . TABLE_NAME . "
        WHERE district_name IS NOT NULL AND fin_year IS NOT NULL
        GROUP BY district_name, fin_year
        ORDER BY fin_year DESC, record_count DESC",
        
        // Records per year
        "CREATE OR REPLACE VIEW v_records_per_year AS
        SELECT 
            fin_year, 
            COUNT(*) AS record_count,
            COUNT(DISTINCT district_name) as district_count
        FROM " . TABLE_NAME . "
        WHERE fin_year IS NOT NULL
        GROUP BY fin_year
        ORDER BY fin_year DESC",
        
        // Records per district
        "CREATE OR REPLACE VIEW v_records_per_district AS
        SELECT 
            district_name, 
            COUNT(*) AS record_count,
            COUNT(DISTINCT fin_year) as year_count,
            MIN(fin_year) as first_year,
            MAX(fin_year) as last_year
        FROM " . TABLE_NAME . "
        WHERE district_name IS NOT NULL
        GROUP BY district_name
        ORDER BY record_count DESC",
        
        // District-year summary with key metrics
        "CREATE OR REPLACE VIEW v_district_year_summary AS
        SELECT 
            district_name,
            fin_year,
            COUNT(*) as total_records,
            AVG(CAST(total_exp AS NUMERIC)) as avg_total_exp,
            AVG(CAST(total_households_worked AS NUMERIC)) as avg_households_worked,
            AVG(CAST(total_individuals_worked AS NUMERIC)) as avg_individuals_worked,
            AVG(CAST(total_no_of_active_workers AS NUMERIC)) as avg_active_workers,
            SUM(CAST(total_exp AS NUMERIC)) as sum_total_exp
        FROM " . TABLE_NAME . "
        WHERE district_name IS NOT NULL AND fin_year IS NOT NULL
        GROUP BY district_name, fin_year
        ORDER BY fin_year DESC, sum_total_exp DESC"
    ];
    
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($views as $index => $sql) {
        try {
            $db->execute($sql);
            $successCount++;
        } catch (Exception $e) {
            $errorCount++;
            error_log("Failed to create view $index: " . $e->getMessage());
        }
    }
    
    logActivity('Create Views', "Created $successCount views, $errorCount failed");
    
    if ($errorCount > 0) {
        throw new Exception("Created $successCount views but failed to create $errorCount views. Check error log for details.");
    }
    
    return $successCount;
}

// Enhanced database statistics with view data
function getDatabaseStats() {
    $db = getDB();
    
    $stats = [
        'total_records' => 0,
        'districts' => 0,
        'years' => 0,
        'by_district' => [],
        'by_year' => [],
        'by_district_year' => [],
        'yearly_summary' => [],
        'district_summary' => [],
        'all_districts' => [],
        'all_years' => []
    ];
    
    try {
        // Basic counts
        $result = $db->fetchOne("SELECT COUNT(*) as count FROM " . TABLE_NAME);
        $stats['total_records'] = $result['count'] ?? 0;
        
        $result = $db->fetchOne("SELECT COUNT(DISTINCT district_name) as count FROM " . TABLE_NAME);
        $stats['districts'] = $result['count'] ?? 0;
        
        $result = $db->fetchOne("SELECT COUNT(DISTINCT fin_year) as count FROM " . TABLE_NAME);
        $stats['years'] = $result['count'] ?? 0;
        
        // Get all districts and years
        $stats['all_districts'] = getAllDistrictsFromDB();
        $stats['all_years'] = getAllYearsFromDB();
        
        // Try to fetch from views if they exist
        try {
            // Records by district
            $stats['by_district'] = $db->fetchAll("
                SELECT * FROM v_records_per_district 
                ORDER BY record_count DESC 
                LIMIT 15
            ");
            
            // Records by year
            $stats['by_year'] = $db->fetchAll("SELECT * FROM v_records_per_year");
            
            // District-year combinations
            $stats['by_district_year'] = $db->fetchAll("
                SELECT * FROM v_records_per_district_year 
                ORDER BY fin_year DESC, record_count DESC 
                LIMIT 20
            ");
            
            // Yearly summary from existing v_yearly_summary view
            $stats['yearly_summary'] = $db->fetchAll("
                SELECT * FROM v_yearly_summary 
                ORDER BY fin_year DESC
            ");
            
            // Top districts summary
            $stats['district_summary'] = $db->fetchAll("
                SELECT * FROM v_district_year_summary 
                ORDER BY fin_year DESC, sum_total_exp DESC 
                LIMIT 10
            ");
            
        } catch (Exception $e) {
            // Views don't exist yet, fall back to direct queries
            $stats['by_district'] = $db->fetchAll("
                SELECT district_name, COUNT(*) as record_count 
                FROM " . TABLE_NAME . " 
                WHERE district_name IS NOT NULL 
                GROUP BY district_name 
                ORDER BY record_count DESC 
                LIMIT 10
            ");
            
            $stats['by_year'] = $db->fetchAll("
                SELECT fin_year, COUNT(*) as record_count 
                FROM " . TABLE_NAME . " 
                WHERE fin_year IS NOT NULL 
                GROUP BY fin_year 
                ORDER BY fin_year DESC
            ");
            
            // Fallback for yearly summary
            $stats['yearly_summary'] = $db->fetchAll("
                SELECT 
                    fin_year,
                    COUNT(*) as total_records,
                    COUNT(DISTINCT district_name) as districts_covered,
                    ROUND(AVG(CAST(total_exp AS NUMERIC)), 2) as avg_expenditure,
                    ROUND(SUM(CAST(total_exp AS NUMERIC)), 2) as total_expenditure,
                    ROUND(AVG(CAST(total_households_worked AS NUMERIC)), 2) as avg_households,
                    ROUND(AVG(CAST(total_individuals_worked AS NUMERIC)), 2) as avg_individuals
                FROM " . TABLE_NAME . "
                WHERE fin_year IS NOT NULL
                GROUP BY fin_year
                ORDER BY fin_year DESC
            ");
        }
        
    } catch (Exception $e) {
        error_log("Error getting database stats: " . $e->getMessage());
    }
    
    return $stats;
}

// Check if database views exist
function checkViewsExist() {
    $db = getDB();
    $views = [
        'v_records_per_district_year',
        'v_records_per_year', 
        'v_records_per_district',
        'v_district_year_summary',
        'v_yearly_summary'  // Keep this to check if it exists
    ];
    
    $existingViews = [];
    
    foreach ($views as $viewName) {
        try {
            $result = $db->fetchOne("SELECT COUNT(*) as count FROM $viewName LIMIT 1");
            $existingViews[$viewName] = true;
        } catch (Exception $e) {
            $existingViews[$viewName] = false;
        }
    }
    
    return $existingViews;
}

// Get Tamil Nadu districts list (fallback)
function getTamilNaduDistricts() {
    return [
        'ARIYALUR', 'CHENGALPATTU', 'CHENNAI', 'COIMBATORE', 'CUDDALORE',
        'DHARMAPURI', 'DINDIGUL', 'ERODE', 'KALLAKURICHI', 'KANCHIPURAM',
        'KANYAKUMARI', 'KARUR', 'KRISHNAGIRI', 'MADURAI', 'MAYILADUTHURAI',
        'NAGAPATTINAM', 'NAMAKKAL', 'NILGIRIS', 'PERAMBALUR', 'PUDUKKOTTAI',
        'RAMANATHAPURAM', 'RANIPET', 'SALEM', 'SIVAGANGA', 'TENKASI',
        'THANJAVUR', 'THENI', 'THOOTHUKUDI', 'TIRUCHIRAPPALLI', 'TIRUNELVELI',
        'TIRUPATHUR', 'TIRUPPUR', 'TIRUVALLUR', 'TIRUVANNAMALAI', 'TIRUVARUR',
        'VELLORE', 'VILUPPURAM', 'VIRUDHUNAGAR'
    ];
}

$stats = getDatabaseStats();
$viewStatus = checkViewsExist();
$existingViewsCount = count(array_filter($viewStatus));
$totalViewsCount = count($viewStatus);

// Use database districts and years, fallback to static list if empty
$dbDistricts = !empty($stats['all_districts']) ? $stats['all_districts'] : getTamilNaduDistricts();
$dbYears = !empty($stats['all_years']) ? $stats['all_years'] : ['2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
?>
<!DOCTYPE html>
<html lang="ta">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>தரவு ஒத்திசைவு - MGNREGA டாஷ்போர்டு</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
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
        .view-status-badge {
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 20px;
        }
        .loading-spinner {
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .language-badge {
            background: linear-gradient(45deg, #FF9933, #FF9933, #138808, #138808);
            color: white;
            font-weight: bold;
        }
        .yearly-stats-table th {
            background-color: #667eea;
            color: white;
        }
        .available-data-card {
            border-left: 4px solid #28a745;
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
        <!-- Current Statistics -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card stat-card">
                    <div class="card-header bg-white">
                        <h3 class="section-title mb-0">
                            <i class="bi bi-database-check me-2"></i>
                            தற்போதைய தரவுத்தள நிலை
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="row g-4">
                            <div class="col-md-4">
                                <div class="card bg-primary text-white stat-card">
                                    <div class="card-body text-center">
                                        <i class="bi bi-file-text display-4 mb-3"></i>
                                        <h4 class="card-title">மொத்த பதிவுகள்</h4>
                                        <h2 class="display-4 fw-bold"><?= number_format($stats['total_records']) ?></h2>
                                        <p class="mb-0">Total Records</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-success text-white stat-card">
                                    <div class="card-body text-center">
                                        <i class="bi bi-map display-4 mb-3"></i>
                                        <h4 class="card-title">மாவட்டங்கள்</h4>
                                        <h2 class="display-4 fw-bold"><?= number_format($stats['districts']) ?></h2>
                                        <p class="mb-0">Districts Covered</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-info text-white stat-card">
                                    <div class="card-body text-center">
                                        <i class="bi bi-calendar-range display-4 mb-3"></i>
                                        <h4 class="card-title">நிதியாண்டுகள்</h4>
                                        <h2 class="display-4 fw-bold"><?= number_format($stats['years']) ?></h2>
                                        <p class="mb-0">Financial Years</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Available Data Summary -->
                        <div class="row mt-4">
                            <div class="col-md-6">
                                <div class="card available-data-card h-100">
                                    <div class="card-header bg-success text-white">
                                        <h5 class="mb-0">
                                            <i class="bi bi-map me-2"></i>
                                            கிடைக்கும் மாவட்டங்கள்
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="row g-2" style="max-height: 200px; overflow-y: auto;">
                                            <?php foreach ($dbDistricts as $district): ?>
                                            <div class="col-md-6">
                                                <div class="bg-light p-2 rounded border text-center">
                                                    <small class="fw-semibold"><?= htmlspecialchars($district) ?></small>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="mt-2 text-center">
                                            <small class="text-muted">மொத்தம்: <?= count($dbDistricts) ?> மாவட்டங்கள்</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card available-data-card h-100">
                                    <div class="card-header bg-info text-white">
                                        <h5 class="mb-0">
                                            <i class="bi bi-calendar3 me-2"></i>
                                            கிடைக்கும் நிதியாண்டுகள்
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="row g-2">
                                            <?php foreach ($dbYears as $year): ?>
                                            <div class="col-6">
                                                <div class="bg-light p-2 rounded border text-center">
                                                    <small class="fw-semibold"><?= htmlspecialchars($year) ?></small>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="mt-2 text-center">
                                            <small class="text-muted">மொத்தம்: <?= count($dbYears) ?> நிதியாண்டுகள்</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Yearly Summary from v_yearly_summary view -->
                        <?php if (!empty($stats['yearly_summary'])): ?>
                        <div class="row mt-4">
                            <div class="col-12">
                                <div class="card border-primary">
                                    <div class="card-header bg-primary text-white">
                                        <h5 class="mb-0">
                                            <i class="bi bi-graph-up me-2"></i>
                                            ஆண்டு வாரியான சுருக்கம் (v_yearly_summary view)
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-striped table-hover yearly-stats-table">
                                                <thead>
                                                    <tr>
                                                        <th>நிதியாண்டு</th>
                                                        <th>மொத்த பதிவுகள்</th>
                                                        <th>மாவட்டங்கள்</th>
                                                        <th>சராசரி செலவு (₹)</th>
                                                        <th>மொத்த செலவு (₹)</th>
                                                        <th>சராசரி குடும்பங்கள்</th>
                                                        <th>சராசரி நபர்கள்</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($stats['yearly_summary'] as $yearly): ?>
                                                    <tr>
                                                        <td><strong><?= htmlspecialchars($yearly['fin_year']) ?></strong></td>
                                                        <td><?= number_format($yearly['total_records']) ?></td>
                                                        <td><?= number_format($yearly['districts_covered']) ?></td>
                                                        <td>₹<?= number_format($yearly['avg_expenditure'] ?? 0, 2) ?></td>
                                                        <td>₹<?= number_format($yearly['total_expenditure'] ?? 0, 2) ?></td>
                                                        <td><?= number_format($yearly['avg_households'] ?? 0, 2) ?></td>
                                                        <td><?= number_format($yearly['avg_individuals'] ?? 0, 2) ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                
                    </div>
                </div>
            </div>
        </div>

        <!-- Fetch from API Section -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card stat-card">
                    <div class="card-header bg-white">
                        <h3 class="section-title mb-0 text-warning">
                            <i class="bi bi-cloud-download me-2"></i>
                            API-யிலிருந்து தரவைப் பெறுங்கள்
                        </h3>
                    </div>
                    <div class="card-body">
                    
                        
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">மாவட்டம்</label>
                                <select id="apiDistrict" class="form-select form-select-lg">
                                    <option value="">அனைத்து மாவட்டங்களும்</option>
                                    <?php foreach ($dbDistricts as $dist): ?>
                                    <option value="<?= $dist ?>"><?= $dist ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">All Districts from Database</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">நிதியாண்டு</label>
                                <select id="apiYear" class="form-select form-select-lg">
                                    <option value="">அனைத்து ஆண்டுகளும்</option>
                                    <?php foreach ($dbYears as $year): ?>
                                    <option value="<?= $year ?>"><?= $year ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Financial Years from Database</div>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button id="fetchBtn" class="btn btn-warning btn-action w-100">
                                    <i class="bi bi-cloud-download me-2"></i>
                                    API-லிருந்து பெறுக
                                </button>
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <i class="bi bi-lightbulb me-2"></i>
                            <strong>குறிப்பு:</strong> இது data.gov.in API-லிருந்து தரவைப் பெற்று உள்ளூரில் JSON வடிவத்தில் சேமிக்கும். 
                            பெற்ற பிறகு, "தரவுத்தளத்துடன் ஒத்திசைக்க" கீழே கிளிக் செய்யவும்.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Data Operations Section -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card stat-card">
                    <div class="card-header bg-white">
                        <h3 class="section-title mb-0 text-success">
                            <i class="bi bi-gear me-2"></i>
                            தரவு செயல்பாடுகள்
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="row g-4">
                            <!-- Sync Data -->
                            <div class="col-md-6">
                                <div class="card border-success h-100">
                                    <div class="card-header bg-success text-white">
                                        <h5 class="mb-0">
                                            <i class="bi bi-arrow-repeat me-2"></i>
                                            தரவுத்தளத்துடன் ஒத்திசைக்க
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <p class="card-text">
                                            உள்ளூர் சேமிப்பிலிருந்து சமீபத்திய JSON கோப்பை தரவுத்தளத்தில் ஏற்றவும். 
                                            இது இருக்கும் தரவில் புதிய பதிவுகளைச் சேர்க்கும்.
                                        </p>
                                        <p class="text-muted small">
                                            Load the most recent JSON file from local storage into the database.
                                        </p>
                                        <button id="syncBtn" class="btn btn-success btn-action w-100">
                                            <i class="bi bi-arrow-repeat me-2"></i>
                                            ஒத்திசைக்க
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="card bg-light h-100">
                                    <div class="card-body">
                                        <h5 class="card-title text-success">
                                            <i class="bi bi-play-circle me-2"></i>
                                            முதல் முறை அமைப்பு
                                        </h5>
                                        <ol class="list-group list-group-numbered">
                                            <li class="list-group-item border-0 bg-transparent">மாவட்டம் மற்றும் ஆண்டைத் தேர்ந்தெடுக்கவும்</li>
                                            <li class="list-group-item border-0 bg-transparent">"API-லிருந்து பெறுக" கிளிக் செய்யவும்</li>
                                            <li class="list-group-item border-0 bg-transparent">வெற்றி செய்திக்காக காத்திருக்கவும்</li>
                                            <li class="list-group-item border-0 bg-transparent">"ஒத்திசைக்க" கிளிக் செய்யவும்</li>
                                            <li class="list-group-item border-0 bg-transparent">"காட்சிகளை உருவாக்கு" கிளிக் செய்யவும்</li>
                                            <li class="list-group-item border-0 bg-transparent">டாஷ்போர்டைப் பார்க்கவும்!</li>
                                        </ol>
                                    </div>
                                </div>
                            </div>
                   
                </div>

                            
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status Messages -->
        <div id="statusArea" class="d-none mb-4">
            <div id="statusContent" class="card">
                <div class="card-body">
                    <div id="statusMessage" class="d-flex align-items-center">
                        <i id="statusIcon" class="bi me-3 fs-4"></i>
                        <div>
                            <h5 id="statusTitle" class="mb-1"></h5>
                            <p id="statusText" class="mb-0"></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

      
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
    <script>
    // Elements
    const fetchBtn = document.getElementById('fetchBtn');
    const syncBtn = document.getElementById('syncBtn');
    const clearBtn = document.getElementById('clearBtn');
    const viewsBtn = document.getElementById('viewsBtn');
    const checkViewsBtn = document.getElementById('checkViewsBtn');
    const statusArea = document.getElementById('statusArea');
    const statusIcon = document.getElementById('statusIcon');
    const statusTitle = document.getElementById('statusTitle');
    const statusText = document.getElementById('statusText');

    // Show status message
    function showStatus(type, title, message, autoHide = true) {
        const colors = {
            'success': { bg: 'bg-success', icon: 'bi-check-circle-fill', title: 'வெற்றி' },
            'error': { bg: 'bg-danger', icon: 'bi-x-circle-fill', title: 'பிழை' },
            'warning': { bg: 'bg-warning', icon: 'bi-exclamation-triangle-fill', title: 'எச்சரிக்கை' },
            'info': { bg: 'bg-info', icon: 'bi-info-circle-fill', title: 'தகவல்' }
        };

        const config = colors[type];
        
        statusArea.className = `card border-0 ${config.bg} text-white`;
        statusIcon.className = `bi ${config.icon} me-3 fs-4`;
        statusTitle.textContent = `${config.title}: ${title}`;
        statusText.textContent = message;
        
        statusArea.classList.remove('d-none');
        statusArea.scrollIntoView({ behavior: 'smooth', block: 'center' });

        if (autoHide && type !== 'error') {
            setTimeout(() => {
                statusArea.classList.add('d-none');
            }, 5000);
        }
    }

    // Set button loading state
    function setButtonLoading(button, isLoading) {
        if (isLoading) {
            button.disabled = true;
            button.innerHTML = `<span class="loading-spinner"><i class="bi bi-arrow-repeat"></i></span> ${button.dataset.originalText || button.textContent}`;
        } else {
            button.disabled = false;
            button.innerHTML = button.dataset.originalText || button.innerHTML;
        }
    }

    // Store original button texts
    document.querySelectorAll('.btn-action').forEach(btn => {
        btn.dataset.originalText = btn.innerHTML;
    });

    // Fetch from API
    fetchBtn.addEventListener('click', async () => {
        const district = document.getElementById('apiDistrict').value;
        const year = document.getElementById('apiYear').value;
        
        if (!confirm('API-லிருந்து தரவைப் பெறவா? இது தரவின் அளவைப் பொறுத்து சில நிமிடங்கள் எடுக்கலாம்.')) {
            return;
        }

        showStatus('info', 'தரவு பெறப்படுகிறது', 'API-லிருந்து தரவைப் பெறுகிறது... தயவு செய்து காத்திருக்கவும்...', false);
        setButtonLoading(fetchBtn, true);

        try {
            const formData = new FormData();
            formData.append('action', 'fetch_api');
            formData.append('district', district);
            formData.append('year', year);

            const response = await fetch('sync.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                showStatus('success', 'தரவு பெறப்பட்டது', data.message + ' இப்போது தரவுத்தளத்தில் ஏற்ற "ஒத்திசைக்க" கிளிக் செய்யவும்.');
            } else {
                showStatus('error', 'பிழை', data.error);
            }
        } catch (error) {
            showStatus('error', 'நெட்வொர்க் பிழை', error.message);
        } finally {
            setButtonLoading(fetchBtn, false);
        }
    });

    // Sync data
    syncBtn.addEventListener('click', async () => {
        if (!confirm('தரவுத்தளத்துடன் தரவை ஒத்திசைக்கவா? இது சமீபத்திய JSON கோப்பை தரவுத்தளத்தில் ஏற்றும்.')) {
            return;
        }

        showStatus('info', 'ஒத்திசைக்கப்படுகிறது', 'தரவுத்தளத்துடன் தரவை ஒத்திசைக்கிறது... தயவு செய்து காத்திருக்கவும்...', false);
        setButtonLoading(syncBtn, true);

        try {
            const formData = new FormData();
            formData.append('action', 'sync');

            const response = await fetch('sync.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                showStatus('success', 'ஒத்திசைக்கப்பட்டது', data.message + ' பக்கம் புதுப்பிக்கப்படுகிறது...');
                setTimeout(() => location.reload(), 3000);
            } else {
                showStatus('error', 'பிழை', data.error);
            }
        } catch (error) {
            showStatus('error', 'நெட்வொர்க் பிழை', error.message);
        } finally {
            setButtonLoading(syncBtn, false);
        }
    });

    // Create views
    viewsBtn.addEventListener('click', async () => {
        if (!confirm('தரவுத்தள காட்சிகளை உருவாக்கவா? இது சிறந்த செயல்திறனுக்காக உகந்த காட்சிகளை உருவாக்கும்.')) {
            return;
        }

        showStatus('info', 'காட்சிகள் உருவாக்கப்படுகின்றன', 'தரவுத்தள காட்சிகள் உருவாக்கப்படுகின்றன... தயவு செய்து காத்திருக்கவும்...', false);
        setButtonLoading(viewsBtn, true);

        try {
            const formData = new FormData();
            formData.append('action', 'create_views');

            const response = await fetch('sync.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                showStatus('success', 'காட்சிகள் உருவாக்கப்பட்டன', `${data.message} ${data.count} views created. பக்கம் புதுப்பிக்கப்படுகிறது...`);
                setTimeout(() => location.reload(), 3000);
            } else {
                showStatus('error', 'பிழை', data.error);
            }
        } catch (error) {
            showStatus('error', 'நெட்வொர்க் பிழை', error.message);
        } finally {
            setButtonLoading(viewsBtn, false);
        }
    });

    // Check views status
    checkViewsBtn.addEventListener('click', async () => {
        setButtonLoading(checkViewsBtn, true);

        try {
            const formData = new FormData();
            formData.append('action', 'check_views');

            const response = await fetch('sync.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                const container = document.getElementById('viewsStatusContainer');
                container.innerHTML = '';
                
                Object.entries(data.views).forEach(([viewName, exists]) => {
                    const col = document.createElement('div');
                    col.className = 'col-md-4';
                    col.innerHTML = `
                        <div class="d-flex justify-content-between align-items-center p-2 bg-white rounded border">
                            <span class="text-sm">${viewName}</span>
                            <span class="badge bg-${exists ? 'success' : 'danger'} view-status-badge">
                                ${exists ? '✓' : '✗'}
                            </span>
                        </div>
                    `;
                    container.appendChild(col);
                });
                
                showStatus('success', 'புதுப்பிக்கப்பட்டது', 'காட்சி நிலை வெற்றிகரமாக புதுப்பிக்கப்பட்டது.');
            } else {
                showStatus('error', 'பிழை', data.error);
            }
        } catch (error) {
            showStatus('error', 'நெட்வொர்க் பிழை', error.message);
        } finally {
            setButtonLoading(checkViewsBtn, false);
        }
    });

    // Clear all data
    clearBtn.addEventListener('click', async () => {
        const confirmText = 'DELETE ALL DATA';
        const userInput = prompt(
            `⚠️ எச்சரிக்கை: இது தரவுத்தளத்திலிருந்து அனைத்து தரவையும் நிரந்தரமாக நீக்கும்!\n\n` +
            `இந்த செயலை திரும்பப் பெற முடியாது!\n\n` +
            `உறுதிப்படுத்த "${confirmText}" (மேற்கோள்கள் இல்லாமல்) தட்டச்சு செய்யவும்:`
        );

        if (userInput !== confirmText) {
            if (userInput !== null) {
                alert('❌ உறுதிப்படுத்தல் உரை பொருந்தவில்லை. உங்கள் பாதுகாப்பிற்காக செயல் ரத்து செய்யப்பட்டது.');
            }
            return;
        }

        showStatus('warning', 'தரவு அழிக்கப்படுகிறது', 'அனைத்து தரவையும் தரவுத்தளத்திலிருந்து அழிக்கிறது... தயவு செய்து காத்திருக்கவும்...', false);
        setButtonLoading(clearBtn, true);

        try {
            const formData = new FormData();
            formData.append('action', 'clear');

            const response = await fetch('sync.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                showStatus('success', 'தரவு அழிக்கப்பட்டது', data.message + ' பக்கம் புதுப்பிக்கப்படுகிறது...');
                setTimeout(() => location.reload(), 3000);
            } else {
                showStatus('error', 'பிழை', data.error);
            }
        } catch (error) {
            showStatus('error', 'நெட்வொர்க் பிழை', error.message);
        } finally {
            setButtonLoading(clearBtn, false);
        }
    });

    // Refresh districts and years from database
    async function refreshDataLists() {
        try {
            const formData = new FormData();
            formData.append('action', 'get_districts_years');

            const response = await fetch('sync.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                // Update districts dropdown
                const districtSelect = document.getElementById('apiDistrict');
                districtSelect.innerHTML = '<option value="">அனைத்து மாவட்டங்களும்</option>';
                data.districts.forEach(district => {
                    districtSelect.innerHTML += `<option value="${district}">${district}</option>`;
                });

                // Update years dropdown
                const yearSelect = document.getElementById('apiYear');
                yearSelect.innerHTML = '<option value="">அனைத்து ஆண்டுகளும்</option>';
                data.years.forEach(year => {
                    yearSelect.innerHTML += `<option value="${year}">${year}</option>`;
                });

                showStatus('success', 'புதுப்பிக்கப்பட்டது', 'மாவட்டங்கள் மற்றும் ஆண்டுகளின் பட்டியல் வெற்றிகரமாக புதுப்பிக்கப்பட்டது.');
            }
        } catch (error) {
            console.error('Error refreshing data lists:', error);
        }
    }

    // Refresh data lists when page loads
    document.addEventListener('DOMContentLoaded', function() {
        refreshDataLists();
    });
    </script>
</body>
</html>