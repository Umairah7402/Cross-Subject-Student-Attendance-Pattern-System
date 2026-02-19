<?php
session_start();

// Check if user confirmed logout
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['confirm_logout'])) {
    // Log the logout activity
    if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
        $user_id = $_SESSION['user_id'];
        $role = $_SESSION['role'];
        $username = $_SESSION['lecturer_name'] ?? 'Unknown';
        
        // Log logout activity (you could save this to a database)
        error_log("CSSAP System - User logout: ID $user_id, Role: $role, Name: $username, Time: " . date('Y-m-d H:i:s'));
    }

    // Clear all session variables
    $_SESSION = array();

    // Destroy the session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], 
            $params["domain"], 
            $params["secure"], 
            $params["httponly"]
        );
    }

    // Destroy the session
    session_destroy();

    // Redirect to login page with success message
    header("Location: login.php?logout=success");
    exit();
}

// If not confirmed, show confirmation page
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logout Confirmation - CSSAP</title>
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

        .logout-container {
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            text-align: center;
            max-width: 400px;
            width: 100%;
        }

        .logout-icon {
            font-size: 4rem;
            color: #6c757d;
            margin-bottom: 20px;
        }

        h1 {
            color: #495057;
            margin-bottom: 15px;
            font-size: 1.8rem;
        }

        p {
            color: #6c757d;
            margin-bottom: 30px;
            line-height: 1.6;
        }

        .user-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
            border-left: 4px solid #28a745;
        }

        .user-name {
            font-weight: 600;
            color: #495057;
            font-size: 1.1rem;
        }

        .user-role {
            color: #6c757d;
            font-size: 0.9rem;
        }

        .btn-group {
            display: flex;
            gap: 15px;
            justify-content: center;
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn-logout {
            background: #dc3545;
            color: white;
        }

        .btn-logout:hover {
            background: #c82333;
            transform: translateY(-2px);
        }

        .btn-cancel {
            background: #6c757d;
            color: white;
        }

        .btn-cancel:hover {
            background: #545b62;
            transform: translateY(-2px);
        }

        .security-note {
            font-size: 0.8rem;
            color: #868e96;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #e9ecef;
        }

        @media (max-width: 480px) {
            .logout-container {
                padding: 30px 20px;
            }
            
            .btn-group {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="logout-container">
        <div class="logout-icon">
            🔐
        </div>
        
        <h1>Confirm Logout</h1>
        
        <p>Are you sure you want to logout from the CSSAP system?</p>
        
        <?php if (isset($_SESSION['lecturer_name'])): ?>
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($_SESSION['lecturer_name']); ?></div>
                <div class="user-role">Lecturer Account</div>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="btn-group">
                <button type="submit" name="confirm_logout" class="btn btn-logout">
                    Yes, Logout
                </button>
                <a href="lecturer_dashboard.php" class="btn btn-cancel">
                    Cancel
                </a>
            </div>
        </form>
        
        <div class="security-note">
            For security reasons, please close all browser windows after logging out.
        </div>
    </div>
</body>
</html>