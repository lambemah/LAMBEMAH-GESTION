<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Connexion à la base de données impossible.');
}
$conn->set_charset('utf8mb4');

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($v){ return number_format((float)$v, 0, ',', ' ') . ' FG'; }

/* =========================
   DÉCONNEXION
   ========================= */
if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: index.php');
    exit;
}

/* =========================
   CONNEXION
   ========================= */
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $loginError = 'Veuillez remplir tous les champs.';
    } else {
        $stmt = $conn->prepare("SELECT id, nom, username, mot_de_passe, role FROM utilisateurs WHERE username = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $r = $stmt->get_result();
            $u = $r ? $r->fetch_assoc() : null;
            $stmt->close();

            if ($u) {
                $stored = (string)$u['mot_de_passe'];
                $valid = password_verify($password, $stored) || hash_equals($stored, $password);
                if ($valid) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int)$u['id'];
                    $_SESSION['utilisateur_id'] = (int)$u['id'];
                    $_SESSION['id_utilisateur'] = (int)$u['id'];
                    $_SESSION['id'] = (int)$u['id'];
                    $_SESSION['nom'] = $u['nom'];
                    $_SESSION['username'] = $u['username'];
                    $_SESSION['role'] = $u['role'];
                    header('Location: index.php');
                    exit;
                }
            }
            $loginError = 'Nom d’utilisateur ou mot de passe incorrect.';
        } else {
            $loginError = 'Erreur de connexion à la base de données.';
        }
    }
}

$loggedIn = isset($_SESSION['user_id']) || isset($_SESSION['utilisateur_id']) || isset($_SESSION['id_utilisateur']) || isset($_SESSION['id']);

