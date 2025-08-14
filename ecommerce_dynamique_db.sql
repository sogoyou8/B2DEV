-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1
-- Généré le : lun. 26 mai 2025 à 06:46
-- Version du serveur : 10.4.32-MariaDB
-- Version de PHP : 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `ecommerce_dynamique_db`
--

-- --------------------------------------------------------

--
-- Structure de la table `cart`
--

CREATE TABLE `cart` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `favorites`
--

CREATE TABLE `favorites` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `favorites`
--

INSERT INTO `favorites` (`id`, `user_id`, `item_id`) VALUES
(15, 6, 5),
(16, 6, 3),
(17, 6, 1),
(18, 6, 2);

-- --------------------------------------------------------

--
-- Structure de la table `invoice`
--

CREATE TABLE `invoice` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `transaction_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `amount` decimal(10,2) NOT NULL,
  `billing_address` varchar(255) NOT NULL,
  `city` varchar(100) NOT NULL,
  `postal_code` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `invoice`
--

INSERT INTO `invoice` (`id`, `order_id`, `transaction_date`, `amount`, `billing_address`, `city`, `postal_code`) VALUES
(2, 7, '2025-03-02 18:31:25', 3137.88, 'Adresse de facturation', 'Ville', 'Code postal'),
(3, 8, '2025-03-03 02:19:55', 1719.94, '', '', ''),
(4, 9, '2025-05-26 03:06:58', 110.00, '', '', '');

-- --------------------------------------------------------

--
-- Structure de la table `items`
--

CREATE TABLE `items` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `stock` int(11) NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `stock_alert_threshold` int(11) DEFAULT 5,
  `category` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `items`
--

INSERT INTO `items` (`id`, `name`, `description`, `price`, `stock`, `image`, `created_at`, `updated_at`, `stock_alert_threshold`, `category`) VALUES
(1, 'nike shox', 'chaussure lifestyle', 219.99, 10, 'images.jpg', '2025-03-01 18:11:43', '2025-03-02 18:31:25', 5, NULL),
(2, 'nike mercurial', 'crampon de football', 198.99, 6, 'images (2).jpg', '2025-03-01 18:14:52', '2025-05-26 04:01:11', 5, ''),
(3, 'Nike United Mercurial Vapor 16 Elite', 'Chaussure de foot à crampons basse FG\r\n', 279.99, 38, 'ZM+VAPOR+16+ELITE+FG+NU1.png', '2025-03-02 10:57:03', '2025-03-03 02:19:55', 5, NULL),
(4, 'Nike Mercurial Superfly 10 Elite By You', 'Chaussure de foot montante à crampons pour terrain sec personnalisable\r\n', 309.99, 78, 'custom-nike-mercurial-superfly-10-elite-by-you.png', '2025-03-02 13:51:53', '2025-03-03 02:19:55', 5, NULL),
(5, 'Nike Phantom GX 2 Elite « Erling Haaland »', 'Chaussure de foot à crampons basse FG\r\n', 269.99, 53, 'PHANTOM+GX+II+ELITE+FG+EH.png', '2025-03-02 19:05:46', '2025-03-03 02:19:55', 5, NULL),
(8, 'a', 'a', 0.01, 1, NULL, '2025-03-03 03:23:27', '2025-03-03 03:34:16', 5, NULL),
(9, 'mega nom', 'mega description', 11.00, 0, NULL, '2025-05-24 15:51:23', '2025-05-26 03:06:56', 5, NULL);

-- --------------------------------------------------------

--
-- Structure de la table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `type` varchar(50) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `is_persistent` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `notifications`
--

INSERT INTO `notifications` (`id`, `type`, `message`, `is_read`, `is_persistent`, `created_at`, `read_at`) VALUES
(1, 'security', 'Tentative de connexion échouée pour l\'email : yoann@gmail.com', 1, 1, '2025-05-26 03:01:55', NULL),
(2, 'security', 'Tentative de connexion échouée pour l\'email : yoann@gmail.com', 1, 1, '2025-05-26 03:02:05', '2025-05-26 05:58:04'),
(3, 'important', 'Le produit \'mega nom\' est en rupture de stock !', 1, 1, '2025-05-26 03:06:58', NULL),
(4, 'security', 'Tentative de connexion échouée pour l\'email : toro@gmail.com', 0, 1, '2025-05-26 04:05:39', NULL),
(5, 'security', 'Tentative de connexion échouée pour l\'email : toro@gmail.com', 0, 1, '2025-05-26 04:06:49', NULL),
(6, 'admin_action', 'Prédictions IA générées par Admin1', 0, 0, '2025-05-26 04:45:59', NULL),
(7, 'admin_action', 'Prédictions IA générées par Admin1', 0, 0, '2025-05-26 04:46:25', NULL),
(8, 'admin_action', 'Prédictions IA générées par Admin1', 0, 0, '2025-05-26 04:46:28', NULL),
(9, 'admin_action', 'Prédictions IA générées par Admin1', 0, 0, '2025-05-26 04:46:31', NULL),
(10, 'admin_action', 'Prédictions IA générées par Admin1', 0, 0, '2025-05-26 04:46:40', NULL),
(11, 'admin_action', 'Prédictions IA générées par Admin1', 0, 0, '2025-05-26 04:46:41', NULL);

-- --------------------------------------------------------

--
-- Structure de la table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `status` enum('pending','shipped','delivered','cancelled') DEFAULT 'pending',
  `order_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `orders`
--

