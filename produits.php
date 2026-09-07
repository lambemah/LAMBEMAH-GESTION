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
   MODIFIER UN ARTICLE
   ========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["modifier_article"])) {

    $id = (int)($_POST["id"] ?? 0);
    $designation = trim($_POST["nom"] ?? "");
    $categorie = trim($_POST["categorie"] ?? "");
    $prix = (float)($_POST["prix_achat"] ?? 0);

    if ($id <= 0 || $designation === "") {

        $message = "La désignation est obligatoire.";
        $type = "error";

    } elseif ($prix < 0) {

        $message = "Le prix d'achat est incorrect.";
        $type = "error";

    } else {

        try {

            $stmt = $conn->prepare(
                "UPDATE produits
                 SET nom = ?, categorie = ?, prix_achat = ?
                 WHERE id = ?"
            );

            $stmt->bind_param(
                "ssdi",
                $designation,
                $categorie,
                $prix,
                $id
            );

            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }

            $stmt->close();

            $message = "Article modifié avec succès.";
            $type = "success";

        } catch (Exception $e) {

            $message = "Erreur : " . $e->getMessage();
            $type = "error";
        }
    }
}


/* =========================================================
   SUPPRIMER UN ACHAT NON PAYÉ
   ========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["supprimer_achat"])) {

    $mouvement_id = (int)($_POST["mouvement_id"] ?? 0);

    if ($mouvement_id <= 0) {

        $message = "Achat introuvable.";
        $type = "error";

    } else {

        $conn->begin_transaction();

        try {

            $stmt = $conn->prepare(
                "SELECT id, produit_id, quantite, prix, description
                 FROM mouvements
                 WHERE id = ?
                 AND type = 'ENTREE'
                 LIMIT 1"
            );

            $stmt->bind_param("i", $mouvement_id);
            $stmt->execute();

            $achat = $stmt->get_result()->fetch_assoc();

            $stmt->close();

            if (!$achat) {
                throw new Exception("Achat introuvable.");
            }

            $description = $achat["description"] ?? "";

            /* Vérification du paiement */
            preg_match(
                '/Payé\s*:\s*([0-9\s,\.]+)\s*FG/i',
                $description,
                $matchPaye
            );

            $paye = 0;

            if (isset($matchPaye[1])) {
                $paye = (float)str_replace(
                    [" ", ","],
                    ["", "."],
                    $matchPaye[1]
                );
            }

            if ($paye > 0) {
                throw new Exception(
                    "Cet achat contient déjà un paiement ou une avance. Il est verrouillé."
                );
            }

            /* Retour du stock */
            $stmt = $conn->prepare(
                "UPDATE produits
                 SET stock = stock - ?
                 WHERE id = ?
                 AND stock >= ?"
            );

            $qte = (int)$achat["quantite"];
            $produit_id = (int)$achat["produit_id"];

            $stmt->bind_param(
                "iii",
                $qte,
                $produit_id,
                $qte
            );

            if (!$stmt->execute() || $stmt->affected_rows === 0) {
                throw new Exception(
                    "Impossible de corriger le stock."
                );
            }

            $stmt->close();

            /* Suppression de l'achat */
            $stmt = $conn->prepare(
                "DELETE FROM mouvements
                 WHERE id = ?"
            );

            $stmt->bind_param(
                "i",
                $mouvement_id
            );

            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }

            $stmt->close();

            $conn->commit();

            $message = "Achat supprimé et stock corrigé.";
            $type = "success";

        } catch (Exception $e) {

            $conn->rollback();

            $message = "Impossible de supprimer : " . $e->getMessage();
            $type = "error";
        }
    }
}


