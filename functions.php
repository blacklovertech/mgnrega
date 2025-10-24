<?php
// includes/functions.php - Core Functions

// Get list of districts
function getDistricts() {
    $db = getDB();
    $sql = "SELECT DISTINCT district_name FROM " . TABLE_NAME . " 
            WHERE district_name IS NOT NULL 
            ORDER BY district_name";
    $result = $db->fetchAll($sql);
    return array_column($result, 'district_name');
}

// Get list of financial years
function getYears() {
    $db = getDB();
    $sql = "SELECT DISTINCT fin_year FROM " . TABLE_NAME . " 
            WHERE fin_year IS NOT NULL 
            ORDER BY fin_year DESC";
    $result = $db->fetchAll($sql);
    return array_column($result, 'fin_year');
}

// Get complete dashboard data
function getDashboardData($district, $year) {
    return [
        'overview' => getOverview($district, $year),
        'monthly' => getMonthlyData($district, $year),
        'scst' => getSCSTData($district, $year),
        'projects' => getProjectData($district, $year),
        'budget' => getBudgetData($district, $year),
        'wages' => getWagesData($district, $year)
    ];
}

// Get overview statistics
function getOverview($district, $year) {
    $db = getDB();
    $sql = "SELECT
        COALESCE(SUM(total_households_worked), 0) AS total_households,
        COALESCE(SUM(total_individuals_worked), 0) AS total_individuals,
        COALESCE(SUM(women_persondays), 0) AS total_women_days,
        COALESCE(AVG(average_days_of_employment_provided_per_household), 0) AS avg_employment_days,
        COALESCE(SUM(number_of_completed_works), 0) AS total_completed_works,
        COALESCE(SUM(number_of_ongoing_works), 0) AS total_ongoing_works,
        COALESCE(SUM(total_exp), 0) AS total_expenditure,
        COALESCE(SUM(total_no_of_active_job_cards), 0) AS total_job_cards,
        COALESCE(SUM(total_no_of_active_workers), 0) AS total_active_workers
    FROM " . TABLE_NAME . "
    WHERE district_name = :district AND fin_year = :year";
    
    $result = $db->fetchOne($sql, [':district' => $district, ':year' => $year]);
    
    // Ensure all values are numeric
    foreach ($result as $key => $value) {
        $result[$key] = $value ?? 0;
    }
    
    return $result;
}

// Get monthly breakdown data
function getMonthlyData($district, $year) {
    $db = getDB();
    $sql = "SELECT 
        month,
        COALESCE(SUM(total_households_worked), 0) AS total_households,
        COALESCE(SUM(total_individuals_worked), 0) AS total_individuals,
        COALESCE(SUM(wages + material_and_skilled_wages), 0) AS total_wages,
        COALESCE(SUM(women_persondays), 0) AS women_days,
        COALESCE(SUM(sc_persondays), 0) AS sc_days,
        COALESCE(SUM(st_persondays), 0) AS st_days
    FROM " . TABLE_NAME . "
    WHERE district_name = :district AND fin_year = :year
    GROUP BY month
    ORDER BY CASE month
        WHEN 'April' THEN 1 WHEN 'May' THEN 2 WHEN 'June' THEN 3 
        WHEN 'July' THEN 4 WHEN 'August' THEN 5 WHEN 'September' THEN 6 
        WHEN 'October' THEN 7 WHEN 'November' THEN 8 WHEN 'December' THEN 9 
        WHEN 'January' THEN 10 WHEN 'February' THEN 11 WHEN 'March' THEN 12
        ELSE 13
    END";
    
    return $db->fetchAll($sql, [':district' => $district, ':year' => $year]);
}

// Get SC/ST participation data
function getSCSTData($district, $year) {
    $db = getDB();
    $sql = "SELECT 
        COALESCE(SUM(sc_persondays), 0) AS sc_days, 
        COALESCE(SUM(st_persondays), 0) AS st_days,
        COALESCE(SUM(sc_workers_against_active_workers), 0) AS sc_workers,
        COALESCE(SUM(st_workers_against_active_workers), 0) AS st_workers,
        COALESCE(SUM(total_no_of_active_workers), 0) AS total_workers
    FROM " . TABLE_NAME . "
    WHERE district_name = :district AND fin_year = :year";
    
    $result = $db->fetchOne($sql, [':district' => $district, ':year' => $year]);
    
    // Calculate percentages
    $totalWorkers = $result['total_workers'] ?? 0;
    if ($totalWorkers > 0) {
        $result['sc_percentage'] = round(($result['sc_workers'] / $totalWorkers) * 100, 2);
        $result['st_percentage'] = round(($result['st_workers'] / $totalWorkers) * 100, 2);
    } else {
        $result['sc_percentage'] = 0;
        $result['st_percentage'] = 0;
    }
    
    return $result;
}

