<?php
session_start();
require_once "config.php";

/*
|--------------------------------------------------------------------------
| CONNEXION
|--------------------------------------------------------------------------
*/
if (isset($conn)) {
    $db = $conn;
} elseif (isset($mysqli)) {
    $db = $mysqli;
} else {
    die("Connexion à la base de données introuvable.");
}

if ($db->connect_error) {
    die("Erreur de connexion : " . $db->connect_error);
}

$db->set_charset("utf8mb4");

/*
|--------------------------------------------------------------------------
| OUTILS
|--------------------------------------------------------------------------
*/
function prochainId($db, $table)
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);

    $result = $db->query("SELECT COALESCE(MAX(id),0)+1 AS prochain FROM `$table`");

    if (!$result) {
        throw new Exception($db->error);
    }

    $row = $result->fetch_assoc();
    return (int)$row['prochain'];
}

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| MESSAGE
|--------------------------------------------------------------------------
*/
$message = "";
$type_message = "success";

/*
|--------------------------------------------------------------------------
| AJOUT D'UNE FACTURE D'ACHAT
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["enregistrer_achat"])) {

    try {

        $fournisseur = trim($_POST["fournisseur"] ?? "");
        $articles = $_POST["article"] ?? [];
        $quantites = $_POST["quantite"] ?? [];
        $prix = $_POST["prix"] ?? [];
        $paye = (float)($_POST["paye"] ?? 0);

        if ($fournisseur === "") {
            throw new Exception("Veuillez renseigner le fournisseur.");
        }

        $lignes = [];
        $total = 0;

        for ($i = 0; $i < count($articles); $i++) {

            $produit_id = (int)($articles[$i] ?? 0);
            $qte = (int)($quantites[$i] ?? 0);
            $pu = (float)($prix[$i] ?? 0);

            if ($produit_id <= 0 || $qte <= 0 || $pu < 0) {
                continue;
            }

            $montant = $qte * $pu;
            $total += $montant;

            $lignes[] = [
                "produit_id" => $produit_id,
                "quantite" => $qte,
                "prix" => $pu,
                "montant" => $montant
            ];
        }

        if (empty($lignes)) {
            throw new Exception("Ajoutez au moins un article.");
        }

        if ($paye < 0) {
            $paye = 0;
        }

        if ($paye > $total) {
            $paye = $total;
        }

        $reste = $total - $paye;

        $ref = "ACH-" . date("Ymd-His") . "-" . rand(100, 999);

        $db->begin_transaction();

        foreach ($lignes as $ligne) {

            $id_mouvement = prochainId($db, "mouvements");

            $description =
                "FACTURE=$ref" .
                "|FOURNISSEUR=" . $fournisseur .
                "|PAYE=" . number_format($paye, 2, '.', '') .
                "|RESTE=" . number_format($reste, 2, '.', '');

            /*
             * On garde prix_vente car la colonne existe dans la base,
             * mais elle n'est pas utilisée dans cette page.
             */
            $stmt = $db->prepare("
                INSERT INTO mouvements
                (id, produit_id, type, quantite, prix, description, date_mouvement)
                VALUES (?, ?, 'ENTREE', ?, ?, ?, ?)
            ");

            if (!$stmt) {
                throw new Exception($db->error);
            }

            $date = date("Y-m-d H:i:s");

            $stmt->bind_param(
                "iiidss",
                $id_mouvement,
                $ligne["produit_id"],
                $ligne["quantite"],
                $ligne["prix"],
                $description,
                $date
            );

            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }

            $stmt->close();

            /*
             * Mise à jour du stock
             */
            $stmt = $db->prepare("
                UPDATE produits
                SET stock = stock + ?
                WHERE id = ?
            ");

            $stmt->bind_param(
                "ii",
                $ligne["quantite"],
                $ligne["produit_id"]
            );

            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }

            $stmt->close();
        }

        $db->commit();

        $message = "Achat enregistré : $ref";
        $type_message = "success";

    } catch (Exception $e) {

        if ($db->errno === 0) {
            // rien
        }

        try {
            $db->rollback();
        } catch (Exception $x) {
        }

        $message = "Erreur : " . $e->getMessage();
        $type_message = "danger";
    }
}

