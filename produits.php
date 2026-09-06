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
$type_message = "";

/* =========================================================
   AJOUT D'UN ACHAT
   ========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajouter_achat"])) {

    $designation = trim($_POST["designation"] ?? "");
    $prix_achat  = (float)($_POST["prix_achat"] ?? 0);
    $quantite    = (int)($_POST["quantite"] ?? 0);
    $description = trim($_POST["description"] ?? "");

    if ($designation === "") {
        $message = "Veuillez saisir la désignation du produit.";
        $type_message = "error";

    } elseif ($prix_achat <= 0) {
        $message = "Le prix d'achat doit être supérieur à zéro.";
        $type_message = "error";

    } elseif ($quantite <= 0) {
        $message = "La quantité doit être supérieure à zéro.";
        $type_message = "error";

    } else {

        $montant = $prix_achat * $quantite;

        $conn->begin_transaction();

        try {

            /* Chercher si le produit existe déjà */
            $stmt = $conn->prepare(
                "SELECT id, nom, stock
                 FROM produits
                 WHERE LOWER(nom) = LOWER(?)
                 LIMIT 1"
            );

            if (!$stmt) {
                throw new Exception("Erreur préparation produit.");
            }

            $stmt->bind_param("s", $designation);
            $stmt->execute();

            $result = $stmt->get_result();
            $produit = $result->fetch_assoc();

            $stmt->close();

            /* =================================================
               PRODUIT EXISTANT
               ================================================= */
            if ($produit) {

                $produit_id = (int)$produit["id"];

                /* Augmenter le stock + mettre à jour le prix d'achat */
                $stmt = $conn->prepare(
                    "UPDATE produits
                     SET stock = stock + ?,
                         prix_achat = ?
                     WHERE id = ?"
                );

                if (!$stmt) {
                    throw new Exception("Erreur mise à jour produit.");
                }

                $stmt->bind_param(
                    "idi",
                    $quantite,
                    $prix_achat,
                    $produit_id
                );

                if (!$stmt->execute()) {
                    throw new Exception("Impossible de mettre à jour le produit.");
                }

                $stmt->close();

            } else {

                /* =================================================
                   NOUVEAU PRODUIT
                   ================================================= */

                $categorie = "Achat";

                /*
                 * prix_vente existe encore dans la base actuelle,
                 * mais on ne l'utilise plus dans l'application.
                 */
                $prix_vente = 0;

                $stmt = $conn->prepare(
                    "INSERT INTO produits
                    (nom, categorie, prix_achat, prix_vente, stock)
                    VALUES (?, ?, ?, ?, ?)"
                );

                if (!$stmt) {
                    throw new Exception("Erreur création produit.");
                }

                $stmt->bind_param(
                    "ssddi",
                    $designation,
                    $categorie,
                    $prix_achat,
                    $prix_vente,
                    $quantite
                );

                if (!$stmt->execute()) {
                    throw new Exception("Impossible de créer le produit.");
                }

                $produit_id = $stmt->insert_id;

                $stmt->close();
            }

            /* =================================================
               ENREGISTRER L'ACHAT DANS MOUVEMENTS
               ================================================= */

            $description_mouvement =
                "Achat : " . $designation .
                " | Prix unitaire : " .
                number_format($prix_achat, 0, ",", " ") .
                " FG | Montant : " .
                number_format($montant, 0, ",", " ") .
                " FG";

            if ($description !== "") {
                $description_mouvement .= " | " . $description;
            }

            $stmt = $conn->prepare(
                "INSERT INTO mouvements
                (produit_id, type, quantite, prix, description)
                VALUES (?, 'ENTREE', ?, ?, ?)"
            );

            if (!$stmt) {
                throw new Exception("Erreur préparation mouvement.");
            }

            $stmt->bind_param(
                "iids",
                $produit_id,
                $quantite,
                $prix_achat,
                $description_mouvement
            );

            if (!$stmt->execute()) {
                throw new Exception("Impossible d'enregistrer l'achat.");
            }

            $stmt->close();

            $conn->commit();

            $message =
                "Achat enregistré : " .
                htmlspecialchars($designation) .
                " × " . $quantite .
                " = " .
                number_format($montant, 0, ",", " ") .
                " FG";

            $type_message = "success";

        } catch (Exception $e) {

            $conn->rollback();

            $message = "L'achat n'a pas pu être enregistré.";
            $type_message = "error";
        }
    }
}


