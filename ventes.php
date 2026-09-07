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
   ID MANUEL
========================================================= */
function prochain_id($conn, $table) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);

    $r = $conn->query(
        "SELECT COALESCE(MAX(id),0)+1 AS prochain_id FROM $table"
    );

    return (int)$r->fetch_assoc()["prochain_id"];
}


/* =========================================================
   MODIFIER UNE VENTE NON PAYÉE
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["modifier_vente"])) {

    $vente_id = (int)($_POST["vente_id"] ?? 0);
    $produit_id = (int)($_POST["produit_id"] ?? 0);
    $quantite = (int)($_POST["quantite"] ?? 0);
    $prix_unitaire = (float)($_POST["prix_unitaire"] ?? 0);
    $client = trim($_POST["client"] ?? "");

    if (
        $vente_id <= 0 ||
        $produit_id <= 0 ||
        $quantite <= 0 ||
        $prix_unitaire < 0
    ) {
        $message = "Les informations de la vente sont incorrectes.";
        $type = "error";
    } else {

        $conn->begin_transaction();

        try {

            $stmt = $conn->prepare(
                "SELECT *
                 FROM ventes
                 WHERE id = ?
                 LIMIT 1"
            );

            $stmt->bind_param("i", $vente_id);
            $stmt->execute();

            $ancienne = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$ancienne) {
                throw new Exception("Vente introuvable.");
            }

            /* Paiement récupéré dans description */
            $ancienne_description = $ancienne["description"] ?? "";

            preg_match(
                '/Payé\s*:\s*([0-9\s,\.]+)\s*FG/i',
                $ancienne_description,
                $matchPaye
            );

            $ancien_paye = 0;

            if (isset($matchPaye[1])) {
                $ancien_paye = (float)str_replace(
                    [" ", ","],
                    ["", "."],
                    $matchPaye[1]
                );
            }

            if ($ancien_paye > 0) {
                throw new Exception(
                    "Cette vente contient déjà un paiement ou une avance. Elle est verrouillée."
                );
            }

            /* Produit demandé */
            $stmt = $conn->prepare(
                "SELECT nom, stock
                 FROM produits
                 WHERE id = ?
                 LIMIT 1"
            );

            $stmt->bind_param(
                "i",
                $produit_id
            );

            $stmt->execute();

            $produit = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$produit) {
                throw new Exception("Article introuvable.");
            }

            /*
             * Remettre l'ancien article en stock
             */
            $ancien_produit_id = (int)$ancienne["produit_id"];
            $ancienne_qte = (int)$ancienne["quantite"];

            $stmt = $conn->prepare(
                "UPDATE produits
                 SET stock = stock + ?
                 WHERE id = ?"
            );

            $stmt->bind_param(
                "ii",
                $ancienne_qte,
                $ancien_produit_id
            );

            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }

            $stmt->close();

            /*
             * Vérifier le nouveau stock
             */
            if ((int)$produit["stock"] < $quantite &&
                $produit_id != $ancien_produit_id) {

                throw new Exception(
                    "Stock insuffisant pour le nouvel article."
                );
            }

            /*
             * Si même article, le stock disponible comprend
             * déjà l'ancienne quantité remise.
             */
            if ($produit_id == $ancien_produit_id) {

                $stock_disponible =
                    (int)$produit["stock"] + $ancienne_qte;

            } else {

                $stock_disponible =
                    (int)$produit["stock"];
            }

            if ($stock_disponible < $quantite) {

                throw new Exception(
                    "Stock insuffisant."
                );
            }

            /*
             * Retirer la nouvelle quantité
             */
            $stmt = $conn->prepare(
                "UPDATE produits
                 SET stock = stock - ?
                 WHERE id = ?"
            );

            $stmt->bind_param(
                "ii",
                $quantite,
                $produit_id
            );

            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }

            $stmt->close();

            $montant =
                $quantite * $prix_unitaire;

            $date =
                $ancienne["date_vente"] ??
                date("Y-m-d H:i:s");

            $description =
                "VENTE | Client : " .
                ($client ?: "Client comptoir") .
                " | Désignation : " .
                $produit["nom"] .
                " | Total : " .
                argent($montant) .
                " | Payé : 0 FG" .
                " | Reste : " .
                argent($montant);

            /*
             * Modifier la vente
             */
            $stmt = $conn->prepare(
                "UPDATE ventes
                 SET produit_id = ?,
                     quantite = ?,
                     prix_unitaire = ?,
                     montant = ?,
                     description = ?
                 WHERE id = ?"
            );

            $stmt->bind_param(
                "iiddsi",
                $produit_id,
                $quantite,
                $prix_unitaire,
                $montant,
                $description,
                $vente_id
            );

            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }

            $stmt->close();

            $conn->commit();

            $message =
                "Vente modifiée avec succès.";

            $type = "success";

        } catch (Exception $e) {

            $conn->rollback();

            $message =
                "Impossible de modifier : " .
                $e->getMessage();

            $type = "error";
        }
    }
}


