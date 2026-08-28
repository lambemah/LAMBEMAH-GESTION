```php
<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["id"])) {
    header("Location: index.php");
    exit;
}

$message = "";

/* AJOUT RECETTE */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajouter_recette"])) {

    $libelle = trim($_POST["libelle"] ?? "");
    $montant = floatval($_POST["montant"] ?? 0);
    $description = trim($_POST["description"] ?? "");

    if ($libelle === "" || $montant <= 0) {
        $message = "Veuillez remplir correctement les informations.";
    } else {

        $stmt = $conn->prepare(
            "INSERT INTO recettes (libelle, montant, description)
             VALUES (?, ?, ?)"
        );

        $stmt->bind_param("sds", $libelle, $montant, $description);

        if ($stmt->execute()) {
            $message = "Recette enregistrée avec succès.";
        } else {
            $message = "Erreur lors de l'enregistrement.";
        }

        $stmt->close();
    }
}

/* SUPPRESSION */
if (isset($_GET["supprimer"])) {

    $id = intval($_GET["supprimer"]);

    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM recettes WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        header("Location: recettes.php");
        exit;
    }
}

/* TOTAL */
$total_recettes = 0;

$result_total = $conn->query(
    "SELECT COALESCE(SUM(montant),0) AS total FROM recettes"
);

if ($result_total) {
    $row_total = $result_total->fetch_assoc();
    $total_recettes = $row_total["total"];
}

/* LISTE */
$recettes = $conn->query(
    "SELECT * FROM recettes ORDER BY date_recette DESC"
);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Recettes - LAMBEMAH GESTION</title>

<style>
*{
    box-sizing:border-box;
}

body{
    margin:0;
    font-family:Arial,sans-serif;
    background:#f4f8fc;
    color:#172033;
}

.header{
    background:linear-gradient(135deg,#071b3a,#0d4f8b);
    color:white;
    padding:22px 18px;
    border-radius:0 0 25px 25px;
}

.header h1{
    margin:0;
    font-size:25px;
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

.card{
    background:white;
    border-radius:18px;
    padding:20px;
    margin-bottom:20px;
    box-shadow:0 8px 25px rgba(0,0,0,.06);
}

.total{
    background:linear-gradient(135deg,#0d6efd,#063d78);
    color:white;
    padding:22px;
    border-radius:18px;
    margin-bottom:20px;
}

.total small{
    opacity:.8;
}

.total strong{
    display:block;
    font-size:30px;
    margin-top:8px;
}

input,textarea{
    width:100%;
    padding:13px;
    border:1px solid #dce4ed;
    border-radius:10px;
    margin-top:7px;
    margin-bottom:15px;
    font-size:15px;
}

textarea{
    min-height:80px;
    resize:vertical;
}

button{
    width:100%;
    border:0;
    padding:14px;
    border-radius:10px;
    background:#0d6efd;
    color:white;
    font-weight:bold;
    cursor:pointer;
}

.message{
    padding:13px;
    background:#e8f5e9;
    color:#176b2c;
    border-radius:10px;
    margin-bottom:15px;
}

table{
    width:100%;
    border-collapse:collapse;
}

th,td{
    padding:13px 8px;
    border-bottom:1px solid #edf1f5;
    text-align:left;
}

th{
    color:#0b4b80;
}

.amount{
    color:#087f3e;
    font-weight:bold;
}

.delete{
    color:#d62828;
    text-decoration:none;
    font-weight:bold;
}

.back{
    display:inline-block;
    margin-bottom:15px;
    color:#0d6efd;
    text-decoration:none;
    font-weight:bold;
}

@media(max-width:650px){

    .container{
        padding:12px;
    }

    .card{
        padding:15px;
    }

    table{
        font-size:13px;
    }

    th:nth-child(3),
    td:nth-child(3){
        display:none;
    }

    .total strong{
        font-size:25px;
    }
}
</style>
</head>

<body>

<div class="header">
    <h1>💵 Recettes</h1>
    <p>LAMBEMAH GESTION</p>
</div>

<div class="container">

<a class="back" href="index.php">← Retour à l'accueil</a>

<div class="total">
    <small>Total des recettes</small>
    <strong><?= number_format($total_recettes,0,","," ") ?> FG</strong>
</div>

<div class="card">

<h2>➕ Nouvelle recette</h2>

<?php if ($message): ?>
<div class="message"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<form method="POST">

<label>Libellé</label>
<input type="text" name="libelle"
       placeholder="Ex : Impression DTF client"
       required>

<label>Montant (FG)</label>
<input type="number" name="montant"
       placeholder="Ex : 15000"
       min="1"
       required>

<label>Description</label>
<textarea name="description"
          placeholder="Détails de la recette..."></textarea>

<button type="submit" name="ajouter_recette">
Enregistrer la recette
</button>

</form>

</div>

<div class="card">

<h2>📋 Historique des recettes</h2>

<div style="overflow-x:auto">

<table>

<tr>
<th>Libellé</th>
<th>Montant</th>
<th>Description</th>
<th>Date</th>
<th></th>
</tr>

<?php while($r = $recettes->fetch_assoc()): ?>

<tr>

<td><?= htmlspecialchars($r["libelle"]) ?></td>

<td class="amount">
<?= number_format($r["montant"],0,","," ") ?> FG
</td>

<td><?= htmlspecialchars($r["description"]) ?></td>

<td><?= date("d/m/Y H:i", strtotime($r["date_recette"])) ?></td>

<td>
<a class="delete"
   href="?supprimer=<?= $r["id"] ?>"
   onclick="return confirm('Supprimer cette recette ?')">
✕
</a>
</td>

</tr>

<?php endwhile; ?>

</table>

</div>

</div>

</div>

</body>
</html>
```