/*
|--------------------------------------------------------------------------
| RÈGLEMENT D'UNE FACTURE
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["regler_facture"])) {

    try {

        $ref = trim($_POST["reference"] ?? "");
        $nouveau_paye = (float)($_POST["montant_paye"] ?? 0);

        if ($ref === "") {
            throw new Exception("Facture introuvable.");
        }

        $nouveau_paye = max(0, $nouveau_paye);

        $pattern = "%FACTURE=" . $db->real_escape_string($ref) . "|%";

        $result = $db->query("
            SELECT description, quantite, prix
            FROM mouvements
            WHERE type='ENTREE'
            AND description LIKE '$pattern'
        ");

        if (!$result || $result->num_rows === 0) {
            throw new Exception("Facture introuvable.");
        }

        $total = 0;
        $ancienne_description = "";

        while ($row = $result->fetch_assoc()) {

            $total += ((float)$row["quantite"] * (float)$row["prix"]);

            if ($ancienne_description === "") {
                $ancienne_description = $row["description"];
            }
        }

        if ($nouveau_paye > $total) {
            $nouveau_paye = $total;
        }

        $reste = $total - $nouveau_paye;

        /*
         * On remplace uniquement PAYE et RESTE
         * dans la description de chaque ligne.
         */
        $result = $db->query("
            SELECT id, description
            FROM mouvements
            WHERE type='ENTREE'
            AND description LIKE '$pattern'
        ");

        while ($row = $result->fetch_assoc()) {

            $description = $row["description"];

            $description = preg_replace(
                '/\|PAYE=[^|]*/',
                '|PAYE=' . number_format($nouveau_paye, 2, '.', ''),
                $description
            );

            $description = preg_replace(
                '/\|RESTE=[^|]*/',
                '|RESTE=' . number_format($reste, 2, '.', ''),
                $description
            );

            $stmt = $db->prepare("
                UPDATE mouvements
                SET description=?
                WHERE id=?
            ");

            $stmt->bind_param(
                "si",
                $description,
                $row["id"]
            );

            $stmt->execute();
            $stmt->close();
        }

        $message = "Règlement enregistré.";
        $type_message = "success";

    } catch (Exception $e) {

        $message = "Erreur : " . $e->getMessage();
        $type_message = "danger";
    }
}