/* =========================================================
   SUPPRIMER UNE VENTE NON PAYÉE
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["supprimer_vente"])) {

    $vente_id =
        (int)($_POST["vente_id"] ?? 0);

    if ($vente_id <= 0) {

        $message = "Vente introuvable.";
        $type = "error";

    } else {

        $conn->begin_transaction();

        try {

            $stmt = $conn->prepare(
                "SELECT *
                 FROM ventes
                 WHERE id = ?
                 LIMIT 1"
            );

            $stmt->bind_param(
                "i",
                $vente_id
            );

            $stmt->execute();

            $vente =
                $stmt->get_result()->fetch_assoc();

            $stmt->close();

            if (!$vente) {
                throw new Exception(
                    "Vente introuvable."
                );
            }

            $description =
                $vente["description"] ?? "";

            preg_match(
                '/Payé\s*:\s*([0-9\s,\.]+)\s*FG/i',
                $description,
                $matchPaye
            );

            $paye = 0;

            if (isset($matchPaye[1])) {

                $paye =
                    (float)str_replace(
                        [" ", ","],
                        ["", "."],
                        $matchPaye[1]
                    );
            }

            if ($paye > 0) {

                throw new Exception(
                    "Cette vente contient déjà un paiement ou une avance. Elle ne peut pas être supprimée."
                );
            }

            /*
             * Restaurer stock
             */
            $produit_id =
                (int)$vente["produit_id"];

            $quantite =
                (int)$vente["quantite"];

            $stmt = $conn->prepare(
                "UPDATE produits
                 SET stock = stock + ?
                 WHERE id = ?"
            );

            $stmt->bind_param(
                "ii",
                $quantite,
                $produit_id
            );

            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }

            $stmt->close();

            /*
             * Supprimer vente
             */
            $stmt = $conn->prepare(
                "DELETE FROM ventes
                 WHERE id = ?"
            );

            $stmt->bind_param(
                "i",
                $vente_id
            );

            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }

            $stmt->close();

            $conn->commit();

            $message =
                "Vente supprimée et stock restauré.";

            $type = "success";

        } catch (Exception $e) {

            $conn->rollback();

            $message =
                "Impossible de supprimer : " .
                $e->getMessage();

            $type = "error";
        }
    }
}


/* =========================================================
   ANNULER UNE VENTE PAYÉE / AVEC AVANCE
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["annuler_vente"])) {

    $vente_id =
        (int)($_POST["vente_id"] ?? 0);

    if ($vente_id <= 0) {

        $message = "Vente introuvable.";
        $type = "error";

    } else {

        $conn->begin_transaction();

        try {

            $stmt = $conn->prepare(
                "SELECT *
                 FROM ventes
                 WHERE id = ?
                 LIMIT 1"
            );

            $stmt->bind_param(
                "i",
                $vente_id
            );

            $stmt->execute();

            $vente =
                $stmt->get_result()->fetch_assoc();

            $stmt->close();

            if (!$vente) {
                throw new Exception(
                    "Vente introuvable."
                );
            }

            $description =
                $vente["description"] ?? "";

            if (
                strpos($description, "ANNULÉE") !== false ||
                strpos($description, "ANNULEE") !== false
            ) {

                throw new Exception(
                    "Cette vente est déjà annulée."
                );
            }

            /*
             * Restaurer le stock
             */
            $produit_id =
                (int)$vente["produit_id"];

            $quantite =
                (int)$vente["quantite"];

            $stmt = $conn->prepare(
                "UPDATE produits
                 SET stock = stock + ?
                 WHERE id = ?"
            );

            $stmt->bind_param(
                "ii",
                $quantite,
                $produit_id
            );

            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }

            $stmt->close();

            /*
             * Marquer comme annulée
             */
            $nouvelle_description =
                $description .
                " | ANNULÉE LE " .
                date("d/m/Y H:i");

            $stmt = $conn->prepare(
                "UPDATE ventes
                 SET description = ?
                 WHERE id = ?"
            );

            $stmt->bind_param(
                "si",
                $nouvelle_description,
                $vente_id
            );

            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }

            $stmt->close();

            $conn->commit();

            $message =
                "Vente annulée. Le stock a été restauré.";

            $type = "success";

        } catch (Exception $e) {

            $conn->rollback();

            $message =
                "Impossible d'annuler : " .
                $e->getMessage();

            $type = "error";
        }
    }
}


