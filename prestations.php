```php
<?php
session_start();
require_once "config.php";

/* =========================================================
   PROTECTION
========================================================= */

if (!isset($_SESSION["id"])) {
    header("Location: index.php");
    exit;
}

$nom  = $_SESSION["nom"] ?? "Utilisateur";
$role = $_SESSION["role"] ?? "lecture";

$message = "";
$type = "";

/* =========================================================
   FORMAT ARGENT
========================================================= */

function argent($montant)
{
    return number_format(
        (float)$montant,
        0,
        ",",
        " "
    ) . " FG";
}

/* =========================================================
   ENREGISTREMENT PRESTATION
   AUCUNE NOUVELLE TABLE
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajouter_prestation"])) {

    $client = trim($_POST["client"] ?? "");
    $description = trim($_POST["description"] ?? "");

    $articles = $_POST["article"] ?? [];
    $quantites = $_POST["quantite"] ?? [];
    $prix_articles = $_POST["prix_article"] ?? [];
    $types_dtf = $_POST["type_dtf"] ?? [];
    $prix_dtf = $_POST["prix_dtf"] ?? [];

    if ($client === "") {
        $client = "Client non renseigné";
    }

    if (empty($articles)) {

        $message = "Ajoute au moins un article.";
        $type = "error";

    } else {

        $total_articles = 0;
        $total_dtf_facture = 0;
        $total_dtf_a4 = 0;
        $details = [];

        $valide = true;

        /*
        -----------------------------------------------------
        TRAITEMENT DES ARTICLES
        -----------------------------------------------------
        */

        foreach ($articles as $i => $article) {

            $article = trim($article);

            $qte = (int)($quantites[$i] ?? 0);
            $prix_article = (float)($prix_articles[$i] ?? 0);

            if ($article === "" || $qte <= 0) {
                $valide = false;
                break;
            }

            /*
            Prix des articles :
            T-shirt, Lacoste, Chemise, Képi, Pull...
            */

            $montant_article = $qte * $prix_article;

            $total_articles += $montant_article;

            /*
            -------------------------------------------------
            CALCUL DTF
            -------------------------------------------------
            
            ADULTE :
            1 T-shirt = 1 DTF A4

            ENFANT :
            2 T-shirts = 1 DTF A4

            -------------------------------------------------
            */

            $type_dtf = strtolower(
                trim($types_dtf[$i] ?? "aucun")
            );

            $prix_dtf_unitaire = (float)(
                $prix_dtf[$i] ?? 0
            );

            $nombre_dtf = 0;

            if ($type_dtf === "adulte") {

                $nombre_dtf = $qte;

            } elseif ($type_dtf === "enfant") {

                $nombre_dtf = (int)ceil($qte / 2);

            }

            /*
            Prix DTF facturé au client.
            */

            $montant_dtf = $nombre_dtf * $prix_dtf_unitaire;

            $total_dtf_facture += $montant_dtf;
            $total_dtf_a4 += $nombre_dtf;

            /*
            Détails pour la recette
            */

            $ligne =
                $article .
                " × " .
                $qte .
                " = " .
                argent($montant_article);

            if ($nombre_dtf > 0) {

                $ligne .=
                    " | DTF " .
                    ucfirst($type_dtf) .
                    " : " .
                    $nombre_dtf .
                    " A4 × " .
                    argent($prix_dtf_unitaire) .
                    " = " .
                    argent($montant_dtf);
            }

            $details[] = $ligne;
        }

        if (!$valide) {

            $message = "Vérifie les articles et les quantités.";
            $type = "error";

        } else {

            /*
            -------------------------------------------------
            TOTAL FACTURÉ
            -------------------------------------------------
            */

            $total_facture =
                $total_articles +
                $total_dtf_facture;

            if ($total_facture <= 0) {

                $message =
                    "Le montant total doit être supérieur à zéro.";

                $type = "error";

            } else {

                /*
                -------------------------------------------------
                DESCRIPTION FINALE
                -------------------------------------------------
                */

                $description_finale =
                    "Client : " .
                    $client .
                    " | " .
                    implode(" || ", $details);

                if ($description !== "") {

                    $description_finale .=
                        " | Note : " .
                        $description;
                }

                /*
                -------------------------------------------------
                ENREGISTRER COMME RECETTE
                -------------------------------------------------
                */

                $libelle =
                    "Prestation - " .
                    $client;

                $stmt = $conn->prepare(
                    "INSERT INTO recettes
                    (libelle, montant, description)
                    VALUES (?, ?, ?)"
                );

                if ($stmt) {

                    $stmt->bind_param(
                        "sds",
                        $libelle,
                        $total_facture,
                        $description_finale
                    );

                    if ($stmt->execute()) {

                        /*
                        -------------------------------------------------
                        DÉDUCTION DU STOCK DTF
                        -------------------------------------------------

                        On cherche le produit DTF dans produits.

                        Le nom peut contenir :
                        DTF
                        DTF A4
                        DTF A4 5000
                        -------------------------------------------------
                        */

                        if ($total_dtf_a4 > 0) {

                            $stmt_dtf = $conn->prepare(
                                "SELECT id, stock
                                 FROM produits
                                 WHERE nom LIKE '%DTF%'
                                 ORDER BY id ASC
                                 LIMIT 1"
                            );

                            if ($stmt_dtf) {

                                $stmt_dtf->execute();

                                $result_dtf =
                                    $stmt_dtf->get_result();

                                $dtf =
                                    $result_dtf->fetch_assoc();

                                $stmt_dtf->close();

                                if ($dtf) {

                                    /*
                                    Stock DTF A4 diminué
                                    */

                                    $stmt_stock =
                                        $conn->prepare(
                                            "UPDATE produits
                                             SET stock =
                                             CASE
                                                 WHEN stock >= ?
                                                 THEN stock - ?
                                                 ELSE 0
                                             END
                                             WHERE id = ?"
                                        );

                                    if ($stmt_stock) {

                                        $stmt_stock->bind_param(
                                            "iii",
                                            $total_dtf_a4,
                                            $total_dtf_a4,
                                            $dtf["id"]
                                        );

                                        $stmt_stock->execute();

                                        $stmt_stock->close();
                                    }
                                }
                            }
                        }

                        /*
                        -------------------------------------------------
                        MESSAGE
                        -------------------------------------------------
                        */

                        $message =
                            "Prestation enregistrée avec succès. " .
                            "Total : " .
                            argent($total_facture) .
                            " | DTF utilisés : " .
                            $total_dtf_a4 .
                            " A4.";

                        $type = "success";

                    } else {

                        $message =
                            "Erreur lors de l'enregistrement.";

                        $type = "error";
                    }

                    $stmt->close();

                } else {

                    $message =
                        "Impossible de préparer l'enregistrement.";

                    $type = "error";
                }
            }
        }
    }
}

/* =========================================================
   STATISTIQUES
========================================================= */

$total_prestations = 0;
$nombre_prestations = 0;

$result = $conn->query(
    "SELECT
        COALESCE(SUM(montant),0) AS total,
        COUNT(*) AS nombre
     FROM recettes
     WHERE libelle LIKE 'Prestation - %'"
);

if ($result) {

    $data = $result->fetch_assoc();

    $total_prestations =
        (float)$data["total"];

    $nombre_prestations =
        (int)$data["nombre"];
}

/* =========================================================
   STOCK DTF
========================================================= */

$stock_dtf = 0;

$result_dtf_stock = $conn->query(
    "SELECT stock
     FROM produits
     WHERE nom LIKE '%DTF%'
     ORDER BY id ASC
     LIMIT 1"
);

if ($result_dtf_stock) {

    $dtf_stock_data =
        $result_dtf_stock->fetch_assoc();

    if ($dtf_stock_data) {

        $stock_dtf =
            (int)$dtf_stock_data["stock"];
    }
}

/* =========================================================
   HISTORIQUE
========================================================= */

$prestations = $conn->query(
    "SELECT
        id,
        libelle,
        montant,
        description,
        date_recette
     FROM recettes
     WHERE libelle LIKE 'Prestation - %'
     ORDER BY id DESC
     LIMIT 50"
);

?>

<!DOCTYPE html>

<html lang="fr">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Prestations - LAMBEMAH GESTION</title>

<style>

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, sans-serif;
    background: #f3f7fb;
    color: #172536;
}

/* =========================================================
   SIDEBAR
========================================================= */

.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    bottom: 0;
    width: 250px;

    background:
        linear-gradient(
            180deg,
            #061a2d,
            #092d4b,
            #07527c
        );

    color: white;
    padding: 24px 15px;

    z-index: 1000;
}

.brand {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 5px 10px 25px;
}

.brand-icon {
    width: 45px;
    height: 45px;
    border-radius: 14px;

    display: flex;
    align-items: center;
    justify-content: center;

    background:
        linear-gradient(
            135deg,
            #20b8ff,
            #1264c7
        );

    font-size: 22px;
}

.brand h2 {
    font-size: 18px;
}

.brand span {
    font-size: 9px;
    color: #86a9c0;
}

.nav {
    list-style: none;
}

.nav li {
    margin: 4px 0;
}

.nav a {
    display: flex;
    align-items: center;
    gap: 12px;

    padding: 12px 14px;

    border-radius: 12px;

    color: #c5d5e1;
    text-decoration: none;

    font-size: 13px;
}

.nav a:hover,
.nav a.active {
    background: rgba(32,184,255,.16);
    color: white;
}

.nav a.active {
    border-left: 3px solid #20b8ff;
}

.sidebar-bottom {
    position: absolute;
    left: 15px;
    right: 15px;
    bottom: 20px;
}

.profile {
    padding: 12px;
    border-radius: 12px;

    background: rgba(255,255,255,.06);

    margin-bottom: 10px;
}

.profile strong {
    display: block;
    font-size: 12px;
}

.profile span {
    color: #8ca8bb;
    font-size: 10px;
}

.logout {
    display: block;
    text-align: center;

    text-decoration: none;

    color: #ffbaba;

    background: rgba(255,70,70,.08);

    padding: 10px;

    border-radius: 10px;

    font-size: 11px;
}

/* =========================================================
   MAIN
========================================================= */

.main {
    margin-left: 250px;
    padding: 28px;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;

    margin-bottom: 22px;
}

.header h1 {
    font-size: 25px;
}

.header p {
    color: #8998a5;
    font-size: 12px;
    margin-top: 5px;
}

.avatar {
    width: 42px;
    height: 42px;

    border-radius: 13px;

    display: flex;
    align-items: center;
    justify-content: center;

    background:
        linear-gradient(
            135deg,
            #20b8ff,
            #1264c7
        );

    color: white;
    font-weight: bold;
}

/* =========================================================
   STATS
========================================================= */

.cards {
    display: grid;
    grid-template-columns: repeat(3, 1fr);

    gap: 15px;

    margin-bottom: 20px;
}

.stat {
    background: white;
    border-radius: 17px;
    padding: 18px;

    box-shadow:
        0 6px 25px rgba(25,55,80,.06);
}

.stat small {
    color: #8c9aa6;
    font-size: 10px;
}

.stat h2 {
    margin-top: 8px;
    font-size: 21px;
    color: #0c79b5;
}

.stat.green h2 {
    color: #159765;
}

/* =========================================================
   CONTENU
========================================================= */

.content {
    display: grid;

    grid-template-columns:
        430px 1fr;

    gap: 20px;
}

.card {
    background: white;
    border-radius: 20px;

    padding: 22px;

    box-shadow:
        0 6px 25px rgba(25,55,80,.06);
}

.card h2 {
    font-size: 17px;
    margin-bottom: 6px;
}

.card > p {
    color: #8b99a5;
    font-size: 11px;
    margin-bottom: 18px;
}

/* =========================================================
   MESSAGE
========================================================= */

.message {
    padding: 12px;
    border-radius: 10px;

    margin-bottom: 15px;

    font-size: 11px;
}

.success {
    background: #eafaf2;
    color: #168653;
}

.error {
    background: #fff0f0;
    color: #d33;
}

/* =========================================================
   INFO DTF
========================================================= */

.dtf-info {
    background: #eef9ff;
    border: 1px solid #d6effb;

    color: #1679ad;

    padding: 12px;

    border-radius: 11px;

    font-size: 10px;

    margin-bottom: 16px;
}

.dtf-info strong {
    color: #086b9d;
}

/* =========================================================
   ARTICLES
========================================================= */

.article-box {
    border: 1px solid #e2e9ee;

    border-radius: 13px;

    padding: 13px;

    margin-bottom: 12px;

    background: #fbfdff;
}

.article-title {
    display: flex;

    justify-content: space-between;

    align-items: center;

    margin-bottom: 10px;
}

.article-title strong {
    font-size: 11px;
}

.remove-btn {
    width: auto;

    padding: 5px 8px;

    background: #fff0f0;

    color: #d33;

    font-size: 10px;

    border-radius: 7px;
}

.row {
    display: grid;

    grid-template-columns:
        1.3fr .7fr .9fr;

    gap: 7px;

    margin-bottom: 9px;
}

.row-2 {
    display: grid;

    grid-template-columns:
        1fr 1fr;

    gap: 7px;
}

.group {
    margin-bottom: 13px;
}

label {
    display: block;

    font-size: 9px;

    font-weight: bold;

    color: #536675;

    margin-bottom: 5px;
}

input,
select,
textarea {
    width: 100%;

    padding: 10px;

    border:
        1px solid #dfe8ee;

    border-radius: 9px;

    background: #fbfdff;

    outline: none;

    font-family: Arial, sans-serif;

    font-size: 11px;
}

input:focus,
select:focus,
textarea:focus {
    border-color: #20b8ff;
}

textarea {
    min-height: 65px;
    resize: vertical;
}

.add-btn {
    width: 100%;

    margin-bottom: 13px;

    border: 1px dashed #20b8ff;

    background: #eef9ff;

    color: #0878b7;

    padding: 10px;

    border-radius: 10px;

    cursor: pointer;

    font-weight: bold;
}

.total-box {
    background:
        linear-gradient(
            135deg,
            #eef9ff,
            #f7fcff
        );

    border-radius: 12px;

    padding: 14px;

    margin: 15px 0;

    text-align: center;
}

.total-box small {
    display: block;

    color: #7b909f;

    font-size: 9px;

    margin-bottom: 5px;
}

.total-box strong {
    color: #0878b7;

    font-size: 21px;
}

/* =========================================================
   BOUTON
========================================================= */

.btn-primary {
    width: 100%;

    border: 0;

    border-radius: 11px;

    padding: 13px;

    cursor: pointer;

    color: white;

    background:
        linear-gradient(
            135deg,
            #20b8ff,
            #1264c7
        );

    font-weight: bold;

    font-size: 12px;
}

/* =========================================================
   HISTORIQUE
========================================================= */

.sale {
    padding: 14px 0;

    border-bottom:
        1px solid #edf1f4;
}

.sale:first-child {
    padding-top: 0;
}

.sale-top {
    display: flex;

    justify-content: space-between;

    gap: 10px;
}

.sale-product {
    font-weight: bold;

    font-size: 12px;

    color: #20394c;
}

.sale-amount {
    color: #0878b7;

    font-weight: bold;

    white-space: nowrap;

    font-size: 12px;
}

.sale-description {
    margin-top: 8px;

    padding: 9px;

    background: #f8fafc;

    border-radius: 8px;

    color: #637581;

    font-size: 9px;

    line-height: 1.6;
}

.sale-date {
    margin-top: 7px;

    color: #9aa6ae;

    font-size: 9px;
}

/* =========================================================
   MOBILE
========================================================= */

@media(max-width:900px) {

    .content {
        grid-template-columns: 1fr;
    }

    .cards {
        grid-template-columns:
            repeat(3, 1fr);
    }
}

@media(max-width:700px) {

    .sidebar {
        position: relative;

        width: 100%;
        height: auto;

        padding: 10px;
    }

    .brand {
        padding: 4px 7px 10px;
    }

    .brand-icon {
        width: 39px;
        height: 39px;
    }

    .brand h2 {
        font-size: 15px;
    }

    .nav {
        display: grid;

        grid-template-columns:
            repeat(4, 1fr);

        gap: 4px;
    }

    .nav a {
        flex-direction: column;

        gap: 4px;

        padding: 8px 3px;

        text-align: center;

        font-size: 8px;
    }

    .nav a.active {
        border-left: 0;

        border-bottom:
            2px solid #20b8ff;
    }

    .sidebar-bottom {
        position: static;

        margin-top: 8px;
    }

    .profile {
        display: none;
    }

    .main {
        margin-left: 0;

        padding: 14px;
    }

    .header h1 {
        font-size: 20px;
    }

    .header p {
        font-size: 10px;
    }

    .cards {
        grid-template-columns:
            1fr 1fr;

        gap: 9px;
    }

    .stat {
        padding: 14px;
    }

    .stat:last-child {
        grid-column: 1 / -1;
    }

    .content {
        gap: 13px;
    }

    .card {
        padding: 15px;

        border-radius: 16px;
    }

    .row {
        grid-template-columns: 1fr 1fr;
    }

    .row > div:first-child {
        grid-column: 1 / -1;
    }
}

@media(max-width:390px) {

    .nav a {
        font-size: 7px;
    }

    .stat h2 {
        font-size: 16px;
    }
}

</style>

</head>

<body>

<!-- =========================================================
     MENU
========================================================= -->

<aside class="sidebar">

    <div class="brand">

        <div class="brand-icon">
            💼
        </div>

        <div>

            <h2>LAMBEMAH</h2>

            <span>
                GESTION • PRESTATION
            </span>

        </div>

    </div>

    <ul class="nav">

        <li>
            <a href="index.php">
                🏠 <span>Accueil</span>
            </a>
        </li>

        <li>
            <a href="produits.php">
                📦 <span>Produits</span>
            </a>
        </li>

        <li>
            <a href="ventes.php">
                💰 <span>Ventes</span>
            </a>
        </li>

        <li>
            <a href="prestations.php" class="active">
                🖨️ <span>Prestations</span>
            </a>
        </li>

        <li>
            <a href="recettes.php">
                💵 <span>Recettes</span>
            </a>
        </li>

        <li>
            <a href="depenses.php">
                💸 <span>Dépenses</span>
            </a>
        </li>

        <li>
            <a href="statistiques.php">
                📊 <span>Statistiques</span>
            </a>
        </li>

        <?php if ($role === "admin"): ?>

        <li>
            <a href="utilisateurs.php">
                👥 <span>Équipe</span>
            </a>
        </li>

        <?php endif; ?>

    </ul>

    <div class="sidebar-bottom">

        <div class="profile">

            <strong>
                <?= htmlspecialchars($nom) ?>
            </strong>

            <span>
                <?= htmlspecialchars($role) ?>
            </span>

        </div>

        <a
            class="logout"
            href="index.php?logout=1"
        >
            🚪 Déconnexion
        </a>

    </div>

</aside>

<!-- =========================================================
     MAIN
========================================================= -->

<main class="main">

    <div class="header">

        <div>

            <h1>
                🖨️ Prestations
            </h1>

            <p>
                Articles du client + impression DTF.
            </p>

        </div>

        <div class="avatar">

            <?= strtoupper(
                substr($nom, 0, 1)
            ) ?>

        </div>

    </div>

    <?php if ($message !== ""): ?>

        <div class="message <?= htmlspecialchars($type) ?>">

            <?= $message ?>

        </div>

    <?php endif; ?>

    <!-- =====================================================
         STATS
    ====================================================== -->

    <div class="cards">

        <div class="stat">

            <small>
                CA PRESTATIONS
            </small>

            <h2>
                <?= argent($total_prestations) ?>
            </h2>

        </div>

        <div class="stat">

            <small>
                PRESTATIONS
            </small>

            <h2>
                <?= $nombre_prestations ?>
            </h2>

        </div>

        <div class="stat green">

            <small>
                STOCK DTF A4
            </small>

            <h2>
                <?= $stock_dtf ?>
            </h2>

        </div>

    </div>

    <div class="content">

        <!-- =================================================
             FORMULAIRE
        ================================================== -->

        <div class="card">

            <h2>
                ➕ Nouvelle prestation
            </h2>

            <p>
                Le client peut apporter plusieurs articles.
            </p>

            <div class="dtf-info">

                🖨️ <strong>Règle DTF :</strong>

                <br><br>

                👕 Adulte :
                <strong>1 T-shirt = 1 A4</strong>

                <br>

                👶 Enfant :
                <strong>2 T-shirts = 1 A4</strong>

                <br><br>

                Le prix facturé au client est libre :
                5 000, 10 000, 15 000 FG...

            </div>

            <form method="POST">

                <input
                    type="hidden"
                    name="ajouter_prestation"
                    value="1"
                >

                <div class="group">

                    <label>
                        CLIENT
                    </label>

                    <input
                        type="text"
                        name="client"
                        placeholder="Ex : Fanta"
                        required
                    >

                </div>

                <div id="articles">

                    <div class="article-box">

                        <div class="article-title">

                            <strong>
                                ARTICLE 1
                            </strong>

                        </div>

                        <div class="row">

                            <div>

                                <label>
                                    ARTICLE
                                </label>

                                <input
                                    type="text"
                                    name="article[]"
                                    placeholder="T-shirt, Lacoste..."
                                    required
                                >

                            </div>

                            <div>

                                <label>
                                    QUANTITÉ
                                </label>

                                <input
                                    type="number"
                                    name="quantite[]"
                                    value="1"
                                    min="1"
                                    required
                                >

                            </div>

                            <div>

                                <label>
                                    PRIX ARTICLE
                                </label>

                                <input
                                    type="number"
                                    name="prix_article[]"
                                    value="0"
                                    min="0"
                                    step="500"
                                    required
                                >

                            </div>

                        </div>

                        <div class="row-2">

                            <div>

                                <label>
                                    DTF
                                </label>

                                <select
                                    name="type_dtf[]"
                                >

                                    <option value="aucun">
                                        Aucun
                                    </option>

                                    <option value="adulte">
                                        Adulte — 1 T-shirt = 1 A4
                                    </option>

                                    <option value="enfant">
                                        Enfant — 2 T-shirts = 1 A4
                                    </option>

                                </select>

                            </div>

                            <div>

                                <label>
                                    PRIX DTF / A4
                                </label>

                                <input
                                    type="number"
                                    name="prix_dtf[]"
                                    value="0"
                                    min="0"
                                    step="500"
                                    placeholder="Ex : 10000"
                                >

                            </div>

                        </div>

                    </div>

                </div>

                <button
                    type="button"
                    class="add-btn"
                    onclick="ajouterArticle()"
                >
                    ➕ Ajouter un autre article
                </button>

                <div class="group">

                    <label>
                        NOTE / DÉTAIL
                    </label>

                    <textarea
                        name="description"
                        placeholder="Ex : Fanta apporte ses propres T-shirts..."
                    ></textarea>

                </div>

                <div class="total-box">

                    <small>
                        LE TOTAL SERA CALCULÉ APRÈS ENREGISTREMENT
                    </small>

                    <strong>
                        Articles + DTF
                    </strong>

                </div>

                <button
                    type="submit"
                    class="btn-primary"
                >
                    🖨️ ENREGISTRER LA PRESTATION
                </button>

            </form>

        </div>

        <!-- =================================================
             HISTORIQUE
        ================================================== -->

        <div class="card">

            <h2>
                📋 Prestations récentes
            </h2>

            <p>
                Les 50 dernières prestations.
            </p>

            <?php if (
                $prestations &&
                $prestations->num_rows > 0
            ): ?>

                <?php while (
                    $p =
                    $prestations->fetch_assoc()
                ): ?>

                    <div class="sale">

                        <div class="sale-top">

                            <div class="sale-product">

                                🖨️
                                <?= htmlspecialchars(
                                    $p["libelle"]
                                ) ?>

                            </div>

                            <div class="sale-amount">

                                <?= argent(
                                    $p["montant"]
                                ) ?>

                            </div>

                        </div>

                        <div class="sale-description">

                            <?= nl2br(
                                htmlspecialchars(
                                    $p["description"]
                                )
                            ) ?>

                        </div>

                        <div class="sale-date">

                            📅
                            <?= date(
                                "d/m/Y H:i",
                                strtotime(
                                    $p["date_recette"]
                                )
                            ) ?>

                        </div>

                    </div>

                <?php endwhile; ?>

            <?php else: ?>

                <div
                    style="
                    text-align:center;
                    padding:35px 10px;
                    color:#8998a5;
                    font-size:12px;
                    "
                >

                    🖨️

                    <br><br>

                    Aucune prestation enregistrée.

                </div>

            <?php endif; ?>

        </div>

    </div>

</main>

<script>

/* =========================================================
   AJOUTER UN ARTICLE
========================================================= */

let numeroArticle = 1;

function ajouterArticle() {

    numeroArticle++;

    const container =
        document.getElementById("articles");

    const div =
        document.createElement("div");

    div.className = "article-box";

    div.innerHTML = `

        <div class="article-title">

            <strong>
                ARTICLE ${numeroArticle}
            </strong>

            <button
                type="button"
                class="remove-btn"
                onclick="this.closest('.article-box').remove()"
            >
                ✕
            </button>

        </div>

        <div class="row">

            <div>

                <label>
                    ARTICLE
                </label>

                <input
                    type="text"
                    name="article[]"
                    placeholder="T-shirt, Lacoste..."
                    required
                >

            </div>

            <div>

                <label>
                    QUANTITÉ
                </label>

                <input
                    type="number"
                    name="quantite[]"
                    value="1"
                    min="1"
                    required
                >

            </div>

            <div>

                <label>
                    PRIX ARTICLE
                </label>

                <input
                    type="number"
                    name="prix_article[]"
                    value="0"
                    min="0"
                    step="500"
                    required
                >

            </div>

        </div>

        <div class="row-2">

            <div>

                <label>
                    DTF
                </label>

                <select name="type_dtf[]">

                    <option value="aucun">
                        Aucun
                    </option>

                    <option value="adulte">
                        Adulte — 1 T-shirt = 1 A4
                    </option>

                    <option value="enfant">
                        Enfant — 2 T-shirts = 1 A4
                    </option>

                </select>

            </div>

            <div>

                <label>
                    PRIX DTF / A4
                </label>

                <input
                    type="number"
                    name="prix_dtf[]"
                    value="0"
                    min="0"
                    step="500"
                    placeholder="Ex : 10000"
                >

            </div>

        </div>
    `;

    container.appendChild(div);
}

</script>

</body>

</html>
```
