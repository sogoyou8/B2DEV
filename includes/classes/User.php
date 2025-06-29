<?php
class User {
    private $pdo;
    private $id;
    private $name;
    private $email;
    private $role;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    // Getters/Setters basiques...
    
    public static function getAdmins($pdo) {
        $stmt = $pdo->query("SELECT * FROM users WHERE role = 'admin'");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public static function getTotalUsers($pdo) {
        return $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
    }
}
?>