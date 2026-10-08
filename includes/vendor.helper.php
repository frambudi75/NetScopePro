<?php
/**
 * IPManager Pro - SNMP Vendor Detection Helper
 * 
 * Detects device vendor from sysDescr and fetches CPU/RAM
 * using vendor-specific SNMP OIDs.
 * 
 * Supported Vendors:
 * - Cisco (IOS, IOS-XE, NX-OS, ASA)
 * - MikroTik (RouterOS)
 * - Juniper (JunOS)
 * - HP / Aruba / H3C (ProCurve, ArubaOS-Switch)
 * - Alcatel-Lucent / Nokia (AOS 6/7/8)
 * - Fortinet (FortiOS - FortiGate, FortiSwitch)
 * - pfSense / OPNsense (FreeBSD-based)
 * - Huawei (VRP)
 * - Dell / Force10 / PowerConnect
 * - Ubiquiti (EdgeSwitch, UniFi Switch)
 * - TP-Link (JetStream)
 * - D-Link (DGS, DES series)
 * - Zyxel (GS/XGS series)
 * - Extreme Networks (EXOS)
 * - Ruckus / Brocade (ICX, FastIron)
 * - Sophos (XG/XGS Firewall)
 * - Check Point (Gaia)
 * - Arista (EOS)
 * - Cambium / ePMP
 * - Palo Alto (PAN-OS)
 * - Netgear (Smart Managed)
 * - Moxa (Industrial)
 * - Allied Telesis
 * - Generic (RFC 2790 Host Resources MIB fallback)
 */

class VendorDetector {

    /**
     * Alcatel OmniSwitch sysObjectID hardware catalogue
     * Maps sysObjectID (.1.3.6.1.2.1.1.2.0) directly to exact commercial model names.
     */
    public static $alcatel_sysobjectid_map = [
        // Alcatel OmniSwitch 6350
        '.1.3.6.1.4.1.6486.800.1.1.2.1.13.1.1' => 'Alcatel OmniSwitch 6350-24',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.13.1.2' => 'Alcatel OmniSwitch 6350-P24',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.13.1.3' => 'Alcatel OmniSwitch 6350-48',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.13.1.4' => 'Alcatel OmniSwitch 6350-P48',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.13.1.5' => 'Alcatel OmniSwitch 6350-10',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.13.1.6' => 'Alcatel OmniSwitch 6350-P10',

        // Alcatel OmniSwitch 6450
        '.1.3.6.1.4.1.6486.800.1.1.2.1.12.1.1' => 'Alcatel OmniSwitch 6450-10',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.12.1.2' => 'Alcatel OmniSwitch 6450-P10',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.12.1.3' => 'Alcatel OmniSwitch 6450-10L',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.12.1.4' => 'Alcatel OmniSwitch 6450-P10L',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.12.1.5' => 'Alcatel OmniSwitch 6450-24',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.12.1.6' => 'Alcatel OmniSwitch 6450-P24',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.12.1.7' => 'Alcatel OmniSwitch 6450-U24',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.12.1.8' => 'Alcatel OmniSwitch 6450-48',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.12.1.9' => 'Alcatel OmniSwitch 6450-P48',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.12.1.10' => 'Alcatel OmniSwitch 6450-24L',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.12.1.11' => 'Alcatel OmniSwitch 6450-P24L',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.12.1.12' => 'Alcatel OmniSwitch 6450-48L',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.12.1.13' => 'Alcatel OmniSwitch 6450-P48L',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.12.1.14' => 'Alcatel OmniSwitch 6450-P10S',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.12.1.15' => 'Alcatel OmniSwitch 6450-U24S',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.12.1.16' => 'Alcatel OmniSwitch 6450-10M',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.12.1.17' => 'Alcatel OmniSwitch 6450-24XM',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.12.1.18' => 'Alcatel OmniSwitch 6450-24X',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.12.1.19' => 'Alcatel OmniSwitch 6450-P24X',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.12.1.20' => 'Alcatel OmniSwitch 6450-48X',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.12.1.21' => 'Alcatel OmniSwitch 6450-P48X',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.12.1.22' => 'Alcatel OmniSwitch 6450-U24SXM',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.12.1.23' => 'Alcatel OmniSwitch 6450-U24X',

        // Alcatel OmniSwitch 6250
        '.1.3.6.1.4.1.6486.800.1.1.2.1.11.1.1' => 'Alcatel OmniSwitch 6250-8M',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.11.1.2' => 'Alcatel OmniSwitch 6250-24M',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.11.1.3' => 'Alcatel OmniSwitch 6250-24MD',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.11.1.4' => 'Alcatel OmniSwitch 6250-U24M',

        // Alcatel OmniSwitch 6400
        '.1.3.6.1.4.1.6486.800.1.1.2.1.10.1.1' => 'Alcatel OmniSwitch 6400-24',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.10.1.2' => 'Alcatel OmniSwitch 6400-P24',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.10.1.3' => 'Alcatel OmniSwitch 6400-U24',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.10.1.4' => 'Alcatel OmniSwitch 6400-DU24',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.10.1.5' => 'Alcatel OmniSwitch 6400-48',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.10.1.6' => 'Alcatel OmniSwitch 6400-P48',

        // Alcatel OmniSwitch 6855
        '.1.3.6.1.4.1.6486.800.1.1.2.1.9.1.1' => 'Alcatel OmniSwitch 6855-14',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.9.1.2' => 'Alcatel OmniSwitch 6855-U10',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.9.1.3' => 'Alcatel OmniSwitch 6855-24',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.9.1.4' => 'Alcatel OmniSwitch 6855-U24',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.9.1.5' => 'Alcatel OmniSwitch 6855-U24X',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.9.1.6' => 'Alcatel OmniSwitch 6855-P14',

        // Alcatel OmniSwitch 6850
        '.1.3.6.1.4.1.6486.800.1.1.2.1.7.1.1' => 'Alcatel OmniSwitch 6850-24',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.7.1.2' => 'Alcatel OmniSwitch 6850-48',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.7.1.3' => 'Alcatel OmniSwitch 6850-24X',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.7.1.4' => 'Alcatel OmniSwitch 6850-48X',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.7.1.5' => 'Alcatel OmniSwitch 6850-P24',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.7.1.6' => 'Alcatel OmniSwitch 6850-P48',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.7.1.7' => 'Alcatel OmniSwitch 6850-P24X',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.7.1.8' => 'Alcatel OmniSwitch 6850-P48X',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.7.1.9' => 'Alcatel OmniSwitch 6850-U24',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.7.1.10' => 'Alcatel OmniSwitch 6850-U24X',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.7.1.11' => 'Alcatel OmniSwitch 6850-24L',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.7.1.12' => 'Alcatel OmniSwitch 6850-48L',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.7.1.13' => 'Alcatel OmniSwitch 6850-24XL',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.7.1.14' => 'Alcatel OmniSwitch 6850-48XL',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.7.1.15' => 'Alcatel OmniSwitch 6850-P24L',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.7.1.16' => 'Alcatel OmniSwitch 6850-P48L',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.7.1.17' => 'Alcatel OmniSwitch 6850-P24XL',
        '.1.3.6.1.4.1.6486.800.1.1.2.1.7.1.18' => 'Alcatel OmniSwitch 6850-P48XL',

        // Alcatel OmniSwitch 6900
        '.1.3.6.1.4.1.6486.801.1.1.2.1.10.1.1' => 'Alcatel OmniSwitch 6900-X20',
        '.1.3.6.1.4.1.6486.801.1.1.2.1.10.1.2' => 'Alcatel OmniSwitch 6900-X40',
        '.1.3.6.1.4.1.6486.801.1.1.2.1.10.1.3' => 'Alcatel OmniSwitch 6900-T20',
        '.1.3.6.1.4.1.6486.801.1.1.2.1.10.1.4' => 'Alcatel OmniSwitch 6900-T40',
        '.1.3.6.1.4.1.6486.801.1.1.2.1.10.1.5' => 'Alcatel OmniSwitch 6900-Q32',
        '.1.3.6.1.4.1.6486.801.1.1.2.1.10.1.6' => 'Alcatel OmniSwitch 6900-X72'
    ];

