/**
 * Outing Management System - Vanilla JS Helpers
 */

document.addEventListener('DOMContentLoaded', function () {
    // 1. Inisialisasi Tooltip Bootstrap jika tersedia
    if (typeof bootstrap !== 'undefined') {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }

    // 2. Kalkulasi Otomatis Pembelian (Qty × Harga Satuan = Total Harga)
    const qtyInput = document.getElementById('calc_qty');
    const priceInput = document.getElementById('calc_unit_price');
    const totalInput = document.getElementById('calc_total_price');
    const totalDisplay = document.getElementById('calc_total_display');

    function calculateTotal() {
        if (!qtyInput || !priceInput || !totalInput) return;
        const qty = parseFloat(qtyInput.value) || 0;
        const price = parseFloat(priceInput.value) || 0;
        const total = Math.round(qty * price);
        totalInput.value = total;
        if (totalDisplay) {
            totalDisplay.textContent = 'Rp ' + total.toLocaleString('id-ID');
        }
    }

    if (qtyInput && priceInput) {
        qtyInput.addEventListener('input', calculateTotal);
        priceInput.addEventListener('input', calculateTotal);
        calculateTotal();
    }

    // 3. Konfirmasi Dialog Sebelum Hapus Data (Wajib)
    document.addEventListener('click', function (e) {
        const confirmBtn = e.target.closest('[data-confirm]');
        if (confirmBtn) {
            const message = confirmBtn.getAttribute('data-confirm') || 'Apakah Anda yakin ingin menghapus data ini?';
            if (!confirm(message)) {
                e.preventDefault();
                return false;
            }
        }
    });

    // 4. Modal Bukti Bayar / Receipt Preview
    const receiptModal = document.getElementById('receiptPreviewModal');
    if (receiptModal) {
        receiptModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const fileUrl = button.getAttribute('data-file-url');
            const fileType = button.getAttribute('data-file-type');
            const container = document.getElementById('receiptPreviewContainer');
            
            if (fileType === 'pdf') {
                container.innerHTML = `<iframe src="${fileUrl}" style="width:100%;height:500px;border:none;border-radius:8px;"></iframe>`;
            } else {
                container.innerHTML = `<div class="text-center"><img src="${fileUrl}" class="img-fluid rounded shadow-sm" style="max-height:500px;" alt="Bukti Pembayaran"></div>`;
            }
        });
    }

    // 5. Simpan & Aktifkan Tab dari Hash URL
    const hash = window.location.hash;
    if (hash) {
        const triggerEl = document.querySelector(`button[data-bs-target="${hash}"], a[href="${hash}"]`);
        if (triggerEl && typeof bootstrap !== 'undefined') {
            const tab = new bootstrap.Tab(triggerEl);
            tab.show();
        }
    }

    // Update URL hash saat tab diklik
    const tabTriggers = document.querySelectorAll('button[data-bs-toggle="tab"], a[data-bs-toggle="pill"]');
    tabTriggers.forEach(function (tabEl) {
        tabEl.addEventListener('shown.bs.tab', function (e) {
            const targetId = e.target.getAttribute('data-bs-target') || e.target.getAttribute('href');
            if (targetId && targetId.startsWith('#')) {
                history.replaceState(null, null, targetId);
            }
        });
    });
});
