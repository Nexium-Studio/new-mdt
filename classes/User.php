<?php
/**
 * Classe User - Gestion des utilisateurs
 */

class User {
    private $db;
    private $id;
    private $email;
    private $username;
    private $avatar;
    private $iban;
    private $discord_id;
    private $faction;
    private $faction_grade;
    private $role;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Créer un nouvel utilisateur
     */
    public function create($data) {
        try {
            $sql = "INSERT INTO users (email, username, password, avatar, discord_id, faction, role) 
                    VALUES (:email, :username, :password, :avatar, :discord_id, :faction, :role)";
            
            $stmt = $this->db->prepare($sql);
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
            
            $stmt->execute([
                'email' => $data['email'],
                'username' => $data['username'],
                'password' => $hashedPassword,
                'avatar' => $data['avatar'] ?? null,
                'discord_id' => $data['discord_id'] ?? null,
                'faction' => $data['faction'] ?? 'NONE',
                'role' => $data['role'] ?? 'Citoyen'
            ]);
            
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("Erreur création utilisateur: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Authentifier un utilisateur
     */
    public function authenticate($email, $password) {
        try {
            $sql = "SELECT * FROM users WHERE email = :email AND is_active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['email' => $email]);
            
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                // Mettre à jour la dernière connexion
                $this->updateLastLogin($user['id']);
                
                // Enregistrer le log
                $this->logAction($user['id'], 'SYSTEM', 'Connexion', 'Connexion réussie');
                
                return $user;
            }
            
            return false;
        } catch (PDOException $e) {
            error_log("Erreur authentification: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Authentifier avec Discord
     */
    public function authenticateWithDiscord($discordId, $discordData) {
        try {
            $sql = "SELECT * FROM users WHERE discord_id = :discord_id AND is_active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['discord_id' => $discordId]);
            
            $user = $stmt->fetch();
            
            if ($user) {
                // Mettre à jour les informations Discord si nécessaire
                $this->updateDiscordInfo($user['id'], $discordData);
                $this->updateLastLogin($user['id']);
                $this->logAction($user['id'], 'SYSTEM', 'Connexion Discord', 'Connexion via Discord réussie');
                
                return $user;
            }
            
            return false;
        } catch (PDOException $e) {
            error_log("Erreur authentification Discord: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Récupérer un utilisateur par ID
     */
    public function getById($id) {
        try {
            $sql = "SELECT * FROM users WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id]);
            
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Erreur récupération utilisateur: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Récupérer tous les utilisateurs
     */
    public function getAll($filters = []) {
        try {
            $sql = "SELECT * FROM users WHERE 1=1";
            $params = [];
            
            if (!empty($filters['faction'])) {
                $sql .= " AND faction = :faction";
                $params['faction'] = $filters['faction'];
            }
            
            if (!empty($filters['role'])) {
                $sql .= " AND role = :role";
                $params['role'] = $filters['role'];
            }
            
            if (isset($filters['is_active'])) {
                $sql .= " AND is_active = :is_active";
                $params['is_active'] = $filters['is_active'];
            }
            
            $sql .= " ORDER BY created_at DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Erreur récupération utilisateurs: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Mettre à jour un utilisateur
     */
    public function update($id, $data) {
        try {
            $fields = [];
            $params = ['id' => $id];
            
            $allowedFields = ['email', 'username', 'avatar', 'iban', 'discord_id', 'faction', 'faction_grade', 'role', 'is_active'];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $fields[] = "$field = :$field";
                    $params[$field] = $data[$field];
                }
            }
            
            if (!empty($fields)) {
                $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
                $stmt = $this->db->prepare($sql);
                return $stmt->execute($params);
            }
            
            return false;
        } catch (PDOException $e) {
            error_log("Erreur mise à jour utilisateur: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Mettre à jour le mot de passe
     */
    public function updatePassword($id, $newPassword) {
        try {
            $sql = "UPDATE users SET password = :password WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            
            return $stmt->execute([
                'id' => $id,
                'password' => $hashedPassword
            ]);
        } catch (PDOException $e) {
            error_log("Erreur mise à jour mot de passe: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Supprimer un utilisateur
     */
    public function delete($id) {
        try {
            $sql = "DELETE FROM users WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            
            return $stmt->execute(['id' => $id]);
        } catch (PDOException $e) {
            error_log("Erreur suppression utilisateur: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Vérifier si un email existe
     */
    public function emailExists($email, $excludeId = null) {
        try {
            $sql = "SELECT id FROM users WHERE email = :email";
            $params = ['email' => $email];
            
            if ($excludeId) {
                $sql .= " AND id != :id";
                $params['id'] = $excludeId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetch() !== false;
        } catch (PDOException $e) {
            error_log("Erreur vérification email: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Vérifier si un username existe
     */
    public function usernameExists($username, $excludeId = null) {
        try {
            $sql = "SELECT id FROM users WHERE username = :username";
            $params = ['username' => $username];
            
            if ($excludeId) {
                $sql .= " AND id != :id";
                $params['id'] = $excludeId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetch() !== false;
        } catch (PDOException $e) {
            error_log("Erreur vérification username: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Mettre à jour la dernière connexion
     */
    private function updateLastLogin($id) {
        try {
            $sql = "UPDATE users SET last_login = NOW() WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id]);
        } catch (PDOException $e) {
            error_log("Erreur mise à jour last_login: " . $e->getMessage());
        }
    }
    
    /**
     * Mettre à jour les informations Discord
     */
    private function updateDiscordInfo($id, $discordData) {
        try {
            if (!isset($discordData['avatar'])) {
                return;
            }

            // L'avatar Discord n'est appliqué que si l'utilisateur n'a pas déjà
            // un avatar : un avatar personnalisé n'est jamais écrasé à la connexion.
            $check = $this->db->prepare("SELECT avatar FROM users WHERE id = ? LIMIT 1");
            $check->execute([$id]);
            $current = trim((string) $check->fetchColumn());
            if ($current !== '') {
                return;
            }

            $avatar = "https://cdn.discordapp.com/avatars/{$discordData['id']}/{$discordData['avatar']}.png";
            $stmt = $this->db->prepare("UPDATE users SET avatar = :avatar WHERE id = :id");
            $stmt->execute([
                'id' => $id,
                'avatar' => $avatar
            ]);
        } catch (PDOException $e) {
            error_log("Erreur mise à jour Discord info: " . $e->getMessage());
        }
    }
    
    /**
     * Enregistrer une action dans les logs
     */
    private function logAction($userId, $panel, $action, $description = null) {
        mdt_audit_log(
            $userId !== null ? (int) $userId : null,
            (string) $panel,
            (string) $action,
            $description,
            'auth',
            null
        );
    }
    
    /**
     * Récupérer les utilisateurs par faction avec leur grade
     */
    public function getByFactionWithGrade($faction) {
        try {
            $sql = "SELECT * FROM users WHERE faction = :faction AND faction_grade IS NOT NULL ORDER BY faction_grade ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['faction' => $faction]);
            
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Erreur récupération utilisateurs par faction: " . $e->getMessage());
            return [];
        }
    }
}
