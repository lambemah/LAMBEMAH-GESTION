<?php
session_start();
require_once "config.php";

/* =========================================================
   AUTHENTIFICATION
========================================================= */

if (!isset($_SESSION["id"])) {
    header("Location: index.php");
    exit;
}

$role = $_SESSION["role"] ?? "lecture";

/* Seul l'administrateur gère les utilisateurs */
if ($role !== "admin") {
    http_response_code(403);
    die("
        <div style='font-family:Arial;text-align:center;padding:60px'>
            <h2>⛔ Accès refusé</h2>
            <p>Seul l'administrateur peut gérer les utilisateurs.</p>
            <a href='index.php'>← Retour à l'accueil</a>
        </div>
    ");
}

$message = "";
$type_message = "";

/* =========================================================
   RÔLES AUTORISÉS
========================================================= */

$roles_autorises = [
    "admin",
    "gestion",
    "vendeur",
    "comptable",
    "lecture"
];

/* =========================================================
   TRAITEMENT DES FORMULAIRES
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";

    /* =====================================================
       AJOUTER
    ===================================================== */

    if ($action === "ajouter") {

        $nom = trim($_POST["nom"] ?? "");
        $username = trim($_POST["username"] ?? "");
        $mot_de_passe = trim($_POST["mot_de_passe"] ?? "");
        $role_nouveau = trim($_POST["role"] ?? "lecture");

        if ($nom === "" || $username === "" || $mot_de_passe === "") {

            $message = "Veuillez remplir tous les champs obligatoires.";
            $type_message = "error";

        } elseif (!in_array($role_nouveau, $roles_autorises, true)) {

            $message = "Rôle invalide.";
            $type_message = "error";

        } elseif (strlen($mot_de_passe) < 4) {

            $message = "Le mot de passe doit contenir au moins 4 caractères.";
            $type_message = "error";

        } else {

            /* Vérifier si le nom d'utilisateur existe */
            $verification = $conn->prepare(
                "SELECT id FROM utilisateurs WHERE username = ? LIMIT 1"
            );

            if (!$verification) {

                $message = "Erreur de vérification.";
                $type_message = "error";

            } else {

                $verification->bind_param("s", $username);
                $verification->execute();

                $resultat = $verification->get_result();

                if ($resultat->num_rows > 0) {

                    $message = "Ce nom d'utilisateur existe déjà.";
                    $type_message = "error";

                } else {

                    /*
                     * Certains anciens champs ID de la base
                     * ne sont pas AUTO_INCREMENT.
                     * On génère donc nous-même le prochain ID.
                     */
                    $res_id = $conn->query(
                        "SELECT COALESCE(MAX(id), 0) + 1 AS prochain_id
                         FROM utilisateurs"
                    );

                    $ligne_id = $res_id ? $res_id->fetch_assoc() : null;
                    $nouvel_id = (int)($ligne_id["prochain_id"] ?? 1);

                    /* Sécurité supplémentaire */
                    if ($nouvel_id <= 0) {
                        $nouvel_id = 1;
                    }

                    /* Hachage du mot de passe */
                    $mot_de_passe_hash = password_hash(
                        $mot_de_passe,
                        PASSWORD_DEFAULT
                    );

                    $stmt = $conn->prepare(
                        "INSERT INTO utilisateurs
                        (id, nom, username, mot_de_passe, role)
                        VALUES (?, ?, ?, ?, ?)"
                    );

                    if (!$stmt) {

                        $message = "Erreur lors de la préparation de l'ajout.";
                        $type_message = "error";

                    } else {

                        $stmt->bind_param(
                            "issss",
                            $nouvel_id,
                            $nom,
                            $username,
                            $mot_de_passe_hash,
                            $role_nouveau
                        );

                        if ($stmt->execute()) {

                            $message = "Utilisateur ajouté avec succès.";
                            $type_message = "success";

                        } else {

                            $message = "Erreur lors de l'ajout de l'utilisateur.";
                            $type_message = "error";
                        }

                        $stmt->close();
                    }
                }

                $verification->close();
            }
        }
    }

    /* =====================================================
       MODIFIER
    ===================================================== */

    elseif ($action === "modifier") {

        $id = (int)($_POST["id"] ?? 0);
        $nom = trim($_POST["nom"] ?? "");
        $username = trim($_POST["username"] ?? "");
        $mot_de_passe = trim($_POST["mot_de_passe"] ?? "");
        $role_nouveau = trim($_POST["role"] ?? "lecture");

        if ($id <= 0 || $nom === "" || $username === "") {

            $message = "Informations invalides.";
            $type_message = "error";

        } elseif (!in_array($role_nouveau, $roles_autorises, true)) {

            $message = "Rôle invalide.";
            $type_message = "error";

        } elseif ($id === (int)$_SESSION["id"] && $role_nouveau !== "admin") {

            /*
             * Empêche l'administrateur connecté de se retirer
             * lui-même ses droits administrateur.
             */
            $message = "Tu ne peux pas retirer tes propres droits administrateur.";
            $type_message = "error";

        } else {

            /* Vérifier que le username n'est pas utilisé par quelqu'un d'autre */
            $verification = $conn->prepare(
                "SELECT id
                 FROM utilisateurs
                 WHERE username = ?
                 AND id <> ?
                 LIMIT 1"
            );

            if (!$verification) {

                $message = "Erreur lors de la vérification.";
                $type_message = "error";

            } else {

                $verification->bind_param(
                    "si",
                    $username,
                    $id
                );

                $verification->execute();

                $resultat = $verification->get_result();

                if ($resultat->num_rows > 0) {

                    $message = "Ce nom d'utilisateur est déjà utilisé.";
                    $type_message = "error";

                } else {

                    /*
                     * Si aucun nouveau mot de passe n'est fourni,
                     * on conserve l'ancien.
                     */

                    if ($mot_de_passe === "") {

                        $stmt = $conn->prepare(
                            "UPDATE utilisateurs
                             SET nom = ?, username = ?, role = ?
                             WHERE id = ?"
                        );

                        if ($stmt) {

                            $stmt->bind_param(
                                "sssi",
                                $nom,
                                $username,
                                $role_nouveau,
                                $id
                            );
                        }

                    } else {

                        if (strlen($mot_de_passe) < 4) {

                            $stmt = null;

                            $message = "Le nouveau mot de passe doit contenir au moins 4 caractères.";
                            $type_message = "error";

                        } else {

                            $mot_de_passe_hash = password_hash(
                                $mot_de_passe,
                                PASSWORD_DEFAULT
                            );

                            $stmt = $conn->prepare(
                                "UPDATE utilisateurs
                                 SET nom = ?, username = ?, mot_de_passe = ?, role = ?
                                 WHERE id = ?"
                            );

                            if ($stmt) {

                                $stmt->bind_param(
                                    "ssssi",
                                    $nom,
                                    $username,
                                    $mot_de_passe_hash,
                                    $role_nouveau,
                                    $id
                                );
                            }
                        }
                    }

                    if (isset($stmt) && $stmt) {

                        if ($stmt->execute()) {

                            $message = "Utilisateur modifié avec succès.";
                            $type_message = "success";

                        } else {

                            $message = "Erreur lors de la modification.";
                            $type_message = "error";
                        }

                        $stmt->close();
                    }
                }

                $verification->close();
            }
        }
    }

    /* =====================================================
       SUPPRIMER
    ===================================================== */

    elseif ($action === "supprimer") {

        $id = (int)($_POST["id"] ?? 0);

        /* Impossible de supprimer son propre compte */
        if ($id === (int)$_SESSION["id"]) {

            $message = "Tu ne peux pas supprimer ton propre compte.";
            $type_message = "error";

        } elseif ($id <= 0) {

            $message = "Utilisateur invalide.";
            $type_message = "error";

        } else {

            $stmt = $conn->prepare(
                "DELETE FROM utilisateurs WHERE id = ?"
            );

            if (!$stmt) {

                $message = "Erreur lors de la suppression.";
                $type_message = "error";

            } else {

                $stmt->bind_param("i", $id);

                if ($stmt->execute()) {

                    if ($stmt->affected_rows > 0) {

                        $message = "Utilisateur supprimé avec succès.";
                        $type_message = "success";

                    } else {

                        $message = "Utilisateur introuvable.";
                        $type_message = "error";
                    }

                } else {

                    $message = "Erreur lors de la suppression.";
                    $type_message = "error";
                }

                $stmt->close();
            }
        }
    }
}