    /**
     * Detect vendor and poll CPU/RAM via SNMP.
     * Returns ['model' => string, 'cpu' => int, 'mem' => int]
     */
    public static function detect($ip, $community, $sys_descr) {
        $info = strtolower($sys_descr);

        // Probe sysObjectID (.1.3.6.1.2.1.1.2.0) for high-precision hardware model detection
        $sysobj_raw = @snmp2_get($ip, $community, ".1.3.6.1.2.1.1.2.0", 350000, 1);
        $sysobj = $sysobj_raw ? trim(str_replace(['OID: ', '"'], '', $sysobj_raw)) : '';
        if ($sysobj && !str_starts_with($sysobj, '.')) {
            $sysobj = '.' . $sysobj;
        }

        // Fast match on Alcatel sysObjectID table
        $exact_alcatel_model = null;
        if ($sysobj && isset(self::$alcatel_sysobjectid_map[$sysobj])) {
            $exact_alcatel_model = self::$alcatel_sysobjectid_map[$sysobj];
        }

        // Try each vendor in order of specificity
        $vendors = [
            'Cisco'           => ['match' => ['cisco'], 'handler' => 'pollCisco'],
            'MikroTik'        => ['match' => ['mikrotik', 'routeros'], 'handler' => 'pollMikroTik'],
            'Fortinet'        => ['match' => ['fortinet', 'fortigate', 'fortiswitch', 'fortios'], 'handler' => 'pollFortinet'],
            'Palo Alto'       => ['match' => ['palo alto', 'pan-os'], 'handler' => 'pollPaloAlto'],
            'Juniper'         => ['match' => ['juniper', 'junos', 'srx', 'ex2', 'ex3', 'ex4', 'qfx'], 'handler' => 'pollJuniper'],
            'HP/Aruba'        => ['match' => ['procurve', 'aruba', 'h3c', 'comware', 'hpe'], 'handler' => 'pollHPAruba'],
            'Alcatel-Lucent'  => ['match' => ['alcatel', 'aos', 'omniswitch', 'nokia'], 'handler' => 'pollAlcatel'],
            'Huawei'          => ['match' => ['huawei', 'vrp', 'versatile routing'], 'handler' => 'pollHuawei'],
            'pfSense'         => ['match' => ['pfsense', 'opnsense'], 'handler' => 'pollNetSNMP'],
            'Sophos'          => ['match' => ['sophos', 'sfos', 'cyberoam'], 'handler' => 'pollSophos'],
            'Check Point'     => ['match' => ['check point', 'gaia', 'splat'], 'handler' => 'pollCheckPoint'],
            'Extreme'         => ['match' => ['extreme', 'exos', 'extremexos'], 'handler' => 'pollExtreme'],
            'Ruckus'          => ['match' => ['ruckus', 'brocade', 'fastiron', 'icx'], 'handler' => 'pollRuckus'],
            'Dell'            => ['match' => ['dell', 'force10', 'ftos', 'powerconnect', 'os6', 'os9', 'os10'], 'handler' => 'pollDell'],
            'Arista'          => ['match' => ['arista', 'eos'], 'handler' => 'pollGenericHR'],
            'Ubiquiti'        => ['match' => ['ubiquiti', 'edgeswitch', 'edgeos', 'unifi', 'ubnt'], 'handler' => 'pollGenericHR'],
            'TP-Link'         => ['match' => ['tp-link', 'tplink', 'jetstream', 't1600', 't2600'], 'handler' => 'pollTPLink'],
            'D-Link'          => ['match' => ['d-link', 'dlink', 'dgs-', 'des-'], 'handler' => 'pollDLink'],
            'Zyxel'           => ['match' => ['zyxel', 'zywall', 'usg flex'], 'handler' => 'pollZyxel'],
            'Netgear'         => ['match' => ['netgear', 'prosafe'], 'handler' => 'pollGenericHR'],
            'Moxa'            => ['match' => ['moxa'], 'handler' => 'pollGenericHR'],
            'Allied Telesis'  => ['match' => ['allied', 'alliedware', 'at-'], 'handler' => 'pollGenericHR'],
            'Cambium'         => ['match' => ['cambium', 'epmp', 'cnmatrix'], 'handler' => 'pollGenericHR'],

            'Windows Server'  => ['match' => ['windows', 'microsoft'], 'handler' => 'pollGenericHR'],
            'FreeBSD'         => ['match' => ['freebsd'], 'handler' => 'pollNetSNMP'],
            'Linux Server'    => ['match' => ['linux', 'ubuntu', 'debian', 'centos', 'rhel', 'rocky', 'alma', 'net-snmp'], 'handler' => 'pollNetSNMP'],
        ];

        // Direct Alcatel hit from sysObjectID
        if ($exact_alcatel_model) {
            $result = self::pollAlcatel($ip, $community);
            $result['model'] = $exact_alcatel_model;
            $result['temp'] = self::pollTemperature($ip, $community, $result['model'], $sys_descr);
            return $result;
        }

        foreach ($vendors as $model => $v) {
            foreach ($v['match'] as $keyword) {
                if (strpos($info, $keyword) !== false) {
                    $result = self::{$v['handler']}($ip, $community);
                    $result['model'] = ($model === 'Alcatel-Lucent' && $exact_alcatel_model) ? $exact_alcatel_model : $model;
                    $result['temp'] = self::pollTemperature($ip, $community, $result['model'], $sys_descr);
                    return $result;
                }
            }
        }

        // Alcatel OID branch check fallback (.1.3.6.1.4.1.6486)
        if ($sysobj && strpos($sysobj, '.1.3.6.1.4.1.6486.') !== false) {
            $result = self::pollAlcatel($ip, $community);
            $result['model'] = 'Alcatel OmniSwitch';
            $result['temp'] = self::pollTemperature($ip, $community, $result['model'], $sys_descr);
            return $result;
        }

        // Ultimate fallback
        $result = self::pollGenericHR($ip, $community);
        $result['model'] = 'Generic';
        $result['temp'] = self::pollTemperature($ip, $community, $result['model'], $sys_descr);
        return $result;
    }