/* =========================================================
   NOUVELLE VENTE
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["enregistrer_vente"])) {

    $client =
        trim($_POST["client"] ?? "");

    $paye =
        (float)($_POST["paye"] ?? 0);

    $ids =
        $_POST["produit_id"] ?? [];

    $quantites =
        $_POST["quantite"] ?? [];

    $prix =
        $_POST["prix"] ?? [];

    $lignes = [];
    $total = 0;

    for ($i = 0; $i < count($ids); $i++) {

        $produit_id =
            (int)($ids[$i] ?? 0);

        $qte =
            (int)($quantites[$i] ?? 0);

        $pu =
            (float)($prix[$i] ?? 0);

        if (
            $produit_id > 0 &&
            $qte > 0 &&
            $pu >= 0
        ) {

            $lignes[] = [
                "produit_id" => $produit_id,
                "quantite" => $qte,
                "prix" => $pu
            ];

            $total +=
                $qte * $pu;
        }
    }

    if (count($lignes) === 0) {

        $message =
            "Ajoute au moins un article.";

        $type = "error";

    } elseif ($paye < 0 || $paye > $total) {

        $message =
            "Le montant payé est incorrect.";

        $type = "error";

    } else {

        $reste =
            $total - $paye;

        $date =
            date("Y-m-d H:i:s");

        $conn->begin_transaction();

        try {

            foreach ($lignes as $ligne) {

                $produit_id =
                    $ligne["produit_id"];

                $qte =
                    $ligne["quantite"];

                $pu =
                    $ligne["prix"];

                /*
                 * Vérifier article et stock
                 */
                $stmt = $conn->prepare(
                    "SELECT nom, stock
                     FROM produits
                     WHERE id = ?
                     LIMIT 1"
                );

                $stmt->bind_param(
                    "i",
                    $produit_id
                );

                $stmt->execute();

                $produit =
                    $stmt->get_result()->fetch_assoc();

                $stmt->close();

                if (!$produit) {

                    throw new Exception(
                        "Article introuvable."
                    );
                }

                if (
                    (int)$produit["stock"] <
                    $qte
                ) {

                    throw new Exception(
                        "Stock insuffisant pour : " .
                        $produit["nom"]
                    );
                }

                $montant =
                    $qte * $pu;

                /*
                 * Description historique.
                 * Le nom est conservé ici pour
                 * ne pas dépendre du nom futur
                 * du produit.
                 */
                $description =
                    "VENTE | Client : " .
                    ($client ?: "Client comptoir") .
                    " | Désignation : " .
                    $produit["nom"] .
                    " | Total facture : " .
                    argent($total) .
                    " | Payé : " .
                    argent($paye) .
                    " | Reste : " .
                    argent($reste);

                /*
                 * ID manuel
                 */
                $vente_id =
                    prochain_id(
                        $conn,
                        "ventes"
                    );

                /*
                 * Enregistrer vente
                 */
                $stmt = $conn->prepare(
                    "INSERT INTO ventes
                    (id, produit_id, quantite, prix_unitaire, montant, description, date_vente)
                    VALUES (?, ?, ?, ?, ?, ?, ?)"
                );

                $stmt->bind_param(
                    "iiiddss",
                    $vente_id,
                    $produit_id,
                    $qte,
                    $pu,
                    $montant,
                    $description,
                    $date
                );

                if (!$stmt->execute()) {
                    throw new Exception(
                        $stmt->error
                    );
                }

                $stmt->close();

                /*
                 * Diminuer stock
                 */
                $stmt = $conn->prepare(
                    "UPDATE produits
                     SET stock = stock - ?
                     WHERE id = ?"
                );

                $stmt->bind_param(
                    "ii",
                    $qte,
                    $produit_id
                );

                if (!$stmt->execute()) {
                    throw new Exception(
                        $stmt->error
                    );
                }

                $stmt->close();
            }

            $conn->commit();

            $message =
                "Vente enregistrée : " .
                argent($total) .
                " • Payé : " .
                argent($paye) .
                " • Reste : " .
                argent($reste);

            $type = "success";

        } catch (Exception $e) {

            $conn->rollback();

            $message =
                "Erreur : " .
                $e->getMessage();

            $type = "error";
        }
    }
}


/* =========================================================
   ARTICLE À MODIFIER
========================================================= */
$vente_edit = null;

if (isset($_GET["modifier"])) {

    $id =
        (int)$_GET["modifier"];

    if ($id > 0) {

        $stmt = $conn->prepare(
            "SELECT *
             FROM ventes
             WHERE id = ?
             LIMIT 1"
        );

        $stmt->bind_param(
            "i",
            $id
        );

        $stmt->execute();

        $vente_edit =
            $stmt->get_result()->fetch_assoc();

        $stmt->close();
    }
}