/* =========================================================
   UTILISATEUR À MODIFIER
========================================================= */

$modifier = null;

if (isset($_GET["modifier"])) {

    $id_modifier = (int)$_GET["modifier"];

    if ($id_modifier > 0) {

        $stmt = $conn->prepare(
            "SELECT id, nom, username, role
             FROM utilisateurs
             WHERE id = ?
             LIMIT 1"
        );

        if ($stmt) {

            $stmt->bind_param("i", $id_modifier);
            $stmt->execute();

            $result = $stmt->get_result();

            if ($result && $result->num_rows === 1) {
                $modifier = $result->fetch_assoc();
            }

            $stmt->close();
        }
    }
}

/* =========================================================
   RECHERCHE
========================================================= */

$recherche = trim($_GET["recherche"] ?? "");

if ($recherche !== "") {

    $motif = "%" . $recherche . "%";

    $stmt = $conn->prepare(
        "SELECT id, nom, username, role, date_creation
         FROM utilisateurs
         WHERE nom LIKE ?
            OR username LIKE ?
            OR role LIKE ?
         ORDER BY id DESC"
    );

    $stmt->bind_param(
        "sss",
        $motif,
        $motif,
        $motif
    );

    $stmt->execute();

    $utilisateurs = $stmt->get_result();

} else {

    $utilisateurs = $conn->query(
        "SELECT id, nom, username, role, date_creation
         FROM utilisateurs
         ORDER BY id DESC"
    );
}

