<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

/**
 * Récupère l'adresse de profil enregistrée pour un utilisateur.
 * Retourne array|null => ['billing_address'=>..., 'city'=>..., 'postal_code'=>..., 'save_flag'=>0|1]
 */
function getUserAddress(PDO $pdo, int $userId): ?array {
    if ($userId <= 0) return null;
    try {
        $stmt = $pdo->prepare("SELECT billing_address, city, postal_code, IFNULL(save_address_default,0) AS save_flag FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return [
            'billing_address' => $r['billing_address'] ?? '',
            'city' => $r['city'] ?? '',
            'postal_code' => $r['postal_code'] ?? '',
            'save_flag' => (int)($r['save_flag'] ?? 0)
        ];
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Sauvegarde l'adresse de profil pour l'utilisateur (si colonnes présentes).
 * Retourne true/false.
 */
function saveUserAddress(PDO $pdo, int $userId, string $billing, string $city, string $postal, int $saveFlag = 0): bool {
    if ($userId <= 0) return false;
    try {
        // Utiliser NULL si champ vide pour compatibilité avec schémas existants
        $billingDb = $billing !== '' ? $billing : null;
        $cityDb = $city !== '' ? $city : null;
        $postalDb = $postal !== '' ? $postal : null;

        // Vérifier que les colonnes existent avant UPDATE (évite erreur SQL si migration non appliquée)
        $colCheck = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'billing_address'")->fetch(PDO::FETCH_ASSOC);
        if (!$colCheck) {
            // colonnes absentes -> rien à faire
            return false;
        }

        $upd = $pdo->prepare("UPDATE users SET billing_address = ?, city = ?, postal_code = ?, save_address_default = ? WHERE id = ?");
        $upd->execute([$billingDb, $cityDb, $postalDb, $saveFlag ? 1 : 0, $userId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Récupère la dernière adresse enregistrée sur une invoice liée à l'utilisateur (snapshot historique).
 * Retourne array|null => ['billing_address'=>..., 'city'=>..., 'postal_code'=>...]
 */
function getLatestInvoiceAddress(PDO $pdo, int $userId): ?array {
    if ($userId <= 0) return null;
    try {
        $stmt = $pdo->prepare("
            SELECT i.billing_address, i.city, i.postal_code
            FROM invoice i
            JOIN orders o ON o.id = i.order_id
            WHERE o.user_id = ?
            ORDER BY i.id DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return [
            'billing_address' => $r['billing_address'] ?? '',
            'city' => $r['city'] ?? '',
            'postal_code' => $r['postal_code'] ?? ''
        ];
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Format d'affichage simple (sécurisé pour echo).
 * Retourne HTML (avec <br>) prêt à être echo.
 */
function formatAddressForDisplay(?array $addr): string {
    if (empty($addr)) return '';
    $parts = [];
    if (!empty($addr['billing_address'])) $parts[] = nl2br(htmlspecialchars($addr['billing_address']));
    $line2 = trim(($addr['postal_code'] ?? '') . ' ' . ($addr['city'] ?? ''));
    if ($line2 !== '') $parts[] = htmlspecialchars($line2);
    return implode("<br>\n", $parts);
}