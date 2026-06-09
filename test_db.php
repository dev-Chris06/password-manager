<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

echo "=== Test de connexion à la base de données ===\n\n";

try {
    $pdo = get_pdo();
    echo "✓ Connexion PDO réussie\n";
    
    // Vérifier si la base de données existe
    $stmt = $pdo->query("SELECT DATABASE() as current_db");
    $result = $stmt->fetch();
    echo "✓ Base de données actuelle: " . ($result['current_db'] ?? 'N/A') . "\n";
    
    // Vérifier les tables
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "✓ Tables trouvées: " . implode(', ', $tables) . "\n";
    
    // Vérifier la structure des tables
    echo "\n--- Structure de la table utilisateurs ---\n";
    $stmt = $pdo->query("DESCRIBE utilisateurs");
    $columns = $stmt->fetchAll();
    foreach ($columns as $col) {
        echo "  - {$col['Field']}: {$col['Type']} " . ($col['Null'] === 'NO' ? 'NOT NULL' : 'NULL') . "\n";
    }
    
    echo "\n--- Structure de la table entrees ---\n";
    $stmt = $pdo->query("DESCRIBE entrees");
    $columns = $stmt->fetchAll();
    foreach ($columns as $col) {
        echo "  - {$col['Field']}: {$col['Type']} " . ($col['Null'] === 'NO' ? 'NOT NULL' : 'NULL') . "\n";
    }
    
    echo "\n--- Structure de la table journal_actions ---\n";
    $stmt = $pdo->query("DESCRIBE journal_actions");
    $columns = $stmt->fetchAll();
    foreach ($columns as $col) {
        echo "  - {$col['Field']}: {$col['Type']} " . ($col['Null'] === 'NO' ? 'NOT NULL' : 'NULL') . "\n";
    }
    
    echo "\n=== Test terminé avec succès ===\n";
    
} catch (PDOException $e) {
    echo "✗ Erreur de connexion: " . $e->getMessage() . "\n";
    exit(1);
}