/* =========================================================
   LABELS DES RÔLES
========================================================= */

function nomRole($role)
{
    $roles = [
        "admin"     => "👑 Administrateur",
        "gestion"   => "💼 Gestionnaire",
        "vendeur"   => "💰 Vendeur",
        "comptable" => "🧾 Comptable",
        "lecture"   => "👁️ Lecture seule"
    ];

    return $roles[$role] ?? $role;
}

function classeRole($role)
{
    switch ($role) {

        case "admin":
            return "role-admin";

        case "gestion":
            return "role-gestion";

        case "vendeur":
            return "role-vendeur";

        case "comptable":
            return "role-comptable";

        default:
            return "role-lecture";
    }
}

?>
<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Utilisateurs - LAMBEMAH GESTION</title>

<style>

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    background: #f4f9fc;
    color: #263746;
}

/* =========================================================
   SIDEBAR
========================================================= */

.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    bottom: 0;
    width: 235px;
    background: linear-gradient(180deg, #173b63, #155b87);
    padding: 20px 13px;
    color: white;
    z-index: 10;
}

.brand {
    padding: 4px 12px 22px;
}

.brand-logo {
    width: 58px;
    height: 58px;
    object-fit: contain;
    display: block;
    margin-bottom: 8px;
}

.brand h2 {
    font-size: 19px;
    letter-spacing: .4px;
}

.brand span {
    display: block;
    margin-top: 4px;
    font-size: 10px;
    opacity: .75;
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
    gap: 10px;
    padding: 11px 12px;
    color: white;
    text-decoration: none;
    border-radius: 10px;
    font-size: 13px;
    transition: .2s;
}

.nav a:hover,
.nav a.active {
    background: rgba(255,255,255,.15);
}

.sidebar-bottom {
    position: absolute;
    bottom: 18px;
    left: 13px;
    right: 13px;
}

.logout {
    display: block;
    color: white;
    text-decoration: none;
    padding: 11px 12px;
    border-radius: 10px;
    background: rgba(255,255,255,.09);
    font-size: 13px;
}

/* =========================================================
   MAIN
========================================================= */

.main {
    margin-left: 235px;
    padding: 25px;
}

.header {
    margin-bottom: 18px;
}

.header h1 {
    font-size: 25px;
    color: #173b63;
}

.header p {
    margin-top: 5px;
    color: #81919a;
    font-size: 13px;
}

/* =========================================================
   MESSAGES
========================================================= */

.message {
    padding: 11px 14px;
    border-radius: 9px;
    margin-bottom: 16px;
    font-size: 13px;
}

.success {
    background: #eaf8f1;
    color: #16834d;
    border: 1px solid #c9eedb;
}

.error {
    background: #fff0f0;
    color: #c62828;
    border: 1px solid #ffd0d0;
}

/* =========================================================
   GRID
========================================================= */

.grid {
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 18px;
}

/* =========================================================
   CARD
========================================================= */

.card {
    background: white;
    border-radius: 15px;
    padding: 19px;
    box-shadow: 0 4px 18px rgba(30,80,110,.07);
    border: 1px solid #e8f0f4;
}

.card h2 {
    color: #173b63;
    font-size: 16px;
    margin-bottom: 17px;
}

/* =========================================================
   FORM
========================================================= */

.group {
    margin-bottom: 13px;
}

.group label {
    display: block;
    font-size: 11px;
    font-weight: bold;
    margin-bottom: 6px;
    color: #526572;
}

