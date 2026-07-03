<?php
/**
 * AssetHelper - Utilities for server asset management (Security & Health)
 */
class AssetHelper {
    
    /**
     * Encrypt a string using AES-256-CBC
     */
    public static function encrypt($data) {
        if (empty($data)) return $data;
        $key = pack('H*', ENCRYPTION_KEY);
        $iv_size = openssl_cipher_iv_length('aes-256-cbc');
        $iv = openssl_random_pseudo_bytes($iv_size);
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt a string using AES-256-CBC
     */
    public static function decrypt($data) {
        if (empty($data)) return $data;
        $key = @pack('H*', ENCRYPTION_KEY);
        $decoded = @base64_decode($data);
        if (!$decoded) return $data; // Not base64
        
        $iv_size = openssl_cipher_iv_length('aes-256-cbc');
        if (strlen($decoded) <= $iv_size) return $data; // Too short to have an IV
        
        $iv = substr($decoded, 0, $iv_size);
        $encrypted = substr($decoded, $iv_size);
        $decrypted = @openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        
        return ($decrypted === false) ? $data : $decrypted;
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
