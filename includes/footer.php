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
            <p style="color: var(--text-muted); font-size: 0.875rem; margin-bottom: 1.5rem;">Laporan anda akan dikirimkan langsung ke pengembang untuk ditindaklanjuti.</p>
            
            <form id="bugReportForm" onsubmit="submitBugReport(event)">
                <div class="input-group">
                    <label>Judul Masalah</label>
                    <input type="text" id="bugTitle" class="input-control" placeholder="Apa yang salah?" required>
                </div>
                <div class="input-group">
                    <label>Detail Kejadian</label>
                    <textarea id="bugDescription" class="input-control" style="height: 120px;" placeholder="Tolong jelaskan langkah-langkah sebelum terjadi error..." required></textarea>
                </div>
                <button type="submit" id="btnSubmitBug" class="btn btn-primary" style="width: 100%; margin-top: 1rem; padding: 1rem;">
                    Kirim Laporan
                </button>
            </form>
        </div>
    </div>

    <script>
        function openBugReportModal(e) {
            if (e) e.preventDefault();
            document.getElementById('bugReportModal').style.display = 'flex';
        }

        function closeBugReportModal() {
            document.getElementById('bugReportModal').style.display = 'none';
        }

        async function submitBugReport(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSubmitBug');
            const originalText = btn.innerText;
            
            btn.disabled = true;
            btn.innerText = 'Sending...';

            const formData = new FormData();
            formData.append('title', document.getElementById('bugTitle').value);
            formData.append('description', document.getElementById('bugDescription').value);

            try {
                const response = await fetch('api/report-bug', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    alert('Terima kasih! Laporan bug berhasil dikirim.');
                    closeBugReportModal();
                    document.getElementById('bugReportForm').reset();
                } else {
                    alert('Error: ' + result.error || 'Terjadi kesalahan.');
                }
            } catch (error) {
                alert('Terjadi kesalahan koneksi saat mengirim laporan.');
            } finally {
                btn.disabled = false;
                btn.innerText = originalText;
            }
        }
    </script>

    <script>
        lucide.createIcons();
        
        // Mobile Sidebar Toggle
        const menuBtn = document.getElementById('menu-toggle');
        const sidebar = document.querySelector('.sidebar');
        if (menuBtn && sidebar) {
            menuBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                sidebar.classList.toggle('active');
            });
            
            document.addEventListener('click', (e) => {
                if (!sidebar.contains(e.target) && sidebar.classList.contains('active')) {
                    sidebar.classList.remove('active');
                }
            });
        }
    </script>
</body>
</html>
