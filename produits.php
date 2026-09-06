<?php

session_start();

require_once "config.php";


/* =========================================================
   CONNEXION
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
   FONCTION ARGENT
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
   AJOUTER UN NOUVEL ARTICLE
   ========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["ajouter_produit"])
) {

    $designation = trim($_POST["nom"] ?? "");
    $categorie = trim($_POST["categorie"] ?? "");
    $prix_achat = (float)($_POST["prix_achat"] ?? 0);
    $stock_initial = (int)($_POST["stock_initial"] ?? 0);
    $fournisseur = trim($_POST["fournisseur"] ?? "");


    /* -------------------------
       VÉRIFICATIONS
       ------------------------- */

    if ($designation === "") {

        $message = "La désignation est obligatoire.";
        $type = "error";

    } elseif ($prix_achat < 0) {

        $message = "Le prix d'achat est incorrect.";
        $type = "error";

    } elseif ($stock_initial < 0) {

        $message = "La quantité ne peut pas être négative.";
        $type = "error";

    } else {


        /* -------------------------
           VÉRIFIER SI EXISTE
           ------------------------- */

        $stmt = $conn->prepare(
            "SELECT id
             FROM produits
             WHERE nom = ?
             LIMIT 1"
        );

        if (!$stmt) {

            $message = "Erreur : " . $conn->error;
            $type = "error";

        } else {

            $stmt->bind_param(
                "s",
                $designation
            );

            $stmt->execute();

            $result = $stmt->get_result();

            $existe = $result->num_rows > 0;

            $stmt->close();


            if ($existe) {

                $message =
                    "Cet article existe déjà. Utilise « Enregistrer un achat ».";

                $type = "error";

            } else {


                /* -------------------------
                   TRANSACTION
                   ------------------------- */

                $conn->begin_transaction();


                try {


                    /* =====================================
                       PROCHAIN ID PRODUIT
                       ===================================== */

                    $result_id = $conn->query(
                        "SELECT COALESCE(MAX(id), 0) + 1 AS prochain_id
                         FROM produits"
                    );

                    if (!$result_id) {
                        throw new Exception($conn->error);
                    }

                    $row_id = $result_id->fetch_assoc();

                    $produit_id =
                        (int)$row_id["prochain_id"];


                    /* =====================================
                       PRIX DE VENTE
                       ===================================== */

                    $prix_vente = 0;


                    /* =====================================
                       CRÉER LE PRODUIT
                       ===================================== */

                    $stmt = $conn->prepare(
                        "INSERT INTO produits
                        (
                            id,
                            nom,
                            categorie,
                            prix_achat,
                            prix_vente,
                            stock
                        )
                        VALUES (?, ?, ?, ?, ?, ?)"
                    );

                    if (!$stmt) {
                        throw new Exception($conn->error);
                    }


                    $stmt->bind_param(
                        "issddi",
                        $produit_id,
                        $designation,
                        $categorie,
                        $prix_achat,
                        $prix_vente,
                        $stock_initial
                    );


                    if (!$stmt->execute()) {
                        throw new Exception($stmt->error);
                    }


                    $stmt->close();


                    /* =====================================
                       ENREGISTRER L'ACHAT INITIAL
                       ===================================== */

                    if ($stock_initial > 0) {


                        $date_achat =
                            date("Y-m-d H:i:s");


                        $description =
                            "ACHAT | Fournisseur : " .
                            (
                                $fournisseur !== ""
                                ? $fournisseur
                                : "Non renseigné"
                            ) .
                            " | Désignation : " .
                            $designation;


                        /* -------------------------
                           PROCHAIN ID MOUVEMENT
                           ------------------------- */

                        $result_mvt_id = $conn->query(
                            "SELECT COALESCE(MAX(id), 0) + 1 AS prochain_id
                             FROM mouvements"
                        );

                        if (!$result_mvt_id) {
                            throw new Exception($conn->error);
                        }

                        $row_mvt_id =
                            $result_mvt_id->fetch_assoc();

                        $mouvement_id =
                            (int)$row_mvt_id["prochain_id"];


                        /* -------------------------
                           INSERTION MOUVEMENT
                           ------------------------- */

                        $stmt = $conn->prepare(
                            "INSERT INTO mouvements
                            (
                                id,
                                produit_id,
                                type,
                                quantite,
                                prix,
                                description,
                                date_mouvement
                            )
                            VALUES
                            (?, ?, 'ENTREE', ?, ?, ?, ?)"
                        );


                        if (!$stmt) {
                            throw new Exception($conn->error);
                        }


                        $stmt->bind_param(
                            "iiidss",
                            $mouvement_id,
                            $produit_id,
                            $stock_initial,
                            $prix_achat,
                            $description,
                            $date_achat
                        );


                        if (!$stmt->execute()) {
                            throw new Exception($stmt->error);
                        }


                        $stmt->close();
                    }


                    /* =====================================
                       VALIDER
                       ===================================== */

                    $conn->commit();


                    $message =
                        "Article ajouté avec succès.";


                    if ($stock_initial > 0) {

                        $message .=
                            " Achat enregistré : " .
                            $stock_initial .
                            " article(s) × " .
                            argent($prix_achat) .
                            ".";

                    }


                    $type = "success";


                } catch (Exception $e) {


                    $conn->rollback();


                    $message =
                        "Impossible d'ajouter l'article : " .
                        $e->getMessage();

                    $type = "error";
                }
            }
        }
    }
}


