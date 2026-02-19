
-- Create Database
CREATE DATABASE IF NOT EXISTS cssap;

USE cssap;

-- Subjects Table
CREATE TABLE IF NOT EXISTS subjects (
    subject_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL
);

-- Students Table
CREATE TABLE IF NOT EXISTS students (
    student_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
);

-- Attendance Table
CREATE TABLE IF NOT EXISTS attendance (
    attendance_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    subject_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    status ENUM('present', 'absent') NOT NULL,
    FOREIGN KEY (student_id) REFERENCES students(student_id),
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id)
);

-- Users Table (Admin and Lecturers)
CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'lecturer') NOT NULL
);

-- Interventions Table
CREATE TABLE IF NOT EXISTS interventions (
    intervention_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    subject_id INT NOT NULL,
    intervention_date DATE NOT NULL,
    comments TEXT,
    FOREIGN KEY (student_id) REFERENCES students(student_id),
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id)
);

-- Insert Default Data

INSERT INTO subjects (name) VALUES
    ('Mathematics'),
    ('Science'),
    ('History');

INSERT INTO users (username, password, role) VALUES
    ('admin', '" . password_hash('adminpassword', PASSWORD_DEFAULT) . "', 'admin'),
    ('lecturer1', '" . password_hash('lecturerpassword', PASSWORD_DEFAULT) . "', 'lecturer');

-- Insert Dummy Data for Lecturers
INSERT INTO lecturers (lecturer_name, email, subject_id) VALUES
    ('John Doe', 'john.doe@example.com', 1),  -- Lecturer for Mathematics
    ('Jane Smith', 'jane.smith@example.com', 2), -- Lecturer for Science
    ('Robert Brown', 'robert.brown@example.com', 3); -- Lecturer for History

-- Create Database
CREATE DATABASE IF NOT EXISTS cssap;

USE cssap;

-- Subjects Table
CREATE TABLE IF NOT EXISTS subjects (
    subject_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL
);

-- Insert Subjects
INSERT INTO subjects (name) VALUES
    ('Mathematics'),
    ('Science'),
    ('History');

-- Lecturer Table
CREATE TABLE IF NOT EXISTS lecturers (
    lecturer_id INT AUTO_INCREMENT PRIMARY KEY,
    lecturer_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    subject_id INT NOT NULL,
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id)
);

-- Insert Dummy Data for Lecturers
INSERT INTO lecturers (lecturer_name, email, subject_id) VALUES
    ('John Doe', 'john.doe@example.com', 1),  -- Lecturer for Mathematics
    ('Jane Smith', 'jane.smith@example.com', 2), -- Lecturer for Science
    ('Robert Brown', 'robert.brown@example.com', 3); -- Lecturer for History

-- Drop the existing foreign key constraint (if pointing to the wrong table)
ALTER TABLE students DROP FOREIGN KEY students_ibfk_1;

-- Add the correct foreign key constraint referencing the subjects table
ALTER TABLE students ADD CONSTRAINT FOREIGN KEY (subject_id) REFERENCES subjects(subject_id);

-- Insert Dummy Data for Students
INSERT INTO students (name, subject_id) VALUES
    ('Alice Johnson', 1),  -- Student for Mathematics
    ('Bob Smith', 2),      -- Student for Science
    ('Charlie Brown', 3),  -- Student for History
    ('David Williams', 1), -- Student for Mathematics
    ('Eve Davis', 2),      -- Student for Science
    ('Frank Miller', 3),   -- Student for History
    ('Grace Lee', 1),      -- Student for Mathematics
    ('Hannah Wilson', 2);  -- Student for Science

-- Attendance Table
CREATE TABLE IF NOT EXISTS attendance (
    attendance_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    subject_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    status ENUM('present', 'absent') NOT NULL,
    FOREIGN KEY (student_id) REFERENCES students(student_id),
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id)
);
