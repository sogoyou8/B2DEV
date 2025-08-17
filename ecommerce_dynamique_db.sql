-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1
-- Généré le : sam. 16 août 2025 à 17:30
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
(18, 6, 2),
(19, 11, 9);

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
(4, 9, '2025-05-26 03:06:58', 110.00, '', '', ''),
(5, 10, '2025-05-26 07:37:10', 1501.01, '', '', ''),
(6, 11, '2025-05-26 09:16:34', 32.34, '', '', '');

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
(1, 'nike shox', 'chaussure lifestyle', 219.99, 5, 'images.jpg', '2025-03-01 18:11:43', '2025-08-16 15:22:08', 5, 'chaussure'),
(2, 'nike mercurial', 'crampon de football', 198.99, 6, 'images (2).jpg', '2025-03-01 18:14:52', '2025-05-26 07:32:25', 5, 'chaussure'),
(3, 'Nike United Mercurial Vapor 16 Elite', 'Chaussure de foot à crampons basse FG\r\n', 279.99, 38, 'ZM+VAPOR+16+ELITE+FG+NU1.png', '2025-03-02 10:57:03', '2025-05-26 07:32:18', 5, 'chaussure'),
(4, 'Nike Mercurial Superfly 10 Elite By You', 'Chaussure de foot montante à crampons pour terrain sec personnalisable\r\n', 309.99, 78, 'custom-nike-mercurial-superfly-10-elite-by-you.png', '2025-03-02 13:51:53', '2025-05-26 07:32:11', 5, 'chaussure'),
(5, 'Nike Phantom GX 2 Elite « Erling Haaland »', 'Chaussure de foot à crampons basse FG\r\n', 269.99, 53, 'PHANTOM+GX+II+ELITE+FG+EH.png', '2025-03-02 19:05:46', '2025-05-26 07:32:01', 5, 'chaussure'),
(8, 'a', 'a', 0.01, 0, NULL, '2025-03-03 03:23:27', '2025-05-26 07:37:08', 5, 'chaussure'),
(9, 'mega poster', 'de la taille d\'un mur', 11.00, 15, NULL, '2025-05-24 15:51:23', '2025-05-26 07:31:37', 5, 'poster'),
(10, 'bille', 'lot de bille', 8.23, 66, NULL, '2025-05-26 07:25:48', '2025-05-26 07:37:06', 11, 'jeu'),
(11, 'carte yugioh', 'set de 5 exodia le maudit', 18.99, 1, NULL, '2025-05-26 07:29:39', '2025-05-26 07:31:19', 5, 'jeu de carte'),
(12, 'arceus', 'carte pokemon arceus', 16.17, 0, NULL, '2025-05-26 07:43:03', '2025-05-26 09:16:32', 1, 'jeu de carte '),
(13, 'sac nike', 'sac a dos nike', 22.00, 2, NULL, '2025-05-26 07:55:52', '2025-05-26 07:55:52', 1, 'sac de sport'),
(14, 'aze', 'aze', 84.00, 45, NULL, '2025-08-16 15:28:16', '2025-08-16 15:28:16', 5, 'chaussure');

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
(4, 'security', 'Tentative de connexion échouée pour l\'email : toro@gmail.com', 1, 1, '2025-05-26 04:05:39', '2025-05-26 07:11:38'),
(5, 'security', 'Tentative de connexion échouée pour l\'email : toro@gmail.com', 1, 1, '2025-05-26 04:06:49', '2025-05-26 11:15:11'),
(6, 'admin_action', 'Prédictions IA générées par Admin1', 1, 0, '2025-05-26 04:45:59', '2025-05-26 11:15:11'),
(7, 'admin_action', 'Prédictions IA générées par Admin1', 1, 0, '2025-05-26 04:46:25', '2025-05-26 11:15:11'),
(8, 'admin_action', 'Prédictions IA générées par Admin1', 1, 0, '2025-05-26 04:46:28', '2025-05-26 11:15:11'),
(9, 'admin_action', 'Prédictions IA générées par Admin1', 1, 0, '2025-05-26 04:46:31', '2025-05-26 11:15:11'),
(10, 'admin_action', 'Prédictions IA générées par Admin1', 1, 0, '2025-05-26 04:46:40', '2025-05-26 11:15:11'),
(11, 'admin_action', 'Prédictions IA générées par Admin1', 1, 0, '2025-05-26 04:46:41', '2025-05-26 11:15:11'),
(12, 'admin_action', 'Prédictions IA générées par Admin1', 1, 0, '2025-05-26 04:47:50', '2025-05-26 11:15:11'),
(13, 'admin_action', 'Prédictions IA générées par Admin1', 1, 0, '2025-05-26 04:47:57', '2025-05-26 11:15:11'),
(14, 'admin_action', 'Prédictions IA générées par Admin1', 1, 0, '2025-05-26 04:52:15', '2025-05-26 11:15:11'),
(15, 'admin_action', 'Prédictions IA générées par Admin1', 1, 0, '2025-05-26 04:58:35', '2025-05-26 11:15:11'),
(16, 'security', 'Tentative de connexion échouée pour l\'email : toro@gmail.com', 1, 1, '2025-05-26 05:16:11', '2025-05-26 07:16:38'),
(17, 'important', 'Le produit \'a\' est en rupture de stock !', 1, 1, '2025-05-26 07:37:10', '2025-05-26 11:15:11'),
(18, 'security', 'Tentative de connexion échouée pour l\'email : matthias@gmail.com', 0, 1, '2025-05-26 09:15:52', NULL),
(19, 'important', 'Le produit \'arceus\' est en rupture de stock !', 0, 1, '2025-05-26 09:16:34', NULL),
(20, 'security', 'Tentative de connexion admin échouée pour l\'email : admin@gmail.com', 0, 1, '2025-06-29 03:18:00', NULL),
(21, 'security', 'Tentative de connexion admin échouée pour l\'email : admin@gmail.com', 0, 1, '2025-06-29 03:18:11', NULL),
(22, 'security', 'Tentative de connexion admin échouée pour l\'email : admin1@gmail.com', 0, 1, '2025-06-29 03:19:05', NULL),
(23, 'security', 'Tentative de connexion admin échouée pour l\'email : admin@gmail.com', 0, 1, '2025-08-14 03:02:30', NULL),
(24, 'admin_action', 'Utilisateur \'testo\' modifié avec succès par azerty', 0, 0, '2025-08-14 07:17:18', NULL),
(25, 'admin_action', 'Utilisateur \'Admin\' modifié avec succès par azerty', 0, 0, '2025-08-15 10:18:33', NULL),
(26, 'admin_action', 'Utilisateur \'testo1\' modifié avec succès par azerty', 0, 0, '2025-08-15 11:06:28', NULL),
(27, 'admin_action', 'Nouvel admin créé : ab (ab@gmail.com) par admin ID 13', 0, 0, '2025-08-15 11:16:28', NULL),
(28, 'admin_action', 'Nouvel admin créé : abc (abc@gmail.com) par admin ID 13', 0, 0, '2025-08-15 11:18:03', NULL),
(29, 'admin_action', 'Utilisateur \'abc\' modifié avec succès par azerty', 0, 0, '2025-08-15 11:18:49', NULL),
(30, 'admin_action', 'Utilisateur \'abcd\' modifié avec succès par azerty', 0, 0, '2025-08-15 12:36:28', NULL),
(31, 'admin_action', 'Utilisateur \'abc\' modifié avec succès par azerty', 0, 0, '2025-08-15 12:36:37', NULL),
(32, 'admin_action', 'Utilisateur \'abc\' modifié avec succès par azerty', 0, 0, '2025-08-15 12:37:06', NULL),
(33, 'admin_action', 'Utilisateur \'abc\' modifié avec succès par azerty', 0, 0, '2025-08-15 12:37:13', NULL),
(34, 'admin_action', 'Utilisateur \'yoann\' modifié avec succès par azerty', 0, 0, '2025-08-15 12:37:31', NULL),
(35, 'admin_action', 'Utilisateur \'yoann\' modifié avec succès par azerty', 0, 0, '2025-08-15 12:37:38', NULL),
(36, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 04:56:24', NULL),
(37, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 04:56:35', NULL),
(38, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:01:32', NULL),
(39, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:07:18', NULL),
(40, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:07:24', NULL),
(41, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:07:26', NULL),
(42, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:07:34', NULL),
(43, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:07:36', NULL),
(44, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:12:42', NULL),
(45, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:12:47', NULL),
(46, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:12:48', NULL),
(47, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:12:49', NULL),
(48, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:12:50', NULL),
(49, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:16:30', NULL),
(50, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:16:37', NULL),
(51, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:22:11', NULL),
(52, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:22:12', NULL),
(53, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:22:13', NULL),
(54, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:22:14', NULL),
(55, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:22:17', NULL),
(56, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:22:21', NULL),
(57, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:22:23', NULL),
(58, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:22:23', NULL),
(59, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:22:24', NULL),
(60, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:23:03', NULL),
(61, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:23:11', NULL),
(62, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:23:57', NULL),
(63, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:24:07', NULL),
(64, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:24:46', NULL),
(65, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 05:24:53', NULL),
(66, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 12:06:33', NULL),
(67, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 12:10:44', NULL),
(68, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 12:21:19', NULL),
(69, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 12:22:09', NULL),
(70, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 12:24:55', NULL),
(71, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 12:25:01', NULL),
(72, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 12:28:16', NULL),
(73, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 12:28:19', NULL),
(74, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 12:28:20', NULL),
(75, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 12:28:21', NULL),
(76, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 12:28:33', NULL),
(77, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 12:43:25', NULL),
(78, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 12:43:33', NULL),
(79, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 12:47:46', NULL),
(80, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 12:47:54', NULL),
(81, 'admin_action', 'Prédictions IA générées par azerty', 0, 0, '2025-08-16 12:57:46', NULL);

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
(9, 11, 110.00, 'pending', '2025-05-26 03:06:56'),
(10, 12, 1501.01, 'pending', '2025-05-26 07:37:06'),
(11, 12, 32.34, 'pending', '2025-05-26 09:16:32');

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
(19, 9, 9, 10, 11.00),
(20, 10, 10, 22, 8.23),
(21, 10, 1, 6, 219.99),
(22, 10, 8, 1, 0.01),
(23, 11, 12, 2, 16.17);

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

--
-- Déchargement des données de la table `previsions`
--

INSERT INTO `previsions` (`id`, `item_id`, `date_prevision`, `quantite_prevue`, `confidence_score`, `trend_direction`, `created_at`) VALUES
(132, 1, '2025-09-01', 18, 0, 'données insuffisante', '2025-08-16 12:57:46'),
(133, 2, '2025-09-01', 2, 0, 'données insuffisante', '2025-08-16 12:57:46'),
(134, 3, '2025-09-01', 6, 0, 'données insuffisante', '2025-08-16 12:57:46'),
(135, 4, '2025-09-01', 7, 0, 'données insuffisante', '2025-08-16 12:57:46'),
(136, 5, '2025-09-01', 2, 0, 'données insuffisante', '2025-08-16 12:57:46'),
(137, 8, '2025-09-01', 1, 0, 'données insuffisante', '2025-08-16 12:57:46'),
(138, 9, '2025-09-01', 12, 0, 'données insuffisante', '2025-08-16 12:57:46'),
(139, 10, '2025-09-01', 26, 0, 'données insuffisante', '2025-08-16 12:57:46'),
(140, 12, '2025-09-01', 2, 0, 'données insuffisante', '2025-08-16 12:57:46');

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
(8, 8, 'PHANTOM+GX+II+ELITE+FG+EH.png', 0),
(10, 9, 'téléchargement.jpg', 0),
(13, 1, 'LEGEND+10+CLUB+FG_MG (3).png', 2),
(14, 1, 'LEGEND+10+CLUB+FG_MG (4).png', 3),
(15, 1, 'LEGEND+10+CLUB+FG_MG (5).png', 4),
(16, 1, 'LEGEND+10+CLUB+FG_MG (6).png', 5),
(17, 1, 'LEGEND+10+CLUB+FG_MG (7).png', 6),
(18, 1, 'LEGEND+10+CLUB+FG_MG (8).png', 7),
(19, 1, 'LEGEND+10+CLUB+FG_MG.png', 8),
(20, 5, 'PHANTOM+GX+II+ELITE+FG+EH (3).png', 0),
(21, 5, 'PHANTOM+GX+II+ELITE+FG+EH (1).png', 1),
(22, 5, 'PHANTOM+GX+II+ELITE+FG+EH (2).png', 2),
(23, 5, 'PHANTOM+GX+II+ELITE+FG+EH.png', 3),
(24, 2, 'custom-nike-mercurial-superfly-10-academy-by-you (1).png', 0),
(25, 2, 'custom-nike-mercurial-superfly-10-academy-by-you (2).png', 1),
(26, 2, 'custom-nike-mercurial-superfly-10-academy-by-you (3).png', 2),
(27, 2, 'custom-nike-mercurial-superfly-10-academy-by-you (4).png', 3),
(28, 2, 'custom-nike-mercurial-superfly-10-academy-by-you (5).png', 4),
(29, 2, 'custom-nike-mercurial-superfly-10-academy-by-you (6).png', 5),
(30, 2, 'custom-nike-mercurial-superfly-10-academy-by-you (7).png', 6),
(31, 2, 'custom-nike-mercurial-superfly-10-academy-by-you.png', 7),
(34, 3, 'PHANTOM+LUNA+II+ELITE+FG (3).png', 2),
(35, 3, 'PHANTOM+LUNA+II+ELITE+FG.png', 3),
(36, 3, 'PHANTOM+LUNA+II+ELITE+FG (1).png', 2),
(37, 3, 'PHANTOM+LUNA+II+ELITE+FG (2).png', 3),
(40, 4, 'PHANTOM+GX+II+ELITE+FG (3).png', 0),
(41, 4, 'PHANTOM+GX+II+ELITE+FG (1).png', 1),
(42, 4, 'PHANTOM+GX+II+ELITE+FG (2).png', 2),
(43, 4, 'PHANTOM+GX+II+ELITE+FG.png', 3),
(44, 10, 'billes-colorees-mega-pack-ref_NC1101_2.jpg', 0),
(45, 10, 'images.jpg', 1),
(46, 10, 'téléchargement.jpg', 2),
(47, 11, 'images (1).jpg', 0),
(48, 12, 'images (2).jpg', 0),
(49, 13, '2535112-full_product.jpg', 0),
(50, 14, 'IMG_4380.png', 0);

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
  `is_demo` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `is_demo`, `created_at`) VALUES
(1, 'Admin', 'admin@gmail.com', '$2y$10$wQwQwK1k8wQwQwK1k8wQwOQwQwQwK1k8wQwQwK1k8wQwQwK1k8wQwK', 'admin', 0, '2025-02-24 13:01:33'),
(6, 'yoann', 'yoann@gmail.com', '$2y$10$O645SVVTj0DVRu/i2qcDF.6YSud6AMe/FfGpU9MSPOqdYNw8DuGf.', 'user', 0, '2025-03-02 18:30:08'),
(10, 'Admin1', 'admin1@gmail.com', '$2y$10$tqMFs.G40/unAEs7Zu4zZuC9ADKYyt71fY6yoUQ..Jj5QkFD09pmS', 'admin', 0, '2025-05-24 15:39:25'),
(11, 'Toro', 'toro@gmail.com', '$2y$10$HtAhfTsUqOnAv2E7NbdWeehCvVWAIhfqEJS9C5Ac/1U0WjOJljtNK', 'user', 0, '2025-05-26 03:02:43'),
(12, 'matthias', 'matthias@gmail.com', '$2y$10$yGnIjzhcnpZDlI9Xqlku1OnP7d8VQuxXxSGHbfvZoiM91ra54j1va', 'user', 0, '2025-05-26 07:35:43'),
(13, 'azerty', 'azerty@gmail.com', '$2y$10$bjaB62THPhNBENRVpoReSO97BnfaR2dj5bFlHC0lyCtoqrrsL8n0m', 'admin', 0, '2025-08-14 03:19:18'),
(15, 'abc', 'abc@gmail.com', '$2y$10$6j62LmUZ1kHzvNQMAYWQB.JUaXc.KdW8W/hepvNiwCD1EeY2tcTES', 'user', 0, '2025-08-15 11:18:03'),
(16, 'Admin demo', 'admin.demo@gmail.com', '$2y$10$dk4vbdRZqPuGt3VLLE5IcuV.md.0vL.Ul.ihYK.v1QveZ3O/gP8i6', 'admin', 1, '2025-08-16 14:50:07');

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT pour la table `favorites`
--
ALTER TABLE `favorites`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT pour la table `invoice`
--
ALTER TABLE `invoice`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT pour la table `items`
--
ALTER TABLE `items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT pour la table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=82;

--
-- AUTO_INCREMENT pour la table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT pour la table `order_details`
--
ALTER TABLE `order_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT pour la table `previsions`
--
ALTER TABLE `previsions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=141;

--
-- AUTO_INCREMENT pour la table `product_images`
--
ALTER TABLE `product_images`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=51;

--
-- AUTO_INCREMENT pour la table `stock`
--
ALTER TABLE `stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

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
