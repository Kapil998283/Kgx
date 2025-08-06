<?php
// Advanced Notification Helper for Tournament Section
// Usage: NotificationHelper::sendToUser(...), sendToTeam(...), sendToTournament(...)

class NotificationHelper {
    // Send notification to a single user
    public static function sendToUser($user_id, $title, $message, $url = null, $type = 'tournament', $related_id = null, $related_type = null, $conn = null) {
        if (!$conn) $conn = self::getDb();
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, type, related_id, related_type, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $msg = $title ? ("<b>" . $title . ":</b> " . $message) : $message;
        $stmt->execute([$user_id, $msg, $type, $related_id, $related_type]);
        // Optionally, you can log or handle $url for in-app links
    }

    // Send notification to all members of a team
    public static function sendToTeam($team_id, $title, $message, $url = null, $type = 'tournament', $related_id = null, $related_type = null, $conn = null) {
        if (!$conn) $conn = self::getDb();
        $stmt = $conn->prepare("SELECT user_id FROM team_members WHERE team_id = ? AND status = 'active'");
        $stmt->execute([$team_id]);
        $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($users as $user_id) {
            self::sendToUser($user_id, $title, $message, $url, $type, $related_id, $related_type, $conn);
        }
    }

    // Send notification to all users registered for a tournament
    public static function sendToTournament($tournament_id, $title, $message, $url = null, $type = 'tournament', $related_id = null, $related_type = null, $conn = null) {
        if (!$conn) $conn = self::getDb();
        // Get all team_ids registered for the tournament
        $stmt = $conn->prepare("SELECT team_id FROM tournament_registrations WHERE tournament_id = ? AND status = 'approved'");
        $stmt->execute([$tournament_id]);
        $teams = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($teams as $team_id) {
            self::sendToTeam($team_id, $title, $message, $url, $type, $related_id, $related_type, $conn);
        }
    }

    // General send (for future extensibility)
    public static function send($user_ids, $title, $message, $url = null, $type = 'tournament', $related_id = null, $related_type = null, $conn = null) {
        if (!$conn) $conn = self::getDb();
        foreach ((array)$user_ids as $user_id) {
            self::sendToUser($user_id, $title, $message, $url, $type, $related_id, $related_type, $conn);
        }
    }

    // Helper to get DB connection
    private static function getDb() {
        require_once __DIR__ . '/../../config/supabase.php';
        $database = new Database();
        return $database->connect();
    }
} 