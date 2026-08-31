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
   ARGENT
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
   ENREGISTRER UNE VENTE
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajouter_vente"])) {

    $articles = $_POST["article"] ?? [];
    $quantites = $_POST["quantite"] ?? [];
    $prix = $_POST["prix"] ?? [];
    $description = trim($_POST["description"] ?? "");

    if (empty($articles)) {

        $message = "Ajoute au moins un produit.";
        $type = "error";

    } else {

        $valide = true;
        $total = 0;
        $details = [];

        /*
        -----------------------------------------------------
        Vérification des produits
        -----------------------------------------------------
        */

        foreach ($articles as $i => $produit_id) {

            $produit_id = (int)$produit_id;
            $qte = (int)($quantites[$i] ?? 0);
            $prix_unitaire = (float)($prix[$i] ?? 0);

            if (
                $produit_id <= 0 ||
                $qte <= 0 ||
                $prix_unitaire <= 0
            ) {
                $valide = false;
                break;
            }

            $stmt = $conn->prepare(
                "SELECT id, nom, stock
                 FROM produits
                 WHERE id = ?
                 LIMIT 1"
            );

            $stmt->bind_param(
                "i",
                $produit_id
            );

            $stmt->execute();

            $result = $stmt->get_result();

            $produit = $result->fetch_assoc();

            $stmt->close();

            if (!$produit) {

                $valide = false;
                $message = "Produit introuvable.";
                $type = "error";
                break;
            }

            if ((int)$produit["stock"] < $qte) {

                $valide = false;

                $message =
                    "Stock insuffisant pour " .
                    $produit["nom"] .
                    ". Stock disponible : " .
                    $produit["stock"];

                $type = "error";

                break;
            }

            $montant_ligne =
                $qte * $prix_unitaire;

            $total += $montant_ligne;

            $details[] =
                $produit["nom"] .
                " × " .
                $qte .
                " = " .
                argent($montant_ligne);
        }

        if ($valide) {

            $conn->begin_transaction();

            try {

                /*
                -------------------------------------------------
                ENREGISTRER CHAQUE ARTICLE DANS ventes
                -------------------------------------------------
                */

                foreach ($articles as $i => $produit_id) {

                    $produit_id = (int)$produit_id;
                    $qte = (int)$quantites[$i];
                    $prix_unitaire = (float)$prix[$i];

                    $montant =
                        $qte * $prix_unitaire;

                    /*
                    Vente
                    */

                    $stmt = $conn->prepare(
                        "INSERT INTO ventes
                        (
                            produit_id,
                            quantite,
                            prix_unitaire,
                            montant,
                            description
                        )
                        VALUES (?, ?, ?, ?, ?)"
                    );

                    $stmt->bind_param(
                        "iidds",
                        $produit_id,
                        $qte,
                        $prix_unitaire,
                        $montant,
                        $description
                    );

                    if (!$stmt->execute()) {

                        throw new Exception(
                            "Erreur vente"
                        );
                    }

                    $stmt->close();

                    /*
                    -------------------------------------------------
                    DIMINUTION DU STOCK
                    -------------------------------------------------
                    */

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

                    if (
                        !$stmt->execute() ||
                        $stmt->affected_rows !== 1
                    ) {

                        throw new Exception(
                            "Erreur stock"
                        );
                    }

                    $stmt->close();
                }

                /*
                -------------------------------------------------
                RECETTE GLOBALE
                -------------------------------------------------
                
                Cela permet à l'application de retrouver
                le chiffre d'affaires.
                -------------------------------------------------
                */

                $libelle =
                    "Vente - " .
                    implode(" | ", $details);

                $description_recette =
                    "Vente de plusieurs articles : " .
                    implode(" || ", $details);

                if ($description !== "") {

                    $description_recette .=
                        " | Note : " .
                        $description;
                }

                $stmt = $conn->prepare(
                    "INSERT INTO recettes
                    (
                        libelle,
                        montant,
                        description
                    )
                    VALUES (?, ?, ?)"
                );

                $stmt->bind_param(
                    "sds",
                    $libelle,
                    $total,
                    $description_recette
                );

                if (!$stmt->execute()) {

                    throw new Exception(
                        "Erreur recette"
                    );
                }

                $stmt->close();

                $conn->commit();

                /*
                -------------------------------------------------
                SUCCÈS
                -------------------------------------------------
                */

                $message =
                    "Vente enregistrée avec succès. Total : " .
                    argent($total);

                $type = "success";

            } catch (Exception $e) {

                $conn->rollback();

                $message =
                    "La vente n'a pas pu être enregistrée.";

                $type = "error";
            }
        }
    }
}

