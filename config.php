<?php
session_start();
require_once "config.php";

$message = "";

// Connexion
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"] ?? "");
    $password = trim($_POST["password"] ?? "");

    if ($username === "" || $password === "") {
        $message = "Veuillez remplir tous les champs.";
    } else {
        $stmt = $conn->prepare(
            "SELECT id, nom, username, mot_de_passe, role 
             FROM utilisateurs 
             WHERE username = ? 
             LIMIT 1"
        );

        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if ($password === $user["mot_de_passe"]) {
                $_SESSION["id"] = $user["id"];
                $_SESSION["nom"] = $user["nom"];
                $_SESSION["username"] = $user["username"];
                $_SESSION["role"] = $user["role"];

                header("Location: index.php");
                exit;
            } else {
                $message = "Nom d'utilisateur ou mot de passe incorrect.";
            }
        } else {
            $message = "Nom d'utilisateur ou mot de passe incorrect.";
        }

        $stmt->close();
    }
}

// Déconnexion
if (isset($_GET["logout"])) {
    session_unset();
    session_destroy();

    header("Location: index.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>LAMBEMAH GESTION</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            min-height: 100vh;
            background: linear-gradient(135deg, #e8f7ff, #ffffff);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 430px;
        }

        .logo {
            text-align: center;
            margin-bottom: 25px;
        }

        .logo h1 {
            color: #168dcc;
            font-size: 32px;
            margin-bottom: 8px;
        }

        .logo p {
            color: #777;
            font-size: 15px;
        }

        .card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 35px rgba(0, 0, 0, 0.08);
        }

        .card h2 {
            text-align: center;
            color: #333;
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            color: #444;
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 14px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 16px;
            outline: none;
        }

        input:focus {
            border-color: #168dcc;
        }

        button {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 10px;
            background: #168dcc;
            color: white;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #0d78ad;
        }

        .message {
            background: #ffecec;
            color: #c62828;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 18px;
            text-align: center;
        }

        .welcome {
            text-align: center;
        }

        .welcome h2 {
            color: #168dcc;
            margin-bottom: 10px;
        }

        .welcome p {
            color: #666;
            margin-bottom: 25px;
        }

        .menu {
            display: grid;
            gap: 12px;
        }

        .menu a {
            display: block;
            padding: 15px;
            background: #f0faff;
            color: #168dcc;
            text-decoration: none;
            border-radius: 10px;
            font-weight: bold;
        }

        .menu a:hover {
            background: #dff4ff;
        }

        .logout {
            margin-top: 20px;
            display: block;
            text-align: center;
            color: #d9534f;
            text-decoration: none;
        }

        @media (max-width: 480px) {
            .card {
                padding: 22px;
            }

            .logo h1 {
                font-size: 27px;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <div class="logo">
        <h1>💼 LAMBEMAH GESTION</h1>
        <p>Gestion simple et efficace de votre activité</p>
    </div>

    <div class="card">

        <?php if (!isset($_SESSION["id"])): ?>

            <h2>Connexion</h2>

            <?php if ($message !== ""): ?>
                <div class="message">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <form method="POST">

                <div class="form-group">
                    <label>Nom d'utilisateur</label>
                    <input
                        type="text"
                        name="username"
                        placeholder="Entrez votre nom d'utilisateur"
                        required
                    >
                </div>

                <div class="form-group">
                    <label>Mot de passe</label>
                    <input
                        type="password"
                        name="password"
                        placeholder="Entrez votre mot de passe"
                        required
                    >
                </div>

                <button type="submit">
                    Se connecter
                </button>

            </form>

        <?php else: ?>

            <div class="welcome">

                <h2>
                    Bienvenue <?= htmlspecialchars($_SESSION["nom"]) ?> 👋
                </h2>

                <p>
                    Vous êtes connecté à LAMBEMAH GESTION.
                </p>

                <div class="menu">
                    <a href="#">📦 Produits</a>
                    <a href="#">💰 Ventes</a>
                    <a href="#">💵 Recettes</a>
                    <a href="#">💸 Dépenses</a>
                    <a href="#">📊 Statistiques</a>
                </div>

                <a class="logout" href="?logout=1">
                    Déconnexion
                </a>

            </div>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