/*
|--------------------------------------------------------------------------
| SUPPRESSION D'UNE FACTURE NON PAYÉE
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["supprimer_facture"])) {

    try {

        $ref = trim($_POST["reference"] ?? "");

        if ($ref === "") {
            throw new Exception("Facture introuvable.");
        }

        $pattern = "%FACTURE=" . $db->real_escape_string($ref) . "|%";

        $result = $db->query("
            SELECT id, produit_id, quantite, description
            FROM mouvements
            WHERE type='ENTREE'
            AND description LIKE '$pattern'
        ");

        if (!$result || $result->num_rows === 0) {
            throw new Exception("Facture introuvable.");
        }

        $lignes = [];

        while ($row = $result->fetch_assoc()) {

            preg_match(
                '/\|PAYE=([^|]+)/',
                $row["description"],
                $m1
            );

            $paye = isset($m1[1]) ? (float)$m1[1] : 0;

            if ($paye > 0) {
                throw new Exception(
                    "Cette facture est déjà payée ou partiellement payée. Suppression impossible."
                );
            }

            $lignes[] = $row;
        }

        $db->begin_transaction();

        foreach ($lignes as $ligne) {

            $stmt = $db->prepare("
                UPDATE produits
                SET stock = stock - ?
                WHERE id = ?
            ");

            $stmt->bind_param(
                "ii",
                $ligne["quantite"],
                $ligne["produit_id"]
            );

            $stmt->execute();
            $stmt->close();

            $stmt = $db->prepare("
                DELETE FROM mouvements
                WHERE id=?
            ");

            $stmt->bind_param(
                "i",
                $ligne["id"]
            );

            $stmt->execute();
            $stmt->close();
        }

        $db->commit();

        $message = "Facture supprimée.";
        $type_message = "success";

    } catch (Exception $e) {

        try {
            $db->rollback();
        } catch (Exception $x) {
        }

        $message = "Erreur : " . $e->getMessage();
        $type_message = "danger";
    }
}

/*
|--------------------------------------------------------------------------
| IMPRESSION FACTURE
|--------------------------------------------------------------------------
*/
if (isset($_GET["imprimer"])) {

    $ref = trim($_GET["imprimer"]);

    $pattern = "%FACTURE=" . $db->real_escape_string($ref) . "|%";

    $result = $db->query("
        SELECT m.*, p.nom
        FROM mouvements m
        LEFT JOIN produits p ON p.id=m.produit_id
        WHERE m.type='ENTREE'
        AND m.description LIKE '$pattern'
        ORDER BY m.id ASC
    ");

    if (!$result || $result->num_rows === 0) {
        die("Facture introuvable.");
    }

    $lignes = [];
    $total = 0;
    $fournisseur = "";
    $paye = 0;
    $reste = 0;

    while ($row = $result->fetch_assoc()) {

        $montant = (float)$row["quantite"] * (float)$row["prix"];

        $total += $montant;

        if (preg_match('/\|FOURNISSEUR=([^|]*)/', $row["description"], $m)) {
            $fournisseur = $m[1];
        }

        if (preg_match('/\|PAYE=([^|]*)/', $row["description"], $m)) {
            $paye = (float)$m[1];
        }

        if (preg_match('/\|RESTE=([^|]*)/', $row["description"], $m)) {
            $reste = (float)$m[1];
        }

        $row["montant"] = $montant;

        $lignes[] = $row;
    }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title><?= e($ref) ?></title>

<style>
body{
    font-family:Arial,sans-serif;
    font-size:12px;
    color:#222;
    margin:25px;
}
h1{
    font-size:20px;
    margin:0 0 5px;
}
.small{
    font-size:11px;
    color:#666;
}
table{
    width:100%;
    border-collapse:collapse;
    margin-top:18px;
}
th,td{
    border-bottom:1px solid #ddd;
    padding:7px;
    text-align:left;
}
th{
    background:#f4f6f8;
    font-size:11px;
}
.total{
    margin-top:18px;
    width:300px;
    margin-left:auto;
}
.total div{
    display:flex;
    justify-content:space-between;
    padding:4px;
}
.grand{
    font-weight:bold;
    font-size:14px;
    border-top:2px solid #222;
}
button{
    padding:8px 14px;
    border:0;
    cursor:pointer;
}
@media print{
    button{display:none}
}
</style>
</head>

<body>

<button onclick="window.print()">Imprimer / PDF</button>

<h1>FACTURE D'ACHAT</h1>

<div class="small">
Référence : <?= e($ref) ?><br>
Fournisseur : <?= e($fournisseur) ?><br>
Date : <?= date("d/m/Y") ?>
</div>

<table>
<thead>
<tr>
    <th>Article</th>
    <th>Qté</th>
    <th>Prix achat</th>
    <th>Montant</th>
</tr>
</thead>

<tbody>
<?php foreach ($lignes as $ligne): ?>
<tr>
    <td><?= e($ligne["nom"]) ?></td>
    <td><?= (int)$ligne["quantite"] ?></td>
    <td><?= number_format($ligne["prix"],0,","," ") ?> FG</td>
    <td><?= number_format($ligne["montant"],0,","," ") ?> FG</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<div class="total">
    <div>
        <span>Total</span>
        <strong><?= number_format($total,0,","," ") ?> FG</strong>
    </div>

    <div>
        <span>Payé</span>
        <strong><?= number_format($paye,0,","," ") ?> FG</strong>
    </div>

    <div class="grand">
        <span>Reste</span>
        <strong><?= number_format($reste,0,","," ") ?> FG</strong>
    </div>
</div>

</body>
</html>
<?php
exit;
}

/*
|--------------------------------------------------------------------------
| LISTE DES FACTURES
|--------------------------------------------------------------------------
*/
$factures = [];

$result = $db->query("
    SELECT *
    FROM mouvements
    WHERE type='ENTREE'
    AND description LIKE 'FACTURE=%'
    ORDER BY id DESC
");

if ($result) {

    while ($row = $result->fetch_assoc()) {

        preg_match('/FACTURE=([^|]+)/', $row["description"], $mref);
        preg_match('/FOURNISSEUR=([^|]*)/', $row["description"], $mf);
        preg_match('/PAYE=([^|]+)/', $row["description"], $mp);
        preg_match('/RESTE=([^|]+)/', $row["description"], $mr);

        $ref = $mref[1] ?? "—";
        $fournisseur = $mf[1] ?? "—";
        $paye = isset($mp[1]) ? (float)$mp[1] : 0;
        $reste = isset($mr[1]) ? (float)$mr[1] : 0;

        if (!isset($factures[$ref])) {

            $factures[$ref] = [
                "ref" => $ref,
                "fournisseur" => $fournisseur,
                "total" => 0,
                "paye" => $paye,
                "reste" => $reste,
                "articles" => 0,
                "date" => $row["date_mouvement"]
            ];
        }

        $factures[$ref]["total"] +=
            ((float)$row["quantite"] * (float)$row["prix"]);

        $factures[$ref]["articles"]++;
    }
}

/*
|--------------------------------------------------------------------------
| STOCK
|--------------------------------------------------------------------------
*/
$produits = [];

$result = $db->query("
    SELECT id, nom, categorie, prix_achat, stock
    FROM produits
    ORDER BY nom ASC
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $produits[] = $row;
    }
}

$total_stock = 0;
$valeur_stock = 0;

foreach ($produits as $p) {

    $total_stock += (int)$p["stock"];

    $valeur_stock +=
        ((float)$p["prix_achat"] * (int)$p["stock"]);
}

?>
<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Achats — LAMBEMAH GESTION</title>

<style>

*{
    box-sizing:border-box;
}

body{
    margin:0;
    background:#f5f7fa;
    color:#202938;
    font-family:Arial,Helvetica,sans-serif;
    font-size:12px;
}

/* MENU */

.topbar{
    height:48px;
    background:#123b68;
    color:white;
    display:flex;
    align-items:center;
    padding:0 16px;
    justify-content:space-between;
}

.logo{
    font-size:14px;
    font-weight:bold;
}

.menu{
    display:flex;
    gap:5px;
}

.menu a{
    color:white;
    text-decoration:none;
    padding:6px 9px;
    border-radius:5px;
    font-size:11px;
}

.menu a:hover,
.menu a.active{
    background:rgba(255,255,255,.14);
}

/* PAGE */

.container{
    max-width:1180px;
    margin:0 auto;
    padding:16px;
}

.header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    margin-bottom:12px;
}

