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

    $client = trim($_POST["client"] ?? "");
    $format = trim($_POST["format"] ?? "A4");
    $quantite = (int)($_POST["quantite"] ?? 1);
    $prix_dtf = (float)($_POST["prix_dtf"] ?? 5000);
    $prix_impression = (float)($_POST["prix_impression"] ?? 0);

    if ($quantite <= 0 || $prix_dtf < 0 || $prix_impression < 0) {
        $message = "Veuillez vérifier les informations.";
    } else {

        $total = ($prix_dtf + $prix_impression) * $quantite;

        $libelle = "Prestation DTF - " . $format;

        if ($client !== "") {
            $libelle .= " - " . $client;
        }

        $description =
            "Client : " . ($client ?: "Non précisé") .
            " | Format : " . $format .
            " | Quantité : " . $quantite .
            " | DTF fournisseur : " . number_format($prix_dtf, 0, ',', ' ') . " GNF/unité" .
            " | Impression : " . number_format($prix_impression, 0, ',', ' ') . " GNF/unité";

        $stmt = $conn->prepare(
            "INSERT INTO recettes (libelle, montant, description)
             VALUES (?, ?, ?)"
        );

        if ($stmt) {
            $stmt->bind_param("sds", $libelle, $total, $description);

            if ($stmt->execute()) {
                $stmt->close();
                header("Location: prestations.php?success=1");
                exit;
            }

            $message = "Erreur lors de l'enregistrement.";
            $stmt->close();
        } else {
            $message = "Erreur SQL : " . $conn->error;
        }
    }
}

$prestations = $conn->query(
    "SELECT *
     FROM recettes
     WHERE libelle LIKE 'Prestation DTF%'
     ORDER BY date_recette DESC"
);

function gnf($montant) {
    return number_format((float)$montant, 0, ',', ' ') . " GNF";
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Prestations - LAMBEMAH GESTION</title>

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

.info {
    background: #eaf8ff;
    padding: 15px;
    border-radius: 12px;
    margin-bottom: 20px;
    font-size: 13px;
    line-height: 1.6;
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
    min-width: 650px;
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
    color: #16834d;
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
<li><a href="prestations.php" class="active">🖨️ Prestations</a></li>
<li><a href="depenses.php">💸 Dépenses</a></li>
<li><a href="statistiques.php">📊 Statistiques</a></li>
<li><a href="utilisateurs.php">👥 Utilisateurs</a></li>

</ul>

<div class="sidebar-bottom">
<a class="logout" href="index.php?logout=1">🚪 Déconnexion</a>
</div>

</aside>

<main class="main">

<div class="header">

<h1>Prestations 🖨️</h1>

<p>
Gestion des impressions DTF pour les clients.
</p>

</div>

<div class="grid">

<div class="card">

<h2>➕ Nouvelle prestation</h2>

<?php if ($message): ?>
<div class="message">
<?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<?php if (isset($_GET["success"])): ?>
<div class="success">
✅ Prestation enregistrée avec succès.
</div>
<?php endif; ?>

<div class="info">

<strong>💡 DTF fournisseur</strong><br>

A4 = <strong>5 000 GNF</strong>

<br><br>

Le client peut apporter son propre T-shirt.
Tu renseignes ensuite ton prix d'impression.

</div>

<form method="POST">

<div class="group">
<label>Nom du client</label>
<input type="text" name="client" placeholder="Ex : Client Mamadou">
</div>

<div class="group">
<label>Format</label>
<input type="text" name="format" value="A4">
</div>

<div class="group">
<label>Quantité</label>
<input type="number" name="quantite" value="1" min="1" required>
</div>

<div class="group">
<label>Prix DTF fournisseur / unité</label>
<input type="number" name="prix_dtf" value="5000" min="0" required>
</div>

<div class="group">
<label>Prix de ton impression / unité</label>
<input type="number" name="prix_impression" placeholder="Ex : 10000" min="0" required>
</div>

<button type="submit">
🖨️ Enregistrer la prestation
</button>

</form>

</div>

<div class="card">

<h2>📋 Prestations récentes</h2>

<div class="table-container">

<table>

<thead>
<tr>
<th>PRESTATION</th>
<th>MONTANT</th>
<th>DÉTAIL</th>
<th>DATE</th>
</tr>
</thead>

<tbody>

<?php if ($prestations && $prestations->num_rows > 0): ?>

<?php while ($p = $prestations->fetch_assoc()): ?>

<tr>

<td><?= htmlspecialchars($p["libelle"]) ?></td>

<td class="amount">
<?= gnf($p["montant"]) ?>
</td>

<td>
<?= htmlspecialchars($p["description"]) ?>
</td>

<td>
<?= date("d/m/Y H:i", strtotime($p["date_recette"])) ?>
</td>

</tr>

<?php endwhile; ?>

<?php else: ?>

<tr>
<td colspan="4">Aucune prestation enregistrée.</td>
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
