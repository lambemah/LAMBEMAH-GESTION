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
function money($v){ return number_format((float)$v, 0, ',', ' ') . ' FG'; }

$periode = $_GET['periode'] ?? 'tout';
$allowed = ['tout','mois','annee'];
if (!in_array($periode, $allowed, true)) $periode = 'tout';

$whereV = '';
$whereR = '';
$whereD = '';

if ($periode === 'mois') {
    $whereV = " WHERE MONTH(date_vente)=MONTH(CURDATE()) AND YEAR(date_vente)=YEAR(CURDATE())";
    $whereR = " WHERE MONTH(date_recette)=MONTH(CURDATE()) AND YEAR(date_recette)=YEAR(CURDATE())";
    $whereD = " WHERE MONTH(date_depense)=MONTH(CURDATE()) AND YEAR(date_depense)=YEAR(CURDATE())";
} elseif ($periode === 'annee') {
    $whereV = " WHERE YEAR(date_vente)=YEAR(CURDATE())";
    $whereR = " WHERE YEAR(date_recette)=YEAR(CURDATE())";
    $whereD = " WHERE YEAR(date_depense)=YEAR(CURDATE())";
}

/* =========================
   VENTES
========================= */
$totalVentes = 0;
$nbVentes = 0;
$q = $conn->query("SELECT COUNT(*) n, COALESCE(SUM(montant),0) total FROM ventes $whereV");
if ($q) {
    $x = $q->fetch_assoc();
    $nbVentes = (int)$x['n'];
    $totalVentes = (float)$x['total'];
}

/* =========================
   RECETTES
   On distingue les recettes diverses des prestations DTF.
========================= */
$totalRecettesDiverses = 0;
$nbRecettes = 0;
$conditionRecettes = "libelle NOT LIKE 'Prestation DTF%' AND libelle NOT LIKE '%Prestation DTF%'";
if ($periode === 'mois') {
    $conditionRecettes .= " AND MONTH(date_recette)=MONTH(CURDATE()) AND YEAR(date_recette)=YEAR(CURDATE())";
} elseif ($periode === 'annee') {
    $conditionRecettes .= " AND YEAR(date_recette)=YEAR(CURDATE())";
}

$q = $conn->query("SELECT COUNT(*) n, COALESCE(SUM(montant),0) total FROM recettes WHERE $conditionRecettes");
if ($q) {
    $x = $q->fetch_assoc();
    $nbRecettes = (int)$x['n'];
    $totalRecettesDiverses = (float)$x['total'];
}

/* =========================
   PRESTATIONS
========================= */
$totalPrestations = 0;
$nbPrestations = 0;
$conditionPrest = "(libelle LIKE 'Prestation DTF%' OR libelle LIKE '%Prestation DTF%')";
if ($periode === 'mois') {
    $conditionPrest .= " AND MONTH(date_recette)=MONTH(CURDATE()) AND YEAR(date_recette)=YEAR(CURDATE())";
} elseif ($periode === 'annee') {
    $conditionPrest .= " AND YEAR(date_recette)=YEAR(CURDATE())";
}

$q = $conn->query("SELECT COUNT(*) n, COALESCE(SUM(montant),0) total FROM recettes WHERE $conditionPrest");
if ($q) {
    $x = $q->fetch_assoc();
    $nbPrestations = (int)$x['n'];
    $totalPrestations = (float)$x['total'];
}

/* =========================
   DEPENSES
   Les coûts DTF sont déjà des dépenses automatiques.
========================= */
$totalDepenses = 0;
$nbDepenses = 0;
$q = $conn->query("SELECT COUNT(*) n, COALESCE(SUM(montant),0) total FROM depenses $whereD");
if ($q) {
    $x = $q->fetch_assoc();
    $nbDepenses = (int)$x['n'];
    $totalDepenses = (float)$x['total'];
}

/* =========================
   COÛTS DTF
========================= */
$conditionDtf = "libelle LIKE 'DTF fournisseur%'";
if ($periode === 'mois') {
    $conditionDtf .= " AND MONTH(date_depense)=MONTH(CURDATE()) AND YEAR(date_depense)=YEAR(CURDATE())";
} elseif ($periode === 'annee') {
    $conditionDtf .= " AND YEAR(date_depense)=YEAR(CURDATE())";
}