h1{
    font-size:18px;
    margin:0;
}

.subtitle{
    color:#718096;
    font-size:11px;
    margin-top:3px;
}

/* BOUTONS */

.btn{
    border:0;
    border-radius:5px;
    padding:7px 10px;
    font-size:11px;
    cursor:pointer;
    text-decoration:none;
    display:inline-block;
}

.btn-primary{
    background:#1769aa;
    color:white;
}

.btn-success{
    background:#198754;
    color:white;
}

.btn-danger{
    background:#c0392b;
    color:white;
}

.btn-light{
    background:#e9eef3;
    color:#25364d;
}

/* STATS */

.stats{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:9px;
    margin-bottom:12px;
}

.card{
    background:white;
    border:1px solid #e2e8f0;
    border-radius:7px;
    padding:10px;
}

.card-label{
    color:#718096;
    font-size:10px;
}

.card-value{
    font-size:16px;
    font-weight:bold;
    margin-top:3px;
}

/* FORMULAIRE */

.panel{
    background:white;
    border:1px solid #e2e8f0;
    border-radius:7px;
    padding:12px;
    margin-bottom:12px;
}

.panel-title{
    font-size:13px;
    font-weight:bold;
    margin-bottom:10px;
}

.grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:9px;
}

label{
    display:block;
    font-size:10px;
    color:#526174;
    margin-bottom:3px;
}

input,
select{
    width:100%;
    border:1px solid #ccd5df;
    border-radius:4px;
    padding:7px 8px;
    font-size:11px;
    background:white;
}

.lines{
    margin-top:10px;
}

.line{
    display:grid;
    grid-template-columns:2fr .7fr 1fr auto;
    gap:6px;
    margin-bottom:6px;
    align-items:end;
}

.remove{
    height:30px;
    width:30px;
    border:0;
    background:#f3d7d4;
    color:#9d2b20;
    border-radius:4px;
    cursor:pointer;
}