/* =========================================================
   STATISTIQUES ACHATS
   ========================================================= */

$total_achats = 0;
$nombre_achats = 0;

$result = $conn->query(
    "SELECT
        COUNT(*) AS nombre,
        COALESCE(SUM(quantite * prix), 0) AS total
     FROM mouvements
     WHERE type = 'ENTREE'"
);

if ($result) {
    $data = $result->fetch_assoc();

    $nombre_achats = (int)$data["nombre"];
    $total_achats = (float)$data["total"];
}


/* =========================================================
   STOCK TOTAL
   ========================================================= */

$stock_total = 0;

$result = $conn->query(
    "SELECT COALESCE(SUM(stock), 0) AS total
     FROM produits"
);

if ($result) {
    $data = $result->fetch_assoc();
    $stock_total = (int)$data["total"];
}


/* =========================================================
   PRODUITS
   ========================================================= */

$produits = $conn->query(
    "SELECT
        id,
        nom,
        categorie,
        prix_achat,
        stock
     FROM produits
     ORDER BY nom ASC"
);


/* =========================================================
   HISTORIQUE DES ACHATS
   ========================================================= */

$achats = $conn->query(
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
     LIMIT 100"
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

<title>LAMBEMAH GESTION - Achats</title>

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
    width: 250px;
    height: 100vh;
    background: #102a56;
    color: white;
    padding: 25px 15px;
    overflow-y: auto;
}

.logo {
    font-size: 23px;
    font-weight: bold;
    text-align: center;
    margin-bottom: 30px;
}

.logo small {
    display: block;
    font-size: 11px;
    opacity: .7;
    margin-top: 5px;
}

.nav-link {
    color: #dce8f8;
    padding: 12px 14px;
    border-radius: 10px;
    margin-bottom: 5px;
    text-decoration: none;
    display: block;
}

.nav-link:hover,
.nav-link.active {
    background: #1d4d8f;
    color: white;
}

.main {
    margin-left: 250px;
    padding: 30px;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
}

.header h1 {
    margin: 0;
    font-size: 28px;
}

.header p {
    margin: 5px 0 0;
    color: #6c757d;
}

.cards {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 18px;
    margin-bottom: 25px;
}

.card-stat {
    background: white;
    border-radius: 15px;
    padding: 20px;
    box-shadow: 0 3px 15px rgba(0,0,0,.06);
}

.card-stat .label {
    color: #6c757d;
    font-size: 14px;
}

.card-stat .value {
    font-size: 25px;
    font-weight: bold;
    margin-top: 8px;
}

.box {
    background: white;
    border-radius: 15px;
    padding: 22px;
    margin-bottom: 25px;
    box-shadow: 0 3px 15px rgba(0,0,0,.06);
}

.box h2 {
    font-size: 20px;
    margin-bottom: 20px;
}

.form-control,
.form-select {
    min-height: 46px;
}

.btn-main {
    background: #174b8f;
    color: white;
    border: 0;
    min-height: 46px;
    border-radius: 9px;
    padding: 0 20px;
}

.btn-main:hover {
    background: #123d75;
    color: white;
}

.alert {
    border-radius: 10px;
}

.table-responsive {
    border-radius: 10px;
}

.table {
    margin-bottom: 0;
}

.badge-stock {
    background: #e8f1ff;
    color: #174b8f;
    padding: 7px 10px;
    border-radius: 20px;
}

.mobile-nav {
    display: none;
}


/* MOBILE */

