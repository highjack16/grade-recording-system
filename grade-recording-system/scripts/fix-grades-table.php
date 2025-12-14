<?php
// Fix Grades Table - Add missing columns if they don't exist
$conn = new mysqli('localhost', 'root', '', 'ccsgrading_db');

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>Fixing Grades Table...</h2>";

// Check and add missing columns
$columns_to_add = [
    'quiz' => 'DECIMAL(5,2) DEFAULT NULL',
    'midterm' => 'DECIMAL(5,2) DEFAULT NULL',
    'final' => 'DECIMAL(5,2) DEFAULT NULL',
    'assignment' => 'DECIMAL(5,2) DEFAULT NULL',
    'participation' => 'DECIMAL(5,2) DEFAULT NULL',
    'overall_grade' => 'DECIMAL(5,2) DEFAULT NULL',
    'final_grade' => 'DECIMAL(5,2) DEFAULT NULL',
    'letter_grade' => 'VARCHAR(2) DEFAULT NULL',
    'submitted_date' => 'TIMESTAMP NULL DEFAULT NULL'
];

// Get existing columns
$result = $conn->query("DESCRIBE grades");
if (!$result) {
    die("Error describing table: " . $conn->error);
}

$existing_columns = [];
while ($row = $result->fetch_assoc()) {
    $existing_columns[] = $row['Field'];
}

echo "<p>Existing columns: " . implode(", ", $existing_columns) . "</p>";

// Add missing columns
foreach ($columns_to_add as $column => $type) {
    if (!in_array($column, $existing_columns)) {
        $sql = "ALTER TABLE grades ADD COLUMN $column $type";
        if ($conn->query($sql)) {
            echo "<p style='color: green;'>✓ Added column: <strong>$column</strong></p>";
        } else {
            echo "<p style='color: red;'>✗ Error adding column $column: " . $conn->error . "</p>";
        }
    } else {
        echo "<p style='color: blue;'>ℹ Column <strong>$column</strong> already exists</p>";
    }
}

echo "<h3 style='color: green; margin-top: 20px;'>✓ Grades table has been fixed!</h3>";
echo "<p><a href='../faculty/submit-grades.php'>Go back to Submit Grades</a></p>";

$conn->close();
?>