.form-footer{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-top:10px;
    padding-top:9px;
    border-top:1px solid #edf0f3;
}

.total-form{
    font-size:13px;
    font-weight:bold;
}

/* TABLE */

.table-wrap{
    overflow-x:auto;
}

table{
    width:100%;
    border-collapse:collapse;
}

th,
td{
    padding:7px 8px;
    border-bottom:1px solid #edf0f3;
    text-align:left;
    white-space:nowrap;
}

th{
    background:#f7f9fb;
    color:#526174;
    font-size:10px;
    font-weight:bold;
}

td{
    font-size:11px;
}

.status{
    padding:3px 6px;
    border-radius:10px;
    font-size:9px;
    font-weight:bold;
}

.status-paid{
    background:#d9f3e4;
    color:#13733c;
}

.status-partial{
    background:#fff0c9;
    color:#8a6500;
}

.status-unpaid{
    background:#edf0f3;
    color:#596575;
}

.actions{
    display:flex;
    gap:4px;
}

/* MOBILE */

@media(max-width:700px){

    body{
        font-size:11px;
    }

    .topbar{
        height:auto;
        min-height:46px;
        padding:7px 10px;
    }

    .menu{
        display:none;
    }

    .container{
        padding:10px;
    }

    .header{
        align-items:flex-start;
    }

    h1{
        font-size:16px;
    }

    .stats{
        grid-template-columns:1fr 1fr;
    }

    .stats .card:last-child{
        grid-column:span 2;
    }

    .grid{
        grid-template-columns:1fr;
    }

    .line{
        grid-template-columns:1fr 80px;
        background:#f8fafc;
        padding:7px;
        border-radius:5px;
    }

    .line select{
        grid-column:1 / 3;
    }

    .line input[type="number"]{
        grid-column:1;
    }

    .remove{
        grid-column:2;
        grid-row:2;
        justify-self:end;
    }

    .form-footer{
        display:block;
    }

    .total-form{
        margin-bottom:8px;
    }

    .btn{
        font-size:10px;
        padding:6px 8px;
    }

    th,
    td{
        padding:6px;
    }

}

</style>

</head>

<body>

<div class="topbar">

    <div class="logo">
        LAMBEMAH GESTION
    </div>

    <div class="menu">

        <a href="index.php">Accueil</a>

        <a href="produits.php" class="active">
            Achats
        </a>

        <a href="ventes.php">
            Ventes
        </a>

        <a href="depenses.php">
            Dépenses
        </a>

        <a href="recettes.php">
            Recettes
        </a>

    </div>

</div>


