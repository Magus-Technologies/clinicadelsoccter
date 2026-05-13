/* TechRepair Pro — app.js */

// ── Auto-dismiss flash messages ──────────────────────────
setTimeout(() => {
  document.querySelectorAll('.alert.fade.show').forEach(el => {
    bootstrap.Alert.getOrCreateInstance(el)?.close();
  });
}, 4000);

// ── Confirm delete buttons ───────────────────────────────
document.addEventListener('click', e => {
  const btn = e.target.closest('[data-confirm]');
  if (btn) {
    e.preventDefault();
    if (confirm(btn.dataset.confirm || '¿Está seguro?')) {
      const form = btn.closest('form');
      if (form) form.submit(); else window.location = btn.href;
    }
  }
});

// ══════════════════════════════════════════════════════════
// SIDEBAR — Desktop collapse + Mobile drawer
// ══════════════════════════════════════════════════════════
const sidebar  = document.getElementById('sidebar');
const mainWrap = document.getElementById('main-content');
const overlay  = document.getElementById('sidebar-overlay');
const toggleBtn= document.getElementById('sidebar-toggle');

function isMobile() { return window.innerWidth <= 991; }

toggleBtn?.addEventListener('click', () => {
  if (isMobile()) {
    // Mobile: slide-in drawer
    sidebar.classList.toggle('mobile-open');
    overlay.classList.toggle('show');
    document.body.style.overflow = sidebar.classList.contains('mobile-open') ? 'hidden' : '';
  } else {
    // Desktop: icon-only collapse
    sidebar.classList.toggle('collapsed');
    mainWrap?.classList.toggle('expanded');
    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed') ? '1' : '0');
  }
});

// Close drawer when overlay clicked
overlay?.addEventListener('click', closeMobileSidebar);

function closeMobileSidebar() {
  sidebar?.classList.remove('mobile-open');
  overlay?.classList.remove('show');
  document.body.style.overflow = '';
}

// Close drawer when a nav link is clicked on mobile
document.querySelectorAll('.tr-nav-item').forEach(a => {
  a.addEventListener('click', () => { if (isMobile()) closeMobileSidebar(); });
});

// Restore desktop collapse state
if (!isMobile() && localStorage.getItem('sidebarCollapsed') === '1') {
  sidebar?.classList.add('collapsed');
  mainWrap?.classList.add('expanded');
}

// Resize: clean up mobile state if resized to desktop
window.addEventListener('resize', () => {
  if (!isMobile()) closeMobileSidebar();
});

// ── Foto drag & drop upload ──────────────────────────────
function initFotoDrop(dropZoneId, previewId, inputId) {
  const zone    = document.getElementById(dropZoneId);
  const preview = document.getElementById(previewId);
  const input   = document.getElementById(inputId);
  if (!zone) return;

  zone.addEventListener('click', () => input.click());
  zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('dragover'); });
  zone.addEventListener('dragleave', ()  => zone.classList.remove('dragover'));
  zone.addEventListener('drop', e => {
    e.preventDefault(); zone.classList.remove('dragover');
    handleFiles(e.dataTransfer.files);
  });
  input.addEventListener('change', () => handleFiles(input.files));

  function handleFiles(files) {
    Array.from(files).forEach(file => {
      if (!file.type.startsWith('image/')) return;
      const reader = new FileReader();
      reader.onload = ev => {
        const div = document.createElement('div');
        div.className = 'foto-preview-item';
        div.innerHTML = `<img src="${ev.target.result}" alt="foto">
          <button type="button" class="btn-remove" onclick="this.closest('.foto-preview-item').remove()">✕</button>`;
        preview.appendChild(div);
      };
      reader.readAsDataURL(file);
    });
  }
}

// ── Firma digital ────────────────────────────────────────
let signaturePad = null;

function initFirma(canvasId, hiddenId) {
  const canvas = document.getElementById(canvasId);
  if (!canvas || typeof SignaturePad === 'undefined') return;

  signaturePad = new SignaturePad(canvas, {
    backgroundColor: 'rgb(255,255,255)',
    penColor: '#1a1d23',
    minWidth: 1.5, maxWidth: 3,
  });

  function resizeCanvas() {
    const ratio = Math.max(window.devicePixelRatio || 1, 1);
    const w = canvas.offsetWidth;
    const h = canvas.offsetHeight || 120;
    canvas.width  = w * ratio;
    canvas.height = h * ratio;
    canvas.getContext('2d').scale(ratio, ratio);
    signaturePad.clear();
  }

  window.addEventListener('resize', resizeCanvas);
  setTimeout(resizeCanvas, 100);   // wait for layout

  document.getElementById('btn-clear-firma')?.addEventListener('click', () => signaturePad.clear());

  // Save on form submit
  canvas.closest('form')?.addEventListener('submit', () => {
    const hidden = document.getElementById(hiddenId);
    if (hidden && signaturePad && !signaturePad.isEmpty()) {
      hidden.value = signaturePad.toDataURL('image/svg+xml');
    }
  });
}

// ── Search filter for tables ─────────────────────────────
function initTableSearch(inputId, tableId) {
  const input = document.getElementById(inputId);
  const table = document.getElementById(tableId);
  if (!input || !table) return;
  input.addEventListener('input', () => {
    const q = input.value.toLowerCase();
    table.querySelectorAll('tbody tr').forEach(row => {
      row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });
}

// ── Currency inputs ──────────────────────────────────────
document.addEventListener('input', e => {
  if (e.target.classList.contains('currency-input')) {
    e.target.value = e.target.value.replace(/[^0-9.]/g, '');
  }
});

// ── Calcular totales OT ──────────────────────────────────
function calcularTotalOT() {
  const rep = parseFloat(document.getElementById('costo_repuestos')?.value) || 0;
  const mo  = parseFloat(document.getElementById('costo_mano_obra')?.value)  || 0;
  const dsc = parseFloat(document.getElementById('descuento')?.value)         || 0;
  const tot = rep + mo - dsc;
  const el  = document.getElementById('precio_final');
  if (el)   el.value = tot.toFixed(2);
  const sh  = document.getElementById('total_display');
  if (sh)   sh.textContent = 'S/ ' + tot.toFixed(2);
}

['costo_repuestos','costo_mano_obra','descuento'].forEach(id => {
  document.getElementById(id)?.addEventListener('input', calcularTotalOT);
});

// ── Bootstrap tooltips ───────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  // Only init tooltips on non-touch devices (avoid sticky tooltips on mobile)
  if (!('ontouchstart' in window)) {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
      new bootstrap.Tooltip(el);
    });
  }
});

// ── Wrap all .tr-card-body > .tr-table with scroll div ───
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.tr-table').forEach(table => {
    if (!table.closest('.table-responsive-wrapper')) {
      const wrap = document.createElement('div');
      wrap.className = 'table-responsive-wrapper';
      table.parentNode.insertBefore(wrap, table);
      wrap.appendChild(table);
    }
  });
});
