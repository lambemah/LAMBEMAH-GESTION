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
   AJOUT D'UNE DÉPENSE
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajouter_depense"])) {

    $libelle = trim($_POST["libelle"] ?? "");
    $quantite = (int)($_POST["quantite"] ?? 0);
    $prix_unitaire = (float)($_POST["prix_unitaire"] ?? 0);
    $description = trim($_POST["description"] ?? "");

    if ($libelle === "") {

        $message = "Veuillez indiquer ce que vous avez acheté.";
        $type = "error";

    } elseif ($quantite <= 0) {

        $message = "La quantité doit être supérieure à zéro.";
        $type = "error";

    } elseif ($prix_unitaire <= 0) {

        $message = "Le prix unitaire doit être supérieur à zéro.";
        $type = "error";

    } else {

        $montant = $quantite * $prix_unitaire;

        /*
         * On garde la description simple.
         * La quantité et le prix sont également
         * enregistrés dans le texte pour garder
         * toutes les informations de l'achat.
         */

        $description_finale =
            "Quantité : " . $quantite .
            " | Prix unitaire : " .
            number_format($prix_unitaire, 0, ",", " ") .
            " FG";

        if ($description !== "") {
            $description_finale .=
                " | Note : " . $description;
        }

        $stmt = $conn->prepare(
            "INSERT INTO depenses
            (libelle, montant, description)
            VALUES (?, ?, ?)"
        );

        if ($stmt) {

            $stmt->bind_param(
                "sds",
                $libelle,
                $montant,
                $description_finale
            );

            if ($stmt->execute()) {

                $message =
                    "Dépense enregistrée avec succès : "
                    . number_format($montant, 0, ",", " ")
                    . " FG.";

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


/* =========================================================
   STATISTIQUES
========================================================= */

$total_depenses = 0;
$nombre_depenses = 0;

$result = $conn->query(
    "SELECT
        COALESCE(SUM(montant), 0) AS total,
        COUNT(*) AS nombre
     FROM depenses"
);

if ($result) {

    $data = $result->fetch_assoc();

    $total_depenses = (float)$data["total"];
    $nombre_depenses = (int)$data["nombre"];
}


/* =========================================================
   DERNIÈRES DÉPENSES
========================================================= */

$depenses = $conn->query(
    "SELECT
        id,
        libelle,
        montant,
        description,
        date_depense
     FROM depenses
     ORDER BY id DESC
     LIMIT 50"
);


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

?>

<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Dépenses - LAMBEMAH GESTION</title>

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

    transition: .2s;
}

.nav a:hover {

    background:
        rgba(255,255,255,.08);

    color: white;
}

.nav a.active {

    background:
        rgba(32,184,255,.16);

    color: white;

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
   STATISTIQUES
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
        360px 1fr;

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

    padding: 12px;

    border:
        1px solid #dfe8ee;

    border-radius: 10px;

    background: #fbfdff;

    outline: none;

    font-family: Arial, sans-serif;

    font-size: 12px;
}

input:focus,
select:focus,
textarea:focus {

    border-color:
        #20b8ff;
}

textarea {

    min-height: 80px;

    resize: vertical;
}

.montant {

    padding: 14px;

    border-radius: 12px;

    background:
        linear-gradient(
            135deg,
            #eef9ff,
            #f7fcff
        );

    color: #0878b7;

    font-size: 20px;

    font-weight: bold;

    text-align: center;
}

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

    font-size: 13px;
}

.btn-primary:hover {
    opacity: .9;
}


/* =========================================================
   HISTORIQUE
========================================================= */

.expense {

    padding: 15px 0;

    border-bottom:
        1px solid #edf1f4;
}

.expense:first-child {
    padding-top: 0;
}

.expense-top {

    display: flex;

    justify-content: space-between;

    gap: 15px;
}

.expense-name {

    font-weight: bold;

    font-size: 13px;

    color: #20394c;
}

.expense-amount {

    color: #d45a5a;

    font-weight: bold;

    font-size: 13px;

    white-space: nowrap;
}

.expense-info {

    display: flex;

    flex-wrap: wrap;

    gap: 7px;

    margin-top: 8px;
}

.badge {

    background: #f0f7fb;

    color: #607482;

    padding: 5px 8px;

    border-radius: 7px;

    font-size: 9px;
}

.expense-description {

    margin-top: 8px;

    padding: 8px;

    border-radius: 8px;

    background: #f8fafc;

    color: #647481;

    font-size: 10px;
}

.expense-date {

    margin-top: 8px;

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

        padding:
            4px 7px 10px;
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

    .stat h2 {
        font-size: 17px;
    }

    .card {

        padding: 15px;

        border-radius: 16px;
    }

    .expense-top {

        align-items: flex-start;
    }

    .expense-name {
        font-size: 12px;
    }

    .expense-amount {
        font-size: 12px;
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

                🏠

                <span>
                    Accueil
                </span>

            </a>

        </li>


        <li>

            <a href="produits.php">

                📦

                <span>
                    Produits
                </span>

            </a>

        </li>


        <li>

            <a href="ventes.php">

                💰

                <span>
                    Ventes
                </span>

            </a>

        </li>


        <li>

            <a href="prestations.php">

                🖨️

                <span>
                    Prestations
                </span>

            </a>

        </li>


        <li>

            <a href="recettes.php">

                💵

                <span>
                    Recettes
                </span>

            </a>

        </li>


        <li>

            <a
                href="depenses.php"
                class="active"
            >

                💸

                <span>
                    Dépenses
                </span>

            </a>

        </li>


        <li>

            <a href="statistiques.php">

                📊

                <span>
                    Statistiques
                </span>

            </a>

        </li>


        <?php if ($role === "admin"): ?>

        <li>

            <a href="utilisateurs.php">

                👥

                <span>
                    Équipe
                </span>

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
                💸 Dépenses
            </h1>

            <p>
                Enregistre tes achats et suis toutes tes dépenses.
            </p>

        </div>


        <div class="avatar">

            <?= strtoupper(
                substr($nom, 0, 1)
            ) ?>

        </div>

    </div>


    <?php if ($message !== ""): ?>

        <div
            class="message <?= htmlspecialchars($type) ?>"
        >

            <?= htmlspecialchars($message) ?>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         STATISTIQUES
    ====================================================== -->

    <div class="cards">

        <div class="stat">

            <small>
                TOTAL DES DÉPENSES
            </small>

            <h2>
                <?= argent($total_depenses) ?>
            </h2>

        </div>


        <div class="stat">

            <small>
                NOMBRE DE DÉPENSES
            </small>

            <h2>
                <?= $nombre_depenses ?>
            </h2>

        </div>

    </div>


    <!-- =====================================================
         CONTENU
    ====================================================== -->

    <div class="content">


        <!-- =================================================
             FORMULAIRE
        ================================================== -->

        <div class="card">

            <h2>
                ➕ Nouvelle dépense
            </h2>

            <p>
                Enregistre un achat, un transport, du DTF ou toute autre dépense.
            </p>


            <form method="POST">

                <input
                    type="hidden"
                    name="ajouter_depense"
                    value="1"
                >


                <div class="group">

                    <label>
                        ACHAT / DÉPENSE
                    </label>

                    <input
                        type="text"
                        name="libelle"
                        placeholder="Ex : T-shirts adultes, DTF, transport..."
                        required
                    >

                </div>


                <div class="group">

                    <label>
                        QUANTITÉ
                    </label>

                    <input
                        type="number"
                        name="quantite"
                        id="quantite"
                        value="1"
                        min="1"
                        required
                        oninput="calculer()"
                    >

                </div>


                <div class="group">

                    <label>
                        PRIX UNITAIRE
                    </label>

                    <input
                        type="number"
                        name="prix_unitaire"
                        id="prix_unitaire"
                        placeholder="Ex : 5000"
                        min="1"
                        step="500"
                        required
                        oninput="calculer()"
                    >

                </div>


                <div class="group">

                    <label>
                        MONTANT TOTAL
                    </label>

                    <div
                        class="montant"
                        id="montant"
                    >
                        0 FG
                    </div>

                </div>


                <div class="group">

                    <label>
                        NOTE / DÉTAIL
                    </label>

                    <textarea
                        name="description"
                        placeholder="Ex : Achat de 15 T-shirts adultes chez le fournisseur..."
                    ></textarea>

                </div>


                <button
                    type="submit"
                    class="btn-primary"
                >

                    💾 Enregistrer la dépense

                </button>

            </form>

        </div>


        <!-- =================================================
             HISTORIQUE
        ================================================== -->

        <div class="card">

            <h2>
                📋 Historique des dépenses
            </h2>

            <p>
                Les 50 dernières dépenses enregistrées.
            </p>


            <?php if (
                $depenses &&
                $depenses->num_rows > 0
            ): ?>


                <?php while (
                    $d =
                    $depenses->fetch_assoc()
                ): ?>


                    <?php

                    /*
                     * Extraction de la quantité
                     * et du prix unitaire depuis
                     * la description.
                     */

                    $quantite_ligne = 1;
                    $prix_ligne = 0;

                    if (
                        preg_match(
                            '/Quantité : ([0-9]+)/',
                            $d["description"],
                            $match_qte
                        )
                    ) {

                        $quantite_ligne =
                            (int)$match_qte[1];
                    }

                    if (
                        preg_match(
                            '/Prix unitaire : ([0-9 ]+)/',
                            $d["description"],
                            $match_prix
                        )
                    ) {

                        $prix_ligne =
                            (float)str_replace(
                                " ",
                                "",
                                $match_prix[1]
                            );
                    }

                    ?>


                    <div class="expense">


                        <div class="expense-top">

                            <div class="expense-name">

                                💸

                                <?= htmlspecialchars(
                                    $d["libelle"]
                                ) ?>

                            </div>


                            <div class="expense-amount">

                                <?= argent(
                                    $d["montant"]
                                ) ?>

                            </div>

                        </div>


                        <div class="expense-info">

                            <span class="badge">

                                Quantité :
                                <?= $quantite_ligne ?>

                            </span>


                            <span class="badge">

                                Prix unitaire :
                                <?= argent(
                                    $prix_ligne
                                ) ?>

                            </span>

                        </div>


                        <?php if (
                            !empty($d["description"])
                        ): ?>

                            <div class="expense-description">

                                <?= htmlspecialchars(
                                    $d["description"]
                                ) ?>

                            </div>

                        <?php endif; ?>


                        <div class="expense-date">

                            📅

                            <?= htmlspecialchars(
                                $d["date_depense"]
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

                    💸

                    <br><br>

                    Aucune dépense enregistrée pour le moment.

                </div>

            <?php endif; ?>

        </div>

    </div>

</main>


<script>

/* =========================================================
   CALCUL AUTOMATIQUE
========================================================= */

function calculer() {

    const quantite =
        parseFloat(
            document.getElementById("quantite").value
        ) || 0;

    const prix =
        parseFloat(
            document.getElementById("prix_unitaire").value
        ) || 0;

    const total =
        quantite * prix;

    document.getElementById("montant").innerText =
        new Intl.NumberFormat("fr-FR").format(total)
        + " FG";
}

</script>

</body>

</html>