<div class="container">

    <div class="header">

        <div>
            <h1>Achats & fournisseurs</h1>

            <div class="subtitle">
                Factures d'achat et stock
            </div>
        </div>

        <a href="#nouvel-achat" class="btn btn-primary">
            + Nouvel achat
        </a>

    </div>


    <?php if ($message !== ""): ?>

        <div
            class="panel"
            style="
                color:
                <?= $type_message === 'danger'
                    ? '#9d2b20'
                    : '#13733c' ?>;
                background:
                <?= $type_message === 'danger'
                    ? '#fff0ee'
                    : '#effaf3' ?>;
                border-color:
                <?= $type_message === 'danger'
                    ? '#f1c5c0'
                    : '#c8ead5' ?>;
        "
        >
            <?= e($message) ?>
        </div>

    <?php endif; ?>


    <div class="stats">

        <div class="card">

            <div class="card-label">
                Factures d'achat
            </div>

            <div class="card-value">
                <?= count($factures) ?>
            </div>

        </div>

        <div class="card">

            <div class="card-label">
                Articles en stock
            </div>

            <div class="card-value">
                <?= number_format($total_stock,0,","," ") ?>
            </div>

        </div>

        <div class="card">

            <div class="card-label">
                Valeur du stock
            </div>

            <div class="card-value">
                <?= number_format($valeur_stock,0,","," ") ?> FG
            </div>

        </div>

    </div>


    <!-- NOUVEL ACHAT -->

    <div class="panel" id="nouvel-achat">

        <div class="panel-title">
            Nouvelle facture d'achat
        </div>

        <form method="POST">

            <div class="grid">

                <div>

                    <label>
                        Fournisseur
                    </label>

                    <input
                        type="text"
                        name="fournisseur"
                        placeholder="Nom du fournisseur"
                        required
                    >

                </div>

                <div>

                    <label>
                        Montant payé
                    </label>

                    <input
                        type="number"
                        name="paye"
                        id="paye"
                        value="0"
                        min="0"
                        step="1"
                    >

                </div>

            </div>


            <div class="lines" id="lines">

                <div class="line">

                    <select
                        name="article[]"
                        onchange="calculer()"
                        required
                    >

                        <option value="">
                            Choisir un article
                        </option>

                        <?php foreach ($produits as $produit): ?>

                            <option value="<?= $produit["id"] ?>">

                                <?= e($produit["nom"]) ?>

                                — stock <?= (int)$produit["stock"] ?>

                            </option>

                        <?php endforeach; ?>

                    </select>


                    <input
                        type="number"
                        name="quantite[]"
                        value="1"
                        min="1"
                        placeholder="Qté"
                        oninput="calculer()"
                        required
                    >


                    <input
                        type="number"
                        name="prix[]"
                        value="0"
                        min="0"
                        step="1"
                        placeholder="Prix achat"
                        oninput="calculer()"
                        required
                    >


                    <button
                        type="button"
                        class="remove"
                        onclick="supprimerLigne(this)"
                    >
                        ×
                    </button>

                </div>

            </div>


            <div style="margin-top:7px">

                <button
                    type="button"
                    class="btn btn-light"
                    onclick="ajouterLigne()"
                >
                    + Article
                </button>

            </div>


            <div class="form-footer">

                <div class="total-form">

                    Total :
                    <span id="total">
                        0
                    </span>
                    FG

                </div>

                <button
                    type="submit"
                    name="enregistrer_achat"
                    class="btn btn-success"
                >
                    Enregistrer l'achat
                </button>

            </div>

        </form>

    </div>


    <!-- FACTURES -->

    <div class="panel">

        <div class="panel-title">
            Factures d'achat
        </div>

        <div class="table-wrap">

            <table>

                <thead>

                    <tr>

                        <th>Référence</th>
                        <th>Fournisseur</th>
                        <th>Articles</th>
                        <th>Total</th>
                        <th>Payé</th>
                        <th>Reste</th>
                        <th>Statut</th>
                        <th>Actions</th>

                    </tr>

                </thead>

                <tbody>

                <?php if (empty($factures)): ?>

                    <tr>

                        <td colspan="8" style="text-align:center;color:#718096">

                            Aucune facture d'achat.

                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($factures as $facture): ?>

                        <?php

                        if ($facture["reste"] <= 0) {

                            $statut = "Payé";
                            $class = "status-paid";

                        } elseif ($facture["paye"] > 0) {

                            $statut = "Partiel";
                            $class = "status-partial";

                        } else {

                            $statut = "Non payé";
                            $class = "status-unpaid";
                        }

                        ?>

                        <tr>

                            <td>
                                <strong>
                                    <?= e($facture["ref"]) ?>
                                </strong>
                            </td>

                            <td>
                                <?= e($facture["fournisseur"]) ?>
                            </td>

                            <td>
                                <?= (int)$facture["articles"] ?>
                            </td>

                            <td>
                                <?= number_format(
                                    $facture["total"],
                                    0,
                                    ",",
                                    " "
                                ) ?> FG
                            </td>

                            <td>
                                <?= number_format(
                                    $facture["paye"],
                                    0,
                                    ",",
                                    " "
                                ) ?> FG
                            </td>

                            <td>
                                <?= number_format(
                                    $facture["reste"],
                                    0,
                                    ",",
                                    " "
                                ) ?> FG
                            </td>

                            <td>

                                <span class="status <?= $class ?>">
                                    <?= $statut ?>
                                </span>

                            </td>

                            <td>

                                <div class="actions">

                                    <a
                                        class="btn btn-light"
                                        href="?imprimer=<?= urlencode($facture["ref"]) ?>"
                                        target="_blank"
                                    >
                                        Voir
                                    </a>

                                    <?php if ($facture["reste"] > 0): ?>

                                        <button
                                            type="button"
                                            class="btn btn-success"
                                            onclick="regler(
                                                '<?= e($facture["ref"]) ?>',
                                                <?= $facture["reste"] ?>
                                            )"
                                        >
                                            Régler
                                        </button>

                                    <?php endif; ?>

                                    <?php if ($facture["paye"] <= 0): ?>

                                        <form method="POST" style="display:inline">

                                            <input
                                                type="hidden"
                                                name="reference"
                                                value="<?= e($facture["ref"]) ?>"
                                            >

                                            <button
                                                type="submit"
                                                name="supprimer_facture"
                                                class="btn btn-danger"
                                                onclick="return confirm('Supprimer cette facture ?')"
                                            >
                                                Suppr.
                                            </button>

                                        </form>

                                    <?php endif; ?>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>


    <!-- STOCK -->

    <div class="panel">

        <div class="panel-title">
            Stock actuel
        </div>

        <div class="table-wrap">

            <table>

                <thead>

                    <tr>

                        <th>Désignation</th>
                        <th>Catégorie</th>
                        <th>Prix achat</th>
                        <th>Stock</th>

                    </tr>

                </thead>

                <tbody>

                <?php foreach ($produits as $produit): ?>

                    <tr>

                        <td>
                            <?= e($produit["nom"]) ?>
                        </td>

                        <td>
                            <?= e($produit["categorie"]) ?>
                        </td>

                        <td>
                            <?= number_format(
                                $produit["prix_achat"],
                                0,
                                ",",
                                " "
                            ) ?> FG
                        </td>

                        <td>

                            <strong>
                                <?= (int)$produit["stock"] ?>
                            </strong>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>