/* =========================================================
   ENREGISTRER UN ACHAT
   ========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["ajouter_entree"])
) {

    $produit_id =
        (int)($_POST["produit_id"] ?? 0);

    $quantite =
        (int)($_POST["quantite"] ?? 0);

    $prix =
        (float)($_POST["prix"] ?? 0);

    $fournisseur =
        trim($_POST["fournisseur"] ?? "");

    $description_note =
        trim($_POST["description"] ?? "");


    /* -------------------------
       VÉRIFICATIONS
       ------------------------- */

    if ($produit_id <= 0) {

        $message = "Choisis un article.";
        $type = "error";

    } elseif ($quantite <= 0) {

        $message =
            "La quantité doit être supérieure à zéro.";

        $type = "error";

    } elseif ($prix < 0) {

        $message =
            "Le prix d'achat est incorrect.";

        $type = "error";

    } else {


        $conn->begin_transaction();


        try {


            /* =====================================
               VÉRIFIER PRODUIT
               ===================================== */

            $stmt = $conn->prepare(
                "SELECT id, nom
                 FROM produits
                 WHERE id = ?
                 LIMIT 1"
            );


            if (!$stmt) {
                throw new Exception($conn->error);
            }


            $stmt->bind_param(
                "i",
                $produit_id
            );


            $stmt->execute();


            $produit =
                $stmt
                ->get_result()
                ->fetch_assoc();


            $stmt->close();


            if (!$produit) {

                throw new Exception(
                    "Article introuvable."
                );
            }


            /* =====================================
               DATE
               ===================================== */

            $date_achat =
                date("Y-m-d H:i:s");


            /* =====================================
               DESCRIPTION
               ===================================== */

            $description =
                "ACHAT | Fournisseur : " .
                (
                    $fournisseur !== ""
                    ? $fournisseur
                    : "Non renseigné"
                ) .
                " | Désignation : " .
                $produit["nom"];


            if ($description_note !== "") {

                $description .=
                    " | Note : " .
                    $description_note;
            }


            /* =====================================
               PROCHAIN ID MOUVEMENT
               ===================================== */

            $result_mvt_id = $conn->query(
                "SELECT COALESCE(MAX(id), 0) + 1 AS prochain_id
                 FROM mouvements"
            );


            if (!$result_mvt_id) {
                throw new Exception($conn->error);
            }


            $row_mvt_id =
                $result_mvt_id->fetch_assoc();


            $mouvement_id =
                (int)$row_mvt_id["prochain_id"];


            /* =====================================
               ENREGISTRER LE MOUVEMENT
               ===================================== */

            $stmt = $conn->prepare(
                "INSERT INTO mouvements
                (
                    id,
                    produit_id,
                    type,
                    quantite,
                    prix,
                    description,
                    date_mouvement
                )
                VALUES
                (?, ?, 'ENTREE', ?, ?, ?, ?)"
            );


            if (!$stmt) {
                throw new Exception($conn->error);
            }


            $stmt->bind_param(
                "iiidss",
                $mouvement_id,
                $produit_id,
                $quantite,
                $prix,
                $description,
                $date_achat
            );


            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }


            $stmt->close();


            /* =====================================
               AUGMENTER LE STOCK
               ===================================== */

            $stmt = $conn->prepare(
                "UPDATE produits
                 SET
                    stock = stock + ?,
                    prix_achat = ?
                 WHERE id = ?"
            );


            if (!$stmt) {
                throw new Exception($conn->error);
            }


            $stmt->bind_param(
                "idi",
                $quantite,
                $prix,
                $produit_id
            );


            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }


            $stmt->close();


            /* =====================================
               VALIDER
               ===================================== */

            $conn->commit();


            $montant_achat =
                $quantite * $prix;


            $message =
                "Achat enregistré : " .
                argent($montant_achat) .
                ".";


            $type = "success";


        } catch (Exception $e) {


            $conn->rollback();


            $message =
                "L'achat n'a pas pu être enregistré : " .
                $e->getMessage();

            $type = "error";
        }
    }
}


