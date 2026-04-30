<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/app/Support/translation.php';

use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
// Koristimo safeLoad da ne pukne ako .env ne postoji u nekim okruženjima
$dotenv->safeLoad(); 

class Config {
    // BigCommerce - Globalna podešavanja aplikacije
    public static $BC_CLIENT_ID;
    public static $BC_CLIENT_SECRET;
    
    // Ovi se koriste samo kao fallback (rezerva) ako nismo u multi-tenant modu
    public static $BC_STORE_HASH;
    public static $BC_ACCESS_TOKEN;
    
    // Osnovni URL API-ja (bez store hash-a)
    public static $BC_API_URL;
    
    // Database
    public static $DB_HOST;
    public static $DB_NAME;
    public static $DB_USER;
    public static $DB_PASS;
    
    // App Settings
    public static $APP_URL;
    public static $ADMIN_USERNAME;
    public static $ADMIN_PASSWORD;
    public static $SECRET_CRON_KEY;
    public static $CUSTOM_FIELD_NAME;
    public static $SESSION_LIFETIME;

    public static $DEBUG_WEBHOOKS = true;
    public static $PHP_CLI_PATH = '/usr/local/bin/ea-php82';
    
    public static function init() {
        // 1. Učitaj osnovne podatke o aplikaciji (potrebni za OAuth handshake)
        self::$BC_CLIENT_ID = $_ENV['BC_CLIENT_ID'] ?? null;
        self::$BC_CLIENT_SECRET = $_ENV['BC_CLIENT_SECRET'] ?? null;
        
        // 2. Učitaj fallback vrednosti (opciono, mogu biti null u multi-tenant)
        self::$BC_STORE_HASH = $_ENV['BC_STORE_HASH'] ?? null;
        self::$BC_ACCESS_TOKEN = $_ENV['BC_ACCESS_TOKEN'] ?? null;
        
        // 3. Postavi GENERIČKI base URL. 
        // BigCommerceAPI klasa će na ovo dodati /stores/{hash}/v3/
        self::$BC_API_URL = 'https://api.bigcommerce.com'; 
        
        // 4. Database Credentials
        self::$DB_HOST = $_ENV['DB_HOST'] ?? 'localhost';
        self::$DB_NAME = $_ENV['DB_NAME'] ?? '';
        self::$DB_USER = $_ENV['DB_USER'] ?? 'root';
        self::$DB_PASS = $_ENV['DB_PASS'] ?? '';
        
        // 5. App Credentials
        self::$APP_URL = $_ENV['APP_URL'] ?? 'http://localhost';
        self::$ADMIN_USERNAME = $_ENV['ADMIN_USERNAME'] ?? 'admin';
        self::$ADMIN_PASSWORD = $_ENV['ADMIN_PASSWORD'] ?? '';
        self::$SECRET_CRON_KEY = $_ENV['SECRET_CRON_KEY'] ?? '';
        self::$CUSTOM_FIELD_NAME = $_ENV['CUSTOM_FIELD_NAME'] ?? 'Promocija';
        self::$SESSION_LIFETIME = $_ENV['SESSION_LIFETIME'] ?? 3600;
    }
}

// Inicijalizuj konfiguraciju odmah pri učitavanju fajla
Config::init();