@media(max-width: 800px) {

    .sidebar {
        display: none;
    }

    .main {
        margin-left: 0;
        padding: 15px;
        padding-bottom: 90px;
    }

    .header {
        display: block;
    }

    .header h1 {
        font-size: 23px;
    }

    .cards {
        grid-template-columns: 1fr;
        gap: 10px;
    }

    .card-stat {
        padding: 16px;
    }

    .box {
        padding: 16px;
    }

    .mobile-nav {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: #102a56;
        z-index: 999;
        padding: 8px 4px;
    }

    .mobile-nav a {
        text-align: center;
        color: white;
        text-decoration: none;
        font-size: 11px;
    }

    .mobile-nav i {
        display: block;
        font-size: 20px;
        margin-bottom: 2px;
    }

    .table {
        font-size: 13px;
    }

}

</style>

</head>

<body>


<!-- =====================================================
     MENU PC
     ===================================================== -->

<div class="sidebar">

    <div class="logo">
        LAMBEMAH
        <small>GESTION</small>
    </div>

    <a class="nav-link" href="index.php">
        <i class="bi bi-house"></i> Accueil
    </a>

    <a class="nav-link active" href="produits.php">
        <i class="bi bi-cart"></i> Achats / Produits
    </a>

    <a class="nav-link" href="ventes.php">
        <i class="bi bi-cash-stack"></i> Ventes
    </a>

    <a class="nav-link" href="prestations.php">
        <i class="bi bi-printer"></i> Prestations
    </a>

    <a class="nav-link" href="recettes.php">
        <i class="bi bi-wallet2"></i> Recettes
    </a>

    <a class="nav-link" href="depenses.php">
        <i class="bi bi-arrow-down-circle"></i> Dépenses
    </a>

    <a class="nav-link" href="statistiques.php">
        <i class="bi bi-bar-chart"></i> Statistiques
    </a>

    <?php if ($role === "admin"): ?>

    <a class="nav-link" href="utilisateurs.php">
        <i class="bi bi-people"></i> Équipe
    </a>

    <?php endif; ?>

    <a class="nav-link" href="index.php?logout=1">
        <i class="bi bi-box-arrow-right"></i> Déconnexion
    </a>

</div>


<!-- =====================================================
     CONTENU
     ===================================================== -->

