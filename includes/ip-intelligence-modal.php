<?php
/**
 * NetScope Pro - IP Intelligence Dossier Modal
 * Global component providing a 360-degree view of any IP address.
 */
?>
<!-- IP Intelligence Dossier Modal -->
<div id="ipIntelligenceModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(5, 8, 14, 0.78); backdrop-filter: blur(8px); z-index: 5500; align-items: center; justify-content: center; padding: 1rem;">
    <div class="card" style="width: 100%; max-width: 820px; max-height: 90vh; display: flex; flex-direction: column; padding: 0; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; box-shadow: 0 25px 60px rgba(0, 0, 0, 0.6); overflow: hidden; position: relative;">
        
        <!-- Modal Top Bar -->
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: rgba(255, 255, 255, 0.02);">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 36px; height: 36px; border-radius: 8px; background: var(--brand-soft); display: flex; align-items: center; justify-content: center; color: var(--primary);">
                    <i data-lucide="scan" style="width: 20px; height: 20px;"></i>
                </div>
                <div>
                    <h3 style="margin: 0; font-size: 1.125rem; font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 8px;">
                        IP Intelligence Dossier
                        <span style="font-size: 0.7rem; font-weight: 600; padding: 2px 8px; border-radius: 999px; background: var(--brand-soft); color: var(--primary); letter-spacing: 0.5px;">NETSCOPE 360°</span>
                    </h3>
                    <p style="margin: 0; font-size: 0.75rem; color: var(--text-muted);">Unified L2 physical port, L3 network context &amp; security telemetry</p>
                </div>
            </div>
            <button type="button" onclick="closeIpIntelligence()" style="background: transparent; border: none; color: var(--text-muted); cursor: pointer; padding: 6px; border-radius: 6px; display: flex; align-items: center; justify-content: center; transition: all 0.2s;" onmouseover="this.style.color='var(--text)'; this.style.background='var(--surface-light)';" onmouseout="this.style.color='var(--text-muted)'; this.style.background='transparent';">
                <i data-lucide="x" style="width: 18px; height: 18px;"></i>
            </button>
        </div>

        <!-- Hero Telemetry Strip -->
        <div style="padding: 1.25rem 1.5rem; background: var(--surface-light); border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <span id="ipIntelIp" style="font-family: 'JetBrains Mono', monospace; font-size: 1.5rem; font-weight: 700; color: var(--text); letter-spacing: -0.5px;">
                    0.0.0.0
                </span>
                <button type="button" onclick="copyIpAddress()" class="btn" style="padding: 4px 8px; font-size: 0.75rem; background: var(--surface); color: var(--text-muted); border: 1px solid var(--border);" title="Copy IP to clipboard">
                    <i data-lucide="copy" style="width: 13px; height: 13px;"></i>
                    <span id="copyFeedback" style="margin-left: 4px;">Copy</span>
                </button>
                <span id="ipIntelStateBadge" style="font-size: 0.75rem; padding: 4px 10px; border-radius: 6px; font-weight: 700; text-transform: uppercase;">
                    UNKNOWN
                </span>
                <span id="ipIntelConflictPill" style="display: none; font-size: 0.7rem; padding: 4px 10px; border-radius: 6px; font-weight: 700; background: var(--danger-soft); color: var(--danger); border: 1px solid rgba(248, 81, 73, 0.3); align-items: center; gap: 4px;">
                    <i data-lucide="alert-triangle" style="width: 13px; height: 13px;"></i> CONFLICT DETECTED
                </span>
            </div>
            
            <div style="display: flex; align-items: center; gap: 14px;">
                <div style="text-align: right;">
                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Confidence</div>
                    <div id="ipIntelConfidence" style="font-weight: 700; font-size: 0.95rem; color: var(--primary); font-family: 'JetBrains Mono', monospace;">-</div>
                </div>
                <div style="text-align: right; border-left: 1px solid var(--border); padding-left: 14px;">
                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Last Seen</div>
                    <div id="ipIntelLastSeen" style="font-weight: 600; font-size: 0.8rem; color: var(--text);">-</div>
                </div>
            </div>
        </div>

        <!-- Scrollable Body Content -->
        <div id="ipIntelContent" style="padding: 1.5rem; overflow-y: auto; flex: 1;">
            
            <!-- Loading State -->
            <div id="ipIntelLoading" style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 3rem 0; gap: 12px;">
                <div class="spinner" style="width: 28px; height: 28px; border: 3px solid var(--primary); border-top-color: transparent; border-radius: 50%; animation: spin 0.8s linear infinite;"></div>
                <span style="font-size: 0.875rem; color: var(--text-muted);">Correlating network intelligence from L2 switch tables, L3 routing &amp; ARP...</span>
            </div>

            <!-- Content Grid -->
            <div id="ipIntelBody" style="display: none; grid-template-columns: repeat(auto-fit, minmax(330px, 1fr)); gap: 1.25rem;">
                
                <!-- Card 1: L3 Network Context -->
                <div style="background: var(--surface-light); border: 1px solid var(--border); border-radius: 8px; padding: 1.25rem; display: flex; flex-direction: column; gap: 1rem;">
                    <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255, 255, 255, 0.05); padding-bottom: 0.75rem;">
                        <span style="font-size: 0.8rem; font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 8px;">
                            <i data-lucide="layers" style="width: 15px; color: var(--primary);"></i> L3 Network Context
                        </span>
                        <span id="ipIntelCidr" style="font-family: 'JetBrains Mono', monospace; font-size: 0.75rem; color: var(--primary); font-weight: 600;">-</span>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 0.75rem; font-size: 0.8125rem;">
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--text-muted);">Subnet Name:</span>
                            <span id="ipIntelSubnetName" style="font-weight: 600; color: var(--text); text-align: right;">-</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--text-muted);">VLAN:</span>
                            <span id="ipIntelVlan" style="font-weight: 600; color: var(--text); text-align: right;">-</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--text-muted);">Default Gateway:</span>
                            <span id="ipIntelGateway" style="font-family: 'JetBrains Mono', monospace; color: var(--text); text-align: right;">-</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--text-muted);">DNS Resolvers:</span>
                            <span id="ipIntelDns" style="font-family: 'JetBrains Mono', monospace; font-size: 0.75rem; color: var(--text); text-align: right;">-</span>
                        </div>
                    </div>
                </div>

                <!-- Card 2: L2 Physical Switch Port Attachment -->
                <div style="background: var(--surface-light); border: 1px solid var(--border); border-radius: 8px; padding: 1.25rem; display: flex; flex-direction: column; gap: 1rem;">
                    <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255, 255, 255, 0.05); padding-bottom: 0.75rem;">
                        <span style="font-size: 0.8rem; font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 8px;">
                            <i data-lucide="git-commit" style="width: 15px; color: var(--success);"></i> L2 Physical Attachment
                        </span>
                        <span id="ipIntelPortStatus" style="font-size: 0.65rem; font-weight: 700; padding: 2px 8px; border-radius: 4px;">-</span>
                    </div>

                    <div id="ipIntelSwitchPortData" style="display: flex; flex-direction: column; gap: 0.75rem; font-size: 0.8125rem;">
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--text-muted);">Switch:</span>
                            <span id="ipIntelSwitchName" style="font-weight: 600; color: var(--primary); text-align: right;">-</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--text-muted);">Switch Model:</span>
                            <span id="ipIntelSwitchModel" style="color: var(--text); text-align: right; font-size: 0.75rem;">-</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--text-muted);">Port Name:</span>
                            <span id="ipIntelPortName" style="font-weight: 700; color: var(--text); text-align: right; font-family: 'JetBrains Mono', monospace;">-</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--text-muted);">Port Speed &amp; STP:</span>
                            <span id="ipIntelPortSpeedStp" style="color: var(--text); text-align: right;">-</span>
                        </div>
                        <div id="ipIntelSfpRow" style="display: none; justify-content: space-between; padding-top: 4px; border-top: 1px dashed var(--border);">
                            <span style="color: var(--text-muted);">SFP Optics:</span>
                            <span id="ipIntelSfpData" style="color: var(--warning); text-align: right; font-size: 0.75rem;">-</span>
                        </div>
                    </div>

                    <div id="ipIntelSwitchPortEmpty" style="display: none; padding: 1.5rem 0.5rem; text-align: center; color: var(--text-muted); font-size: 0.8rem;">
                        <i data-lucide="help-circle" style="width: 24px; height: 24px; opacity: 0.4; margin-bottom: 6px;"></i>
                        <div>No active L2 switch port entry recorded in FDB bridge table.</div>
                    </div>
                </div>

                <!-- Card 3: Device Identity & Telemetry -->
                <div style="background: var(--surface-light); border: 1px solid var(--border); border-radius: 8px; padding: 1.25rem; display: flex; flex-direction: column; gap: 1rem;">
                    <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255, 255, 255, 0.05); padding-bottom: 0.75rem;">
                        <span style="font-size: 0.8rem; font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 8px;">
                            <i data-lucide="cpu" style="width: 15px; color: var(--warning);"></i> Device Identity &amp; Telemetry
                        </span>
                        <span id="ipIntelVendor" style="font-size: 0.75rem; color: var(--primary); font-weight: 600;">-</span>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 0.75rem; font-size: 0.8125rem;">
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--text-muted);">Hostname:</span>
                            <span id="ipIntelHostname" style="font-weight: 600; color: var(--text); text-align: right;">-</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--text-muted);">MAC Address:</span>
                            <span id="ipIntelMac" style="font-family: 'JetBrains Mono', monospace; color: var(--text); text-align: right;">-</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--text-muted);">Asset Tag / Owner:</span>
                            <span id="ipIntelAssetOwner" style="color: var(--text); text-align: right;">-</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--text-muted);">OS / Fingerprint:</span>
                            <span id="ipIntelOs" style="color: var(--text); text-align: right;">-</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="color: var(--text-muted);">Data Sources:</span>
                            <div id="ipIntelSources" style="display: flex; gap: 4px; flex-wrap: wrap; justify-content: flex-end;">-</div>
                        </div>
                    </div>
                </div>

                <!-- Card 4: Security & Cross-Module Intelligence -->
                <div style="background: var(--surface-light); border: 1px solid var(--border); border-radius: 8px; padding: 1.25rem; display: flex; flex-direction: column; gap: 1rem;">
                    <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255, 255, 255, 0.05); padding-bottom: 0.75rem;">
                        <span style="font-size: 0.8rem; font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 8px;">
                            <i data-lucide="shield-check" style="width: 15px; color: var(--primary);"></i> Security &amp; Cross-Module
                        </span>
                        <span id="ipIntelSecurityState" style="font-size: 0.7rem; font-weight: 700; color: var(--success);">CLEAN</span>
                    </div>

                    <!-- Conflict Box -->
                    <div id="ipIntelConflictBox" style="display: none; padding: 0.75rem; background: var(--danger-soft); border: 1px solid rgba(248, 81, 73, 0.3); border-radius: 6px; flex-direction: column; gap: 6px;">
                        <div style="font-size: 0.75rem; font-weight: 700; color: var(--danger); display: flex; align-items: center; gap: 6px;">
                            <i data-lucide="alert-octagon" style="width: 14px;"></i> IP Conflict Detected
                        </div>
                        <div id="ipIntelConflictText" style="font-size: 0.7rem; color: var(--text); line-height: 1.4;">-</div>
                        <button type="button" id="btnResolveConflict" onclick="resolveCurrentConflict()" class="btn" style="margin-top: 6px; padding: 4px 8px; font-size: 0.7rem; background: var(--danger); color: white; border: none; align-self: flex-start; border-radius: 4px;">
                            <i data-lucide="check-circle" style="width: 12px; height: 12px;"></i> Mark as Resolved
                        </button>
                    </div>

                    <!-- Clean status notice -->
                    <div id="ipIntelCleanNotice" style="display: flex; align-items: center; gap: 8px; font-size: 0.75rem; color: var(--text-muted); background: rgba(255, 255, 255, 0.02); padding: 8px 12px; border-radius: 6px;">
                        <i data-lucide="shield" style="width: 14px; color: var(--success);"></i>
                        <span>Single device ownership verified. No ARP/MAC collision recorded.</span>
                    </div>

                    <!-- Cross-module links -->
                    <div style="display: flex; flex-direction: column; gap: 6px; font-size: 0.8rem; padding-top: 4px; border-top: 1px solid rgba(255,255,255,0.05);">
                        <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Cross-Module References</div>
                        <div id="ipIntelCrossModules" style="display: flex; gap: 6px; flex-wrap: wrap;">
                            <span style="font-size: 0.75rem; color: var(--text-muted); opacity: 0.5;">No external module associations</span>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Mini Audit Trail Section -->
            <div id="ipIntelAuditSection" style="display: none; margin-top: 1.25rem; background: var(--surface-light); border: 1px solid var(--border); border-radius: 8px; padding: 1rem;">
                <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 6px;">
                    <i data-lucide="history" style="width: 13px;"></i> Recent Audit Trail for this Host
                </div>
                <div id="ipIntelAuditList" style="display: flex; flex-direction: column; gap: 6px; font-size: 0.75rem;">
                    <!-- Populated dynamically -->
                </div>
            </div>

        </div>

        <!-- Modal Action Footer -->
        <div style="padding: 1rem 1.5rem; border-top: 1px solid var(--border); background: var(--surface); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <a id="btnIntelConflictProber" href="#" class="btn btn-secondary" style="font-size: 0.8125rem; padding: 6px 12px;">
                    <i data-lucide="shield-alert" style="width: 14px; height: 14px;"></i> Conflict Prober
                </a>
                <a id="btnIntelSubnetLink" href="#" class="btn btn-secondary" style="font-size: 0.8125rem; padding: 6px 12px;">
                    <i data-lucide="external-link" style="width: 14px; height: 14px;"></i> View Subnet
                </a>
            </div>
            <button type="button" onclick="closeIpIntelligence()" class="btn btn-primary" style="padding: 6px 16px; font-size: 0.8125rem;">
                Close
            </button>
        </div>

    </div>
