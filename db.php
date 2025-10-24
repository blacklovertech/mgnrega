<?php
/**
 * Database Connection Handler with Configuration
 * 
 * Implements Singleton Pattern for efficient database connection management.
 * Ensures only ONE connection is created and reused throughout the application.
 * 
 * Benefits:
 * - Single connection for entire application lifecycle
 * - Prevents connection overhead and memory leaks
 * - Centralized error handling
 * - Easy to maintain and test
 * - Production-ready with security features
 */

// Database Configuration
define('DB_HOST', 'db.gtkomvrvyzravwrbfgdm.supabase.co');
define('DB_PORT', 5432);
define('DB_NAME', 'postgres');
define('DB_USER', 'postgres');
define('DB_PASSWORD', 'mgnrega');
define('TABLE_NAME', 'mgnrega');

// Application Settings
define('RECORDS_PER_PAGE', 100);
define('CACHE_DURATION', 3600); // 1 hour in seconds
define('MAX_SYNC_RETRIES', 3);

// Timezone
date_default_timezone_set('Asia/Kolkata');

// Error Reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

class Database {
    // Static instance to hold the single database connection
    private static $instance = null;
    
    // PDO connection object
    private $conn;
    
    /**
     * Private constructor - prevents direct instantiation
     * This is key to Singleton pattern
     */
    private function __construct() {
        try {
            // Build PostgreSQL DSN (Data Source Name)
            $dsn = sprintf(
                "pgsql:host=%s;port=%s;dbname=%s",
                DB_HOST,
                DB_PORT,
                DB_NAME
            );
            
            // Create PDO connection with security options
            $this->conn = new PDO(
                $dsn,
                DB_USER,
                DB_PASSWORD,
                [
                    // Throw exceptions on errors (instead of silent failures)
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    
                    // Return associative arrays by default
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    
                    // Use real prepared statements (security++)
                    PDO::ATTR_EMULATE_PREPARES => false,
                    
                    // Set connection timeout (10 seconds)
                    PDO::ATTR_TIMEOUT => 10,
                    
                    // Make connection persistent (reuse across requests)
                    PDO::ATTR_PERSISTENT => true,
                    
                    // Force column names to lowercase
                    PDO::ATTR_CASE => PDO::CASE_LOWER
                ]
            );
            
            // Set UTF-8 encoding
            $this->conn->exec("SET NAMES 'utf8'");
            
            // Log successful connection (only first time)
            if (!isset($_SESSION['db_connected'])) {
                logActivity('Database', 'Connection established successfully', 'INFO');
                $_SESSION['db_connected'] = true;
            }
            
        } catch (PDOException $e) {
            // Log the error
            error_log("Database connection failed: " . $e->getMessage());
            
            // Show user-friendly error (don't expose internal details)
            $errorMessage = "Unable to connect to database. Please try again later.";
            
            // In development, show detailed error
            if (defined('DEBUG') && DEBUG === true) {
                $errorMessage .= " Debug: " . $e->getMessage();
            }
            
            die($errorMessage);
        }
    }
    
    /**
     * Get the single instance of Database class
     * This is the public access point
     * 
     * @return Database
     */
    public static function getInstance() {
        // If no instance exists, create one
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        // Return the existing instance
        return self::$instance;
    }
    
    /**
     * Get the PDO connection object
     * 
     * @return PDO
     */
    public function getConnection() {
        return $this->conn;
    }
    
