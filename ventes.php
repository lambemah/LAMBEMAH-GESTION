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

/* =========================================================
   ENREGISTRER UNE VENTE AVEC PLUSIEURS ARTICLES
   ========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["enregistrer_vente"])) {

    $produits_ids = $_POST["produit_id"] ?? [];
    $quantites = $_POST["quantite"] ?? [];
    $prix_unitaires = $_POST["prix_unitaire"] ?? [];

    if (!is_array($produits_ids) || count($produits_ids) === 0) {
        $message = "Ajoutez au moins un article.";
        $type = "error";
    } else {

        $articles = [];
        $total_vente = 0;
        $erreur = "";

        /* Vérification de chaque ligne */
        for ($i = 0; $i < count($produits_ids); $i++) {

            $produit_id = (int)($produits_ids[$i] ?? 0);
            $quantite = (int)($quantites[$i] ?? 0);
            $prix_unitaire = (float)($prix_unitaires[$i] ?? 0);

            /* Ignorer une ligne complètement vide */
            if ($produit_id <= 0 && $quantite <= 0 && $prix_unitaire <= 0) {
                continue;
            }

            if ($produit_id <= 0) {
                $erreur = "Veuillez sélectionner un article à la ligne " . ($i + 1) . ".";
                break;
            }

            if ($quantite <= 0) {
                $erreur = "La quantité doit être supérieure à zéro à la ligne " . ($i + 1) . ".";
                break;
            }

            if ($prix_unitaire <= 0) {
                $erreur = "Veuillez indiquer le prix de vente à la ligne " . ($i + 1) . ".";
                break;
            }

            /* Vérifier le produit et son stock */
            $stmt = $conn->prepare(
                "SELECT id, nom, stock
                 FROM produits
                 WHERE id = ?
                 LIMIT 1"
            );

            if (!$stmt) {
                $erreur = "Erreur de préparation.";
                break;
            }

            $stmt->bind_param("i", $produit_id);
            $stmt->execute();

            $result = $stmt->get_result();
            $produit = $result->fetch_assoc();

            $stmt->close();

            if (!$produit) {
                $erreur = "Produit introuvable à la ligne " . ($i + 1) . ".";
                break;
            }

            if ((int)$produit["stock"] < $quantite) {
                $erreur =
                    "Stock insuffisant pour « " .
                    $produit["nom"] .
                    " ». Disponible : " .
                    (int)$produit["stock"] .
                    ".";
                break;
            }

            $montant = $quantite * $prix_unitaire;

            $articles[] = [
                "produit_id" => $produit_id,
                "nom" => $produit["nom"],
                "quantite" => $quantite,
                "prix_unitaire" => $prix_unitaire,
                "montant" => $montant
            ];

            $total_vente += $montant;
        }

        if (count($articles) === 0 && $erreur === "") {
            $erreur = "Ajoutez au moins un article.";
        }

        /* Enregistrement */
        if ($erreur !== "") {

            $message = $erreur;
            $type = "error";

        } else {

            $conn->begin_transaction();

            try {

                foreach ($articles as $article) {

                    /* Description de la ligne */
                    $description =
                        "Vente - " .
                        $article["nom"] .
                        " | Quantité : " .
                        $article["quantite"];

                    /* Enregistrer la vente */
                    $stmt = $conn->prepare(
                        "INSERT INTO ventes
                        (produit_id, quantite, prix_unitaire, montant, description)
                        VALUES (?, ?, ?, ?, ?)"
                    );

                    if (!$stmt) {
                        throw new Exception("Impossible de préparer la vente.");
                    }

                    $stmt->bind_param(
                        "iidds",
                        $article["produit_id"],
                        $article["quantite"],
                        $article["prix_unitaire"],
                        $article["montant"],
                        $description
                    );

                    if (!$stmt->execute()) {
                        $stmt->close();
                        throw new Exception("Impossible d'enregistrer la vente.");
                    }

                    $stmt->close();

                    /* Diminuer le stock */
                    $stmt = $conn->prepare(
                        "UPDATE produits
                         SET stock = stock - ?
                         WHERE id = ?
                         AND stock >= ?"
                    );

                    if (!$stmt) {
                        throw new Exception("Impossible de préparer la mise à jour du stock.");
                    }

                    $stmt->bind_param(
                        "iii",
                        $article["quantite"],
                        $article["produit_id"],
                        $article["quantite"]
                    );

                    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                        $stmt->close();
                        throw new Exception("Impossible de mettre à jour le stock.");
                    }

                    $stmt->close();
                }

                $conn->commit();

                $message =
                    "Vente enregistrée avec succès ! Total : " .
                    number_format($total_vente, 0, ",", " ") .
                    " FG";

                $type = "success";

            } catch (Exception $e) {

                $conn->rollback();

                $message = "La vente n'a pas pu être enregistrée.";
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
        COALESCE(SUM(montant), 0) AS total,
        COUNT(*) AS nombre
     FROM ventes"
);

