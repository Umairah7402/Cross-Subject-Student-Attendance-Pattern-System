<?php
session_start();
include('db_connect.php');

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$message = '';
$message_class = '';

// Get current admin data
$admin_id = $_SESSION['user_id'];

// First, let's check if the admins table exists and has the correct structure
$admin_data = [
    'username' => $_SESSION['username'] ?? 'Admin',
    'admin_name' => 'Administrator',
    'email' => 'admin@cssap.edu'
];

// Try to get admin data from database, but handle errors gracefully
try {
    $admin_sql = "SELECT u.username, a.admin_name, a.email FROM admins a 
                  JOIN users u ON a.user_id = u.user_id 
                  WHERE u.user_id = ?";
    $admin_stmt = $conn->prepare($admin_sql);
    
    if ($admin_stmt) {
        $admin_stmt->bind_param("i", $admin_id);
        $admin_stmt->execute();
        $admin_result = $admin_stmt->get_result();
        
        if ($admin_result && $admin_result->num_rows > 0) {
            $admin_data = $admin_result->fetch_assoc();
        } else {
            // If no admin data found, use session data
            error_log("No admin profile data found for user ID: " . $admin_id);
        }
    } else {
        error_log("Failed to prepare admin query: " . $conn->error);
    }
} catch (Exception $e) {
    error_log("Error fetching admin data: " . $e->getMessage());
}

// Handle profile update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_profile'])) {
    $admin_name = trim($_POST['admin_name']);
    $email = trim($_POST['email']);
    $username = trim($_POST['username']);
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate required fields
    if (empty($admin_name) || empty($email) || empty($username)) {
        $message = "❌ Please fill in all required fields!";
        $message_class = "error";
    } else {
        $update_success = true;
        
        // Check if password change is requested
        if (!empty($current_password)) {
            // Verify current password
            $verify_sql = "SELECT password FROM users WHERE user_id = ?";
            $verify_stmt = $conn->prepare($verify_sql);
            
            if ($verify_stmt) {
                $verify_stmt->bind_param("i", $admin_id);
                $verify_stmt->execute();
                $verify_result = $verify_stmt->get_result();
                
                if ($verify_result && $verify_result->num_rows > 0) {
                    $user_data = $verify_result->fetch_assoc();
                    
                    if (!password_verify($current_password, $user_data['password'])) {
                        $message = "❌ Current password is incorrect!";
                        $message_class = "error";
                        $update_success = false;
                    } elseif ($new_password !== $confirm_password) {
                        $message = "❌ New passwords do not match!";
                        $message_class = "error";
                        $update_success = false;
                    } elseif (strlen($new_password) < 6) {
                        $message = "❌ New password must be at least 6 characters long!";
                        $message_class = "error";
                        $update_success = false;
                    } else {
                        // Update password
                        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                        $update_password_sql = "UPDATE users SET password = ? WHERE user_id = ?";
                        $update_password_stmt = $conn->prepare($update_password_sql);
                        
                        if ($update_password_stmt) {
                            $update_password_stmt->bind_param("si", $hashed_password, $admin_id);
                            if (!$update_password_stmt->execute()) {
                                $message = "❌ Error updating password: " . $update_password_stmt->error;
                                $message_class = "error";
                                $update_success = false;
                            }
                        } else {
                            $message = "❌ Error preparing password update query";
                            $message_class = "error";
                            $update_success = false;
                        }
                    }
                } else {
                    $message = "❌ Error verifying current password";
                    $message_class = "error";
                    $update_success = false;
                }
            } else {
                $message = "❌ Error preparing password verification query";
                $message_class = "error";
                $update_success = false;
            }
        }
        
        // Update admin profile if no errors
        if ($update_success && empty($message)) {
            // Update username in users table
            $update_username_sql = "UPDATE users SET username = ? WHERE user_id = ?";
            $update_username_stmt = $conn->prepare($update_username_sql);
            
            if ($update_username_stmt) {
                $update_username_stmt->bind_param("si", $username, $admin_id);
                if ($update_username_stmt->execute()) {
                    $_SESSION['username'] = $username;
                } else {
                    $message = "❌ Error updating username: " . $update_username_stmt->error;
                    $message_class = "error";
                    $update_success = false;
                }
            }
            
            // Try to update admin profile if admins table exists
            if ($update_success) {
                $update_admin_sql = "UPDATE admins SET admin_name = ?, email = ? WHERE user_id = ?";
                $update_admin_stmt = $conn->prepare($update_admin_sql);
                
                if ($update_admin_stmt) {
                    $update_admin_stmt->bind_param("ssi", $admin_name, $email, $admin_id);
                    if ($update_admin_stmt->execute()) {
                        $message = "✅ Profile updated successfully!";
                        $message_class = "success";
                        
                        // Update local admin data
                        $admin_data['username'] = $username;
                        $admin_data['admin_name'] = $admin_name;
                        $admin_data['email'] = $email;
                    } else {
                        $message = "❌ Error updating profile: " . $update_admin_stmt->error;
                        $message_class = "error";
                    }
                } else {
                    // If admins table doesn't exist, just update the session
                    $_SESSION['username'] = $username;
                    $message = "✅ Username updated successfully!";
                    $message_class = "success";
                    
                    // Update local admin data
                    $admin_data['username'] = $username;
                    $admin_data['admin_name'] = $admin_name;
                    $admin_data['email'] = $email;
                }
            }
        }
    }
}

