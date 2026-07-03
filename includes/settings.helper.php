<?php
/**
 * Global Settings Helper
 */
class Settings {
    private static $cached_settings = null;
    private static $db = null;

    private static function init() {
        if (self::$cached_settings === null) {
            require_once 'db.php';
            self::$db = get_db_connection();
            
            $stmt = self::$db->query("SELECT * FROM settings");
            $list = $stmt->fetchAll();

            self::$cached_settings = [];
            foreach ($list as $s) {
                self::$cached_settings[$s['key']] = $s['value'];
            }
        }
    }

    public static function get($key, $default = '') {
        try {
            self::init();
            return self::$cached_settings[$key] ?? $default;
        } catch (Exception $e) {
            return $default;
        }
    }

    public static function enabled($key) {
        return self::get($key, '0') === '1';
    }

    public static function set($key, $value) {
        try {
            self::init();
            $stmt = self::$db->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
            $stmt->execute([$key, $value]);
            self::$cached_settings[$key] = $value;
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
