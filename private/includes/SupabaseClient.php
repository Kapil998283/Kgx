<?php
require_once __DIR__ . '/../config/supabase.php';

/**
 * Supabase Client for PHP
 * 
 * This class handles all interactions with Supabase including:
 * - REST API calls
 * - Authentication
 * - Database operations
 * - File storage
 */
class SupabaseClient {
    private $config;
    private $headers;
    private $authHeaders;
    private $pdo;
    
    private $isAdminMode = false;
    
    public function __construct($useServiceRole = false, $session = null) {
        $this->config = SupabaseConfig::getConfig();
        $this->isAdminMode = $useServiceRole;
        
        // Validate configuration
        $errors = SupabaseConfig::validate();
        if (!empty($errors)) {
            throw new Exception('Supabase configuration errors: ' . implode(', ', $errors));
        }
        
        // Set up headers for API calls
        $apiKey = $useServiceRole ? $this->config['service_role_key'] : $this->config['anon_key'];
        $bearerToken = $apiKey;

        if ($session && isset($session['access_token'])) {
            $bearerToken = $session['access_token'];
        }
        
        $this->headers = [
            'Content-Type: application/json',
            'apikey: ' . $this->config['anon_key'],
            'Authorization: Bearer ' . $bearerToken
        ];
        
        $this->authHeaders = [
            'Content-Type: application/json',
            'apikey: ' . $this->config['anon_key']
        ];
    }

    public function setAuthToken($token) {
        if (!empty($token)) {
            $this->headers['Authorization'] = 'Bearer ' . $token;
        }
    }
    
    public function isAdminMode() {
        return $this->isAdminMode;
    }
    
    /**
     * Get direct PostgreSQL connection
     */
    public function getConnection() {
        if ($this->pdo === null) {
            try {
                $dsn = "pgsql:host={$this->config['db_host']};port={$this->config['db_port']};dbname={$this->config['db_name']}";
                $this->pdo = new PDO($dsn, $this->config['db_user'], $this->config['db_password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => $this->config['timeout'],
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]);
            } catch (PDOException $e) {
                throw new Exception('Database connection failed: ' . $e->getMessage());
            }
        }
        return $this->pdo;
    }
    