INSERT INTO `orders` (`id`, `user_id`, `total_price`, `status`, `order_date`) VALUES
(7, 6, 3137.88, 'pending', '2025-03-02 18:31:25'),
(8, 6, 1719.94, 'pending', '2025-03-03 02:19:55'),
(9, 11, 110.00, 'pending', '2025-05-26 03:06:56');

-- --------------------------------------------------------

--
-- Structure de la table `order_details`
--

CREATE TABLE `order_details` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `order_details`
--

INSERT INTO `order_details` (`id`, `order_id`, `item_id`, `quantity`, `price`) VALUES
(12, 7, 1, 3, 219.99),
(13, 7, 2, 2, 198.99),
(14, 7, 3, 3, 279.99),
(15, 7, 4, 4, 309.99),
(16, 8, 5, 2, 269.99),
(17, 8, 4, 2, 309.99),
(18, 8, 3, 2, 279.99),
(19, 9, 9, 10, 11.00);

-- --------------------------------------------------------

--
-- Structure de la table `previsions`
--

CREATE TABLE `previsions` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `date_prevision` date NOT NULL,
  `quantite_prevue` int(11) NOT NULL,
  `confidence_score` int(11) DEFAULT 0,
  `trend_direction` varchar(20) DEFAULT 'stable',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `product_images`
--

CREATE TABLE `product_images` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `image` varchar(255) NOT NULL,
  `position` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `product_images`
--

INSERT INTO `product_images` (`id`, `product_id`, `image`, `position`) VALUES
(7, 8, 'mercurial-superfly-7-mbappe-nike-bondy-dreams.webp', 0),
(8, 8, 'PHANTOM+GX+II+ELITE+FG+EH.png', 0),
(9, 8, 'PHANTOM+GX+II+PRO+TF.png', 0),
(10, 9, 'téléchargement.jpg', 0);

-- --------------------------------------------------------

--
-- Structure de la table `stock`
--

CREATE TABLE `stock` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('user','admin') DEFAULT 'user',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `created_at`) VALUES
(1, 'Admin', 'admin@gmail.com', '$2y$10$wQwQwK1k8wQwQwK1k8wQwOQwQwQwK1k8wQwQwK1k8wQwQwK1k8wQwK', 'admin', '2025-02-24 13:01:33'),
(6, 'yoann', 'yoann@gmail.com', '$2y$10$O645SVVTj0DVRu/i2qcDF.6YSud6AMe/FfGpU9MSPOqdYNw8DuGf.', 'user', '2025-03-02 18:30:08'),
(7, 'test', 'test@gmail.com', '$2y$10$8tet4xAQigXguRBrzL/Ck.Q/Dw7p5eZK8fJGMCj2OTbfnbXmOZ/.S', 'admin', '2025-03-02 18:48:50'),
(10, 'Admin1', 'admin1@gmail.com', '$2y$10$tqMFs.G40/unAEs7Zu4zZuC9ADKYyt71fY6yoUQ..Jj5QkFD09pmS', 'admin', '2025-05-24 15:39:25'),
(11, 'Toro', 'toro@gmail.com', '$2y$10$HtAhfTsUqOnAv2E7NbdWeehCvVWAIhfqEJS9C5Ac/1U0WjOJljtNK', 'user', '2025-05-26 03:02:43');

--
-- Index pour les tables déchargées
--

--
-- Index pour la table `cart`
--
ALTER TABLE `cart`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `item_id` (`item_id`);

--
-- Index pour la table `favorites`
--
ALTER TABLE `favorites`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `item_id` (`item_id`);

--
-- Index pour la table `invoice`
--
ALTER TABLE `invoice`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

--
-- Index pour la table `items`
--
ALTER TABLE `items`
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Index pour la table `order_details`
--
ALTER TABLE `order_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `item_id` (`item_id`);

--
-- Index pour la table `previsions`
--
ALTER TABLE `previsions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `item_id` (`item_id`);

--
-- Index pour la table `product_images`
--
ALTER TABLE `product_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`);

--
-- Index pour la table `stock`
--
ALTER TABLE `stock`
  ADD PRIMARY KEY (`id`),
  ADD KEY `item_id` (`item_id`);

--
-- Index pour la table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `cart`
--
ALTER TABLE `cart`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT pour la table `favorites`
--
ALTER TABLE `favorites`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT pour la table `invoice`
--
ALTER TABLE `invoice`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT pour la table `items`
--
ALTER TABLE `items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT pour la table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT pour la table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT pour la table `order_details`
--
ALTER TABLE `order_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT pour la table `previsions`
--
ALTER TABLE `previsions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `product_images`
--
ALTER TABLE `product_images`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT pour la table `stock`
--
ALTER TABLE `stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `cart`
--
ALTER TABLE `cart`
  ADD CONSTRAINT `cart_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `cart_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`);

--
-- Contraintes pour la table `favorites`
--
ALTER TABLE `favorites`
  ADD CONSTRAINT `favorites_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `favorites_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`);

--
-- Contraintes pour la table `invoice`
--
ALTER TABLE `invoice`
  ADD CONSTRAINT `invoice_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `order_details`
--
ALTER TABLE `order_details`
  ADD CONSTRAINT `order_details_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_details_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `previsions`
--
ALTER TABLE `previsions`
  ADD CONSTRAINT `previsions_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`);

--
-- Contraintes pour la table `product_images`
--
ALTER TABLE `product_images`
  ADD CONSTRAINT `product_images_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `items` (`id`);

--
-- Contraintes pour la table `stock`
--
ALTER TABLE `stock`
  ADD CONSTRAINT `stock_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
