<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["id"])) {
    header("Location: index.php");
    exit;
}

/* =========================
   DONNÉES STATISTIQUES
========================= */

// Produits
$total_produits = 0;
$stock_total = 0;
$valeur_stock = 0;

$result = $conn->query("
    SELECT 
        COUNT(*) AS total_produits,
        COALESCE(SUM(stock), 0) AS stock_total,
        COALESCE(SUM(stock * prix_achat), 0) AS valeur_stock
    FROM produits
");

if ($result) {
    $data = $result->fetch_assoc();
    $total_produits = (int)$data["total_produits"];
    $stock_total = (int)$data["stock_total"];
    $valeur_stock = (float)$data["valeur_stock"];
}

// Ventes
$total_ventes = 0;
$montant_ventes = 0;

$result = $conn->query("
    SELECT 
        COUNT(*) AS total_ventes,
        COALESCE(SUM(montant), 0) AS montant_ventes
    FROM ventes
");

if ($result) {
    $data = $result->fetch_assoc();
    $total_ventes = (int)$data["total_ventes"];
    $montant_ventes = (float)$data["montant_ventes"];
}

// Recettes
$total_recettes = 0;
$montant_recettes = 0;

$result = $conn->query("
    SELECT 
        COUNT(*) AS total_recettes,
        COALESCE(SUM(montant), 0) AS montant_recettes
    FROM recettes
");

if ($result) {
    $data = $result->fetch_assoc();
    $total_recettes = (int)$data["total_recettes"];
    $montant_recettes = (float)$data["montant_recettes"];
}

// Dépenses
$total_depenses = 0;
$montant_depenses = 0;

$result = $conn->query("
    SELECT 
        COUNT(*) AS total_depenses,
        COALESCE(SUM(montant), 0) AS montant_depenses
    FROM depenses
");

if ($result) {
    $data = $result->fetch_assoc();
    $total_depenses = (int)$data["total_depenses"];
    $montant_depenses = (float)$data["montant_depenses"];
}

// Résultat global
$revenus = $montant_ventes + $montant_recettes;
$resultat = $revenus - $montant_depenses;


/* =========================
   FORMATAGE
========================= */

function fcfa($montant) {
    return number_format((float)$montant, 0, ',', ' ') . " FG";
}
?>

<!DOCTYPE html>

<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

```
<title>Statistiques - LAMBEMAH GESTION</title>

<style>
    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    body {
        font-family: Arial, sans-serif;
        background: #f4f8fc;
        color: #172b4d;
        min-height: 100vh;
    }

    /* HEADER */
    .header {
        background: linear-gradient(135deg, #071b3a, #0d6efd);
        color: white;
        padding: 22px 25px;
        border-radius: 0 0 25px 25px;
        box-shadow: 0 5px 20px rgba(7, 27, 58, .18);
    }

    .header-content {
        max-width: 1200px;
        margin: auto;
    }

    .header h1 {
        font-size: 25px;
        margin-bottom: 5px;
    }

    .header p {
        opacity: .85;
        font-size: 14px;
    }

    /* CONTENU */
    .container {
        max-width: 1200px;
        margin: auto;
        padding: 25px 18px 40px;
    }

    .back {
        display: inline-block;
        margin-bottom: 20px;
        text-decoration: none;
        color: #0d6efd;
        font-weight: bold;
    }

    /* CARTES */
    .cards {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 18px;
        margin-bottom: 25px;
    }

    .card {
        background: white;
        border-radius: 18px;
        padding: 22px;
        box-shadow: 0 6px 22px rgba(7, 27, 58, .08);
        border: 1px solid #e8eef5;
    }

    .card .icon {
        font-size: 28px;
        margin-bottom: 12px;
    }

    .card .label {
        color: #718096;
        font-size: 13px;
        margin-bottom: 8px;
    }

    .card .value {
        font-size: 23px;
        font-weight: bold;
        color: #071b3a;
    }

    /* RESULTAT */
    .result {
        background: linear-gradient(135deg, #071b3a, #0d6efd);
        color: white;
        border-radius: 22px;
        padding: 28px;
        margin-bottom: 25px;
        box-shadow: 0 8px 25px rgba(7, 27, 58, .18);
    }

    .result h2 {
        font-size: 18px;
        margin-bottom: 15px;
    }

    .result .amount {
        font-size: 32px;
        font-weight: bold;
    }

    .result p {
        margin-top: 8px;
        opacity: .85;
        font-size: 13px;
    }

    /* DETAILS */
    .section {
        background: white;
        border-radius: 20px;
        padding: 22px;
        box-shadow: 0 6px 22px rgba(7, 27, 58, .07);
    }

    .section h2 {
        margin-bottom: 18px;
        color: #071b3a;
        font-size: 19px;
    }

    .row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px 5px;
        border-bottom: 1px solid #edf1f6;
    }

    .row:last-child {
        border-bottom: none;
    }

    .row span:first-child {
        color: #64748b;
    }

    .row strong {
        color: #071b3a;
    }

    /* MOBILE */
    @media (max-width: 850px) {
        .cards {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 550px) {
        .header {
            padding: 20px 17px;
            border-radius: 0 0 20px 20px;
        }

        .header h1 {
            font-size: 21px;
        }

        .container {
            padding: 20px 13px 35px;
        }

        .cards {
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .card {
            padding: 17px 14px;
            border-radius: 15px;
        }

        .card .icon {
            font-size: 24px;
        }

        .card .value {
            font-size: 18px;
        }

        .result {
            padding: 22px;
            border-radius: 18px;
        }

        .result .amount {
            font-size: 26px;
        }
    }
</style>
```

</head>

<body>

<header class="header">
    <div class="header-content">
        <h1>📊 Statistiques</h1>
        <p>LAMBEMAH GESTION — Vue générale de votre activité</p>
    </div>
</header>

<div class="container">

```
<a href="index.php" class="back">← Retour au tableau de bord</a>

<div class="cards">

    <div class="card">
        <div class="icon">📦</div>
        <div class="label">Produits</div>
        <div class="value"><?= $total_produits ?></div>
    </div>

    <div class="card">
        <div class="icon">🛍️</div>
        <div class="label">Ventes</div>
        <div class="value"><?= $total_ventes ?></div>
    </div>

    <div class="card">
        <div class="icon">💰</div>
        <div class="label">Recettes</div>
        <div class="value"><?= fcfa($montant_recettes) ?></div>
    </div>

    <div class="card">
        <div class="icon">💸</div>
        <div class="label">Dépenses</div>
        <div class="value"><?= fcfa($montant_depenses) ?></div>
    </div>

</div>

<div class="result">

    <h2>💎 Résultat actuel</h2>

    <div class="amount">
        <?= fcfa($resultat) ?>
    </div>

    <p>
        Ventes + recettes − dépenses
    </p>

</div>

<div class="section">

    <h2>📋 Détails de l'activité</h2>

    <div class="row">
        <span>🛍️ Montant total des ventes</span>
        <strong><?= fcfa($montant_ventes) ?></strong>
    </div>

    <div class="row">
        <span>💰 Total des recettes</span>
        <strong><?= fcfa($montant_recettes) ?></strong>
    </div>

    <div class="row">
        <span>💸 Total des dépenses</span>
        <strong><?= fcfa($montant_depenses) ?></strong>
    </div>

    <div class="row">
        <span>📦 Quantité totale en stock</span>
        <strong><?= $stock_total ?></strong>
    </div>

    <div class="row">
        <span>💼 Valeur du stock à l'achat</span>
        <strong><?= fcfa($valeur_stock) ?></strong>
    </div>

</div>
```

</div>

</body>
</html>
