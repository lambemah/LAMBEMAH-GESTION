<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["id"])) {
    header("Location: index.php");
    exit;
}

$nom  = $_SESSION["nom"] ?? "Utilisateur";
$role = $_SESSION["role"] ?? "lecture";

$message = "";
$type = "";

function argent($n) {
    return number_format((float)$n, 0, ",", " ") . " FG";
}

/* =========================================================
   NOUVEL ARTICLE
   ========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajouter_produit"])) {

    $designation = trim($_POST["nom"] ?? "");
    $categorie = trim($_POST["categorie"] ?? "");
    $prix = (float)($_POST["prix_achat"] ?? 0);
    $quantite = (int)($_POST["stock_initial"] ?? 0);
    $fournisseur = trim($_POST["fournisseur"] ?? "");

    if ($designation === "") {
        $message = "La désignation est obligatoire.";
        $type = "error";
    } elseif ($prix < 0 || $quantite < 0) {
        $message = "Les valeurs saisies sont incorrectes.";
        $type = "error";
    } else {

        $check = $conn->prepare(
            "SELECT id FROM produits WHERE nom = ? LIMIT 1"
        );
        $check->bind_param("s", $designation);
        $check->execute();
        $existe = $check->get_result()->num_rows > 0;
        $check->close();

        if ($existe) {

            $message = "Cet article existe déjà. Utilise « Enregistrer un achat ».";
            $type = "error";

        } else {

            $conn->begin_transaction();

            try {

                $r = $conn->query(
                    "SELECT COALESCE(MAX(id),0)+1 AS id FROM produits"
                );
                $produit_id = (int)$r->fetch_assoc()["id"];

                $prix_vente = 0;

                $stmt = $conn->prepare(
                    "INSERT INTO produits
                    (id, nom, categorie, prix_achat, prix_vente, stock)
                    VALUES (?, ?, ?, ?, ?, ?)"
                );

                $stmt->bind_param(
                    "issddi",
                    $produit_id,
                    $designation,
                    $categorie,
                    $prix,
                    $prix_vente,
                    $quantite
                );

                if (!$stmt->execute()) {
                    throw new Exception($stmt->error);
                }

                $stmt->close();

                if ($quantite > 0) {

                    $r = $conn->query(
                        "SELECT COALESCE(MAX(id),0)+1 AS id FROM mouvements"
                    );

                    $mouvement_id = (int)$r->fetch_assoc()["id"];

                    $date = date("Y-m-d H:i:s");

                    $description =
                        "ACHAT | Fournisseur : " .
                        ($fournisseur ?: "Non renseigné") .
                        " | Désignation : " . $designation .
                        " | Total : " . argent($prix * $quantite) .
                        " | Payé : 0 FG" .
                        " | Reste : " . argent($prix * $quantite);

                    $stmt = $conn->prepare(
                        "INSERT INTO mouvements
                        (id, produit_id, type, quantite, prix, description, date_mouvement)
                        VALUES (?, ?, 'ENTREE', ?, ?, ?, ?)"
                    );

                    $stmt->bind_param(
                        "iiidss",
                        $mouvement_id,
                        $produit_id,
                        $quantite,
                        $prix,
                        $description,
                        $date
                    );

                    if (!$stmt->execute()) {
                        throw new Exception($stmt->error);
                    }

                    $stmt->close();
                }

                $conn->commit();

                $message = "Article ajouté avec succès.";
                $type = "success";

            } catch (Exception $e) {

                $conn->rollback();

                $message = "Erreur : " . $e->getMessage();
                $type = "error";
            }
        }
    }
}


/* =========================================================
   ACHAT MULTI-ARTICLES
   ========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["enregistrer_achat"])) {

    $fournisseur = trim($_POST["fournisseur"] ?? "");
    $paye = (float)($_POST["paye"] ?? 0);

    $ids = $_POST["produit_id"] ?? [];
    $quantites = $_POST["quantite"] ?? [];
    $prix = $_POST["prix"] ?? [];

    if ($fournisseur === "") {

        $message = "Le fournisseur est obligatoire.";
        $type = "error";

    } else {

        $lignes = [];
        $total = 0;

        for ($i = 0; $i < count($ids); $i++) {

            $produit_id = (int)($ids[$i] ?? 0);
            $qte = (int)($quantites[$i] ?? 0);
            $pu = (float)($prix[$i] ?? 0);

            if ($produit_id > 0 && $qte > 0 && $pu >= 0) {

                $montant = $qte * $pu;
                $total += $montant;

                $lignes[] = [
                    "produit_id" => $produit_id,
                    "quantite" => $qte,
                    "prix" => $pu,
                    "montant" => $montant
                ];
            }
        }

        if (count($lignes) === 0) {

            $message = "Ajoute au moins un article.";
            $type = "error";

        } elseif ($paye < 0 || $paye > $total) {

            $message = "Le montant payé est incorrect.";
            $type = "error";

        } else {

            $reste = $total - $paye;
            $date = date("Y-m-d H:i:s");

            $conn->begin_transaction();

            try {

                foreach ($lignes as $ligne) {

                    $produit_id = $ligne["produit_id"];
                    $qte = $ligne["quantite"];
                    $pu = $ligne["prix"];

                    $stmt = $conn->prepare(
                        "SELECT nom FROM produits WHERE id = ? LIMIT 1"
                    );

                    $stmt->bind_param("i", $produit_id);
                    $stmt->execute();

                    $produit = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$produit) {
                        throw new Exception("Article introuvable.");
                    }

                    $r = $conn->query(
                        "SELECT COALESCE(MAX(id),0)+1 AS id FROM mouvements"
                    );

                    $mouvement_id = (int)$r->fetch_assoc()["id"];

                    $description =
                        "ACHAT | Fournisseur : " . $fournisseur .
                        " | Désignation : " . $produit["nom"] .
                        " | Total achat : " . argent($total) .
                        " | Payé : " . argent($paye) .
                        " | Reste fournisseur : " . argent($reste);

                    $stmt = $conn->prepare(
                        "INSERT INTO mouvements
                        (id, produit_id, type, quantite, prix, description, date_mouvement)
                        VALUES (?, ?, 'ENTREE', ?, ?, ?, ?)"
                    );

                    $stmt->bind_param(
                        "iiidss",
                        $mouvement_id,
                        $produit_id,
                        $qte,
                        $pu,
                        $description,
                        $date
                    );

                    if (!$stmt->execute()) {
                        throw new Exception($stmt->error);
                    }

                    $stmt->close();

                    $stmt = $conn->prepare(
                        "UPDATE produits
                         SET stock = stock + ?, prix_achat = ?
                         WHERE id = ?"
                    );

                    $stmt->bind_param(
                        "idi",
                        $qte,
                        $pu,
                        $produit_id
                    );

                    if (!$stmt->execute()) {
                        throw new Exception($stmt->error);
                    }

                    $stmt->close();
                }

                $conn->commit();

                $message =
                    "Achat enregistré : " . argent($total) .
                    " | Payé : " . argent($paye) .
                    " | Reste : " . argent($reste);

                $type = "success";

            } catch (Exception $e) {

                $conn->rollback();

                $message = "Erreur : " . $e->getMessage();
                $type = "error";
            }
        }
    }
}


/* =========================================================
   LISTES
   ========================================================= */