if (!$loggedIn):
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Connexion — LAMBEMAH GESTION</title>
<style>
:root{--navy:#08253f;--blue:#1677e8;--light:#eef4fa;--line:#d7e1eb;--text:#1a2a3a;--muted:#718092}
*{box-sizing:border-box}html,body{margin:0;min-height:100%;font-family:Inter,Arial,sans-serif;color:var(--text);background:linear-gradient(135deg,#eef4fa,#f8fbfe)}
body{min-height:100vh;display:grid;place-items:center;padding:20px}
.login{width:min(390px,100%);background:#fff;border:1px solid #dce7f0;border-radius:20px;box-shadow:0 18px 40px rgba(12,41,67,.10);padding:28px}
.brand{text-align:center}.logo{width:70px;height:70px;object-fit:contain;margin:auto auto 10px;display:block}.brand h1{margin:0;font-size:23px;color:var(--navy)}.brand p{margin:6px 0 22px;color:var(--muted);font-size:12px}
.error{background:#fff1f1;border:1px solid #f1cdcd;color:#ad2525;border-radius:9px;padding:10px 11px;font-size:12px;margin-bottom:12px}
label{display:block;font-size:12px;font-weight:700;margin:12px 0 6px}input{width:100%;height:44px;border:1px solid var(--line);border-radius:10px;padding:0 12px;font-size:13px;outline:none}input:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(22,119,232,.10)}button{width:100%;height:44px;margin-top:18px;border:0;border-radius:10px;background:var(--blue);color:#fff;font-size:13px;font-weight:800;cursor:pointer}
</style>
</head>
<body>
<div class="login">
  <div class="brand">
    <img class="logo" src="/assets/logo.png" alt="LAMBEMAH" onerror="this.style.display='none'">
    <h1>LAMBEMAH GESTION</h1>
    <p>Connexion à votre espace</p>
  </div>
  <?php if($loginError): ?><div class="error"><?=h($loginError)?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <label>Nom d’utilisateur</label>
    <input type="text" name="username" required autofocus>
    <label>Mot de passe</label>
    <input type="password" name="password" required>
    <button type="submit" name="login_submit">Se connecter</button>
  </form>
</div>
</body>
</html>
<?php exit; endif;

/* =========================
   INDICATEURS
   ========================= */
$caVentes = 0; $nbVentes = 0;
$q = $conn->query("SELECT COUNT(*) n, COALESCE(SUM(montant),0) total FROM ventes");
if($q){$r=$q->fetch_assoc();$nbVentes=(int)$r['n'];$caVentes=(float)$r['total'];}

$caPrestations = 0; $nbPrestations = 0;
$q = $conn->query("SELECT COUNT(*) n, COALESCE(SUM(montant),0) total FROM recettes WHERE libelle LIKE 'Prestation DTF%'");
if($q){$r=$q->fetch_assoc();$nbPrestations=(int)$r['n'];$caPrestations=(float)$r['total'];}

$recettesManuelles = 0;
$q = $conn->query("SELECT COALESCE(SUM(montant),0) total FROM recettes WHERE libelle NOT LIKE 'Prestation DTF%'");
if($q){$recettesManuelles=(float)$q->fetch_assoc()['total'];}

$depenses = 0;
$q = $conn->query("SELECT COALESCE(SUM(montant),0) total FROM depenses");
if($q){$depenses=(float)$q->fetch_assoc()['total'];}

$cogs = 0;
$q = $conn->query("SELECT v.quantite, p.prix_achat FROM ventes v LEFT JOIN produits p ON p.id=v.produit_id");
if($q){while($r=$q->fetch_assoc()){$cogs += (float)$r['quantite']*(float)($r['prix_achat']??0);}}

$caTotal = $caVentes + $caPrestations + $recettesManuelles;
$benefice = $caTotal - $cogs - $depenses;

$stockQte = 0; $stockValeur = 0; $faible = 0; $rupture = 0;
$q = $conn->query("SELECT stock, prix_achat FROM produits");
if($q){while($r=$q->fetch_assoc()){
    $s=(int)$r['stock']; $stockQte += $s; $stockValeur += $s*(float)$r['prix_achat'];
    if($s<=0)$rupture++; elseif($s<=5)$faible++;
}}

$recentSales=[];
$q=$conn->query("SELECT v.id,v.quantite,v.montant,v.date_vente,p.nom FROM ventes v LEFT JOIN produits p ON p.id=v.produit_id ORDER BY v.id DESC LIMIT 6");
if($q){while($r=$q->fetch_assoc())$recentSales[]=$r;}

$recentPrestations=[];
$q=$conn->query("SELECT id,libelle,montant,date_recette,description FROM recettes WHERE libelle LIKE 'Prestation DTF%' ORDER BY id DESC LIMIT 6");
if($q){while($r=$q->fetch_assoc())$recentPrestations[]=$r;}

$userName = $_SESSION['nom'] ?? $_SESSION['username'] ?? 'Utilisateur';
$role = $_SESSION['role'] ?? 'admin';
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tableau de bord — LAMBEMAH GESTION</title>
<style>
:root{--navy:#07253f;--navy2:#0d3556;--blue:#1976e8;--blue2:#eaf3ff;--green:#0aa36f;--orange:#eaa626;--red:#dd4d4d;--text:#1e3042;--muted:#718092;--line:#dfe7ee;--bg:#f4f8fc}
*{box-sizing:border-box}html,body{margin:0;font-family:Inter,Arial,sans-serif;background:var(--bg);color:var(--text)}a{text-decoration:none;color:inherit}
.app{min-height:100vh;display:flex}.side{width:220px;position:fixed;inset:0 auto 0 0;background:linear-gradient(180deg,var(--navy),#0a2f4d);color:#fff;padding:20px 12px;overflow:auto}.brand{display:flex;align-items:center;gap:10px;padding:2px 8px 20px;border-bottom:1px solid rgba(255,255,255,.10)}.brand img{width:38px;height:38px;object-fit:contain}.brand b{display:block;font-size:16px;letter-spacing:.4px}.brand small{color:#bcd1e4;font-size:10px}.nav{padding-top:12px}.nav a{display:flex;align-items:center;gap:10px;padding:10px 11px;border-radius:10px;margin:4px 0;color:#dce9f5;font-size:12px}.nav a:hover,.nav a.active{background:var(--blue);color:#fff}.userBox{margin-top:18px;padding:11px;border-radius:10px;background:rgba(255,255,255,.07);font-size:10px;color:#c8d8e7}.logout{margin-top:10px!important;background:rgba(255,255,255,.06)}
.main{margin-left:220px;width:calc(100% - 220px);padding:22px 24px 30px;min-width:0}.top{display:flex;align-items:flex-start;justify-content:space-between;gap:15px;margin-bottom:18px}.top h1{margin:0;font-size:22px;color:var(--navy)}.top p{margin:5px 0 0;font-size:11px;color:var(--muted)}.topUser{font-size:10px;background:#fff;border:1px solid var(--line);padding:8px 10px;border-radius:9px;color:#536579}
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.kpi{background:#fff;border:1px solid var(--line);border-radius:12px;padding:13px 14px;min-height:94px}.kpi .t{font-size:10px;color:var(--muted)}.kpi .v{font-size:18px;font-weight:800;margin-top:6px;color:#183651}.kpi.blue{border-left:4px solid var(--blue)}.kpi.green{border-left:4px solid var(--green)}.kpi.orange{border-left:4px solid var(--orange)}.kpi.red{border-left:4px solid var(--red)}
.smallgrid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:10px}.pill{background:#fff;border:1px solid var(--line);border-radius:10px;padding:9px 10px;font-size:10px}.pill b{font-size:12px;margin-left:5px}.contentGrid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}.panel{background:#fff;border:1px solid var(--line);border-radius:13px;padding:14px}.panelHead{display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:10px}.panel h2{font-size:14px;margin:0;color:var(--navy)}.linkBtn{font-size:10px;background:#eef4fb;padding:7px 9px;border-radius:8px;color:#31526e;font-weight:700}.quick{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.quick a{background:#f7fbff;border:1px solid #dbe7f2;border-radius:10px;padding:11px 6px;text-align:center;font-size:10px;font-weight:800;color:#224563}.quick span{display:block;font-size:18px;margin-bottom:4px}.tablewrap{overflow:auto}.table{width:100%;border-collapse:collapse;font-size:10px}.table th,.table td{padding:8px 7px;border-bottom:1px solid #edf1f5;text-align:left;white-space:nowrap}.table th{background:#f7fafc;color:#6c7d8c;font-size:9px;text-transform:uppercase}.amount{text-align:right;font-weight:800}.empty{padding:14px;text-align:center;color:#8a98a5;font-size:10px}.mobileBar{display:none}
@media(max-width:1050px){.side{width:190px}.main{margin-left:190px;width:calc(100% - 190px)}.kpis{grid-template-columns:repeat(2,1fr)}.smallgrid{grid-template-columns:repeat(2,1fr)}.quick{grid-template-columns:repeat(2,1fr)}}
@media(max-width:700px){.side{width:58px;padding:10px 5px}.brand{padding:5px 3px 14px;justify-content:center}.brand img{width:34px;height:34px}.brand div{display:none}.nav a{justify-content:center;padding:10px 4px;font-size:17px}.nav a span,.userBox{display:none}.logout{margin-top:8px!important}.main{margin-left:58px;width:calc(100% - 58px);padding:10px}.top{margin-bottom:10px}.top h1{font-size:17px}.top p{font-size:9px}.topUser{display:none}.kpis{grid-template-columns:1fr 1fr;gap:6px}.kpi{min-height:76px;padding:9px 10px;border-radius:10px}.kpi .t{font-size:9px}.kpi .v{font-size:13px}.smallgrid{grid-template-columns:1fr 1fr;gap:5px;margin-top:6px}.pill{padding:7px 7px;font-size:8.5px;border-radius:8px}.pill b{font-size:10px}.contentGrid{grid-template-columns:1fr;gap:8px;margin-top:8px}.panel{padding:10px;border-radius:10px}.panel h2{font-size:12px}.linkBtn{font-size:8.5px;padding:6px 7px}.quick{gap:5px}.quick a{font-size:9px;padding:8px 4px}.quick span{font-size:16px}.table{font-size:9px}.table th,.table td{padding:6px 5px}}
</style>
</head>
<body>
<div class="app">
<aside class="side">
  <div class="brand">
    <img src="/assets/logo.png" alt="LAMBEMAH" onerror="this.style.display='none'">
    <div><b>LAMBEMAH</b><small>GESTION • PRESTATION</small></div>
  </div>
  <nav class="nav">
    <a class="active" href="index.php">🏠 <span>Tableau de bord</span></a>
    <a href="produits.php">📦 <span>Achats / Produits</span></a>
    <a href="ventes.php">💰 <span>Ventes / Clients</span></a>
    <a href="prestations.php">🖨️ <span>Prestations</span></a>
    <a href="recettes.php">💵 <span>Recettes</span></a>
    <a href="depenses.php">💸 <span>Dépenses</span></a>
    <a href="statistiques.php">📊 <span>Statistiques</span></a>
    <a href="utilisateurs.php">👥 <span>Équipe</span></a>
    <a class="logout" href="?logout=1">🚪 <span>Déconnexion</span></a>
  </nav>
  <div class="userBox">Connecté : <b><?=h($userName)?></b><br><?=h($role)?></div>
</aside>

<main class="main">
  <div class="top">
    <div><h1>📊 Tableau de bord</h1><p>Vue rapide de l’activité de LAMBEMAH GESTION.</p></div>
    <div class="topUser">👤 <?=h($userName)?> · <?=h($role)?></div>
  </div>

  <div class="kpis">
    <div class="kpi blue"><div class="t">Chiffre d’affaires total</div><div class="v"><?=money($caTotal)?></div></div>
    <div class="kpi green"><div class="t">Ventes</div><div class="v"><?=money($caVentes)?></div></div>
    <div class="kpi orange"><div class="t">Prestations DTF</div><div class="v"><?=money($caPrestations)?></div></div>
    <div class="kpi red"><div class="t">Dépenses</div><div class="v"><?=money($depenses)?></div></div>
  </div>

  <div class="smallgrid">
    <div class="pill">💼 Bénéfice estimé <b><?=money($benefice)?></b></div>
    <div class="pill">📦 Stock <b><?=number_format($stockQte,0,',',' ')?></b></div>
    <div class="pill">💰 Valeur stock <b><?=money($stockValeur)?></b></div>
    <div class="pill">⚠️ Stock faible <b><?=$faible?></b> · ⛔ Ruptures <b><?=$rupture?></b></div>
  </div>

  <section class="panel" style="margin-top:12px">
    <div class="panelHead"><h2>⚡ Accès rapide</h2></div>
    <div class="quick">
      <a href="ventes.php?nouvelle_vente=1"><span>💰</span>Nouvelle vente</a>
      <a href="prestations.php"><span>🖨️</span>Nouvelle prestation</a>
      <a href="produits.php?nouvel_achat=1"><span>📦</span>Nouvel achat</a>
      <a href="statistiques.php"><span>📊</span>Statistiques</a>
    </div>
  </section>

  <div class="contentGrid">
    <section class="panel">
      <div class="panelHead"><h2>🧾 Dernières ventes</h2><a class="linkBtn" href="ventes.php">Tout voir</a></div>
      <div class="tablewrap">
        <table class="table">
          <thead><tr><th>Article</th><th>Qté</th><th>Montant</th><th>Date</th></tr></thead>
          <tbody>
          <?php if($recentSales): foreach($recentSales as $r): ?>
            <tr><td><?=h($r['nom'] ?? 'Article')?></td><td><?=h($r['quantite'])?></td><td class="amount"><?=money($r['montant'])?></td><td><?=h(substr((string)$r['date_vente'],0,10))?></td></tr>
          <?php endforeach; else: ?><tr><td class="empty" colspan="4">Aucune vente.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel">
      <div class="panelHead"><h2>🖨️ Dernières prestations</h2><a class="linkBtn" href="prestations.php">Tout voir</a></div>
      <div class="tablewrap">
        <table class="table">
          <thead><tr><th>Client</th><th>Montant</th><th>Date</th></tr></thead>
          <tbody>
          <?php if($recentPrestations): foreach($recentPrestations as $r): $client=preg_replace('/^Prestation DTF\s*-\s*/i','',$r['libelle']); ?>
            <tr><td><?=h($client)?></td><td class="amount"><?=money($r['montant'])?></td><td><?=h(substr((string)$r['date_recette'],0,10))?></td></tr>
          <?php endforeach; else: ?><tr><td class="empty" colspan="3">Aucune prestation.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <section class="panel" style="margin-top:12px">
    <div class="panelHead"><h2>🧭 Modules</h2></div>
    <div class="quick">
      <a href="produits.php"><span>📦</span>Achats / Stock</a>
      <a href="ventes.php"><span>💰</span>Ventes</a>
      <a href="prestations.php"><span>🖨️</span>Prestations</a>
      <a href="depenses.php"><span>💸</span>Dépenses</a>
    </div>
  </section>
</main>
</div>
</body>
</html>