</div>

<script>
let currentIntelIp = null;
let currentIntelData = null;

async function openIpIntelligence(ip, id = null) {
    if (!ip && !id) return;
    currentIntelIp = ip;
    
    const modal = document.getElementById('ipIntelligenceModal');
    if (!modal) return;
    
    // Reset view
    modal.style.display = 'flex';
    document.getElementById('ipIntelIp').innerText = ip || 'Loading...';
    document.getElementById('ipIntelLoading').style.display = 'flex';
    document.getElementById('ipIntelBody').style.display = 'none';
    document.getElementById('ipIntelAuditSection').style.display = 'none';
    document.getElementById('copyFeedback').innerText = 'Copy';
    
    try {
        const url = ip ? `api/ip-intelligence.php?ip=${encodeURIComponent(ip)}` : `api/ip-intelligence.php?id=${id}`;
        const res = await fetch(url);
        const json = await res.json();
        
        if (!json.success || !json.data) {
            alert(json.error || 'Failed to fetch IP intelligence data.');
            closeIpIntelligence();
            return;
        }
        
        currentIntelData = json.data;
        populateIpIntelligence(json.data);
    } catch (e) {
        console.error('Error fetching IP intelligence:', e);
        alert('Network error while communicating with intelligence engine.');
        closeIpIntelligence();
    }
}