    /**
     * Execute a query and return PDO statement
     * 
     * @param string $sql SQL query with placeholders
     * @param array $params Parameters to bind
     * @return PDOStatement
     * @throws PDOException
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt;
            
        } catch (PDOException $e) {
            // Log the error with query details
            error_log("Query failed: " . $e->getMessage() . " | SQL: " . $sql);
            
            // Re-throw for handling at higher level
            throw new PDOException(
                "Database query error: " . $e->getMessage(),
                (int)$e->getCode()
            );
        }
    }
    
    /**
     * Fetch all rows from query
     * 
     * @param string $sql SQL query
     * @param array $params Parameters to bind
     * @return array Array of rows
     */
    public function fetchAll($sql, $params = []) {
        try {
            $stmt = $this->query($sql, $params);
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("fetchAll failed: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Fetch single row from query
     * 
     * @param string $sql SQL query
     * @param array $params Parameters to bind
     * @return array|false Single row or false if not found
     */
    public function fetchOne($sql, $params = []) {
        try {
            $stmt = $this->query($sql, $params);
            return $stmt->fetch();
            
        } catch (PDOException $e) {
            error_log("fetchOne failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Fetch single column value
     * 
     * @param string $sql SQL query
     * @param array $params Parameters to bind
     * @return mixed Single value
     */
    public function fetchColumn($sql, $params = []) {
        try {
            $stmt = $this->query($sql, $params);
            return $stmt->fetchColumn();
            
        } catch (PDOException $e) {
            error_log("fetchColumn failed: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Execute query and return number of affected rows
     * 
     * @param string $sql SQL query
     * @param array $params Parameters to bind
     * @return int Number of affected rows
     */
    public function execute($sql, $params = []) {
        try {
            $stmt = $this->query($sql, $params);
            return $stmt->rowCount();
            
        } catch (PDOException $e) {
            error_log("execute failed: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Begin transaction
     * 
     * @return bool
     */
    public function beginTransaction() {
        return $this->conn->beginTransaction();
    }
    
    /**
     * Commit transaction
     * 
     * @return bool
     */
    public function commit() {
        return $this->conn->commit();
    }
    
    /**
     * Rollback transaction
     * 
     * @return bool
     */
    public function rollback() {
        return $this->conn->rollBack();
    }
    
    /**
     * Get last inserted ID
     * 
     * @param string $name Sequence name (PostgreSQL specific)
     * @return string
     */
    public function lastInsertId($name = null) {
        return $this->conn->lastInsertId($name);
    }
    
    /**
     * Check if connection is alive
     * 
     * @return bool
     */
    public function isConnected() {
        try {
            $this->conn->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Close the connection
     * Usually not needed due to persistent connections
     */
    public function close() {
        $this->conn = null;
    }
    
    /**
     * Prevent cloning of the instance
     */
    private function __clone() {}
    
    /**
     * Prevent unserialization of the instance
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}

/**
 * Helper function to get database instance
 * This is what you'll use in your code
 * 
 * Usage:
 * $db = getDB();
 * $districts = $db->fetchAll("SELECT * FROM mgnrega");
 * 
 * @return Database
 */
function getDB() {
    return Database::getInstance();
}

/**
 * Helper function to execute a transaction safely
 * 
 * Usage:
 * transaction(function($db) {
 *     $db->execute("INSERT INTO ...");
 *     $db->execute("UPDATE ...");
 * });
 * 
 * @param callable $callback Function to execute in transaction
 * @return mixed Result from callback
 * @throws Exception
 */
function transaction(callable $callback) {
    $db = getDB();
    
    try {
        $db->beginTransaction();
        $result = $callback($db);
        $db->commit();
        return $result;
        
    } catch (Exception $e) {
        $db->rollback();
        error_log("Transaction failed: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Helper function to check database health
 * 
 * @return array Status information
 */
function checkDatabaseHealth() {
    $db = getDB();
    
    $health = [
        'connected' => false,
        'total_records' => 0,
        'last_check' => date('Y-m-d H:i:s')
    ];
    
    try {
        // Check if connected
        $health['connected'] = $db->isConnected();
        
        // Get record count
        $result = $db->fetchOne("SELECT COUNT(*) as count FROM " . TABLE_NAME);
        $health['total_records'] = $result['count'] ?? 0;
        
        $health['status'] = 'healthy';
        
    } catch (Exception $e) {
        $health['status'] = 'error';
        $health['error'] = $e->getMessage();
    }
    
    return $health;
}

/**
 * Log activity helper function
 * 
 * @param string $category
 * @param string $message
 * @param string $level
 */
function logActivitydb($category, $message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] [$category] $message" . PHP_EOL;
    
    // Log to file (you can modify this to log to database)
    error_log($logMessage, 3, 'database_activity.log');
}
?>