/* =========================================================
   STATISTIQUES
========================================================= */

$total_ventes = 0;
$nombre_ventes = 0;

$result = $conn->query(
    "SELECT
        COALESCE(SUM(montant),0) AS total,
        COUNT(*) AS nombre
     FROM ventes"
);

if ($result) {

    $data = $result->fetch_assoc();

    $total_ventes =
        (float)$data["total"];

    $nombre_ventes =
        (int)$data["nombre"];
}

/* =========================================================
   PRODUITS DISPONIBLES
========================================================= */

$produits = $conn->query(
    "SELECT
        id,
        nom,
        categorie,
        prix_vente,
        stock
     FROM produits
     WHERE stock > 0
     ORDER BY nom ASC"
);

/* =========================================================
   HISTORIQUE
========================================================= */

$ventes = $conn->query(
    "SELECT
        v.id,
        v.quantite,
        v.prix_unitaire,
        v.montant,
        v.description,
        v.date_vente,
        p.nom AS produit_nom
     FROM ventes v
     LEFT JOIN produits p
        ON p.id = v.produit_id
     ORDER BY v.id DESC
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

<title>Ventes - LAMBEMAH GESTION</title>

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
    border-left:
        3px solid #20b8ff;
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

    background:
        rgba(255,255,255,.06);

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

    background:
        rgba(255,70,70,.08);

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

    grid-template-columns:
        repeat(2, 1fr);

    gap: 15px;

    margin-bottom: 20px;
}

