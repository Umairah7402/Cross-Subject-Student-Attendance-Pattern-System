<?php
session_start();
include('db_connect.php');

// Check if user is logged in and is a lecturer
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'lecturer') {
    header("Location: login.php");
    exit();
}

$lecturer_id = $_SESSION['lecturer_id'];
$message = '';
$message_class = '';

// Get subjects taught by this lecturer (FIXED: using lecturer_subjects table)
$subjects_sql = "SELECT DISTINCT s.subject_id, s.subject_name 
                 FROM subjects s 
                 JOIN lecturer_subjects ls ON s.subject_id = ls.subject_id 
                 WHERE ls.lecturer_id = ?";
$stmt = $conn->prepare($subjects_sql);
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$subjects_result = $stmt->get_result();

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['mark_attendance'])) {
    $subject_id = $_POST['subject_id'];
    $attendance_date = $_POST['attendance_date'];
    
    // Get students enrolled in this subject (FIXED: using enrollments table)
    $students_sql = "SELECT s.student_id, s.name 
                     FROM students s 
                     JOIN enrollments e ON s.student_id = e.student_id 
                     WHERE e.subject_id = ?";
    $stmt = $conn->prepare($students_sql);
    $stmt->bind_param("i", $subject_id);
    $stmt->execute();
    $students_result = $stmt->get_result();
    
    $success_count = 0;
    $error_count = 0;
    
    // Process each student's attendance
    while ($student = $students_result->fetch_assoc()) {
        $student_id = $student['student_id'];
        // INVERTED LOGIC: If checkbox is ticked, student is ABSENT. If not ticked, student is PRESENT.
        $status = isset($_POST['absent_students'][$student_id]) ? 'absent' : 'present';
        
        // Check if attendance already exists for this student on this date
        $check_sql = "SELECT attendance_id FROM attendance WHERE student_id = ? AND subject_id = ? AND attendance_date = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("iis", $student_id, $subject_id, $attendance_date);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            // Update existing attendance
            $update_sql = "UPDATE attendance SET status = ? WHERE student_id = ? AND subject_id = ? AND attendance_date = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("siis", $status, $student_id, $subject_id, $attendance_date);
            if ($update_stmt->execute()) {
                $success_count++;
            } else {
                $error_count++;
                error_log("Update attendance error: " . $update_stmt->error);
            }
        } else {
            // Insert new attendance
            $insert_sql = "INSERT INTO attendance (student_id, subject_id, attendance_date, status) VALUES (?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("iiss", $student_id, $subject_id, $attendance_date, $status);
            if ($insert_stmt->execute()) {
                $success_count++;
            } else {
                $error_count++;
                error_log("Insert attendance error: " . $insert_stmt->error);
            }
        }
    }
    
    if ($error_count == 0) {
        $message = "✅ Attendance marked successfully for $success_count students!";
        $message_class = "success";
    } else {
        $message = "⚠️ Attendance marked for $success_count students, but $error_count records failed.";
        $message_class = "error";
    }
}