<!-- MODAL REGLEMENT -->

<div
    id="modal"
    style="
        display:none;
        position:fixed;
        inset:0;
        background:rgba(0,0,0,.35);
        align-items:center;
        justify-content:center;
        padding:15px;
    "
>

    <div
        class="panel"
        style="
            width:100%;
            max-width:350px;
            margin:0;
        "
    >

        <div class="panel-title">
            Règlement de la facture
        </div>

        <form method="POST">

            <input
                type="hidden"
                name="reference"
                id="reference"
            >

            <label>
                Montant payé
            </label>

            <input
                type="number"
                name="montant_paye"
                id="montant_paye"
                min="0"
                step="1"
                required
            >

            <div
                style="
                    display:flex;
                    gap:6px;
                    justify-content:flex-end;
                    margin-top:10px;
                "
            >

                <button
                    type="button"
                    class="btn btn-light"
                    onclick="fermerModal()"
                >
                    Annuler
                </button>

                <button
                    type="submit"
                    name="regler_facture"
                    class="btn btn-success"
                >
                    Enregistrer
                </button>

            </div>

        </form>

    </div>

</div>


<script>

function ajouterLigne(){

    const container = document.getElementById("lines");

    const premiere = container.querySelector(".line");

    const nouvelle = premiere.cloneNode(true);

    nouvelle.querySelector("select").value = "";

    nouvelle.querySelectorAll("input").forEach(function(input){

        if(input.name === "quantite[]"){
            input.value = 1;
        }else{
            input.value = 0;
        }

    });

    container.appendChild(nouvelle);

    calculer();
}


function supprimerLigne(button){

    const lignes = document.querySelectorAll(".line");

    if(lignes.length <= 1){
        return;
    }

    button.closest(".line").remove();

    calculer();
}


function calculer(){

    let total = 0;

    document.querySelectorAll(".line").forEach(function(ligne){

        const qte =
            parseFloat(
                ligne.querySelector('input[name="quantite[]"]').value
            ) || 0;

        const prix =
            parseFloat(
                ligne.querySelector('input[name="prix[]"]').value
            ) || 0;

        total += qte * prix;

    });

    document.getElementById("total").textContent =
        new Intl.NumberFormat("fr-FR").format(total);
}


function regler(reference, reste){

    document.getElementById("reference").value = reference;

    document.getElementById("montant_paye").value = reste;

    document.getElementById("modal").style.display = "flex";
}


function fermerModal(){

    document.getElementById("modal").style.display = "none";
}


document.getElementById("paye").addEventListener("input", calculer);

calculer();

</script>

</body>
</html>
