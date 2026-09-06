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
    $quantites    = $_POST["quantite"] ?? [];
    $prix_unitaires = $_POST["prix_unitaire"] ?? [];

    if (!is_array($produits_ids)) {
        $produits_ids = [];
    }

    if (!is_array($quantites)) {
        $quantites = [];
    }

    if (!is_array($prix_unitaires)) {
        $prix_unitaires = [];
    }


    /* Vérifier le client */

    if ($client === "") {

        $message = "Le nom du client est obligatoire.";
        $type = "error";

    } elseif (count($produits_ids) === 0) {

        $message = "Ajoute au moins un article.";
        $type = "error";

    } else {

        $conn->begin_transaction();

        try {

            $lignes_valides = [];
            $total_vente = 0;


            /* =================================================
               VÉRIFICATION DE CHAQUE ARTICLE
               ================================================= */

            foreach ($produits_ids as $i => $produit_id) {

                $produit_id = (int)$produit_id;
                $quantite = (int)($quantites[$i] ?? 0);
                $prix_unitaire = (float)($prix_unitaires[$i] ?? 0);


                /* Ignorer une ligne vide */

                if ($produit_id <= 0) {
                    continue;
                }


                if ($quantite <= 0) {
                    throw new Exception(
                        "La quantité d'un article doit être supérieure à zéro."
                    );
                }


                if ($prix_unitaire < 0) {
                    throw new Exception(
                        "Le prix de vente est incorrect."
                    );
                }


                /* Chercher l'article */

                $stmt = $conn->prepare(
                    "SELECT id, nom, stock
                     FROM produits
                     WHERE id = ?
                     LIMIT 1"
                );

                $stmt->bind_param("i", $produit_id);
                $stmt->execute();

                $produit =
                    $stmt->get_result()->fetch_assoc();

                $stmt->close();


                if (!$produit) {

                    throw new Exception(
                        "Un article sélectionné n'existe pas."
                    );
                }


                /* Vérifier le stock */

                if ((int)$produit["stock"] < $quantite) {

                    throw new Exception(
                        "Stock insuffisant pour : " .
                        $produit["nom"] .
                        ". Stock disponible : " .
                        $produit["stock"]
                    );
                }


                $montant =
                    $quantite * $prix_unitaire;


                $total_vente += $montant;


                $lignes_valides[] = [
                    "produit_id" => $produit_id,
                    "nom" => $produit["nom"],
                    "quantite" => $quantite,
                    "prix" => $prix_unitaire,
                    "montant" => $montant
                ];
            }


            if (count($lignes_valides) === 0) {

                throw new Exception(
                    "Ajoute au moins un article valide."
                );
            }


            /* =================================================
               DATE AUTOMATIQUE
               ================================================= */

            $date_vente =
                date("Y-m-d H:i:s");


            /* =================================================
               ENREGISTRER CHAQUE ARTICLE
               ================================================= */

            foreach ($lignes_valides as $ligne) {

                /*
                 * Le client est enregistré dans description
                 * pour éviter de créer une nouvelle colonne.
                 */

                $description =
                    "Client : " .
                    $client .
                    " | Vente de " .
                    $ligne["nom"] .
                    " | Quantité : " .
                    $ligne["quantite"];


                /* Enregistrer la vente */

                $stmt = $conn->prepare(
                    "INSERT INTO ventes
                    (
                        produit_id,
                        quantite,
                        prix_unitaire,
                        montant,
                        description,
                        date_vente
                    )
                    VALUES (?, ?, ?, ?, ?, ?)"
                );


                $stmt->bind_param(
                    "iiddss",
                    $ligne["produit_id"],
                    $ligne["quantite"],
                    $ligne["prix"],
                    $ligne["montant"],
                    $description,
                    $date_vente
                );


                if (!$stmt->execute()) {
                    throw new Exception();
                }


                $stmt->close();


                /* =================================================
                   DIMINUER LE STOCK
                   ================================================= */

                $stmt = $conn->prepare(
                    "UPDATE produits
                     SET stock = stock - ?
                     WHERE id = ?"
                );


                $stmt->bind_param(
                    "ii",
                    $ligne["quantite"],
                    $ligne["produit_id"]
                );


                if (!$stmt->execute()) {
                    throw new Exception();
                }


                $stmt->close();


                /* =================================================
                   ENREGISTRER LE MOUVEMENT DE SORTIE
                   ================================================= */

                $description_mouvement =
                    "VENTE | Client : " .
                    $client .
                    " | Article : " .
                    $ligne["nom"];


                $stmt = $conn->prepare(
                    "INSERT INTO mouvements
                    (
                        produit_id,
                        type,
                        quantite,
                        prix,
                        description,
                        date_mouvement
                    )
                    VALUES (?, 'SORTIE', ?, ?, ?, ?)"
                );


                $stmt->bind_param(
                    "iidss",
                    $ligne["produit_id"],
                    $ligne["quantite"],
                    $ligne["prix"],
                    $description_mouvement,
                    $date_vente
                );


                if (!$stmt->execute()) {
                    throw new Exception();
                }


                $stmt->close();
            }


            /* =================================================
               VALIDER TOUTE LA VENTE
               ================================================= */

            $conn->commit();


            $message =
                "Vente enregistrée avec succès le " .
                date("d/m/Y à H:i") .
                " — Total : " .
                number_format(
                    $total_vente,
                    0,
                    ",",
                    " "
                ) .
                " FG.";

            $type = "success";

        } catch (Exception $e) {

            $conn->rollback();

            $message =
                $e->getMessage() !== ""
                ? $e->getMessage()
                : "La vente n'a pas pu être enregistrée.";

            $type = "error";
        }
    }
}