// Get admin activity statistics (with error handling)
$activity_data = [
    'users_created' => 0,
    'attendance_managed' => 0,
    'interventions_managed' => 0,
    'last_login' => null
];

try {
    // Simple count queries that should work with your existing structure
    $users_count_sql = "SELECT COUNT(*) as count FROM users WHERE role != 'admin'";
    $users_result = $conn->query($users_count_sql);
    if ($users_result) {
        $activity_data['users_created'] = $users_result->fetch_assoc()['count'];
    }
    
    $attendance_count_sql = "SELECT COUNT(*) as count FROM attendance";
    $attendance_result = $conn->query($attendance_count_sql);
    if ($attendance_result) {
        $activity_data['attendance_managed'] = $attendance_result->fetch_assoc()['count'];
    }
    
    $interventions_count_sql = "SELECT COUNT(*) as count FROM interventions";
    $interventions_result = $conn->query($interventions_count_sql);
    if ($interventions_result) {
        $activity_data['interventions_managed'] = $interventions_result->fetch_assoc()['count'];
    }
    
} catch (Exception $e) {
    error_log("Error fetching activity data: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - CSSAP Admin</title>
    <style>
        /* Keep all the same CSS styles from the previous version */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            color: #333;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 280px;
            background: linear-gradient(135deg, #2c3e50, #34495e);
            color: white;
            padding: 30px 0;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
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
            background: #16a085;
            color: white;
        }

        .nav-icon {
            margin-right: 10px;
            font-size: 1.1rem;
        }

        .main-content {
            flex: 1;
            padding: 30px;
            background: #f8f9fa;
            overflow-y: auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
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

        .profile-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        @media (max-width: 968px) {
            .profile-layout {
                grid-template-columns: 1fr;
            }
        }

        .content-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
        }

        .section-header h2 {
            color: #2c3e50;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .btn {
            padding: 12px 25px;
            background: linear-gradient(135deg, #16a085, #1abc9c);
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-size: 14px;
            font-weight: 600;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(22, 160, 133, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, #f39c12, #e67e22);
        }

        .profile-card {
            text-align: center;
            padding: 30px;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, #16a085, #1abc9c);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 3rem;
            color: white;
            box-shadow: 0 8px 25px rgba(22, 160, 133, 0.3);
        }

        .profile-name {
            font-size: 1.5rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .profile-role {
            color: #7f8c8d;
            margin-bottom: 20px;
            font-size: 1rem;
        }

        .profile-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 25px;
        }

        .profile-stat {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: #16a085;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.8rem;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #495057;
            font-size: 14px;
        }

        .required::after {
            content: " *";
            color: #e74c3c;
        }

        input[type="text"], input[type="email"], input[type="password"] {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        input:focus {
            outline: none;
            border-color: #16a085;
            box-shadow: 0 0 0 3px rgba(22, 160, 133, 0.1);
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e9ecef;
        }

        .password-toggle {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #7f8c8d;
            cursor: pointer;
        }

        .message {
            padding: 15px 20px;
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

        .security-note {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
            font-size: 0.9rem;
            color: #856404;
        }

        .last-login {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
            color: #7f8c8d;
            font-size: 0.9rem;
        }

        .system-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e9ecef;
        }

        .info-label {
            font-weight: 600;
            color: #495057;
            min-width: 120px;
        }

        .info-value {
            color: #7f8c8d;
        }

        .database-warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 10px;
            padding: 15px;
            margin: 15px 0;
            color: #856404;
            text-align: center;
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
                <li><a href="index.php">📊 Dashboard</a></li>
                <li><a href="users.php">👥 User Management</a></li>
                <li><a href="lecturers.php">👨‍🏫 Lecturer Management</a></li>
                <li><a href="students.php">🎓 Student Management</a></li>
                <li><a href="subjects.php">📚 Subject Management</a></li>
                <li><a href="attendance.php">📝 Attendance Overview</a></li>
                <li><a href="interventions.php">🔄 Interventions</a></li>
                <li><a href="reports.php">📈 System Reports</a></li>
                <li><a href="profile.php" class="active">⚙️ My Profile</a></li>
                <li><a href="logout.php">🚪 Logout</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <h1>My Profile</h1>
                <div class="user-welcome">
                    <div class="name">Welcome, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></div>
                    <div class="role">Administrator</div>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="message <?php echo $message_class; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <div class="profile-layout">
                <!-- Profile Information Card -->
                <div class="content-section">
                    <div class="profile-card">
                        <div class="profile-avatar">
                            👨‍💼
                        </div>
                        <div class="profile-name"><?php echo htmlspecialchars($admin_data['admin_name']); ?></div>
                        <div class="profile-role">System Administrator</div>
                        
                        <div class="profile-stats">
                            <div class="profile-stat">
                                <div class="stat-number"><?php echo $activity_data['users_created']; ?></div>
                                <div class="stat-label">System Users</div>
                            </div>
                            <div class="profile-stat">
                                <div class="stat-number"><?php echo $activity_data['attendance_managed']; ?></div>
                                <div class="stat-label">Attendance Records</div>
                            </div>
                            <div class="profile-stat">
                                <div class="stat-number"><?php echo $activity_data['interventions_managed']; ?></div>
                                <div class="stat-label">Interventions</div>
                            </div>
                            <div class="profile-stat">
                                <div class="stat-number"><?php echo $activity_data['users_created'] + $activity_data['attendance_managed'] + $activity_data['interventions_managed']; ?></div>
                                <div class="stat-label">Total Records</div>
                            </div>
                        </div>

                        <div class="last-login">
                            Current session: <?php echo date('M j, Y g:i A'); ?>
                        </div>
                    </div>
                </div>

                <!-- Profile Edit Form -->
                <div class="content-section">
                    <div class="section-header">
                        <h2>Edit Profile Information</h2>
                    </div>

                    <form method="POST">
                        <div class="form-group">
                            <label for="username" class="required">Username</label>
                            <input type="text" id="username" name="username" 
                                   value="<?php echo htmlspecialchars($admin_data['username']); ?>" 
                                   required>
                        </div>

                        <div class="form-group">
                            <label for="admin_name" class="required">Full Name</label>
                            <input type="text" id="admin_name" name="admin_name" 
                                   value="<?php echo htmlspecialchars($admin_data['admin_name']); ?>" 
                                   required>
                        </div>

                        <div class="form-group">
                            <label for="email" class="required">Email Address</label>
                            <input type="email" id="email" name="email" 
                                   value="<?php echo htmlspecialchars($admin_data['email']); ?>" 
                                   required>
                        </div>

                        <div class="security-note">
                            🔒 <strong>Password Security</strong><br>
                            Leave password fields blank if you don't want to change your password.
                        </div>

                        <div class="form-group password-toggle">
                            <label for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password">
                            <button type="button" class="toggle-password" onclick="togglePassword('current_password')">👁️</button>
                        </div>

                        <div class="form-group password-toggle">
                            <label for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password">
                            <button type="button" class="toggle-password" onclick="togglePassword('new_password')">👁️</button>
                        </div>

                        <div class="form-group password-toggle">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password">
                            <button type="button" class="toggle-password" onclick="togglePassword('confirm_password')">👁️</button>
                        </div>

                        <div class="form-actions">
                            <button type="reset" class="btn btn-warning">Reset</button>
                            <button type="submit" name="update_profile" class="btn">Update Profile</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- System Information -->
            <div class="content-section">
                <div class="section-header">
                    <h2>System Information</h2>
                </div>
                
                <div class="system-info">
                    <div class="info-item">
                        <span class="info-label">PHP Version:</span>
                        <span class="info-value"><?php echo phpversion(); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Database:</span>
                        <span class="info-value">MySQL</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Server Time:</span>
                        <span class="info-value"><?php echo date('Y-m-d H:i:s'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Session ID:</span>
                        <span class="info-value"><?php echo session_id(); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const button = input.nextElementSibling;
            
            if (input.type === 'password') {
                input.type = 'text';
                button.textContent = '🙈';
            } else {
                input.type = 'password';
                button.textContent = '👁️';
            }
        }

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const currentPassword = document.getElementById('current_password').value;
            
            // If any password field is filled, all must be filled
            if (newPassword || confirmPassword || currentPassword) {
                if (!currentPassword) {
                    alert('Please enter your current password to change your password.');
                    e.preventDefault();
                    return;
                }
                
                if (!newPassword) {
                    alert('Please enter a new password.');
                    e.preventDefault();
                    return;
                }
                
                if (!confirmPassword) {
                    alert('Please confirm your new password.');
                    e.preventDefault();
                    return;
                }
                
                if (newPassword !== confirmPassword) {
                    alert('New passwords do not match.');
                    e.preventDefault();
                    return;
                }
                
                if (newPassword.length < 6) {
                    alert('New password must be at least 6 characters long.');
                    e.preventDefault();
                    return;
                }
            }
        });

        // Auto-focus first field
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('username').focus();
        });
    </script>
</body>
</html>