/* =========================================================
   ANNULER UN ACHAT PAYÉ / AVEC AVANCE
   ========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["annuler_achat"])) {

    $mouvement_id = (int)($_POST["mouvement_id"] ?? 0);

    if ($mouvement_id <= 0) {

        $message = "Achat introuvable.";
        $type = "error";

    } else {

        $conn->begin_transaction();

        try {

            $stmt = $conn->prepare(
                "SELECT id, produit_id, quantite, prix, description
                 FROM mouvements
                 WHERE id = ?
                 AND type = 'ENTREE'
                 LIMIT 1"
            );

            $stmt->bind_param("i", $mouvement_id);
            $stmt->execute();

            $achat = $stmt->get_result()->fetch_assoc();

            $stmt->close();

            if (!$achat) {
                throw new Exception("Achat introuvable.");
            }

            $description = $achat["description"] ?? "";

            if (strpos($description, "ANNULÉ") !== false ||
                strpos($description, "ANNULE") !== false) {

                throw new Exception(
                    "Cet achat est déjà annulé."
                );
            }

            /* Stock : on retire la quantité achetée */
            $qte = (int)$achat["quantite"];
            $produit_id = (int)$achat["produit_id"];

            $stmt = $conn->prepare(
                "UPDATE produits
                 SET stock = stock - ?
                 WHERE id = ?
                 AND stock >= ?"
            );

            $stmt->bind_param(
                "iii",
                $qte,
                $produit_id,
                $qte
            );

            if (!$stmt->execute() || $stmt->affected_rows === 0) {
                throw new Exception(
                    "Stock insuffisant pour annuler cet achat."
                );
            }

            $stmt->close();

            /* Marquer l'achat comme annulé */
            $nouvelle_description =
                $description .
                " | ANNULÉ LE " .
                date("d/m/Y H:i");

            $stmt = $conn->prepare(
                "UPDATE mouvements
                 SET description = ?
                 WHERE id = ?"
            );

            $stmt->bind_param(
                "si",
                $nouvelle_description,
                $mouvement_id
            );

            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }

            $stmt->close();

            $conn->commit();

            $message =
                "Achat annulé. Le stock a été automatiquement corrigé.";

            $type = "success";

        } catch (Exception $e) {

            $conn->rollback();

            $message =
                "Impossible d'annuler : " . $e->getMessage();

            $type = "error";
        }
    }
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
            "SELECT id
             FROM produits
             WHERE nom = ?
             LIMIT 1"
        );

        $check->bind_param(
            "s",
            $designation
        );

        $check->execute();

        $existe =
            $check->get_result()->num_rows > 0;

        $check->close();

        if ($existe) {

            $message =
                "Cet article existe déjà. Utilise « Enregistrer un achat ».";

            $type = "error";

        } else {

            $conn->begin_transaction();

            try {

                /* ID manuel */
                $r = $conn->query(
                    "SELECT COALESCE(MAX(id),0)+1 AS id
                     FROM produits"
                );

                $produit_id =
                    (int)$r->fetch_assoc()["id"];

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

                /* Mouvement initial */
                if ($quantite > 0) {

                    $r = $conn->query(
                        "SELECT COALESCE(MAX(id),0)+1 AS id
                         FROM mouvements"
                    );

                    $mouvement_id =
                        (int)$r->fetch_assoc()["id"];

                    $date =
                        date("Y-m-d H:i:s");

                    $description =
                        "ACHAT | Fournisseur : " .
                        ($fournisseur ?: "Non renseigné") .
                        " | Désignation : " .
                        $designation .
                        " | Total : " .
                        argent($prix * $quantite) .
                        " | Payé : 0 FG" .
                        " | Reste : " .
                        argent($prix * $quantite);

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

                $message =
                    "Article ajouté avec succès.";

                $type = "success";

            } catch (Exception $e) {

                $conn->rollback();

                $message =
                    "Erreur : " . $e->getMessage();

                $type = "error";
            }
        }
    }
}