// Get students for selected subject (for AJAX functionality) - FIXED: using enrollments
if (isset($_GET['subject_id']) && isset($_GET['date'])) {
    $subject_id = $_GET['subject_id'];
    $date = $_GET['date'];
    
    $students_sql = "SELECT s.student_id, s.name, a.status 
                     FROM students s 
                     JOIN enrollments e ON s.student_id = e.student_id 
                     LEFT JOIN attendance a ON s.student_id = a.student_id AND a.subject_id = ? AND a.attendance_date = ?
                     WHERE e.subject_id = ?
                     ORDER BY s.name";
    $stmt = $conn->prepare($students_sql);
    $stmt->bind_param("isi", $subject_id, $date, $subject_id);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($students);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mark Attendance - CSSAP</title>
    <style>
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

        .container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .sidebar {
            width: 250px;
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            padding: 20px 0;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
        }

        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.2);
            margin-bottom: 20px;
        }

        .sidebar-header h2 {
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .nav-links {
            list-style: none;
        }

        .nav-links li {
            margin-bottom: 5px;
        }

        .nav-links a {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }

        .nav-links a:hover, .nav-links a.active {
            background: rgba(255,255,255,0.1);
            border-left-color: white;
        }

        .logout-btn {
            margin: 20px;
            padding: 10px;
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            border-radius: 5px;
            cursor: pointer;
            width: calc(100% - 40px);
            transition: background 0.3s ease;
        }

        .logout-btn:hover {
            background: rgba(255,255,255,0.3);
        }

        /* Main Content Styles */
        .main-content {
            flex: 1;
            padding: 20px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #dee2e6;
        }

        .header h1 {
            color: #28a745;
            font-size: 2rem;
        }

        .attendance-form {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #495057;
        }

        select, input[type="date"] {
            width: 100%;
            padding: 10px;
            border: 2px solid #e9ecef;
            border-radius: 5px;
            font-size: 16px;
            transition: border-color 0.3s ease;
        }

        select:focus, input[type="date"]:focus {
            outline: none;
            border-color: #28a745;
        }

        .btn {
            padding: 12px 24px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            transition: background 0.3s ease;
        }

        .btn:hover {
            background: #218838;
        }

        .btn-secondary {
            background: #6c757d;
        }

        .btn-secondary:hover {
            background: #545b62;
        }

        .students-list {
            margin-top: 20px;
        }

        .student-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border: 1px solid #e9ecef;
            border-radius: 5px;
            margin-bottom: 10px;
            background: #f8f9fa;
            transition: background 0.3s ease;
        }

        .student-item:hover {
            background: #e9ecef;
        }

        .student-name {
            flex: 1;
            font-weight: 500;
        }

        .attendance-checkbox {
            transform: scale(1.2);
            margin-right: 10px;
        }

        .message {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 500;
        }

        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .loading {
            text-align: center;
            padding: 20px;
            color: #6c757d;
        }

        .no-students {
            text-align: center;
            padding: 40px;
            color: #6c757d;
            font-style: italic;
        }

        .attendance-summary {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }

        .stat-item {
            text-align: center;
            padding: 10px;
            background: white;
            border-radius: 5px;
            border: 1px solid #dee2e6;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
        }

        .stat-present {
            color: #28a745;
        }

        .stat-absent {
            color: #dc3545;
        }

        .stat-label {
            font-size: 0.8rem;
            color: #6c757d;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            padding: 5px;
            border-radius: 5px;
            transition: background-color 0.3s ease;
        }

        .checkbox-label:hover {
            background-color: #e9ecef;
        }

        .absent-checkbox:checked + .checkbox-text {
            color: #dc3545;
            font-weight: bold;
        }

        .checkbox-text {
            margin-left: 8px;
        }

        .quick-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }

        .quick-btn {
            padding: 8px 16px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s ease;
        }

        .quick-btn:hover {
            background: #545b62;
        }

        .quick-btn.mark-all-present {
            background: #28a745;
        }

        .quick-btn.mark-all-present:hover {
            background: #218838;
        }

        .quick-btn.mark-all-absent {
            background: #dc3545;
        }

        .quick-btn.mark-all-absent:hover {
            background: #c82333;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>CSSAP</h2>
                <p>Lecturer Portal</p>
            </div>
            <ul class="nav-links">
                <li><a href="lecturer_dashboard.php">Dashboard</a></li>
                <li><a href="attendance.php" class="active">Mark Attendance</a></li>
                <li><a href="students.php">Student Management</a></li>
                <li><a href="interventions.php">Interventions</a></li>
                <li><a href="reports.php">Reports</a></li>
                <li><a href="profile.php">My Profile</a></li>
            </ul>
            <form method="POST" action="logout.php">
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <h1>Mark Attendance</h1>
                <div>
                    <span>Welcome, <strong><?php echo htmlspecialchars($_SESSION['lecturer_name']); ?></strong></span>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="message <?php echo $message_class; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <div class="attendance-form">
                <form method="POST" id="attendanceForm">
                    <div class="form-group">
                        <label for="subject_id">Select Subject:</label>
                        <select name="subject_id" id="subject_id" required>
                            <option value="">-- Select Subject --</option>
                            <?php while ($subject = $subjects_result->fetch_assoc()): ?>
                                <option value="<?php echo $subject['subject_id']; ?>">
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="attendance_date">Attendance Date:</label>
                        <input type="date" name="attendance_date" id="attendance_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div id="students-container">
                        <!-- Students list will be loaded here via AJAX -->
                        <div class="no-students">Please select a subject and date to view students</div>
                    </div>

                    <button type="submit" name="mark_attendance" class="btn">📝 Mark Attendance</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const subjectSelect = document.getElementById('subject_id');
            const dateInput = document.getElementById('attendance_date');
            const studentsContainer = document.getElementById('students-container');

            function loadStudents() {
                const subjectId = subjectSelect.value;
                const date = dateInput.value;

                if (!subjectId || !date) {
                    studentsContainer.innerHTML = '<div class="no-students">Please select a subject and date to view students</div>';
                    return;
                }

                studentsContainer.innerHTML = '<div class="loading">🔄 Loading students...</div>';

                fetch(`attendance.php?subject_id=${subjectId}&date=${date}`)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(students => {
                        if (students.length === 0) {
                            studentsContainer.innerHTML = '<div class="no-students">No students enrolled in this subject</div>';
                            return;
                        }

                        let absentCount = 0;
                        let html = `
                            <div class="attendance-summary">
                                <strong>📊 Attendance Summary</strong>
                                <div class="quick-actions">
                                    <button type="button" class="quick-btn mark-all-present" onclick="markAllPresent()">✅ Mark All Present</button>
                                    <button type="button" class="quick-btn mark-all-absent" onclick="markAllAbsent()">❌ Mark All Absent</button>
                                </div>
                                <div class="summary-stats">
                                    <div class="stat-item">
                                        <div class="stat-number">${students.length}</div>
                                        <div class="stat-label">Total Students</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-number stat-present" id="present-count">${students.length}</div>
                                        <div class="stat-label">Present</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-number stat-absent" id="absent-count">0</div>
                                        <div class="stat-label">Absent</div>
                                    </div>
                                </div>
                            </div>
                            <div class="students-list">
                        `;
                        
                        students.forEach(student => {
                            // INVERTED LOGIC: Checkbox checked means student is ABSENT
                            const isChecked = student.status === 'absent' ? 'checked' : '';
                            if (isChecked) absentCount++;
                            
                            html += `
                                <div class="student-item">
                                    <div class="student-name">${student.name}</div>
                                    <label class="checkbox-label">
                                        <input type="checkbox" 
                                               class="attendance-checkbox absent-checkbox" 
                                               name="absent_students[${student.student_id}]" 
                                               value="1" 
                                               ${isChecked}
                                               onchange="updateAttendanceSummary()">
                                        <span class="checkbox-text">❌ Mark as Absent</span>
                                    </label>
                                </div>
                            `;
                        });
                        html += '</div>';
                        studentsContainer.innerHTML = html;
                        
                        // Initialize summary
                        updateAttendanceSummary();
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        studentsContainer.innerHTML = '<div class="error">❌ Error loading students. Please try again.</div>';
                    });
            }

            function updateAttendanceSummary() {
                const checkboxes = document.querySelectorAll('.absent-checkbox');
                const absentCount = Array.from(checkboxes).filter(cb => cb.checked).length;
                const totalStudents = checkboxes.length;
                const presentCount = totalStudents - absentCount;
                
                document.getElementById('present-count').textContent = presentCount;
                document.getElementById('absent-count').textContent = absentCount;
            }

            function markAllPresent() {
                const checkboxes = document.querySelectorAll('.absent-checkbox');
                checkboxes.forEach(checkbox => {
                    checkbox.checked = false;
                });
                updateAttendanceSummary();
            }

            function markAllAbsent() {
                const checkboxes = document.querySelectorAll('.absent-checkbox');
                checkboxes.forEach(checkbox => {
                    checkbox.checked = true;
                });
                updateAttendanceSummary();
            }

            subjectSelect.addEventListener('change', loadStudents);
            dateInput.addEventListener('change', loadStudents);

            // Load students if subject and date are already selected
            if (subjectSelect.value && dateInput.value) {
                loadStudents();
            }

            // Make functions available globally
            window.updateAttendanceSummary = updateAttendanceSummary;
            window.markAllPresent = markAllPresent;
            window.markAllAbsent = markAllAbsent;
        });
    </script>
</body>
</html>