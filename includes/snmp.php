<?php
/**
 * SNMP Helper class
 */
class SNMPHelper {
    private static function fetchRawInfo($ip, $community, $timeout_microseconds, $retries) {
        $results = [
            'name' => null,
            'description' => null,
        ];

        // sysName.0 OID: .1.3.6.1.2.1.1.5.0
        $name = @snmpget($ip, $community, ".1.3.6.1.2.1.1.5.0", $timeout_microseconds, $retries);
        if ($name !== false) {
            $results['name'] = trim(str_replace('"', '', $name));
        }

        // sysDescr.0 OID: .1.3.6.1.2.1.1.1.0
        $descr = @snmpget($ip, $community, ".1.3.6.1.2.1.1.1.0", $timeout_microseconds, $retries);
        if ($descr !== false) {
            $results['description'] = trim(str_replace('"', '', $descr));
        }

        return ($results['name'] || $results['description']) ? $results : null;
    }

    public static function getInfo($ip, $community = 'public', $version = '2c') {
        if (!extension_loaded('snmp')) {
            return null;
        }

        // Set SNMP options
        snmp_set_quick_print(1);
        snmp_set_enum_print(0);
        snmp_set_valueretrieval(SNMP_VALUE_PLAIN);

        try {
            // Fast first try, then one adaptive retry with slightly longer timeout.
            $attempt_profiles = [
                ['timeout' => 400000, 'retries' => 0],
                ['timeout' => 900000, 'retries' => 1],
            ];

            foreach ($attempt_profiles as $idx => $profile) {
                $results = self::fetchRawInfo($ip, $community, $profile['timeout'], $profile['retries']);
                if ($results !== null) {
                    return $results;
                }

                // Add a tiny pause before retry to reduce burst packet loss effects.
                if ($idx === 0) {
                    usleep(120000);
                }
            }
        } catch (Exception $e) {
            return null;
        }

        return null;
    }
}