/* =========================================================
   FACTURE
========================================================= */
$facture = null;
$facture_lignes = [];

if (isset($_GET["facture"])) {

    $facture_id =
        (int)$_GET["facture"];

    if ($facture_id > 0) {

        $stmt = $conn->prepare(
            "SELECT *
             FROM ventes
             WHERE id = ?
             LIMIT 1"
        );

        $stmt->bind_param(
            "i",
            $facture_id
        );

        $stmt->execute();

        $facture =
            $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if ($facture) {

            $description =
                $facture["description"] ?? "";

            preg_match(
                '/Client\s*:\s*(.*?)\s*\|/i',
                $description,
                $clientMatch
            );

            preg_match(
                '/Désignation\s*:\s*(.*?)\s*\|/i',
                $description,
                $designationMatch
            );

            preg_match(
                '/Payé\s*:\s*([0-9\s,\.]+)\s*FG/i',
                $description,
                $payeMatch
            );

            preg_match(
                '/Reste\s*:\s*([0-9\s,\.]+)\s*FG/i',
                $description,
                $resteMatch
            );

            $facture_client =
                $clientMatch[1] ??
                "Client comptoir";

            $facture_designation =
                $designationMatch[1] ??
                "Article";

            $facture_paye = 0;
            $facture_reste = 0;

            if(isset($payeMatch[1])) {

                $facture_paye =
                    (float)str_replace(
                        [" ", ","],
                        ["", "."],
                        $payeMatch[1]
                    );
            }

            if(isset($resteMatch[1])) {

                $facture_reste =
                    (float)str_replace(
                        [" ", ","],
                        ["", "."],
                        $resteMatch[1]
                    );
            }
        }
    }
}


/* =========================================================
   LISTE PRODUITS
========================================================= */
$produits =
$conn->query(
    "SELECT id, nom, stock
     FROM produits
     ORDER BY nom ASC"
);


/* =========================================================
   HISTORIQUE VENTES
========================================================= */
$ventes =
$conn->query(
    "SELECT v.*, p.nom AS produit_nom
     FROM ventes v
     LEFT JOIN produits p
     ON p.id = v.produit_id
     ORDER BY v.id DESC
     LIMIT 100"
);


/* =========================================================
   TOTAL VENTES
========================================================= */
$total_ventes = 0;

$r =
$conn->query(
    "SELECT COALESCE(SUM(montant),0) AS total
     FROM ventes
     WHERE description NOT LIKE '%ANNULÉE%'
     AND description NOT LIKE '%ANNULEE%'"
);

if($r) {

    $total_ventes =
        (float)$r->fetch_assoc()["total"];
}


/* =========================================================
   NOMBRE VENTES
========================================================= */
$nombre_ventes = 0;

$r =
$conn->query(
    "SELECT COUNT(*) AS total
     FROM ventes
     WHERE description NOT LIKE '%ANNULÉE%'
     AND description NOT LIKE '%ANNULEE%'"
);

if($r) {

    $nombre_ventes =
        (int)$r->fetch_assoc()["total"];
}

?>

<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">

<meta
name="viewport"
content="width=device-width, initial-scale=1.0"
>

<title>LAMBEMAH • Ventes</title>

<link
href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
rel="stylesheet"
>

<link
href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
rel="stylesheet"
>


<style>

:root{
    --bleu:#102a43;
    --bleu2:#1d4ed8;
    --bleu3:#eaf2ff;
    --fond:#f5f8fc;
    --texte:#172033;
    --gris:#64748b;
}

*{
    box-sizing:border-box;
}

body{
    margin:0;
    background:var(--fond);
    font-family:Arial,sans-serif;
    color:var(--texte);
}


/* SIDEBAR */

.sidebar{
    position:fixed;
    left:0;
    top:0;
    width:245px;
    height:100vh;
    background:var(--bleu);
    padding:20px 15px;
    color:white;
    z-index:1000;
}

.logo{
    font-size:23px;
    font-weight:800;
    padding:5px 10px 20px;
}

.logo small{
    display:block;
    font-size:10px;
    color:#9cc8ff;
    margin-top:5px;
    letter-spacing:1px;
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
    background:var(--bleu2);
    color:white;
}


/* MAIN */

.main{
    margin-left:245px;
    padding:25px;
    max-width:1500px;
}

.top-title{
    margin-bottom:20px;
}

.top-title h1{
    margin:0;
    font-size:28px;
    font-weight:800;
}

.top-title p{
    color:var(--gris);
    margin:5px 0 0;
}


/* STATS */

.stats{
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:15px;
    margin-bottom:20px;
}

.stat{
    background:white;
    padding:18px;
    border-radius:15px;
    box-shadow:0 3px 15px rgba(0,0,0,.05);
}

