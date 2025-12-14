<?php
// Migration script to add missing grade columns to the grades table
require_once '../config/db.php';

try {
    // Check if columns exist, if not add them
    $columns_to_add = [
        'quiz' => 'DECIMAL(5,2)',
        'midterm' => 'DECIMAL(5,2)',
        'final' => 'DECIMAL(5,2)',
        'overall_grade' => 'DECIMAL(5,2)',
        'letter_grade' => 'VARCHAR(2)',
        'submitted_date' => 'TIMESTAMP NULL'
    ];
    
    foreach ($columns_to_add as $column => $type) {
        $check_sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                      WHERE TABLE_NAME = 'grades' AND COLUMN_NAME = '$column' AND TABLE_SCHEMA = 'ccsgrading_db'";
        $result = $conn->query($check_sql);
        
        if ($result->num_rows === 0) {
            $alter_sql = "ALTER TABLE grades ADD COLUMN $column $type";
            if ($conn->query($alter_sql)) {
                echo "✓ Added column: $column<br>";
            } else {
                echo "✗ Error adding column $column: " . $conn->error . "<br>";
            }
        } else {
            echo "✓ Column $column already exists<br>";
        }
    }
    
    echo "<br>Migration completed successfully!<br>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

$conn->close();
?>