.group input,
.group select {
    width: 100%;
    padding: 10px 11px;
    border: 1px solid #d9e6ec;
    border-radius: 9px;
    font-size: 13px;
    outline: none;
    background: white;
}

.group input:focus,
.group select:focus {
    border-color: #168dcc;
    box-shadow: 0 0 0 2px rgba(22,141,204,.08);
}

button {
    border: none;
    border-radius: 9px;
    padding: 9px 13px;
    cursor: pointer;
    font-weight: bold;
    font-size: 12px;
}

.btn-primary {
    width: 100%;
    background: #168dcc;
    color: white;
}

.btn-primary:hover {
    background: #0e78ad;
}

.btn-edit {
    background: #eaf7ff;
    color: #168dcc;
}

.btn-delete {
    background: #fff0f0;
    color: #d33;
}

.btn-cancel {
    display: inline-block;
    margin-top: 10px;
    color: #168dcc;
    text-decoration: none;
    font-size: 12px;
}

/* =========================================================
   ROLES
========================================================= */

.roles {
    background: #f5faff;
    padding: 12px;
    border-radius: 10px;
    margin-top: 15px;
    font-size: 11px;
    line-height: 1.8;
    border: 1px solid #e5f0f5;
}

.roles strong {
    color: #173b63;
}

/* =========================================================
   SEARCH
========================================================= */

.search-box {
    display: flex;
    gap: 8px;
    margin-bottom: 15px;
}

.search-box input {
    flex: 1;
    padding: 9px 11px;
    border: 1px solid #d9e6ec;
    border-radius: 9px;
    font-size: 12px;
    outline: none;
}

.search-box button {
    background: #173b63;
    color: white;
    min-width: 75px;
}

.clear-search {
    display: flex;
    align-items: center;
    text-decoration: none;
    padding: 0 8px;
    color: #8b99a2;
    font-size: 11px;
}

/* =========================================================
   TABLE
========================================================= */

.table-container {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 620px;
}

th,
td {
    padding: 11px 9px;
    text-align: left;
    border-bottom: 1px solid #edf2f5;
    font-size: 12px;
}

th {
    color: #89969d;
    font-size: 10px;
    font-weight: bold;
}

td strong {
    color: #263746;
}

.badge {
    display: inline-block;
    padding: 5px 8px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: bold;
}

.role-admin {
    background: #fff6dc;
    color: #9a7110;
}

.role-gestion {
    background: #eaf3ff;
    color: #1766a3;
}

.role-vendeur {
    background: #eafaf1;
    color: #16834d;
}

.role-comptable {
    background: #f2edff;
    color: #6951a8;
}

.role-lecture {
    background: #f0f3f5;
    color: #66747d;
}

.actions {
    display: flex;
    gap: 6px;
}

.date {
    color: #89969d;
    font-size: 11px;
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 950px) {

    .grid {
        grid-template-columns: 1fr;
    }

}

@media (max-width: 700px) {

    .sidebar {
        position: relative;
        width: 100%;
        padding: 12px;
    }

    .brand {
        padding: 3px 8px 12px;
    }

    .brand-logo {
        width: 48px;
        height: 48px;
    }

    .brand h2 {
        font-size: 17px;
    }

    .nav {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 4px;
    }

    .nav li {
        margin: 0;
    }

    .nav a {
        flex-direction: column;
        justify-content: center;
        text-align: center;
        padding: 8px 4px;
        font-size: 9px;
        gap: 3px;
    }

    .sidebar-bottom {
        position: static;
        margin-top: 8px;
    }

    .logout {
        text-align: center;
        font-size: 11px;
        padding: 8px;
    }

    .main {
        margin-left: 0;
        padding: 14px;
    }

    .header h1 {
        font-size: 21px;
    }

    .header p {
        font-size: 11px;
    }

    .card {
        padding: 15px;
        border-radius: 12px;
    }

    .card h2 {
        font-size: 15px;
    }

    .search-box {
        flex-wrap: wrap;
    }

    .search-box input {
        min-width: 0;
        width: 100%;
    }

    .search-box button {
        flex: 1;
    }

    .clear-search {
        justify-content: center;
        padding: 8px;
    }
}

</style>

</head>

<body>

<!-- =====================================================
     SIDEBAR
===================================================== -->