/* =========================================================
   PRODUITS DISPONIBLES
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
     LEFT JOIN produits p
        ON p.id = v.produit_id
     ORDER BY v.id DESC
     LIMIT 100"
);


/* =========================================================
   TOTAL DES VENTES
   ========================================================= */

$total_ventes = 0;

$result = $conn->query(
    "SELECT
        COALESCE(SUM(montant), 0) AS total
     FROM ventes"
);

if ($result) {

    $data = $result->fetch_assoc();

    $total_ventes =
        (float)$data["total"];
}


/* =========================================================
   NOMBRE DE VENTES
   ========================================================= */

$nombre_ventes = 0;

$result = $conn->query(
    "SELECT COUNT(*) AS total
     FROM ventes"
);

if ($result) {

    $data = $result->fetch_assoc();

    $nombre_ventes =
        (int)$data["total"];
}


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
   EXTRAIRE CLIENT
   ========================================================= */

function extraireClient($description)
{
    if (
        strpos(
            $description,
            "Client : "
        ) !== false
    ) {

        $parties =
            explode(
                " | ",
                $description
            );


        foreach ($parties as $partie) {

            if (
                strpos(
                    $partie,
                    "Client : "
                ) === 0
            ) {

                return str_replace(
                    "Client : ",
                    "",
                    $partie
                );
            }
        }
    }

    return "Client non renseigné";
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


/* =========================================================
   SIDEBAR
   ========================================================= */

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


/* =========================================================
   CONTENU
   ========================================================= */

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

    margin-top: 5px;

    color: #667085;
}


/* =========================================================
   STATISTIQUES
   ========================================================= */

.stats {

    display: grid;

    grid-template-columns:
        repeat(2, 1fr);

    gap: 15px;

    margin-bottom: 20px;
}


.card-stat {

    background: white;

    border-radius: 15px;

    padding: 20px;

    box-shadow:
        0 3px 15px
        rgba(0,0,0,.05);
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


/* =========================================================
   BLOCS
   ========================================================= */

.box {

    background: white;

    border-radius: 15px;

    padding: 20px;

    margin-bottom: 20px;

    box-shadow:
        0 3px 15px
        rgba(0,0,0,.05);
}


.box-title {

    font-size: 19px;

    font-weight: bold;

    margin-bottom: 18px;
}


/* =========================================================
   FORMULAIRES
   ========================================================= */

.form-control,
.form-select {

    min-height: 45px;

    border-radius: 9px;
}


.help {

    color: #667085;

    font-size: 12px;
}


/* =========================================================
   LIGNES ARTICLES
   ========================================================= */

.article-line {

    background: #f8fafc;

    border: 1px solid #e5e7eb;

    border-radius: 12px;

    padding: 15px;

    margin-bottom: 12px;
}


.line-total {

    background: #eef5ff;

    border-radius: 9px;

    min-height: 45px;

    display: flex;

    align-items: center;

    justify-content: center;

    font-weight: bold;

    padding: 5px;
}


/* =========================================================
   TOTAL
   ========================================================= */

.total-box {

    background: #eef5ff;

    border-radius: 12px;

    padding: 17px;

    margin-top: 15px;

    font-size: 18px;

    font-weight: bold;

    display: flex;

    justify-content: space-between;

    align-items: center;
}


/* =========================================================
   TABLE
   ========================================================= */

.table-responsive {

    overflow-x: auto;
}


table {

    width: 100%;
}


th {

    background: #f5f7fa;

    padding: 12px;

    font-size: 12px;

    white-space: nowrap;
}


td {

    padding: 12px;

    border-top: 1px solid #edf0f4;

    font-size: 13px;

    white-space: nowrap;
}


/* =========================================================
   MOBILE
   ========================================================= */

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

        grid-template-columns:
            repeat(4, 1fr);

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


    .total-box {

        flex-direction: column;

        gap: 8px;

        align-items: flex-start;
    }

}

