<?php
/**
 * Supabase Configuration for KGX Esports Platform
 * 
 * This file contains all the configuration settings for connecting to Supabase
 */

class SupabaseConfig {
    // Load environment variables from .env file if it exists
    private static $envLoaded = false;
    
    private static function loadEnv() {
        if (!self::$envLoaded) {
            $envFile = dirname(__DIR__, 2) . '/.env';
            if (file_exists($envFile)) {
                $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    if (strpos(trim($line), '#') === 0) {
                        continue; // Skip comments
                    }
                    
                    list($name, $value) = explode('=', $line, 2);
                    $name = trim($name);
                    $value = trim($value);
                    
                    // Remove quotes if present
                    if (preg_match('/^["\'](.*)["\'](\s*)$/', $value, $matches)) {
                        $value = $matches[1];
                    }
                    
                    if (!array_key_exists($name, $_ENV)) {
                        $_ENV[$name] = $value;
                    }
                }
            }
            self::$envLoaded = true;
        }
    }
    
    private static function getEnv($key, $default = null) {
        self::loadEnv();
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }
    
    // Supabase Project Settings - read from environment variables
    public static function getSupabaseUrl() {
        return self::getEnv('SUPABASE_URL', 'https://xparbvjsubqptuaupwcd.supabase.co');
    }
    
    public static function getSupabaseAnonKey() {
        return self::getEnv('SUPABASE_ANON_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InhwYXJidmpzdWJxcHR1YXVwd2NkIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NTM2NTEzMjMsImV4cCI6MjA2OTIyNzMyM30.Ykd7IQ3t0aD-UhRB9vE7hcWIp5wIUg0s9TTdc2LrwU8');
    }
    
    public static function getSupabaseServiceRoleKey() {
        return self::getEnv('SUPABASE_SERVICE_ROLE_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InhwYXJidmpzdWJxcHR1YXVwd2NkIiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImlhdCI6MTc1MzY1MTMyMywiZXhwIjoyMDY5MjI3MzIzfQ.XG50W_HvKwbvpGFSn8An3RJeXEH-6Fin7JZQjb1O6PE');
    }
    
    // Database Settings - read from environment variables
    public static function getDbHost() {
        return self::getEnv('SUPABASE_DB_HOST', 'xparbvjsubqptuaupwcd.supabase.co');
    }
    
    public static function getDbPort() {
        return self::getEnv('SUPABASE_DB_PORT', '5432');
    }
    
    public static function getDbName() {
        return self::getEnv('SUPABASE_DB_NAME', 'postgres');
    }
    
    public static function getDbUser() {
        return self::getEnv('SUPABASE_DB_USER', 'postgres');
    }
    
    public static function getDbPassword() {
        return self::getEnv('SUPABASE_DB_PASSWORD', 'Kapil9982');
    }
    
    // JWT Settings - read from environment variables
    public static function getJwtSecret() {
        return self::getEnv('SUPABASE_JWT_SECRET', 'O4bNMDjTZnu46L0SBG3CbyeDkEtJK47rNHp6rZaTNbGG4Qln4fJihN2f9l3P2uMe6PblhZr/o+qJW1eoIwRqdA==');
    }
    
    // Static API Settings
    const API_VERSION = 'v1';
    const TIMEOUT = 30; // Increased to 30 seconds
    
    // File Upload Settings (if using Supabase Storage)
    const STORAGE_BUCKET = 'uploads';
    const MAX_FILE_SIZE = 10485760; // 10MB in bytes
    const ALLOWED_FILE_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
    
    /**
     * Get all configuration as array
     */
    public static function getConfig() {
        return [
            'url' => self::getSupabaseUrl(),
            'anon_key' => self::getSupabaseAnonKey(),
            'service_role_key' => self::getSupabaseServiceRoleKey(),
            'db_host' => self::getDbHost(),
            'db_port' => self::getDbPort(),
            'db_name' => self::getDbName(),
            'db_user' => self::getDbUser(),
            'db_password' => self::getDbPassword(),
            'jwt_secret' => self::getJwtSecret(),
            'timeout' => self::TIMEOUT
        ];
    }
    
    /**
     * Validate configuration
     */
    public static function validate() {
        $errors = [];
        
        // Check if required configuration values are set and not empty
        if (empty(self::getSupabaseUrl()) || !filter_var(self::getSupabaseUrl(), FILTER_VALIDATE_URL)) {
            $errors[] = 'SUPABASE_URL is not set or is not a valid URL';
        }
        
        if (empty(self::getSupabaseAnonKey()) || strlen(self::getSupabaseAnonKey()) < 20) {
            $errors[] = 'SUPABASE_ANON_KEY is not set or appears to be invalid';
        }
        
        if (empty(self::getDbHost()) || strlen(self::getDbHost()) < 5) {
            $errors[] = 'SUPABASE_DB_HOST is not set or appears to be invalid';
        }
        
        if (empty(self::getDbPassword())) {
            $errors[] = 'SUPABASE_DB_PASSWORD is not set';
        }
        
        return $errors;
    }
}

// Environment-specific settings
if (isset($_SERVER['HTTP_HOST'])) {
    // Production settings
    if ($_SERVER['HTTP_HOST'] === 'your-production-domain.com') {
        // You can override settings for production here
        // Example: error_reporting(0);
    }
    // Development settings
    else {
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
    }
}
