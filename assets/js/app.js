/* ═══════════════════════════════════════════════════════════════
   Migrador de Personajes — AzerothCore | app.js
   ═══════════════════════════════════════════════════════════════ */

document.addEventListener('DOMContentLoaded', () => {

    // ── File drop zone ─────────────────────────────────────────
    const fileDrop  = document.getElementById('fileDrop');
    const fileInput = document.getElementById('dump_file');
    const fileName  = document.getElementById('fileName');

    if (fileDrop && fileInput) {
        fileDrop.addEventListener('dragover', e => {
            e.preventDefault();
            fileDrop.classList.add('drag-over');
        });
        fileDrop.addEventListener('dragleave', () => {
            fileDrop.classList.remove('drag-over');
        });
        fileDrop.addEventListener('drop', e => {
            e.preventDefault();
            fileDrop.classList.remove('drag-over');
            const file = e.dataTransfer.files[0];
            if (file) {
                // Verificar extensión
                if (!file.name.toLowerCase().endsWith('.lua')) {
                    showToast('Solo se aceptan archivos .lua', 'error');
                    return;
                }
                // Asignar al input
                const dt = new DataTransfer();
                dt.items.add(file);
                fileInput.files = dt.files;
                if (fileName) {
                    fileName.textContent = '📄 ' + file.name + ' (' + formatBytes(file.size) + ')';
                }
            }
        });
        fileInput.addEventListener('change', () => {
            const file = fileInput.files[0];
            if (file && fileName) {
                fileName.textContent = '📄 ' + file.name + ' (' + formatBytes(file.size) + ')';
            }
        });
    }

    // ── Modal de denegar ───────────────────────────────────────
    let activeDenyForm = null;

    document.querySelectorAll('.deny-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            activeDenyForm = btn.closest('.deny-form');
            openDenyModal();
        });
    });

    const denyConfirmBtn = document.getElementById('denyConfirmBtn');
    if (denyConfirmBtn) {
        denyConfirmBtn.addEventListener('click', () => {
            const reasonText = document.getElementById('denyReasonText').value.trim();
            if (!reasonText) {
                showToast('Escribe un motivo de denegación', 'error');
                return;
            }
            if (activeDenyForm) {
                const reasonInput = activeDenyForm.querySelector('.deny-reason-input');
                if (reasonInput) reasonInput.value = reasonText;
                closeDenyModal();
                activeDenyForm.submit();
            }
        });
    }

    // Cerrar modal al hacer clic fuera
    const denyModal = document.getElementById('denyModal');
    if (denyModal) {
        denyModal.addEventListener('click', e => {
            if (e.target === denyModal) closeDenyModal();
        });
    }

    // ── Escape key cierra modal ────────────────────────────────
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeDenyModal();
    });

    // ── Auto-dismiss alertas después de 5 segundos ─────────────
    document.querySelectorAll('.alert-success').forEach(el => {
        setTimeout(() => {
            el.style.transition = 'opacity 0.5s';
            el.style.opacity    = '0';
            setTimeout(() => el.remove(), 500);
        }, 5000);
    });

});

// ── Helpers globales ───────────────────────────────────────────
function openDenyModal() {
    const m = document.getElementById('denyModal');
    if (m) {
        m.style.display = 'flex';
        document.getElementById('denyReasonText').value = '';
        document.getElementById('denyReasonText').focus();
    }
}

function closeDenyModal() {
    const m = document.getElementById('denyModal');
    if (m) m.style.display = 'none';
}

function formatBytes(bytes) {
    if (bytes < 1024)       return bytes + ' B';
    if (bytes < 1024*1024)  return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024*1024)).toFixed(1) + ' MB';
}

function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = 'alert alert-' + type;
    toast.style.cssText = `
        position:fixed; top:1rem; right:1rem; z-index:9999;
        min-width:220px; max-width:380px;
        animation: slideIn 0.3s ease;
    `;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.4s';
        setTimeout(() => toast.remove(), 400);
    }, 4000);
}