    // =====================================================================
    // VENDOR-SPECIFIC POLLERS
    // =====================================================================

    private static function pollCisco($ip, $c) {
        // cpmCPUTotal5minRev (.1.3.6.1.4.1.9.9.109.1.1.1.1.5.1)
        $cpu = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.9.9.109.1.1.1.1.5.1");
        // ciscoMemoryPoolUsed/Free
        $used = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.9.9.48.1.1.1.5.1");
        $free = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.9.9.48.1.1.1.6.1");
        $mem = ($used > 0) ? round(($used / ($used + $free)) * 100) : 0;

        // ASA fallback
        if ($cpu == 0 && $mem == 0) {
            $cpu = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.9.9.109.1.1.1.1.8.1"); // cpmCPUTotal5secRev
            $used = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.9.9.221.1.1.1.1.18.1.1");
            $free = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.9.9.221.1.1.1.1.20.1.1");
            if ($used > 0) $mem = round(($used / ($used + $free)) * 100);
        }

        if ($cpu == 0) $cpu = self::getGenericCPU($ip, $c);
        if ($mem == 0) $mem = self::getGenericMem($ip, $c);
        return ['cpu' => $cpu, 'mem' => $mem];
    }

    private static function pollMikroTik($ip, $c) {
        $cpu = @snmp2_get($ip, $c, ".1.3.6.1.4.1.14988.1.1.3.11.0");
        $total = @snmp2_get($ip, $c, ".1.3.6.1.4.1.14988.1.1.3.8.0");
        $used  = @snmp2_get($ip, $c, ".1.3.6.1.4.1.14988.1.1.3.9.0");
        $mem = ((int)$total > 0) ? round(((int)$used / (int)$total) * 100) : 0;

        if ($cpu === false || $cpu === "" || (int)$cpu >= 100) {
            $cpu = self::getGenericCPU($ip, $c);
        }
        if ($mem == 0) $mem = self::getGenericMem($ip, $c);
        return ['cpu' => (int)$cpu, 'mem' => $mem];
    }