<aside class="sidebar">

    <div class="brand">

        <img
            src="assets/logo.png"
            alt="LAMBEMAH GESTION"
            class="brand-logo"
            onerror="this.style.display='none';"
        >

        <h2>LAMBEMAH</h2>

        <span>GESTION • PRESTATION</span>

    </div>

    <ul class="nav">

        <li>
            <a href="index.php">
                🏠 Accueil
            </a>
        </li>

        <li>
            <a href="produits.php">
                📦 Produits
            </a>
        </li>

        <li>
            <a href="ventes.php">
                💰 Ventes
            </a>
        </li>

        <li>
            <a href="prestations.php">
                🖨️ Prestations
            </a>
        </li>

        <li>
            <a href="depenses.php">
                💸 Dépenses
            </a>
        </li>

        <li>
            <a href="recettes.php">
                💵 Recettes
            </a>
        </li>

        <li>
            <a href="statistiques.php">
                📊 Statistiques
            </a>
        </li>

        <li>
            <a href="utilisateurs.php" class="active">
                👥 Utilisateurs
            </a>
        </li>

    </ul>

    <div class="sidebar-bottom">

        <a
            class="logout"
            href="index.php?logout=1"
        >
            🚪 Déconnexion
        </a>

    </div>

</aside>


<!-- =====================================================
     CONTENU
===================================================== -->