function closeIpIntelligence() {
    const modal = document.getElementById('ipIntelligenceModal');
    if (modal) modal.style.display = 'none';
}

function copyIpAddress() {
    if (!currentIntelIp) return;
    navigator.clipboard.writeText(currentIntelIp).then(() => {
        const fb = document.getElementById('copyFeedback');
        if (fb) {
            fb.innerText = 'Copied!';
            setTimeout(() => fb.innerText = 'Copy', 2000);
        }
    });
}

function populateIpIntelligence(d) {
    document.getElementById('ipIntelLoading').style.display = 'none';
    document.getElementById('ipIntelBody').style.display = 'grid';
    
    currentIntelIp = d.ip;
    document.getElementById('ipIntelIp').innerText = d.ip;
    
    // State badge
    const stateBadge = document.getElementById('ipIntelStateBadge');
    stateBadge.innerText = (d.status || 'FREE').toUpperCase();
    switch (d.status) {
        case 'active':
            stateBadge.style.background = 'var(--success-soft)';
            stateBadge.style.color = 'var(--success)';
            break;
        case 'reserved':
            stateBadge.style.background = 'var(--warning-soft)';
            stateBadge.style.color = 'var(--warning)';
            break;
        case 'dhcp':
            stateBadge.style.background = 'var(--brand-soft)';
            stateBadge.style.color = 'var(--primary)';
            break;
        case 'offline':
            stateBadge.style.background = 'var(--danger-soft)';
            stateBadge.style.color = 'var(--danger)';
            break;
        default:
            stateBadge.style.background = 'rgba(148, 163, 184, 0.15)';
            stateBadge.style.color = 'var(--text-muted)';
            break;
    }
    
    // Conflict Pill
    const conflictPill = document.getElementById('ipIntelConflictPill');
    const conflictBox = document.getElementById('ipIntelConflictBox');
    const cleanNotice = document.getElementById('ipIntelCleanNotice');
    const secState = document.getElementById('ipIntelSecurityState');
    
    if (d.conflict && d.conflict.detected) {
        conflictPill.style.display = 'inline-flex';
        conflictBox.style.display = 'flex';
        cleanNotice.style.display = 'none';
        secState.innerText = 'CONFLICT';
        secState.style.color = 'var(--danger)';
        
        let cText = d.conflict.details || 'Discrepancy detected between multiple MAC addresses or device fingerprints.';
        if (d.conflict.mac) {
            cText += ` (Conflicting MAC: ${d.conflict.mac})`;
        }
        document.getElementById('ipIntelConflictText').innerText = cText;
    } else {
        conflictPill.style.display = 'none';
        conflictBox.style.display = 'none';
        cleanNotice.style.display = 'flex';
        secState.innerText = 'CLEAN';
        secState.style.color = 'var(--success)';
    }
    
    // Confidence & Last Seen
    document.getElementById('ipIntelConfidence').innerText = d.confidence_score > 0 ? `${d.confidence_score}%` : 'N/A';
    document.getElementById('ipIntelLastSeen').innerText = d.last_seen || 'Never';
    
    // L3 Subnet Card
    if (d.subnet) {
        document.getElementById('ipIntelCidr').innerText = d.subnet.cidr || '-';
        document.getElementById('ipIntelSubnetName').innerText = d.subnet.description || 'Subnet #' + d.subnet.id;
        document.getElementById('ipIntelVlan').innerText = d.subnet.vlan_number ? `VLAN ${d.subnet.vlan_number} (${d.subnet.vlan_name || 'Unnamed'})` : 'No VLAN';
        document.getElementById('ipIntelGateway').innerText = d.subnet.gateway_ip || 'None configured';
        document.getElementById('ipIntelDns').innerText = d.subnet.dns_servers || 'Default';
        document.getElementById('btnIntelSubnetLink').href = `subnet-details?id=${d.subnet.id}`;
        document.getElementById('btnIntelSubnetLink').style.display = 'inline-flex';
    } else {
        document.getElementById('ipIntelCidr').innerText = 'Unallocated';
        document.getElementById('ipIntelSubnetName').innerText = 'No matching subnet';
        document.getElementById('ipIntelVlan').innerText = '-';
        document.getElementById('ipIntelGateway').innerText = '-';
        document.getElementById('ipIntelDns').innerText = '-';
        document.getElementById('btnIntelSubnetLink').style.display = 'none';
    }
    
    // L2 Switch Port Attachment Card
    const sp = d.switch_port;
    const spData = document.getElementById('ipIntelSwitchPortData');
    const spEmpty = document.getElementById('ipIntelSwitchPortEmpty');
    const portStatusBadge = document.getElementById('ipIntelPortStatus');
    const sfpRow = document.getElementById('ipIntelSfpRow');
    
    if (sp && sp.found) {
        spData.style.display = 'flex';
        spEmpty.style.display = 'none';
        
        document.getElementById('ipIntelSwitchName').innerHTML = `<a href="switch-details?id=${sp.switch_id}" style="color: var(--primary); text-decoration: none; border-bottom: 1px dashed var(--primary);">${sp.switch_name} (${sp.switch_ip})</a>`;
        document.getElementById('ipIntelSwitchModel').innerText = sp.switch_model;
        document.getElementById('ipIntelPortName').innerText = sp.port_name;
        
        let speedStp = sp.port_speed || 'Auto';
        if (sp.stp_state) speedStp += ` • STP: ${sp.stp_state.toUpperCase()}`;
        document.getElementById('ipIntelPortSpeedStp').innerText = speedStp;
        
        portStatusBadge.innerText = (sp.port_status || 'UP').toUpperCase();
        portStatusBadge.style.background = sp.port_status === 'up' ? 'var(--success-soft)' : 'var(--danger-soft)';
        portStatusBadge.style.color = sp.port_status === 'up' ? 'var(--success)' : 'var(--danger)';
        
        if (sp.sfp_vendor || sp.sfp_rx_power) {
            sfpRow.style.display = 'flex';
            document.getElementById('ipIntelSfpData').innerText = `${sp.sfp_vendor || 'Optic'} (Rx: ${sp.sfp_rx_power || 'N/A'} dBm)`;
        } else {
            sfpRow.style.display = 'none';
        }
    } else {
        spData.style.display = 'none';
        spEmpty.style.display = 'block';
        portStatusBadge.innerText = 'UNMAPPED';
        portStatusBadge.style.background = 'rgba(148, 163, 184, 0.15)';
        portStatusBadge.style.color = 'var(--text-muted)';
    }
    
    // Device Identity Card
    document.getElementById('ipIntelVendor').innerText = d.vendor || 'Unknown Vendor';
    document.getElementById('ipIntelHostname').innerText = d.hostname || '-';
    document.getElementById('ipIntelMac').innerText = d.mac_addr || 'No MAC recorded';
    
    let assetOwner = [];
    if (d.asset_tag) assetOwner.push(`Tag: ${d.asset_tag}`);
    if (d.owner) assetOwner.push(`Owner: ${d.owner}`);
    document.getElementById('ipIntelAssetOwner').innerText = assetOwner.length ? assetOwner.join(' • ') : '-';
    
    document.getElementById('ipIntelOs').innerText = d.os || 'Unknown';
    
    // Sources pills
    const sourcesContainer = document.getElementById('ipIntelSources');
    sourcesContainer.innerHTML = '';
    if (d.data_sources && d.data_sources.length > 0) {
        d.data_sources.forEach(src => {
            const span = document.createElement('span');
            span.style.cssText = 'font-size: 0.6rem; padding: 1px 6px; border-radius: 999px; border: 1px solid rgba(148, 163, 184, 0.3); color: var(--text-muted); font-weight: 600;';
            span.innerText = src;
            sourcesContainer.appendChild(span);
        });
    } else {
        sourcesContainer.innerHTML = '<span style="color: var(--text-muted); font-size: 0.75rem;">None</span>';
    }
    
    // Cross-Module References
    const crossModulesContainer = document.getElementById('ipIntelCrossModules');
    crossModulesContainer.innerHTML = '';
    let hasCross = false;
    
    if (d.related_nodes) {
        if (d.related_nodes.is_switch) {
            hasCross = true;
            crossModulesContainer.innerHTML += `
                <a href="switch-details?id=${d.related_nodes.switch_id}" class="btn" style="font-size: 0.7rem; padding: 2px 8px; background: var(--brand-soft); color: var(--primary); text-decoration: none; border-radius: 4px;">
                    <i data-lucide="vibrate" style="width: 11px; height: 11px;"></i> Switch: ${d.related_nodes.switch_name}
                </a>
            `;
        }
        if (d.related_nodes.is_server_asset) {
            hasCross = true;
            crossModulesContainer.innerHTML += `
                <a href="server-assets" class="btn" style="font-size: 0.7rem; padding: 2px 8px; background: var(--brand-soft); color: var(--primary); text-decoration: none; border-radius: 4px;">
                    <i data-lucide="server" style="width: 11px; height: 11px;"></i> Asset: ${d.related_nodes.asset_hostname || 'Server'}
                </a>
            `;
        }
        if (d.related_nodes.is_netwatch) {
            hasCross = true;
            crossModulesContainer.innerHTML += `
                <a href="netwatch" class="btn" style="font-size: 0.7rem; padding: 2px 8px; background: var(--success-soft); color: var(--success); text-decoration: none; border-radius: 4px;">
                    <i data-lucide="activity" style="width: 11px; height: 11px;"></i> Netwatch Monitored
                </a>
            `;
        }
    }
    if (!hasCross) {
        crossModulesContainer.innerHTML = '<span style="font-size: 0.75rem; color: var(--text-muted); opacity: 0.5;">No external module associations</span>';
    }
    
    // Audit Trail Section
    const auditSection = document.getElementById('ipIntelAuditSection');
    const auditList = document.getElementById('ipIntelAuditList');
    if (d.audit_trail && d.audit_trail.length > 0) {
        auditSection.style.display = 'block';
        auditList.innerHTML = '';
        d.audit_trail.forEach(item => {
            const row = document.createElement('div');
            row.style.cssText = 'display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255,255,255,0.03); padding-bottom: 4px;';
            row.innerHTML = `
                <span style="color: var(--text);">${escapeHtml(item.details)}</span>
                <span style="color: var(--text-muted); font-size: 0.7rem; white-space: nowrap; margin-left: 8px;">${item.created_at}</span>
            `;
            auditList.appendChild(row);
        });
    } else {
        auditSection.style.display = 'none';
    }
    
    // Conflict Prober Link
    document.getElementById('btnIntelConflictProber').href = `tools?action=conflict&target=${encodeURIComponent(d.ip)}`;
    
    if (window.lucide) {
        lucide.createIcons();
    }
}

