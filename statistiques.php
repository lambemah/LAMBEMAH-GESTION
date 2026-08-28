```php
<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["id"])) {
    header("Location: index.php");
    exit;
}

/* RECETTES */
$r = $conn->query(
    "SELECT COALESCE(SUM(montant),0) AS total FROM recettes"
);
$total_recettes = $r->fetch_assoc()["total"];

/* DEPENSES */
$d = $conn->query(
    "SELECT COALESCE(SUM(montant),0) AS total FROM depenses"
);
$total_depenses = $d->fetch_assoc()["total"];

/* VENTES */
$v = $conn->query(
    "SELECT 
        COUNT(*) AS nombre,
        COALESCE(SUM(total),0) AS total
     FROM ventes"
);

$ventes_data = $v->fetch_assoc();

$nombre_ventes = $ventes_data["nombre"];
$total_ventes = $ventes_data["total"];

/* PRODUITS */
$p = $conn->query(
    "SELECT 
        COUNT(*) AS nombre,
        COALESCE(SUM(stock),0) AS stock
     FROM produits"
);

$produits_data = $p->fetch_assoc();

$nombre_produits = $produits_data["nombre"];
$stock_total = $produits_data["stock"];

/* BENEFICE */
$benefice = ($total_recettes + $total_ventes) - $total_depenses;
?>

<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Statistiques - LAMBEMAH GESTION</title>

<style>

*{
box-sizing:border-box;
}

body{
margin:0;
font-family:Arial,sans-serif;
background:#f3f7fb;
color:#172033;
}

.header{
background:linear-gradient(135deg,#061a38,#0c5795);
color:white;
padding:25px 20px;
border-radius:0 0 28px 28px;
}

.header h1{
margin:0;
font-size:26px;
}

.header p{
margin:7px 0 0;
opacity:.85;
}

.container{
max-width:1100px;
margin:auto;
padding:20px;
}

.back{
display:inline-block;
margin-bottom:18px;
color:#0d6efd;
font-weight:bold;
text-decoration:none;
}

.grid{
display:grid;
grid-template-columns:repeat(2,1fr);
gap:15px;
}

.stat{
background:white;
padding:20px;
border-radius:18px;
box-shadow:0 8px 25px rgba(0,0,0,.06);
}

.icon{
font-size:30px;
}

.stat h3{
margin:12px 0 5px;
font-size:15px;
color:#687386;
}

.stat strong{
font-size:25px;
color:#092f58;
}

.big{
margin-top:20px;
background:linear-gradient(135deg,#071b3a,#0d6efd);
color:white;
padding:25px;
border-radius:20px;
box-shadow:0 10px 30px rgba(0,0,0,.1);
}

.big h2{
margin-top:0;
}

.big strong{
font-size:35px;
}

.section{
background:white;
margin-top:20px;
padding:20px;
border-radius:18px;
box-shadow:0 8px 25px rgba(0,0,0,.06);
}

.section h2{
margin-top:0;
}

.info{
padding:15px;
background:#f2f7fc;
border-radius:12px;
margin-top:10px;
}

@media(max-width:650px){

.container{
padding:12px;
}

.grid{
grid-template-columns:1fr 1fr;
gap:10px;
}

.stat{
padding:15px;
}

.stat strong{
font-size:20px;
}

.big{
padding:20px;
}

.big strong{
font-size:28px;
}

}

</style>

</head>

<body>

<div class="header">

<h1>📊 Statistiques</h1>

<p>
Vue générale de LAMBEMAH GESTION
</p>

</div>

<div class="container">

<a class="back" href="index.php">
← Retour à l'accueil
</a>

<div class="grid">

<div class="stat">

<div class="icon">💵</div>

<h3>Recettes</h3>

<strong>
<?= number_format($total_recettes,0,","," ") ?> FG
</strong>

</div>

<div class="stat">

<div class="icon">💸</div>

<h3>Dépenses</h3>

<strong>
<?= number_format($total_depenses,0,","," ") ?> FG
</strong>

</div>

<div class="stat">

<div class="icon">💰</div>

<h3>Ventes</h3>

<strong>
<?= number_format($total_ventes,0,","," ") ?> FG
</strong>

</div>

<div class="stat">

<div class="icon">📦</div>

<h3>Produits</h3>

<strong>
<?= $nombre_produits ?>
</strong>

</div>

<div class="stat">

<div class="icon">🛒</div>

<h3>Nombre de ventes</h3>

<strong>
<?= $nombre_ventes ?>
</strong>

</div>

<div class="stat">

<div class="icon">📦</div>

<h3>Stock total</h3>

<strong>
<?= $stock_total ?>
</strong>

</div>

</div>

<div class="big">

<h2>📈 Résultat actuel</h2>

<p>
Recettes + ventes − dépenses
</p>

<strong>
<?= number_format($benefice,0,","," ") ?> FG
</strong>

</div>

<div class="section">

<h2>💡 Suivi de l'activité</h2>

<div class="info">

<strong>DTF :</strong>

Ton coût fournisseur actuel pour un DTF A4 est de
<strong>5 000 FG</strong>.

</div>

<div class="info">

<strong>Prestations :</strong>

Les clients peuvent apporter leur propre T-shirt.
Tu peux enregistrer uniquement la prestation d'impression
et le montant facturé.

</div>

<div class="info">

<strong>Conseil de gestion :</strong>

Enregistre chaque entrée d'argent dans
<strong>Recettes</strong> et chaque sortie dans
<strong>Dépenses</strong>.
</div>

</div>

</div>

</body>

</html>
```