    private static function pollFortinet($ip, $c) {
        // fgSysCpuUsage
        $cpu = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.12356.101.4.1.3.0");
        // fgSysMemUsage (already percentage)
        $mem = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.12356.101.4.1.4.0");
        // Fallback: fgSysMemCapacity + calculate
        if ($mem == 0) {
            $total = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.12356.101.4.1.5.0");
            $used_kb = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.12356.101.4.1.6.0");
            if ($total > 0) $mem = round(($used_kb / $total) * 100);
        }
        if ($cpu == 0) $cpu = self::getGenericCPU($ip, $c);
        if ($mem == 0) $mem = self::getGenericMem($ip, $c);
        return ['cpu' => $cpu, 'mem' => $mem];
    }

    private static function pollPaloAlto($ip, $c) {
        // panSessionUtilization / panSysCpuMgmt
        $cpu = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.25461.2.1.2.3.1.0");
        // panSessionMax for load estimate
        if ($cpu == 0) $cpu = self::getGenericCPU($ip, $c);
        $mem = self::getGenericMem($ip, $c);
        return ['cpu' => $cpu, 'mem' => $mem];
    }

    private static function pollJuniper($ip, $c) {
        $cpu = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.2636.3.1.13.1.8.1.1.0");
        $mem = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.2636.3.1.13.1.11.1.1.0");
        if ($cpu == 0) $cpu = self::getGenericCPU($ip, $c);
        if ($mem == 0) $mem = self::getGenericMem($ip, $c);
        return ['cpu' => $cpu, 'mem' => $mem];
    }

    private static function pollHPAruba($ip, $c) {
        // H3C/Comware
        $cpu = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.25506.2.6.1.1.1.1.6.1");
        $mem = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.25506.2.6.1.1.1.1.8.1");
        if ($cpu == 0) $cpu = self::getGenericCPU($ip, $c);
        if ($mem == 0) $mem = self::getGenericMem($ip, $c);
        return ['cpu' => $cpu, 'mem' => $mem];
    }

    private static function pollAlcatel($ip, $c) {
        // Base Health OIDs (Alcatel can have multiple modules/chassis indices)
        // Chassis-based OIDs
        $cpu_roots = [
            ".1.3.6.1.4.1.6486.800.1.2.1.16.1.1.1.13", // healthModuleCpuChassisUtil
            ".1.3.6.1.4.1.6486.801.1.2.1.16.1.1.1.13",
            ".1.3.6.1.4.1.6486.800.1.2.1.16.1.1.1.2",  // healthModuleCpu1MinAvg
            ".1.3.6.1.4.1.6486.800.1.2.1.10.1.1.1.11" // alaStackMgrHealthCpuUtil
        ];
        $mem_roots = [
            ".1.3.6.1.4.1.6486.800.1.2.1.16.1.1.1.10", // healthModuleMemoryChassisUtil
            ".1.3.6.1.4.1.6486.801.1.2.1.16.1.1.1.10",
            ".1.3.6.1.4.1.6486.800.1.2.1.10.1.1.1.12" // alaStackMgrHealthMemoryUtil
        ];

        $cpu = 0; $mem = 0;
        
        foreach ($cpu_roots as $root) {
            $walk = @snmp2_real_walk($ip, $c, $root);
            if ($walk) {
                foreach ($walk as $val) {
                    $val = (int)trim(str_replace(['"', 'INTEGER: '], '', $val));
                    if ($val > 0 && $val <= 100) { $cpu = $val; break 2; }
                }
            }
        }

        foreach ($mem_roots as $root) {
            $walk = @snmp2_real_walk($ip, $c, $root);
            if ($walk) {
                foreach ($walk as $val) {
                    $val = (int)trim(str_replace(['"', 'INTEGER: '], '', $val));
                    if ($val > 0 && $val <= 100) { $mem = $val; break 2; }
                }
            }
        }

        if ($cpu == 0) $cpu = self::getGenericCPU($ip, $c);
        if ($mem == 0) $mem = self::getGenericMem($ip, $c);
        return ['cpu' => $cpu, 'mem' => $mem];
    }

    private static function pollHuawei($ip, $c) {
        // hwEntityCpuUsage (walk, take first)
        $cpu_walk = @snmp2_real_walk($ip, $c, ".1.3.6.1.4.1.2011.5.25.31.1.1.1.1.5");
        $cpu = 0;
        if ($cpu_walk) {
            foreach ($cpu_walk as $val) { $cpu = (int)$val; if ($cpu > 0) break; }
        }
        // hwEntityMemUsage
        $mem_walk = @snmp2_real_walk($ip, $c, ".1.3.6.1.4.1.2011.5.25.31.1.1.1.1.37");
        $mem = 0;
        if ($mem_walk) {
            foreach ($mem_walk as $val) { $mem = (int)$val; if ($mem > 0) break; }
        }
        if ($cpu == 0) $cpu = self::getGenericCPU($ip, $c);
        if ($mem == 0) $mem = self::getGenericMem($ip, $c);
        return ['cpu' => $cpu, 'mem' => $mem];
    }

    private static function pollSophos($ip, $c) {
        $cpu = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.2604.5.1.1.0");
        $mem = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.2604.5.1.2.0");
        if ($cpu == 0) $cpu = self::getGenericCPU($ip, $c);
        if ($mem == 0) $mem = self::getGenericMem($ip, $c);
        return ['cpu' => $cpu, 'mem' => $mem];
    }

    private static function pollCheckPoint($ip, $c) {
        $cpu = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.2620.1.6.7.2.6.0");
        $total = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.2620.1.6.7.4.1.0");
        $active = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.2620.1.6.7.4.3.0");
        $mem = ($total > 0) ? round(($active / $total) * 100) : 0;
        if ($cpu == 0) $cpu = self::getGenericCPU($ip, $c);
        if ($mem == 0) $mem = self::getGenericMem($ip, $c);
        return ['cpu' => $cpu, 'mem' => $mem];
    }