.stat-icon{
    width:42px;
    height:42px;
    border-radius:12px;
    background:var(--bleu3);
    color:var(--bleu2);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:20px;
    margin-bottom:10px;
}

.stat-label{
    color:var(--gris);
    font-size:13px;
}

.stat-value{
    font-size:23px;
    font-weight:800;
}


/* BOX */

.box{
    background:white;
    border-radius:16px;
    padding:20px;
    margin-bottom:20px;
    box-shadow:0 3px 15px rgba(0,0,0,.05);
}

.box-title{
    font-size:19px;
    font-weight:800;
    margin-bottom:15px;
}


/* FORM */

.form-control,
.form-select{
    min-height:44px;
    border-radius:9px;
}

label{
    font-size:13px;
    font-weight:600;
    margin-bottom:5px;
}

.ligne{
    background:#f8fafc;
    border:1px solid #e5e7eb;
    border-radius:11px;
    padding:12px;
    margin-bottom:10px;
}

.total{
    background:var(--bleu3);
    border-radius:11px;
    padding:15px;
    font-size:19px;
    font-weight:800;
}

.reste{
    color:#b42318;
}


/* FACTURE */

.facture{
    border:1px solid #e2e8f0;
    border-radius:15px;
    padding:25px;
    background:white;
}

.facture-head{
    display:flex;
    justify-content:space-between;
    gap:20px;
    border-bottom:1px solid #e5e7eb;
    padding-bottom:15px;
    margin-bottom:20px;
}

.facture-brand{
    font-size:24px;
    font-weight:900;
    color:var(--bleu);
}

.facture-number{
    font-weight:bold;
    color:var(--bleu2);
}

.paye-ok{
    color:#166534;
    font-weight:bold;
}

.paye-reste{
    color:#b42318;
    font-weight:bold;
}


/* MOBILE */

@media(max-width:768px){

    .sidebar{
        position:static;
        width:100%;
        height:auto;
        padding:14px 10px;
    }

    .logo{
        text-align:center;
        padding-bottom:12px;
    }

    .menu{
        display:grid;
        grid-template-columns:repeat(4,1fr);
        gap:3px;
    }

    .menu a{
        flex-direction:column;
        justify-content:center;
        font-size:9px;
        text-align:center;
        padding:8px 3px;
        margin:0;
        gap:4px;
    }

    .main{
        margin-left:0;
        padding:12px;
    }

    .top-title h1{
        font-size:22px;
    }

    .stats{
        grid-template-columns:1fr;
        gap:10px;
    }

    .box{
        padding:14px;
        border-radius:13px;
    }

    .table{
        font-size:12px;
    }

    .table th,
    .table td{
        white-space:nowrap;
    }

    .facture{
        padding:15px;
    }

    .facture-head{
        display:block;
    }
}


/* IMPRESSION */

@media print{

    body{
        background:white;
    }

    .sidebar,
    .main > *:not(.zone-facture){
        display:none !important;
    }

    .zone-facture{
        display:block !important;
    }

    .facture{
        box-shadow:none;
        border:none;
    }

}

</style>

</head>


<body>


<!-- ======================================================
     MENU
====================================================== -->

<aside class="sidebar">

<div class="logo">

LAMBEMAH

<small>
GESTION • PRESTATION
</small>

</div>


<nav class="menu">

<a href="index.php">

<i class="bi bi-house"></i>
Accueil

</a>


<a href="produits.php">

<i class="bi bi-box"></i>
Achats / Stock

</a>


<a
href="ventes.php"
class="active"
>

<i class="bi bi-cart-check"></i>
Ventes

</a>


<a href="prestations.php">

<i class="bi bi-printer"></i>
Prestations

</a>


<a href="recettes.php">

<i class="bi bi-cash-coin"></i>
Recettes

</a>


<a href="depenses.php">

<i class="bi bi-wallet2"></i>
Dépenses

</a>


<a href="statistiques.php">

<i class="bi bi-bar-chart"></i>
Statistiques

</a>


<?php if($role === "admin"): ?>

<a href="utilisateurs.php">

<i class="bi bi-people"></i>
Équipe

</a>

<?php endif; ?>


<a href="index.php?logout=1">

<i class="bi bi-box-arrow-right"></i>
Déconnexion

</a>

</nav>

</aside>


<!-- ======================================================
     MAIN
====================================================== -->

<main class="main">


<div class="top-title">

<h1>
🧾 Ventes
</h1>

<p>
Créez vos ventes, factures et suivez les paiements.
</p>

</div>


<?php if($message): ?>

<div class="alert
<?= $type === "success"
? "alert-success"
: "alert-danger"
?>
">

