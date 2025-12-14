<?php
// Database Initialization Script
$conn = new mysqli('localhost', 'root', '', '');

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database
$sql = "CREATE DATABASE IF NOT EXISTS ccsgrading_db";
if ($conn->query($sql) === TRUE) {
    echo "Database created successfully<br>";
} else {
    echo "Error creating database: " . $conn->error . "<br>";
}

// Select database
$conn->select_db('ccsgrading_db');

// Create users table
$sql = "CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'faculty', 'student') NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
$conn->query($sql);

// Create courses table
$sql = "CREATE TABLE IF NOT EXISTS courses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    course_code VARCHAR(50) UNIQUE NOT NULL,
    course_name VARCHAR(255) NOT NULL,
    description TEXT,
    credits INT DEFAULT 3,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($sql);

// Create terms table
$sql = "CREATE TABLE IF NOT EXISTS terms (
    id INT PRIMARY KEY AUTO_INCREMENT,
    term_name VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($sql);

// Create sections table
$sql = "CREATE TABLE IF NOT EXISTS sections (
    id INT PRIMARY KEY AUTO_INCREMENT,
    course_id INT NOT NULL,
    section_name VARCHAR(50) NOT NULL,
    faculty_id INT NOT NULL,
    term_id INT NOT NULL,
    max_students INT DEFAULT 50,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id),
    FOREIGN KEY (faculty_id) REFERENCES users(id),
    FOREIGN KEY (term_id) REFERENCES terms(id)
)";
$conn->query($sql);

// Create enrollments table
$sql = "CREATE TABLE IF NOT EXISTS enrollments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    section_id INT NOT NULL,
    enrollment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('enrolled', 'dropped') DEFAULT 'enrolled',
    FOREIGN KEY (student_id) REFERENCES users(id),
    FOREIGN KEY (section_id) REFERENCES sections(id),
    UNIQUE KEY unique_enrollment (student_id, section_id)
)";
$conn->query($sql);

// Create grades table
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
$conn->query($sql);

// Create announcements table
$sql = "CREATE TABLE IF NOT EXISTS announcements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    section_id INT NOT NULL,
    faculty_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (section_id) REFERENCES sections(id),
    FOREIGN KEY (faculty_id) REFERENCES users(id)
)";
$conn->query($sql);

// Create system_logs table
$sql = "CREATE TABLE IF NOT EXISTS system_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(255) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
)";
$conn->query($sql);

// Insert demo users
$demo_users = [
    ['admin@school.com', password_hash('admin123', PASSWORD_BCRYPT), 'Admin', 'User', 'admin'],
    ['faculty@school.com', password_hash('faculty123', PASSWORD_BCRYPT), 'John', 'Doe', 'faculty'],
    ['student@school.com', password_hash('student123', PASSWORD_BCRYPT), 'Jane', 'Smith', 'student']
];

foreach ($demo_users as $user) {
    $sql = "INSERT IGNORE INTO users (email, password, first_name, last_name, role) VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sssss', $user[0], $user[1], $user[2], $user[3], $user[4]);
    $stmt->execute();
}

// Insert demo term
$sql = "INSERT IGNORE INTO terms (term_name, start_date, end_date) VALUES ('Fall 2024', '2024-09-01', '2024-12-15')";
$conn->query($sql);

// Insert demo course
$sql = "INSERT IGNORE INTO courses (course_code, course_name, description, credits) VALUES ('CS101', 'Introduction to Computer Science', 'Basic concepts of programming', 3)";
$conn->query($sql);

echo "Database initialized successfully!<br>";
echo "Demo Credentials:<br>";
echo "Admin: admin@school.com / admin123<br>";
echo "Faculty: faculty@school.com / faculty123<br>";
echo "Student: student@school.com / student123<br>";

$conn->close();
?>