/* =========================================================
   ACHAT MULTI-ARTICLES
   ========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["enregistrer_achat"])) {

    $fournisseur =
        trim($_POST["fournisseur"] ?? "");

    $paye =
        (float)($_POST["paye"] ?? 0);

    $ids =
        $_POST["produit_id"] ?? [];

    $quantites =
        $_POST["quantite"] ?? [];

    $prix =
        $_POST["prix"] ?? [];

    if ($fournisseur === "") {

        $message =
            "Le fournisseur est obligatoire.";

        $type = "error";

    } else {

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

                $montant =
                    $qte * $pu;

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

                    /* Vérifier article */
                    $stmt = $conn->prepare(
                        "SELECT nom
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

                    /* ID mouvement */
                    $r = $conn->query(
                        "SELECT COALESCE(MAX(id),0)+1 AS id
                         FROM mouvements"
                    );

                    $mouvement_id =
                        (int)$r->fetch_assoc()["id"];

                    $description =
                        "ACHAT | Fournisseur : " .
                        $fournisseur .
                        " | Désignation : " .
                        $produit["nom"] .
                        " | Total achat : " .
                        argent($total) .
                        " | Payé : " .
                        argent($paye) .
                        " | Reste fournisseur : " .
                        argent($reste);

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

                    /* Ajouter au stock */
                    $stmt = $conn->prepare(
                        "UPDATE produits
                         SET stock = stock + ?,
                             prix_achat = ?
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
                    "Achat enregistré : " .
                    argent($total) .
                    " • Payé : " .
                    argent($paye) .
                    " • Reste : " .
                    argent($reste);

                $type = "success";

            } catch (Exception $e) {

                $conn->rollback();

                $message =
                    "Erreur : " . $e->getMessage();

                $type = "error";
            }
        }
    }
}


/* =========================================================
   ARTICLE À MODIFIER
   ========================================================= */
$article_edit = null;

