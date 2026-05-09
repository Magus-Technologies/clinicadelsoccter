  </div><!-- /.tr-content -->
</div><!-- /.tr-main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>assets/js/app.js"></script>
<script>
feather.replace();

// Notificaciones de stock bajo
fetch('<?= BASE_URL ?>modules/inventario/api_stock_alerts.php')
  .then(r => r.json()).then(data => {
    if (data.count > 0) {
      const badge = document.getElementById('badge-notif');
      badge.textContent = data.count;
      badge.style.display = 'inline';
    }
  }).catch(() => {});
</script>
<?php if (isset($pageScripts)): ?>
<?= $pageScripts ?>
<?php endif; ?>

<!-- ── Mobile bottom navigation ── -->
<nav class="mobile-bottom-nav" id="mobile-bottom-nav" style="display:none">
  <a href="<?= BASE_URL ?>modules/dashboard/index.php"
     class="<?= strpos($_SERVER['REQUEST_URI'],'dashboard')!==false?'active':'' ?>">
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>
    </svg>
    Inicio
  </a>
  <a href="<?= BASE_URL ?>modules/ot/index.php"
     class="<?= strpos($_SERVER['REQUEST_URI'],'/ot/')!==false?'active':'' ?>">
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
      <line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>
    </svg>
    OTs
  </a>
  <a href="<?= BASE_URL ?>modules/ot/nueva.php" style="position:relative">
    <span style="width:42px;height:42px;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;margin-top:-10px;box-shadow:0 4px 12px rgba(79,70,229,.4)">
      <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none"
           stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
      </svg>
    </span>
    Nueva OT
  </a>
  <a href="<?= BASE_URL ?>modules/clientes/index.php"
     class="<?= strpos($_SERVER['REQUEST_URI'],'clientes')!==false?'active':'' ?>">
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
      <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
    </svg>
    Clientes
  </a>
  <a href="<?= BASE_URL ?>modules/ventas/pos.php"
     class="<?= strpos($_SERVER['REQUEST_URI'],'ventas')!==false?'active':'' ?>">
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
      <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
    </svg>
    POS
  </a>
</nav>

<script>
// Show bottom nav only on mobile/tablet — garantiza que no aparece en desktop
(function() {
  function checkNav() {
    var nav = document.getElementById('mobile-bottom-nav');
    if (!nav) return;
    nav.style.display = window.innerWidth <= 991 ? 'flex' : 'none';
  }
  checkNav();
  window.addEventListener('resize', checkNav);
})();
</script>
</body>
</html>