    private static function pollExtreme($ip, $c) {
        $cpu = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.1916.1.32.1.4.1.5.1");
        $total = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.1916.1.32.2.2.1.2.1");
        $free  = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.1916.1.32.2.2.1.3.1");
        $mem = ($total > 0) ? round((($total - $free) / $total) * 100) : 0;
        if ($cpu == 0) $cpu = self::getGenericCPU($ip, $c);
        if ($mem == 0) $mem = self::getGenericMem($ip, $c);
        return ['cpu' => $cpu, 'mem' => $mem];
    }

    private static function pollRuckus($ip, $c) {
        // snAgentCpuUtil100thPercent (divide by 100)
        $cpu_raw = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.1991.1.1.2.11.1.1.4.1");
        $cpu = ($cpu_raw > 0) ? round($cpu_raw / 100) : 0;
        $mem = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.1991.1.1.2.1.53.0");
        if ($cpu == 0) $cpu = self::getGenericCPU($ip, $c);
        if ($mem == 0) $mem = self::getGenericMem($ip, $c);
        return ['cpu' => $cpu, 'mem' => $mem];
    }

    private static function pollDell($ip, $c) {
        $cpu = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.674.10895.5000.2.6132.1.1.1.1.4.9.0");
        if ($cpu == 0) $cpu = self::getGenericCPU($ip, $c);
        $mem = self::getGenericMem($ip, $c);
        return ['cpu' => $cpu, 'mem' => $mem];
    }

    private static function pollTPLink($ip, $c) {
        // tpSysMonitorCpu1Min
        $cpu = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.11863.6.4.1.1.1.1.2.0");
        // tpSysMonitorMemoryUtil
        $mem = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.11863.6.4.1.2.1.1.2.0");
        if ($cpu == 0) $cpu = self::getGenericCPU($ip, $c);
        if ($mem == 0) $mem = self::getGenericMem($ip, $c);
        return ['cpu' => $cpu, 'mem' => $mem];
    }

    private static function pollDLink($ip, $c) {
        // agentCPUutilizationIn5min
        $cpu = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.171.12.1.1.6.2.0");
        $mem = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.171.12.1.1.9.5.0");
        if ($cpu == 0) $cpu = self::getGenericCPU($ip, $c);
        if ($mem == 0) $mem = self::getGenericMem($ip, $c);
        return ['cpu' => $cpu, 'mem' => $mem];
    }

    private static function pollZyxel($ip, $c) {
        $cpu = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.890.1.15.3.2.5.0");
        if ($cpu == 0) $cpu = self::getGenericCPU($ip, $c);
        $mem = self::getGenericMem($ip, $c);
        return ['cpu' => $cpu, 'mem' => $mem];
    }

    /**
     * UCD-SNMP-MIB handler for net-snmp based systems.
     * Used by: pfSense, OPNsense, FreeBSD, Linux servers.
     * OID tree: .1.3.6.1.4.1.2021
     */
    private static function pollNetSNMP($ip, $c) {
        // ssCpuIdle (.1.3.6.1.4.1.2021.11.11.0) — returns idle %, usage = 100 - idle
        $idle = @snmp2_get($ip, $c, ".1.3.6.1.4.1.2021.11.11.0");
        $cpu = ($idle !== false && $idle !== "") ? (100 - (int)$idle) : 0;

        // Fallback: ssCpuUser + ssCpuSystem
        if ($cpu == 0 || $idle === false) {
            $user = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.2021.11.9.0");
            $sys  = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.2021.11.10.0");
            if ($user + $sys > 0) $cpu = $user + $sys;
        }

        // memTotalReal / memAvailReal (in KB)
        $total = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.2021.4.5.0");
        $avail = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.2021.4.6.0");
        $mem = ($total > 0) ? round((($total - $avail) / $total) * 100) : 0;

        // Final fallback to HR MIB
        if ($cpu == 0) $cpu = self::getGenericCPU($ip, $c);
        if ($mem == 0) $mem = self::getGenericMem($ip, $c);
        return ['cpu' => $cpu, 'mem' => $mem];
    }

    // =====================================================================
    // GENERIC FALLBACKS (RFC 2790 - Host Resources MIB)
    // =====================================================================

    public static function pollGenericHR($ip, $c) {
        return [
            'cpu' => self::getGenericCPU($ip, $c),
            'mem' => self::getGenericMem($ip, $c),
        ];
    }

    private static function getGenericCPU($ip, $c) {
        // hrProcessorLoad (.1.3.6.1.2.1.25.3.3.1.2) — average all cores
        $cores = @snmp2_real_walk($ip, $c, ".1.3.6.1.2.1.25.3.3.1.2");
        if ($cores && count($cores) > 0) {
            $sum = 0; $cnt = 0;
            foreach ($cores as $val) {
                $sum += (int)trim(str_replace(['INTEGER: ', '"'], '', $val));
                $cnt++;
            }
            return $cnt > 0 ? round($sum / $cnt) : 0;
        }
        return 0;
    }