if (isset($_GET["modifier"])) {

    $id_edit =
        (int)$_GET["modifier"];

    if ($id_edit > 0) {

        $stmt = $conn->prepare(
            "SELECT id, nom, categorie, prix_achat, stock
             FROM produits
             WHERE id = ?
             LIMIT 1"
        );

        $stmt->bind_param(
            "i",
            $id_edit
        );

        $stmt->execute();

        $article_edit =
            $stmt->get_result()->fetch_assoc();

        $stmt->close();
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


/* Historique des achats */
$mouvements = $conn->query(
    "SELECT m.*, p.nom AS produit_nom
     FROM mouvements m
     LEFT JOIN produits p
     ON p.id = m.produit_id
     WHERE m.type = 'ENTREE'
     AND m.description NOT LIKE 'ANNULATION ACHAT%'
     AND m.description NOT LIKE 'SUPPRESSION ACHAT%'
     ORDER BY m.id DESC
     LIMIT 50"
);


/* Total des achats non annulés */
$total_achat = 0;

$r = $conn->query(
    "SELECT COALESCE(SUM(m.quantite * m.prix),0) AS total
     FROM mouvements m
     WHERE m.type = 'ENTREE'
     AND m.description NOT LIKE '%ANNULÉ%'
     AND m.description NOT LIKE '%ANNULE%'"
);

if ($r) {

    $total_achat =
        (float)$r->fetch_assoc()["total"];
}


/* Nombre d'articles */
$total_articles = 0;

$r = $conn->query(
    "SELECT COUNT(*) AS total
     FROM produits"
);

if ($r) {

    $total_articles =
        (int)$r->fetch_assoc()["total"];
}


/* Quantité totale en stock */
$total_stock = 0;

$r = $conn->query(
    "SELECT COALESCE(SUM(stock),0) AS total
     FROM produits"
);

if ($r) {

    $total_stock =
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

<title>LAMBEMAH • Achats & Stock</title>

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
    --blanc:#ffffff;
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


/* =========================
   SIDEBAR
========================= */

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
    letter-spacing:.5px;
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
    transition:.2s;
}

.menu a:hover,
.menu a.active{
    background:var(--bleu2);
    color:white;
}

.menu i{
    font-size:17px;
}


/* =========================
   CONTENU
========================= */

.main{
    margin-left:245px;
    padding:25px;
    max-width:1500px;
}

.top-title{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    margin-bottom:20px;
}

.top-title h1{
    margin:0;
    font-size:28px;
    font-weight:800;
}

.top-title p{
    margin:5px 0 0;
    color:var(--gris);
}


/* =========================
   STATISTIQUES
========================= */

.stats{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:15px;
    margin-bottom:20px;
}

.stat{
    background:white;
    border-radius:15px;
    padding:18px;
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
    font-size:22px;
    font-weight:800;
    margin-top:3px;
}


/* =========================
   BOX
========================= */

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
    display:flex;
    align-items:center;
    gap:8px;
}


/* =========================
   FORM
========================= */

.form-control,
.form-select{
    min-height:44px;
    border-radius:9px;
}

.form-control:focus,
.form-select:focus{
    border-color:var(--bleu2);
    box-shadow:0 0 0 .2rem rgba(29,78,216,.10);
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


/* =========================
   ARTICLE
========================= */

.article-card{
    border:1px solid #e5e7eb;
    border-radius:13px;
    padding:15px;
    margin-bottom:10px;
    transition:.2s;
}

.article-card:hover{
    border-color:#bfdbfe;
    box-shadow:0 4px 12px rgba(0,0,0,.04);
}

.article-name{
    font-weight:800;
    font-size:16px;
}

.article-category{
    font-size:12px;
    color:var(--gris);
}

.stock-ok{
    background:#dcfce7;
    color:#166534;
    padding:5px 9px;
    border-radius:20px;
    font-size:12px;
    font-weight:bold;
}

.stock-low{
    background:#fef3c7;
    color:#92400e;
    padding:5px 9px;
    border-radius:20px;
    font-size:12px;
    font-weight:bold;
}

.stock-zero{
    background:#fee2e2;
    color:#991b1b;
    padding:5px 9px;
    border-radius:20px;
    font-size:12px;
    font-weight:bold;
}


/* =========================
   RECHERCHE
========================= */

.search-box{
    position:relative;
    margin-bottom:15px;
}

.search-box i{
    position:absolute;
    left:14px;
    top:13px;
    color:#94a3b8;
}

.search-box input{
    padding-left:40px;
}


/* =========================
   BOUTONS
========================= */

.btn{
    border-radius:9px;
}

.btn-primary{
    background:var(--bleu2);
    border-color:var(--bleu2);
}

.btn-primary:hover{
    background:#1742b0;
}


/* =========================
   MOBILE
========================= */

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

    .menu i{
        font-size:17px;
    }

    .main{
        margin-left:0;
        padding:12px;
    }

    .top-title{
        display:block;
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
        font-size:13px;
    }

    .table th,
    .table td{
        white-space:nowrap;
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

<a
href="produits.php"
class="active"
>

<i class="bi bi-box"></i>
Achats / Stock

</a>

<a href="ventes.php">

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
     CONTENU
====================================================== -->

<main class="main">


<div class="top-title">

<div>

<h1>
📦 Achats & Stock
</h1>

<p>
Gérez simplement vos articles, achats et stocks.
</p>

</div>

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
     STATISTIQUES
====================================================== -->

<div class="stats">


<div class="stat">

<div class="stat-icon">
<i class="bi bi-box"></i>
</div>

<div class="stat-label">
Articles
</div>

<div class="stat-value">
<?= $total_articles ?>
</div>

</div>


<div class="stat">

<div class="stat-icon">
<i class="bi bi-boxes"></i>
</div>

<div class="stat-label">
Stock total
</div>

<div class="stat-value">
<?= $total_stock ?>
</div>

</div>


<div class="stat">

<div class="stat-icon">
<i class="bi bi-cash-stack"></i>
</div>

<div class="stat-label">
Total achats
</div>

<div class="stat-value">
<?= argent($total_achat) ?>
</div>

</div>

</div>


<!-- ======================================================
     MODIFIER ARTICLE
====================================================== -->

<?php if($article_edit): ?>

<div class="box">

<div class="box-title">

<i class="bi bi-pencil-square"></i>

Modifier l'article

</div>


<form method="POST">

<input
type="hidden"
name="modifier_article"
value="1"
>

<input
type="hidden"
name="id"
value="<?= (int)$article_edit["id"] ?>"
>


<div class="row g-3">


<div class="col-md-5">

<label>
Désignation
</label>

<input
type="text"
name="nom"
class="form-control"
value="<?= htmlspecialchars($article_edit["nom"]) ?>"
required
>

</div>


<div class="col-md-3">

<label>
Catégorie
</label>

<input
type="text"
name="categorie"
class="form-control"
value="<?= htmlspecialchars($article_edit["categorie"] ?? "") ?>"
>

</div>


<div class="col-md-2">

<label>
Prix d'achat
</label>

<input
type="number"
name="prix_achat"
class="form-control"
min="0"
value="<?= (float)$article_edit["prix_achat"] ?>"
required
>

</div>


<div class="col-md-2">

<label>
Stock actuel
</label>

<input
type="text"
class="form-control"
value="<?= (int)$article_edit["stock"] ?>"
readonly
>

</div>

</div>


<div class="mt-3">

<button class="btn btn-primary">

<i class="bi bi-check-lg"></i>

Enregistrer

</button>


<a
href="produits.php"
class="btn btn-light"
>

Annuler

</a>

</div>

</form>

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

<input
type="hidden"
name="ajouter_produit"
value="1"
>


<div class="row g-3">


<div class="col-md-4">

<label>
Désignation
</label>

<input
name="nom"
class="form-control"
placeholder="Ex : T-shirt grand"
required
>

</div>


<div class="col-md-3">

<label>
Catégorie
</label>

<input
name="categorie"
class="form-control"
placeholder="Ex : Vêtement"
>

</div>


<div class="col-md-2">

<label>
Prix d'achat
</label>

<input
type="number"
name="prix_achat"
class="form-control"
min="0"
required
>

</div>


<div class="col-md-2">

<label>
Stock initial
</label>

<input
type="number"
name="stock_initial"
class="form-control"
min="0"
value="0"
>

</div>


<div class="col-md-6">

<label>
Fournisseur
</label>

<input
name="fournisseur"
class="form-control"
placeholder="Nom du fournisseur"
>

</div>

</div>


<button class="btn btn-primary mt-3">

<i class="bi bi-save"></i>

Ajouter l'article

</button>

</form>

</div>


<!-- ======================================================
     ACHAT
====================================================== -->

<div class="box">

<div class="box-title">

🛒 Enregistrer un achat

</div>


<form method="POST" id="formAchat">

<input
type="hidden"
name="enregistrer_achat"
value="1"
>


<div class="mb-3">

<label>
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

<label>
Prix achat
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

TOTAL ACHAT :

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


<div class="search-box">

<i class="bi bi-search"></i>

<input
type="text"
id="recherche"
class="form-control"
placeholder="Rechercher un article..."
oninput="rechercherArticle()"
>

</div>


<div id="listeArticles">


<?php

$stock =
$conn->query(
"SELECT id, nom, categorie, prix_achat, stock
 FROM produits
 ORDER BY nom"
);

while($p = $stock->fetch_assoc()):

$stock_qte =
(int)$p["stock"];

?>


<div
class="article-card"
data-nom="<?= htmlspecialchars(
strtolower($p["nom"])
) ?>"
>


<div class="row align-items-center">


<div class="col-md-4">

<div class="article-name">

<?= htmlspecialchars($p["nom"]) ?>

</div>

<div class="article-category">

<?= htmlspecialchars(
$p["categorie"] ?? ""
) ?>

</div>

</div>


<div class="col-md-2 mt-2 mt-md-0">

<small class="text-muted">
Prix achat
</small>

<div>
<strong>
<?= argent($p["prix_achat"]) ?>
</strong>
</div>

</div>


<div class="col-md-2 mt-2 mt-md-0">

<?php if($stock_qte <= 0): ?>

<span class="stock-zero">
Rupture
</span>

<?php elseif($stock_qte <= 5): ?>

<span class="stock-low">
Faible : <?= $stock_qte ?>
</span>

<?php else: ?>

<span class="stock-ok">
Stock : <?= $stock_qte ?>
</span>

<?php endif; ?>

</div>


<div class="col-md-4 text-md-end mt-2 mt-md-0">

<a
href="produits.php?modifier=<?= (int)$p["id"] ?>"
class="btn btn-sm btn-outline-primary"
>

<i class="bi bi-pencil"></i>

Modifier

</a>

</div>


</div>

</div>


<?php endwhile; ?>

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

<table class="table align-middle">

<thead>

<tr>

<th>Article</th>

<th>Fournisseur</th>

<th>Prix</th>

<th>Qté</th>

<th>Montant</th>

<th>Paiement</th>

<th>Date</th>

<th>Action</th>

</tr>

</thead>


<tbody>


<?php if(
$mouvements &&
$mouvements->num_rows
): ?>


<?php while(
$m = $mouvements->fetch_assoc()
): ?>


<?php

$description =
$m["description"] ?? "";

$fournisseur =
"Non renseigné";

$parts =
explode(" | ", $description);

foreach($parts as $part){

    if(
        strpos(
            $part,
            "Fournisseur : "
        ) === 0
    ){

        $fournisseur =
            str_replace(
                "Fournisseur : ",
                "",
                $part
            );
    }
}


/* Paiement */
$paye_ligne = 0;

preg_match(
    '/Payé\s*:\s*([0-9\s,\.]+)\s*FG/i',
    $description,
    $matchPaye
);

if(isset($matchPaye[1])){

    $paye_ligne =
        (float)str_replace(
            [" ", ","],
            ["", "."],
            $matchPaye[1]
        );
}


/* État */
$annule =
    (
        strpos($description,"ANNULÉ") !== false ||
        strpos($description,"ANNULE") !== false
    );

?>


<tr>


<td>

<strong>
<?= htmlspecialchars(
$m["produit_nom"] ?? "Article"
) ?>
</strong>

<?php if($annule): ?>

<br>

<span class="badge text-bg-secondary">
Annulé
</span>

<?php endif; ?>

</td>


<td>

<?= htmlspecialchars(
$fournisseur
) ?>

</td>


<td>

<?= argent($m["prix"]) ?>

</td>


<td>

<?= (int)$m["quantite"] ?>

</td>


<td>

<strong>

<?= argent(
$m["prix"] * $m["quantite"]
) ?>

</strong>

</td>


<td>

<?php if($annule): ?>

<span class="badge text-bg-secondary">
Annulé
</span>

<?php elseif($paye_ligne > 0): ?>

<span class="badge text-bg-warning">
Avance / Payé
</span>

<?php else: ?>

<span class="badge text-bg-success">
Non payé
</span>

<?php endif; ?>

</td>


<td>

<?= !empty($m["date_mouvement"])
? date(
    "d/m/Y H:i",
    strtotime($m["date_mouvement"])
)
: "-"
?>

</td>


<td>

<?php if(!$annule): ?>


<?php if($paye_ligne <= 0): ?>


<form
method="POST"
style="display:inline"
onsubmit="return confirmerSuppression()"
>

<input
type="hidden"
name="supprimer_achat"
value="1"
>

<input
type="hidden"
name="mouvement_id"
value="<?= (int)$m["id"] ?>"
>

<button
class="btn btn-sm btn-outline-danger"
title="Supprimer"
>

<i class="bi bi-trash"></i>

</button>

</form>


<?php else: ?>


<form
method="POST"
style="display:inline"
onsubmit="return confirmerAnnulation()"
>

<input
type="hidden"
name="annuler_achat"
value="1"
>

<input
type="hidden"
name="mouvement_id"
value="<?= (int)$m["id"] ?>"
>

<button
class="btn btn-sm btn-outline-warning"
title="Annuler l'achat"
>

<i class="bi bi-x-circle"></i>

</button>

</form>


<?php endif; ?>


<?php else: ?>

<span class="text-muted">
—
</span>

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


/* =========================================================
   ARGENT
========================================================= */

function argent(n){

    return new Intl.NumberFormat("fr-FR")
    .format(n) + " FG";

}


/* =========================================================
   CALCUL ACHAT
========================================================= */

function calculer(){

    let total = 0;

    document.querySelectorAll(".ligne")
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


    document.getElementById("total")
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


    document.getElementById("reste")
    .textContent =
        argent(reste);

}


/* =========================================================
   AJOUTER LIGNE
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
   RECHERCHE ARTICLE
========================================================= */

function rechercherArticle(){

    let recherche =
        document
        .getElementById("recherche")
        .value
        .toLowerCase()
        .trim();


    document
        .querySelectorAll(".article-card")
        .forEach(function(article){

            let nom =
                article.dataset.nom || "";


            if(
                nom.includes(recherche)
            ){

                article.style.display =
                    "";

            } else {

                article.style.display =
                    "none";

            }

        });

}


/* =========================================================
   CONFIRMATIONS
========================================================= */

function confirmerSuppression(){

    return confirm(
        "Supprimer cet achat ?\n\n" +
        "Le stock sera automatiquement corrigé."
    );

}


function confirmerAnnulation(){

    return confirm(
        "Annuler cet achat ?\n\n" +
        "L'achat sera conservé dans l'historique " +
        "mais le stock sera corrigé."
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
