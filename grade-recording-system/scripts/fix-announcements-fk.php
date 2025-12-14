<?php
// Fix announcements table foreign key constraint
$conn = new mysqli('localhost', 'root', '', 'ccsgrading_db');

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "Checking announcements table constraint...<br>";

// Check if the wrong constraint exists
$check_sql = "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
              WHERE TABLE_NAME = 'announcements' AND COLUMN_NAME = 'section_id' AND REFERENCED_TABLE_NAME = 'course_sections'";
$result = $conn->query($check_sql);

if ($result && $result->num_rows > 0) {
    echo "Found incorrect foreign key constraint. Fixing...<br>";
    
    // Drop the announcements table and recreate it with correct constraint
    $conn->query("DROP TABLE IF EXISTS announcements");
    
    $sql = "CREATE TABLE announcements (
        id INT PRIMARY KEY AUTO_INCREMENT,
        section_id INT NOT NULL,
        faculty_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        content TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
        FOREIGN KEY (faculty_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    
    if ($conn->query($sql)) {
        echo "✓ Announcements table fixed successfully!<br>";
    } else {
        echo "✗ Error fixing table: " . $conn->error . "<br>";
    }
} else {
    echo "✓ Announcements table constraint is already correct.<br>";
}

$conn->close();
?>
