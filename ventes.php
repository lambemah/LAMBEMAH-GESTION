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

    $produit_id = (int)($_POST["produit_id"] ?? 0);
    $quantite = (int)($_POST["quantite"] ?? 0);
    $description = trim($_POST["description"] ?? "");

    if ($produit_id <= 0 || $quantite <= 0) {
        $message = "Veuillez sélectionner un produit et une quantité valide.";
    } else {

        $stmt = $conn->prepare(
            "SELECT nom, prix_vente, stock FROM produits WHERE id = ?"
        );
        $stmt->bind_param("i", $produit_id);
        $stmt->execute();
        $produit = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$produit) {
            $message = "Produit introuvable.";
        } elseif ((int)$produit["stock"] < $quantite) {
            $message = "Stock insuffisant.";
        } else {

            $prix = (float)$produit["prix_vente"];
            $montant = $prix * $quantite;

            $stmt = $conn->prepare(
                "INSERT INTO ventes
                (produit_id, quantite, prix_unitaire, montant, description)
                VALUES (?, ?, ?, ?, ?)"
            );

            $stmt->bind_param(
                "iidds",
                $produit_id,
                $quantite,
                $prix,
                $montant,
                $description
            );

            if ($stmt->execute()) {

                $stmt->close();

                $stmt = $conn->prepare(
                    "UPDATE produits
                     SET stock = stock - ?
                     WHERE id = ?"
                );

                $stmt->bind_param("ii", $quantite, $produit_id);
                $stmt->execute();
                $stmt->close();

                header("Location: ventes.php");
                exit;

            } else {
                $message = "Impossible d'enregistrer la vente.";
                $stmt->close();
            }
        }
    }
}

$produits = $conn->query(
    "SELECT id, nom, prix_vente, stock
     FROM produits
     ORDER BY nom ASC"
);

$ventes = $conn->query(
    "SELECT v.*, p.nom AS produit
     FROM ventes v
     LEFT JOIN produits p ON p.id = v.produit_id
     ORDER BY v.date_vente DESC
     LIMIT 30"
);

function gnf($n) {
    return number_format((float)$n, 0, ',', ' ') . " GNF";
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>LAMBEMAH GESTION - Ventes</title>

<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial;background:#f5faff;color:#263746}
.sidebar{position:fixed;left:0;top:0;bottom:0;width:245px;background:linear-gradient(180deg,#53c5eb,#168dcc);color:white;padding:25px 15px}
.brand{padding:5px 12px 28px}.brand-icon{font-size:28px;margin-bottom:10px}.brand h2{font-size:21px}.brand span{font-size:11px;opacity:.8}
.nav{list-style:none}.nav li{margin:5px 0}.nav a{display:flex;gap:12px;padding:13px 14px;border-radius:12px;color:white;text-decoration:none;font-size:14px}.nav a:hover,.nav a.active{background:rgba(255,255,255,.2)}
.sidebar-bottom{position:absolute;bottom:20px;left:15px;right:15px}.logout{display:block;color:white;text-decoration:none;padding:13px;border-radius:12px;background:rgba(255,255,255,.12)}
.main{margin-left:245px;padding:30px}
.header{display:flex;justify-content:space-between;margin-bottom:25px}.header h1{font-size:27px}.header p{color:#81919a;margin-top:6px}
.profile{background:white;padding:10px 15px;border-radius:30px}.avatar{display:inline-flex;width:35px;height:35px;border-radius:50%;background:#dff5ff;color:#168dcc;align-items:center;justify-content:center;font-weight:bold}
.grid{display:grid;grid-template-columns:330px 1fr;gap:20px}
.card{background:white;border-radius:18px;padding:22px;box-shadow:0 5px 20px #dfeef4}.card h2{font-size:17px;margin-bottom:20px}
.group{margin-bottom:15px}.group label{display:block;font-size:12px;font-weight:bold;margin-bottom:7px}.group input,.group select{width:100%;padding:12px;border:1px solid #dce8ed;border-radius:10px}
button{width:100%;padding:13px;border:0;border-radius:10px;background:#168dcc;color:white;font-weight:bold;cursor:pointer}
.error{background:#fff0f0;color:#c62828;padding:12px;border-radius:10px;margin-bottom:15px}
.table{overflow:auto}table{width:100%;border-collapse:collapse;min-width:650px}th,td{text-align:left;padding:13px;border-bottom:1px solid #edf3f6;font-size:13px}th{font-size:11px;color:#89969d}
.amount{color:#16a26a;font-weight:bold}
@media(max-width:850px){.grid{grid-template-columns:1fr}}
@media(max-width:700px){.sidebar{position:relative;width:100%;padding:15px}.nav{display:grid;grid-template-columns:repeat(3,1fr)}.nav a{flex-direction:column;align-items:center;font-size:10px}.sidebar-bottom{position:static;margin-top:10px}.main{margin:0;padding:18px}}
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
<li><a href="ventes.php" class="active">💰 Ventes</a></li>
<li><a href="prestations.php">🖨️ Prestations</a></li>
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
<div>
<h1>Ventes 💰</h1>
<p>Enregistrez les ventes de vos produits.</p>
</div>

<div class="profile">
<span class="avatar"><?= strtoupper(substr($_SESSION["nom"],0,1)) ?></span>
</div>
</div>

<div class="grid">

<div class="card">

<h2>➕ Nouvelle vente</h2>

<?php if($message): ?>
<div class="error"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<form method="POST">

<div class="group">
<label>Produit</label>
<select name="produit_id" required>
<option value="">Choisir un produit</option>

<?php while($p=$produits->fetch_assoc()): ?>
<option value="<?= $p["id"] ?>">
<?= htmlspecialchars($p["nom"]) ?>
— <?= gnf($p["prix_vente"]) ?>
— Stock: <?= $p["stock"] ?>
</option>
<?php endwhile; ?>

</select>
</div>

<div class="group">
<label>Quantité</label>
<input type="number" name="quantite" min="1" required>
</div>

<div class="group">
<label>Description</label>
<input type="text" name="description" placeholder="Ex : Vente client">
</div>

<button type="submit">💰 Enregistrer la vente</button>

</form>

</div>

<div class="card">

<h2>📋 Ventes récentes</h2>

<div class="table">

<table>

<tr>
<th>PRODUIT</th>
<th>QUANTITÉ</th>
<th>PRIX</th>
<th>TOTAL</th>
<th>DATE</th>
</tr>

<?php if($ventes && $ventes->num_rows): ?>

<?php while($v=$ventes->fetch_assoc()): ?>

<tr>
<td><?= htmlspecialchars($v["produit"] ?: "Produit") ?></td>
<td><?= $v["quantite"] ?></td>
<td><?= gnf($v["prix_unitaire"]) ?></td>
<td class="amount"><?= gnf($v["montant"]) ?></td>
<td><?= date("d/m/Y H:i",strtotime($v["date_vente"])) ?></td>
</tr>

<?php endwhile; ?>

<?php else: ?>

<tr>
<td colspan="5">Aucune vente enregistrée.</td>
</tr>

<?php endif; ?>

</table>

</div>

</div>

</div>

</main>

</body>
</html>
```