if ($result) {
    $data = $result->fetch_assoc();

    $total_ventes = (float)$data["total"];
    $nombre_ventes = (int)$data["nombre"];
}


/* =========================================================
   PRODUITS DISPONIBLES
   ========================================================= */

$produits = $conn->query(
    "SELECT
        id,
        nom,
        categorie,
        stock,
        prix_achat
     FROM produits
     ORDER BY nom ASC"
);


/* =========================================================
   HISTORIQUE DES VENTES
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
     LEFT JOIN produits p ON p.id = v.produit_id
     ORDER BY v.id DESC
     LIMIT 50"
);


/* =========================================================
   FORMAT ARGENT
   ========================================================= */

function argent($montant) {
    return number_format((float)$montant, 0, ",", " ") . " FG";
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Ventes - LAMBEMAH GESTION</title>

<link
href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
rel="stylesheet"
>

<link
href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
rel="stylesheet"
>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #f4f7fb;
    font-family: Arial, sans-serif;
    color: #172033;
}

/* SIDEBAR */

.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    bottom: 0;
    width: 245px;
    background: #102a43;
    color: white;
    padding: 22px 15px;
    overflow-y: auto;
}

.logo {
    font-size: 22px;
    font-weight: bold;
    padding: 5px 12px 25px;
}

.logo small {
    display: block;
    font-size: 12px;
    color: #9cc8ff;
    margin-top: 4px;
}

.menu a {
    display: flex;
    align-items: center;
    gap: 11px;
    color: #dbeafe;
    text-decoration: none;
    padding: 12px 13px;
    border-radius: 10px;
    margin-bottom: 5px;
    font-size: 14px;
}

.menu a:hover,
.menu a.active {
    background: #1d4ed8;
    color: white;
}

/* CONTENU */

.main {
    margin-left: 245px;
    padding: 25px;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
}

.header h1 {
    margin: 0;
    font-size: 27px;
    font-weight: 700;
}

.header p {
    margin: 5px 0 0;
    color: #667085;
}

/* CARDS */

.stats {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
    margin-bottom: 20px;
}

.card-stat {
    background: white;
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 4px 15px rgba(0,0,0,.05);
}

.card-stat .icon {
    width: 42px;
    height: 42px;
    background: #e8f1ff;
    color: #1d4ed8;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    font-size: 20px;
    margin-bottom: 12px;
}

.card-stat .label {
    color: #667085;
    font-size: 13px;
}

.card-stat .value {
    font-size: 23px;
    font-weight: bold;
    margin-top: 4px;
}

/* FORMULAIRE */

.box {
    background: white;
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 4px 15px rgba(0,0,0,.05);
    margin-bottom: 20px;
}

.box-title {
    font-size: 19px;
    font-weight: bold;
    margin-bottom: 18px;
}

.article-line {
    border: 1px solid #e1e7ef;
    border-radius: 13px;
    padding: 15px;
    margin-bottom: 12px;
    background: #fbfcfe;
}

.line-number {
    font-weight: bold;
    color: #1d4ed8;
    margin-bottom: 10px;
}

label {
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 6px;
}

.form-control,
.form-select {
    min-height: 45px;
    border-radius: 9px;
}

.total-line {
    background: #eef5ff;
    border-radius: 12px;
    padding: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 15px;
}

.total-line strong {
    color: #1d4ed8;
    font-size: 21px;
}

/* TABLE */

