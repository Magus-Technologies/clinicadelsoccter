<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';

$db = getDB();
$cfgRows = $db->query("SELECT clave,valor FROM configuracion WHERE grupo IN ('catalogo','empresa')")->fetchAll();
$cfg = []; foreach($cfgRows as $r) $cfg[$r['clave']] = $r['valor'];
$empresa   = $cfg['empresa_nombre'] ?? 'TechRepair';
$waNumero  = preg_replace('/\D/', '', $cfg['catalogo_whatsapp'] ?? $cfg['empresa_telefono'] ?? '');
$colorPrim = $cfg['catalogo_color_primario'] ?? '#0d9488';

$catId  = (int)($_GET['cat'] ?? 0);
$buscar = trim($_GET['q'] ?? '');

$where = ['p.activo=1','p.visible_catalogo=1'];
$params = [];
if ($catId)  { $where[]='p.categoria_id=?'; $params[]=$catId; }
if ($buscar) { $where[]='p.nombre LIKE ?';   $params[]='%'.$buscar.'%'; }

$productos = $db->prepare("
    SELECT p.*,c.nombre AS cat_nombre FROM productos p
    JOIN categorias c ON c.id=p.categoria_id
    WHERE ".implode(' AND ',$where)."
    ORDER BY p.destacado DESC, p.nombre LIMIT 20");
$productos->execute($params);
$productos = $productos->fetchAll();

$categorias = $db->query("
    SELECT c.id,c.nombre,COUNT(p.id) AS total FROM categorias c
    JOIN productos p ON p.categoria_id=c.id
    WHERE p.activo=1 AND p.visible_catalogo=1 AND c.activo=1
    GROUP BY c.id ORDER BY c.nombre")->fetchAll();

$banners = $db->query("SELECT * FROM catalogo_banners WHERE activo=1 ORDER BY orden LIMIT 5")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Catálogo — <?= htmlspecialchars($empresa) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
:root{--p:<?= htmlspecialchars($colorPrim) ?>;}
body{font-family:'Inter',sans-serif;background:#f8fafc;color:#1f2937;}
/* TOPBAR */
.topbar{background:#fff;border-bottom:1px solid #e5e7eb;padding:0 20px;height:60px;
  display:flex;align-items:center;justify-content:space-between;
  position:sticky;top:0;z-index:200;box-shadow:0 1px 3px rgba(0,0,0,.08);}
.brand-name{font-size:15px;font-weight:800;}
.search-form{flex:1;max-width:380px;margin:0 16px;}
.search-form input{width:100%;border:1px solid #e5e7eb;border-radius:50px;
  padding:8px 16px;font-size:13px;outline:none;}
.search-form input:focus{border-color:var(--p);}
.cart-btn{background:var(--p);color:#fff;border:none;border-radius:50px;
  padding:8px 16px;font-size:13px;font-weight:600;cursor:pointer;
  display:flex;align-items:center;gap:6px;position:relative;}
.cart-badge{position:absolute;top:-6px;right:-6px;background:#ef4444;color:#fff;
  width:18px;height:18px;border-radius:50%;font-size:10px;font-weight:800;
  display:none;align-items:center;justify-content:center;}
.cart-badge.show{display:flex;}
/* BANNER */
.banner-wrap{background:#1f2937;position:relative;overflow:hidden;}
.banner-slide{display:none;position:relative;}
.banner-slide.active{display:block;}
.banner-slide img{width:100%;height:260px;object-fit:cover;}
.banner-overlay{position:absolute;inset:0;background:linear-gradient(to right,rgba(0,0,0,.6),transparent);
  display:flex;flex-direction:column;justify-content:center;padding:32px 40px;}
.banner-overlay h2{font-size:24px;font-weight:800;color:#fff;margin-bottom:6px;}
.banner-overlay p{font-size:13px;color:rgba(255,255,255,.85);}
.banner-ph{background:var(--p);height:160px;display:flex;align-items:center;
  justify-content:center;flex-direction:column;color:#fff;text-align:center;padding:20px;}
.banner-ph h2{font-size:20px;font-weight:800;margin-bottom:4px;}
.banner-dots{position:absolute;bottom:10px;left:50%;transform:translateX(-50%);display:flex;gap:5px;}
.banner-dot{width:8px;height:8px;border-radius:50%;background:rgba(255,255,255,.4);border:none;cursor:pointer;}
.banner-dot.active{background:#fff;}
/* CHIPS */
.chips{display:flex;gap:8px;overflow-x:auto;padding:12px 16px;
  background:#fff;border-bottom:1px solid #e5e7eb;scrollbar-width:none;}
.chips::-webkit-scrollbar{display:none;}
.chip{flex-shrink:0;padding:5px 14px;border-radius:50px;border:1px solid #e5e7eb;
  background:#fff;font-size:12px;font-weight:600;color:#6b7280;
  cursor:pointer;text-decoration:none;white-space:nowrap;}
.chip:hover,.chip.active{background:var(--p);border-color:var(--p);color:#fff;}
/* LAYOUT */
.page{display:flex;}
.sidebar{width:210px;flex-shrink:0;background:#fff;border-right:1px solid #e5e7eb;
  padding:16px 0;min-height:calc(100vh - 60px);
  position:sticky;top:60px;height:calc(100vh - 60px);overflow-y:auto;}
.sidebar-title{font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;
  letter-spacing:.07em;padding:0 18px 10px;}
.scat{display:flex;align-items:center;justify-content:space-between;
  padding:9px 18px;font-size:13px;font-weight:500;color:#1f2937;
  text-decoration:none;border:none;background:none;width:100%;text-align:left;cursor:pointer;}
.scat:hover{background:#f9fafb;color:var(--p);}
.scat.active{background:#f0fdfa;color:var(--p);font-weight:700;}
.scat span{font-size:11px;background:#f3f4f6;color:#6b7280;
  padding:1px 7px;border-radius:20px;font-weight:600;}
.scat.active span{background:var(--p);color:#fff;}
/* CONTENT */
.content{flex:1;padding:20px;min-width:0;}
.sect-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;}
.sect-title{font-size:16px;font-weight:800;}
.sect-count{font-size:12px;color:#9ca3af;}
/* GRID */
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px;}
.card{background:#fff;border-radius:12px;border:1px solid #e5e7eb;
  overflow:hidden;cursor:pointer;transition:box-shadow .2s,transform .15s;
  display:flex;flex-direction:column;}
.card:hover{box-shadow:0 4px 16px rgba(0,0,0,.1);transform:translateY(-2px);}
.card-img{aspect-ratio:1;background:#f9fafb;display:flex;align-items:center;
  justify-content:center;overflow:hidden;position:relative;}
.card-img img{width:100%;height:100%;object-fit:contain;padding:10px;}
.card-img-ph{font-size:38px;opacity:.3;}
.badge-off{position:absolute;top:8px;left:8px;background:#ef4444;color:#fff;
  font-size:11px;font-weight:800;padding:2px 7px;border-radius:20px;}
.badge-star{position:absolute;top:8px;right:8px;background:#f59e0b;color:#fff;
  font-size:11px;font-weight:800;padding:2px 7px;border-radius:20px;}
.card-body{padding:12px;flex:1;display:flex;flex-direction:column;}
.card-cat{font-size:10px;font-weight:700;color:var(--p);text-transform:uppercase;
  letter-spacing:.05em;margin-bottom:3px;}
.card-name{font-size:13px;font-weight:700;line-height:1.3;margin-bottom:6px;flex:1;}
.card-desc{font-size:11.5px;color:#6b7280;margin-bottom:6px;line-height:1.4;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.price-wrap{display:flex;align-items:baseline;gap:5px;margin-bottom:10px;}
.price{font-size:17px;font-weight:800;color:var(--p);}
.price-old{font-size:12px;color:#9ca3af;text-decoration:line-through;}
.actions{display:flex;gap:6px;}
.btn-wa{flex:1;padding:8px;background:#25D366;color:#fff;border:none;border-radius:8px;
  font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;
  justify-content:center;gap:4px;}
.btn-wa:hover{background:#20b858;}
.btn-add{flex:1;padding:8px;background:var(--p);color:#fff;border:none;border-radius:8px;
  font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;
  justify-content:center;gap:4px;transition:.15s;}
.btn-add:hover{filter:brightness(.88);}
.btn-add.added{background:#16a34a;}
/* CARRITO DRAWER */
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:500;}
.overlay.open{display:block;}
.drawer{position:fixed;top:0;right:-410px;bottom:0;width:410px;max-width:100vw;
  background:#fff;z-index:501;box-shadow:-4px 0 24px rgba(0,0,0,.12);
  display:flex;flex-direction:column;transition:right .3s ease;}
.drawer.open{right:0;}
.drawer-head{display:flex;align-items:center;justify-content:space-between;
  padding:16px 20px;border-bottom:1px solid #e5e7eb;}
.drawer-title{font-size:16px;font-weight:800;}
.drawer-close{background:none;border:none;cursor:pointer;font-size:20px;color:#9ca3af;padding:4px;}
.drawer-items{flex:1;overflow-y:auto;padding:12px 16px;}
.empty-cart{text-align:center;padding:40px 20px;color:#9ca3af;}
.ci{display:flex;gap:10px;padding:12px 0;border-bottom:1px solid #f3f4f6;align-items:flex-start;}
.ci:last-child{border-bottom:none;}
.ci-img{width:56px;height:56px;border-radius:8px;border:1px solid #e5e7eb;
  object-fit:contain;background:#f9fafb;padding:3px;flex-shrink:0;}
.ci-ph{width:56px;height:56px;border-radius:8px;background:#f3f4f6;
  display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}
.ci-info{flex:1;}
.ci-name{font-size:13px;font-weight:600;margin-bottom:2px;line-height:1.3;}
.ci-pu{font-size:11px;color:#9ca3af;}
.ci-ctrl{display:flex;align-items:center;gap:5px;margin-top:6px;}
.qty-btn{width:26px;height:26px;border-radius:6px;border:1px solid #e5e7eb;
  background:#f9fafb;font-size:14px;font-weight:700;cursor:pointer;}
.qty-btn:hover{background:#e5e7eb;}
.qty-n{font-size:13px;font-weight:700;min-width:22px;text-align:center;}
.btn-rm{background:none;border:none;cursor:pointer;font-size:11px;color:#ef4444;
  font-weight:600;padding:0;margin-left:6px;}
.ci-sub{font-size:14px;font-weight:800;color:var(--p);flex-shrink:0;}
.drawer-foot{padding:14px 18px;border-top:1px solid #e5e7eb;}
.cart-sum{background:#f9fafb;border-radius:10px;padding:10px 14px;margin-bottom:10px;}
.cr{display:flex;justify-content:space-between;font-size:13px;margin-bottom:3px;}
.cr.total{font-size:15px;font-weight:800;margin-top:6px;padding-top:6px;
  border-top:1px solid #e5e7eb;margin-bottom:0;}
.btn-wap{width:100%;padding:13px;background:#25D366;color:#fff;border:none;
  border-radius:10px;font-size:14px;font-weight:800;cursor:pointer;
  display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:8px;}
.btn-wap:hover{background:#20b858;}
.btn-wap:disabled{opacity:.5;cursor:not-allowed;}
.btn-vac{width:100%;padding:9px;background:#fff;color:#ef4444;
  border:1px solid #fca5a5;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;}
.btn-vac:hover{background:#fef2f2;}
/* MODAL */
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);
  z-index:600;align-items:center;justify-content:center;padding:20px;}
.modal.open{display:flex;}
.modal-box{background:#fff;border-radius:16px;width:100%;max-width:600px;
  max-height:88vh;overflow-y:auto;padding:24px;}
.modal-close{float:right;background:none;border:none;cursor:pointer;
  font-size:20px;color:#9ca3af;padding:2px;}
/* WA FLOAT */
.wa-float{position:fixed;bottom:22px;right:22px;z-index:400;width:50px;height:50px;
  border-radius:50%;background:#25D366;color:#fff;border:none;cursor:pointer;
  display:flex;align-items:center;justify-content:center;
  box-shadow:0 4px 14px rgba(37,211,102,.4);}
.wa-float:hover{transform:scale(1.08);}
/* RESPONSIVE */
@media(max-width:768px){
  .sidebar{display:none;}
  .content{padding:14px;}
  .grid{grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:10px;}
  .banner-overlay{padding:20px 24px;}
  .banner-overlay h2{font-size:18px;}
  .drawer{width:100%;}
}
@media(max-width:480px){
  .grid{grid-template-columns:1fr 1fr;gap:8px;}
  .topbar{padding:0 12px;}
}
</style>
</head>
<body>

<!-- TOPBAR -->
<header class="topbar">
  <div class="brand-name"><?= htmlspecialchars($empresa) ?></div>
  <form class="search-form" method="GET">
    <?php if($catId): ?><input type="hidden" name="cat" value="<?= $catId ?>"/><?php endif; ?>
    <input type="text" name="q" placeholder="Buscar productos..."
           value="<?= htmlspecialchars($buscar) ?>"/>
  </form>
  <button class="cart-btn" onclick="abrirCarrito()">
    🛒 Carrito
    <span class="cart-badge" id="cart-badge">0</span>
  </button>
</header>

<!-- CHIPS MOBILE -->
<div class="chips">
  <a href="?" class="chip <?= !$catId?'active':'' ?>">Todos</a>
  <?php foreach($categorias as $c): ?>
  <a href="?cat=<?= $c['id'] ?><?= $buscar?'&q='.urlencode($buscar):'' ?>"
     class="chip <?= $catId==$c['id']?'active':'' ?>">
    <?= htmlspecialchars($c['nombre']) ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- BANNER -->
<div class="banner-wrap">
  <?php if(!empty($banners)): ?>
  <?php foreach($banners as $i=>$b): ?>
  <div class="banner-slide <?= $i===0?'active':'' ?>">
    <img src="<?= UPLOAD_URL ?>banners/<?= htmlspecialchars($b['imagen']) ?>"
         alt="<?= htmlspecialchars($b['titulo']??'') ?>"
         onerror="this.parentElement.style.display='none'"/>
    <?php if($b['titulo']||$b['subtitulo']): ?>
    <div class="banner-overlay">
      <?php if($b['titulo']): ?><h2><?= htmlspecialchars($b['titulo']) ?></h2><?php endif; ?>
      <?php if($b['subtitulo']): ?><p><?= htmlspecialchars($b['subtitulo']) ?></p><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if(count($banners)>1): ?>
  <div class="banner-dots">
    <?php for($i=0;$i<count($banners);$i++): ?>
    <button class="banner-dot <?= $i===0?'active':'' ?>" data-s="<?= $i ?>"></button>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
  <?php else: ?>
  <div class="banner-ph">
    <h2><?= htmlspecialchars($empresa) ?></h2>
    <p>Encuentra los mejores productos para tu equipo</p>
  </div>
  <?php endif; ?>
</div>

<!-- LAYOUT -->
<div class="page">
  <!-- SIDEBAR desktop -->
  <aside class="sidebar">
    <div class="sidebar-title">Categorías</div>
    <button class="scat <?= !$catId?'active':'' ?>" onclick="window.location='?'">
      Todos <span><?= count($productos) ?></span>
    </button>
    <?php foreach($categorias as $c): ?>
    <button class="scat <?= $catId==$c['id']?'active':'' ?>"
            onclick="window.location='?cat=<?= $c['id'] ?>'">
      <?= htmlspecialchars($c['nombre']) ?>
      <span><?= $c['total'] ?></span>
    </button>
    <?php endforeach; ?>
  </aside>

  <!-- PRODUCTS -->
  <main class="content">
    <div class="sect-head">
      <div class="sect-title"><?= $catId ? htmlspecialchars(array_column($categorias,'nombre','id')[$catId]??'Productos') : ($buscar?'Resultados: '.htmlspecialchars($buscar):'Todos los productos') ?></div>
      <div class="sect-count"><?= count($productos) ?> producto(s)</div>
    </div>

    <?php if(empty($productos)): ?>
    <div style="text-align:center;padding:60px 20px;color:#9ca3af">
      <div style="font-size:48px;margin-bottom:12px">🔍</div>
      <div style="font-size:15px;font-weight:600">Sin productos disponibles</div>
      <div style="font-size:12px;margin-top:4px">Activa productos en el panel de administración</div>
    </div>
    <?php else: ?>
    <div class="grid">
      <?php foreach($productos as $p):
        $fotos     = json_decode($p['fotos_catalogo']??'[]',true)?:[];
        $img       = $fotos[0]??null;
        $tieneOft  = !empty($p['precio_oferta']) && (float)$p['precio_oferta'] < (float)$p['precio_venta'];
        $pFinal    = $tieneOft ? (float)$p['precio_oferta'] : (float)$p['precio_venta'];
        $pctDto    = $tieneOft ? round((1-$pFinal/$p['precio_venta'])*100) : 0;
        $jsObj     = json_encode([
          'id'=>(int)$p['id'],'nombre'=>$p['nombre'],'precio'=>$pFinal,
          'cat'=>$p['cat_nombre'],'img'=>$img?UPLOAD_URL.'catalogo/'.$img:'',
          'desc'=>$p['descripcion']??'','stock'=>(int)$p['stock_actual']
        ],JSON_UNESCAPED_UNICODE);
      ?>
      <div class="card" onclick="verProd(<?= htmlspecialchars($jsObj,ENT_QUOTES) ?>)">
        <div class="card-img">
          <?php if($img): ?>
          <img src="<?= UPLOAD_URL ?>catalogo/<?= htmlspecialchars($img) ?>"
               alt="<?= htmlspecialchars($p['nombre']) ?>" loading="lazy"
               onerror="this.style.display='none';this.nextSibling.style.display='flex'"/>
          <div class="card-img-ph" style="display:none">📦</div>
          <?php else: ?>
          <div class="card-img-ph">📦</div>
          <?php endif; ?>
          <?php if($pctDto): ?><span class="badge-off">-<?= $pctDto ?>%</span><?php endif; ?>
          <?php if($p['destacado']): ?><span class="badge-star">⭐</span><?php endif; ?>
        </div>
        <div class="card-body">
          <div class="card-cat"><?= htmlspecialchars($p['cat_nombre']) ?></div>
          <div class="card-name"><?= htmlspecialchars($p['nombre']) ?></div>
          <?php if(!empty($p['descripcion'])): ?>
          <div class="card-desc"><?= htmlspecialchars($p['descripcion']) ?></div>
          <?php endif; ?>
          <div class="price-wrap">
            <span class="price">S/ <?= number_format($pFinal,2) ?></span>
            <?php if($tieneOft): ?><span class="price-old">S/ <?= number_format($p['precio_venta'],2) ?></span><?php endif; ?>
          </div>
          <div class="actions" onclick="event.stopPropagation()">
            <?php if($waNumero): ?>
            <button class="btn-wa" onclick="pedirWA(<?= htmlspecialchars(json_encode(['n'=>$p['nombre'],'p'=>$pFinal],JSON_UNESCAPED_UNICODE),ENT_QUOTES) ?>)">
              <svg width="12" height="12" fill="currentColor" viewBox="0 0 16 16"><path d="M13.601 2.326A7.854 7.854 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.933 7.933 0 0 0 3.79.965h.004c4.368 0 7.926-3.558 7.93-7.93A7.898 7.898 0 0 0 13.6 2.326zM7.994 14.521a6.573 6.573 0 0 1-3.356-.92l-.24-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.557 6.557 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592z"/></svg>
              WhatsApp
            </button>
            <?php endif; ?>
            <button class="btn-add" onclick="addCart(<?= htmlspecialchars($jsObj,ENT_QUOTES) ?>,this)">
              🛒 Añadir
            </button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </main>
</div>

<!-- CARRITO DRAWER -->
<div class="overlay" id="overlay" onclick="cerrarCarrito()"></div>
<div class="drawer" id="drawer">
  <div class="drawer-head">
    <div class="drawer-title">🛒 Carrito</div>
    <button class="drawer-close" onclick="cerrarCarrito()">✕</button>
  </div>
  <div class="drawer-items" id="drawer-items">
    <div class="empty-cart">🛒<br><br>Tu carrito está vacío</div>
  </div>
  <div class="drawer-foot">
    <div class="cart-sum" id="cart-sum" style="display:none">
      <div class="cr"><span>Ítems</span><span id="cs-items">0</span></div>
      <div class="cr total"><span>Total</span><span id="cs-total">S/ 0.00</span></div>
    </div>
    <button class="btn-wap" id="btn-wap" onclick="pedirCarrito()" disabled>
      <svg width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path d="M13.601 2.326A7.854 7.854 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.933 7.933 0 0 0 3.79.965h.004c4.368 0 7.926-3.558 7.93-7.93A7.898 7.898 0 0 0 13.6 2.326zM7.994 14.521a6.573 6.573 0 0 1-3.356-.92l-.24-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.557 6.557 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592z"/></svg>
      Pedir por WhatsApp
    </button>
    <button class="btn-vac" onclick="vaciarCarrito()">Vaciar carrito</button>
  </div>
</div>

<!-- MODAL PRODUCTO -->
<div class="modal" id="modal" onclick="if(event.target===this)cerrarModal()">
  <div class="modal-box" id="modal-box"></div>
</div>

<?php if($waNumero): ?>
<button class="wa-float" onclick="pedirWA(null)" title="Contáctanos">
  <svg width="26" height="26" fill="currentColor" viewBox="0 0 24 24"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91C21.95 6.45 17.5 2 12.04 2zm5.52 14.11c-.24.67-1.39 1.31-1.9 1.37-.49.06-1.09.08-1.76-.11-.41-.12-.93-.28-1.61-.56-2.79-1.22-4.62-4.03-4.76-4.22-.14-.19-1.14-1.52-1.14-2.9 0-1.38.72-2.06 1-2.35.24-.26.53-.33.7-.33l.5.01c.16 0 .37-.06.58.44l.83 2.02c.08.19.13.41.02.65l-.38.78c-.1.2-.2.41-.09.65.56 1.01 1.37 1.88 2.38 2.53.25.16.56.15.73-.06l.47-.6c.18-.22.42-.33.67-.22l2 .92c.25.12.42.18.48.27.06.09.06.53-.18 1.2z"/></svg>
</button>
<?php endif; ?>

<script>
const WA  = <?= json_encode($waNumero, JSON_UNESCAPED_UNICODE) ?>;
const EMP = <?= json_encode($empresa,  JSON_UNESCAPED_UNICODE) ?>;
let carrito = JSON.parse(localStorage.getItem('tr_cat')||'[]');

function h(s){return(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// CARRITO
function addCart(p, btn) {
  const i = carrito.findIndex(x=>x.id===p.id);
  if(i>=0) carrito[i].qty++; else carrito.push({...p,qty:1});
  localStorage.setItem('tr_cat',JSON.stringify(carrito));
  renderCarrito();
  if(btn){btn.classList.add('added');btn.textContent='✓ Añadido';
    setTimeout(()=>{btn.classList.remove('added');btn.textContent='🛒 Añadir';},1400);}
  abrirCarrito();
}
function cambiarQty(id,d){
  const i=carrito.findIndex(x=>x.id===id); if(i<0)return;
  carrito[i].qty=Math.max(1,carrito[i].qty+d);
  localStorage.setItem('tr_cat',JSON.stringify(carrito)); renderCarrito();
}
function quitarItem(id){
  carrito=carrito.filter(x=>x.id!==id);
  localStorage.setItem('tr_cat',JSON.stringify(carrito)); renderCarrito();
}
function vaciarCarrito(){
  if(!carrito.length||confirm('¿Vaciar carrito?')){
    carrito=[];localStorage.setItem('tr_cat','[]');renderCarrito();
  }
}
function renderCarrito(){
  const di=document.getElementById('drawer-items');
  const badge=document.getElementById('cart-badge');
  const total=carrito.reduce((s,i)=>s+i.precio*i.qty,0);
  const items=carrito.reduce((s,i)=>s+i.qty,0);
  badge.textContent=items>99?'99+':items;
  badge.style.display=items?'flex':'none';
  if(!carrito.length){
    di.innerHTML='<div class="empty-cart">🛒<br><br>Tu carrito está vacío</div>';
    document.getElementById('cart-sum').style.display='none';
    document.getElementById('btn-wap').disabled=true;
    return;
  }
  di.innerHTML=carrito.map(it=>`
    <div class="ci">
      ${it.img?`<img class="ci-img" src="${h(it.img)}" onerror="this.style.display='none'">`:`<div class="ci-ph">📦</div>`}
      <div class="ci-info">
        <div class="ci-name">${h(it.nombre)}</div>
        <div class="ci-pu">S/ ${it.precio.toFixed(2)} c/u</div>
        <div class="ci-ctrl">
          <button class="qty-btn" onclick="cambiarQty(${it.id},-1)">−</button>
          <span class="qty-n">${it.qty}</span>
          <button class="qty-btn" onclick="cambiarQty(${it.id},1)">+</button>
          <button class="btn-rm" onclick="quitarItem(${it.id})">Quitar</button>
        </div>
      </div>
      <div class="ci-sub">S/ ${(it.precio*it.qty).toFixed(2)}</div>
    </div>`).join('');
  document.getElementById('cart-sum').style.display='';
  document.getElementById('cs-items').textContent=items;
  document.getElementById('cs-total').textContent='S/ '+total.toFixed(2);
  document.getElementById('btn-wap').disabled=false;
}
function abrirCarrito(){
  document.getElementById('drawer').classList.add('open');
  document.getElementById('overlay').classList.add('open');
  document.body.style.overflow='hidden';
}
function cerrarCarrito(){
  document.getElementById('drawer').classList.remove('open');
  document.getElementById('overlay').classList.remove('open');
  document.body.style.overflow='';
}

// WHATSAPP
function pedirWA(p){
  if(!WA){alert('WhatsApp no configurado en ajustes.');return;}
  const msg=p?`Hola, me interesa *${p.n}* a S/ ${p.p.toFixed(2)} — ${EMP} 😊`
              :`Hola ${EMP}, quisiera consultar sobre sus productos 😊`;
  window.open('https://wa.me/'+WA+'?text='+encodeURIComponent(msg),'_blank');
}
function pedirCarrito(){
  if(!WA){alert('WhatsApp no configurado.');return;}
  if(!carrito.length)return;
  const tot=carrito.reduce((s,i)=>s+i.precio*i.qty,0);
  let msg=`🛒 *Pedido — ${EMP}*\n\n`;
  carrito.forEach((it,i)=>{ msg+=`${i+1}. ${it.nombre}\n   ${it.qty} × S/ ${it.precio.toFixed(2)} = *S/ ${(it.precio*it.qty).toFixed(2)}*\n`; });
  msg+=`\n💰 *TOTAL: S/ ${tot.toFixed(2)}*\n\n¿Confirman disponibilidad? Gracias 😊`;
  window.open('https://wa.me/'+WA+'?text='+encodeURIComponent(msg),'_blank');
}

// MODAL PRODUCTO
function verProd(p){
  document.getElementById('modal-box').innerHTML=`
    <button class="modal-close" onclick="cerrarModal()">✕</button>
    <div style="display:flex;gap:20px;flex-wrap:wrap">
      <div style="flex:1;min-width:180px">
        ${p.img?`<img src="${h(p.img)}" style="width:100%;border-radius:10px;border:1px solid #e5e7eb" />`
               :`<div style="background:#f3f4f6;border-radius:10px;height:180px;display:flex;align-items:center;justify-content:center;font-size:48px">📦</div>`}
      </div>
      <div style="flex:1;min-width:180px">
        <div style="font-size:10px;font-weight:700;color:var(--p);text-transform:uppercase;margin-bottom:6px">${h(p.cat)}</div>
        <div style="font-size:19px;font-weight:800;margin-bottom:8px;line-height:1.2">${h(p.nombre)}</div>
        ${p.desc?`<div style="font-size:13px;color:#6b7280;line-height:1.5;margin-bottom:12px">${h(p.desc)}</div>`:''}
        <div style="font-size:24px;font-weight:800;color:var(--p);margin-bottom:12px">S/ ${p.precio.toFixed(2)}</div>
        <div style="font-size:12px;color:#6b7280;margin-bottom:14px">
          ${p.stock>0?`<span style="color:#16a34a;font-weight:600">✓ En stock (${p.stock})</span>`:`<span style="color:#dc2626;font-weight:600">Sin stock</span>`}
        </div>
        <div style="display:flex;gap:8px">
          ${WA?`<button class="btn-wa" style="flex:1;padding:11px;font-size:13px" onclick="pedirWA({n:'${p.nombre.replace(/'/g,"\\'")}',p:${p.precio}})">WhatsApp</button>`:''}
          <button class="btn-add" style="flex:1;padding:11px;font-size:13px" onclick="addCart(${JSON.stringify(p).replace(/"/g,'&quot;')},null);cerrarModal()">Añadir al carrito</button>
        </div>
      </div>
    </div>`;
  document.getElementById('modal').classList.add('open');
  document.body.style.overflow='hidden';
}
function cerrarModal(){
  document.getElementById('modal').classList.remove('open');
  document.body.style.overflow='';
}

// BANNER SLIDER
(function(){
  const slides=document.querySelectorAll('.banner-slide');
  const dots=document.querySelectorAll('.banner-dot');
  if(slides.length<=1)return;
  let cur=0;
  function go(n){
    slides[cur].classList.remove('active');dots[cur]?.classList.remove('active');
    cur=(n+slides.length)%slides.length;
    slides[cur].classList.add('active');dots[cur]?.classList.add('active');
  }
  dots.forEach(d=>d.addEventListener('click',()=>go(parseInt(d.dataset.s))));
  setInterval(()=>go(cur+1),4500);
})();

renderCarrito();
</script>
</body>
</html>