<div class="main">

    <div class="header">

        <div>
            <h1>🛒 Achats / Stock</h1>

            <p>
                Enregistrer les achats effectués pour LAMBEMAH GESTION
            </p>
        </div>

    </div>


    <!-- MESSAGE -->

    <?php if ($message !== ""): ?>

        <div class="alert
            <?php echo $type_message === "success"
                ? "alert-success"
                : "alert-danger"; ?>">

            <?php echo $message; ?>

        </div>

    <?php endif; ?>


    <!-- =================================================
         STATISTIQUES
         ================================================= -->

    <div class="cards">

        <div class="card-stat">

            <div class="label">
                Total des achats
            </div>

            <div class="value">
                <?php echo argent($total_achats); ?>
            </div>

        </div>


        <div class="card-stat">

            <div class="label">
                Nombre d'achats
            </div>

            <div class="value">
                <?php echo $nombre_achats; ?>
            </div>

        </div>


        <div class="card-stat">

            <div class="label">
                Stock total
            </div>

            <div class="value">
                <?php echo $stock_total; ?> articles
            </div>

        </div>

    </div>


    <!-- =================================================
         FORMULAIRE ACHAT
         ================================================= -->

    <div class="box">

        <h2>
            <i class="bi bi-plus-circle"></i>
            Enregistrer un achat
        </h2>

        <form method="POST">

            <div class="row g-3">

                <div class="col-md-4">

                    <label class="form-label">
                        Désignation du produit
                    </label>

                    <input
                        type="text"
                        name="designation"
                        id="designation"
                        class="form-control"
                        placeholder="Ex : T-shirt"
                        required>

                </div>


                <div class="col-md-3">

                    <label class="form-label">
                        Prix d'achat unitaire
                    </label>

                    <input
                        type="number"
                        name="prix_achat"
                        id="prix_achat"
                        class="form-control"
                        min="1"
                        step="1"
                        placeholder="Ex : 15000"
                        oninput="calculerMontant()"
                        required>

                </div>


                <div class="col-md-2">

                    <label class="form-label">
                        Quantité achetée
                    </label>

                    <input
                        type="number"
                        name="quantite"
                        id="quantite"
                        class="form-control"
                        min="1"
                        value="1"
                        oninput="calculerMontant()"
                        required>

                </div>


                <div class="col-md-3">

                    <label class="form-label">
                        Montant total
                    </label>

                    <input
                        type="text"
                        id="montant_affiche"
                        class="form-control"
                        value="0 FG"
                        readonly>

                </div>


                <div class="col-12">

                    <label class="form-label">
                        Note / détail
                    </label>

                    <input
                        type="text"
                        name="description"
                        class="form-control"
                        placeholder="Ex : Achat effectué aujourd'hui">

                </div>


                <div class="col-12">

                    <button
                        type="submit"
                        name="ajouter_achat"
                        class="btn-main">

                        <i class="bi bi-check-circle"></i>
                        Enregistrer l'achat

                    </button>

                </div>

            </div>

        </form>

    </div>


    <!-- =================================================
         STOCK ACTUEL
         ================================================= -->

    <div class="box">

        <h2>
            <i class="bi bi-box-seam"></i>
            Produits en stock
        </h2>

        <div class="table-responsive">

            <table class="table table-hover align-middle">

                <thead>

                    <tr>
                        <th>Désignation</th>
                        <th>Prix d'achat</th>
                        <th>Stock</th>
                    </tr>

                </thead>

                <tbody>

                <?php if ($produits && $produits->num_rows > 0): ?>

                    <?php while ($p = $produits->fetch_assoc()): ?>

                        <tr>

                            <td>
                                <strong>
                                    <?php
                                    echo htmlspecialchars($p["nom"]);
                                    ?>
                                </strong>
                            </td>

                            <td>
                                <?php
                                echo argent($p["prix_achat"]);
                                ?>
                            </td>

                            <td>

                                <span class="badge-stock">

                                    <?php
                                    echo (int)$p["stock"];
                                    ?>

                                    article(s)

                                </span>

                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="3" class="text-center text-muted">
                            Aucun produit enregistré.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>


    <!-- =================================================
         HISTORIQUE ACHATS
         ================================================= -->

    <div class="box">

        <h2>
            <i class="bi bi-clock-history"></i>
            Historique des achats
        </h2>

        <div class="table-responsive">

            <table class="table table-hover align-middle">

                <thead>

                    <tr>
                        <th>Désignation</th>
                        <th>Prix achat</th>
                        <th>Quantité</th>
                        <th>Montant</th>
                        <th>Date</th>
                    </tr>

                </thead>

                <tbody>

                <?php if ($achats && $achats->num_rows > 0): ?>

                    <?php while ($a = $achats->fetch_assoc()): ?>

                        <?php
                        $montant_achat =
                            (float)$a["prix"] *
                            (int)$a["quantite"];
                        ?>

                        <tr>

                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $a["produit_nom"] ?? "Produit"
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo argent($a["prix"]);
                                ?>
                            </td>

                            <td>
                                <?php
                                echo (int)$a["quantite"];
                                ?>
                            </td>

                            <td>
                                <strong>
                                    <?php
                                    echo argent($montant_achat);
                                    ?>
                                </strong>
                            </td>

                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $a["date_mouvement"]
                                );
                                ?>
                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>

                        <td
                            colspan="5"
                            class="text-center text-muted">

                            Aucun achat enregistré.

                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>


<!-- =====================================================
     MENU MOBILE
     ===================================================== -->

<div class="mobile-nav">

    <a href="index.php">
        <i class="bi bi-house"></i>
        Accueil
    </a>

    <a href="produits.php">
        <i class="bi bi-cart"></i>
        Achats
    </a>

    <a href="ventes.php">
        <i class="bi bi-cash-stack"></i>
        Ventes
    </a>

    <a href="prestations.php">
        <i class="bi bi-printer"></i>
        DTF
    </a>

</div>


<script>

function calculerMontant() {

    const prix =
        parseFloat(
            document.getElementById("prix_achat").value
        ) || 0;

    const quantite =
        parseInt(
            document.getElementById("quantite").value
        ) || 0;

    const montant = prix * quantite;

    document.getElementById("montant_affiche").value =
        montant.toLocaleString("fr-FR") + " FG";
}

</script>

</body>
</html>
