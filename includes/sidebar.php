<aside class="sidebar">
    <div class="sidebar-logo mb-4" style="display: flex; align-items: center; gap: 10px;">
        <div style="background: var(--primary); padding: 8px; border-radius: 8px;">
            <i data-lucide="network" style="color: white;"></i>
        </div>
        <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--primary);">IPManager <span style="color: white;">Pro</span></h2>
    </div>
    
    <nav class="sidebar-nav">
        <p style="text-transform: uppercase; font-size: 0.75rem; color: var(--text-muted); margin-bottom: 1rem; letter-spacing: 1px;">Menu</p>
        <ul style="display: flex; flex-direction: column; gap: 0.5rem;">
            <li>
                <a href="index" class="btn" style="width: 100%; justify-content: flex-start; background: <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'var(--surface-light)' : 'transparent'; ?>">
                    <i data-lucide="layout-dashboard"></i> Dashboard
                </a>
            </li>
            <li>
                <a href="tools" class="btn" style="width: 100%; justify-content: flex-start; background: <?php echo basename($_SERVER['PHP_SELF']) == 'tools.php' ? 'var(--surface-light)' : 'transparent'; ?>">
                    <i data-lucide="wrench"></i> Network Toolbox
                </a>
            </li>
            <li>
                <a href="netwatch" class="btn" style="width: 100%; justify-content: flex-start; background: <?php echo basename($_SERVER['PHP_SELF']) == 'netwatch.php' ? 'var(--surface-light)' : 'transparent'; ?>">
                    <i data-lucide="eye"></i> Netwatch
                </a>
            </li>
            <li>
                <a href="switches" class="btn" style="width: 100%; justify-content: flex-start; background: <?php echo basename($_SERVER['PHP_SELF']) == 'switches.php' ? 'var(--surface-light)' : 'transparent'; ?>">
                    <i data-lucide="server"></i> Managed Switches
                </a>
            </li>
            <?php if (is_admin()): ?>
            <li>
                <a href="server-assets" class="btn" style="width: 100%; justify-content: flex-start; background: <?php echo basename($_SERVER['PHP_SELF']) == 'server-assets.php' ? 'var(--surface-light)' : 'transparent'; ?>">
                    <i data-lucide="database"></i> Server Assets
                </a>
            </li>
            <?php endif; ?>
            <li>
                <a href="subnets" class="btn" style="width: 100%; justify-content: flex-start; background: <?php echo basename($_SERVER['PHP_SELF']) == 'subnets.php' ? 'var(--surface-light)' : 'transparent'; ?>">
                    <i data-lucide="layers"></i> Subnets
                </a>
            </li>
            <li>
                <a href="vlans" class="btn" style="width: 100%; justify-content: flex-start; background: <?php echo basename($_SERVER['PHP_SELF']) == 'vlans.php' ? 'var(--surface-light)' : 'transparent'; ?>">
                    <i data-lucide="vibrate"></i> VLANs
                </a>
            </li>
            <li>
                <a href="devices" class="btn" style="width: 100%; justify-content: flex-start;">
                    <i data-lucide="monitor"></i> Devices
                </a>
            </li>
            <li>
                <a href="topology" class="btn" style="width: 100%; justify-content: flex-start; background: <?php echo basename($_SERVER['PHP_SELF']) == 'topology.php' ? 'var(--surface-light)' : 'transparent'; ?>">
                    <i data-lucide="map"></i> Network Map
                </a>
            </li>
            <li>
                <a href="topology-manager" class="btn" style="width: 100%; justify-content: flex-start; background: <?php echo basename($_SERVER['PHP_SELF']) == 'topology-manager.php' ? 'var(--surface-light)' : 'transparent'; ?>">
                    <i data-lucide="settings-2"></i> Manage Links
                </a>
            </li>
        </ul>

        <?php if (is_admin()): ?>
        <p style="text-transform: uppercase; font-size: 0.75rem; color: var(--text-muted); margin: 2rem 0 1rem; letter-spacing: 1px;">Admin</p>
        <ul style="display: flex; flex-direction: column; gap: 0.5rem;">
            <li>
                <a href="users" class="btn" style="width: 100%; justify-content: flex-start; background: <?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'var(--surface-light)' : 'transparent'; ?>">
                    <i data-lucide="users"></i> User Management
                </a>
            </li>
            <li>
                <a href="logs" class="btn" style="width: 100%; justify-content: flex-start; background: <?php echo basename($_SERVER['PHP_SELF']) == 'logs.php' ? 'var(--surface-light)' : 'transparent'; ?>">
                    <i data-lucide="scroll"></i> Audit Logs
                </a>
            </li>
            <li>
                <a href="settings" class="btn" style="width: 100%; justify-content: flex-start; background: <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'var(--surface-light)' : 'transparent'; ?>">
                    <i data-lucide="settings"></i> 
                    System Settings
                    <?php if (Updater::isUpdateAvailable()): ?>
                        <span style="margin-left: auto; width: 8px; height: 8px; background: var(--primary); border-radius: 50%; box-shadow: 0 0 8px var(--primary);"></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="#" class="btn" onclick="openBugReportModal(event)" style="width: 100%; justify-content: flex-start; color: var(--warning);">
                    <i data-lucide="bug"></i> 
                    Report Issue
                </a>
            </li>
        <?php endif; ?>
        <ul style="display: flex; flex-direction: column; gap: 0.5rem; <?php echo !is_admin() ? 'margin-top: 2rem;' : ''; ?>">
            <li>
                <a href="change-password" class="btn" style="width: 100%; justify-content: flex-start; background: <?php echo basename($_SERVER['PHP_SELF']) == 'change-password.php' ? 'var(--surface-light)' : 'transparent'; ?>">
                    <i data-lucide="user-cog"></i> Account Settings
                </a>
            </li>
            <li>
                <a href="about" class="btn" style="width: 100%; justify-content: flex-start; background: <?php echo basename($_SERVER['PHP_SELF']) == 'about.php' ? 'var(--surface-light)' : 'transparent'; ?>">
                    <i data-lucide="info"></i> About
                    <?php if (Updater::isUpdateAvailable()): ?>
                        <span style="margin-left: auto; background: var(--primary); color: white; font-size: 0.6rem; padding: 2px 6px; border-radius: 10px; font-weight: 700;">NEW</span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="logout" class="btn" style="width: 100%; justify-content: flex-start; color: var(--danger);">
                    <i data-lucide="log-out"></i> Logout
                </a>
            </li>
        </ul>
    </nav>
</aside>