async function resolveCurrentConflict() {
    if (!currentIntelIp) return;
    if (!confirm(`Mark IP conflict on ${currentIntelIp} as resolved?`)) return;
    
    const btn = document.getElementById('btnResolveConflict');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerText = 'Resolving...';
    
    try {
        const formData = new FormData();
        formData.append('action', 'resolve_conflict');
        formData.append('ip', currentIntelIp);
        
        const res = await fetch('api/ip-intelligence.php', {
            method: 'POST',
            body: formData
        });
        const result = await res.json();
        
        if (result.success) {
            document.getElementById('ipIntelConflictPill').style.display = 'none';
            document.getElementById('ipIntelConflictBox').style.display = 'none';
            document.getElementById('ipIntelCleanNotice').style.display = 'flex';
            document.getElementById('ipIntelSecurityState').innerText = 'RESOLVED';
            document.getElementById('ipIntelSecurityState').style.color = 'var(--success)';
        } else {
            alert(result.error || 'Failed to resolve conflict.');
        }
    } catch (e) {
        alert('Connection error while resolving conflict.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

// Close on outside click
document.getElementById('ipIntelligenceModal').addEventListener('click', function(e) {
    if (e.target === this) closeIpIntelligence();
});

// Close on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('ipIntelligenceModal');
        if (modal && modal.style.display !== 'none') {
            closeIpIntelligence();
        }
    }
});
</script>