<?= htmlspecialchars($message) ?>

</div>

<?php endif; ?>


<!-- ======================================================
     STATS
====================================================== -->

<div class="stats">


<div class="stat">

<div class="stat-icon">

<i class="bi bi-receipt"></i>

</div>

<div class="stat-label">
Ventes enregistrées
</div>

<div class="stat-value">
<?= $nombre_ventes ?>
</div>

</div>


<div class="stat">

<div class="stat-icon">

<i class="bi bi-cash-stack"></i>

</div>

<div class="stat-label">
Total des ventes
</div>

<div class="stat-value">
<?= argent($total_ventes) ?>
</div>

</div>

</div>


<!-- ======================================================
     FACTURE
====================================================== -->

<?php if($facture): ?>

<div
class="box zone-facture"
>

<div class="facture">

<div class="facture-head">

<div>

<div class="facture-brand">
LAMBEMAH
</div>

<div class="text-muted">
GESTION • PRESTATION
</div>

</div>


<div class="text-end">

<div class="facture-number">
FACTURE #<?= (int)$facture["id"] ?>
</div>

<div>

<?= !empty($facture["date_vente"])
? date(
    "d/m/Y H:i",
    strtotime($facture["date_vente"])
)
: "-"
?>

</div>

</div>

</div>


<div class="mb-3">

<strong>
Client :
</strong>

<?= htmlspecialchars(
$facture_client
) ?>

</div>


<table class="table">

<thead>

<tr>

<th>
Désignation
</th>

<th>
Qté
</th>

<th>
PU
</th>

<th>
Montant
</th>

</tr>

</thead>


<tbody>

<tr>

<td>

<?= htmlspecialchars(
$facture_designation
) ?>

</td>

<td>
<?= (int)$facture["quantite"] ?>
</td>

<td>
<?= argent(
$facture["prix_unitaire"]
) ?>
</td>

<td>

<strong>
<?= argent(
$facture["montant"]
) ?>
</strong>

</td>

</tr>

</tbody>

</table>


<div class="row justify-content-end">

<div class="col-md-5">

<div class="d-flex justify-content-between">

<span>
Total
</span>

<strong>
<?= argent(
$facture["montant"]
) ?>
</strong>

</div>


<div class="d-flex justify-content-between">

<span>
Payé
</span>

<strong class="paye-ok">

<?= argent(
$facture_paye
) ?>

</strong>

</div>


<div class="d-flex justify-content-between">

<span>
Reste
</span>

<strong class="paye-reste">

<?= argent(
$facture_reste
) ?>

</strong>

</div>

</div>

</div>


<div class="mt-4">

<button
onclick="window.print()"
class="btn btn-primary"
>

<i class="bi bi-printer"></i>

Imprimer

</button>


<a
href="ventes.php"
class="btn btn-light"
>

Retour

</a>

</div>

</div>

</div>

<?php endif; ?>


<!-- ======================================================
     MODIFIER VENTE
====================================================== -->

<?php if($vente_edit): ?>

<div class="box">

<div class="box-title">

<i class="bi bi-pencil-square"></i>

Modifier la vente

</div>


<?php

$edit_description =
$vente_edit["description"] ?? "";

preg_match(
    '/Payé\s*:\s*([0-9\s,\.]+)\s*FG/i',
    $edit_description,
    $editPaye
);

$edit_paye = 0;

if(isset($editPaye[1])){

    $edit_paye =
        (float)str_replace(
            [" ", ","],
            ["", "."],
            $editPaye[1]
        );
}

?>


<?php if($edit_paye > 0): ?>

<div class="alert alert-warning">

<i class="bi bi-lock-fill"></i>

Cette vente possède déjà un paiement ou une avance.
Elle est verrouillée et ne peut pas être modifiée.

</div>

<a
href="ventes.php"
class="btn btn-light"
>
Retour
</a>


<?php else: ?>


<form method="POST">

<input
type="hidden"
name="modifier_vente"
value="1"
>

<input
type="hidden"
name="vente_id"
value="<?= (int)$vente_edit["id"] ?>"
>


<div class="row g-3">


<div class="col-md-4">

<label>
Client
</label>

<?php

preg_match(
    '/Client\s*:\s*(.*?)\s*\|/i',
    $edit_description,
    $clientEdit
);

$client_value =
$clientEdit[1] ??
"Client comptoir";

?>

<input
type="text"
name="client"
class="form-control"
value="<?= htmlspecialchars($client_value) ?>"
>

</div>


<div class="col-md-4">

<label>
Article
</label>

<select
name="produit_id"
class="form-select"
required
>

<?php

$produits_edit =
$conn->query(
"SELECT id, nom, stock
 FROM produits
 ORDER BY nom"
);

