<aside class="sidebar">
    <div class="sidebar-header" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; min-height: 28px;">
        <a href="index" class="sidebar-brand" style="display: flex; align-items: center; gap: 8px; text-decoration: none;" title="NetScope Pro">
            <i data-lucide="network" class="brand-icon" style="color: var(--primary); width: 20px; flex-shrink: 0;"></i>
            <h2 class="logo-text" style="font-size: 1.05rem; font-weight: 700; color: var(--text); letter-spacing: -0.01em; margin: 0; white-space: nowrap;">NetScope <span style="color: var(--text-muted); font-weight: 400;">Pro</span></h2>
        </a>
        <button id="sidebar-collapse-btn" class="sidebar-collapse-btn" type="button" title="Minimize / Expand Sidebar" aria-label="Toggle Sidebar">
            <i data-lucide="panel-left-close" style="width: 16px; height: 16px;"></i>
        </button>
    </div>

    <nav class="sidebar-nav">
        <?php
        $current = basename($_SERVER['PHP_SELF']);
        
        // Query for Netwatch Down Devices & Conflict Count
        $netwatch_down_count = 0;
        $conflict_count_badge = 0;
        $loop_count_badge = 0;
        try {
            $db_conn = isset($db) ? $db : (isset($pdo) ? $pdo : (function_exists('get_db_connection') ? get_db_connection() : null));
            if ($db_conn) {
                $netwatch_down_count = (int)$db_conn->query("SELECT COUNT(*) FROM netwatch WHERE status = 'down'")->fetchColumn();
                $conflict_count_badge = (int)$db_conn->query("SELECT COUNT(*) FROM ip_addresses WHERE conflict_detected = 1")->fetchColumn();
                $loop_count_badge = (int)$db_conn->query("SELECT COUNT(DISTINCT switch_id) FROM switch_port_map WHERE stp_state = 'blocking' AND LOWER(port_status) = 'up'")->fetchColumn();
            }
        } catch (Exception $e) {}

        $menu_groups = [
            'Menu' => [
                ['index', 'layout-dashboard', 'Dashboard']
            ],
            'Infrastructure' => [
                ['subnets', 'layers', 'Subnets'],
                ['vlans', 'vibrate', 'VLANs'],
                ['devices', 'monitor', 'Devices'],
                ['switches', 'server', 'Managed Switches'],
                ['server-assets', 'database', 'Server Assets'],
            ],
            'Monitoring & Tools' => [
                ['netwatch', 'eye', 'Netwatch'],
                ['conflicts', 'shield-alert', 'Conflict Center'],
                ['loop-detective', 'search-check', 'Loop Detective'],
                ['topology', 'map', 'Network Map'],
                ['topology-manager', 'settings-2', 'Manage Links'],
                ['tools', 'wrench', 'Network Toolbox'],
            ]
        ];
        
        foreach ($menu_groups as $group_label => $items):
        ?>
            <p class="section-label" style="margin: <?php echo $group_label === 'Menu' ? '0' : '1.5rem'; ?> 0 0.75rem;"><?php echo $group_label; ?></p>
            <ul style="display: flex; flex-direction: column; gap: 2px;">
            <?php
            foreach ($items as $item):
                if ($item[0] === 'server-assets' && !is_admin()) continue;
                $is_active = ($current == $item[0] . '.php');
            ?>
            <li>
                <a href="<?php echo $item[0]; ?>" class="btn <?php echo $is_active ? 'active' : ''; ?>" title="<?php echo htmlspecialchars($item[2]); ?>" style="width: 100%; justify-content: flex-start; background: <?php echo $is_active ? 'var(--surface-light)' : 'transparent'; ?>; border-left: 2px solid <?php echo $is_active ? 'var(--primary)' : 'transparent'; ?>; color: <?php echo $is_active ? 'var(--text)' : 'var(--text-muted)'; ?>; font-weight: <?php echo $is_active ? '500' : '400'; ?>;">
                    <i data-lucide="<?php echo $item[1]; ?>" style="width: 15px; flex-shrink: 0;"></i> 
                    <span class="nav-text"><?php echo $item[2]; ?></span>
                    <?php if ($item[0] === 'netwatch' && $netwatch_down_count > 0): ?>
                        <span class="sidebar-badge badge-danger"><?php echo $netwatch_down_count; ?></span>
                    <?php endif; ?>
                    <?php if ($item[0] === 'conflicts' && $conflict_count_badge > 0): ?>
                        <span class="sidebar-badge badge-danger"><?php echo $conflict_count_badge; ?></span>
                    <?php endif; ?>
                    <?php if ($item[0] === 'loop-detective' && $loop_count_badge > 0): ?>
                        <span class="sidebar-badge badge-warning"><?php echo $loop_count_badge; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>

        <?php if (is_admin()): ?>
        <p class="section-label" style="margin: 1.5rem 0 0.75rem;">Admin</p>
        <ul style="display: flex; flex-direction: column; gap: 2px;">
            <?php
            $admin_items = [
                ['users', 'users', 'User Management'],
                ['logs', 'scroll', 'Audit Logs'],
                ['settings', 'settings', 'System Settings'],
            ];
            foreach ($admin_items as $item):
                $is_active = ($current == $item[0] . '.php');
            ?>
            <li>
                <a href="<?php echo $item[0]; ?>" class="btn <?php echo $is_active ? 'active' : ''; ?>" title="<?php echo htmlspecialchars($item[2]); ?>" style="width: 100%; justify-content: flex-start; background: <?php echo $is_active ? 'var(--surface-light)' : 'transparent'; ?>; border-left: 2px solid <?php echo $is_active ? 'var(--primary)' : 'transparent'; ?>; color: <?php echo $is_active ? 'var(--text)' : 'var(--text-muted)'; ?>; font-weight: <?php echo $is_active ? '500' : '400'; ?>;">
                    <i data-lucide="<?php echo $item[1]; ?>" style="width: 15px; flex-shrink: 0;"></i> 
                    <span class="nav-text"><?php echo $item[2]; ?></span>
                    <?php if ($item[0] === 'settings' && Updater::isUpdateAvailable()): ?>
                        <span class="sidebar-badge badge-primary sidebar-badge-dot"></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endforeach; ?>
            <li>
                <a href="#" class="btn" onclick="openBugReportModal(event)" title="Report Issue" style="width: 100%; justify-content: flex-start; color: var(--text-muted); border-left: 2px solid transparent;">
                    <i data-lucide="bug" style="width: 15px; flex-shrink: 0;"></i> 
                    <span class="nav-text">Report Issue</span>
                </a>
            </li>
        </ul>
        <?php endif; ?>

        <p class="section-label" style="margin: 1.5rem 0 0.75rem;">Account</p>
        <ul style="display: flex; flex-direction: column; gap: 2px;">
            <?php
            $account_items = [
                ['change-password', 'user-cog', 'Account Settings'],
                ['about', 'info', 'About'],
            ];
            foreach ($account_items as $item):
                $is_active = ($current == $item[0] . '.php');
            ?>
            <li>
                <a href="<?php echo $item[0]; ?>" class="btn <?php echo $is_active ? 'active' : ''; ?>" title="<?php echo htmlspecialchars($item[2]); ?>" style="width: 100%; justify-content: flex-start; background: <?php echo $is_active ? 'var(--surface-light)' : 'transparent'; ?>; border-left: 2px solid <?php echo $is_active ? 'var(--primary)' : 'transparent'; ?>; color: <?php echo $is_active ? 'var(--text)' : 'var(--text-muted)'; ?>; font-weight: <?php echo $is_active ? '500' : '400'; ?>;">
                    <i data-lucide="<?php echo $item[1]; ?>" style="width: 15px; flex-shrink: 0;"></i> 
                    <span class="nav-text"><?php echo $item[2]; ?></span>
                    <?php if ($item[0] === 'about' && Updater::isUpdateAvailable()): ?>
                        <span class="sidebar-badge badge-primary" style="font-size: 0.6rem;">NEW</span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endforeach; ?>
            <li>
                <a href="logout" class="btn" title="Logout" style="width: 100%; justify-content: flex-start; color: var(--danger); border-left: 2px solid transparent;">
                    <i data-lucide="log-out" style="width: 15px; flex-shrink: 0;"></i> 
                    <span class="nav-text">Logout</span>
                </a>
            </li>
        </ul>
    </nav>
</aside>