$produits = $conn->query(
    "SELECT id, nom, categorie, prix_achat, stock
     FROM produits
     ORDER BY nom ASC"
);

$mouvements = $conn->query(
    "SELECT m.*, p.nom AS produit_nom
     FROM mouvements m
     LEFT JOIN produits p ON p.id = m.produit_id
     WHERE m.type = 'ENTREE'
     ORDER BY m.id DESC
     LIMIT 50"
);

$total_achat = 0;

$r = $conn->query(
    "SELECT COALESCE(SUM(quantite * prix),0) AS total
     FROM mouvements
     WHERE type = 'ENTREE'"
);

if ($r) {
    $total_achat = (float)$r->fetch_assoc()["total"];
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Achats / Stock - LAMBEMAH</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>

body{
    margin:0;
    background:#f4f7fb;
    font-family:Arial,sans-serif;
    color:#172033;
}

.sidebar{
    position:fixed;
    left:0;
    top:0;
    width:245px;
    height:100vh;
    background:#102a43;
    padding:20px 15px;
    color:white;
}

.logo{
    font-size:22px;
    font-weight:bold;
    padding:5px 10px 20px;
}

.logo small{
    display:block;
    font-size:11px;
    color:#9cc8ff;
    margin-top:4px;
}

.menu a{
    display:flex;
    align-items:center;
    gap:10px;
    color:#dbeafe;
    text-decoration:none;
    padding:11px;
    border-radius:9px;
    margin-bottom:4px;
}

.menu a:hover,
.menu a.active{
    background:#1d4ed8;
    color:white;
}

.main{
    margin-left:245px;
    padding:25px;
}

.box{
    background:white;
    border-radius:15px;
    padding:20px;
    margin-bottom:20px;
    box-shadow:0 3px 15px rgba(0,0,0,.05);
}

.box-title{
    font-size:19px;
    font-weight:bold;
    margin-bottom:15px;
}

.form-control,
.form-select{
    min-height:44px;
}

.ligne{
    background:#f8fafc;
    border:1px solid #e5e7eb;
    border-radius:10px;
    padding:12px;
    margin-bottom:10px;
}

.total{
    background:#eef5ff;
    border-radius:10px;
    padding:15px;
    font-size:20px;
    font-weight:bold;
}

.reste{
    color:#b42318;
}

@media(max-width:768px){

    .sidebar{
        position:static;
        width:100%;
        height:auto;
    }

    .menu{
        display:grid;
        grid-template-columns:repeat(4,1fr);
    }

    .menu a{
        flex-direction:column;
        justify-content:center;
        font-size:10px;
        text-align:center;
    }

    .main{
        margin-left:0;
        padding:14px;
    }
}

</style>

</head>

<body>

<aside class="sidebar">

<div class="logo">
LAMBEMAH
<small>GESTION • PRESTATION</small>
</div>

<nav class="menu">

<a href="index.php"><i class="bi bi-house"></i>Accueil</a>

<a href="produits.php" class="active">
<i class="bi bi-box"></i>Achats / Stock
</a>

<a href="ventes.php">
<i class="bi bi-cart-check"></i>Ventes
</a>

<a href="prestations.php">
<i class="bi bi-printer"></i>Prestations
</a>

<a href="recettes.php">
<i class="bi bi-cash-coin"></i>Recettes
</a>

<a href="depenses.php">
<i class="bi bi-wallet2"></i>Dépenses
</a>

<a href="statistiques.php">
<i class="bi bi-bar-chart"></i>Statistiques
</a>

<?php if($role === "admin"): ?>

<a href="utilisateurs.php">
<i class="bi bi-people"></i>Équipe
</a>

<?php endif; ?>

<a href="index.php?logout=1">
<i class="bi bi-box-arrow-right"></i>Déconnexion
</a>

</nav>

</aside>


<main class="main">

<h1>📦 Achats / Stock</h1>

<p class="text-muted">
Enregistre plusieurs articles achetés chez un même fournisseur.
</p>


<?php if($message): ?>

<div class="alert <?= $type === "success" ? "alert-success" : "alert-danger" ?>">
<?= htmlspecialchars($message) ?>
</div>

<?php endif; ?>


<!-- ======================================================
     NOUVEL ARTICLE
====================================================== -->

<div class="box">

<div class="box-title">
<i class="bi bi-plus-circle"></i>
Ajouter un nouvel article
</div>

<form method="POST">

<input type="hidden" name="ajouter_produit" value="1">

<div class="row g-3">

<div class="col-md-4">
<label>Désignation</label>
<input name="nom" class="form-control" required>
</div>

<div class="col-md-3">
<label>Catégorie</label>
<input name="categorie" class="form-control">
</div>

<div class="col-md-2">
<label>Prix d'achat</label>
<input type="number" name="prix_achat" class="form-control" min="0" required>
</div>

<div class="col-md-2">
<label>Quantité initiale</label>
<input type="number" name="stock_initial" class="form-control" min="0" value="0">
</div>

<div class="col-md-6">
<label>Fournisseur</label>
<input name="fournisseur" class="form-control">
</div>

</div>

<button class="btn btn-primary mt-3">
<i class="bi bi-save"></i>
Ajouter l'article
</button>

</form>

</div>


<!-- ======================================================
     ACHAT MULTI-ARTICLES
====================================================== -->

<div class="box">

<div class="box-title">
🛒 Enregistrer un achat
</div>

<form method="POST" id="formAchat">

<input type="hidden" name="enregistrer_achat" value="1">

<div class="mb-3">

<label class="fw-bold">
Fournisseur
</label>

<input
type="text"
name="fournisseur"
class="form-control"
placeholder="Ex : Mariama Djello FBK"
required
>

</div>


<div id="lignes">


<div class="ligne">

<div class="row g-2 align-items-end">

<div class="col-md-5">

<label>Article</label>

<select name="produit_id[]" class="form-select" required>

<option value="">Choisir</option>

<?php

$produits_form =
$conn->query(
"SELECT id, nom, stock
 FROM produits
 ORDER BY nom ASC"
);

while($p = $produits_form->fetch_assoc()):

?>

<option value="<?= (int)$p["id"] ?>">

<?= htmlspecialchars($p["nom"]) ?>

— Stock <?= (int)$p["stock"] ?>

</option>

<?php endwhile; ?>

</select>

</div>


<div class="col-md-2">

<label>Prix achat</label>

<input
type="number"
name="prix[]"
class="form-control prix"
min="0"
value="0"
oninput="calculer()"
required
>

</div>


<div class="col-md-2">

<label>Quantité</label>

<input
type="number"
name="quantite[]"
class="form-control quantite"
min="1"
value="1"
oninput="calculer()"
required
>

</div>


<div class="col-md-2">

<label>Montant</label>

<input
type="text"
class="form-control montant"
value="0 FG"
readonly
>

</div>


<div class="col-md-1">

<button
type="button"
class="btn btn-danger"
onclick="supprimerLigne(this)"
>

<i class="bi bi-trash"></i>

</button>

</div>

</div>

</div>

</div>


<button
type="button"
class="btn btn-outline-primary mb-3"
onclick="ajouterLigne()"
>

<i class="bi bi-plus-circle"></i>
Ajouter un autre article

</button>


<div class="total mb-3">

TOTAL ACHAT :
<span id="total">0 FG</span>

</div>


<div class="mb-3">

<label class="fw-bold">
Montant payé / avance
</label>

<input
type="number"
name="paye"
id="paye"
class="form-control"
min="0"
value="0"
oninput="calculer()"
>

</div>


<div class="total mb-3">

RESTE À PAYER :
<span
id="reste"
class="reste"
>
0 FG
</span>

</div>


<button class="btn btn-primary btn-lg">

<i class="bi bi-check-circle"></i>

Enregistrer l'achat

</button>

</form>

</div>


<!-- ======================================================
     STOCK
====================================================== -->

<div class="box">

<div class="box-title">
📦 Stock actuel
</div>

<div class="table-responsive">

<table class="table">

<thead>
<tr>
<th>Désignation</th>
<th>Catégorie</th>
<th>Prix achat</th>
<th>Stock</th>
</tr>
</thead>

<tbody>

<?php

$stock =
$conn->query(
"SELECT nom,categorie,prix_achat,stock
 FROM produits
 ORDER BY nom"
);

while($p=$stock->fetch_assoc()):

?>

<tr>

<td><?= htmlspecialchars($p["nom"]) ?></td>

<td><?= htmlspecialchars($p["categorie"] ?? "") ?></td>

<td><?= argent($p["prix_achat"]) ?></td>

<td>
<strong><?= (int)$p["stock"] ?></strong>
</td>

</tr>

<?php endwhile; ?>

</tbody>

</table>

</div>

</div>


<!-- ======================================================
     HISTORIQUE
====================================================== -->

<div class="box">

<div class="box-title">
🕘 Derniers achats
</div>

<div class="table-responsive">

<table class="table">

<thead>

<tr>
<th>Article</th>
<th>Fournisseur</th>
<th>Prix</th>
<th>Qté</th>
<th>Montant</th>
<th>Date</th>
</tr>

</thead>

<tbody>

<?php if($mouvements && $mouvements->num_rows): ?>

<?php while($m=$mouvements->fetch_assoc()): ?>

<?php

$description = $m["description"] ?? "";

$fournisseur = "Non renseigné";

$parts = explode(" | ", $description);

foreach($parts as $part){

    if(strpos($part,"Fournisseur : ") === 0){

        $fournisseur =
        str_replace(
            "Fournisseur : ",
            "",
            $part
        );
    }
}

?>

<tr>

<td>
<?= htmlspecialchars($m["produit_nom"] ?? "Article") ?>
</td>

<td>
<?= htmlspecialchars($fournisseur) ?>
</td>

<td>
<?= argent($m["prix"]) ?>
</td>

<td>
<?= (int)$m["quantite"] ?>
</td>

<td>
<strong>
<?= argent($m["prix"] * $m["quantite"]) ?>
</strong>
</td>

<td>
<?= !empty($m["date_mouvement"])
? date("d/m/Y H:i",strtotime($m["date_mouvement"]))
: "-" ?>
</td>

</tr>

<?php endwhile; ?>

<?php else: ?>

<tr>
<td colspan="6" class="text-center">
Aucun achat enregistré.
</td>
</tr>

<?php endif; ?>

</tbody>

</table>

</div>

</div>

</main>


<script>

function argent(n){

    return new Intl.NumberFormat("fr-FR")
    .format(n) + " FG";

}


function calculer(){

    let total = 0;

    document.querySelectorAll(".ligne").forEach(function(ligne){

        let prix =
        parseFloat(
            ligne.querySelector(".prix").value
        ) || 0;

        let qte =
        parseInt(
            ligne.querySelector(".quantite").value
        ) || 0;

        let montant = prix * qte;

        ligne.querySelector(".montant").value =
        argent(montant);

        total += montant;

    });


    document.getElementById("total").textContent =
    argent(total);


    let paye =
    parseFloat(
        document.getElementById("paye").value
    ) || 0;


    let reste = total - paye;

    if(reste < 0){
        reste = 0;
    }


    document.getElementById("reste").textContent =
    argent(reste);

}


function ajouterLigne(){

    let conteneur =
    document.getElementById("lignes");

    let premiere =
    conteneur.querySelector(".ligne");

    let nouvelle =
    premiere.cloneNode(true);


    nouvelle
    .querySelectorAll("input")
    .forEach(function(input){

        if(input.classList.contains("prix")){
            input.value = 0;
        }

        else if(input.classList.contains("quantite")){
            input.value = 1;
        }

        else if(input.classList.contains("montant")){
            input.value = "0 FG";
        }

    });


    nouvelle
    .querySelector("select").selectedIndex = 0;


    conteneur.appendChild(nouvelle);

}


function supprimerLigne(btn){

    let lignes =
    document.querySelectorAll(".ligne");

    if(lignes.length <= 1){

        alert("Il faut garder au moins un article.");

        return;
    }

    btn.closest(".ligne").remove();

    calculer();

}


document.addEventListener(
"DOMContentLoaded",
calculer
);

</script>

</body>
</html>