    private static function getGenericMem($ip, $c) {
        // Try hrStorage walk to find RAM type
        $types = @snmp2_real_walk($ip, $c, ".1.3.6.1.2.1.25.2.3.1.2");
        if ($types) {
            foreach ($types as $oid => $type) {
                // hrStorageRam = .1.3.6.1.2.1.25.2.1.2
                if (strpos($type, ".1.3.6.1.2.1.25.2.1.2") !== false) {
                    $parts = explode('.', $oid);
                    $idx = end($parts);
                    $total = (int)@snmp2_get($ip, $c, ".1.3.6.1.2.1.25.2.3.1.5.$idx");
                    $used  = (int)@snmp2_get($ip, $c, ".1.3.6.1.2.1.25.2.3.1.6.$idx");
                    if ($total > 0) return round(($used / $total) * 100);
                }
            }
        }
        // Direct fallback index 65536
        $total = (int)@snmp2_get($ip, $c, ".1.3.6.1.2.1.25.2.3.1.5.65536");
        $used  = (int)@snmp2_get($ip, $c, ".1.3.6.1.2.1.25.2.3.1.6.65536");
        if ($total > 0) return round(($used / $total) * 100);
        // Ultimate fallback: UCD-SNMP memTotalReal/memAvailReal
        $ucd_total = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.2021.4.5.0");
        $ucd_avail = (int)@snmp2_get($ip, $c, ".1.3.6.1.4.1.2021.4.6.0");
        if ($ucd_total > 0) return round((($ucd_total - $ucd_avail) / $ucd_total) * 100);
        return 0;
    }

    // =====================================================================
    // SFP / DOM POLLING
    // =====================================================================

    /**
     * Poll SFP/DOM data based on detected vendor model.
     * Returns an array of interface indices mapped to their SFP data:
     * [ ifIndex => ['vendor'=>'...', 'part'=>'...', 'serial'=>'...', 'rx_power'=>'...', 'tx_power'=>'...'] ]
     */
    public static function pollSfpDOM($ip, $community, $model) {
        $sfp_data = [];
        
        // MikroTik (RouterOS v6 & v7 included)
        // mtxrOpticalTable: .1.3.6.1.4.1.14988.1.1.19.1.1
        // .2: mtxrOpticalName (e.g. sfp-sfpplus8, sfp1)
        // .3: mtxrOpticalRxLoss (0=false, 1=true)
        // .4: mtxrOpticalTxFault (0=false, 1=true)
        // .5: mtxrOpticalWaveLength (nm, e.g. 1310, 850)
        // .6: mtxrOpticalTemperature (deci-Celsius, e.g. 340 = 34.0 C)
        // .7: mtxrOpticalSupplyVoltage (mV, e.g. 3300 = 3.3V)
        // .8: mtxrOpticalTxBiasCurrent (mA)
        // .9: mtxrOpticalTxPower (millidBm, e.g. -2400 = -2.40 dBm)
        // .10: mtxrOpticalRxPower (millidBm, e.g. -5100 = -5.10 dBm)
        if (stripos($model, 'MikroTik') !== false || stripos($model, 'RouterOS') !== false) {
            $opt_names = [];
            $walk_names = @snmp2_real_walk($ip, $community, ".1.3.6.1.4.1.14988.1.1.19.1.1.2", 400000, 1);
            if ($walk_names && is_array($walk_names)) {
                foreach ($walk_names as $oid => $val) {
                    $parts = explode('.', $oid);
                    $opt_names[end($parts)] = trim(str_replace(['STRING: ', '"'], '', $val));
                }
            }

            $opt_waves = [];
            $walk_waves = @snmp2_real_walk($ip, $community, ".1.3.6.1.4.1.14988.1.1.19.1.1.5", 400000, 1);
            if ($walk_waves && is_array($walk_waves)) {
                foreach ($walk_waves as $oid => $val) {
                    $parts = explode('.', $oid);
                    $opt_waves[end($parts)] = (int)trim(str_replace(['INTEGER: ', 'Gauge32: ', '"'], '', $val));
                }
            }

            $opt_temps = [];
            $walk_temps = @snmp2_real_walk($ip, $community, ".1.3.6.1.4.1.14988.1.1.19.1.1.6", 400000, 1);
            if ($walk_temps && is_array($walk_temps)) {
                foreach ($walk_temps as $oid => $val) {
                    $parts = explode('.', $oid);
                    $raw_t = (int)trim(str_replace(['INTEGER: ', 'Gauge32: ', '"'], '', $val));
                    if ($raw_t > 0 && $raw_t < 1500) {
                        $opt_temps[end($parts)] = round($raw_t / 10, 1) . '°C';
                    }
                }
            }

            $opt_tx = [];
            $walk_tx = @snmp2_real_walk($ip, $community, ".1.3.6.1.4.1.14988.1.1.19.1.1.9", 400000, 1);
            if ($walk_tx && is_array($walk_tx)) {
                foreach ($walk_tx as $oid => $val) {
                    $parts = explode('.', $oid);
                    $raw_tx = (int)trim(str_replace(['INTEGER: ', '"'], '', $val));
                    if ($raw_tx <= -40000 || $raw_tx >= 100000) {
                        $opt_tx[end($parts)] = 'No Light';
                    } else {
                        $opt_tx[end($parts)] = round($raw_tx / 1000, 2) . ' dBm';
                    }
                }
            }

            $opt_rx = [];
            $walk_rx = @snmp2_real_walk($ip, $community, ".1.3.6.1.4.1.14988.1.1.19.1.1.10", 400000, 1);
            if ($walk_rx && is_array($walk_rx)) {
                foreach ($walk_rx as $oid => $val) {
                    $parts = explode('.', $oid);
                    $raw_rx = (int)trim(str_replace(['INTEGER: ', '"'], '', $val));
                    if ($raw_rx <= -40000 || $raw_rx >= 100000) {
                        $opt_rx[end($parts)] = 'No Light';
                    } else {
                        $opt_rx[end($parts)] = round($raw_rx / 1000, 2) . ' dBm';
                    }
                }
            }

            if (!empty($opt_names)) {
                foreach ($opt_names as $idx => $pname) {
                    $wave = $opt_waves[$idx] ?? null;
                    $wavelength_str = ($wave && $wave > 0) ? $wave . 'nm' : null;
                    $part_desc = $wavelength_str ? "Optical DDM ({$wavelength_str})" : "Optical DDM Transceiver";
                    if (isset($opt_temps[$idx])) {
                        $part_desc .= " • " . $opt_temps[$idx];
                    }

                    $entry = [
                        'vendor'   => 'MikroTik / DDM',
                        'part'     => $part_desc,
                        'serial'   => $wavelength_str ?: 'DDM Standard',
                        'rx_power' => $opt_rx[$idx] ?? null,
                        'tx_power' => $opt_tx[$idx] ?? null,
                    ];

                    $sfp_data[$idx] = $entry;
                    $sfp_data[$pname] = $entry;
                    $sfp_data[strtolower($pname)] = $entry;
                }
            }
            return $sfp_data;
        }

        // Juniper (jnxDomCurrentTable)
        // .1.3.6.1.4.1.2636.3.60.1.1.1
        if (stripos($model, 'Juniper') !== false) {
            $rx_power = @snmp2_real_walk($ip, $community, ".1.3.6.1.4.1.2636.3.60.1.1.1.1.5");
            $tx_power = @snmp2_real_walk($ip, $community, ".1.3.6.1.4.1.2636.3.60.1.1.1.1.7");
            // If DOM table exists, map it
            if ($rx_power && is_array($rx_power)) {
                foreach ($rx_power as $oid => $val) {
                    $oid_parts = explode('.', $oid);
                    $idx = end($oid_parts);
                    $sfp_data[$idx] = [
                        'vendor'   => 'Juniper', // Vendor string is usually in ENTITY-MIB, simplified here
                        'part'     => null,
                        'serial'   => null,
                        'rx_power' => trim(str_replace(['INTEGER: ', '"'], '', $val)) . ' 0.01dBm', // Juniper uses 0.01 dBm steps
                        'tx_power' => isset($tx_power[$oid]) ? trim(str_replace(['INTEGER: ', '"'], '', $tx_power[$oid])) . ' 0.01dBm' : null
                    ];
                }
            }
            return $sfp_data;
        }

        // Generic ENTITY-MIB (Cisco, HP, Alcatel, etc)
        // Try grabbing standard DOM table if available (CISCO-ENTITY-SENSOR-MIB or generic)
        // For simplicity in generic fallback, we'll return empty as full ENTITY-MIB correlation to ifIndex is complex.
        
        return $sfp_data;
    }

