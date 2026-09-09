<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id']) && !isset($_SESSION['id'])) {
    header('Location: index.php');
    exit;
}

$role = $_SESSION['role'] ?? 'admin';
$nom  = $_SESSION['nom'] ?? $_SESSION['username'] ?? 'Utilisateur';

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function argent($v){ return number_format((float)$v, 0, ',', ' ') . ' FG'; }

$message = '';
$type = 'success';

/* =========================
   ACTIONS
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* AJOUT */
    if ($action === 'ajouter') {
        $libelle = trim($_POST['libelle'] ?? '');
        $montant = (float)($_POST['montant'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $date = trim($_POST['date_recette'] ?? date('Y-m-d'));

        if ($libelle === '') {
            $message = 'Indique le libellé de la recette.';
            $type = 'error';
        } elseif ($montant <= 0) {
            $message = 'Le montant doit être supérieur à 0.';
            $type = 'error';
        } else {
            $nextId = 1;
            $q = $conn->query("SELECT COALESCE(MAX(id),0)+1 AS n FROM recettes");
            if ($q) $nextId = (int)$q->fetch_assoc()['n'];

            $st = $conn->prepare(
                "INSERT INTO recettes (id, libelle, montant, description, date_recette)
                 VALUES (?, ?, ?, ?, ?)"
            );

            if ($st) {
                $st->bind_param('isdss', $nextId, $libelle, $montant, $description, $date);
                if ($st->execute()) {
                    $message = 'Recette enregistrée avec succès.';
                } else {
                    $message = 'Impossible d’enregistrer la recette.';
                    $type = 'error';
                }
                $st->close();
            } else {
                $message = 'Erreur de préparation de la recette.';
                $type = 'error';
            }
        }
    }

    /* MODIFICATION */
    if ($action === 'modifier') {
        $id = (int)($_POST['id'] ?? 0);
        $libelle = trim($_POST['libelle'] ?? '');
        $montant = (float)($_POST['montant'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $date = trim($_POST['date_recette'] ?? date('Y-m-d'));

        if ($id <= 0 || $libelle === '' || $montant <= 0) {
            $message = 'Informations de recette invalides.';
            $type = 'error';
        } else {
            /* Les prestations sont gérées exclusivement dans prestations.php.
               On ne permet donc jamais de modifier une prestation ici. */
            $st = $conn->prepare(
                "SELECT id FROM recettes
                 WHERE id = ?
                   AND (libelle NOT LIKE 'Prestation DTF%'
                        AND libelle NOT LIKE '%Prestation DTF%')
                 LIMIT 1"
            );
            $st->bind_param('i', $id);
            $st->execute();
            $ok = $st->get_result()->fetch_assoc();
            $st->close();

            if (!$ok) {
                $message = 'Cette recette appartient à une prestation et doit être gérée depuis Prestations.';
                $type = 'error';
            } else {
                $st = $conn->prepare(
                    "UPDATE recettes
                     SET libelle=?, montant=?, description=?, date_recette=?
                     WHERE id=?"
                );
                $st->bind_param('sdssi', $libelle, $montant, $description, $date, $id);
                if ($st->execute()) {
                    $message = 'Recette modifiée.';
                } else {
                    $message = 'Modification impossible.';
                    $type = 'error';
                }
                $st->close();
            }
        }
    }

    /* SUPPRESSION */
    if ($action === 'supprimer') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {
            $st = $conn->prepare(
                "SELECT id FROM recettes
                 WHERE id = ?
                   AND libelle NOT LIKE 'Prestation DTF%'
                   AND libelle NOT LIKE '%Prestation DTF%'
                 LIMIT 1"
            );
            $st->bind_param('i', $id);
            $st->execute();
            $ok = $st->get_result()->fetch_assoc();
            $st->close();

            if (!$ok) {
                $message = 'Une prestation ne peut pas être supprimée depuis Recettes.';
                $type = 'error';
            } else {
                $st = $conn->prepare("DELETE FROM recettes WHERE id=?");
                $st->bind_param('i', $id);
                if ($st->execute()) {
                    $message = 'Recette supprimée.';
                } else {
                    $message = 'Suppression impossible.';
                    $type = 'error';
                }
                $st->close();
            }
        }
    }
}

/* =========================
   MODE MODIFICATION
========================= */
$edit = null;
if (isset($_GET['modifier'])) {
    $id = (int)$_GET['modifier'];

    $st = $conn->prepare(
        "SELECT id, libelle, montant, description, date_recette
         FROM recettes
         WHERE id=?
           AND libelle NOT LIKE 'Prestation DTF%'
           AND libelle NOT LIKE '%Prestation DTF%'
         LIMIT 1"
    );
    $st->bind_param('i', $id);
    $st->execute();
    $edit = $st->get_result()->fetch_assoc();
    $st->close();
}

/* =========================
   STATS
   IMPORTANT :
   Les prestations DTF ne sont pas comptées ici comme recettes manuelles.
   Elles restent dans la table recettes pour être gérées par prestations.php.
========================= */
$totalRecettes = 0;
$nombreRecettes = 0;

$r = $conn->query(
    "SELECT COUNT(*) AS n, COALESCE(SUM(montant),0) AS total
     FROM recettes
     WHERE libelle NOT LIKE 'Prestation DTF%'
       AND libelle NOT LIKE '%Prestation DTF%'"
);
if ($r) {
    $x = $r->fetch_assoc();
    $nombreRecettes = (int)$x['n'];
    $totalRecettes = (float)$x['total'];
}

/* Prestations séparées pour information */
$totalPrestations = 0;
$nombrePrestations = 0;

$r = $conn->query(
    "SELECT COUNT(*) AS n, COALESCE(SUM(montant),0) AS total
     FROM recettes
     WHERE libelle LIKE 'Prestation DTF%'
        OR libelle LIKE '%Prestation DTF%'"
);
if ($r) {
    $x = $r->fetch_assoc();
    $nombrePrestations = (int)$x['n'];
    $totalPrestations = (float)$x['total'];
}

/* =========================
   LISTE
========================= */
$liste = [];
$r = $conn->query(
    "SELECT id, libelle, montant, description, date_recette
     FROM recettes
     WHERE libelle NOT LIKE 'Prestation DTF%'
       AND libelle NOT LIKE '%Prestation DTF%'
     ORDER BY date_recette DESC, id DESC
     LIMIT 100"
);
if ($r) {
    while ($x = $r->fetch_assoc()) $liste[] = $x;
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Recettes - LAMBEMAH GESTION</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f3f7fb;color:#102d45;font-family:Arial,sans-serif}
.layout{display:flex;min-height:100vh}
.sidebar{width:245px;background:#062842;color:#fff;position:fixed;left:0;top:0;bottom:0;padding:26px 14px;overflow:auto}
.brand{display:flex;align-items:center;gap:12px;padding:0 8px 25px}
.brand-icon{width:52px;height:52px;border-radius:16px;background:#1598e8;display:flex;align-items:center;justify-content:center;font-size:25px}
.brand b{font-size:21px}.brand small{display:block;color:#61b9ef;font-size:11px;margin-top:3px}
.nav a{display:flex;align-items:center;gap:12px;color:#fff;text-decoration:none;padding:13px 15px;border-radius:12px;margin:4px 0;font-size:14px}
.nav a:hover,.nav a.active{background:#13527a}
.nav a.active{border-left:3px solid #1ca7f5;padding-left:12px}
.account{margin-top:25px;background:#13527a;border-radius:13px;padding:13px 15px}
.account b{font-size:13px}.account small{display:block;color:#d9edf8;margin-top:5px}
.logout{display:block;text-align:center;background:#0e4b70;color:#ffb1b1!important;margin-top:12px!important}
.main{margin-left:245px;width:calc(100% - 245px);padding:30px 34px 45px}
.top{display:flex;justify-content:space-between;align-items:center;gap:15px}
.top h1{margin:0;font-size:28px}.top p{margin:7px 0 0;color:#778b9b;font-size:13px}
.cards{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:22px 0}
.card{background:#fff;border:1px solid #dbe5ed;border-radius:15px;padding:18px;box-shadow:0 4px 18px #17324a0b}
.card small{font-size:10px;color:#7d8f9d;text-transform:uppercase}.card b{display:block;font-size:23px;margin-top:7px}
.grid{display:grid;grid-template-columns:360px 1fr;gap:14px}
.box{background:#fff;border:1px solid #dbe5ed;border-radius:15px;padding:18px}
.box h2{margin:0 0 14px;font-size:16px}
.group{margin-bottom:11px}.label{display:block;font-size:10px;font-weight:bold;color:#607486;margin-bottom:5px}
input,textarea{width:100%;padding:10px;border:1px solid #d8e2ea;border-radius:8px;font-size:12px}
textarea{min-height:75px;resize:vertical}
.btn{display:inline-block;border:0;border-radius:8px;padding:9px 12px;font-size:11px;font-weight:bold;text-decoration:none;cursor:pointer}
.primary{background:#1769e8;color:#fff}.secondary{background:#eaf2fb;color:#1769e8}
.danger{background:#fee2e2;color:#b42318}.full{width:100%}
.alert{padding:11px 13px;border-radius:9px;margin:0 0 14px;font-size:12px}
.ok{background:#dcfce7;color:#166534}.error{background:#fee2e2;color:#991b1b}
.table-wrap{overflow:auto}
table{width:100%;border-collapse:collapse;min-width:650px}
th,td{border:1px solid #dce5ec;padding:9px 10px;text-align:left;font-size:11px;vertical-align:top}
th{background:#eef5fb;color:#4e6475;font-size:10px;text-transform:uppercase}
td.amount{text-align:right;font-weight:bold;white-space:nowrap}
.actions{display:flex;gap:5px;align-items:center;white-space:nowrap}
.inline{display:inline}
.note{font-size:10px;color:#738695;margin-top:7px}
.badge{display:inline-block;padding:4px 7px;border-radius:20px;background:#eef5fb;color:#1769e8;font-size:9px;font-weight:bold}
@media(max-width:850px){
 .sidebar{position:static;width:100%;height:auto;padding:10px}
 .brand{padding:3px 7px 10px}
 .brand-icon{width:42px;height:42px;font-size:20px}
 .brand b{font-size:18px}
 .nav{display:grid;grid-template-columns:repeat(4,1fr);gap:3px}
 .nav a{justify-content:center;text-align:center;font-size:10px;padding:8px 3px}
 .account,.logout{display:none}
 .main{margin:0;width:100%;padding:14px}
 .top{align-items:flex-start}
 .top h1{font-size:21px}
 .cards,.grid{grid-template-columns:1fr}
 .card{padding:14px}
 .box{padding:13px}
}
</style>
</head>
<body>
<div class="layout">
<aside class="sidebar">
    <div class="brand">
        <div class="brand-icon">💼</div>
        <div><b>LAMBEMAH</b><small>GESTION • PRESTATION</small></div>
    </div>
    <nav class="nav">
        <a href="index.php">🏠 Accueil</a>
        <a href="produits.php">📦 Produits</a>
        <a href="ventes.php">💰 Ventes</a>
        <a href="prestations.php">🖨️ Prestations</a>
        <a class="active" href="recettes.php">💵 Recettes</a>
        <a href="depenses.php">💸 Dépenses</a>
        <a href="statistiques.php">📊 Statistiques</a>
        <?php if($role==='admin'): ?><a href="utilisateurs.php">👥 Équipe</a><?php endif; ?>
        <a href="index.php?logout=1">🚪 Déconnexion</a>
    </nav>
    <div class="account"><b><?=h($nom)?></b><small><?=h($role)?></small></div>
</aside>

<main class="main">
    <div class="top">
        <div>
            <h1>💵 Recettes</h1>
            <p>Enregistre les recettes diverses de l’activité.</p>
        </div>
    </div>

    <?php if($message): ?>
        <div class="alert <?=$type==='success'?'ok':'error'?>"><?=h($message)?></div>
    <?php endif; ?>

    <div class="cards">
        <div class="card">
            <small>Recettes diverses</small>
            <b><?=argent($totalRecettes)?></b>
        </div>
        <div class="card">
            <small>Nombre de recettes</small>
            <b><?=$nombreRecettes?></b>
        </div>
        <div class="card">
            <small>Prestations DTF séparées</small>
            <b><?=argent($totalPrestations)?></b>
            <div class="note"><?=$nombrePrestations?> prestation(s) — gérées dans Prestations</div>
        </div>
    </div>

    <div class="grid">
        <div class="box">
            <h2><?=$edit?'✏️ Modifier la recette':'➕ Nouvelle recette'?></h2>

            <form method="post">
                <input type="hidden" name="action" value="<?=$edit?'modifier':'ajouter'?>">
                <?php if($edit): ?><input type="hidden" name="id" value="<?=h($edit['id'])?>"><?php endif; ?>

                <div class="group">
                    <label class="label">LIBELLÉ</label>
                    <input name="libelle" required value="<?=h($edit['libelle']??'')?>" placeholder="Ex : Vente diverse, autre revenu...">
                </div>

                <div class="group">
                    <label class="label">MONTANT</label>
                    <input type="number" name="montant" min="1" step="1" required value="<?=h($edit['montant']??'')?>" placeholder="0">
                </div>

                <div class="group">
                    <label class="label">DATE</label>
                    <input type="date" name="date_recette" required value="<?=h($edit['date_recette']??date('Y-m-d'))?>">
                </div>

                <div class="group">
                    <label class="label">DESCRIPTION</label>
                    <textarea name="description" placeholder="Détail facultatif..."><?=h($edit['description']??'')?></textarea>
                </div>

                <button class="btn primary full" type="submit">
                    <?=$edit?'💾 Enregistrer les modifications':'💾 Enregistrer la recette'?>
                </button>

                <?php if($edit): ?>
                    <a class="btn secondary full" style="margin-top:7px;text-align:center" href="recettes.php">Annuler</a>
                <?php endif; ?>
            </form>

            <div class="note">
                Les prestations DTF sont enregistrées dans la table recettes mais restent gérées depuis la page Prestations pour éviter les doublons.
            </div>
        </div>

        <div class="box">
            <h2>📋 Historique des recettes</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Libellé</th>
                            <th>Description</th>
                            <th>Montant</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if(!$liste): ?>
                        <tr><td colspan="5">Aucune recette diverse enregistrée.</td></tr>
                    <?php else: foreach($liste as $r): ?>
                        <tr>
                            <td><?=h(date('d/m/Y',strtotime($r['date_recette'])))?></td>
                            <td><b><?=h($r['libelle'])?></b></td>
                            <td><?=h($r['description']??'')?></td>
                            <td class="amount"><?=argent($r['montant'])?></td>
                            <td>
                                <div class="actions">
                                    <a class="btn secondary" href="?modifier=<?=(int)$r['id']?>">✏️ Modifier</a>
                                    <form class="inline" method="post" onsubmit="return confirm('Supprimer cette recette ?');">
                                        <input type="hidden" name="action" value="supprimer">
                                        <input type="hidden" name="id" value="<?=(int)$r['id']?>">
                                        <button class="btn danger" type="submit">🗑️</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>
</div>
</body>
</html>
