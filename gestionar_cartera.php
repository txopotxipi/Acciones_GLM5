<?php
// gestionar_cartera.php — v3
// Edita acciones, coste medio, fecha de compra y sector de cada valor.
// Protección: define GESTION_PASSWORD en config.php para exigir contraseña.
require_once 'config.php';
session_start();

$sectoresDisponibles = [
    'Energía' => '#10b981', 'Telecomunicaciones' => '#3b82f6', 'Banca' => '#f59e0b',
    'Petróleo' => '#ef4444', 'Media' => '#8b5cf6', 'Tecnología' => '#06b6d4',
    'Construcción' => '#f97316', 'Utilities' => '#22c55e', 'Otro' => '#64748b',
];

// ---------- Autenticación opcional ----------
$requiresAuth = defined('GESTION_PASSWORD') && GESTION_PASSWORD !== '';
$authError = '';
if ($requiresAuth) {
    if (isset($_GET['logout'])) { session_destroy(); header('Location: gestionar_cartera.php'); exit; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gestion_password'])) {
        if (hash_equals(GESTION_PASSWORD, $_POST['gestion_password'])) {
            $_SESSION['gestion_ok'] = true;
            header('Location: gestionar_cartera.php');
            exit;
        }
        $authError = 'Contraseña incorrecta';
    }
    if (empty($_SESSION['gestion_ok'])) {
        ?>
        <!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Acceso — Gestionar Cartera</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="style.css"></head>
        <body><div class="container" style="max-width:420px; margin-top:10vh;">
        <div class="card">
            <h2 style="margin-top:0;">🔒 Gestionar Cartera</h2>
            <?php if ($authError): ?><div class="flash-err"><?= htmlspecialchars($authError) ?></div><?php endif; ?>
            <form method="post">
                <input type="password" name="gestion_password" placeholder="Contraseña" required autofocus
                    style="width:100%; padding:0.6rem; border-radius:8px; border:1px solid var(--border, rgba(148,163,184,0.25)); background:var(--bg-card, rgba(255,255,255,0.04)); color:inherit; margin-bottom:0.75rem;">
                <button type="submit" class="btn btn-primary" style="width:100%;">Entrar</button>
            </form>
            <p style="font-size:0.75rem; color:#94a3b8; margin-top:1rem;"><a href="index.php" style="color:inherit;">← Volver a la cartera</a></p>
        </div></div></body></html>
        <?php
        exit;
    }
}

// ---------- Guardar cambios ----------
$flash = ''; $flashType = 'ok';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar'])) {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE valores SET
            num_acciones = :acciones,
            precio_medio_compra = :coste,
            fecha_compra = :fecha,
            sector = :sector,
            sector_color = :color
            WHERE id = :id");
        foreach ($_POST['valores'] ?? [] as $id => $v) {
            $sector = $_POST['valores'][$id]['sector'] ?? 'Otro';
            $stmt->execute([
                ':acciones' => max(0, (float)str_replace(',', '.', $v['num_acciones'] ?? '0')),
                ':coste'    => max(0, (float)str_replace(',', '.', $v['precio_medio_compra'] ?? '0')),
                ':fecha'    => !empty($v['fecha_compra']) ? $v['fecha_compra'] : null,
                ':sector'   => $sector,
                ':color'    => $sectoresDisponibles[$sector] ?? '#64748b',
                ':id'       => (int)$id,
            ]);
        }
        $pdo->commit();
        $flash = '✅ Cambios guardados correctamente.';
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash = '❌ Error al guardar: ' . $e->getMessage();
        $flashType = 'err';
    }
}

$valores = $pdo->query("SELECT * FROM valores WHERE ticker <> '^IBEX' ORDER BY (num_acciones * precio_actual) DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestionar Cartera — Cartera Pro GLM5</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="mejoras_v3.css">
</head>
<body>
<div class="container">
    <header>
        <div class="header-left">
            <h1>Gestionar <span>Cartera</span></h1>
            <div class="subtitle">Acciones, coste medio y sector de cada posición</div>
        </div>
        <div class="header-right">
            <a href="index.php" class="btn btn-secondary" style="text-decoration:none;">← Volver</a>
            <?php if ($requiresAuth): ?>
                <a href="gestionar_cartera.php?logout=1" class="btn btn-secondary" style="text-decoration:none;">Salir</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!$requiresAuth): ?>
        <div class="flash-err">⚠️ Esta página no tiene contraseña. Define <code>GESTION_PASSWORD</code> en config.php para protegerla.</div>
    <?php endif; ?>
    <?php if ($flash): ?>
        <div class="flash-<?= $flashType ?>"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <div class="card gestion-form">
        <form method="post" id="form-gestion">
            <div class="table-scroll-wrapper">
                <table class="gestion-table">
                    <thead>
                        <tr>
                            <th>Valor</th>
                            <th class="text-right">Acciones</th>
                            <th class="text-right">Coste medio (€)</th>
                            <th>Fecha compra</th>
                            <th>Sector</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($valores as $v): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($v['nombre']) ?></strong><br>
                                <small class="text-muted"><?= htmlspecialchars($v['ticker']) ?></small>
                            </td>
                            <td class="text-right">
                                <input type="number" step="1" min="0"
                                    name="valores[<?= $v['id'] ?>][num_acciones]"
                                    value="<?= (float)$v['num_acciones'] ?>">
                            </td>
                            <td class="text-right">
                                <input type="number" step="0.0001" min="0"
                                    name="valores[<?= $v['id'] ?>][precio_medio_compra]"
                                    value="<?= htmlspecialchars($v['precio_medio_compra'] ?? '') ?>"
                                    placeholder="0.0000">
                            </td>
                            <td>
                                <input type="date"
                                    name="valores[<?= $v['id'] ?>][fecha_compra]"
                                    value="<?= htmlspecialchars($v['fecha_compra'] ?? '') ?>">
                            </td>
                            <td>
                                <select name="valores[<?= $v['id'] ?>][sector]">
                                    <?php foreach ($sectoresDisponibles as $s => $c): ?>
                                        <option value="<?= $s ?>" <?= ($v['sector'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="gestion-save-bar">
                <a href="index.php" class="btn btn-secondary" style="text-decoration:none;">Cancelar</a>
                <button type="submit" name="guardar" value="1" class="btn btn-primary">💾 Guardar cambios</button>
            </div>
        </form>
    </div>

    <p style="font-size:0.75rem; color:#94a3b8; margin-top:1rem;">
        💡 El <strong>coste medio</strong> es el precio medio al que compraste tus acciones (con comisiones incluidas).
        Con ese dato, el panel calcula tu P&L real. Si compraste en varias tandas:
        <code>coste medio = importe total invertido ÷ acciones totales</code>.
    </p>
</div>
</body>
</html>
