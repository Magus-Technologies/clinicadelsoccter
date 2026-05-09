<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';

if (isLoggedIn()) redirect(BASE_URL . 'modules/dashboard/index.php');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($email && $password) {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM usuarios WHERE email = ? AND activo = 1 LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id']     = $user['id'];
            $_SESSION['user_nombre'] = $user['nombre'];
            $_SESSION['user_rol']    = $user['rol'];
            $_SESSION['user_email']  = $user['email'];
            $db->prepare("UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?")->execute([$user['id']]);
            redirect(BASE_URL . 'modules/dashboard/index.php');
        } else {
            $error = 'Correo o contraseña incorrectos.';
        }
    } else {
        $error = 'Completa todos los campos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Iniciar sesión — <?= APP_NAME ?> | MAGUS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
    *, *::before, *::after { box-sizing: border-box; }

    body {
      font-family: 'Inter', sans-serif;
      min-height: 100vh;
      margin: 0;
      padding: 0;
      background: #eef4fb;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 16px;
    }

    /* ── Contenedor principal ── */
    .login-box {
      display: flex;
      width: 860px;
      max-width: 100%;
      min-height: 520px;
      border-radius: 20px;
      overflow: hidden;
      box-shadow: 0 12px 48px rgba(0,80,180,.18);
      animation: slideUp .5s cubic-bezier(.22,.68,0,1.2) both;
    }
    @keyframes slideUp {
      from { opacity:0; transform:translateY(28px); }
      to   { opacity:1; transform:translateY(0); }
    }

    /* ── Panel izquierdo azul ── */
    .panel-left {
      flex: 0 0 380px;
      background: linear-gradient(160deg, #1a7fe8 0%, #0d5bbf 50%, #0a3d8f 100%);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 40px 32px;
      position: relative;
      overflow: hidden;
    }
    /* Ondas decorativas */
    .panel-left::before {
      content: '';
      position: absolute;
      width: 320px; height: 320px;
      border-radius: 50%;
      background: rgba(255,255,255,.06);
      top: -80px; left: -80px;
    }
    .panel-left::after {
      content: '';
      position: absolute;
      width: 260px; height: 260px;
      border-radius: 50%;
      background: rgba(255,255,255,.05);
      bottom: -60px; right: -60px;
    }
    .panel-left-inner { position: relative; z-index: 1; text-align: center; }

    .hero-img {
      width: 200px; height: 200px;
      border-radius: 16px;
      object-fit: cover;
      border: 3px solid rgba(255,255,255,.3);
      box-shadow: 0 8px 32px rgba(0,0,0,.2);
      margin-bottom: 24px;
    }
    .hero-welcome {
      color: rgba(255,255,255,.8);
      font-size: 13px;
      font-weight: 500;
      letter-spacing: .5px;
      text-transform: uppercase;
      margin-bottom: 6px;
    }
    .hero-title {
      color: #fff;
      font-size: 1.6rem;
      font-weight: 800;
      line-height: 1.2;
      margin-bottom: 12px;
    }
    .hero-sub {
      color: rgba(255,255,255,.65);
      font-size: 12.5px;
      line-height: 1.6;
    }

    /* ── Panel derecho blanco ── */
    .panel-right {
      flex: 1;
      background: #fff;
      display: flex;
      flex-direction: column;
      justify-content: center;
      padding: 48px 44px 36px;
      position: relative;
      overflow: hidden;
    }

    /* Borde neon sutil — solo en el panel derecho */
    .neon-border {
      position: absolute;
      inset: 0;
      pointer-events: none;
      z-index: 0;
      transform: translateZ(0);
    }
    .neon-line-1 {
      fill: none;
      stroke: #b8d8f0;
      stroke-width: 1.5;
      stroke-linecap: round;
      filter: drop-shadow(0 0 2px rgba(0,120,220,.2));
      stroke-dasharray: 70 9999;
      stroke-dashoffset: 0;
      animation: runBorder1 4s linear infinite;
      will-change: stroke-dashoffset;
    }
    .neon-line-2 {
      fill: none;
      stroke: #d4eaf8;
      stroke-width: 1;
      stroke-linecap: round;
      filter: drop-shadow(0 0 2px rgba(0,150,220,.15));
      stroke-dasharray: 50 9999;
      stroke-dashoffset: -900;
      animation: runBorder2 4s linear infinite;
      will-change: stroke-dashoffset;
    }
    @keyframes runBorder1 { from{stroke-dashoffset:0} to{stroke-dashoffset:-1800} }
    @keyframes runBorder2 { from{stroke-dashoffset:-900} to{stroke-dashoffset:-2700} }

    .panel-right-inner { position: relative; z-index: 1; }

    .form-title {
      font-size: 1.5rem; font-weight: 800;
      color: #0a1a2e;
      margin-bottom: 6px;
    }
    .form-title span { color: #1a7fe8; }
    .form-subtitle {
      color: rgba(0,60,130,.55);
      font-size: 13px;
      margin-bottom: 28px;
    }

    .form-label {
      color: rgba(0,60,130,.75);
      font-size: 12px;
      font-weight: 600;
      letter-spacing: .4px;
      text-transform: uppercase;
      margin-bottom: 6px;
    }
    .input-group-text {
      background: #f0f6ff;
      border: 1px solid #c8ddf5;
      border-right: none;
      color: #5599cc;
      transition: all .2s;
    }
    .form-control {
      background: #f7faff;
      border: 1px solid #c8ddf5;
      border-left: none;
      color: #0a1a2e;
      font-size: 14px;
      transition: border-color .2s, box-shadow .2s;
    }
    .form-control::placeholder { color: rgba(0,80,160,.25); }
    .form-control:focus {
      background: #fff;
      border-color: #1a7fe8;
      box-shadow: 0 0 0 3px rgba(26,127,232,.1);
      color: #0a1a2e;
      outline: none;
    }
    .input-group:focus-within .input-group-text {
      border-color: #1a7fe8;
      color: #1a7fe8;
      background: #e8f2ff;
    }
    .btn-outline-secondary {
      background: #f0f6ff;
      border: 1px solid #c8ddf5;
      border-left: none;
      color: #5599cc;
      transition: all .2s;
    }
    .btn-outline-secondary:hover,
    .input-group:focus-within .btn-outline-secondary {
      background: #e8f2ff;
      color: #1a7fe8;
      border-color: #1a7fe8;
    }

    .btn-login {
      width: 100%;
      padding: 13px;
      font-weight: 700;
      font-size: 14.5px;
      letter-spacing: .3px;
      border: none;
      border-radius: 10px;
      background: linear-gradient(135deg, #0d5bbf 0%, #1a7fe8 100%);
      color: #fff;
      cursor: pointer;
      transition: transform .15s, box-shadow .2s;
      box-shadow: 0 4px 20px rgba(26,127,232,.35);
    }
    .btn-login:hover {
      transform: translateY(-1px);
      box-shadow: 0 6px 28px rgba(26,127,232,.5);
    }
    .btn-login:active { transform: translateY(0); }

    .alert-danger {
      background: rgba(220,50,50,.07);
      border: 1px solid rgba(200,60,60,.2);
      color: #c0392b;
      border-radius: 8px;
      font-size: 13px;
    }

    /* ── Footer ── */
    .login-footer {
      text-align: center;
      margin-top: 20px;
      font-size: 12px;
      color: #555;
      line-height: 1.7;
    }
    .login-footer a {
      color: #0d5bbf;
      font-weight: 600;
      text-decoration: none;
    }
    .login-footer a:hover { text-decoration: underline; }

    /* Responsive */
    .login-box { max-width: 860px; width: 100%; }

    @media (max-width: 700px) {
      body {
        background: linear-gradient(160deg, #1a7fe8 0%, #0d5bbf 60%, #0a3d8f 100%);
        justify-content: center;
        align-items: center;
        padding: 24px 16px;
        gap: 0;
      }
      .login-footer-ext-wrap { display: none; }
      .login-box {
        flex-direction: column;
        width: 100%;
        max-width: 400px;
        min-height: auto;
        border-radius: 20px;
        box-shadow: 0 8px 40px rgba(0,0,0,.25);
      }
      .panel-left {
        flex: none;
        padding: 28px 24px 20px;
      }
      .hero-img {
        width: 120px; height: 120px;
        margin-bottom: 12px;
        border-radius: 50%;
      }
      .hero-title { font-size: 1.3rem; }
      .hero-welcome { font-size: 11px; }
      .hero-sub { font-size: 12px; }
      .panel-right {
        flex: none;
        padding: 28px 24px 32px;
        border-radius: 0;
        margin-top: 0;
        justify-content: flex-start;
      }
      .form-title { font-size: 1.2rem; }
      .form-subtitle { margin-bottom: 20px; }
    }
  </style>
</head>
<body>

<div class="login-box">

  <!-- Panel izquierdo -->
  <div class="panel-left">
    <div class="panel-left-inner">
      <img src="<?= BASE_URL ?>assets/img/login-hero.jpeg" alt="TechRepair" class="hero-img"/>
      <div class="hero-welcome">Bienvenido de nuevo</div>
      <div class="hero-title"><?= APP_NAME ?></div>
      <div class="hero-sub">Sistema de gestión técnica<br>para reparación de equipos electrónicos</div>
    </div>
  </div>

  <!-- Panel derecho -->
  <div class="panel-right">

    <!-- Borde neon sutil -->
    <svg class="neon-border" viewBox="0 0 480 520" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
      <rect x="1" y="1" width="478" height="518" rx="0" ry="0" fill="none" stroke="rgba(0,120,220,.08)" stroke-width="1"/>
      <rect class="neon-line-1" x="1" y="1" width="478" height="518" rx="0" ry="0"/>
      <rect class="neon-line-2" x="1" y="1" width="478" height="518" rx="0" ry="0"/>
    </svg>

    <div class="panel-right-inner">
      <div class="form-title"><?= APP_NAME ?> <span>| MAGUS</span></div>
      <div class="form-subtitle">Sistema de gestión técnica</div>

      <?php if ($error): ?>
      <div class="alert alert-danger py-2 small mb-3"><?= sanitize($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="mb-3">
          <label class="form-label">Correo electrónico</label>
          <div class="input-group">
            <span class="input-group-text"><i data-feather="mail" style="width:15px;height:15px"></i></span>
            <input type="email" name="email" class="form-control"
                   value="<?= sanitize($_POST['email'] ?? '') ?>"
                   placeholder="correo@ejemplo.com" required autofocus/>
          </div>
        </div>
        <div class="mb-4">
          <label class="form-label">Contraseña</label>
          <div class="input-group">
            <span class="input-group-text"><i data-feather="lock" style="width:15px;height:15px"></i></span>
            <input type="password" name="password" class="form-control" placeholder="••••••••" required/>
            <button type="button" class="btn btn-outline-secondary" id="btn-show-pass">
              <i data-feather="eye" style="width:15px;height:15px"></i>
            </button>
          </div>
        </div>
        <button type="submit" class="btn-login">Ingresar al sistema</button>
      </form>

      <div class="login-footer" style="margin-top:16px">
      </div>
    </div>
  </div>

</div>

<!-- Footer debajo del card -->
<div style="text-align:center;margin-top:16px;font-size:12px;color:#222" class="login-footer-ext-wrap">
  <div id="footer-spinner-ext" style="padding:4px 0">
    <div style="width:20px;height:20px;border:2.5px solid rgba(0,0,0,.1);border-top:2.5px solid #222;border-radius:50%;animation:spinC .7s linear infinite;margin:0 auto"></div>
  </div>
  <div id="login-footer-ext" style="visibility:hidden;opacity:0;transition:opacity .4s">
    Desarrollado por <a href="https://magustechnologies.com/" target="_blank" style="color:#0d5bbf;font-weight:600;text-decoration:none">MAGUS TECHNOLOGIES</a>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
feather.replace();

document.getElementById('btn-show-pass').addEventListener('click', function() {
  const inp = this.previousElementSibling;
  inp.type = inp.type === 'password' ? 'text' : 'password';
});

window.addEventListener('load', function() {
  setTimeout(function() {
    document.getElementById('footer-spinner-ext').style.display = 'none';
    const t = document.getElementById('login-footer-ext');
    t.style.visibility = 'visible';
    t.style.opacity = '1';
  }, 1200);
});
</script>
</body>
</html>