while(
$p = $produits_edit->fetch_assoc()
):

?>

<option
value="<?= (int)$p["id"] ?>"
<?= (int)$p["id"] ===
(int)$vente_edit["produit_id"]
? "selected"
: ""
?>
>

<?= htmlspecialchars($p["nom"]) ?>

— Stock <?= (int)$p["stock"] ?>

</option>

<?php endwhile; ?>

</select>

</div>


<div class="col-md-2">

<label>
Prix unitaire
</label>

<input
type="number"
name="prix_unitaire"
class="form-control"
min="0"
value="<?= (float)$vente_edit["prix_unitaire"] ?>"
required
>

</div>


<div class="col-md-2">

<label>
Quantité
</label>

<input
type="number"
name="quantite"
class="form-control"
min="1"
value="<?= (int)$vente_edit["quantite"] ?>"
required
>

</div>


</div>


<div class="mt-3">

<button
class="btn btn-primary"
>

<i class="bi bi-check-lg"></i>

Enregistrer les modifications

</button>


<a
href="ventes.php"
class="btn btn-light"
>

Annuler

</a>

</div>

</form>

<?php endif; ?>

</div>

<?php endif; ?>


<!-- ======================================================
     NOUVELLE VENTE
====================================================== -->

<div class="box">

<div class="box-title">

🛒 Nouvelle vente

</div>


<form method="POST">


<input
type="hidden"
name="enregistrer_vente"
value="1"
>


<div class="mb-3">

<label>
Client
</label>

<input
type="text"
name="client"
class="form-control"
placeholder="Ex : Mme Mariama"
>

</div>


<div id="lignes">


<div class="ligne">

<div class="row g-2 align-items-end">


<div class="col-md-5">

<label>
Article
</label>

<select
name="produit_id[]"
class="form-select"
required
>

<option value="">
Choisir un article
</option>


<?php

$produits_form =
$conn->query(
"SELECT id, nom, stock
 FROM produits
 ORDER BY nom ASC"
);

while(
$p = $produits_form->fetch_assoc()
):

?>

<option value="<?= (int)$p["id"] ?>">

<?= htmlspecialchars($p["nom"]) ?>

— Stock <?= (int)$p["stock"] ?>

</option>

<?php endwhile; ?>

</select>

</div>


<div class="col-md-2">

<label>
PVU
</label>

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

<label>
Quantité
</label>

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

<label>
Montant
</label>

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

Ajouter un article

</button>


<div class="total mb-3">

TOTAL :

<span id="total">
0 FG
</span>

</div>


<div class="mb-3">

<label>
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


<button
class="btn btn-primary btn-lg"
>

<i class="bi bi-receipt"></i>

Créer la vente

</button>

</form>

</div>


<!-- ======================================================
     HISTORIQUE
====================================================== -->

<div class="box">

<div class="box-title">

🧾 Historique des ventes

</div>


<div class="table-responsive">

<table class="table align-middle">

<thead>

<tr>

<th>
Client
</th>

<th>
Article
</th>

<th>
Qté
</th>

<th>
PVU
</th>

<th>
Total
</th>

<th>
Paiement
</th>

<th>
Date
</th>

<th>
Actions
</th>

</tr>

</thead>


<tbody>


<?php if(
$ventes &&
$ventes->num_rows
): ?>


<?php while(
$v = $ventes->fetch_assoc()
): ?>


<?php

$desc =
$v["description"] ?? "";


/* Client */
preg_match(
    '/Client\s*:\s*(.*?)\s*\|/i',
    $desc,
    $clientMatch
);

$client =
$clientMatch[1] ??
"Client comptoir";


/* Paiement */
preg_match(
    '/Payé\s*:\s*([0-9\s,\.]+)\s*FG/i',
    $desc,
    $payeMatch
);

$paye = 0;

if(isset($payeMatch[1])){

    $paye =
        (float)str_replace(
            [" ", ","],
            ["", "."],
            $payeMatch[1]
        );
}


/* État */
$annulee =
(
    strpos($desc,"ANNULÉE") !== false ||
    strpos($desc,"ANNULEE") !== false
);

?>


<tr>


<td>

<strong>
<?= htmlspecialchars($client) ?>
</strong>

</td>


<td>

<?= htmlspecialchars(
$v["produit_nom"] ??
"Article"
) ?>

</td>


<td>

<?= (int)$v["quantite"] ?>

</td>


<td>

<?= argent(
$v["prix_unitaire"]
) ?>

</td>


<td>

<strong>

<?= argent(
$v["montant"]
) ?>

</strong>

</td>


<td>


<?php if($annulee): ?>

<span class="badge text-bg-secondary">
Annulée
</span>


<?php elseif($paye > 0): ?>

