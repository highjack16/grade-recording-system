<?php
require_once '../config/db.php';

// Check if the foreign key constraint exists and fix it
$conn->select_db('ccsgrading_db');

// Drop the existing grades table if it has the wrong constraint
$check_sql = "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
              WHERE TABLE_NAME = 'grades' AND COLUMN_NAME = 'section_id' AND REFERENCED_TABLE_NAME = 'course_sections'";
$result = $conn->query($check_sql);

if ($result && $result->num_rows > 0) {
    echo "Found incorrect foreign key constraint. Fixing...<br>";
    
    // Drop the grades table
    $conn->query("DROP TABLE IF EXISTS grades");
    
    // Recreate it with correct constraint
    $sql = "CREATE TABLE IF NOT EXISTS grades (
        id INT PRIMARY KEY AUTO_INCREMENT,
        student_id INT NOT NULL,
        section_id INT NOT NULL,
        exam_type VARCHAR(50),
        grade_value DECIMAL(5,2),
        quiz DECIMAL(5,2),
        midterm DECIMAL(5,2),
        final DECIMAL(5,2),
        assignment DECIMAL(5,2),
        participation DECIMAL(5,2),
        overall_grade DECIMAL(5,2),
        final_grade DECIMAL(5,2),
        letter_grade VARCHAR(2),
        remarks TEXT,
        submitted_date TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
        UNIQUE KEY unique_grade (student_id, section_id)
    )";
    
    if ($conn->query($sql) === TRUE) {
        echo "✓ Grades table recreated successfully with correct foreign key constraint!<br>";
    } else {
        echo "Error recreating grades table: " . $conn->error . "<br>";
    }
} else {
    echo "✓ Foreign key constraint is already correct or table doesn't exist.<br>";
}

$conn->close();
?>
