```php
<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["id"])) {
    header("Location: index.php");
    exit;
}

function somme($conn, $table, $colonne) {

    $sql = "SELECT COALESCE(SUM($colonne), 0) AS total FROM $table";

    $result = $conn->query($sql);

    if ($result) {
        $row = $result->fetch_assoc();
        return (float)$row["total"];
    }

    return 0;
}

function compter($conn, $table) {

    $result = $conn->query(
        "SELECT COUNT(*) AS total FROM $table"
    );

    if ($result) {
        $row = $result->fetch_assoc();
        return (int)$row["total"];
    }

    return 0;
}

/*
|--------------------------------------------------------------------------
| CALCULS
|--------------------------------------------------------------------------
*/

$totalVentes = somme($conn, "ventes", "montant");

$totalRecettes = somme($conn, "recettes", "montant");

$totalDepenses = somme($conn, "depenses", "montant");

$totalProduits = compter($conn, "produits");

$totalVentesNombre = compter($conn, "ventes");

$totalPrestations = $conn->query(
    "SELECT COUNT(*) AS total
     FROM recettes
     WHERE libelle LIKE 'Prestation DTF%'"
);

$nombrePrestations = 0;

if ($totalPrestations) {
    $nombrePrestations =
        (int)$totalPrestations->fetch_assoc()["total"];
}

$resultStock = $conn->query(
    "SELECT COALESCE(SUM(stock),0) AS total
     FROM produits"
);

$stockTotal = 0;

if ($resultStock) {
    $stockTotal =
        (int)$resultStock->fetch_assoc()["total"];
}

$totalActivite = $totalVentes + $totalRecettes;

$solde = $totalActivite - $totalDepenses;

function gnf($montant) {
    return number_format((float)$montant, 0, ',', ' ') . " GNF";
}

?>

<!DOCTYPE html>

<html lang="fr">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Statistiques - LAMBEMAH GESTION</title>

<style>

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, sans-serif;
    background: #f4faff;
    color: #263746;
}

.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    bottom: 0;
    width: 245px;
    background: linear-gradient(180deg, #55c7ed, #168dcc);
    padding: 25px 15px;
    color: white;
}

.brand {
    padding: 5px 12px 28px;
}

.brand-icon {
    font-size: 30px;
}

.brand h2 {
    font-size: 21px;
    margin-top: 5px;
}

.brand span {
    font-size: 11px;
    opacity: .85;
}

.nav {
    list-style: none;
}

.nav li {
    margin: 5px 0;
}

.nav a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 14px;
    color: white;
    text-decoration: none;
    border-radius: 12px;
    font-size: 14px;
}

.nav a:hover,
.nav a.active {
    background: rgba(255,255,255,.22);
}

.sidebar-bottom {
    position: absolute;
    bottom: 20px;
    left: 15px;
    right: 15px;
}

.logout {
    display: block;
    color: white;
    text-decoration: none;
    padding: 13px;
    border-radius: 12px;
    background: rgba(255,255,255,.12);
}

.main {
    margin-left: 245px;
    padding: 30px;
}

.header {
    margin-bottom: 25px;
}

.header h1 {
    font-size: 28px;
}

.header p {
    margin-top: 7px;
    color: #81919a;
}

.cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px;
}

.card {
    background: white;
    border-radius: 18px;
    padding: 22px;
    box-shadow: 0 5px 20px #dfeef4;
}

.card-icon {
    font-size: 27px;
    margin-bottom: 15px;
}

.card-label {
    color: #89969d;
    font-size: 12px;
    font-weight: bold;
}

.card-value {
    color: #168dcc;
    font-size: 23px;
    font-weight: bold;
    margin-top: 9px;
}

.solde {
    background: linear-gradient(135deg, #168dcc, #55c7ed);
    color: white;
}

.solde .card-label,
.solde .card-value {
    color: white;
}

.section {
    margin-top: 22px;
}

.section h2 {
    font-size: 18px;
    margin-bottom: 17px;
}

.stats-list {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.stat-line {
    background: white;
    border-radius: 14px;
    padding: 17px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 4px 15px #e2eff4;
}

.stat-line span {
    color: #71828c;
    font-size: 13px;
}

.stat-line strong {
    color: #263746;
}

.info {
    background: #eaf8ff;
    border-radius: 14px;
    padding: 18px;
    margin-top: 20px;
    line-height: 1.6;
    font-size: 13px;
}

@media(max-width:1050px) {

    .cards {
        grid-template-columns: repeat(2, 1fr);
    }

}

@media(max-width:700px) {

    .sidebar {
        position: relative;
        width: 100%;
        padding: 15px;
    }

    .nav {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
    }

    .nav a {
        flex-direction: column;
        justify-content: center;
        font-size: 10px;
        gap: 5px;
    }

    .sidebar-bottom {
        position: static;
        margin-top: 10px;
    }

    .main {
        margin-left: 0;
        padding: 18px;
    }

    .cards,
    .stats-list {
        grid-template-columns: 1fr;
    }

}

</style>

</head>

<body>

<aside class="sidebar">

<div class="brand">

<div class="brand-icon">💼</div>

<h2>LAMBEMAH</h2>

<span>GESTION • PRESTATION</span>

</div>

<ul class="nav">

<li>
<a href="index.php">
🏠 Accueil
</a>
</li>

<li>
<a href="produits.php">
📦 Produits
</a>
</li>

<li>
<a href="ventes.php">
💰 Ventes
</a>
</li>

<li>
<a href="prestations.php">
🖨️ Prestations
</a>
</li>

<li>
<a href="depenses.php">
💸 Dépenses
</a>
</li>

<li>
<a href="statistiques.php" class="active">
📊 Statistiques
</a>
</li>

<li>
<a href="utilisateurs.php">
👥 Utilisateurs
</a>
</li>

</ul>

<div class="sidebar-bottom">

<a class="logout" href="index.php?logout=1">
🚪 Déconnexion
</a>

</div>

</aside>

<main class="main">

<div class="header">

<h1>Tableau de bord 📊</h1>

<p>
Voici la situation actuelle de LAMBEMAH GESTION.
</p>

</div>


<div class="cards">


<div class="card">

<div class="card-icon">
💰
</div>

<div class="card-label">
VENTES
</div>

<div class="card-value">
<?= gnf($totalVentes) ?>
</div>

</div>


<div class="card">

<div class="card-icon">
🖨️
</div>

<div class="card-label">
PRESTATIONS / RECETTES
</div>

<div class="card-value">
<?= gnf($totalRecettes) ?>
</div>

</div>


<div class="card">

<div class="card-icon">
💸
</div>

<div class="card-label">
DÉPENSES
</div>

<div class="card-value">
<?= gnf($totalDepenses) ?>
</div>

</div>


<div class="card solde">

<div class="card-icon">
📈
</div>

<div class="card-label">
SOLDE
</div>

<div class="card-value">
<?= gnf($solde) ?>
</div>

</div>

</div>


<div class="section">

<h2>📌 Résumé de l'activité</h2>

<div class="stats-list">


<div class="stat-line">

<span>📦 Nombre de produits</span>

<strong>
<?= $totalProduits ?>
</strong>

</div>


<div class="stat-line">

<span>📦 Stock total</span>

<strong>
<?= $stockTotal ?>
</strong>

</div>


<div class="stat-line">

<span>💰 Nombre de ventes</span>

<strong>
<?= $totalVentesNombre ?>
</strong>

</div>


<div class="stat-line">

<span>🖨️ Nombre de prestations</span>

<strong>
<?= $nombrePrestations ?>
</strong>

</div>


<div class="stat-line">

<span>💵 Activité totale</span>

<strong>
<?= gnf($totalActivite) ?>
</strong>

</div>


<div class="stat-line">

<span>📉 Résultat</span>

<strong>
<?= gnf($solde) ?>
</strong>

</div>


</div>

</div>


<div class="info">

<strong>💡 LAMBEMAH GESTION</strong><br>

Cette page rassemble automatiquement les ventes,
les prestations DTF, les recettes et les dépenses
enregistrées dans ton application.

</div>

</main>

</body>

</html>
```