<span class="badge text-bg-warning">
Payée / Avance
</span>


<?php else: ?>

<span class="badge text-bg-success">
Non payée
</span>

<?php endif; ?>


</td>


<td>

<?= !empty($v["date_vente"])
? date(
    "d/m/Y H:i",
    strtotime($v["date_vente"])
)
: "-"
?>

</td>


<td>


<?php if(!$annulee): ?>


<!-- FACTURE -->

<a
href="ventes.php?facture=<?= (int)$v["id"] ?>"
class="btn btn-sm btn-outline-primary"
title="Facture"
>

<i class="bi bi-receipt"></i>

</a>


<?php if($paye <= 0): ?>


<!-- MODIFIER -->

<a
href="ventes.php?modifier=<?= (int)$v["id"] ?>"
class="btn btn-sm btn-outline-secondary"
title="Modifier"
>

<i class="bi bi-pencil"></i>

</a>


<!-- SUPPRIMER -->

<form
method="POST"
style="display:inline"
onsubmit="return confirmerSuppression()"
>

<input
type="hidden"
name="supprimer_vente"
value="1"
>

<input
type="hidden"
name="vente_id"
value="<?= (int)$v["id"] ?>"
>

<button
class="btn btn-sm btn-outline-danger"
title="Supprimer"
>

<i class="bi bi-trash"></i>

</button>

</form>


<?php else: ?>


<!-- ANNULER SI PAIEMENT -->

<form
method="POST"
style="display:inline"
onsubmit="return confirmerAnnulation()"
>

<input
type="hidden"
name="annuler_vente"
value="1"
>

<input
type="hidden"
name="vente_id"
value="<?= (int)$v["id"] ?>"
>

<button
class="btn btn-sm btn-outline-warning"
title="Annuler"
>

<i class="bi bi-x-circle"></i>

</button>

</form>


<span
class="text-muted"
title="Vente verrouillée"
>

<i class="bi bi-lock-fill"></i>

</span>

<?php endif; ?>


<?php endif; ?>


</td>


</tr>


<?php endwhile; ?>


<?php else: ?>


<tr>

<td
colspan="8"
class="text-center text-muted"
>

Aucune vente enregistrée.

</td>

</tr>


<?php endif; ?>


</tbody>

</table>

</div>

</div>


</main>


<script>


/* =========================================================
   FORMAT ARGENT
========================================================= */

function argent(n){

    return new Intl.NumberFormat("fr-FR")
    .format(n) + " FG";

}


/* =========================================================
   CALCUL
========================================================= */

function calculer(){

    let total = 0;

    document
    .querySelectorAll(".ligne")
    .forEach(function(ligne){

        let prix =
            parseFloat(
                ligne.querySelector(".prix").value
            ) || 0;

        let qte =
            parseInt(
                ligne.querySelector(".quantite").value
            ) || 0;

        let montant =
            prix * qte;

        ligne.querySelector(".montant").value =
            argent(montant);

        total += montant;

    });


    document
    .getElementById("total")
    .textContent =
        argent(total);


    let paye =
        parseFloat(
            document.getElementById("paye").value
        ) || 0;


    let reste =
        total - paye;


    if(reste < 0){
        reste = 0;
    }


    document
    .getElementById("reste")
    .textContent =
        argent(reste);

}


/* =========================================================
   AJOUTER ARTICLE
========================================================= */

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

        if(
            input.classList.contains("prix")
        ){

            input.value = 0;

        }

        else if(
            input.classList.contains("quantite")
        ){

            input.value = 1;

        }

        else if(
            input.classList.contains("montant")
        ){

            input.value = "0 FG";

        }

    });


    nouvelle
    .querySelector("select")
    .selectedIndex = 0;


    conteneur.appendChild(nouvelle);

    calculer();

}


/* =========================================================
   SUPPRIMER LIGNE
========================================================= */

function supprimerLigne(btn){

    let lignes =
        document.querySelectorAll(".ligne");


    if(lignes.length <= 1){

        alert(
            "Il faut garder au moins un article."
        );

        return;
    }


    btn
    .closest(".ligne")
    .remove();


    calculer();

}


/* =========================================================
   CONFIRMATIONS
========================================================= */

function confirmerSuppression(){

    return confirm(
        "Supprimer cette vente ?\n\n" +
        "Le stock sera automatiquement restauré."
    );

}


function confirmerAnnulation(){

    return confirm(
        "Annuler cette vente ?\n\n" +
        "La vente restera dans l'historique " +
        "et le stock sera restauré."
    );

}


/* =========================================================
   INIT
========================================================= */

document.addEventListener(
    "DOMContentLoaded",
    function(){

        calculer();

    }
);

</script>


</body>

</html>
