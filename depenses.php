```php
<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["id"])) {
    header("Location: index.php");
    exit;
}

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $libelle = trim($_POST["libelle"] ?? "");
    $montant = (float)($_POST["montant"] ?? 0);
    $description = trim($_POST["description"] ?? "");

    if ($libelle === "" || $montant <= 0) {

        $message = "Veuillez remplir correctement les champs.";

    } else {

        $stmt = $conn->prepare(
            "INSERT INTO depenses (libelle, montant, description)
             VALUES (?, ?, ?)"
        );

        if ($stmt) {

            $stmt->bind_param(
                "sds",
                $libelle,
                $montant,
                $description
            );

            if ($stmt->execute()) {

                $stmt->close();

                header("Location: depenses.php?success=1");
                exit;

            } else {

                $message = "Erreur lors de l'enregistrement.";
                $stmt->close();
            }

        } else {

            $message = "Erreur SQL : " . $conn->error;
        }
    }
}

$depenses = $conn->query(
    "SELECT *
     FROM depenses
     ORDER BY date_depense DESC"
);

$total = 0;
$liste = [];

if ($depenses) {

    while ($d = $depenses->fetch_assoc()) {

        $total += (float)$d["montant"];

        $liste[] = $d;
    }
}

function gnf($montant) {
    return number_format((float)$montant, 0, ',', ' ') . " GNF";
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Dépenses - LAMBEMAH GESTION</title>

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

.grid {
    display: grid;
    grid-template-columns: 350px 1fr;
    gap: 20px;
}

.card {
    background: white;
    border-radius: 18px;
    padding: 23px;
    box-shadow: 0 5px 20px #dfeef4;
}

.card h2 {
    font-size: 18px;
    margin-bottom: 20px;
}

.total {
    background: #eaf8ff;
    border-radius: 14px;
    padding: 18px;
    margin-bottom: 20px;
}

.total small {
    color: #81919a;
}

.total strong {
    display: block;
    margin-top: 7px;
    font-size: 24px;
    color: #168dcc;
}

.group {
    margin-bottom: 15px;
}

.group label {
    display: block;
    font-size: 12px;
    font-weight: bold;
    margin-bottom: 7px;
}

.group input {
    width: 100%;
    padding: 12px;
    border: 1px solid #dce8ed;
    border-radius: 10px;
    font-size: 14px;
}

button {
    width: 100%;
    padding: 13px;
    border: none;
    border-radius: 10px;
    background: #168dcc;
    color: white;
    font-weight: bold;
    cursor: pointer;
}

button:hover {
    background: #0d78ad;
}

.message {
    background: #fff0f0;
    color: #c62828;
    padding: 12px;
    border-radius: 10px;
    margin-bottom: 15px;
}

.success {
    background: #eafaf1;
    color: #16834d;
    padding: 12px;
    border-radius: 10px;
    margin-bottom: 15px;
}

.table-container {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 600px;
}

th,
td {
    padding: 13px;
    text-align: left;
    border-bottom: 1px solid #edf3f6;
    font-size: 13px;
}

th {
    color: #89969d;
    font-size: 11px;
}

.amount {
    color: #d77b19;
    font-weight: bold;
}

@media(max-width: 850px) {

    .grid {
        grid-template-columns: 1fr;
    }

}

@media(max-width: 700px) {

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

<li><a href="index.php">🏠 Accueil</a></li>
<li><a href="produits.php">📦 Produits</a></li>
<li><a href="ventes.php">💰 Ventes</a></li>
<li><a href="prestations.php">🖨️ Prestations</a></li>
<li><a href="depenses.php" class="active">💸 Dépenses</a></li>
<li><a href="statistiques.php">📊 Statistiques</a></li>
<li><a href="utilisateurs.php">👥 Utilisateurs</a></li>

</ul>

<div class="sidebar-bottom">

<a class="logout" href="index.php?logout=1">
🚪 Déconnexion
</a>

</div>

</aside>

<main class="main">

<div class="header">

<h1>Dépenses 💸</h1>

<p>
Suivez les dépenses de votre activité.
</p>

</div>

<div class="grid">

<div class="card">

<h2>➕ Nouvelle dépense</h2>

<?php if ($message): ?>

<div class="message">
<?= htmlspecialchars($message) ?>
</div>

<?php endif; ?>

<?php if (isset($_GET["success"])): ?>

<div class="success">
✅ Dépense enregistrée avec succès.
</div>

<?php endif; ?>

<form method="POST">

<div class="group">

<label>Libellé</label>

<input
type="text"
name="libelle"
placeholder="Ex : Achat de T-shirts"
required
>

</div>

<div class="group">

<label>Montant (GNF)</label>

<input
type="number"
name="montant"
min="1"
placeholder="Ex : 150000"
required
>

</div>

<div class="group">

<label>Description</label>

<input
type="text"
name="description"
placeholder="Ex : Achat fournisseur"
>

</div>

<button type="submit">
💸 Enregistrer la dépense
</button>

</form>

</div>

<div class="card">

<div class="total">

<small>Total des dépenses</small>

<strong>
<?= gnf($total) ?>
</strong>

</div>

<h2>📋 Historique des dépenses</h2>

<div class="table-container">

<table>

<thead>

<tr>
<th>LIBELLÉ</th>
<th>MONTANT</th>
<th>DESCRIPTION</th>
<th>DATE</th>
</tr>

</thead>

<tbody>

<?php if (count($liste) > 0): ?>

<?php foreach ($liste as $d): ?>

<tr>

<td>
<?= htmlspecialchars($d["libelle"]) ?>
</td>

<td class="amount">
<?= gnf($d["montant"]) ?>
</td>

<td>
<?= htmlspecialchars($d["description"] ?: "—") ?>
</td>

<td>
<?= date("d/m/Y H:i", strtotime($d["date_depense"])) ?>
</td>

</tr>

<?php endforeach; ?>

<?php else: ?>

<tr>
<td colspan="4">
Aucune dépense enregistrée.
</td>
</tr>

<?php endif; ?>

</tbody>

</table>

</div>

</div>

</div>

</main>

</body>
</html>
```
