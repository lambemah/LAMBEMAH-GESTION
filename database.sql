CREATE TABLE IF NOT EXISTS utilisateurs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    mot_de_passe VARCHAR(255) NOT NULL,
    role VARCHAR(50) DEFAULT 'admin',
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS produits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(150) NOT NULL,
    categorie VARCHAR(100),
    prix_achat DECIMAL(12,2) DEFAULT 0,
    prix_vente DECIMAL(12,2) DEFAULT 0,
    stock INT DEFAULT 0,
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS mouvements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    produit_id INT NOT NULL,
    type ENUM('ENTREE','SORTIE') NOT NULL,
    quantite INT NOT NULL,
    prix DECIMAL(12,2) DEFAULT 0,
    description TEXT,
    date_mouvement TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (produit_id) REFERENCES produits(id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ventes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    produit_id INT NOT NULL,
    quantite INT NOT NULL,
    prix_unitaire DECIMAL(12,2) NOT NULL,
    total DECIMAL(12,2) NOT NULL,
    date_vente TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (produit_id) REFERENCES produits(id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS depenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    libelle VARCHAR(150) NOT NULL,
    montant DECIMAL(12,2) NOT NULL,
    description TEXT,
    date_depense TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO utilisateurs
(nom, username, mot_de_passe, role)
VALUES
('Lambe Tenin Konate', 'admin', '1234', 'admin');
