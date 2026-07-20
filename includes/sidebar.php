<aside class="sidebar">
    <div class="sidebar-logo" style="display: flex; align-items: center; gap: 8px;">
        <i data-lucide="network" style="color: var(--primary); width: 20px;"></i>
        <h2 style="font-size: 1.05rem; font-weight: 700; color: var(--text); letter-spacing: -0.01em;">NetScope <span style="color: var(--text-muted); font-weight: 400;">Pro</span></h2>
    </div>

    <nav class="sidebar-nav">
        <style>
            .sidebar .btn { transition: all 0.3s ease; }
            .sidebar .btn:hover { border-left-color: var(--text-muted) !important; padding-left: 1.25rem; }
            .sidebar .btn.active:hover { border-left-color: var(--primary) !important; }
        </style>
        <?php
        $current = basename($_SERVER['PHP_SELF']);
        
        // Query for Netwatch Down Devices
        $netwatch_down_count = 0;
        try {
            if (isset($pdo)) {
                $stmt = $pdo->query("SELECT COUNT(*) FROM netwatch WHERE status = 'down'");
                $netwatch_down_count = $stmt->fetchColumn();
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
                <a href="<?php echo $item[0]; ?>" class="btn <?php echo $is_active ? 'active' : ''; ?>" style="width: 100%; justify-content: flex-start; background: <?php echo $is_active ? 'var(--surface-light)' : 'transparent'; ?>; border-left: 2px solid <?php echo $is_active ? 'var(--primary)' : 'transparent'; ?>; color: <?php echo $is_active ? 'var(--text)' : 'var(--text-muted)'; ?>; font-weight: <?php echo $is_active ? '500' : '400'; ?>;">
                    <i data-lucide="<?php echo $item[1]; ?>" style="width: 15px;"></i> <?php echo $item[2]; ?>
                    <?php if ($item[0] === 'netwatch' && $netwatch_down_count > 0): ?>
                        <span style="margin-left: auto; background: var(--danger); color: white; font-size: 0.65rem; padding: 2px 6px; border-radius: 10px; font-weight: 700;"><?php echo $netwatch_down_count; ?></span>
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
                <a href="<?php echo $item[0]; ?>" class="btn <?php echo $is_active ? 'active' : ''; ?>" style="width: 100%; justify-content: flex-start; background: <?php echo $is_active ? 'var(--surface-light)' : 'transparent'; ?>; border-left: 2px solid <?php echo $is_active ? 'var(--primary)' : 'transparent'; ?>; color: <?php echo $is_active ? 'var(--text)' : 'var(--text-muted)'; ?>; font-weight: <?php echo $is_active ? '500' : '400'; ?>;">
                    <i data-lucide="<?php echo $item[1]; ?>" style="width: 15px;"></i> <?php echo $item[2]; ?>
                    <?php if ($item[0] === 'settings' && Updater::isUpdateAvailable()): ?>
                        <span style="margin-left: auto; width: 6px; height: 6px; background: var(--primary); border-radius: 50%;"></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endforeach; ?>
            <li>
                <a href="#" class="btn" onclick="openBugReportModal(event)" style="width: 100%; justify-content: flex-start; color: var(--text-muted); border-left: 2px solid transparent;">
                    <i data-lucide="bug" style="width: 15px;"></i> Report Issue
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
                <a href="<?php echo $item[0]; ?>" class="btn <?php echo $is_active ? 'active' : ''; ?>" style="width: 100%; justify-content: flex-start; background: <?php echo $is_active ? 'var(--surface-light)' : 'transparent'; ?>; border-left: 2px solid <?php echo $is_active ? 'var(--primary)' : 'transparent'; ?>; color: <?php echo $is_active ? 'var(--text)' : 'var(--text-muted)'; ?>; font-weight: <?php echo $is_active ? '500' : '400'; ?>;">
                    <i data-lucide="<?php echo $item[1]; ?>" style="width: 15px;"></i> <?php echo $item[2]; ?>
                    <?php if ($item[0] === 'about' && Updater::isUpdateAvailable()): ?>
                        <span style="margin-left: auto; background: var(--primary); color: var(--background); font-size: 0.6rem; padding: 1px 6px; border-radius: var(--radius-sm); font-weight: 700;">NEW</span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endforeach; ?>
            <li>
                <a href="logout" class="btn" style="width: 100%; justify-content: flex-start; color: var(--danger); border-left: 2px solid transparent;">
                    <i data-lucide="log-out" style="width: 15px;"></i> Logout
                </a>
            </li>
        </ul>
    </nav>
</aside>