$totalDtf = 0;
$q = $conn->query("SELECT COALESCE(SUM(montant),0) total FROM depenses WHERE $conditionDtf");
if ($q) $totalDtf = (float)$q->fetch_assoc()['total'];

/* =========================
   COÛT DES MARCHANDISES VENDUES
   On utilise le prix d'achat enregistré au moment de la vente.
   Pour les anciennes ventes sans prix exploitable, fallback sur prix_achat actuel.
========================= */
$cmv = 0;
$conditionVenteCmv = '1=1';
if ($periode === 'mois') {
    $conditionVenteCmv = "MONTH(v.date_vente)=MONTH(CURDATE()) AND YEAR(v.date_vente)=YEAR(CURDATE())";
} elseif ($periode === 'annee') {
    $conditionVenteCmv = "YEAR(v.date_vente)=YEAR(CURDATE())";
}

$q = $conn->query(
    "SELECT COALESCE(SUM(
        v.quantite * COALESCE(
            NULLIF(
                CASE
                    WHEN v.description REGEXP 'COUT_ACHAT[=:][ ]*[0-9]'
                    THEN CAST(
                        REPLACE(
                            SUBSTRING_INDEX(
                                SUBSTRING_INDEX(v.description,'COUT_ACHAT=',-1),
                                '|',1
                            ), ',', '.'
                        ) AS DECIMAL(12,2)
                    )
                    ELSE NULL
                END, 0
            ),
            p.prix_achat,
            0
        )
    ),0) total
    FROM ventes v
    LEFT JOIN produits p ON p.id=v.produit_id
    WHERE $conditionVenteCmv"
);
if ($q) $cmv = (float)$q->fetch_assoc()['total'];

/* =========================
   INDICATEURS
========================= */
$chiffreAffaires = $totalVentes + $totalPrestations + $totalRecettesDiverses;

/* Les coûts DTF sont inclus dans les dépenses.
   On les affiche séparément, mais on ne les retire pas deux fois. */
$beneficeNet = $chiffreAffaires - $cmv - $totalDepenses;
$marge = $chiffreAffaires > 0 ? ($beneficeNet / $chiffreAffaires) * 100 : 0;

$stockTotal = 0;
$stockValeurAchat = 0;
$stockValeurVente = 0;
$q = $conn->query(
    "SELECT
        COALESCE(SUM(stock),0) stock_total,
        COALESCE(SUM(stock*prix_achat),0) valeur_achat,
        COALESCE(SUM(stock*prix_vente),0) valeur_vente
     FROM produits"
);
if ($q) {
    $x = $q->fetch_assoc();
    $stockTotal = (int)$x['stock_total'];
    $stockValeurAchat = (float)$x['valeur_achat'];
    $stockValeurVente = (float)$x['valeur_vente'];
}

$stockFaible = [];
$q = $conn->query("SELECT id,nom,stock FROM produits WHERE stock<=5 ORDER BY stock ASC, nom ASC LIMIT 10");
if ($q) while($x=$q->fetch_assoc()) $stockFaible[]=$x;

/* =========================
   RÉPARTITION PAR MOIS — 12 derniers mois
========================= */
$monthly = [];
for ($i=11; $i>=0; $i--) {
    $ts = strtotime("-$i months");
    $key = date('Y-m', $ts);
    $monthly[$key] = [
        'label'=>date('M Y',$ts),
        'ventes'=>0,
        'prestations'=>0,
        'recettes'=>0,
        'depenses'=>0
    ];
}

$q = $conn->query(
    "SELECT DATE_FORMAT(date_vente,'%Y-%m') ym, COALESCE(SUM(montant),0) total
     FROM ventes
     WHERE date_vente >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
     GROUP BY ym"
);
if ($q) while($x=$q->fetch_assoc()) if(isset($monthly[$x['ym']])) $monthly[$x['ym']]['ventes']=(float)$x['total'];

$q = $conn->query(
    "SELECT DATE_FORMAT(date_recette,'%Y-%m') ym,
            COALESCE(SUM(CASE WHEN libelle LIKE 'Prestation DTF%' OR libelle LIKE '%Prestation DTF%' THEN montant ELSE 0 END),0) prestations,
            COALESCE(SUM(CASE WHEN libelle NOT LIKE 'Prestation DTF%' AND libelle NOT LIKE '%Prestation DTF%' THEN montant ELSE 0 END),0) recettes
     FROM recettes
     WHERE date_recette >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
     GROUP BY ym"
);
if ($q) while($x=$q->fetch_assoc()) if(isset($monthly[$x['ym']])) {
    $monthly[$x['ym']]['prestations']=(float)$x['prestations'];
    $monthly[$x['ym']]['recettes']=(float)$x['recettes'];
}

