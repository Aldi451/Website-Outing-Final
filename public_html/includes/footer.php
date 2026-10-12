<?php
/**
 * Footer Component
 */
?>
    </div>
</main>

<!-- Modal Preview Bukti Pembayaran Global -->
<div class="modal fade" id="receiptPreviewModal" tabindex="-1" aria-labelledby="receiptModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="receiptModalLabel"><i class="bi bi-file-earmark-image me-2"></i>Bukti Pembayaran</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3">
                <div id="receiptPreviewContainer" class="text-center">
                    <div class="spinner-border text-primary my-4" role="status">
                        <span class="visually-hidden">Memuat...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<footer class="bg-white border-top py-3 mt-auto text-center text-muted small">
    <div class="container-fluid px-4 d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2">
        <div>Sistem Manajemen Outing, Keuangan & Rundown</div>
    </div>
</footer>

<!-- Bootstrap 5 Bundle JS CDN -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Custom JS -->
<script src="<?= base_url('assets/js/main.js'); ?>"></script>
</body>
</html>