// Get project data
function getProjectData($district, $year) {
    $db = getDB();
    $sql = "SELECT 
        COALESCE(SUM(number_of_completed_works), 0) AS completed_works,
        COALESCE(SUM(number_of_ongoing_works), 0) AS ongoing_works,
        COALESCE(SUM(total_no_of_works_takenup), 0) AS total_works,
        COALESCE(AVG(percent_of_category_b_works), 0) AS category_b_percent
    FROM " . TABLE_NAME . "
    WHERE district_name = :district AND fin_year = :year";
    
    $result = $db->fetchOne($sql, [':district' => $district, ':year' => $year]);
    
    // Calculate completion rate
    $totalWorks = $result['total_works'] ?? 0;
    if ($totalWorks > 0) {
        $result['completion_rate'] = round(($result['completed_works'] / $totalWorks) * 100, 2);
    } else {
        $result['completion_rate'] = 0;
    }
    
    return $result;
}

// Get budget utilization data
function getBudgetData($district, $year) {
    $db = getDB();
    $sql = "SELECT 
        COALESCE(SUM(approved_labour_budget), 0) AS approved_budget,
        COALESCE(SUM(total_exp), 0) AS total_expenditure,
        COALESCE(SUM(wages), 0) AS wage_expenditure,
        COALESCE(SUM(material_and_skilled_wages), 0) AS material_expenditure,
        COALESCE(SUM(total_adm_expenditure), 0) AS admin_expenditure
    FROM " . TABLE_NAME . "
    WHERE district_name = :district AND fin_year = :year";
    
    $result = $db->fetchOne($sql, [':district' => $district, ':year' => $year]);
    
    // Calculate utilization percentage
    $approvedBudget = $result['approved_budget'] ?? 0;
    if ($approvedBudget > 0) {
        $result['budget_utilization_percent'] = round(($result['total_expenditure'] / $approvedBudget) * 100, 2);
    } else {
        $result['budget_utilization_percent'] = 0;
    }
    
    return $result;
}

// Get wages data
function getWagesData($district, $year) {
    $db = getDB();
    $sql = "SELECT 
        COALESCE(AVG(average_wage_rate_per_day_per_person), 0) AS avg_wage_rate,
        COALESCE(AVG(percentage_payments_generated_within_15_days), 0) AS payment_timeliness
    FROM " . TABLE_NAME . "
    WHERE district_name = :district AND fin_year = :year";
    
    return $db->fetchOne($sql, [':district' => $district, ':year' => $year]);
}

// Get comparison data (district vs state average)
function getComparisonData($district, $year) {
    $db = getDB();
    
    // Get district data
    $districtSql = "SELECT 
        AVG(average_days_of_employment_provided_per_household) AS avg_employment_days
    FROM " . TABLE_NAME . "
    WHERE district_name = :district AND fin_year = :year";
    
    $districtData = $db->fetchOne($districtSql, [':district' => $district, ':year' => $year]);
    
    // Get state average
    $stateSql = "SELECT 
        AVG(average_days_of_employment_provided_per_household) AS avg_employment_days
    FROM " . TABLE_NAME . "
    WHERE fin_year = :year";
    
    $stateData = $db->fetchOne($stateSql, [':year' => $year]);
    
    return [
        'district' => $districtData['avg_employment_days'] ?? 0,
        'state' => $stateData['avg_employment_days'] ?? 0
    ];
}

// Get year-over-year comparison
function getYearComparison($district, $currentYear, $previousYear) {
    $db = getDB();
    $sql = "SELECT 
        fin_year,
        COALESCE(SUM(total_households_worked), 0) AS total_households,
        COALESCE(SUM(total_exp), 0) AS total_expenditure
    FROM " . TABLE_NAME . "
    WHERE district_name = :district 
        AND fin_year IN (:current, :previous)
    GROUP BY fin_year";
    
    return $db->fetchAll($sql, [
        ':district' => $district, 
        ':current' => $currentYear, 
        ':previous' => $previousYear
    ]);
}

// Format large numbers for display (Indian numbering system)
function formatNumber($number) {
    if ($number === null || $number === '') return '0';
    $number = (float) $number;
    
    if ($number >= 1e9) {
        return number_format($number / 1e9, 2) . 'B';
    } elseif ($number >= 1e7) {
        return number_format($number / 1e7, 2) . ' Cr';
    } elseif ($number >= 1e5) {
        return number_format($number / 1e5, 2) . ' L';
    } elseif ($number >= 1e3) {
        return number_format($number / 1e3, 1) . 'K';
    }
    
    return number_format($number, 0);
}

// Format currency in INR
function formatCurrency($amount) {
    if ($amount === null || $amount === '') return '₹0';
    $amount = (float) $amount;
    return '₹' . formatNumber($amount);
}

// Format percentage
function formatPercentage($value, $decimals = 1) {
    if ($value === null || $value === '') return '0%';
    return number_format((float) $value, $decimals) . '%';
}

