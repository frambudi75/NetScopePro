<?php
/**
 * IPManager Pro Updater Helper
 * Handles GitHub API version checks and caching.
 */

class Updater {
    private static $api_url = 'https://api.github.com/repos/' . GITHUB_REPO . '/releases/latest';

    /**
     * Check for updates if the cache has expired.
     */
    public static function check($force = false) {
        $last_check = Settings::get('last_update_check', 0);
        $cache_time = 86400; // 24 hours

        if ($force || (time() - (int)$last_check) > $cache_time) {
            $latest = self::fetchFromGitHub();
            if ($latest) {
                Settings::set('latest_version_cached', $latest['tag_name']);
                Settings::set('latest_version_url', $latest['html_url']);
                Settings::set('last_update_check', time());
                return $latest['tag_name'];
            }
        }
        return Settings::get('latest_version_cached', APP_VERSION);
    }

    /**
     * Compare current version with latest cached version.
     */
    public static function isUpdateAvailable() {
        $latest = Settings::get('latest_version_cached', APP_VERSION);
        return version_compare($latest, APP_VERSION, '>');
    }

    /**
     * Get the latest version string.
     */
    public static function getLatestVersion() {
        return Settings::get('latest_version_cached', APP_VERSION);
    }

    /**
     * Get the release URL.
     */
    public static function getUpdateUrl() {
        return Settings::get('latest_version_url', GITHUB_URL . '/releases/latest');
    }

    /**
     * Fetch the latest release from GitHub API.
     */
    private static function fetchFromGitHub() {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::$api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'IPManagerPro-Updater');
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For environments with issues with SSL certs

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 200) {
            $data = json_decode($response, true);
            if (isset($data['tag_name'])) {
                // Remove 'v' prefix if present (v3.0.0 -> 3.0.0)
                $tag = ltrim($data['tag_name'], 'v');
                return [
                    'tag_name' => $tag,
                    'html_url' => $data['html_url']
                ];
            }
        }
        return null;
    }
}
