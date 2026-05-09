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
     * Detect vendor and poll CPU/RAM via SNMP.
     * Returns ['model' => string, 'cpu' => int, 'mem' => int]
     */
    public static function detect($ip, $community, $sys_descr) {
        $info = strtolower($sys_descr);

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

        foreach ($vendors as $model => $v) {
            foreach ($v['match'] as $keyword) {
                if (strpos($info, $keyword) !== false) {
                    $result = self::{$v['handler']}($ip, $community);
                    $result['model'] = $model;
                    return $result;
                }
            }
        }

        // Ultimate fallback
        $result = self::pollGenericHR($ip, $community);
        $result['model'] = 'Generic';
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
}
