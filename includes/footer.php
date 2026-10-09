            </div>
            
            <footer class="app-footer">
                <div style="display: flex; align-items: center; justify-content: center; gap: 8px;">
                    <span>&copy; <?php echo date('Y'); ?> <b><?php echo APP_NAME; ?></b> — Developed by </span>
                    <a href="https://github.com/frambudi75" target="_blank" style="display: flex; align-items: center; gap: 5px; color: var(--primary);">
                         Habib Frambudi
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 22v-4a4.8 4.8 0 0 0-1-3.5c3 0 6-2 6-5.5.08-1.25-.27-2.48-1-3.5.28-1.15.28-2.35 0-3.5 0 0-1 0-3 1.5-2.64-.5-5.36-.5-8 0C6 2 5 2 5 2c-.3 1.15-.3 2.35 0 3.5A5.403 5.403 0 0 0 4 9c0 3.5 3 5.5 6 5.5-.39.49-.68 1.05-.85 1.65-.17.6-.22 1.23-.15 1.85v4"/><path d="M9 18c-4.51 2-5-2-7-2"/></svg>
                    </a>
                </div>
            </footer>
        </main>
    </div>

    <!-- Bug Report Modal -->
    <div id="bugReportModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); z-index: 5000; align-items: center; justify-content: center; padding: 1rem;">
        <div class="card" style="width: 100%; max-width: 500px; padding: 2rem; position: relative; border: 1px solid var(--warning);">
            <button onclick="closeBugReportModal()" style="position: absolute; top: 1rem; right: 1rem; background: none; border: none; color: var(--text-muted); cursor: pointer;">
                <i data-lucide="x"></i>
            </button>
            <h2 style="margin-bottom: 0.5rem; display: flex; align-items: center; gap: 10px;">
                <i data-lucide="bug" style="color: var(--warning);"></i> Lapor Masalah
            </h2>
            <p style="color: var(--text-muted); font-size: 0.875rem; margin-bottom: 1.5rem;">Laporan anda akan dikirimkan langsung ke Discord Developer untuk ditindaklanjuti.</p>
            
            <form id="bugReportForm" onsubmit="submitBugReport(event)">
                <div class="input-group">
                    <label>Email Pelapor</label>
                    <input type="email" id="bugEmail" class="input-control" placeholder="nama@email.com" value="<?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?>" required>
                </div>
                <div class="input-group">
                    <label>Judul Masalah</label>
                    <input type="text" id="bugTitle" class="input-control" placeholder="Apa yang salah?" required>
                </div>
                <div class="input-group">
                    <label>Detail Kejadian</label>
                    <textarea id="bugDescription" class="input-control" style="height: 110px;" placeholder="Tolong jelaskan langkah-langkah sebelum terjadi error..." required></textarea>
                </div>
                <div class="input-group">
                    <label style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Bukti Screenshot <span style="color: var(--text-muted); font-size: 0.75rem;">(Opsional)</span></span>
                        <span style="font-size: 0.7rem; color: var(--text-muted);">PNG, JPG, WEBP</span>
                    </label>
                    <input type="file" id="bugScreenshot" class="input-control" accept="image/png, image/jpeg, image/webp, image/gif" style="padding: 6px;">
                    <div id="bugScreenshotPreview" style="display: none; margin-top: 6px; align-items: center; gap: 8px;">
                        <img id="bugScreenshotPreviewImg" style="width: 44px; height: 44px; object-fit: cover; border-radius: 4px; border: 1px solid var(--border-color);">
                        <span id="bugScreenshotPreviewName" style="font-size: 0.75rem; color: var(--text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 260px;"></span>
                    </div>
                </div>
                <div class="input-group" style="margin-top: -0.25rem;">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 0.75rem; cursor: pointer; color: var(--text-muted); user-select: none;">
                        <input type="checkbox" id="bugAttachAudit" checked style="accent-color: var(--primary); width: 15px; height: 15px; cursor: pointer;">
                        <span>Lampirkan berkas diagnostik sistem & error log (<code style="color: var(--primary);">system_diagnostic_log.txt</code>)</span>
                    </label>
                </div>
                <button type="submit" id="btnSubmitBug" class="btn btn-primary" style="width: 100%; margin-top: 0.75rem; padding: 0.85rem; display: flex; align-items: center; justify-content: center; gap: 8px;">
                    <i data-lucide="send" style="width: 16px;"></i> Kirim Laporan ke Discord
                </button>
            </form>
        </div>
    </div>

    <!-- Global IP Intelligence Dossier Modal -->
    <?php require_once __DIR__ . '/ip-intelligence-modal.php'; ?>

    <script>
        function openBugReportModal(e) {
            if (e) e.preventDefault();
            document.getElementById('bugReportModal').style.display = 'flex';
        }

        function closeBugReportModal() {
            document.getElementById('bugReportModal').style.display = 'none';
            const previewWrap = document.getElementById('bugScreenshotPreview');
            if (previewWrap) previewWrap.style.display = 'none';
        }

        const bugScreenshotInput = document.getElementById('bugScreenshot');
        if (bugScreenshotInput) {
            bugScreenshotInput.addEventListener('change', function() {
                const previewWrap = document.getElementById('bugScreenshotPreview');
                const previewImg = document.getElementById('bugScreenshotPreviewImg');
                const previewName = document.getElementById('bugScreenshotPreviewName');
                if (this.files && this.files[0]) {
                    const f = this.files[0];
                    if (f.type.startsWith('image/')) {
                        const r = new FileReader();
                        r.onload = (e) => {
                            previewImg.src = e.target.result;
                            previewName.textContent = f.name + ' (' + (f.size / 1024).toFixed(0) + ' KB)';
                            previewWrap.style.display = 'flex';
                        };
                        r.readAsDataURL(f);
                        return;
                    }
                }
                if (previewWrap) previewWrap.style.display = 'none';
            });
        }

        async function prepareScreenshotFile(file) {
            if (!file || !file.type.startsWith('image/')) return file;
            // Always compress images over 300KB to ensure fast upload and safely bypass PHP limits
            if (file.size <= 300 * 1024) return file;
            return new Promise((resolve) => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = new Image();
                    img.onload = function() {
                        const maxDim = 1600;
                        let w = img.width, h = img.height;
                        if (w > maxDim || h > maxDim) {
                            if (w > h) { h = Math.round((h * maxDim) / w); w = maxDim; }
                            else { w = Math.round((w * maxDim) / h); h = maxDim; }
                        }
                        const canvas = document.createElement('canvas');
                        canvas.width = w; canvas.height = h;
                        const ctx = canvas.getContext('2d');
                        ctx.drawImage(img, 0, 0, w, h);
                        canvas.toBlob((blob) => {
                            if (blob) {
                                resolve(new File([blob], file.name.replace(/\.[^.]+$/, '.jpg'), { type: 'image/jpeg' }));
                            } else {
                                resolve(file);
                            }
                        }, 'image/jpeg', 0.85);
                    };
                    img.onerror = () => resolve(file);
                    img.src = e.target.result;
                };
                reader.onerror = () => resolve(file);
                reader.readAsDataURL(file);
            });
        }

        async function submitBugReport(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSubmitBug');
            const originalText = btn.innerHTML;
            
            btn.disabled = true;
            btn.innerHTML = '<i data-lucide="loader-2" class="spin" style="width: 16px;"></i> Mengirim ke Discord...';

            const formData = new FormData();
            formData.append('email', document.getElementById('bugEmail').value);
            formData.append('title', document.getElementById('bugTitle').value);
            formData.append('description', document.getElementById('bugDescription').value);
            
            const screenshotInput = document.getElementById('bugScreenshot');
            if (screenshotInput && screenshotInput.files && screenshotInput.files[0]) {
                const optimizedFile = await prepareScreenshotFile(screenshotInput.files[0]);
                formData.append('screenshot', optimizedFile);
            }
            const attachAuditCheck = document.getElementById('bugAttachAudit');
            formData.append('attach_audit', (attachAuditCheck && attachAuditCheck.checked) ? '1' : '0');

            try {
                const response = await fetch('api/report-bug', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    alert('Terima kasih! Laporan masalah berhasil dikirim langsung ke Discord Developer.');
                    closeBugReportModal();
                    document.getElementById('bugReportForm').reset();
                    const previewWrap = document.getElementById('bugScreenshotPreview');
                    if (previewWrap) previewWrap.style.display = 'none';
                } else {
                    alert('Error: ' + (result.error || 'Terjadi kesalahan.'));
                }
            } catch (error) {
                alert('Terjadi kesalahan koneksi saat mengirim laporan ke Discord.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
                if (window.lucide) lucide.createIcons();
            }
        }
    </script>

    <script>
        lucide.createIcons();
        
        // Sidebar Navigation Controller (Desktop Collapse & Mobile Drawer)
        const menuBtn = document.getElementById('menu-toggle');
        const collapseBtn = document.getElementById('sidebar-collapse-btn');
        const sidebar = document.querySelector('.sidebar');

        function toggleDesktopCollapse() {
            const isCollapsed = document.documentElement.classList.toggle('sidebar-collapsed');
            if (document.body) document.body.classList.toggle('sidebar-collapsed', isCollapsed);
            try {
                localStorage.setItem('netscope_sidebar_collapsed', isCollapsed ? 'true' : 'false');
            } catch(e) {}
        }

        function toggleMenuButton() {
            if (window.innerWidth <= 1024) {
                if (sidebar) sidebar.classList.toggle('active');
            } else {
                toggleDesktopCollapse();
            }
        }

        if (menuBtn) {
            menuBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                toggleMenuButton();
            });
        }

        if (collapseBtn) {
            collapseBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                toggleDesktopCollapse();
            });
        }

        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 1024 && sidebar && !sidebar.contains(e.target) && sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
            }
        });
    </script>
</body>
</html>