    /**
     * Make HTTP request to Supabase REST API
     */
    private function makeRequest($method, $endpoint, $data = null, $customHeaders = null) {
        $url = $this->config['url'] . '/rest/v1/' . $endpoint;
        $headers = $customHeaders ?? $this->headers;
        
        // Debug logging (disabled for performance)
        // error_log("Supabase Request URL: " . $url);
        // error_log("Supabase Request Method: " . $method);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->config['timeout'],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HEADER => true  // Include headers in response
        ]);
        
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            error_log("Supabase Request Data: " . json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("CURL Error: " . $error);
            throw new Exception('CURL Error: ' . $error);
        }
        
        // Split headers and body
        $responseHeaders = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);
        
        error_log("Supabase Response Code: " . $httpCode);
        error_log("Supabase Response Body: " . substr($responseBody, 0, 500));
        
        $decoded = json_decode($responseBody, true);
        
        if ($httpCode >= 400) {
            $errorMsg = isset($decoded['message']) ? $decoded['message'] : 'HTTP Error: ' . $httpCode;
            error_log("Supabase Error: " . $errorMsg);
            throw new Exception($errorMsg);
        }
        
        // For PATCH/DELETE operations, try to extract count from headers
        if (in_array($method, ['PATCH', 'DELETE'])) {
            $result = ['data' => $decoded];
            
            // Try to extract count from Content-Range header
            if (preg_match('/content-range:\s*(\d+)-?(\d+)?\/(\d+|\*)/i', $responseHeaders, $matches)) {
                $result['count'] = intval($matches[3]);
            } else {
                // Fallback: if we got data back, use its count
                $result['count'] = is_array($decoded) ? count($decoded) : ($httpCode < 300 ? 1 : 0);
            }
            
            return $result;
        }
        
        return $decoded;
    }
    
    /**
     * SELECT data from table
     */
    public function select($table, $options = '*', $conditions = [], $orderBy = null, $limit = null) {
        // Handle new array-based parameter format
        if (is_array($options)) {
            $columns = isset($options['select']) ? $options['select'] : '*';
            $joins = isset($options['join']) ? $options['join'] : null;
            $where = isset($options['where']) ? $options['where'] : null;
            $params = isset($options['params']) ? $options['params'] : [];
            $orderBy = isset($options['order']) ? $options['order'] : null;
            $limit = isset($options['limit']) ? $options['limit'] : null;
            $single = isset($options['single']) ? $options['single'] : false;
            
            // For complex queries with joins, we need to use raw SQL through PDO
            if ($joins) {
                return $this->executeRawQuery($table, $columns, $joins, $where, $params, $orderBy, $limit, $single);
            }
            
            // Convert where clause and params to conditions array
            if ($where && !empty($params)) {
                $conditions = $this->parseWhereClause($where, $params);
            }
        } else {
            // Handle legacy parameter format
            $columns = $options;
        }
        
        // Handle column selection properly - spaces need to be URL encoded in query parameters
        if ($columns === '*') {
            $endpoint = $table . '?select=*';
        } else {
            // Remove any spaces around commas and encode properly
            $cleanColumns = preg_replace('/\s*,\s*/', ',', trim($columns));
            $endpoint = $table . '?select=' . urlencode($cleanColumns);
        }
        
        // Add conditions
        if (is_array($conditions) && !empty($conditions)) {
            foreach ($conditions as $key => $value) {
                if (is_array($value)) {
                    // Handle operators like ['gte', 18] for age>=18 or ['not.is', null]
                    $operator = $value[0];
                    $val = $value[1];
                    if ($val === null && ($operator === 'not.is' || $operator === 'is')) {
                        $endpoint .= "&{$key}={$operator}.null";
                    } else {
                        $encodedVal = urlencode($val);
                        $endpoint .= "&{$key}={$operator}.{$encodedVal}";
                    }
                } elseif ($value === 'is.null') {
                    // Handle NULL checks
                    $endpoint .= "&{$key}=is.null";
                } elseif (is_string($value) && strpos($value, '.') !== false && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    // Handle operators like 'not.is' (but exclude email addresses)
                    $endpoint .= "&{$key}={$value}";
                } elseif ($value === null) {
                    // Handle null values - check if key contains operator
                    if (strpos($key, '.not.is') !== false) {
                        $endpoint .= "&{$key}=null";
                    } else {
                        $endpoint .= "&{$key}=is.null";
                    }
                } elseif (is_bool($value)) {
                    // Handle boolean values
                    $boolStr = $value ? 'true' : 'false';
                    $endpoint .= "&{$key}=eq.{$boolStr}";
                } else {
                    // Special handling for email addresses and other string values
                    $encodedValue = urlencode($value);
                    $endpoint .= "&{$key}=eq.{$encodedValue}";
                }
            }
        }
        
        // Add ordering
        if ($orderBy) {
            if (is_array($orderBy)) {
                // Handle array format like ['created_at' => 'desc']
                $orderParts = [];
                foreach ($orderBy as $column => $direction) {
                    $orderParts[] = $column . '.' . $direction;
                }
                $endpoint .= "&order=" . urlencode(implode(',', $orderParts));
            } else {
                // Handle string format like 'created_at.desc'
                $endpoint .= "&order=" . urlencode($orderBy);
            }
        }
        
        // Add limit
        if ($limit) {
            $endpoint .= "&limit=" . urlencode($limit);
        }
        
        // Debug logging for the endpoint URL
        error_log("Supabase select endpoint: " . $endpoint);
        
        $response = $this->makeRequest('GET', $endpoint);
        
        // Handle single record return
        if (isset($single) && $single && is_array($response) && count($response) > 0) {
            return $response[0];
        }
        
        return $response;
    }
    
    /**
     * Execute raw SQL query for complex queries with joins
     * For admin mode, we'll try to avoid direct DB connections and use REST API alternatives
     */
    private function executeRawQuery($table, $columns, $joins, $where, $params, $orderBy, $limit, $single) {
        // For simple joins, try to use REST API approach instead of direct SQL
        if ($this->isAdminMode && strpos($joins, 'games g ON') !== false) {
            // Handle games join by making separate calls
            $matches = $this->select($table, '*', $this->parseWhereClause($where, $params), $orderBy, $limit);
            
            // Enrich with game data
            foreach ($matches as &$match) {
                if (isset($match['game_id'])) {
                    $games = $this->select('games', 'id,name', ['id' => $match['game_id']]);
                    if (!empty($games)) {
                        $match['game_name'] = $games[0]['name'];
                    }
                }
            }
            
            return $single && count($matches) > 0 ? $matches[0] : $matches;
        }
        
        // Fallback to direct SQL if needed (may fail with connection issues)
        try {
            $pdo = $this->getConnection();
            
            // Build the SQL query
            $sql = "SELECT {$columns} FROM {$table} m {$joins}";
            
            if ($where) {
                $sql .= " WHERE {$where}";
            }
            
            if ($orderBy) {
                $sql .= " ORDER BY {$orderBy}";
            }
            
            if ($limit) {
                $sql .= " LIMIT {$limit}";
            }
            
            error_log("Executing raw SQL: " . $sql);
            error_log("With params: " . json_encode($params));
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if ($single && count($results) > 0) {
                return $results[0];
            }
            
            return $results;
        } catch (Exception $e) {
            error_log("Raw SQL query failed, falling back to simple select: " . $e->getMessage());
            // Fallback to simple select without joins
            return $this->select($table, '*', $this->parseWhereClause($where, $params), $orderBy, $limit);
        }
    }
    
    /**
     * Parse WHERE clause with parameters
     */
    private function parseWhereClause($where, $params) {
        $conditions = [];
        
        // For simple WHERE clauses like 'id = $1', we can try to parse them
        // For now, just return empty array and let the raw query handle it
        
        return $conditions;
    }
    
    /**
     * INSERT data into table
     */
    public function insert($table, $data) {
        // Use REST API for all tables since we've disabled RLS
        error_log("SupabaseClient::insert() called with table: $table");
        error_log("SupabaseClient::insert() data: " . json_encode($data));
        
        try {
            // Add Prefer header to return the inserted data
            $headers = array_merge($this->headers, ['Prefer: return=representation']);
            $result = $this->makeRequest('POST', $table, $data, $headers);
            
            error_log("SupabaseClient::insert() result: " . json_encode($result));
            error_log("SupabaseClient::insert() result type: " . gettype($result));
            
            // Supabase returns null for successful inserts by default
            // With Prefer: return=representation header, it should return the inserted data
            // But if it's still null, that means the insert was successful
            if ($result === null) {
                error_log("SupabaseClient::insert() SUCCESS: Insert completed successfully for table $table (null response is normal)");
                // Return a success indicator instead of throwing an error
                return ['success' => true, 'table' => $table];
            }
            
            return $result;
        } catch (Exception $e) {
            error_log("SupabaseClient::insert() REST API failed with exception: " . $e->getMessage());
            
            // Re-throw the exception instead of falling back to SQL
            throw $e;
        }
    }
    
    /**
     * Insert data via direct SQL (for tables with RLS issues)
     */
    private function insertViaSql($table, $data) {
        $pdo = $this->getConnection();
        
        // Clean and prepare data for PostgreSQL
        $cleanData = [];
        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                // Convert boolean to string for PostgreSQL
                $cleanData[$key] = $value ? 'true' : 'false';
            } elseif ($value === null) {
                // Keep null values as null
                $cleanData[$key] = null;
            } elseif (is_string($value) && $value === '') {
                // Convert empty strings to null for non-text columns if needed
                $cleanData[$key] = $value;
            } else {
                $cleanData[$key] = $value;
            }
        }
        
        $columns = array_keys($cleanData);
        $placeholders = array_map(function($col) { return ':' . $col; }, $columns);
        
        $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ") RETURNING id";
        
        error_log("Executing INSERT SQL: " . $sql);
        error_log("With cleaned data: " . json_encode($cleanData));
        
        try {
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute($cleanData);
            
            if ($result) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                return $row ? [$row] : [['id' => $pdo->lastInsertId()]];
            } else {
                throw new Exception('Insert failed');
            }
        } catch (PDOException $e) {
            error_log("PDO Insert Error: " . $e->getMessage());
            throw new Exception('Database insert failed: ' . $e->getMessage());
        }
    }
    
    /**
     * UPDATE data in table
     */
    public function update($table, $data, $conditions) {
        $endpoint = $table;
        
        // Add conditions for WHERE clause
        $whereParams = [];
        foreach ($conditions as $key => $value) {
            if (strpos($key, '.') !== false) {
                // Handle special operators like 'id.neq', 'age.gte', etc.
                $whereParams[] = "{$key}=" . urlencode($value);
            } else {
                // Default to equality
                $whereParams[] = "{$key}=eq." . urlencode($value);
            }
        }
        if (!empty($whereParams)) {
            $endpoint .= '?' . implode('&', $whereParams);
        }
        
        // Add Prefer header to return updated count
        $headers = array_merge($this->headers, ['Prefer: return=minimal']);
        
        $result = $this->makeRequest('PATCH', $endpoint, $data, $headers);
        
        // For update operations, we need to check the response headers for count
        // Since we're using minimal return, we'll simulate a count response
        if (is_array($result)) {
            return ['count' => count($result)];
        } else {
            // If no error was thrown, assume success
            return ['count' => 1];
        }
    }
    
    /**
     * DELETE data from table
     */
    public function delete($table, $whereClause, $params = []) {
        // Handle different parameter formats
        if (is_array($whereClause)) {
            // Legacy format: $conditions array
            $conditions = $whereClause;
            $endpoint = $table;
            
            $whereParams = [];
            foreach ($conditions as $key => $value) {
                $whereParams[] = "{$key}=eq." . urlencode($value);
            }
            if (!empty($whereParams)) {
                $endpoint .= '?' . implode('&', $whereParams);
            }
            
            return $this->makeRequest('DELETE', $endpoint);
        } else {
            // New format: WHERE clause string with params - use raw SQL
            $pdo = $this->getConnection();
            
            $sql = "DELETE FROM {$table} WHERE {$whereClause}";
            error_log("Executing DELETE SQL: " . $sql);
            error_log("With params: " . json_encode($params));
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            return ['count' => $stmt->rowCount()];
        }
    }
    
    /**
     * Execute raw SQL query (use with caution)
     */
    public function query($sql, $params = []) {
        $pdo = $this->getConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * User Authentication - Sign Up
     */
    public function signUp($email, $password, $userData = []) {
        $data = [
            'email' => $email,
            'password' => $password
        ];
        
        if (!empty($userData)) {
            $data['data'] = $userData;
        }
        
        $url = $this->config['url'] . '/auth/v1/signup';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->config['timeout'],
            CURLOPT_HTTPHEADER => $this->authHeaders,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            throw new Exception('CURL Error during signup: ' . $curlError);
        }
        
        $decoded = $response ? json_decode($response, true) : null;
        
        if ($httpCode >= 400) {
            $errorMsg = 'Sign up failed';
            if (isset($decoded['error_description'])) {
                $errorMsg = $decoded['error_description'];
            } elseif (isset($decoded['message'])) {
                $errorMsg = $decoded['message'];
            } elseif (isset($decoded['error'])) {
                $errorMsg = $decoded['error'];
            }
            throw new Exception($errorMsg);
        }
        
        if (!$decoded) {
            throw new Exception('Invalid JSON response from Supabase signup');
        }
        
        return $decoded;
    }
    
    /**
     * User Authentication - Sign In
     */
    public function signIn($email, $password) {
        $data = [
            'email' => $email,
            'password' => $password
        ];
        
        $url = $this->config['url'] . '/auth/v1/token?grant_type=password';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->config['timeout'],
            CURLOPT_HTTPHEADER => $this->authHeaders,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            throw new Exception('CURL Error during signin: ' . $curlError);
        }
        
        $decoded = json_decode($response, true);
        
        if ($httpCode >= 400) {
            $errorMsg = 'Sign in failed';
            if (isset($decoded['error_description'])) {
                $errorMsg = $decoded['error_description'];
            } elseif (isset($decoded['message'])) {
                $errorMsg = $decoded['message'];
            } elseif (isset($decoded['error'])) {
                $errorMsg = $decoded['error'];
            }
            throw new Exception($errorMsg);
        }
        
        if (!$decoded) {
            throw new Exception('Invalid JSON response from Supabase signin');
        }
        
        return $decoded;
    }
    
    /**
     * Verify JWT token
     */
    public function verifyToken($token) {
        $url = $this->config['url'] . '/auth/v1/user';
        
        $headers = [
            'Content-Type: application/json',
            'apikey: ' . $this->config['anon_key'],
            'Authorization: Bearer ' . $token
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->config['timeout'],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return false;
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Upload file to Supabase Storage
     */
    public function uploadFile($bucketName, $fileName, $filePath, $contentType = 'application/octet-stream') {
        $url = $this->config['url'] . "/storage/v1/object/{$bucketName}/{$fileName}";
        
        $headers = [
            'apikey: ' . $this->config['anon_key'],
            'Authorization: Bearer ' . $this->config['anon_key'],
            'Content-Type: ' . $contentType
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->config['timeout'],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => file_get_contents($filePath),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $decoded = json_decode($response, true);
        
        if ($httpCode >= 400) {
            throw new Exception($decoded['message'] ?? 'File upload failed');
        }
        
        return $decoded;
    }
    
    /**
     * Get public URL for uploaded file
     */
    public function getPublicUrl($bucketName, $fileName) {
        return $this->config['url'] . "/storage/v1/object/public/{$bucketName}/{$fileName}";
    }
    
    /**
     * Delete file from Supabase Storage
     */
    public function deleteFile($bucketName, $fileName) {
        $url = $this->config['url'] . "/storage/v1/object/{$bucketName}/{$fileName}";
        
        $headers = [
            'apikey: ' . $this->config['anon_key'],
            'Authorization: Bearer ' . $this->config['service_role_key'] // Use service role for deletion
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->config['timeout'],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $decoded = json_decode($response, true);
        
        if ($httpCode >= 400) {
            $errorMsg = $decoded['message'] ?? 'File deletion failed';
            throw new Exception("Failed to delete file from storage: {$errorMsg}");
        }
        
        return $decoded;
    }
    
    /**
     * Begin database transaction
     */
    public function beginTransaction() {
        $pdo = $this->getConnection();
        return $pdo->beginTransaction();
    }
    
    /**
     * Commit database transaction
     */
    public function commit() {
        $pdo = $this->getConnection();
        return $pdo->commit();
    }
    
    /**
     * Rollback database transaction
     */
    public function rollback() {
        $pdo = $this->getConnection();
        return $pdo->rollBack();
    }
    
    /**
     * Execute database transaction
     */
    public function transaction(callable $callback) {
        $pdo = $this->getConnection();
        
        try {
            $pdo->beginTransaction();
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    
    /**
     * Call Remote Procedure Call (RPC) functions in Supabase
     * This method calls PostgreSQL stored procedures via Supabase REST API
     */
    public function rpc($functionName, $params = []) {
        try {
            // First try via REST API (preferred method for Supabase RPC)
            $endpoint = "rpc/{$functionName}";
            return $this->makeRequest('POST', $endpoint, $params);
        } catch (Exception $e) {
            // Fallback to direct PostgreSQL call if REST API fails
            error_log("RPC REST API failed, falling back to direct DB call: " . $e->getMessage());
            
            $pdo = $this->getConnection();
            
            // Build the SQL call based on the function name
            switch ($functionName) {
                case 'increment_team_score':
                    $sql = "SELECT increment_team_score(:team_id_param, :increment_by)";
                    break;
                case 'increment_tournament_teams':
                    $sql = "SELECT increment_tournament_teams(:tournament_id_param)";
                    break;
                case 'increment_user_tickets':
                    $sql = "SELECT increment_user_tickets(:user_id_param, :increment_by)";
                    break;
                case 'increment_user_score':
                    $sql = "SELECT increment_user_score(:user_id_param, :increment_by, :tournament_id_param)";
                    break;
                case 'refund_tickets':
                    $sql = "SELECT refund_tickets(:user_id_param, :amount)";
                    break;
                case 'deduct_tickets':
                    $sql = "SELECT deduct_tickets(:user_id_param, :amount) as result";
                    break;
                case 'decrement_tournament_teams':
                    $sql = "SELECT decrement_tournament_teams(:tournament_id_param)";
                    break;
                default:
                    throw new Exception("Unknown RPC function: {$functionName}");
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            // For functions that return a value, fetch it
            if (in_array($functionName, ['deduct_tickets'])) {
                return $stmt->fetch();
            }
            
            return true; // For void functions, return true on success
        }
    }
}
