<?php
session_start();
include('db_connect.php');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // FIXED: Join tables using user_id = user_id (not user_id = lecturer_id)
    $sql = "SELECT u.*, l.lecturer_id, l.lecturer_name, l.email 
            FROM users u 
            JOIN lecturers l ON u.user_id = l.user_id
            WHERE u.username = ? AND u.role = 'lecturer'";
    
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            
            if (password_verify($password, $row['password'])) {
                // Set session variables
                $_SESSION['user_id'] = $row['user_id'];
                $_SESSION['username'] = $row['username'];
                $_SESSION['role'] = $row['role'];
                $_SESSION['lecturer_id'] = $row['lecturer_id'];
                $_SESSION['lecturer_name'] = $row['lecturer_name'];
                $_SESSION['email'] = $row['email'];
                
                header("Location: lecturer_dashboard.php");
                exit();
            } else {
                $error = "Invalid password!";
            }
        } else {
            $error = "Lecturer not found! Please check your username.";
        }
    } else {
        $error = "Database error: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Login - CSSAP</title>
    <style>
        /* Your existing CSS remains the same */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #28a745, #20c997);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .login-container {
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
            position: relative;
            overflow: hidden;
        }

        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #28a745, #20c997);
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .header h1 {
            color: #28a745;
            font-size: 2rem;
            margin-bottom: 10px;
            font-weight: 700;
        }

        .header p {
            color: #6c757d;
            font-size: 0.9rem;
        }

        .logo {
            font-size: 3rem;
            margin-bottom: 15px;
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

        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        input[type="text"]:focus, input[type="password"]:focus {
            outline: none;
            border-color: #28a745;
            background: white;
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
        }

        .btn-login {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s ease;
            margin-top: 10px;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 500;
            line-height: 1.5;
        }

        .error {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
            border: 1px solid #f1b0b7;
        }

        .success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
            border: 1px solid #b1dfbb;
        }

        .info {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            color: #856404;
            border: 1px solid #ffeaa7;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            text-align: center;
        }

        .login-footer {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
            color: #6c757d;
            font-size: 0.8rem;
        }

        .login-footer a {
            color: #28a745;
            text-decoration: none;
            font-weight: 600;
        }

        .login-footer a:hover {
            text-decoration: underline;
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
            color: #6c757d;
            cursor: pointer;
            font-size: 1.1rem;
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 30px 20px;
            }
            
            .header h1 {
                font-size: 1.7rem;
            }
        }
    </style>
</head>
<body>

<div class="login-container">
    <div class="header">
        <div class="logo">👨‍🏫</div>
        <h1>Lecturer Login</h1>
        <p>CSSAP - Cross-Subject Student Attendance Pattern</p>
    </div>
    
    <?php if (isset($error)): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <?php if (isset($_GET['logout']) && $_GET['logout'] == 'success'): ?>
        <div class="success">
            ✅ You have been successfully logged out.
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['session_expired']) && $_GET['session_expired'] == 'true'): ?>
        <div class="info">
            ⚠️ Your session has expired. Please login again.
        </div>
    <?php endif; ?>
    
    <div class="info">
        <strong>📝 Login Instructions:</strong><br>
        Use your registered username to access the lecturer portal.
    </div>
    
    <form method="POST" id="loginForm">
        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" 
                   id="username" 
                   name="username" 
                   required 
                   placeholder="Enter your username"
                   value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
        </div>

        <div class="form-group password-toggle">
            <label for="password">Password</label>
            <input type="password" 
                   id="password" 
                   name="password" 
                   required 
                   placeholder="Enter your password">
            <button type="button" class="toggle-password" onclick="togglePassword()">👁️</button>
        </div>

        <button type="submit" class="btn-login">🔐 Login to Portal</button>
    </form>

    <div class="login-footer">
        <p>Having trouble logging in? <a href="mailto:admin@cssap.edu">Contact Administrator</a></p>
        <p>&copy; <?php echo date('Y'); ?> CSSAP System</p>
    </div>
</div>

<script>
    // Toggle password visibility
    function togglePassword() {
        const passwordInput = document.getElementById('password');
        const toggleButton = document.querySelector('.toggle-password');
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            toggleButton.textContent = '🙈';
        } else {
            passwordInput.type = 'password';
            toggleButton.textContent = '👁️';
        }
    }

    // Form validation
    document.getElementById('loginForm').addEventListener('submit', function(e) {
        const username = document.getElementById('username').value.trim();
        const password = document.getElementById('password').value.trim();
        
        if (!username) {
            alert('Please enter your username');
            e.preventDefault();
            return;
        }
        
        if (!password) {
            alert('Please enter your password');
            e.preventDefault();
            return;
        }
        
        // Show loading state
        const submitBtn = document.querySelector('.btn-login');
        submitBtn.innerHTML = '⏳ Logging in...';
        submitBtn.disabled = true;
    });

    // Auto-focus username field
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('username').focus();
        
        // Clear any existing session data (for security)
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href.split('?')[0]);
        }
    });

    // Enter key support
    document.getElementById('password').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            document.getElementById('loginForm').submit();
        }
    });
</script>

</body>
</html>