// Sanitize input to prevent XSS
function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Validate district name
function validateDistrict($district) {
    $districts = getDistricts();
    return in_array($district, $districts);
}

// Validate year
function validateYear($year) {
    $years = getYears();
    return in_array($year, $years);
}

// Log activity to file
function logActivity($action, $details = '', $level = 'INFO') {
    $logFile = __DIR__ . '/../logs/activity.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    $message = sprintf(
        "[%s] [%s] [%s] %s - %s | User-Agent: %s\n",
        $timestamp,
        $level,
        $ip,
        $action,
        $details,
        substr($userAgent, 0, 100)
    );
    
    // Create logs directory if it doesn't exist
    $logDir = dirname($logFile);
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    // Write to log file
    file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);
    
    // Rotate log file if it's too large (> 10MB)
    if (file_exists($logFile) && filesize($logFile) > 10 * 1024 * 1024) {
        $backupFile = $logFile . '.' . date('Y-m-d-His');
        rename($logFile, $backupFile);
    }
}

// Get database statistics
function getSystemStats() {
    $db = getDB();
    
    $stats = [
        'total_records' => 0,
        'total_districts' => 0,
        'total_years' => 0,
        'last_updated' => null,
        'total_households' => 0,
        'total_expenditure' => 0
    ];
    
    try {
        // Total records
        $result = $db->fetchOne("SELECT COUNT(*) as count FROM " . TABLE_NAME);
        $stats['total_records'] = $result['count'] ?? 0;
        
        // Total districts
        $result = $db->fetchOne("SELECT COUNT(DISTINCT district_name) as count FROM " . TABLE_NAME);
        $stats['total_districts'] = $result['count'] ?? 0;
        
        // Total years
        $result = $db->fetchOne("SELECT COUNT(DISTINCT fin_year) as count FROM " . TABLE_NAME);
        $stats['total_years'] = $result['count'] ?? 0;
        
        // Last updated
        $result = $db->fetchOne("SELECT MAX(created_at) as last_update FROM " . TABLE_NAME);
        $stats['last_updated'] = $result['last_update'] ?? null;
        
        // Total metrics
        $result = $db->fetchOne("SELECT 
            COALESCE(SUM(total_households_worked), 0) as households,
            COALESCE(SUM(total_exp), 0) as expenditure
            FROM " . TABLE_NAME);
        $stats['total_households'] = $result['households'] ?? 0;
        $stats['total_expenditure'] = $result['expenditure'] ?? 0;
        
    } catch (Exception $e) {
        logActivity('ERROR', 'Failed to get system stats: ' . $e->getMessage(), 'ERROR');
    }
    
    return $stats;
}

// Check if data exists for district and year
function hasData($district, $year) {
    $db = getDB();
    $sql = "SELECT COUNT(*) as count FROM " . TABLE_NAME . "
            WHERE district_name = :district AND fin_year = :year";
    $result = $db->fetchOne($sql, [':district' => $district, ':year' => $year]);
    return ($result['count'] ?? 0) > 0;
}

// Get available months for a district and year
function getAvailableMonths($district, $year) {
    $db = getDB();
    $sql = "SELECT DISTINCT month FROM " . TABLE_NAME . "
            WHERE district_name = :district AND fin_year = :year
            ORDER BY CASE month
                WHEN 'April' THEN 1 WHEN 'May' THEN 2 WHEN 'June' THEN 3 
                WHEN 'July' THEN 4 WHEN 'August' THEN 5 WHEN 'September' THEN 6 
                WHEN 'October' THEN 7 WHEN 'November' THEN 8 WHEN 'December' THEN 9 
                WHEN 'January' THEN 10 WHEN 'February' THEN 11 WHEN 'March' THEN 12
            END";
    $result = $db->fetchAll($sql, [':district' => $district, ':year' => $year]);
    return array_column($result, 'month');
}

// Generate simple cache key
function getCacheKey($prefix, ...$params) {
    return $prefix . '_' . md5(serialize($params));
}

// Simple session-based cache (for development)
function cacheGet($key) {
    if (!isset($_SESSION['cache'])) {
        $_SESSION['cache'] = [];
    }
    
    if (isset($_SESSION['cache'][$key])) {
        $cached = $_SESSION['cache'][$key];
        if ($cached['expires'] > time()) {
            return $cached['data'];
        }
        unset($_SESSION['cache'][$key]);
    }
    
    return null;
}

function cacheSet($key, $data, $ttl = 3600) {
    if (!isset($_SESSION['cache'])) {
        $_SESSION['cache'] = [];
    }
    
    $_SESSION['cache'][$key] = [
        'data' => $data,
        'expires' => time() + $ttl
    ];
}

// Error handler wrapper
function handleError($message, $code = 500) {
    logActivity('ERROR', $message, 'ERROR');
    http_response_code($code);
    
    if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $message, 'code' => $code]);
    } else {
        echo "<h1>Error $code</h1><p>$message</p>";
    }
    
    exit;
}
?>