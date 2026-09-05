<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"/>
  <title><?= $pageTitle ?? APP_NAME ?></title>

  <!-- Bootstrap 5.3 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <!-- Feather Icons -->
  <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
  <!-- SortableJS (drag estados OT) -->
  <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
  <!-- Signature Pad -->
  <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <link href="<?= BASE_URL ?>assets/css/app.css" rel="stylesheet"/>
  <style>
    /* Botón de cerrar sesión — visible y claro en el sidebar */
    .tr-logout-btn {
      display: flex; align-items: center; justify-content: center; gap: 8px;
      width: 100%; margin-top: 10px; padding: 9px 12px;
      border-radius: 8px; font-size: 13px; font-weight: 600;
      color: #fecaca; background: rgba(239,68,68,.14);
      border: 1px solid rgba(239,68,68,.35);
      text-decoration: none;
      transition: background .15s, color .15s, border-color .15s;
    }
    .tr-logout-btn:hover { background:#dc2626; color:#fff; border-color:#dc2626; }
    .tr-logout-btn svg { width:16px; height:16px; }
    /* En modo colapsado: mostrar solo el ícono, centrado (sobreescribe el display:none previo) */
    .tr-sidebar.collapsed .tr-logout-btn { display:flex !important; padding:9px 0; margin-top:8px; }
    .tr-sidebar.collapsed .tr-logout-btn span { display:none; }
  </style>
  <script>window.BASE_URL = '<?= BASE_URL ?>';</script>
</head>
<body class="tr-body">

<!-- Mobile overlay -->
<div id="sidebar-overlay"></div>

<!-- SIDEBAR -->
<div class="tr-sidebar" id="sidebar">
  <div class="tr-sidebar-brand">
    <i data-feather="tool" class="me-2"></i>
    <span><?= APP_NAME ?></span>
  </div>

  <nav class="tr-nav">
    <?php $u = currentUser(); $rol = $u['rol']; ?>

    <a href="<?= BASE_URL ?>modules/dashboard/index.php"
       class="tr-nav-item <?= strpos($_SERVER['REQUEST_URI'],'dashboard')!==false?'active':'' ?>">
      <i data-feather="home"></i><span>Dashboard</span>
    </a>

    <?php /* El menú se arma según los permisos del perfil (modules/permisos) */ ?>

    <?php if (puede('ot') || puede('ot_nueva')): ?>
    <div class="tr-nav-group">Reparaciones</div>
      <?php if (puede('ot')): ?>
      <a href="<?= BASE_URL ?>modules/ot/index.php"
         class="tr-nav-item <?= strpos($_SERVER['REQUEST_URI'],'/ot/')!==false?'active':'' ?>">
        <i data-feather="clipboard"></i><span>Órdenes de trabajo</span>
      </a>
      <?php endif; ?>
      <?php if (puede('ot_nueva')): ?>
      <a href="<?= BASE_URL ?>modules/ot/nueva.php" class="tr-nav-item">
        <i data-feather="plus-circle"></i><span>Nueva OT</span>
      </a>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (puede('ventas_pos') || puede('ventas')): ?>
    <div class="tr-nav-group">Ventas</div>
      <?php if (puede('ventas_pos')): ?>
      <a href="<?= BASE_URL ?>modules/ventas/pos.php" class="tr-nav-item">
        <i data-feather="shopping-cart"></i><span>Punto de venta</span>
      </a>
      <?php endif; ?>
      <?php if (puede('ventas')): ?>
      <a href="<?= BASE_URL ?>modules/ventas/index.php" class="tr-nav-item">
        <i data-feather="list"></i><span>Ventas</span>
      </a>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (puede('catalogo')): ?>
    <div class="tr-nav-group">Catálogo público</div>
    <a href="<?= BASE_URL ?>modules/catalogo/index.php"
       class="tr-nav-item <?= strpos($_SERVER['REQUEST_URI'],'catalogo')!==false?'active':'' ?>">
      <i data-feather="shopping-bag"></i><span>Catálogo</span>
    </a>
    <a href="<?= BASE_URL ?>public/catalogo/" target="_blank" class="tr-nav-item">
      <i data-feather="external-link"></i><span>Ver catálogo</span>
    </a>
    <?php endif; ?>

    <?php if (puede('inventario') || puede('compras') || puede('kardex') || puede('categorias')): ?>
    <div class="tr-nav-group">Inventario</div>
      <?php if (puede('inventario')): ?>
      <a href="<?= BASE_URL ?>modules/inventario/index.php" class="tr-nav-item">
        <i data-feather="package"></i><span>Productos</span>
      </a>
      <?php endif; ?>
      <?php if (puede('compras')): ?>
      <a href="<?= BASE_URL ?>modules/compras/index.php"
         class="tr-nav-item <?= strpos($_SERVER['REQUEST_URI'],'compras')!==false?'active':'' ?>">
        <i data-feather="truck"></i><span>Compras</span>
      </a>
      <?php endif; ?>
      <?php if (puede('kardex')): ?>
      <a href="<?= BASE_URL ?>modules/inventario/kardex.php" class="tr-nav-item">
        <i data-feather="bar-chart-2"></i><span>Kardex</span>
      </a>
      <?php endif; ?>
      <?php if (puede('categorias')): ?>
      <a href="<?= BASE_URL ?>modules/categorias/index.php"
         class="tr-nav-item <?= strpos($_SERVER['REQUEST_URI'],'categorias')!==false?'active':'' ?>">
        <i data-feather="grid"></i><span>Categorías</span>
      </a>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (puede('whatsapp')): ?>
    <div class="tr-nav-group">Comunicaciones</div>
    <a href="<?= BASE_URL ?>modules/whatsapp/index.php"
       class="tr-nav-item <?= strpos($_SERVER['REQUEST_URI'],'whatsapp')!==false?'active':'' ?>">
      <i data-feather="message-circle"></i><span>WhatsApp</span>
    </a>
    <?php endif; ?>

    <?php if (puede('clientes')): ?>
    <div class="tr-nav-group">Clientes</div>
    <a href="<?= BASE_URL ?>modules/clientes/index.php" class="tr-nav-item">
      <i data-feather="users"></i><span>Clientes</span>
    </a>
    <?php endif; ?>

    <?php if (puede('servicios')): ?>
    <div class="tr-nav-group">Servicios</div>
    <a href="<?= BASE_URL ?>modules/servicios/index.php"
       class="tr-nav-item <?= strpos($_SERVER['REQUEST_URI'],'servicios')!==false?'active':'' ?>">
      <i data-feather="briefcase"></i><span>Servicios</span>
    </a>
    <?php endif; ?>

    <?php
    $adminItems = ['caja','reportes','usuarios','garantias','estados','plantilla_impresion','configuracion'];
    $verAdmin   = false;
    foreach ($adminItems as $__m) { if (puede($__m)) { $verAdmin = true; break; } }
    ?>
    <?php if ($verAdmin || $rol === ROL_ADMIN): ?>
    <div class="tr-nav-group">Administración</div>
      <?php if (puede('caja')): ?>
      <a href="<?= BASE_URL ?>modules/caja/index.php" class="tr-nav-item">
        <i data-feather="dollar-sign"></i><span>Caja</span>
      </a>
      <?php endif; ?>
      <?php if (puede('reportes')): ?>
      <a href="<?= BASE_URL ?>modules/reportes/index.php" class="tr-nav-item">
        <i data-feather="trending-up"></i><span>Reportes</span>
      </a>
      <?php endif; ?>
      <?php if (puede('usuarios')): ?>
      <a href="<?= BASE_URL ?>modules/usuarios/index.php"
         class="tr-nav-item <?= strpos($_SERVER['REQUEST_URI'],'usuarios')!==false?'active':'' ?>">
        <i data-feather="user-check"></i><span>Usuarios</span>
      </a>
      <?php endif; ?>
      <?php if ($rol === ROL_ADMIN): ?>
      <a href="<?= BASE_URL ?>modules/permisos/index.php"
         class="tr-nav-item <?= strpos($_SERVER['REQUEST_URI'],'permisos')!==false?'active':'' ?>">
        <i data-feather="lock"></i><span>Permisos</span>
      </a>
      <?php endif; ?>
      <?php if (puede('garantias')): ?>
      <a href="<?= BASE_URL ?>modules/garantias/index.php" class="tr-nav-item">
        <i data-feather="shield"></i><span>Garantías</span>
      </a>
      <?php endif; ?>
      <?php if (puede('estados')): ?>
      <a href="<?= BASE_URL ?>modules/estados/index.php"
         class="tr-nav-item <?= strpos($_SERVER['REQUEST_URI'],'estados')!==false?'active':'' ?>">
        <i data-feather="tag"></i><span>Estados OT</span>
      </a>
      <?php endif; ?>
      <?php if (puede('plantilla_impresion')): ?>
      <a href="<?= BASE_URL ?>modules/configuracion/plantilla_impresion.php"
         class="tr-nav-item <?= strpos($_SERVER['REQUEST_URI'],'plantilla_impresion')!==false?'active':'' ?>">
        <i data-feather="printer"></i><span>Plantilla impresión</span>
      </a>
      <?php endif; ?>
      <?php if (puede('configuracion')): ?>
      <a href="<?= BASE_URL ?>modules/configuracion/index.php" class="tr-nav-item">
        <i data-feather="settings"></i><span>Configuración</span>
      </a>
      <?php endif; ?>
    <?php endif; ?>
  </nav>

  <div class="tr-sidebar-footer">
    <div class="d-flex align-items-center gap-2">
      <div class="tr-avatar"><?= strtoupper(substr($u['nombre'],0,1)) ?></div>
      <div class="flex-grow-1 small" style="min-width:0">
        <div class="fw-semibold text-truncate"><?= sanitize($u['nombre']) ?></div>
        <div class="text-muted" style="font-size:11px"><?= ucfirst($u['rol']) ?></div>
      </div>
    </div>
    <a href="<?= BASE_URL ?>modules/auth/logout.php" class="tr-logout-btn"
       title="Cerrar sesión" onclick="return confirm('¿Cerrar sesión?');">
      <i data-feather="log-out"></i><span>Cerrar sesión</span>
    </a>
  </div>
</div>

<!-- MAIN WRAPPER -->
<div class="tr-main" id="main-content">

  <!-- TOPBAR -->
  <div class="tr-topbar">
    <button class="btn btn-sm btn-light" id="sidebar-toggle">
      <i data-feather="menu"></i>
    </button>
    <nav aria-label="breadcrumb" class="ms-3">
      <ol class="breadcrumb mb-0">
        <?php foreach ($breadcrumb ?? [] as $item): ?>
          <?php if ($item['url']): ?>
            <li class="breadcrumb-item">
              <a href="<?= $item['url'] ?>"><?= sanitize($item['label']) ?></a>
            </li>
          <?php else: ?>
            <li class="breadcrumb-item active"><?= sanitize($item['label']) ?></li>
          <?php endif; ?>
        <?php endforeach; ?>
      </ol>
    </nav>
    <div class="ms-auto d-flex align-items-center gap-3">
      <!-- Notificaciones stock -->
      <div class="position-relative">
        <button class="btn btn-sm btn-light" id="btn-notif">
          <i data-feather="bell"></i>
          <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="badge-notif" style="display:none">0</span>
        </button>
      </div>
      <span class="text-muted small"><?= date('d/m/Y H:i') ?></span>
    </div>
  </div>

  <!-- FLASH MESSAGE -->
  <?php $flash = getFlash(); if ($flash): ?>
  <div class="alert alert-<?= $flash['tipo'] ?> alert-dismissible mx-4 mt-3 fade show" role="alert">
    <?= sanitize($flash['mensaje']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <!-- PAGE CONTENT -->
  <div class="tr-content">
