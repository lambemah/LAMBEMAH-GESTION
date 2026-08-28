```php
<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["id"])) {
    header("Location: index.php");
    exit;
}

$message = "";

/* AJOUT DEPENSE */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajouter_depense"])) {

    $libelle = trim($_POST["libelle"] ?? "");
    $montant = floatval($_POST["montant"] ?? 0);
    $description = trim($_POST["description"] ?? "");

    if ($libelle === "" || $montant <= 0) {
        $message = "Veuillez remplir correctement les informations.";
    } else {

        $stmt = $conn->prepare(
            "INSERT INTO depenses (libelle, montant, description)
             VALUES (?, ?, ?)"
        );

        $stmt->bind_param("sds", $libelle, $montant, $description);

        if ($stmt->execute()) {
            $message = "Dépense enregistrée avec succès.";
        } else {
            $message = "Erreur lors de l'enregistrement.";
        }

        $stmt->close();
    }
}

/* SUPPRESSION */
if (isset($_GET["supprimer"])) {

    $id = intval($_GET["supprimer"]);

    $stmt = $conn->prepare("DELETE FROM depenses WHERE id=?");
    $stmt->bind_param("i",$id);
    $stmt->execute();
    $stmt->close();

    header("Location: depenses.php");
    exit;
}

/* TOTAL */
$result_total = $conn->query(
    "SELECT COALESCE(SUM(montant),0) AS total FROM depenses"
);

$total_depenses = $result_total->fetch_assoc()["total"];

/* LISTE */
$depenses = $conn->query(
    "SELECT * FROM depenses ORDER BY date_depense DESC"
);
?>

<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Dépenses - LAMBEMAH GESTION</title>

<style>

*{box-sizing:border-box}

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
background:linear-gradient(135deg,#dc3545,#8b1020);
color:white;
padding:22px;
border-radius:18px;
margin-bottom:20px;
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
}

button{
width:100%;
border:0;
padding:14px;
border-radius:10px;
background:#0d6efd;
color:white;
font-weight:bold;
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
color:#d62828;
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

<h1>💸 Dépenses</h1>
<p>LAMBEMAH GESTION</p>

</div>

<div class="container">

<a class="back" href="index.php">← Retour à l'accueil</a>

<div class="total">

<small>Total des dépenses</small>

<strong>
<?= number_format($total_depenses,0,","," ") ?> FG
</strong>

</div>

<div class="card">

<h2>➕ Nouvelle dépense</h2>

<?php if($message): ?>

<div class="message">
<?= htmlspecialchars($message) ?>
</div>

<?php endif; ?>

<form method="POST">

<label>Libellé</label>

<input type="text"
name="libelle"
placeholder="Ex : Achat DTF A4"
required>

<label>Montant (FG)</label>

<input type="number"
name="montant"
placeholder="Ex : 5000"
min="1"
required>

<label>Description</label>

<textarea
name="description"
placeholder="Détails de la dépense..."></textarea>

<button name="ajouter_depense">
Enregistrer la dépense
</button>

</form>

</div>

<div class="card">

<h2>📋 Historique</h2>

<div style="overflow-x:auto">

<table>

<tr>

<th>Libellé</th>
<th>Montant</th>
<th>Description</th>
<th>Date</th>
<th></th>

</tr>

<?php while($d=$depenses->fetch_assoc()): ?>

<tr>

<td>
<?= htmlspecialchars($d["libelle"]) ?>
</td>

<td class="amount">
<?= number_format($d["montant"],0,","," ") ?> FG
</td>

<td>
<?= htmlspecialchars($d["description"]) ?>
</td>

<td>
<?= date("d/m/Y H:i",strtotime($d["date_depense"])) ?>
</td>

<td>

<a class="delete"
href="?supprimer=<?= $d["id"] ?>"
onclick="return confirm('Supprimer cette dépense ?')">
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