/* =========================================================
   PRODUITS POUR LE FORMULAIRE
   ========================================================= */

$produits =
    $conn->query(
        "SELECT
            id,
            nom,
            stock
         FROM produits
         ORDER BY nom ASC"
    );


/* =========================================================
   HISTORIQUE DES ACHATS
   ========================================================= */

$mouvements =
    $conn->query(
        "SELECT
            m.id,
            m.quantite,
            m.prix,
            m.description,
            m.date_mouvement,
            p.nom AS produit_nom
         FROM mouvements m
         LEFT JOIN produits p
            ON p.id = m.produit_id
         WHERE m.type = 'ENTREE'
         ORDER BY m.id DESC
         LIMIT 50"
    );


/* =========================================================
   TOTAL DES ACHATS
   ========================================================= */

$total_achat = 0;


$result =
    $conn->query(
        "SELECT
            COALESCE(
                SUM(quantite * prix),
                0
            ) AS total
         FROM mouvements
         WHERE type = 'ENTREE'"
    );


if ($result) {

    $data =
        $result->fetch_assoc();

    $total_achat =
        (float)$data["total"];
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

<title>
    Achats - LAMBEMAH GESTION
</title>


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

    grid-template-columns: repeat(2, 1fr);

    gap: 15px;

    margin-bottom: 20px;
}


.card-stat {

    background: white;

    border-radius: 15px;

    padding: 20px;

    box-shadow:
        0 3px 15px rgba(0,0,0,.05);
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
        0 3px 15px rgba(0,0,0,.05);
}


.box-title {

    font-size: 19px;

    font-weight: bold;

    margin-bottom: 18px;
}


.help {

    color: #667085;

    font-size: 12px;
}


/* =========================================================
   FORMULAIRES
   ========================================================= */

.form-control,
.form-select {

    min-height: 45px;

    border-radius: 9px;
}


/* =========================================================
   MONTANT
   ========================================================= */

