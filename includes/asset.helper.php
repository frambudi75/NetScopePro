<?php
/**
 * AssetHelper - Utilities for server asset management (Security & Health)
 */
class AssetHelper {
    const LEGACY_DEFAULT_KEY = '27ffed91f93d4e8eaf12a66852b4a156';
    
    /**
     * Retrieve or generate binary encryption key securely
     */
    public static function getKey() {
        $raw = defined('ENCRYPTION_KEY') && ENCRYPTION_KEY !== '' ? ENCRYPTION_KEY : Settings::get('app_encryption_key', '');
        if (empty($raw)) {
            // Default to legacy standard key to ensure consistent persistence across environments
            $raw = self::LEGACY_DEFAULT_KEY;
            Settings::set('app_encryption_key', $raw);
        }
        return @pack('H*', $raw);
    }

    /**
     * Encrypt a string using AES-256-CBC
     */
    public static function encrypt($data) {
        if (empty($data)) return $data;
        $key = self::getKey();
        $iv_size = openssl_cipher_iv_length('aes-256-cbc');
        $iv = openssl_random_pseudo_bytes($iv_size);
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt a string using AES-256-CBC with multi-key fallback
     */
    public static function decrypt($data) {
        if (empty($data)) return $data;
        $decoded = @base64_decode($data, true);
        if ($decoded === false) return $data; // Not valid base64
        
        $iv_size = openssl_cipher_iv_length('aes-256-cbc');
        if (strlen($decoded) <= $iv_size) return $data; // Too short to contain IV
        
        $iv = substr($decoded, 0, $iv_size);
        $encrypted = substr($decoded, $iv_size);

        // Candidate keys to attempt (Active key first, then legacy default keys)
        $candidates = [];
        $current_raw = defined('ENCRYPTION_KEY') && ENCRYPTION_KEY !== '' ? ENCRYPTION_KEY : Settings::get('app_encryption_key', '');
        if (!empty($current_raw)) {
            $candidates[] = @pack('H*', $current_raw);
            $candidates[] = $current_raw;
        }
        $candidates[] = @pack('H*', self::LEGACY_DEFAULT_KEY);
        $candidates[] = self::LEGACY_DEFAULT_KEY;

        foreach ($candidates as $key) {
            if (empty($key)) continue;
            $decrypted = @openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
            if ($decrypted !== false && $decrypted !== '') {
                return $decrypted;
            }
        }
        
        return $data;
    }

    /**
     * Check if a string appears to be raw un-decrypted ciphertext
     */
    public static function isCiphertext($str) {
        if (!is_string($str) || strlen($str) < 24) return false;
        $trimmed = trim($str);
        // Valid base64 ending in = or ==
        if (preg_match('/^[a-zA-Z0-9\/+]{20,}={1,2}$/', $trimmed)) {
            return true;
        }
        return false;
    }

    /**
     * Basic Connectivity Check (Health Check)
     * Tries to open a TCP socket to the asset
     */
    public static function checkConnectivity($host, $port = 22, $timeout = 2) {
        $start = microtime(true);
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        $end = microtime(true);
        
        if ($fp) {
            fclose($fp);
            return [
                'status' => 'ONLINE',
                'latency' => round(($end - $start) * 1000, 2) . 'ms'
            ];
        } else {
            return [
                'status' => 'OFFLINE',
                'error' => $errstr
            ];
        }
    }
}