$q = $conn->query(
    "SELECT DATE_FORMAT(date_depense,'%Y-%m') ym, COALESCE(SUM(montant),0) total
     FROM depenses
     WHERE date_depense >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
     GROUP BY ym"
);
if ($q) while($x=$q->fetch_assoc()) if(isset($monthly[$x['ym']])) $monthly[$x['ym']]['depenses']=(float)$x['total'];

/* =========================
   TOP PRODUITS VENDUS
========================= */
$topProduits = [];
$q = $conn->query(
    "SELECT p.nom, COALESCE(SUM(v.quantite),0) qte, COALESCE(SUM(v.montant),0) ca
     FROM ventes v
     LEFT JOIN produits p ON p.id=v.produit_id
     $whereV
     GROUP BY v.produit_id,p.nom
     ORDER BY qte DESC
     LIMIT 8"
);
if ($q) while($x=$q->fetch_assoc()) $topProduits[]=$x;

$labels = json_encode(array_values(array_column($monthly,'label')), JSON_UNESCAPED_UNICODE);
$ventesData = json_encode(array_values(array_column($monthly,'ventes')));
$prestData = json_encode(array_values(array_column($monthly,'prestations')));
$recData = json_encode(array_values(array_column($monthly,'recettes')));
$depData = json_encode(array_values(array_column($monthly,'depenses')));
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Statistiques - LAMBEMAH GESTION</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f3f7fb;color:#102d45;font-family:Arial,sans-serif}
.layout{display:flex;min-height:100vh}
.sidebar{width:245px;background:#062842;color:#fff;position:fixed;left:0;top:0;bottom:0;padding:26px 14px;overflow:auto}
.brand{display:flex;align-items:center;gap:12px;padding:0 8px 25px}.brand-icon{width:52px;height:52px;border-radius:16px;background:#1598e8;display:flex;align-items:center;justify-content:center;font-size:25px}
.brand b{font-size:21px}.brand small{display:block;color:#61b9ef;font-size:11px;margin-top:3px}
.nav a{display:flex;align-items:center;gap:12px;color:#fff;text-decoration:none;padding:13px 15px;border-radius:12px;margin:4px 0;font-size:14px}.nav a:hover,.nav a.active{background:#13527a}.nav a.active{border-left:3px solid #1ca7f5;padding-left:12px}
.account{margin-top:25px;background:#13527a;border-radius:13px;padding:13px 15px}.account b{font-size:13px}.account small{display:block;color:#d9edf8;margin-top:5px}
.logout{display:block;text-align:center;background:#0e4b70;color:#ffb1b1!important;margin-top:12px!important}
.main{margin-left:245px;width:calc(100% - 245px);padding:30px 34px 45px}
.top{display:flex;justify-content:space-between;align-items:flex-start;gap:15px}.top h1{margin:0;font-size:28px}.top p{margin:7px 0;color:#778b9b;font-size:13px}
.filters{display:flex;gap:7px;margin:18px 0}.filters a{padding:8px 13px;border-radius:9px;text-decoration:none;font-size:11px;background:#fff;border:1px solid #dbe5ed;color:#52697b}.filters a.active{background:#1769e8;color:#fff;border-color:#1769e8}
.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:14px}.card{background:#fff;border:1px solid #dbe5ed;border-radius:15px;padding:17px;box-shadow:0 4px 18px #17324a0b}.card small{font-size:10px;color:#718493;text-transform:uppercase}.card b{display:block;font-size:21px;margin-top:8px}.card .sub{font-size:10px;color:#7b8c99;margin-top:5px}
.grid{display:grid;grid-template-columns:1.35fr .8fr;gap:14px;margin-bottom:14px}.box{background:#fff;border:1px solid #dbe5ed;border-radius:15px;padding:18px}.box h2{margin:0 0 5px;font-size:16px}.box p{font-size:11px;color:#788b99;margin:0 0 13px}
.chartbox{height:350px}.chartbox canvas{max-height:285px}
.kpis{display:grid;grid-template-columns:1fr 1fr;gap:9px}.mini{border:1px solid #e0e8ef;background:#f8fbfe;border-radius:11px;padding:12px}.mini span{display:block;color:#718493;font-size:9px;text-transform:uppercase}.mini b{display:block;margin-top:6px;font-size:16px}
.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:560px}th,td{border:1px solid #dce5ec;padding:9px 10px;text-align:left;font-size:11px}th{background:#eef5fb;color:#52697a;font-size:10px;text-transform:uppercase}td.amount{text-align:right;font-weight:bold;white-space:nowrap}
.badge{padding:4px 7px;border-radius:20px;font-size:9px;background:#eaf4ff;color:#1769e8}.low{background:#fff4d6;color:#9a6500}.zero{background:#fee2e2;color:#b42318}
.progress{height:7px;background:#e7eef4;border-radius:20px;overflow:hidden}.progress i{display:block;height:100%;background:#1769e8}
@media(max-width:950px){.cards{grid-template-columns:1fr 1fr}.grid{grid-template-columns:1fr}}
@media(max-width:700px){
.sidebar{position:static;width:100%;height:auto;padding:10px}.brand{padding:3px 7px 10px}.brand-icon{width:42px;height:42px;font-size:20px}.brand b{font-size:18px}.nav{display:grid;grid-template-columns:repeat(4,1fr);gap:3px}.nav a{justify-content:center;text-align:center;font-size:10px;padding:8px 3px}.account,.logout{display:none}.main{margin:0;width:100%;padding:14px}.top h1{font-size:21px}.cards{grid-template-columns:1fr 1fr}.card{padding:13px}.card b{font-size:17px}.box{padding:13px}.chartbox{height:300px}.kpis{grid-template-columns:1fr 1fr}.filters{overflow:auto}}
</style>
</head>
<body>
<div class="layout">
<aside class="sidebar">
<div class="brand"><div class="brand-icon">📊</div><div><b>LAMBEMAH</b><small>GESTION • TABLEAU DE BORD</small></div></div>
<nav class="nav">
<a href="index.php">🏠 Accueil</a><a href="produits.php">📦 Produits</a><a href="ventes.php">💰 Ventes</a><a href="prestations.php">🖨️ Prestations</a><a href="recettes.php">💵 Recettes</a><a href="depenses.php">💸 Dépenses</a><a class="active" href="statistiques.php">📊 Statistiques</a>
<?php if($role==='admin'): ?><a href="utilisateurs.php">👥 Équipe</a><?php endif; ?><a href="index.php?logout=1">🚪 Déconnexion</a>
</nav>
<div class="account"><b><?=h($nom)?></b><small><?=h($role)?></small></div>
</aside>

<main class="main">
<div class="top"><div><h1>📊 Tableau de bord</h1><p>Vue complète et calculée de l’activité LAMBEMAH GESTION.</p></div></div>

<div class="filters">
<a class="<?=$periode==='tout'?'active':''?>" href="?periode=tout">Tout</a>
<a class="<?=$periode==='mois'?'active':''?>" href="?periode=mois">Ce mois</a>
<a class="<?=$periode==='annee'?'active':''?>" href="?periode=annee">Cette année</a>
</div>

<div class="cards">
<div class="card"><small>Chiffre d’affaires</small><b><?=money($chiffreAffaires)?></b><div class="sub">Ventes + prestations + recettes</div></div>
<div class="card"><small>Bénéfice net estimé</small><b><?=money($beneficeNet)?></b><div class="sub">Après coût marchandises + dépenses</div></div>
<div class="card"><small>Dépenses</small><b><?=money($totalDepenses)?></b><div class="sub">Dont DTF : <?=money($totalDtf)?></div></div>
<div class="card"><small>Marge nette</small><b><?=number_format($marge,1,',',' ')?> %</b><div class="sub"><?=$nbVentes?> ventes • <?=$nbPrestations?> prestations</div></div>
</div>

<div class="grid">
<section class="box chartbox"><h2>📈 Évolution de l’activité</h2><p>12 derniers mois — montants calculés directement depuis les opérations.</p><canvas id="activityChart"></canvas></section>
<section class="box"><h2>🎯 Indicateurs clés</h2><p>Lecture rapide pour la direction.</p>
<div class="kpis">
<div class="mini"><span>Total ventes</span><b><?=money($totalVentes)?></b></div>
<div class="mini"><span>Prestations</span><b><?=money($totalPrestations)?></b></div>
<div class="mini"><span>Recettes diverses</span><b><?=money($totalRecettesDiverses)?></b></div>
<div class="mini"><span>Coût marchandises</span><b><?=money($cmv)?></b></div>
<div class="mini"><span>Stock en quantité</span><b><?=number_format($stockTotal,0,',',' ')?></b></div>
<div class="mini"><span>Valeur stock achat</span><b><?=money($stockValeurAchat)?></b></div>
</div>
</section>
</div>

<div class="grid">
<section class="box"><h2>🏆 Produits les plus vendus</h2><p>Classement par quantité vendue.</p>
<div class="table-wrap"><table><thead><tr><th>Produit</th><th>Quantité</th><th>CA</th></tr></thead><tbody>
<?php if(!$topProduits): ?><tr><td colspan="3">Aucune vente.</td></tr>
<?php else: foreach($topProduits as $p): ?><tr><td><b><?=h($p['nom']??'Produit supprimé')?></b></td><td><?=number_format((int)$p['qte'],0,',',' ')?></td><td class="amount"><?=money($p['ca'])?></td></tr><?php endforeach; endif; ?>
</tbody></table></div>
</section>

<section class="box"><h2>⚠️ Stock faible</h2><p>Produits à surveiller.</p>
<?php if(!$stockFaible): ?><p>Aucun produit avec un stock inférieur ou égal à 5.</p>
<?php else: foreach($stockFaible as $p): ?><div style="margin-bottom:10px"><div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:4px"><b><?=h($p['nom'])?></b><span class="badge <?=$p['stock']==0?'zero':'low'?>"><?=$p['stock']?> en stock</span></div><div class="progress"><i style="width:<?=min(100,max(4,(int)$p['stock']*20))?>%"></i></div></div><?php endforeach; endif; ?>
</section>
</div>

<div class="box">
<h2>🧾 Synthèse financière</h2><p>Les coûts DTF sont déjà inclus dans les dépenses : ils ne sont donc pas soustraits une deuxième fois.</p>
<div class="table-wrap"><table>
<thead><tr><th>Indicateur</th><th>Montant</th><th>Lecture</th></tr></thead>
<tbody>
<tr><td>Ventes</td><td class="amount"><?=money($totalVentes)?></td><td><?=$nbVentes?> ligne(s) de vente</td></tr>
<tr><td>Prestations DTF</td><td class="amount"><?=money($totalPrestations)?></td><td><?=$nbPrestations?> prestation(s)</td></tr>
<tr><td>Recettes diverses</td><td class="amount"><?=money($totalRecettesDiverses)?></td><td><?=$nbRecettes?> recette(s)</td></tr>
<tr><td>Chiffre d’affaires total</td><td class="amount"><b><?=money($chiffreAffaires)?></b></td><td>Revenus enregistrés</td></tr>
<tr><td>Coût marchandises vendues</td><td class="amount"><?=money($cmv)?></td><td>Coût d’achat estimé des articles vendus</td></tr>
<tr><td>Dépenses totales</td><td class="amount"><?=money($totalDepenses)?></td><td>Dont <?=money($totalDtf)?> de DTF</td></tr>
<tr><td><b>BÉNÉFICE NET ESTIMÉ</b></td><td class="amount"><b><?=money($beneficeNet)?></b></td><td>Marge <?=number_format($marge,1,',',' ')?> %</td></tr>
</tbody></table></div>
</div>
</main>
</div>

<script>
const labels=<?=$labels?>;
const ventes=<?=$ventesData?>;
const prestations=<?=$prestData?>;
const recettes=<?=$recData?>;
const depenses=<?=$depData?>;

new Chart(document.getElementById('activityChart'),{
 type:'line',
 data:{
  labels:labels,
  datasets:[
   {label:'Ventes',data:ventes,tension:.35,borderWidth:2,fill:false},
   {label:'Prestations',data:prestations,tension:.35,borderWidth:2,fill:false},
   {label:'Recettes',data:recettes,tension:.35,borderWidth:2,fill:false},
   {label:'Dépenses',data:depenses,tension:.35,borderWidth:2,fill:false}
  ]
 },
 options:{
  responsive:true,
  maintainAspectRatio:false,
  plugins:{legend:{position:'bottom'}},
  scales:{y:{beginAtZero:true,ticks:{callback:v=>new Intl.NumberFormat('fr-FR').format(v)+' FG'}}}
 }
});
</script>
</body>
</html>
