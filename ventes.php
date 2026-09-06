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
   ENREGISTRER UNE VENTE
   ========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["enregistrer_vente"])) {

    $client = trim($_POST["client"] ?? "");

    $produits_ids = $_POST["produit_id"] ?? [];
    $quantites = $_POST["quantite"] ?? [];
    $prix_unitaires = $_POST["prix_unitaire"] ?? [];

    if ($client === "") {
        $client = "Client non renseigné";
    }

    $articles = [];
    $total_vente = 0;
    $erreur = "";

    if (!is_array($produits_ids) || count($produits_ids) === 0) {
        $erreur = "Ajoutez au moins un article.";
    } else {

        for ($i = 0; $i < count($produits_ids); $i++) {

            $produit_id = (int)($produits_ids[$i] ?? 0);
            $quantite = (int)($quantites[$i] ?? 0);
            $prix_unitaire = (float)($prix_unitaires[$i] ?? 0);

            if ($produit_id <= 0) {
                $erreur = "Veuillez sélectionner l'article " . ($i + 1) . ".";
                break;
            }

            if ($quantite <= 0) {
                $erreur = "La quantité de l'article " . ($i + 1) . " est incorrecte.";
                break;
            }

            if ($prix_unitaire <= 0) {
                $erreur = "Veuillez saisir le PVU de l'article " . ($i + 1) . ".";
                break;
            }

            /* Vérifier le produit */
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
                $erreur = "Produit introuvable.";
                break;
            }

            if ((int)$produit["stock"] < $quantite) {
                $erreur =
                    "Stock insuffisant pour " .
                    $produit["nom"] .
                    ". Stock disponible : " .
                    $produit["stock"];
                break;
            }

            $montant = $prix_unitaire * $quantite;

            $articles[] = [
                "produit_id" => $produit_id,
                "nom" => $produit["nom"],
                "quantite" => $quantite,
                "prix_unitaire" => $prix_unitaire,
                "montant" => $montant
            ];

            $total_vente += $montant;
        }
    }

    /* =====================================================
       ENREGISTREMENT
       ===================================================== */

    if ($erreur !== "") {

        $message = $erreur;
        $type = "error";

    } elseif (count($articles) === 0) {

        $message = "Ajoutez au moins un article.";
        $type = "error";

    } else {

        $conn->begin_transaction();

        try {

            foreach ($articles as $article) {

                $description =
                    "Client : " . $client .
                    " | Vente de " . $article["nom"] .
                    " | Quantité : " . $article["quantite"];

                /* Enregistrer la vente */
                $stmt = $conn->prepare(
                    "INSERT INTO ventes
                    (produit_id, quantite, prix_unitaire, montant, description)
                    VALUES (?, ?, ?, ?, ?)"
                );

                if (!$stmt) {
                    throw new Exception("Erreur vente.");
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
                    throw new Exception("Erreur stock.");
                }

                $stmt->bind_param(
                    "iii",
                    $article["quantite"],
                    $article["produit_id"],
                    $article["quantite"]
                );

                if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                    throw new Exception("Impossible de mettre à jour le stock.");
                }

                $stmt->close();
            }

            $conn->commit();

            $message =
                "Vente enregistrée pour " .
                $client .
                " — Total : " .
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
   PRODUITS
   ========================================================= */

$produits = $conn->query(
    "SELECT
        id,
        nom,
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


function argent($montant) {
    return number_format(
        (float)$montant,
        0,
        ",",
        " "
    ) . " FG";
}

?>

<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>Ventes - LAMBEMAH GESTION</title>

<link
href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
rel="stylesheet">

<link
href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
rel="stylesheet">

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

.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: 245px;
    height: 100vh;
    background: #102a43;
    color: white;
    padding: 22px 15px;
}

.logo {
    font-size: 22px;
    font-weight: bold;
    padding: 5px 12px 25px;
}

.logo small {
    display: block;
    font-size: 11px;
    color: #9cc8ff;
    margin-top: 4px;
}

.menu a {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #dbeafe;
    text-decoration: none;
    padding: 12px;
    border-radius: 10px;
    margin-bottom: 5px;
    font-size: 14px;
}

.menu a:hover,
.menu a.active {
    background: #1d4ed8;
    color: white;
}

.main {
    margin-left: 245px;
    padding: 25px;
}

.header {
    margin-bottom: 25px;
}

.header h1 {
    margin: 0;
    font-size: 28px;
}

.header p {
    margin: 5px 0 0;
    color: #667085;
}

.stats {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
    margin-bottom: 20px;
}

.card-stat {
    background: white;
    border-radius: 15px;
    padding: 20px;
    box-shadow: 0 3px 15px rgba(0,0,0,.05);
}

.label {
    color: #667085;
    font-size: 13px;
}

.value {
    font-size: 24px;
    font-weight: bold;
    margin-top: 6px;
}

.box {
    background: white;
    border-radius: 15px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 3px 15px rgba(0,0,0,.05);
}

.box-title {
    font-size: 19px;
    font-weight: bold;
    margin-bottom: 18px;
}

.article {
    background: #f8fafc;
    border: 1px solid #e3e8ef;
    border-radius: 12px;
    padding: 15px;
    margin-bottom: 12px;
}

.article-title {
    color: #1d4ed8;
    font-weight: bold;
    margin-bottom: 12px;
}

.form-control,
.form-select {
    min-height: 45px;
    border-radius: 9px;
}

.total {
    background: #eef5ff;
    border-radius: 12px;
    padding: 15px;
    margin-top: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.total strong {
    font-size: 21px;
    color: #1d4ed8;
}

table {
    width: 100%;
}

th {
    background: #f5f7fa;
    padding: 12px;
    font-size: 12px;
}

td {
    padding: 12px;
    border-top: 1px solid #edf0f4;
    font-size: 13px;
}

@media(max-width: 768px) {

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
        gap: 4px;
    }

    .menu a {
        justify-content: center;
        flex-direction: column;
        gap: 3px;
        text-align: center;
        font-size: 10px;
        padding: 7px 3px;
    }

    .menu a i {
        font-size: 17px;
    }

    .main {
        margin-left: 0;
        padding: 14px;
        padding-bottom: 30px;
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

    .total {
        display: block;
    }

    .total strong {
        display: block;
        margin-top: 5px;
    }

}

</style>

</head>

<body>


<!-- MENU -->

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
            Achats
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


<!-- CONTENU -->

<main class="main">

    <div class="header">

        <h1>💰 Ventes</h1>

        <p>
            Enregistrer une vente avec plusieurs articles.
        </p>

    </div>


    <?php if ($message !== ""): ?>

        <div class="alert
        <?= $type === "success"
            ? "alert-success"
            : "alert-danger" ?>">

            <?= htmlspecialchars($message) ?>

        </div>

    <?php endif; ?>


    <!-- STATISTIQUES -->

    <div class="stats">

        <div class="card-stat">

            <div class="label">
                Chiffre d'affaires
            </div>

            <div class="value">
                <?= argent($total_ventes) ?>
            </div>

        </div>


        <div class="card-stat">

            <div class="label">
                Articles vendus
            </div>

            <div class="value">
                <?= $nombre_ventes ?>
            </div>

        </div>

    </div>


    <!-- NOUVELLE VENTE -->

    <div class="box">

        <div class="box-title">
            <i class="bi bi-cart-plus"></i>
            Nouvelle vente
        </div>


        <form method="POST">


            <!-- CLIENT -->

            <div class="mb-4">

                <label class="form-label">
                    Nom du client
                </label>

                <input
                    type="text"
                    name="client"
                    class="form-control"
                    placeholder="Ex : Mohamed"
                    required>

            </div>


            <!-- ARTICLES -->

            <div id="articles">


                <!-- ARTICLE 1 -->

                <div class="article">

                    <div class="article-title">
                        Article 1
                    </div>

                    <div class="row g-3">

                        <div class="col-md-5">

                            <label>
                                Désignation
                            </label>

                            <select
                                name="produit_id[]"
                                class="form-select"
                                onchange="calculer()"
                                required>

                                <option value="">
                                    Choisir un article
                                </option>

                                <?php if ($produits): ?>

                                    <?php while ($p = $produits->fetch_assoc()): ?>

                                        <option
                                            value="<?= (int)$p["id"] ?>">

                                            <?= htmlspecialchars($p["nom"]) ?>
                                            — Stock :
                                            <?= (int)$p["stock"] ?>

                                        </option>

                                    <?php endwhile; ?>

                                <?php endif; ?>

                            </select>

                        </div>


                        <div class="col-md-3">

                            <label>
                                PVU
                            </label>

                            <input
                                type="number"
                                name="prix_unitaire[]"
                                class="form-control prix"
                                min="1"
                                placeholder="Ex : 70000"
                                oninput="calculer()"
                                required>

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
                                required>

                        </div>


                        <div class="col-md-2">

                            <label>
                                Montant
                            </label>

                            <input
                                type="text"
                                class="form-control montant"
                                value="0 FG"
                                readonly>

                        </div>

                    </div>

                </div>

            </div>


            <!-- BOUTON AJOUT -->

            <button
                type="button"
                class="btn btn-outline-primary"
                onclick="ajouterArticle()">

                <i class="bi bi-plus-circle"></i>
                Ajouter un article

            </button>


            <!-- TOTAL -->

            <div class="total">

                <strong>
                    Total de la vente
                </strong>

                <strong id="totalGeneral">
                    0 FG
                </strong>

            </div>


            <!-- ENREGISTRER -->

            <button
                type="submit"
                name="enregistrer_vente"
                class="btn btn-primary mt-3">

                <i class="bi bi-check-circle"></i>
                Enregistrer la vente

            </button>


        </form>

    </div>


    <!-- HISTORIQUE -->

    <div class="box">

        <div class="box-title">
            <i class="bi bi-clock-history"></i>
            Dernières ventes
        </div>

        <div style="overflow-x:auto;">

            <table>

                <thead>

                    <tr>

                        <th>Client / Désignation</th>
                        <th>PVU</th>
                        <th>Qté</th>
                        <th>Montant</th>
                        <th>Date</th>

                    </tr>

                </thead>

                <tbody>

                <?php if ($ventes && $ventes->num_rows > 0): ?>

                    <?php while ($v = $ventes->fetch_assoc()): ?>

                        <tr>

                            <td>
                                <?= htmlspecialchars(
                                    $v["description"]
                                ) ?>
                            </td>

                            <td>
                                <?= argent(
                                    $v["prix_unitaire"]
                                ) ?>
                            </td>

                            <td>
                                <?= (int)$v["quantite"] ?>
                            </td>

                            <td>
                                <strong>
                                    <?= argent(
                                        $v["montant"]
                                    ) ?>
                                </strong>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $v["date_vente"]
                                ) ?>
                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>

                        <td colspan="5"
                            class="text-center">

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
        document.getElementById("articles");

    const premier =
        container.querySelector(".article");

    const nouveau =
        premier.cloneNode(true);


    /* Vider les champs */

    nouveau.querySelector("select").value = "";

    nouveau.querySelector(".prix").value = "";

    nouveau.querySelector(".quantite").value = 1;

    nouveau.querySelector(".montant").value = "0 FG";


    /* Numéro */

    const nombre =
        container.querySelectorAll(".article").length + 1;

    nouveau.querySelector(".article-title").textContent =
        "Article " + nombre;


    /* Ajouter */

    container.appendChild(nouveau);

    calculer();
}


/* =========================================================
   CALCUL
   ========================================================= */

function calculer() {

    const articles =
        document.querySelectorAll(".article");

    let total = 0;


    articles.forEach(function(article) {

        const prix =
            parseFloat(
                article.querySelector(".prix").value
            ) || 0;

        const quantite =
            parseInt(
                article.querySelector(".quantite").value
            ) || 0;

        const montant =
            prix * quantite;

        article.querySelector(".montant").value =
            new Intl.NumberFormat("fr-FR")
            .format(montant) + " FG";

        total += montant;

    });


    document.getElementById("totalGeneral").textContent =
        new Intl.NumberFormat("fr-FR")
        .format(total) + " FG";
}

</script>


</body>
</html>