    /**
     * Poll Switch Hardware Temperature (°C) via SNMP.
     * Supports Cisco, Alcatel OmniSwitch, MikroTik, Huawei, Juniper, HP/Aruba, Extreme, Dell, Fortinet.
     * Returns integer temperature in Celsius, or null if unsupported/unavailable.
     */
    public static function pollTemperature($ip, $community, $model, $sys_descr = '') {
        $info = strtolower($model . ' ' . $sys_descr);
        $temp = null;

        // 1. Cisco (CISCO-ENVMON-MIB ciscoEnvMonTemperatureStatusValue)
        if (stripos($info, 'cisco') !== false) {
            $walk = @snmp2_real_walk($ip, $community, ".1.3.6.1.4.1.9.9.13.1.3.1.3", 400000, 1);
            if ($walk && is_array($walk)) {
                foreach ($walk as $val) {
                    $val = (int)trim(str_replace(['INTEGER: ', '"'], '', $val));
                    if ($val >= 15 && $val <= 110) {
                        $temp = $val;
                        break;
                    }
                }
            }
            // Cisco Entity Sensor MIB fallback (.1.3.6.1.4.1.9.9.91.1.1.1.1.4)
            if ($temp === null) {
                $e_walk = @snmp2_real_walk($ip, $community, ".1.3.6.1.4.1.9.9.91.1.1.1.1.4", 400000, 1);
                if ($e_walk && is_array($e_walk)) {
                    foreach ($e_walk as $val) {
                        $val = (int)trim(str_replace(['INTEGER: ', '"'], '', $val));
                        if ($val >= 20 && $val <= 100) {
                            $temp = $val;
                            break;
                        }
                    }
                }
            }
        }

        // 2. Alcatel OmniSwitch (chasHardwareBoardTemp)
        if ($temp === null && (stripos($info, 'alcatel') !== false || stripos($info, 'omniswitch') !== false || stripos($info, 'aos') !== false)) {
            $alcatel_oids = [
                ".1.3.6.1.4.1.6486.800.1.1.1.3.1.1.3.1",
                ".1.3.6.1.4.1.6486.800.1.1.1.3.1.1.3",
                ".1.3.6.1.4.1.6486.801.1.1.1.3.1.1.3.1",
                ".1.3.6.1.4.1.6486.801.1.1.1.3.1.1.3",
                ".1.3.6.1.4.1.6486.800.1.1.1.2.1.1.3.1"
            ];
            foreach ($alcatel_oids as $oid) {
                $val = @snmp2_get($ip, $community, $oid, 400000, 1);
                if ($val !== false && $val !== '') {
                    $num = (int)trim(str_replace(['INTEGER: ', 'Gauge32: ', '"'], '', $val));
                    if ($num >= 15 && $num <= 110) {
                        $temp = $num;
                        break;
                    }
                }
                $walk = @snmp2_real_walk($ip, $community, $oid, 400000, 1);
                if ($walk && is_array($walk)) {
                    foreach ($walk as $wval) {
                        $num = (int)trim(str_replace(['INTEGER: ', 'Gauge32: ', '"'], '', $wval));
                        if ($num >= 15 && $num <= 110) {
                            $temp = $num;
                            break 2;
                        }
                    }
                }
            }
        }

        // 3. MikroTik (RouterOS mtxrHlTemperature / mtxrHlCpuTemperature)
        if ($temp === null && (stripos($info, 'mikrotik') !== false || stripos($info, 'routeros') !== false)) {
            $mt_oids = [
                ".1.3.6.1.4.1.14988.1.1.3.10.0", // mtxrHlTemperature
                ".1.3.6.1.4.1.14988.1.1.3.11.0", // mtxrHlCpuTemperature
                ".1.3.6.1.4.1.14988.1.1.3.14.0", // mtxrHlBoardTemperature
            ];
            foreach ($mt_oids as $oid) {
                $raw = @snmp2_get($ip, $community, $oid, 400000, 1);
                if ($raw !== false && $raw !== '') {
                    $val = (int)trim(str_replace(['INTEGER: ', 'Gauge32: ', '"'], '', $raw));
                    if ($val > 150) $val = (int)round($val / 10);
                    if ($val >= 15 && $val <= 115) {
                        $temp = $val;
                        break;
                    }
                }
            }
        }

        // 4. Huawei (hwEntityTemperature)
        if ($temp === null && stripos($info, 'huawei') !== false) {
            $walk = @snmp2_real_walk($ip, $community, ".1.3.6.1.4.1.2011.5.25.31.1.1.1.1.11", 400000, 1);
            if ($walk && is_array($walk)) {
                foreach ($walk as $wval) {
                    $num = (int)trim(str_replace(['INTEGER: ', '"'], '', $wval));
                    if ($num >= 15 && $num <= 110) {
                        $temp = $num;
                        break;
                    }
                }
            }
        }

        // 5. Juniper (jnxOperatingTemp)
        if ($temp === null && stripos($info, 'juniper') !== false) {
            $walk = @snmp2_real_walk($ip, $community, ".1.3.6.1.4.1.2636.3.1.13.1.7", 400000, 1);
            if ($walk && is_array($walk)) {
                foreach ($walk as $wval) {
                    $num = (int)trim(str_replace(['INTEGER: ', '"'], '', $wval));
                    if ($num >= 15 && $num <= 110) {
                        $temp = $num;
                        break;
                    }
                }
            }
        }

        // 6. HP / Aruba / H3C
        if ($temp === null && (stripos($info, 'aruba') !== false || stripos($info, 'procurve') !== false || stripos($info, 'h3c') !== false || stripos($info, 'hpe') !== false)) {
            $hp_walk = @snmp2_real_walk($ip, $community, ".1.3.6.1.4.1.25506.2.6.1.1.1.1.12", 400000, 1);
            if ($hp_walk && is_array($hp_walk)) {
                foreach ($hp_walk as $wval) {
                    $num = (int)trim(str_replace(['INTEGER: ', '"'], '', $wval));
                    if ($num >= 15 && $num <= 110) { $temp = $num; break; }
                }
            }
            if ($temp === null) {
                $hp_procurve = @snmp2_real_walk($ip, $community, ".1.3.6.1.4.1.11.2.14.11.1.2.6.1.4", 400000, 1);
                if ($hp_procurve && is_array($hp_procurve)) {
                    foreach ($hp_procurve as $wval) {
                        $num = (int)trim(str_replace(['INTEGER: ', '"'], '', $wval));
                        if ($num >= 15 && $num <= 110) { $temp = $num; break; }
                    }
                }
            }
        }

        // 7. Extreme Networks
        if ($temp === null && stripos($info, 'extreme') !== false) {
            $val = @snmp2_get($ip, $community, ".1.3.6.1.4.1.1916.1.1.1.8.0", 400000, 1);
            if ($val !== false && $val !== '') {
                $num = (int)trim(str_replace(['INTEGER: ', '"'], '', $val));
                if ($num >= 15 && $num <= 110) $temp = $num;
            }
        }

        // 8. Dell
        if ($temp === null && stripos($info, 'dell') !== false) {
            $walk = @snmp2_real_walk($ip, $community, ".1.3.6.1.4.1.674.10895.5000.2.6132.1.1.43.1.8.1.4", 400000, 1);
            if ($walk && is_array($walk)) {
                foreach ($walk as $wval) {
                    $num = (int)trim(str_replace(['INTEGER: ', '"'], '', $wval));
                    if ($num >= 15 && $num <= 110) { $temp = $num; break; }
                }
            }
        }

        // 9. Fortinet
        if ($temp === null && stripos($info, 'fortinet') !== false) {
            $walk = @snmp2_real_walk($ip, $community, ".1.3.6.1.4.1.12356.101.4.3.2.1.3", 400000, 1);
            if ($walk && is_array($walk)) {
                foreach ($walk as $wval) {
                    $num = (int)trim(str_replace(['INTEGER: ', '"'], '', $wval));
                    if ($num >= 15 && $num <= 110) { $temp = $num; break; }
                }
            }
        }

        return ($temp !== null && $temp >= 10 && $temp <= 120) ? (int)$temp : null;
    }
}

