<?php
/**
 * LOGGER D'ACTIVITÉ SAMS
 * Enregistre toutes les actions effectuées dans le panel
 */

class ActivityLogger {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Enregistrer une action
     */
    public function log($actionType, $description, $targetType = null, $targetId = null, $targetName = null, $metadata = null) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO sams_activity_logs (
                    user_id, 
                    action_type, 
                    description, 
                    target_type, 
                    target_id, 
                    target_name,
                    ip_address,
                    user_agent,
                    metadata
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $userId = $_SESSION['user_id'] ?? null;
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $metadataJson = $metadata ? json_encode($metadata) : null;
            
            $stmt->execute([
                $userId,
                $actionType,
                $description,
                $targetType,
                $targetId,
                $targetName,
                $ipAddress,
                $userAgent,
                $metadataJson
            ]);
            
            return true;
        } catch (PDOException $e) {
            error_log("Erreur log activité: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Raccourcis pour actions courantes
     */
    public function logCreate($type, $id, $name, $details = null) {
        return $this->log(
            "create_" . $type,
            "Création de " . $type . ": " . $name,
            $type,
            $id,
            $name,
            $details
        );
    }
    
    public function logEdit($type, $id, $name, $changes = null) {
        return $this->log(
            "edit_" . $type,
            "Modification de " . $type . ": " . $name,
            $type,
            $id,
            $name,
            $changes
        );
    }
    
    public function logDelete($type, $id, $name) {
        return $this->log(
            "delete_" . $type,
            "Suppression de " . $type . ": " . $name,
            $type,
            $id,
            $name
        );
    }
    
    public function logView($type, $id, $name) {
        return $this->log(
            "view_patient",
            "Consultation de " . $type . ": " . $name,
            $type,
            $id,
            $name
        );
    }
}