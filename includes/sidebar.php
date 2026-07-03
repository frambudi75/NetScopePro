<aside class="sidebar">
    <div class="sidebar-logo" style="display: flex; align-items: center; gap: 8px;">
        <i data-lucide="network" style="color: var(--primary); width: 20px;"></i>
        <h2 style="font-size: 1.05rem; font-weight: 700; color: var(--text); letter-spacing: -0.01em;">NetScope <span style="color: var(--text-muted); font-weight: 400;">Pro</span></h2>
    </div>

    <nav class="sidebar-nav">
        <p class="section-label" style="margin-bottom: 0.75rem;">Menu</p>
        <ul style="display: flex; flex-direction: column; gap: 2px;">
            <?php
            $nav_items = [
                ['index', 'layout-dashboard', 'Dashboard'],
                ['tools', 'wrench', 'Network Toolbox'],
                ['netwatch', 'eye', 'Netwatch'],
                ['switches', 'server', 'Managed Switches'],
                ['server-assets', 'database', 'Server Assets'],
                ['subnets', 'layers', 'Subnets'],
                ['vlans', 'vibrate', 'VLANs'],
                ['devices', 'monitor', 'Devices'],
                ['topology', 'map', 'Network Map'],
                ['topology-manager', 'settings-2', 'Manage Links'],
            ];
            $current = basename($_SERVER['PHP_SELF']);
            foreach ($nav_items as $item):
                if ($item[0] === 'server-assets' && !is_admin()) continue;
                $is_active = ($current == $item[0] . '.php');
            ?>
            <li>
                <a href="<?php echo $item[0]; ?>" class="btn" style="width: 100%; justify-content: flex-start; background: <?php echo $is_active ? 'var(--surface-light)' : 'transparent'; ?>; border-left: 2px solid <?php echo $is_active ? 'var(--primary)' : 'transparent'; ?>; color: <?php echo $is_active ? 'var(--text)' : 'var(--text-muted)'; ?>; font-weight: <?php echo $is_active ? '500' : '400'; ?>;">
                    <i data-lucide="<?php echo $item[1]; ?>" style="width: 15px;"></i> <?php echo $item[2]; ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>

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
                <a href="<?php echo $item[0]; ?>" class="btn" style="width: 100%; justify-content: flex-start; background: <?php echo $is_active ? 'var(--surface-light)' : 'transparent'; ?>; border-left: 2px solid <?php echo $is_active ? 'var(--primary)' : 'transparent'; ?>; color: <?php echo $is_active ? 'var(--text)' : 'var(--text-muted)'; ?>; font-weight: <?php echo $is_active ? '500' : '400'; ?>;">
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
        <?php endif; ?>

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
                <a href="<?php echo $item[0]; ?>" class="btn" style="width: 100%; justify-content: flex-start; background: <?php echo $is_active ? 'var(--surface-light)' : 'transparent'; ?>; border-left: 2px solid <?php echo $is_active ? 'var(--primary)' : 'transparent'; ?>; color: <?php echo $is_active ? 'var(--text)' : 'var(--text-muted)'; ?>; font-weight: <?php echo $is_active ? '500' : '400'; ?>;">
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