</style>

</head>


<body>


<!-- =======================================================
     MENU
     ======================================================= -->

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


        <a href="ventes.php"
           class="active">

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



<!-- =======================================================
     CONTENU
     ======================================================= -->

<main class="main">


    <div class="header">

        <h1>
            🛒 Ventes
        </h1>

        <p>
            Enregistre les reventes aux clients et
            déduis automatiquement les articles du stock.
        </p>

    </div>



    <!-- MESSAGE -->

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
                Total des ventes
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



    <!-- =====================================================
         NOUVELLE VENTE
         ===================================================== -->

    <div class="box">

        <div class="box-title">

            <i class="bi bi-cart-plus"></i>

            Enregistrer une vente

        </div>


        <form method="POST"
              id="venteForm">


            <input
                type="hidden"
                name="enregistrer_vente"
                value="1">


            <!-- CLIENT -->

            <div class="mb-4">

                <label class="form-label">

                    Client

                </label>

                <input
                    type="text"
                    name="client"
                    class="form-control"
                    placeholder="Nom du client"
                    required>

            </div>


            <!-- ARTICLES -->

            <div id="articles">


                <!-- PREMIÈRE LIGNE -->

                <div class="article-line">


                    <div class="row g-3 align-items-end">


                        <div class="col-md-4">

                            <label class="form-label">
                                Désignation
                            </label>

                            <select
                                name="produit_id[]"
                                class="form-select produit-select"
                                onchange="calculerTotal()"
                                required>

                                <option value="">
                                    Choisir un article
                                </option>


                                <?php

                                if ($produits) {

                                    while (
                                        $p =
                                        $produits->fetch_assoc()
                                    ) {

                                ?>

                                    <option
                                        value="<?= (int)$p["id"] ?>"
                                        data-stock="<?= (int)$p["stock"] ?>">

                                        <?= htmlspecialchars(
                                            $p["nom"]
                                        ) ?>

                                        — Stock :
                                        <?= (int)$p["stock"] ?>

                                    </option>

                                <?php

                                    }
                                }

                                ?>

                            </select>

                        </div>


                        <div class="col-md-2">

                            <label class="form-label">
                                PVU
                            </label>

                            <input
                                type="number"
                                name="prix_unitaire[]"
                                class="form-control prix"
                                min="0"
                                step="1"
                                placeholder="Ex : 70000"
                                oninput="calculerTotal()"
                                required>

                        </div>


                        <div class="col-md-2">

                            <label class="form-label">
                                Quantité
                            </label>

                            <input
                                type="number"
                                name="quantite[]"
                                class="form-control quantite"
                                min="1"
                                value="1"
                                oninput="calculerTotal()"
                                required>

                        </div>


                        <div class="col-md-3">

                            <label class="form-label">
                                Montant
                            </label>

                            <div class="line-total montant-ligne">
                                0 FG
                            </div>

                        </div>


                        <div class="col-md-1">

                            <button
                                type="button"
                                class="btn btn-outline-danger w-100"
                                onclick="supprimerLigne(this)"
                                title="Supprimer">

                                <i class="bi bi-trash"></i>

                            </button>

                        </div>

                    </div>

                </div>

            </div>


            <!-- AJOUT ARTICLE -->

            <button
                type="button"
                class="btn btn-outline-primary"
                onclick="ajouterLigne()">

                <i class="bi bi-plus-circle"></i>

                Ajouter un autre article

            </button>


            <!-- TOTAL -->

            <div class="total-box">

                <span>
                    TOTAL DE LA VENTE
                </span>

                <span id="totalVente">
                    0 FG
                </span>

            </div>


            <!-- ENREGISTRER -->

            <button
                type="submit"
                class="btn btn-primary btn-lg mt-3">

                <i class="bi bi-check-circle"></i>

                Enregistrer la vente

            </button>


        </form>

    </div>



    <!-- =====================================================
         HISTORIQUE
         ===================================================== -->

    <div class="box">

        <div class="box-title">

            <i class="bi bi-clock-history"></i>

            Historique des ventes

        </div>


        <div class="table-responsive">

            <table>

                <thead>

                    <tr>

                        <th>
                            Client
                        </th>

                        <th>
                            Désignation
                        </th>

                        <th>
                            PVU
                        </th>

                        <th>
                            Quantité
                        </th>

                        <th>
                            Montant
                        </th>

                        <th>
                            Date
                        </th>

                    </tr>

                </thead>


                <tbody>


                <?php if (
                    $ventes &&
                    $ventes->num_rows > 0
                ): ?>


                    <?php while (
                        $v =
                        $ventes->fetch_assoc()
                    ): ?>


                        <tr>


                            <td>

                                <strong>

                                    <?= htmlspecialchars(
                                        extraireClient(
                                            $v["description"]
                                            ?? ""
                                        )
                                    ) ?>

                                </strong>

                            </td>


                            <td>

                                <?= htmlspecialchars(
                                    $v["produit_nom"]
                                    ?? "Article supprimé"
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

                                <strong>

                                    <?php

                                    if (
                                        !empty(
                                            $v["date_vente"]
                                        )
                                    ) {

                                        echo date(
                                            "d/m/Y H:i",
                                            strtotime(
                                                $v["date_vente"]
                                            )
                                        );

                                    } else {

                                        echo "Date inconnue";

                                    }

                                    ?>

                                </strong>

                            </td>


                        </tr>


                    <?php endwhile; ?>


                <?php else: ?>


                    <tr>

                        <td
                            colspan="6"
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



<!-- =======================================================
     JAVASCRIPT
     ======================================================= -->

<script>


/* =========================================================
   CALCULER LES MONTANTS
   ========================================================= */

function calculerTotal() {

    let lignes =
        document.querySelectorAll(
            ".article-line"
        );


    let total = 0;


    lignes.forEach(function(ligne) {

        let prix =
            parseFloat(
                ligne.querySelector(".prix").value
            ) || 0;


        let quantite =
            parseInt(
                ligne.querySelector(".quantite").value
            ) || 0;


        let montant =
            prix * quantite;


        ligne.querySelector(
            ".montant-ligne"
        ).textContent =

            new Intl.NumberFormat("fr-FR")
            .format(montant) + " FG";


        total += montant;

    });


    document.getElementById(
        "totalVente"
    ).textContent =

        new Intl.NumberFormat("fr-FR")
        .format(total) + " FG";
}



/* =========================================================
   AJOUTER UNE LIGNE
   ========================================================= */

function ajouterLigne() {

    let container =
        document.getElementById(
            "articles"
        );


    let premiere =
        container.querySelector(
            ".article-line"
        );


    let nouvelle =
        premiere.cloneNode(true);


    /* Réinitialiser */

    nouvelle.querySelector(
        ".produit-select"
    ).value = "";


    nouvelle.querySelector(
        ".prix"
    ).value = "";


    nouvelle.querySelector(
        ".quantite"
    ).value = "1";


    nouvelle.querySelector(
        ".montant-ligne"
    ).textContent = "0 FG";


    container.appendChild(
        nouvelle
    );


    calculerTotal();
}



/* =========================================================
   SUPPRIMER UNE LIGNE
   ========================================================= */

function supprimerLigne(button) {

    let lignes =
        document.querySelectorAll(
            ".article-line"
        );


    if (lignes.length <= 1) {

        alert(
            "Il faut conserver au moins un article."
        );

        return;
    }


    button.closest(
        ".article-line"
    ).remove();


    calculerTotal();
}


</script>


</body>

</html>
