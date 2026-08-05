<?php
session_start();
if(!isset($_SESSION['usuario_id']) || $_SESSION['rol'] != 'admin') {
    header('Location: ../index.php'); exit;
}
require_once '../config.php';
$conn = getConnection();

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $d = json_decode(file_get_contents('php://input'), true);
    $accion = $d['accion'] ?? '';
    try {
        if($accion === 'crear') {
            // Check duplicado
            $ck = $conn->prepare("SELECT id FROM usuarios WHERE usuario=?");
            $ck->execute([$d['usuario']]);
            if($ck->fetch()) { echo json_encode(['success'=>false,'error'=>'El usuario ya existe']); exit; }
            $stmt = $conn->prepare("INSERT INTO usuarios (nombre, usuario, contrasena, rol) VALUES (?,?,?,?)");
            $stmt->execute([$d['nombre'], $d['usuario'], password_hash($d['contrasena'], PASSWORD_DEFAULT), $d['rol']]);
            echo json_encode(['success'=>true]);
        } elseif($accion === 'editar') {
            if(!empty($d['contrasena'])) {
                $stmt = $conn->prepare("UPDATE usuarios SET nombre=?, usuario=?, contrasena=?, rol=? WHERE id=?");
                $stmt->execute([$d['nombre'], $d['usuario'], password_hash($d['contrasena'], PASSWORD_DEFAULT), $d['rol'], $d['id']]);
            } else {
                $stmt = $conn->prepare("UPDATE usuarios SET nombre=?, usuario=?, rol=? WHERE id=?");
                $stmt->execute([$d['nombre'], $d['usuario'], $d['rol'], $d['id']]);
            }
            echo json_encode(['success'=>true]);
        } elseif($accion === 'eliminar') {
            if((int)$d['id'] === (int)$_SESSION['usuario_id']) {
                echo json_encode(['success'=>false,'error'=>'No puedes eliminarte a ti mismo']); exit;
            }
            $stmt = $conn->prepare("DELETE FROM usuarios WHERE id=?");
            $stmt->execute([$d['id']]);
            echo json_encode(['success'=>true]);
        } else {
            echo json_encode(['success'=>false,'error'=>'Acción desconocida']);
        }
    } catch(PDOException $e) {
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

$usuarios = $conn->query("SELECT id, nombre, usuario, rol FROM usuarios ORDER BY rol, nombre")->fetchAll();
$roles = ['admin'=>'Admin','camarero'=>'Camarero','cocina'=>'Cocina'];
$rolBadge = ['admin'=>'bdg-pur','camarero'=>'bdg-blu','cocina'=>'bdg-org'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Usuarios · Pizza Yaja ERP</title>
</head>
<body class="erp">
<?php include 'includes/sidebar.php'; ?>

<div class="erp-main">
  <div class="erp-topbar">
    <button class="erp-hbg" onclick="erpHbg()">☰</button>
    <span class="erp-pg-title">👥 Usuarios</span>
    <button class="eb ora erp-topbar-right" onclick="abrirModal()">+ Nuevo usuario</button>
  </div>

  <div class="erp-content">
    <div class="ec">
      <div class="ec-head">Usuarios del sistema</div>
      <div class="ec-body np">
        <table class="et">
          <thead><tr><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Acciones</th></tr></thead>
          <tbody>
          <?php foreach($usuarios as $u): ?>
            <tr>
              <td><strong><?= htmlspecialchars($u['nombre']) ?></strong></td>
              <td style="color:#888"><?= htmlspecialchars($u['usuario']) ?></td>
              <td><span class="bdg <?= $rolBadge[$u['rol']] ?? 'bdg-gry' ?>"><?= $roles[$u['rol']] ?? $u['rol'] ?></span></td>
              <td style="display:flex;gap:6px">
                <button class="eb gry" style="padding:5px 10px;font-size:12px"
                  onclick='editarUsuario(<?= json_encode(['id'=>$u['id'],'nombre'=>$u['nombre'],'usuario'=>$u['usuario'],'rol'=>$u['rol']]) ?>)'>Editar</button>
                <?php if($u['id'] != $_SESSION['usuario_id']): ?>
                  <button class="eb red" style="padding:5px 10px;font-size:12px"
                    onclick="eliminarUsuario(<?= $u['id'] ?>, '<?= htmlspecialchars($u['nombre']) ?>')">Eliminar</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Modal -->
<div id="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;width:90%;max-width:420px">
    <div style="padding:16px 20px;border-bottom:1px solid #eee;font-weight:700;font-size:15px;display:flex;justify-content:space-between">
      <span id="mTitle">Nuevo usuario</span>
      <button onclick="cerrar()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <div style="padding:20px">
      <input type="hidden" id="uId">
      <div class="ef-group">
        <label class="ef-label">Nombre completo</label>
        <input class="ef" id="uNombre" placeholder="Juan Pérez">
      </div>
      <div class="ef-group">
        <label class="ef-label">Usuario (login)</label>
        <input class="ef" id="uUsuario" placeholder="juan.perez">
      </div>
      <div class="ef-group">
        <label class="ef-label" id="passLabel">Contraseña</label>
        <input class="ef" type="password" id="uPass" placeholder="Dejar vacío para no cambiar">
      </div>
      <div class="ef-group">
        <label class="ef-label">Rol</label>
        <select class="ef" id="uRol">
          <option value="camarero">Camarero</option>
          <option value="cocina">Cocina</option>
          <option value="admin">Admin</option>
        </select>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:6px">
        <button class="eb gry" onclick="cerrar()">Cancelar</button>
        <button class="eb ora" onclick="guardar()">Guardar</button>
      </div>
    </div>
  </div>
</div>

<script>
function abrirModal(u) {
  document.getElementById('uId').value = u ? u.id : '';
  document.getElementById('uNombre').value = u ? u.nombre : '';
  document.getElementById('uUsuario').value = u ? u.usuario : '';
  document.getElementById('uPass').value = '';
  document.getElementById('uRol').value = u ? u.rol : 'camarero';
  document.getElementById('passLabel').textContent = u ? 'Nueva contraseña (dejar vacío = no cambiar)' : 'Contraseña';
  document.getElementById('mTitle').textContent = u ? 'Editar usuario' : 'Nuevo usuario';
  document.getElementById('modal').style.display = 'flex';
}
function editarUsuario(u) { abrirModal(u); }
function cerrar() { document.getElementById('modal').style.display = 'none'; }

function guardar() {
  const id = document.getElementById('uId').value;
  const body = {
    accion: id ? 'editar' : 'crear',
    id: id || undefined,
    nombre: document.getElementById('uNombre').value.trim(),
    usuario: document.getElementById('uUsuario').value.trim(),
    contrasena: document.getElementById('uPass').value,
    rol: document.getElementById('uRol').value,
  };
  if(!body.nombre || !body.usuario) { alert('Nombre y usuario son obligatorios'); return; }
  if(!id && !body.contrasena) { alert('La contraseña es obligatoria para un nuevo usuario'); return; }
  fetch('usuarios.php', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify(body)
  }).then(r => r.json()).then(d => {
    if(d.success) location.reload();
    else alert('Error: ' + d.error);
  });
}

function eliminarUsuario(id, nombre) {
  if(!confirm(`¿Eliminar al usuario "${nombre}"? Esta acción no se puede deshacer.`)) return;
  fetch('usuarios.php', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({accion:'eliminar', id})
  }).then(r => r.json()).then(d => {
    if(d.success) location.reload();
    else alert('Error: ' + d.error);
  });
}
</script>
</body>
</html>