<main class="main">

    <div class="header">

        <h1>Utilisateurs 👥</h1>

        <p>
            Gestion des accès à LAMBEMAH GESTION.
        </p>

    </div>


    <?php if ($message !== ""): ?>

        <div class="message <?= htmlspecialchars($type_message) ?>">

            <?= htmlspecialchars($message) ?>

        </div>

    <?php endif; ?>


    <div class="grid">


        <!-- =================================================
             FORMULAIRE
        ================================================= -->

        <div class="card">

            <?php if ($modifier): ?>

                <h2>✏️ Modifier l'utilisateur</h2>

                <form method="POST">

                    <input
                        type="hidden"
                        name="action"
                        value="modifier"
                    >

                    <input
                        type="hidden"
                        name="id"
                        value="<?= (int)$modifier["id"] ?>"
                    >


                    <div class="group">

                        <label>Nom complet</label>

                        <input
                            type="text"
                            name="nom"
                            value="<?= htmlspecialchars($modifier["nom"]) ?>"
                            required
                        >

                    </div>


                    <div class="group">

                        <label>Nom d'utilisateur</label>

                        <input
                            type="text"
                            name="username"
                            value="<?= htmlspecialchars($modifier["username"]) ?>"
                            required
                        >

                    </div>


                    <div class="group">

                        <label>Nouveau mot de passe</label>

                        <input
                            type="password"
                            name="mot_de_passe"
                            placeholder="Laisser vide pour conserver l'ancien"
                        >

                    </div>


                    <div class="group">

                        <label>Droits</label>

                        <select name="role">

                            <option
                                value="lecture"
                                <?= $modifier["role"] === "lecture" ? "selected" : "" ?>
                            >
                                👁️ Lecture seule
                            </option>

                            <option
                                value="vendeur"
                                <?= $modifier["role"] === "vendeur" ? "selected" : "" ?>
                            >
                                💰 Vendeur
                            </option>

                            <option
                                value="comptable"
                                <?= $modifier["role"] === "comptable" ? "selected" : "" ?>
                            >
                                🧾 Comptable
                            </option>

                            <option
                                value="gestion"
                                <?= $modifier["role"] === "gestion" ? "selected" : "" ?>
                            >
                                💼 Gestionnaire
                            </option>

                            <option
                                value="admin"
                                <?= $modifier["role"] === "admin" ? "selected" : "" ?>
                            >
                                👑 Administrateur
                            </option>

                        </select>

                    </div>


                    <button
                        class="btn-primary"
                        type="submit"
                    >
                        💾 Enregistrer
                    </button>

                </form>


                <a
                    href="utilisateurs.php"
                    class="btn-cancel"
                >
                    ← Annuler
                </a>


            <?php else: ?>


                <h2>➕ Ajouter une personne</h2>

                <form method="POST">

                    <input
                        type="hidden"
                        name="action"
                        value="ajouter"
                    >


                    <div class="group">

                        <label>Nom complet</label>

                        <input
                            type="text"
                            name="nom"
                            placeholder="Ex : Ibrahima Konaté"
                            required
                        >

                    </div>


                    <div class="group">

                        <label>Nom d'utilisateur</label>

                        <input
                            type="text"
                            name="username"
                            placeholder="Ex : ibrahima"
                            required
                        >

                    </div>


                    <div class="group">

                        <label>Mot de passe</label>

                        <input
                            type="password"
                            name="mot_de_passe"
                            placeholder="Minimum 4 caractères"
                            required
                        >

                    </div>


                    <div class="group">

                        <label>Droits accordés</label>

                        <select name="role">

                            <option value="lecture">
                                👁️ Lecture seule
                            </option>

                            <option value="vendeur">
                                💰 Vendeur
                            </option>

                            <option value="comptable">
                                🧾 Comptable
                            </option>

                            <option value="gestion">
                                💼 Gestionnaire
                            </option>

                            <option value="admin">
                                👑 Administrateur
                            </option>

                        </select>

                    </div>


                    <button
                        class="btn-primary"
                        type="submit"
                    >
                        ➕ Créer l'utilisateur
                    </button>

                </form>


                <div class="roles">

                    <strong>👑 Administrateur</strong> : accès complet.<br>

                    <strong>💼 Gestionnaire</strong> : gestion de l'activité.<br>

                    <strong>💰 Vendeur</strong> : produits et ventes.<br>

                    <strong>🧾 Comptable</strong> : recettes, dépenses et statistiques.<br>

                    <strong>👁️ Lecture seule</strong> : consultation uniquement.

                </div>

            <?php endif; ?>

        </div>


        <!-- =================================================
             LISTE
        ================================================= -->

        <div class="card">

            <h2>👥 Équipe LAMBEMAH GESTION</h2>


            <!-- RECHERCHE -->

            <form
                method="GET"
                class="search-box"
            >

                <input
                    type="text"
                    name="recherche"
                    value="<?= htmlspecialchars($recherche) ?>"
                    placeholder="Rechercher un nom, identifiant ou rôle..."
                >

                <button type="submit">
                    🔎 Rechercher
                </button>

                <?php if ($recherche !== ""): ?>

                    <a
                        href="utilisateurs.php"
                        class="clear-search"
                    >
                        ✕ Effacer
                    </a>

                <?php endif; ?>

            </form>


            <div class="table-container">

                <table>

                    <thead>

                        <tr>

                            <th>NOM</th>

                            <th>IDENTIFIANT</th>

                            <th>DROITS</th>

                            <th>CRÉÉ LE</th>

                            <th>ACTIONS</th>

                        </tr>

                    </thead>


                    <tbody>

                    <?php if ($utilisateurs && $utilisateurs->num_rows > 0): ?>

                        <?php while ($u = $utilisateurs->fetch_assoc()): ?>

                            <tr>

                                <td>

                                    <strong>
                                        <?= htmlspecialchars($u["nom"]) ?>
                                    </strong>

                                </td>


                                <td>

                                    <?= htmlspecialchars($u["username"]) ?>

                                </td>


                                <td>

                                    <span
                                        class="badge <?= htmlspecialchars(classeRole($u["role"])) ?>"
                                    >
                                        <?= htmlspecialchars(nomRole($u["role"])) ?>
                                    </span>

                                </td>


                                <td class="date">

                                    <?php

                                    if (!empty($u["date_creation"])) {

                                        $timestamp = strtotime($u["date_creation"]);

                                        echo $timestamp
                                            ? date("d/m/Y", $timestamp)
                                            : "-";

                                    } else {

                                        echo "-";
                                    }

                                    ?>

                                </td>


                                <td>

                                    <div class="actions">

                                        <!-- MODIFIER -->

                                        <a
                                            href="utilisateurs.php?modifier=<?= (int)$u["id"] ?>"
                                            style="text-decoration:none;"
                                            title="Modifier"
                                        >

                                            <button
                                                type="button"
                                                class="btn-edit"
                                            >
                                                ✏️
                                            </button>

                                        </a>


                                        <!-- SUPPRIMER -->

                                        <?php if ((int)$u["id"] !== (int)$_SESSION["id"]): ?>

                                            <form
                                                method="POST"
                                                onsubmit="return confirm('Supprimer cet utilisateur ? Cette action est définitive.');"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="supprimer"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?= (int)$u["id"] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn-delete"
                                                    title="Supprimer"
                                                >
                                                    🗑️
                                                </button>

                                            </form>

                                        <?php endif; ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>

                            <td
                                colspan="5"
                                style="text-align:center;color:#89969d;padding:25px;"
                            >
                                <?= $recherche !== ""
                                    ? "Aucun utilisateur trouvé."
                                    : "Aucun utilisateur."
                                ?>
                            </td>

                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</main>

</body>
</html>
