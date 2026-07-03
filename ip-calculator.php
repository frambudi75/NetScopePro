<?php
require_once 'includes/config.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}

function to_unsigned_long($ip) {
    $long = ip2long($ip);
    if ($long === false) {
        return false;
    }
    return sprintf('%u', $long);
}

function long_to_ip($long) {
    if ($long < 0) {
        $long = $long + 4294967296;
    }
    return long2ip((int)$long);
}

function netmask_from_prefix($prefix) {
    $prefix = (int)$prefix;
    if ($prefix < 0 || $prefix > 32) {
        return null;
    }
    if ($prefix === 0) {
        return '0.0.0.0';
    }
    $mask = (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
    return long2ip($mask);
}

function wildcard_from_prefix($prefix) {
    $prefix = (int)$prefix;
    if ($prefix < 0 || $prefix > 32) {
        return null;
    }
    $wildcard = (~((0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF)) & 0xFFFFFFFF;
    return long2ip($wildcard);
}

function calculate_subnet($ip, $prefix) {
    $prefix = (int)$prefix;
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $prefix < 0 || $prefix > 32) {
        return null;
    }

    $ip_u = (int)to_unsigned_long($ip);
    $mask_u = $prefix === 0 ? 0 : ((0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF);
    $network_u = $ip_u & $mask_u;
    $broadcast_u = $network_u | (~$mask_u & 0xFFFFFFFF);

    $total_hosts = (int)pow(2, (32 - $prefix));
    if ($prefix === 31) {
        $usable_hosts = 2;
        $first_usable = long2ip($network_u);
        $last_usable = long2ip($broadcast_u);
    } elseif ($prefix === 32) {
        $usable_hosts = 1;
        $first_usable = long2ip($network_u);
        $last_usable = long2ip($network_u);
    } else {
        $usable_hosts = max(0, $total_hosts - 2);
        $first_usable = long2ip($network_u + 1);
        $last_usable = long2ip($broadcast_u - 1);
    }

    return [
        'input_ip' => $ip,
        'prefix' => $prefix,
        'netmask' => netmask_from_prefix($prefix),
        'wildcard' => wildcard_from_prefix($prefix),
        'network' => long2ip($network_u),
        'broadcast' => long2ip($broadcast_u),
        'first_usable' => $first_usable,
        'last_usable' => $last_usable,
        'total_hosts' => $total_hosts,
        'usable_hosts' => $usable_hosts
    ];
}

function split_subnets($cidr, $target_prefix) {
    $parts = explode('/', trim((string)$cidr));
    if (count($parts) !== 2) {
        return ['error' => 'Format CIDR tidak valid.'];
    }

    $base_ip = trim($parts[0]);
    $base_prefix = (int)trim($parts[1]);
    $target_prefix = (int)$target_prefix;

    if (!filter_var($base_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return ['error' => 'IP network induk tidak valid.'];
    }
    if ($base_prefix < 0 || $base_prefix > 32 || $target_prefix < 0 || $target_prefix > 32) {
        return ['error' => 'Prefix harus antara 0 sampai 32.'];
    }
    if ($target_prefix < $base_prefix) {
        return ['error' => 'Target prefix harus lebih besar atau sama dengan prefix induk.'];
    }

    $base = calculate_subnet($base_ip, $base_prefix);
    if ($base === null) {
        return ['error' => 'Gagal menghitung network induk.'];
    }

    if ($target_prefix === $base_prefix) {
        return [
            'base' => $base,
            'target_prefix' => $target_prefix,
            'subnets' => [$base]
        ];
    }

    $base_network_u = (int)sprintf('%u', ip2long($base['network']));
    $subnet_size = (int)pow(2, 32 - $target_prefix);
    $count = (int)pow(2, $target_prefix - $base_prefix);
    if ($count > 4096) {
        return ['error' => 'Hasil subnet terlalu banyak. Gunakan target prefix yang lebih kecil.'];
    }

    $result = [];
    for ($i = 0; $i < $count; $i++) {
        $subnet_network_u = $base_network_u + ($i * $subnet_size);
        $subnet_ip = long2ip($subnet_network_u);
        $detail = calculate_subnet($subnet_ip, $target_prefix);
        if ($detail !== null) {
            $result[] = $detail;
        }
    }

    return [
        'base' => $base,
        'target_prefix' => $target_prefix,
        'subnets' => $result
    ];
}

$page_title = 'IP Calculator';
$error = '';
$result = null;
$input_ip = '';
$input_prefix = 24;
$input_cidr = '';
$split_error = '';
$split_result = null;
$split_input_cidr = '';
$split_target_prefix = 26;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? 'calc';

    if ($mode === 'split') {
        $split_input_cidr = trim($_POST['split_cidr'] ?? '');
        $split_target_prefix = (int)($_POST['split_target_prefix'] ?? 26);
        $split_result = split_subnets($split_input_cidr, $split_target_prefix);
        if (isset($split_result['error'])) {
            $split_error = $split_result['error'];
            $split_result = null;
        }
    } else {
        $input_cidr = trim($_POST['cidr'] ?? '');
        $input_ip = trim($_POST['ip_addr'] ?? '');
        $input_prefix = (int)($_POST['prefix'] ?? 24);

        if ($input_cidr !== '') {
            $parts = explode('/', $input_cidr);
            if (count($parts) === 2) {
                $input_ip = trim($parts[0]);
                $input_prefix = (int)trim($parts[1]);
            } else {
                $error = 'Format CIDR tidak valid. Gunakan contoh: 192.168.1.10/24';
            }
        }

        if ($error === '') {
            $result = calculate_subnet($input_ip, $input_prefix);
            if ($result === null) {
                $error = 'Input IP atau prefix tidak valid.';
            }
        }
    }
}

include 'includes/header.php';
?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem;">
    <div class="card">
        <h3 style="font-size: 1.125rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 8px;">
            <i data-lucide="calculator" style="width: 20px;"></i> Advanced Calculator
        </h3>
        <form method="POST">
            <input type="hidden" name="mode" value="calc">
            <div class="input-group">
                <label>CIDR Input (Quick)</label>
                <input type="text" class="input-control" name="cidr" placeholder="192.168.1.10/24" value="<?php echo htmlspecialchars($input_cidr); ?>">
            </div>
            <div style="text-align: center; color: var(--text-muted); margin: 1rem 0; font-size: 0.8rem; font-weight: 600;">OR MANUAL</div>
            <div class="input-group">
                <label>IP Address</label>
                <input type="text" class="input-control" name="ip_addr" placeholder="192.168.1.10" value="<?php echo htmlspecialchars($input_ip); ?>">
            </div>
            <div class="input-group">
                <label>Prefix / Mask</label>
                <select class="input-control" name="prefix">
                    <?php for ($p = 0; $p <= 32; $p++): ?>
                        <option value="<?php echo $p; ?>" <?php echo $p === (int)$input_prefix ? 'selected' : ''; ?>>/<?php echo $p; ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <?php if ($error !== ''): ?>
                <div style="padding: 1rem; border: 1px solid var(--danger); background: rgba(239, 68, 68, 0.1); border-radius: 8px; color: var(--danger); margin-bottom: 1.5rem; font-size: 0.875rem;">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 1rem;">
                <i data-lucide="play"></i> Compute Network
            </button>
        </form>
    </div>

    <div class="card">
        <h3 style="font-size: 1.125rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 8px;">
            <i data-lucide="network" style="width: 20px;"></i> Computed Parameters
        </h3>

        <?php if (!$result): ?>
            <div style="padding: 2.5rem; border: 1px dashed var(--border); border-radius: 12px; color: var(--text-muted); text-align: center;">
                <i data-lucide="info" style="width: 32px; height: 32px; margin-bottom: 1rem; opacity: 0.5;"></i>
                <p>Enter network parameters to see detailed computations.</p>
            </div>
        <?php else: ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem;">
                <div style="padding: 1rem; background: rgba(255,255,255,0.03); border-radius: 10px; border: 1px solid var(--border);">
                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase;">CIDR Notation</div>
                    <div style="font-family: monospace; font-weight: 700; font-size: 0.9375rem;"><?php echo htmlspecialchars($result['input_ip']); ?>/<?php echo (int)$result['prefix']; ?></div>
                </div>
                <div style="padding: 1rem; background: rgba(255,255,255,0.03); border-radius: 10px; border: 1px solid var(--border);">
                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase;">Netmask</div>
                    <div style="font-family: monospace; font-weight: 700; font-size: 0.9375rem;"><?php echo htmlspecialchars($result['netmask']); ?></div>
                </div>
                <div style="padding: 1rem; background: rgba(255,255,255,0.03); border-radius: 10px; border: 1px solid var(--border);">
                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase;">Wildcard</div>
                    <div style="font-family: monospace; font-weight: 700; font-size: 0.9375rem;"><?php echo htmlspecialchars($result['wildcard']); ?></div>
                </div>
                <div style="padding: 1rem; background: rgba(255,255,255,0.03); border-radius: 10px; border: 1px solid var(--border);">
                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase;">Total Hosts</div>
                    <div style="font-weight: 800; font-size: 1.125rem;"><?php echo number_format((int)$result['total_hosts']); ?></div>
                </div>
                <div style="padding: 1rem; background: rgba(16, 185, 129, 0.05); border-radius: 10px; border: 1px solid var(--success);">
                    <div style="font-size: 0.7rem; color: var(--success); text-transform: uppercase; font-weight: 600;">Usable Hosts</div>
                    <div style="font-weight: 800; font-size: 1.125rem; color: var(--success);"><?php echo number_format((int)$result['usable_hosts']); ?></div>
                </div>
                <div style="padding: 1rem; background: rgba(255,255,255,0.03); border-radius: 10px; border: 1px solid var(--border);">
                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase;">Network</div>
                    <div style="font-family: monospace; font-weight: 700; color: var(--primary);"><?php echo htmlspecialchars($result['network']); ?></div>
                </div>
                <div style="padding: 1rem; background: rgba(255,255,255,0.03); border-radius: 10px; border: 1px solid var(--border);">
                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase;">First IP</div>
                    <div style="font-family: monospace; font-weight: 700;"><?php echo htmlspecialchars($result['first_usable']); ?></div>
                </div>
                <div style="padding: 1rem; background: rgba(255,255,255,0.03); border-radius: 10px; border: 1px solid var(--border);">
                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase;">Last IP</div>
                    <div style="font-family: monospace; font-weight: 700;"><?php echo htmlspecialchars($result['last_usable']); ?></div>
                </div>
            </div>

            <div style="padding: 1.25rem; background: rgba(239, 68, 68, 0.05); border: 1px solid var(--danger); border-radius: 10px; margin-top: 1rem; text-align: center;">
                <div style="font-size: 0.7rem; color: var(--danger); text-transform: uppercase; font-weight: 600; margin-bottom: 0.25rem;">Broadcast Address</div>
                <div style="font-family: monospace; font-weight: 800; font-size: 1.25rem; color: var(--danger);"><?php echo htmlspecialchars($result['broadcast']); ?></div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-top: 2rem;">
    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 2rem;">
        <div style="background: rgba(59, 130, 246, 0.1); padding: 8px; border-radius: 50%; color: var(--primary);">
            <i data-lucide="split" style="width: 20px;"></i>
        </div>
        <h3 style="font-size: 1.125rem;">Subnet Splitter (VLSM)</h3>
    </div>

    <form method="POST" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 2rem; border-bottom: 1px solid var(--border); padding-bottom: 2rem;">
        <input type="hidden" name="mode" value="split">
        <div class="input-group" style="margin: 0; flex: 2; min-width: 250px;">
            <label>Parent Network (CIDR)</label>
            <input type="text" class="input-control" name="split_cidr" placeholder="10.10.0.0/24" value="<?php echo htmlspecialchars($split_input_cidr); ?>" required>
        </div>
        <div class="input-group" style="margin: 0; flex: 1; min-width: 150px;">
            <label>Split into Prefix</label>
            <select class="input-control" name="split_target_prefix">
                <?php for ($p = 0; $p <= 32; $p++): ?>
                    <option value="<?php echo $p; ?>" <?php echo $p === (int)$split_target_prefix ? 'selected' : ''; ?>>/<?php echo $p; ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary" style="height: 48px; padding: 0 2.5rem; min-width: 120px;">
            <i data-lucide="wand-sparkles"></i> Generate
        </button>
    </form>

    <?php if ($split_error !== ''): ?>
        <div style="padding: 1rem; border: 1px solid var(--danger); background: rgba(239, 68, 68, 0.1); border-radius: 10px; color: var(--danger);">
            <?php echo htmlspecialchars($split_error); ?>
        </div>
    <?php elseif ($split_result): ?>
        <div style="margin-bottom: 1.5rem; color: var(--text-muted); font-size: 0.9375rem; background: var(--surface-light); padding: 1rem; border-radius: 10px;">
            Parent network <span style="font-family: monospace; font-weight: 600; color: var(--primary);"><?php echo htmlspecialchars($split_result['base']['network']); ?>/<?php echo (int)$split_result['base']['prefix']; ?></span>
            was split into <strong><?php echo count($split_result['subnets']); ?></strong> subnets of <strong>/<?php echo (int)$split_result['target_prefix']; ?></strong>.
        </div>
        <div class="table-responsive">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border); text-align: left;">
                        <th style="padding: 1rem; color: var(--text-muted); font-size: 0.8rem;">Segment</th>
                        <th style="padding: 1rem; color: var(--text-muted); font-size: 0.8rem;">Network</th>
                        <th style="padding: 1rem; color: var(--text-muted); font-size: 0.8rem;">Range (First - Last)</th>
                        <th style="padding: 1rem; color: var(--text-muted); font-size: 0.8rem;">Broadcast</th>
                        <th style="padding: 1rem; color: var(--text-muted); font-size: 0.8rem; text-align: right;">Usable</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($split_result['subnets'] as $idx => $sub): ?>
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td style="padding: 1rem; font-weight: 600; color: var(--text-muted); font-size: 0.8rem;">#<?php echo $idx + 1; ?></td>
                            <td style="padding: 1rem; font-family: monospace; font-weight: 600; color: var(--primary);"><?php echo htmlspecialchars($sub['network']); ?>/<?php echo (int)$sub['prefix']; ?></td>
                            <td style="padding: 1rem; font-family: monospace; font-size: 0.875rem;">
                                <?php echo htmlspecialchars($sub['first_usable']); ?> - <?php echo htmlspecialchars($sub['last_usable']); ?>
                            </td>
                            <td style="padding: 1rem; font-family: monospace; font-size: 0.875rem; color: var(--danger);"><?php echo htmlspecialchars($sub['broadcast']); ?></td>
                            <td style="padding: 1rem; text-align: right; font-weight: 700; color: var(--success);"><?php echo number_format((int)$sub['usable_hosts']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div style="padding: 2.5rem; border: 1px dashed var(--border); border-radius: 12px; color: var(--text-muted); text-align: center;">
            <i data-lucide="info" style="width: 32px; height: 32px; margin-bottom: 1rem; opacity: 0.5;"></i>
            <p>Example: Divide <code>10.0.0.0/24</code> into <code>/26</code> to get 4 equal segments.</p>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