.table-responsive {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th {
    background: #f5f7fa;
    color: #667085;
    font-size: 12px;
    padding: 12px;
    white-space: nowrap;
}

td {
    padding: 12px;
    border-top: 1px solid #edf0f4;
    font-size: 13px;
}

.badge-stock {
    background: #e9f7ef;
    color: #18864b;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 11px;
}

.alert {
    border-radius: 12px;
}

/* MOBILE */

@media (max-width: 768px) {

    .sidebar {
        position: static;
        width: 100%;
        height: auto;
        padding: 10px;
    }

    .logo {
        padding: 5px 8px 10px;
    }

    .menu {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 5px;
    }

    .menu a {
        justify-content: center;
        flex-direction: column;
        gap: 3px;
        padding: 8px 4px;
        font-size: 10px;
        text-align: center;
    }

    .menu a i {
        font-size: 17px;
    }

    .main {
        margin-left: 0;
        padding: 14px;
    }

    .header {
        display: block;
    }

    .header h1 {
        font-size: 23px;
    }

    .stats {
        grid-template-columns: 1fr;
    }

    .box {
        padding: 15px;
    }

    .article-line {
        padding: 12px;
    }

    .total-line {
        display: block;
    }

    .total-line strong {
        display: block;
        margin-top: 5px;
    }

    .btn {
        width: 100%;
    }
}

</style>

</head>

<body>


<!-- =====================================================
     MENU
     ===================================================== -->

<aside class="sidebar">

    <div class="logo">
        LAMBEMAH
        <small>GESTION • PRESTATION</small>
    </div>

    <nav class="menu">

        <a href="index.php">
            <i class="bi bi-house"></i>
            Accueil
        </a>

        <a href="produits.php">
            <i class="bi bi-box"></i>
            Produits
        </a>

        <a href="ventes.php" class="active">
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

        <?php if ($role === "admin"): ?>

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


<!-- =====================================================
     CONTENU
     ===================================================== -->

<main class="main">

    <div class="header">

        <div>
            <h1>Ventes</h1>
            <p>Enregistrer les ventes de plusieurs articles.</p>
        </div>

    </div>


    <?php if ($message !== ""): ?>

        <div class="alert <?= $type === "success" ? "alert-success" : "alert-danger" ?>">
            <?= htmlspecialchars($message) ?>
        </div>

    <?php endif; ?>


    <!-- STATISTIQUES -->

    <div class="stats">

        <div class="card-stat">

            <div class="icon">
                <i class="bi bi-cash-stack"></i>
            </div>

            <div class="label">
                Chiffre d'affaires
            </div>

            <div class="value">
                <?= argent($total_ventes) ?>
            </div>

        </div>


        <div class="card-stat">

            <div class="icon">
                <i class="bi bi-receipt"></i>
            </div>

            <div class="label">
                Nombre de lignes de vente
            </div>

            <div class="value">
                <?= $nombre_ventes ?>
            </div>

        </div>

    </div>


    <!-- =================================================
         NOUVELLE VENTE
         ================================================= -->

    <div class="box">

        <div class="box-title">
            <i class="bi bi-cart-plus"></i>
            Nouvelle vente
        </div>


        <form method="POST" id="venteForm">

            <div id="articlesContainer">

                <!-- PREMIÈRE LIGNE -->

                <div class="article-line">

                    <div class="line-number">
                        Article 1
                    </div>

                    <div class="row g-3">

                        <div class="col-md-5">

                            <label>Désignation</label>

                            <select
                                name="produit_id[]"
                                class="form-select produit-select"
                                onchange="calculerTotal()"
                                required
                            >

                                <option value="">
                                    — Choisir un article —
                                </option>

                                <?php if ($produits): ?>

                                    <?php while ($p = $produits->fetch_assoc()): ?>

                                        <option
                                            value="<?= (int)$p["id"] ?>"
                                            data-stock="<?= (int)$p["stock"] ?>"
                                        >
                                            <?= htmlspecialchars($p["nom"]) ?>
                                            — Stock : <?= (int)$p["stock"] ?>
                                        </option>

                                    <?php endwhile; ?>

                                <?php endif; ?>

                            </select>

                        </div>


                        <div class="col-md-2">

                            <label>PVU</label>

                            <input
                                type="number"
                                name="prix_unitaire[]"
                                class="form-control prix-input"
                                min="1"
                                step="1"
                                placeholder="Ex : 70 000"
                                oninput="calculerTotal()"
                                required
                            >

                        </div>


                        <div class="col-md-2">

                            <label>Quantité</label>

                            <input
                                type="number"
                                name="quantite[]"
                                class="form-control quantite-input"
                                min="1"
                                value="1"
                                oninput="calculerTotal()"
                                required
                            >

                        </div>


                        <div class="col-md-2">

                            <label>Montant</label>

                            <input
                                type="text"
                                class="form-control montant-input"
                                value="0 FG"
                                readonly
                            >

                        </div>


                        <div class="col-md-1 d-flex align-items-end">

                            <button
                                type="button"
                                class="btn btn-outline-danger supprimer-btn"
                                onclick="supprimerArticle(this)"
                                style="display:none;"
                            >
                                <i class="bi bi-trash"></i>
                            </button>

                        </div>

                    </div>

                </div>

            </div>


            <!-- AJOUT ARTICLE -->

            <button
                type="button"
                class="btn btn-outline-primary mb-3"
                onclick="ajouterArticle()"
            >
                <i class="bi bi-plus-circle"></i>
                Ajouter un autre article
            </button>


            <!-- TOTAL -->

            <div class="total-line">

                <span>
                    <strong>Total de la vente</strong>
                </span>

                <strong id="totalGeneral">
                    0 FG
                </strong>

            </div>


            <button
                type="submit"
                name="enregistrer_vente"
                class="btn btn-primary mt-3"
            >
                <i class="bi bi-check-circle"></i>
                Enregistrer la vente
            </button>

        </form>

    </div>


    <!-- =================================================
         HISTORIQUE
         ================================================= -->

    <div class="box">

        <div class="box-title">
            <i class="bi bi-clock-history"></i>
            Dernières ventes
        </div>

        <div class="table-responsive">

            <table>

                <thead>

                    <tr>
                        <th>Désignation</th>
                        <th>PVU</th>
                        <th>Quantité</th>
                        <th>Montant</th>
                        <th>Date</th>
                    </tr>

                </thead>

                <tbody>

                <?php if ($ventes && $ventes->num_rows > 0): ?>

                    <?php while ($v = $ventes->fetch_assoc()): ?>

                        <tr>

                            <td>
                                <strong>
                                    <?= htmlspecialchars($v["produit_nom"] ?? "Article supprimé") ?>
                                </strong>
                            </td>

                            <td>
                                <?= argent($v["prix_unitaire"]) ?>
                            </td>

                            <td>
                                <span class="badge-stock">
                                    <?= (int)$v["quantite"] ?>
                                </span>
                            </td>

                            <td>
                                <strong>
                                    <?= argent($v["montant"]) ?>
                                </strong>
                            </td>

                            <td>
                                <?= htmlspecialchars($v["date_vente"]) ?>
                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="5" class="text-center">
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
   AJOUTER UN ARTICLE
   ========================================================= */

function ajouterArticle() {

    const container =
        document.getElementById("articlesContainer");

    const premiereLigne =
        container.querySelector(".article-line");

    const nouvelleLigne =
        premiereLigne.cloneNode(true);

    /* Réinitialiser les champs */

    const select =
        nouvelleLigne.querySelector(".produit-select");

    const prix =
        nouvelleLigne.querySelector(".prix-input");

    const quantite =
        nouvelleLigne.querySelector(".quantite-input");

    const montant =
        nouvelleLigne.querySelector(".montant-input");

    select.value = "";
    prix.value = "";
    quantite.value = "1";
    montant.value = "0 FG";

    /* Numéro de ligne */

    const nombre =
        container.querySelectorAll(".article-line").length + 1;

    nouvelleLigne.querySelector(".line-number").textContent =
        "Article " + nombre;

    /* Afficher la corbeille */

    const supprimer =
        nouvelleLigne.querySelector(".supprimer-btn");

    supprimer.style.display = "block";

    container.appendChild(nouvelleLigne);

    mettreAJourBoutons();

    calculerTotal();
}


/* =========================================================
   SUPPRIMER UN ARTICLE
   ========================================================= */

function supprimerArticle(bouton) {

    const ligne =
        bouton.closest(".article-line");

    const container =
        document.getElementById("articlesContainer");

    if (container.querySelectorAll(".article-line").length <= 1) {
        return;
    }

    ligne.remove();

    renumeroterArticles();

    mettreAJourBoutons();

    calculerTotal();
}


/* =========================================================
   RENUMÉROTATION
   ========================================================= */

function renumeroterArticles() {

    const lignes =
        document.querySelectorAll(".article-line");

    lignes.forEach(function(ligne, index) {

        ligne.querySelector(".line-number").textContent =
            "Article " + (index + 1);

    });
}


/* =========================================================
   BOUTONS SUPPRIMER
   ========================================================= */

function mettreAJourBoutons() {

    const lignes =
        document.querySelectorAll(".article-line");

    lignes.forEach(function(ligne) {

        const bouton =
            ligne.querySelector(".supprimer-btn");

        if (lignes.length > 1) {
            bouton.style.display = "block";
        } else {
            bouton.style.display = "none";
        }

    });
}


/* =========================================================
   CALCUL TOTAL
   ========================================================= */

function calculerTotal() {

    const lignes =
        document.querySelectorAll(".article-line");

    let totalGeneral = 0;

    lignes.forEach(function(ligne) {

        const prix =
            parseFloat(
                ligne.querySelector(".prix-input").value
            ) || 0;

        const quantite =
            parseInt(
                ligne.querySelector(".quantite-input").value
            ) || 0;

        const montant =
            prix * quantite;

        ligne.querySelector(".montant-input").value =
            new Intl.NumberFormat("fr-FR").format(montant) +
            " FG";

        totalGeneral += montant;

    });

    document.getElementById("totalGeneral").textContent =
        new Intl.NumberFormat("fr-FR").format(totalGeneral) +
        " FG";
}

</script>

</body>
</html>