.amount {

    background: #eef5ff;

    border-radius: 10px;

    padding: 14px;

    margin-top: 15px;

    font-weight: bold;
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
            📦 Achats / Stock
        </h1>

        <p>
            Enregistre ici les marchandises achetées
            et leur stock.
        </p>

    </div>


    <!-- =====================================================
         MESSAGE
         ===================================================== -->

    <?php if ($message !== ""): ?>

        <div
            class="alert
            <?= $type === "success"
                ? "alert-success"
                : "alert-danger" ?>"
        >

            <?= htmlspecialchars($message) ?>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         STATISTIQUES
         ===================================================== -->

    <div class="stats">


        <div class="card-stat">

            <div class="label">

                Total des achats enregistrés

            </div>


            <div class="value">

                <?= argent($total_achat) ?>

            </div>

        </div>


        <div class="card-stat">

            <div class="label">

                Fonctionnement

            </div>


            <div class="value">

                Prix × Quantité

            </div>

        </div>


    </div>


    <!-- =====================================================
         AJOUTER NOUVEL ARTICLE
         ===================================================== -->

    <div class="box">


        <div class="box-title">

            <i class="bi bi-plus-circle"></i>

            Ajouter un nouvel article

        </div>


        <p class="help">

            À utiliser uniquement si l'article
            n'existe pas encore dans le stock.

        </p>


        <form method="POST">


            <input
                type="hidden"
                name="ajouter_produit"
                value="1"
            >


            <div class="row g-3">


                <div class="col-md-4">

                    <label class="form-label">
                        Désignation
                    </label>

                    <input
                        type="text"
                        name="nom"
                        class="form-control"
                        placeholder="Ex : T-shirt grand"
                        required
                    >

                </div>


                <div class="col-md-3">

                    <label class="form-label">
                        Catégorie
                    </label>

                    <input
                        type="text"
                        name="categorie"
                        class="form-control"
                        placeholder="Ex : Vêtement"
                    >

                </div>


                <div class="col-md-3">

                    <label class="form-label">
                        Prix d'achat unitaire
                    </label>

                    <input
                        type="number"
                        name="prix_achat"
                        class="form-control"
                        min="0"
                        step="1"
                        placeholder="Ex : 15000"
                        required
                    >

                </div>


                <div class="col-md-2">

                    <label class="form-label">
                        Quantité
                    </label>

                    <input
                        type="number"
                        name="stock_initial"
                        class="form-control"
                        min="0"
                        value="0"
                        required
                    >

                </div>


                <div class="col-md-6">

                    <label class="form-label">
                        Fournisseur
                    </label>

                    <input
                        type="text"
                        name="fournisseur"
                        class="form-control"
                        placeholder="Nom du fournisseur"
                    >

                </div>


            </div>


            <button
                type="submit"
                class="btn btn-primary mt-3"
            >

                <i class="bi bi-save"></i>

                Ajouter l'article

            </button>


        </form>

    </div>


    <!-- =====================================================
         ENREGISTRER UN ACHAT
         ===================================================== -->

    <div class="box">


        <div class="box-title">

            <i class="bi bi-cart-plus"></i>

            Enregistrer un achat

        </div>


        <form method="POST">


            <input
                type="hidden"
                name="ajouter_entree"
                value="1"
            >


            <div class="row g-3">


                <div class="col-md-5">

                    <label class="form-label">
                        Désignation
                    </label>

                    <select
                        name="produit_id"
                        class="form-select"
                        required
                    >

                        <option value="">
                            Choisir un article
                        </option>


                        <?php if ($produits): ?>

                            <?php while (
                                $p =
                                $produits->fetch_assoc()
                            ): ?>

                                <option
                                    value="<?= (int)$p["id"] ?>"
                                >

                                    <?= htmlspecialchars(
                                        $p["nom"]
                                    ) ?>

                                    — Stock :

                                    <?= (int)$p["stock"] ?>

                                </option>

                            <?php endwhile; ?>

                        <?php endif; ?>

                    </select>

                </div>


                <div class="col-md-3">

                    <label class="form-label">
                        Fournisseur
                    </label>

                    <input
                        type="text"
                        name="fournisseur"
                        class="form-control"
                        placeholder="Nom fournisseur"
                        required
                    >

                </div>


                <div class="col-md-2">

                    <label class="form-label">
                        Prix d'achat
                    </label>

                    <input
                        type="number"
                        name="prix"
                        id="prix"
                        class="form-control"
                        min="0"
                        step="1"
                        placeholder="Ex : 15000"
                        oninput="calculerAchat()"
                        required
                    >

                </div>


                <div class="col-md-2">

                    <label class="form-label">
                        Quantité
                    </label>

                    <input
                        type="number"
                        name="quantite"
                        id="quantite"
                        class="form-control"
                        min="1"
                        value="1"
                        oninput="calculerAchat()"
                        required
                    >

                </div>


            </div>


            <div class="amount">

                MONTANT DE L'ACHAT :

                <span id="montant">
                    0 FG
                </span>

            </div>


            <div class="mt-3">

                <label class="form-label">
                    Note (facultatif)
                </label>

                <input
                    type="text"
                    name="description"
                    class="form-control"
                    placeholder="Ex : achat livré"
                >

            </div>


            <button
                type="submit"
                class="btn btn-primary mt-3"
            >

                <i class="bi bi-check-circle"></i>

                Enregistrer l'achat

            </button>


        </form>

    </div>


    <!-- =====================================================
         STOCK ACTUEL
         ===================================================== -->

    <div class="box">


        <div class="box-title">

            <i class="bi bi-box-seam"></i>

            Stock actuel

        </div>


        <div class="table-responsive">

            <table>

                <thead>

                    <tr>

                        <th>Désignation</th>

                        <th>Catégorie</th>

                        <th>Prix d'achat</th>

                        <th>Stock</th>

                    </tr>

                </thead>


                <tbody>


                <?php

                $liste_stock =
                    $conn->query(
                        "SELECT
                            nom,
                            categorie,
                            prix_achat,
                            stock
                         FROM produits
                         ORDER BY nom ASC"
                    );

                ?>


                <?php if (
                    $liste_stock
                    && $liste_stock->num_rows > 0
                ): ?>


                    <?php while (
                        $p =
                        $liste_stock->fetch_assoc()
                    ): ?>

                        <tr>

                            <td>

                                <strong>

                                    <?= htmlspecialchars(
                                        $p["nom"]
                                    ) ?>

                                </strong>

                            </td>


                            <td>

                                <?= htmlspecialchars(
                                    $p["categorie"] ?? ""
                                ) ?>

                            </td>


                            <td>

                                <?= argent(
                                    $p["prix_achat"]
                                ) ?>

                            </td>


                            <td>

                                <strong>

                                    <?= (int)$p["stock"] ?>

                                </strong>

                            </td>

                        </tr>

                    <?php endwhile; ?>


                <?php else: ?>

                    <tr>

                        <td
                            colspan="4"
                            class="text-center"
                        >

                            Aucun article enregistré.

                       