.stat {
    background: white;

    border-radius: 17px;

    padding: 18px;

    box-shadow:
        0 6px 25px
        rgba(25,55,80,.06);
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

/* =========================================================
   CONTENU
========================================================= */

.content {
    display: grid;

    grid-template-columns:
        400px 1fr;

    gap: 20px;
}

.card {
    background: white;

    border-radius: 20px;

    padding: 22px;

    box-shadow:
        0 6px 25px
        rgba(25,55,80,.06);
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
   FORMULAIRE
========================================================= */

.group {
    margin-bottom: 14px;
}

label {
    display: block;

    font-size: 10px;

    font-weight: bold;

    color: #536675;

    margin-bottom: 6px;
}

input,
select,
textarea {
    width: 100%;

    padding: 11px;

    border:
        1px solid #dfe8ee;

    border-radius: 10px;

    background: #fbfdff;

    outline: none;

    font-family: Arial, sans-serif;
}

input:focus,
select:focus,
textarea:focus {
    border-color: #20b8ff;
}

textarea {
    min-height: 70px;

    resize: vertical;
}

/* =========================================================
   ARTICLE
========================================================= */

.article-box {
    border:
        1px solid #e2e9ee;

    background: #fbfdff;

    padding: 12px;

    border-radius: 12px;

    margin-bottom: 10px;
}

.article-head {
    display: flex;

    justify-content: space-between;

    align-items: center;

    margin-bottom: 9px;
}

.article-head strong {
    font-size: 10px;

    color: #526a7a;
}

.remove {
    width: auto;

    padding: 5px 8px;

    border: none;

    border-radius: 7px;

    background: #fff0f0;

    color: #d33;

    cursor: pointer;
}

.article-row {
    display: grid;

    grid-template-columns:
        1.4fr .6fr .8fr;

    gap: 7px;
}

.add-btn {
    width: 100%;

    padding: 10px;

    border:
        1px dashed #20b8ff;

    border-radius: 10px;

    background: #eef9ff;

    color: #0878b7;

    cursor: pointer;

    font-weight: bold;

    margin-bottom: 15px;
}

/* =========================================================
   TOTAL
========================================================= */

.total-box {
    padding: 14px;

    margin: 15px 0;

    text-align: center;

    border-radius: 12px;

    background:
        linear-gradient(
            135deg,
            #eef9ff,
            #f7fcff
        );
}

.total-box small {
    display: block;

    color: #8b9da9;

    font-size: 9px;

    margin-bottom: 5px;
}

.total-box strong {
    color: #0878b7;

    font-size: 20px;
}

/* =========================================================
   BOUTON
========================================================= */

.btn-primary {
    width: 100%;

    padding: 13px;

    border: none;

    border-radius: 11px;

    background:
        linear-gradient(
            135deg,
            #20b8ff,
            #1264c7
        );

    color: white;

    font-weight: bold;

    cursor: pointer;
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
    font-size: 12px;

    font-weight: bold;

    color: #20394c;
}

.sale-amount {
    font-size: 12px;

    font-weight: bold;

    color: #0878b7;

    white-space: nowrap;
}

.sale-info {
    display: flex;

    flex-wrap: wrap;

    gap: 6px;

    margin-top: 7px;
}

.badge {
    padding: 5px 8px;

    border-radius: 7px;

    background: #f0f7fb;

    color: #607482;

    font-size: 9px;
}

.sale-description {
    margin-top: 8px;

    padding: 8px;

    border-radius: 8px;

    background: #f8fafc;

    color: #637581;

    font-size: 10px;
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

    .cards {
        grid-template-columns:
            1fr 1fr;

        gap: 9px;
    }

    .stat {
        padding: 14px;
    }

    .stat h2 {
        font-size: 16px;
    }

    .card {
        padding: 15px;

        border-radius: 16px;
    }

    .article-row {
        grid-template-columns:
            1fr 1fr;
    }

    .article-row > div:first-child {
        grid-column: 1 / -1;
    }
}

@media(max-width:390px) {

    .nav a {
        font-size: 7px;
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

            <h2>
                LAMBEMAH
            </h2>

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
            <a
                href="ventes.php"
                class="active"
            >
                💰 <span>Ventes</span>
            </a>
        </li>

        <li>
            <a href="prestations.php">
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
                💰 Ventes
            </h1>

            <p>
                Enregistre une ou plusieurs ventes.
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
                TOTAL DES VENTES
            </small>

            <h2>
                <?= argent($total_ventes) ?>
            </h2>

        </div>

        <div class="stat">

            <small>
                NOMBRE DE VENTES
            </small>

            <h2>
                <?= $nombre_ventes ?>
            </h2>

        </div>

    </div>

    <div class="content">

        <!-- =================================================
             NOUVELLE VENTE
        ================================================== -->

        <div class="card">

            <h2>
                ➕ Nouvelle vente
            </h2>

            <p>
                Tu peux vendre plusieurs produits sur une même vente.
            </p>

            <form method="POST">

                <input
                    type="hidden"
                    name="ajouter_vente"
                    value="1"
                >

                <div id="articles">

                    <div class="article-box">

                        <div class="article-head">

                            <strong>
                                ARTICLE 1
                            </strong>

                        </div>

                        <div class="article-row">

                            <div>

                                <label>
                                    PRODUIT
                                </label>

                                <select
                                    name="article[]"
                                    class="produit-select"
                                    required
                                    onchange="prixProduit(this)"
                                >

                                    <option value="">
                                        -- Choisir --
                                    </option>

                                    <?php
                                    if (
                                        $produits &&
                                        $produits->num_rows > 0
                                    ):
                                    ?>

                                        <?php while (
                                            $p =
                                            $produits->fetch_assoc()
                                        ): ?>

                                            <option
                                                value="<?= (int)$p["id"] ?>"
                                                data-prix="<?= htmlspecialchars($p["prix_vente"]) ?>"
                                                data-stock="<?= (int)$p["stock"] ?>"
                                            >

                                                <?= htmlspecialchars($p["nom"]) ?>

                                                <?php if (
                                                    !empty($p["categorie"])
                                                ): ?>

                                                    —
                                                    <?= htmlspecialchars(
                                                        $p["categorie"]
                                                    ) ?>

                                                <?php endif; ?>

                                                —
                                                Stock :
                                                <?= (int)$p["stock"] ?>

                                            </option>

                                        <?php endwhile; ?>

                                    <?php else: ?>

                                        <option
                                            value=""
                                            disabled
                                        >
                                            Aucun produit disponible
                                        </option>

                                    <?php endif; ?>

                                </select>

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
                                    PRIX UNITAIRE
                                </label>

                                <input
                                    type="number"
                                    name="prix[]"
                                    class="prix-input"
                                    min="1"
                                    step="500"
                                    required
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
                    ➕ Ajouter un autre produit
                </button>

                <div class="group">

                    <label>
                        DESCRIPTION
                    </label>

                    <textarea
                        name="description"
                        placeholder="Ex : Vente à Fanta..."
                    ></textarea>

                </div>

                <div class="total-box">

                    <small>
                        TOTAL DE LA VENTE
                    </small>

                    <strong id="totalAffiche">
                        0 FG
                    </strong>

                </div>

                <button
                    type="submit"
                    class="btn-primary"
                >
                    💾 ENREGISTRER LA VENTE
                </button>

            </form>

        </div>

        <!-- =================================================
             HISTORIQUE
        ================================================== -->

        <div class="card">

            <h2>
                📋 Historique des ventes
            </h2>

            <p>
                Les 50 dernières lignes de vente.
            </p>

            <?php if (
                $ventes &&
                $ventes->num_rows > 0
            ): ?>

                <?php while (
                    $v =
                    $ventes->fetch_assoc()
                ): ?>

                    <div class="sale">

                        <div class="sale-top">

                            <div class="sale-product">

                                👕
                                <?= htmlspecialchars(
                                    $v["produit_nom"]
                                    ?? "Produit supprimé"
                                ) ?>

                            </div>

                            <div class="sale-amount">

                                <?= argent(
                                    $v["montant"]
                                ) ?>

                            </div>

                        </div>

                        <div class="sale-info">

                            <span class="badge">

                                Quantité :
                                <?= (int)$v["quantite"] ?>

                            </span>

                            <span class="badge">

                                Prix :
                                <?= argent(
                                    $v["prix_unitaire"]
                                ) ?>

                            </span>

                        </div>

                        <?php if (
                            !empty($v["description"])
                        ): ?>

                            <div class="sale-description">

                                <?= htmlspecialchars(
                                    $v["description"]
                                ) ?>

                            </div>

                        <?php endif; ?>

                        <div class="sale-date">

                            📅
                            <?= htmlspecialchars(
                                $v["date_vente"]
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

                    🛒

                    <br><br>

                    Aucune vente enregistrée.

                </div>

            <?php endif; ?>

        </div>

    </div>

</main>

<script>

/* =========================================================
   NUMÉRO ARTICLE
========================================================= */

let numeroArticle = 1;


/* =========================================================
   PRIX AUTOMATIQUE
========================================================= */

function prixProduit(select) {

    const option =
        select.options[
            select.selectedIndex
        ];

    const prix =
        option.getAttribute(
            "data-prix"
        );

    const box =
        select.closest(
            ".article-box"
        );

    const prixInput =
        box.querySelector(
            ".prix-input"
        );

    if (prix) {

        prixInput.value =
            parseFloat(prix);

    } else {

        prixInput.value = "";

    }

    calculerTotal();
}


/* =========================================================
   AJOUTER PRODUIT
========================================================= */

function ajouterArticle() {

    numeroArticle++;

    const container =
        document.getElementById(
            "articles"
        );

    const premierSelect =
        document.querySelector(
            ".produit-select"
        );

    let options = "";

    if (premierSelect) {

        options =
            premierSelect.innerHTML;

    }

    const div =
        document.createElement(
            "div"
        );

    div.className =
        "article-box";

    div.innerHTML = `

        <div class="article-head">

            <strong>
                ARTICLE ${numeroArticle}
            </strong>

            <button
                type="button"
                class="remove"
                onclick="supprimerArticle(this)"
            >
                ✕
            </button>

        </div>

        <div class="article-row">

            <div>

                <label>
                    PRODUIT
                </label>

                <select
                    name="article[]"
                    class="produit-select"
                    required
                    onchange="prixProduit(this)"
                >

                    ${options}

                </select>

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
                    oninput="calculerTotal()"
                >

            </div>

            <div>

                <label>
                    PRIX UNITAIRE
                </label>

                <input
                    type="number"
                    name="prix[]"
                    class="prix-input"
                    min="1"
                    step="500"
                    required
                    oninput="calculerTotal()"
                >

            </div>

        </div>
    `;

    container.appendChild(div);
}


/* =========================================================
   SUPPRIMER ARTICLE
========================================================= */

function supprimerArticle(button) {

    const box =
        button.closest(
            ".article-box"
        );

    if (box) {

        box.remove();

        calculerTotal();
    }
}


/* =========================================================
   CALCUL TOTAL
========================================================= */

function calculerTotal() {

    const boxes =
        document.querySelectorAll(
            ".article-box"
        );

    let total = 0;

    boxes.forEach(function(box) {

        const quantite =
            parseFloat(
                box.querySelector(
                    'input[name="quantite[]"]'
                ).value
            ) || 0;

        const prix =
            parseFloat(
                box.querySelector(
                    'input[name="prix[]"]'
                ).value
            ) || 0;

        total +=
            quantite * prix;
    });

    document.getElementById(
        "totalAffiche"
    ).innerText =
        new Intl.NumberFormat(
            "fr-FR"
        ).format(total) +
        " FG";
}


/* =========================================================
   ÉCOUTER LES QUANTITÉS
========================================================= */

document.addEventListener(
    "input",
    function(e) {

        if (
            e.target.name ===
                "quantite[]" ||
            e.target.name ===
                "prix[]"
        ) {

            calculerTotal();
        }

    }
);

</script>

</body>

</html>
```
