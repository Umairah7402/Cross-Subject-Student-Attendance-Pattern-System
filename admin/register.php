<?php 
session_start();
include('db_connect.php');

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Fetch subjects for lecturer assignment
$subjects = [];
$subjects_sql = "SELECT subject_id, subject_name FROM subjects ORDER BY subject_name";
$subjects_result = $conn->query($subjects_sql);
if ($subjects_result) {
    while ($row = $subjects_result->fetch_assoc()) {
        $subjects[] = $row;
    }
}

$message = '';
$message_class = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role = $_POST['role'];
    $lecturer_name = trim($_POST['lecturer_name']) ?? '';
    $email = trim($_POST['email']) ?? '';
    $subject_id = $_POST['subject_id'] ?? null;

    // Validate inputs
    $errors = [];

    // Check if username already exists
    $check_sql = "SELECT user_id FROM users WHERE username = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("s", $username);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $errors[] = "Username already exists!";
    }

    // Validate email if provided for lecturer
    if ($role === 'lecturer' && !empty($email)) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Please enter a valid email address!";
        }
        
        // Check if email already exists in lecturers table
        $email_check_sql = "SELECT lecturer_id FROM lecturers WHERE email = ?";
        $email_check_stmt = $conn->prepare($email_check_sql);
        $email_check_stmt->bind_param("s", $email);
        $email_check_stmt->execute();
        $email_check_result = $email_check_stmt->get_result();

        if ($email_check_result->num_rows > 0) {
            $errors[] = "Email address already registered for another lecturer!";
        }
    }

    // Validate subject selection for lecturer
    if ($role === 'lecturer' && empty($subject_id)) {
        $errors[] = "Please select a subject for the lecturer!";
    }

    // Password validation
    if (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long!";
    }

    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match!";
    }

    // If no errors, proceed with registration
    if (empty($errors)) {
        // Hash the password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Start transaction
        $conn->begin_transaction();

        try {
            // Insert into users table
            $user_sql = "INSERT INTO users (username, password, role) VALUES (?, ?, ?)";
            $user_stmt = $conn->prepare($user_sql);
            $user_stmt->bind_param("sss", $username, $hashed_password, $role);

            if ($user_stmt->execute()) {
                $user_id = $conn->insert_id;

                // If registering a lecturer, also insert into lecturers table
                if ($role === 'lecturer' && !empty($lecturer_name) && !empty($email) && !empty($subject_id)) {
                    // Check if lecturers table has subject_id column
                    $check_columns_sql = "SHOW COLUMNS FROM lecturers LIKE 'subject_id'";
                    $columns_result = $conn->query($check_columns_sql);
                    $has_subject_id = $columns_result->num_rows > 0;
                    
                    if ($has_subject_id) {
                        // If lecturers table has subject_id column, insert with subject_id
                        $lecturer_sql = "INSERT INTO lecturers (lecturer_name, email, subject_id, user_id) VALUES (?, ?, ?, ?)";
                        $lecturer_stmt = $conn->prepare($lecturer_sql);
                        $lecturer_stmt->bind_param("ssii", $lecturer_name, $email, $subject_id, $user_id);
                    } else {
                        // If lecturers table doesn't have subject_id column, insert without it
                        $lecturer_sql = "INSERT INTO lecturers (lecturer_name, email, user_id) VALUES (?, ?, ?)";
                        $lecturer_stmt = $conn->prepare($lecturer_sql);
                        $lecturer_stmt->bind_param("ssi", $lecturer_name, $email, $user_id);
                    }
                    
                    if ($lecturer_stmt->execute()) {
                        $lecturer_id = $conn->insert_id;
                        
                        // Insert into lecturer_subjects table if it exists
                        $check_lecturer_subjects_sql = "SHOW TABLES LIKE 'lecturer_subjects'";
                        $lecturer_subjects_result = $conn->query($check_lecturer_subjects_sql);
                        
                        if ($lecturer_subjects_result->num_rows > 0) {
                            $lecturer_subject_sql = "INSERT INTO lecturer_subjects (lecturer_id, subject_id) VALUES (?, ?)";
                            $lecturer_subject_stmt = $conn->prepare($lecturer_subject_sql);
                            $lecturer_subject_stmt->bind_param("ii", $lecturer_id, $subject_id);
                            
                            if (!$lecturer_subject_stmt->execute()) {
                                throw new Exception("Error assigning subject to lecturer: " . $lecturer_subject_stmt->error);
                            }
                        }
                    } else {
                        throw new Exception("Error creating lecturer profile: " . $lecturer_stmt->error);
                    }
                }

                // Commit transaction
                $conn->commit();
                
                $message = "✅ User registered successfully!";
                $message_class = "success";
                
                // Clear form
                $_POST = array();
            } else {
                throw new Exception("Error creating user: " . $user_stmt->error);
            }
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $message = "❌ " . $e->getMessage();
            $message_class = "error";
        }
    } else {
        $message = "❌ " . implode("<br>❌ ", $errors);
        $message_class = "error";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register New User - CSSAP Admin</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .admin-container {
            display: flex;
            width: 100%;
            max-width: 1200px;
            min-height: 800px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        /* Sidebar Styles */
        .sidebar {
            width: 280px;
            background: linear-gradient(135deg, #2c3e50, #34495e);
            color: white;
            padding: 30px 0;
        }

        .sidebar-header {
            padding: 0 25px 25px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 25px;
        }

        .sidebar-header h2 {
            font-size: 1.5rem;
            margin-bottom: 5px;
            color: #ecf0f1;
        }

        .sidebar-header p {
            font-size: 0.9rem;
            opacity: 0.7;
        }

        .nav-links {
            list-style: none;
            padding: 0 15px;
        }

        .nav-links li {
            margin-bottom: 8px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            color: #bdc3c7;
            text-decoration: none;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .nav-links a:hover {
            background: rgba(255,255,255,0.1);
            color: white;
        }

        .nav-links a.active {
            background: #3498db;
            color: white;
        }

        .nav-icon {
            margin-right: 10px;
            font-size: 1.1rem;
        }

        /* Main Content Styles */
        .main-content {
            flex: 1;
            padding: 40px;
            background: #f8f9fa;
            overflow-y: auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            padding-bottom: 25px;
            border-bottom: 2px solid #e9ecef;
        }

        .header h1 {
            color: #2c3e50;
            font-size: 2.2rem;
            font-weight: 700;
        }

        .user-welcome {
            text-align: right;
        }

        .user-welcome .name {
            font-weight: 600;
            color: #2c3e50;
            font-size: 1.1rem;
        }

        .user-welcome .role {
            color: #7f8c8d;
            font-size: 0.9rem;
        }

        /* Form Container */
        .form-container {
            background: white;
            border-radius: 15px;
            padding: 40px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            max-width: 600px;
            margin: 0 auto;
        }

        .form-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .form-header h2 {
            color: #2c3e50;
            font-size: 1.8rem;
            margin-bottom: 10px;
        }

        .form-header p {
            color: #7f8c8d;
            font-size: 1rem;
        }

        .form-group {
            margin-bottom: 25px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 14px;
        }

        input[type="text"], input[type="email"], input[type="password"], select {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        input:focus, select:focus {
            outline: none;
            border-color: #3498db;
            background: white;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .btn {
            padding: 15px 30px;
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
            margin-top: 10px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(52, 152, 219, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #95a5a6, #7f8c8d);
        }

        .btn-secondary:hover {
            box-shadow: 0 8px 20px rgba(149, 165, 166, 0.3);
        }

        .message {
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            text-align: center;
            font-weight: 500;
            line-height: 1.5;
        }

        .success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
            border: 1px solid #b1dfbb;
        }

        .error {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
            border: 1px solid #f1b0b7;
        }

        .form-note {
            font-size: 0.8rem;
            color: #7f8c8d;
            margin-top: 5px;
            display: block;
        }

        .lecturer-fields {
            background: #ecf0f1;
            padding: 25px;
            border-radius: 10px;
            border-left: 4px solid #3498db;
            margin-top: 15px;
            display: none;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .password-strength {
            height: 6px;
            background: #e9ecef;
            border-radius: 3px;
            margin-top: 8px;
            overflow: hidden;
        }

        .strength-bar {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
            border-radius: 3px;
        }

        .strength-weak { background: #e74c3c; width: 33%; }
        .strength-medium { background: #f39c12; width: 66%; }
        .strength-strong { background: #27ae60; width: 100%; }

        .requirements {
            margin-top: 20px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            border-left: 4px solid #f39c12;
        }

        .requirements h4 {
            color: #2c3e50;
            margin-bottom: 10px;
            font-size: 1rem;
        }

        .requirements ul {
            list-style: none;
            padding-left: 0;
        }

        .requirements li {
            padding: 5px 0;
            color: #7f8c8d;
            font-size: 0.9rem;
        }

        .requirements li:before {
            content: "•";
            color: #3498db;
            font-weight: bold;
            display: inline-block;
            width: 1em;
            margin-left: -1em;
        }

        .no-subjects {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 20px;
        }

        .no-subjects a {
            color: #3498db;
            text-decoration: none;
            font-weight: 600;
        }

        .no-subjects a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>CSSAP</h2>
                <p>Admin Portal</p>
            </div>
            <ul class="nav-links">
                <li><a href="index.php" class="active">📊 Dashboard</a></li>
                <li><a href="users.php">👥 User Management</a></li>
                <li><a href="lecturers.php">👨‍🏫 Lecturer Management</a></li>
                <li><a href="students.php">🎓 Student Management</a></li>
                <li><a href="subjects.php">📚 Subject Management</a></li>
                <li><a href="attendance.php">📝 Attendance Overview</a></li>
                <li><a href="interventions.php">🔄 Interventions</a></li>
                <li><a href="reports.php">📈 System Reports</a></li>
                <li><a href="profile.php">⚙️ My Profile</a></li>
                <li><a href="logout.php">🚪 Logout</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <h1>Register New User</h1>
                <div class="user-welcome">
                    <div class="name">Welcome, Admin</div>
                    <div class="role">Administrator</div>
                </div>
            </div>

            <div class="form-container">
                <div class="form-header">
                    <h2>Create New Account</h2>
                    <p>Add new administrators or lecturers to the system</p>
                </div>

                <?php if ($message): ?>
                    <div class="message <?php echo $message_class; ?>">
                        <?php echo $message; ?>
                    </div>
                <?php endif; ?>

                <?php if (empty($subjects) && isset($_POST['role']) && $_POST['role'] === 'lecturer'): ?>
                    <div class="no-subjects">
                        <strong>⚠️ No Subjects Available</strong><br>
                        You need to create subjects first before registering lecturers.<br>
                        <a href="subjects.php">Create Subjects Here</a>
                    </div>
                <?php endif; ?>

                <form method="POST" id="registerForm">
                    <div class="form-group">
                        <label for="role">User Role *</label>
                        <select name="role" id="role" required>
                            <option value="">Select Role</option>
                            <option value="admin" <?php echo isset($_POST['role']) && $_POST['role'] === 'admin' ? 'selected' : ''; ?>>Administrator</option>
                            <option value="lecturer" <?php echo isset($_POST['role']) && $_POST['role'] === 'lecturer' ? 'selected' : ''; ?>>Lecturer</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="username">Username *</label>
                        <input type="text" name="username" id="username" 
                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" 
                               required placeholder="Enter username">
                        <span class="form-note">For lecturers, use their email address as username</span>
                    </div>

                    <!-- Lecturer-specific fields -->
                    <div id="lecturerFields" class="lecturer-fields">
                        <div class="form-group">
                            <label for="lecturer_name">Lecturer Full Name *</label>
                            <input type="text" name="lecturer_name" id="lecturer_name" 
                                   value="<?php echo isset($_POST['lecturer_name']) ? htmlspecialchars($_POST['lecturer_name']) : ''; ?>" 
                                   placeholder="Enter full name">
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address *</label>
                            <input type="email" name="email" id="email" 
                                   value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" 
                                   placeholder="Enter email address">
                        </div>

                        <div class="form-group">
                            <label for="subject_id">Assigned Subject *</label>
                            <select name="subject_id" id="subject_id">
                                <option value="">Select Subject</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['subject_id']; ?>" 
                                        <?php echo (isset($_POST['subject_id']) && $_POST['subject_id'] == $subject['subject_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($subjects)): ?>
                                <span class="form-note" style="color: #e74c3c;">No subjects available. Please create subjects first.</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password">Password *</label>
                        <input type="password" name="password" id="password" required 
                               placeholder="Enter password">
                        <div class="password-strength">
                            <div class="strength-bar" id="passwordStrength"></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm Password *</label>
                        <input type="password" name="confirm_password" id="confirm_password" required 
                               placeholder="Confirm your password">
                    </div>

                    <div class="requirements">
                        <h4>Password Requirements:</h4>
                        <ul>
                            <li>Minimum 6 characters</li>
                            <li>Include uppercase and lowercase letters</li>
                            <li>Include numbers for stronger security</li>
                            <li>Special characters recommended</li>
                        </ul>
                    </div>

                    <button type="submit" class="btn" id="submitBtn">Create User Account</button>
                    <a href="users.php" class="btn btn-secondary">View All Users</a>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const roleSelect = document.getElementById('role');
            const lecturerFields = document.getElementById('lecturerFields');
            const passwordInput = document.getElementById('password');
            const confirmPasswordInput = document.getElementById('confirm_password');
            const passwordStrength = document.getElementById('passwordStrength');
            const lecturerNameInput = document.getElementById('lecturer_name');
            const emailInput = document.getElementById('email');
            const subjectSelect = document.getElementById('subject_id');
            const submitBtn = document.getElementById('submitBtn');
            const subjects = <?php echo json_encode($subjects); ?>;

            // Show/hide lecturer fields based on role selection
            roleSelect.addEventListener('change', function() {
                if (this.value === 'lecturer') {
                    lecturerFields.style.display = 'block';
                    lecturerNameInput.required = true;
                    emailInput.required = true;
                    subjectSelect.required = true;
                    
                    // Disable submit button if no subjects available
                    if (subjects.length === 0) {
                        submitBtn.disabled = true;
                        submitBtn.style.opacity = '0.6';
                        submitBtn.style.cursor = 'not-allowed';
                    } else {
                        submitBtn.disabled = false;
                        submitBtn.style.opacity = '1';
                        submitBtn.style.cursor = 'pointer';
                    }
                } else {
                    lecturerFields.style.display = 'none';
                    lecturerNameInput.required = false;
                    emailInput.required = false;
                    subjectSelect.required = false;
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '1';
                    submitBtn.style.cursor = 'pointer';
                }
            });

            // Password strength indicator
            passwordInput.addEventListener('input', function() {
                const password = this.value;
                let strength = 0;

                if (password.length >= 6) strength++;
                if (password.length >= 8) strength++;
                if (/[A-Z]/.test(password)) strength++;
                if (/[0-9]/.test(password)) strength++;
                if (/[^A-Za-z0-9]/.test(password)) strength++;

                passwordStrength.className = 'strength-bar';
                if (password.length === 0) {
                    passwordStrength.style.width = '0%';
                } else if (strength <= 2) {
                    passwordStrength.className += ' strength-weak';
                } else if (strength <= 4) {
                    passwordStrength.className += ' strength-medium';
                } else {
                    passwordStrength.className += ' strength-strong';
                }
            });

            // Password confirmation validation
            confirmPasswordInput.addEventListener('input', function() {
                if (this.value !== passwordInput.value) {
                    this.setCustomValidity('Passwords do not match');
                } else {
                    this.setCustomValidity('');
                }
            });

            // Form validation
            document.getElementById('registerForm').addEventListener('submit', function(e) {
                const password = passwordInput.value;
                const confirmPassword = confirmPasswordInput.value;

                if (password.length < 6) {
                    e.preventDefault();
                    alert('❌ Password must be at least 6 characters long!');
                    return false;
                }

                if (password !== confirmPassword) {
                    e.preventDefault();
                    alert('❌ Passwords do not match!');
                    return false;
                }

                if (roleSelect.value === 'lecturer') {
                    if (!lecturerNameInput.value.trim()) {
                        e.preventDefault();
                        alert('❌ Please enter lecturer name!');
                        return false;
                    }
                    if (!emailInput.value.trim()) {
                        e.preventDefault();
                        alert('❌ Please enter email address!');
                        return false;
                    }
                    if (!subjectSelect.value) {
                        e.preventDefault();
                        alert('❌ Please select a subject for the lecturer!');
                        return false;
                    }
                }
            });

            // Trigger change event on page load
            roleSelect.dispatchEvent(new Event('change'));
        });
    </script>
</body>
</html>