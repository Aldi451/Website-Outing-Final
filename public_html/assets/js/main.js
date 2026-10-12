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

    // Tombol/link yang membuka tab tertentu (mis. "Upload Bukti Bayar")
    document.querySelectorAll('.js-open-tab[data-tab-target]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            const trigger = document.querySelector(`button[data-bs-target="${link.getAttribute('data-tab-target')}"]`);
            if (trigger && typeof bootstrap !== 'undefined') {
                e.preventDefault();
                new bootstrap.Tab(trigger).show();
                trigger.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

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


/* =====================================================================
 * FITUR PEMBAYARAN IURAN
 * ===================================================================== */

/**
 * Form status pembayaran (Admin): tombol isi cepat nominal & preview status otomatis
 */
document.addEventListener('DOMContentLoaded', function () {
    const LABELS = { belum: 'BELUM', partial: 'PARTIAL', sudah: 'SUDAH' };

    document.querySelectorAll('form[data-pay-form]').forEach(function (form) {
        const estimate = parseFloat(form.getAttribute('data-estimate')) || 0;
        const amountInput = form.querySelector('[name="amount_paid"]');
        const statusSelect = form.querySelector('[name="payment_status"]');
        if (!amountInput || !statusSelect) return;
        const autoOption = statusSelect.querySelector('option[value="auto"]');

        function resolveStatus(paid) {
            if (paid <= 0) return 'belum';
            if (estimate <= 0 || paid >= estimate) return 'sudah';
            return 'partial';
        }

        function refresh() {
            if (!autoOption) return;
            const paid = parseFloat(amountInput.value) || 0;
            autoOption.textContent = 'Otomatis dari nominal \u2192 ' + LABELS[resolveStatus(paid)];
        }

        form.querySelectorAll('[data-fill-amount]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                amountInput.value = btn.getAttribute('data-fill-amount');
                if (autoOption) statusSelect.value = 'auto';
                refresh();
            });
        });

        amountInput.addEventListener('input', refresh);
        refresh();
    });
});

/**
 * Kompresi gambar di browser sebelum upload (hemat kuota & cepat di jaringan HP)
 * - Sisi pendek maks. 1440px, sisi panjang maks. 2560px -> teks struk tetap terbaca jelas
 * - Output JPEG kualitas 0.82 (biasanya 150-500 KB dari foto 3-8 MB)
 * - Server tetap mengompres ulang sebagai pengaman jika browser tidak mendukung
 */
(function () {
    const MAX_SHORT = 1440;
    const MAX_LONG = 2560;
    const QUALITY = 0.82;

    function formatSize(bytes) {
        return bytes >= 1048576
            ? (bytes / 1048576).toFixed(2).replace('.', ',') + ' MB'
            : Math.max(1, Math.round(bytes / 1024)) + ' KB';
    }

    function loadImage(file) {
        return new Promise(function (resolve, reject) {
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = function () { resolve({ img: img, url: url }); };
            img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('Gagal membaca gambar')); };
            img.src = url;
        });
    }

    async function compressImage(file) {
        if (!/^image\/(jpeg|png|webp)$/i.test(file.type)) return file;
        const loaded = await loadImage(file);
        const img = loaded.img;
        const w = img.naturalWidth, h = img.naturalHeight;
        const scale = Math.min(1, MAX_SHORT / Math.min(w, h), MAX_LONG / Math.max(w, h));
        const cw = Math.max(1, Math.round(w * scale));
        const ch = Math.max(1, Math.round(h * scale));

        const canvas = document.createElement('canvas');
        canvas.width = cw;
        canvas.height = ch;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff';           // latar putih untuk PNG transparan
        ctx.fillRect(0, 0, cw, ch);
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(img, 0, 0, cw, ch);
        URL.revokeObjectURL(loaded.url);

        const blob = await new Promise(function (resolve) { canvas.toBlob(resolve, 'image/jpeg', QUALITY); });
        if (!blob) return file;
        // Jika hasil tidak lebih kecil & tidak perlu resize, pakai file asli (mis. screenshot PNG kecil)
        if (blob.size >= file.size && scale === 1) return file;

        const name = (file.name || 'bukti').replace(/\.[^.]+$/, '') + '.jpg';
        return new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() });
    }

    document.addEventListener('change', async function (e) {
        const input = e.target;
        if (!input.matches || !input.matches('input[type="file"][data-compress-image]')) return;
        const file = input.files && input.files[0];
        const form = input.closest('form');
        const info = form ? form.querySelector('.js-compress-info') : null;
        const submitBtn = form ? form.querySelector('[type="submit"]') : null;
        if (info) info.innerHTML = '';
        if (!file) return;

        if (file.type === 'application/pdf') {
            if (info) {
                info.innerHTML = file.size > 5 * 1048576
                    ? '<span class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>PDF ' + formatSize(file.size) + ' melebihi 5 MB. Gunakan foto/screenshot agar bisa dikompres.</span>'
                    : '<span class="text-muted"><i class="bi bi-file-earmark-pdf me-1"></i>PDF ' + formatSize(file.size) + ' (tidak dikompres)</span>';
            }
            return;
        }
        if (!/^image\//i.test(file.type)) return;

        if (submitBtn) submitBtn.disabled = true;
        if (info) info.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Mengompres gambar...</span>';

        try {
            const compressed = await compressImage(file);
            if (compressed !== file && typeof DataTransfer !== 'undefined') {
                const dt = new DataTransfer();
                dt.items.add(compressed);
                input.files = dt.files;
            }
            const finalFile = input.files[0];
            if (info) {
                const previewUrl = URL.createObjectURL(finalFile);
                const saved = finalFile.size < file.size
                    ? '<i class="bi bi-file-zip me-1"></i>Dikompres: ' + formatSize(file.size) + ' &rarr; <strong>' + formatSize(finalFile.size) + '</strong>'
                    : '<i class="bi bi-check-circle me-1"></i>Ukuran ' + formatSize(finalFile.size);
                info.innerHTML = '<div class="d-flex align-items-center gap-2 p-2 border rounded bg-light">'
                    + '<img src="' + previewUrl + '" class="compress-preview" alt="Preview">'
                    + '<span class="text-success">' + saved + '</span></div>';
            }
        } catch (err) {
            // Gagal kompres di browser (mis. HEIC) -> upload file asli, server yang mengompres
            if (info) info.innerHTML = '<span class="text-muted"><i class="bi bi-info-circle me-1"></i>Gambar akan dikompres di server (' + formatSize(file.size) + ').</span>';
        } finally {
            if (submitBtn) submitBtn.disabled = false;
        }
    });
})();
