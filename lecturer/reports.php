<?php
session_start();
include('db_connect.php');

// Check if user is logged in and is a lecturer
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'lecturer') {
    header("Location: login.php");
    exit();
}

$lecturer_id = $_SESSION['lecturer_id'];

// Get filter parameters
$subject_id = $_GET['subject_id'] ?? '';
$student_id = $_GET['student_id'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // Today
$view_student_id = $_GET['view_student_id'] ?? ''; // For modal view

// Get current semester dynamically
$semester_sql = "SELECT DISTINCT semester FROM enrollments ORDER BY semester DESC LIMIT 1";
$semester_result = $conn->query($semester_sql);
$current_semester = $semester_result && $semester_result->num_rows > 0 
    ? $semester_result->fetch_assoc()['semester'] 
    : '2024-1';

// Get subjects taught by this lecturer
$subjects_sql = "SELECT DISTINCT s.subject_id, s.subject_name 
                 FROM subjects s 
                 JOIN lecturer_subjects ls ON s.subject_id = ls.subject_id
                 WHERE ls.lecturer_id = ?";
$stmt = $conn->prepare($subjects_sql);
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$subjects_result = $stmt->get_result();

// Get students for selected subject
$students_result = null;
if ($subject_id) {
    $students_sql = "SELECT DISTINCT st.student_id, st.name 
                     FROM students st
                     JOIN enrollments e ON st.student_id = e.student_id
                     WHERE e.subject_id = ? 
                     ORDER BY st.name";
    $stmt = $conn->prepare($students_sql);
    $stmt->bind_param("i", $subject_id);
    $stmt->execute();
    $students_result = $stmt->get_result();
}

// Generate reports based on filters
$attendance_report = [];
$at_risk_report = [];
$intervention_report = [];

// ENHANCED Overall Attendance Report with Cross-Subject View
$attendance_sql = "
    SELECT 
        s.subject_id,
        s.subject_name,
        st.student_id,
        st.name as student_name,
        COUNT(a.attendance_id) as total_classes,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as attended_classes,
        CASE 
            WHEN COUNT(a.attendance_id) > 0 THEN 
                ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / COUNT(a.attendance_id)) * 100, 2)
            ELSE 0 
        END as attendance_percentage,
        (SELECT COUNT(DISTINCT e2.subject_id) 
         FROM enrollments e2 
         WHERE e2.student_id = st.student_id AND e2.semester = ?) as total_subjects_enrolled,
        (SELECT COUNT(DISTINCT i2.intervention_id) 
         FROM interventions i2 
         WHERE i2.student_id = st.student_id) as total_interventions
    FROM students st
    JOIN enrollments e ON st.student_id = e.student_id
    JOIN subjects s ON e.subject_id = s.subject_id
    LEFT JOIN attendance a ON st.student_id = a.student_id AND s.subject_id = a.subject_id
    WHERE s.subject_id IN (
        SELECT ls.subject_id 
        FROM lecturer_subjects ls 
        WHERE ls.lecturer_id = ?
    ) AND e.semester = ?
";

$params = [$current_semester, $lecturer_id, $current_semester];
$types = "sis";

// Build WHERE conditions dynamically
$where_conditions = [];

if ($subject_id) {
    $where_conditions[] = "s.subject_id = ?";
    $params[] = $subject_id;
    $types .= "i";
}

if ($student_id) {
    $where_conditions[] = "st.student_id = ?";
    $params[] = $student_id;
    $types .= "i";
}

if ($date_from && $date_to) {
    $where_conditions[] = "(a.attendance_date BETWEEN ? AND ? OR a.attendance_date IS NULL)";
    $params[] = $date_from;
    $params[] = $date_to;
    $types .= "ss";
}

// Add WHERE conditions if any exist
if (!empty($where_conditions)) {
    $attendance_sql .= " AND " . implode(" AND ", $where_conditions);
}

$attendance_sql .= " GROUP BY s.subject_id, st.student_id ORDER BY s.subject_name, attendance_percentage ASC";

$stmt = $conn->prepare($attendance_sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $attendance_report = $stmt->get_result();
} else {
    $attendance_report = [];
    error_log("Attendance query failed: " . $conn->error);
}

// FIXED & ENHANCED Cross-Subject At-Risk Students Report
$at_risk_sql = "
    WITH student_subject_attendance AS (
        SELECT 
            st.student_id,
            st.name as student_name,
            s.subject_id,
            s.subject_name,
            COUNT(a.attendance_id) as total_classes,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as attended_classes,
            CASE 
                WHEN COUNT(a.attendance_id) > 0 THEN 
                    ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / COUNT(a.attendance_id)) * 100, 2)
                ELSE 0 
            END as attendance_percentage
        FROM students st
        JOIN enrollments e ON st.student_id = e.student_id
        JOIN subjects s ON e.subject_id = s.subject_id
        LEFT JOIN attendance a ON st.student_id = a.student_id AND s.subject_id = a.subject_id
        WHERE s.subject_id IN (
            SELECT ls.subject_id 
            FROM lecturer_subjects ls 
            WHERE ls.lecturer_id = ?
        ) 
        AND e.semester = ?
";

$at_risk_params = [$lecturer_id, $current_semester];
$at_risk_types = "is";

// Add date filter if provided
if ($date_from && $date_to) {
    $at_risk_sql .= " AND (a.attendance_date BETWEEN ? AND ? OR a.attendance_date IS NULL)";
    $at_risk_params[] = $date_from;
    $at_risk_params[] = $date_to;
    $at_risk_types .= "ss";
}

$at_risk_sql .= "
        GROUP BY st.student_id, s.subject_id, st.name, s.subject_name
    ),
    student_summary AS (
        SELECT 
            student_id,
            student_name,
            GROUP_CONCAT(DISTINCT subject_name ORDER BY subject_name SEPARATOR ', ') as subjects,
            COUNT(DISTINCT subject_id) as subject_count,
            MIN(attendance_percentage) as lowest_attendance,
            AVG(attendance_percentage) as avg_attendance
        FROM student_subject_attendance
        GROUP BY student_id, student_name
    )
    SELECT 
        ss.*,
        (SELECT COUNT(*) FROM interventions i WHERE i.student_id = ss.student_id) as total_interventions,
        (SELECT GROUP_CONCAT(DISTINCT CONCAT(l.lecturer_name, ' (', 
            (SELECT COUNT(*) FROM interventions i2 
             WHERE i2.student_id = ss.student_id AND i2.created_by = l.lecturer_id), 
            ')') SEPARATOR ', ')
         FROM interventions i 
         JOIN lecturers l ON i.created_by = l.lecturer_id 
         WHERE i.student_id = ss.student_id) as involved_lecturers,
        (SELECT GROUP_CONCAT(CONCAT(sa.subject_name, ': ', FORMAT(sa.attendance_percentage, 2), '%') 
          ORDER BY sa.attendance_percentage ASC SEPARATOR ' | ') 
         FROM student_subject_attendance sa 
         WHERE sa.student_id = ss.student_id) as subject_details
    FROM student_summary ss
    WHERE ss.lowest_attendance < 80 OR ss.avg_attendance < 80
    ORDER BY ss.avg_attendance ASC, ss.lowest_attendance ASC
";

$stmt = $conn->prepare($at_risk_sql);
if ($stmt) {
    $stmt->bind_param($at_risk_types, ...$at_risk_params);
    $stmt->execute();
    $at_risk_report = $stmt->get_result();
} else {
    $at_risk_report = [];
    error_log("At-risk query failed: " . $conn->error);
}

// ENHANCED Intervention Report with Cross-Subject Context
$intervention_sql = "
    SELECT 
        i.intervention_id,
        i.intervention_date,
        st.student_id,
        st.name as student_name,
        s.subject_name,
        i.comments,
        l.lecturer_name as created_by_name,
        (SELECT COUNT(*) FROM interventions i2 
         WHERE i2.student_id = st.student_id 
         AND i2.intervention_date < i.intervention_date) as previous_interventions,
        (SELECT GROUP_CONCAT(DISTINCT s2.subject_name SEPARATOR ', ') 
         FROM enrollments e2 
         JOIN subjects s2 ON e2.subject_id = s2.subject_id 
         WHERE e2.student_id = st.student_id AND e2.semester = ?) as all_subjects,
        (SELECT ROUND(AVG(
            CASE 
                WHEN COUNT(a.attendance_id) > 0 THEN 
                    ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / COUNT(a.attendance_id)) * 100, 2)
                ELSE 0 
            END
         ), 2)
         FROM enrollments e2 
         LEFT JOIN attendance a ON e2.student_id = a.student_id AND e2.subject_id = a.subject_id
         WHERE e2.student_id = st.student_id AND e2.semester = ?
         GROUP BY e2.student_id) as overall_attendance
    FROM interventions i
    JOIN students st ON i.student_id = st.student_id
    JOIN subjects s ON i.subject_id = s.subject_id
    JOIN lecturers l ON i.created_by = l.lecturer_id
    WHERE i.subject_id IN (
        SELECT ls.subject_id 
        FROM lecturer_subjects ls 
        WHERE ls.lecturer_id = ?
    )
";

$intervention_params = [$current_semester, $current_semester, $lecturer_id];
$intervention_types = "ssi";

// Build intervention WHERE conditions
$intervention_conditions = [];

if ($date_from && $date_to) {
    $intervention_conditions[] = "i.intervention_date BETWEEN ? AND ?";
    $intervention_params[] = $date_from;
    $intervention_params[] = $date_to;
    $intervention_types .= "ss";
}

if ($subject_id) {
    $intervention_conditions[] = "i.subject_id = ?";
    $intervention_params[] = $subject_id;
    $intervention_types .= "i";
}

if ($student_id) {
    $intervention_conditions[] = "i.student_id = ?";
    $intervention_params[] = $student_id;
    $intervention_types .= "i";
}

// Add WHERE conditions if any exist
if (!empty($intervention_conditions)) {
    $intervention_sql .= " AND " . implode(" AND ", $intervention_conditions);
}

$intervention_sql .= " ORDER BY i.intervention_date DESC, i.intervention_id DESC";

$stmt = $conn->prepare($intervention_sql);
if ($stmt) {
    $stmt->bind_param($intervention_types, ...$intervention_params);
    $stmt->execute();
    $intervention_report = $stmt->get_result();
} else {
    $intervention_report = [];
    error_log("Intervention query failed: " . $conn->error);
}

// Calculate statistics for dashboard - SIMPLIFIED VERSION
$stats_sql = "
    SELECT 
        COUNT(DISTINCT st.student_id) as total_students,
        COUNT(DISTINCT s.subject_id) as total_subjects
    FROM students st
    JOIN enrollments e ON st.student_id = e.student_id
    JOIN subjects s ON e.subject_id = s.subject_id
    WHERE s.subject_id IN (
        SELECT ls.subject_id 
        FROM lecturer_subjects ls 
        WHERE ls.lecturer_id = ?
    ) AND e.semester = ?
";
$stats_stmt = $conn->prepare($stats_sql);
if ($stats_stmt) {
    $stats_stmt->bind_param("is", $lecturer_id, $current_semester);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    $stats = $stats_result->fetch_assoc();
} else {
    $stats = ['total_students' => 0, 'total_subjects' => 0];
    error_log("Stats query failed: " . $conn->error);
}

// Get additional stats
$intervention_stats_sql = "
    SELECT 
        (SELECT COUNT(DISTINCT student_id) 
         FROM interventions 
         WHERE created_by = ?) as students_with_interventions,
        (SELECT COUNT(*) 
         FROM interventions 
         WHERE created_by = ? AND intervention_date = CURDATE()) as today_interventions
";
$intervention_stats_stmt = $conn->prepare($intervention_stats_sql);
if ($intervention_stats_stmt) {
    $intervention_stats_stmt->bind_param("ii", $lecturer_id, $lecturer_id);
    $intervention_stats_stmt->execute();
    $intervention_stats_result = $intervention_stats_stmt->get_result();
    $intervention_stats = $intervention_stats_result->fetch_assoc();
    if ($intervention_stats) {
        $stats = array_merge($stats, $intervention_stats);
    }
}

// Count at-risk students - SIMPLIFIED
$at_risk_count = is_object($at_risk_report) ? $at_risk_report->num_rows : 0;
$stats['at_risk_students'] = $at_risk_count;

// FIXED: Get risk distribution for enhanced stats - USING AVG ATTENDANCE INSTEAD OF MIN
$risk_distribution_sql = "
    SELECT 
        CASE 
            WHEN avg_attendance < 60 THEN 'Critical'
            WHEN avg_attendance < 70 THEN 'High Risk'
            WHEN avg_attendance < 80 THEN 'At Risk'
            WHEN avg_attendance >= 80 THEN 'Good'
            ELSE 'Unknown'
        END as risk_category,
        COUNT(*) as student_count
    FROM (
        SELECT 
            st.student_id,
            st.name,
            AVG(
                CASE 
                    WHEN COUNT(a.attendance_id) > 0 THEN 
                        ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / COUNT(a.attendance_id)) * 100, 2)
                    ELSE 100 
                END
            ) as avg_attendance
        FROM students st
        JOIN enrollments e ON st.student_id = e.student_id
        JOIN subjects s ON e.subject_id = s.subject_id
        LEFT JOIN attendance a ON st.student_id = a.student_id AND s.subject_id = a.subject_id
        WHERE s.subject_id IN (
            SELECT ls.subject_id 
            FROM lecturer_subjects ls 
            WHERE ls.lecturer_id = ?
        ) AND e.semester = ?
        GROUP BY st.student_id, st.name
    ) as student_attendance
    GROUP BY risk_category
    ORDER BY 
        CASE risk_category
            WHEN 'Critical' THEN 1
            WHEN 'High Risk' THEN 2
            WHEN 'At Risk' THEN 3
            WHEN 'Good' THEN 4
            ELSE 5
        END
";
$risk_stmt = $conn->prepare($risk_distribution_sql);
if ($risk_stmt) {
    $risk_stmt->bind_param("is", $lecturer_id, $current_semester);
    $risk_stmt->execute();
    $risk_result = $risk_stmt->get_result();
    $risk_distribution = [];
    while ($row = $risk_result->fetch_assoc()) {
        $risk_distribution[$row['risk_category']] = $row['student_count'];
    }
} else {
    $risk_distribution = [];
    error_log("Risk distribution query failed: " . $conn->error);
}

// Alternative approach: Calculate risk distribution from at_risk_report data
$risk_distribution_calculated = [
    'Critical' => 0,
    'High Risk' => 0,
    'At Risk' => 0,
    'Good' => 0
];

if (is_object($at_risk_report) && $at_risk_report->num_rows > 0) {
    // Reset pointer
    $at_risk_report->data_seek(0);
    
    while ($student = $at_risk_report->fetch_assoc()) {
        $avg_attendance = $student['avg_attendance'] ?? 0;
        $lowest_attendance = $student['lowest_attendance'] ?? 0;
        
        if ($avg_attendance < 60 || $lowest_attendance < 50) {
            $risk_distribution_calculated['Critical']++;
        } elseif ($avg_attendance < 70 || $lowest_attendance < 60) {
            $risk_distribution_calculated['High Risk']++;
        } elseif ($avg_attendance < 80 || $lowest_attendance < 70) {
            $risk_distribution_calculated['At Risk']++;
        } else {
            $risk_distribution_calculated['Good']++;
        }
    }
    
    // Reset pointer again for display
    $at_risk_report->data_seek(0);
}

// Get total students count for calculating "Good" students
$total_students_sql = "
    SELECT COUNT(DISTINCT st.student_id) as total
    FROM students st
    JOIN enrollments e ON st.student_id = e.student_id
    JOIN subjects s ON e.subject_id = s.subject_id
    WHERE s.subject_id IN (
        SELECT ls.subject_id 
        FROM lecturer_subjects ls 
        WHERE ls.lecturer_id = ?
    ) AND e.semester = ?
";
$total_stmt = $conn->prepare($total_students_sql);
$total_stmt->bind_param("is", $lecturer_id, $current_semester);
$total_stmt->execute();
$total_result = $total_stmt->get_result();
$total_students = $total_result->fetch_assoc()['total'] ?? 0;

// Calculate Good students (total - sum of at-risk categories)
$at_risk_total = array_sum($risk_distribution_calculated);
$good_students = max(0, $total_students - $at_risk_total);
$risk_distribution_calculated['Good'] = $good_students;

// Use calculated distribution if SQL query returns empty
if (empty($risk_distribution) || array_sum($risk_distribution) == 0) {
    $risk_distribution = $risk_distribution_calculated;
}

// Get cross-subject data for modal view if view_student_id is set
$modal_student_data = null;
$modal_interventions = null;
$modal_attendance = null;

if ($view_student_id) {
    // Get student info
    $student_info_sql = "SELECT student_id, name FROM students WHERE student_id = ?";
    $stmt = $conn->prepare($student_info_sql);
    $stmt->bind_param("i", $view_student_id);
    $stmt->execute();
    $modal_student_data = $stmt->get_result()->fetch_assoc();
    
    // Get ALL interventions for this student (from ALL lecturers)
    $modal_interventions_sql = "
        SELECT 
            i.intervention_id,
            i.student_id,
            i.subject_id,
            s.subject_name,
            i.intervention_date,
            i.comments,
            i.created_by,
            l.lecturer_name
        FROM interventions i
        JOIN subjects s ON i.subject_id = s.subject_id
        LEFT JOIN lecturers l ON i.created_by = l.lecturer_id
        WHERE i.student_id = ?
        ORDER BY i.intervention_date DESC
    ";
    
    $stmt = $conn->prepare($modal_interventions_sql);
    $stmt->bind_param("i", $view_student_id);
    $stmt->execute();
    $modal_interventions = $stmt->get_result();
    
    // Get cross-subject attendance
    $modal_attendance_sql = "
        SELECT 
            s.subject_id,
            s.subject_name,
            COUNT(a.attendance_id) as total_classes,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as attended_classes,
            CASE 
                WHEN COUNT(a.attendance_id) > 0 THEN 
                    ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / COUNT(a.attendance_id)) * 100, 2)
                ELSE 0 
            END as attendance_percentage
        FROM enrollments e
        JOIN subjects s ON e.subject_id = s.subject_id
        LEFT JOIN attendance a ON e.student_id = a.student_id AND e.subject_id = a.subject_id
        WHERE e.student_id = ? AND e.semester = ?
        GROUP BY s.subject_id
        ORDER BY attendance_percentage ASC
    ";
    
    $stmt = $conn->prepare($modal_attendance_sql);
    $stmt->bind_param("is", $view_student_id, $current_semester);
    $stmt->execute();
    $modal_attendance = $stmt->get_result();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - CSSAP</title>
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
            overflow-x: auto;
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

        .content-section {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e9ecef;
        }

        .section-header h2 {
            color: #495057;
            font-size: 1.4rem;
        }

        .btn {
            padding: 10px 20px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: background 0.3s ease;
            font-size: 14px;
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

        .btn-info {
            background: #17a2b8;
        }

        .btn-info:hover {
            background: #138496;
        }

        .btn-warning {
            background: #ffc107;
            color: #212529;
        }

        .btn-warning:hover {
            background: #e0a800;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        /* Filter Styles */
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }

        .form-group {
            margin-bottom: 0;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #495057;
            font-size: 14px;
        }

        select, input[type="date"] {
            width: 100%;
            padding: 8px 12px;
            border: 2px solid #e9ecef;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        select:focus, input[type="date"]:focus {
            outline: none;
            border-color: #28a745;
        }

        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: end;
        }

        /* Table Styles */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 14px;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }

        th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #495057;
            position: sticky;
            top: 0;
        }

        tr:hover {
            background-color: #f8f9fa;
        }

        .attendance-critical {
            color: #dc3545;
            font-weight: bold;
        }

        .attendance-warning {
            color: #ffc107;
            font-weight: bold;
        }

        .attendance-good {
            color: #28a745;
            font-weight: bold;
        }

        .no-data {
            text-align: center;
            color: #6c757d;
            padding: 40px;
            font-style: italic;
        }

        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            text-align: center;
            border-left: 4px solid #28a745;
        }

        .stat-card.warning {
            border-left-color: #ffc107;
        }

        .stat-card.danger {
            border-left-color: #dc3545;
        }

        .stat-card.info {
            border-left-color: #17a2b8;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #28a745;
            margin-bottom: 5px;
        }

        .stat-card.warning .stat-number {
            color: #ffc107;
        }

        .stat-card.danger .stat-number {
            color: #dc3545;
        }

        .stat-card.info .stat-number {
            color: #17a2b8;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .cross-subject-badge {
            background: #e7f3ff;
            color: #0056b3;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            margin-left: 5px;
        }

        .multi-subject-indicator {
            background: #fff3cd;
            color: #856404;
            padding: 2px 6px;
            border-radius: 8px;
            font-size: 0.7rem;
            margin-left: 5px;
        }

        .intervention-comment {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .risk-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }

        .risk-critical { background-color: #dc3545; }
        .risk-warning { background-color: #ffc107; }
        .risk-moderate { background-color: #fd7e14; }
        .risk-good { background-color: #28a745; }

        .export-options {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .tab-navigation {
            display: flex;
            gap: 5px;
            margin-bottom: 20px;
            border-bottom: 1px solid #dee2e6;
        }

        .tab-btn {
            padding: 10px 20px;
            background: #f8f9fa;
            border: none;
            border-radius: 5px 5px 0 0;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .tab-btn.active {
            background: #28a745;
            color: white;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* New styles for enhanced risk analysis */
        .risk-breakdown {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .risk-pill {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .pill-critical { background: #f8d7da; color: #721c24; }
        .pill-high { background: #fff3cd; color: #856404; }
        .pill-moderate { background: #d1ecf1; color: #0c5460; }
        .pill-good { background: #d4edda; color: #155724; }

        .subject-details {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 5px;
            font-style: italic;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
            margin-top: 10px;
        }

        .action-btn {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            text-decoration: none;
            display: inline-block;
        }

        .action-view { background: #e7f3ff; color: #0056b3; }
        .action-intervene { background: #fff3cd; color: #856404; }
        .action-alert { background: #f8d7da; color: #721c24; }

        /* Risk distribution chart */
        .risk-chart {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .chart-item {
            text-align: center;
            padding: 10px;
            border-radius: 6px;
        }

        .chart-critical { background: #f8d7da; border: 2px solid #dc3545; }
        .chart-high { background: #fff3cd; border: 2px solid #ffc107; }
        .chart-moderate { background: #d1ecf1; border: 2px solid #17a2b8; }
        .chart-good { background: #d4edda; border: 2px solid #28a745; }

        /* Enhanced chart numbers */
        .chart-number {
            font-size: 1.8rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .chart-critical .chart-number { color: #dc3545; }
        .chart-high .chart-number { color: #ffc107; }
        .chart-moderate .chart-number { color: #17a2b8; }
        .chart-good .chart-number { color: #28a745; }
        
        .chart-label {
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: 10px;
            width: 80%;
            max-width: 700px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e9ecef;
        }

        .modal-header h3 {
            color: #495057;
            margin: 0;
        }

        .close {
            color: #6c757d;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .close:hover {
            color: #dc3545;
        }

        .cross-subject-table {
            width: 100%;
            margin-top: 20px;
        }

        /* New styles for interventions */
        .intervention-item {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
        }
        
        .intervention-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .intervention-subject {
            background: #17a2b8;
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .intervention-lecturer {
            color: #6c757d;
            font-size: 0.9rem;
            font-style: italic;
        }
        
        .intervention-date {
            color: #6c757d;
            font-size: 0.8rem;
        }
        
        .intervention-comment {
            margin-top: 8px;
            line-height: 1.4;
        }
        
        .other-lecturer-intervention {
            border-left: 4px solid #17a2b8;
        }
        
        .current-lecturer-intervention {
            border-left: 4px solid #28a745;
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
                <li><a href="attendance.php">Mark Attendance</a></li>
                <li><a href="students.php">Student Management</a></li>
                <li><a href="interventions.php">Interventions</a></li>
                <li><a href="reports.php" class="active">Reports</a></li>
                <li><a href="profile.php">My Profile</a></li>
            </ul>
            <form method="POST" action="logout.php">
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <h1>Attendance & Intervention Reports</h1>
                <div>
                    <span>Welcome, <strong><?php echo htmlspecialchars($_SESSION['lecturer_name']); ?></strong></span>
                    <div style="font-size: 0.8rem; color: #6c757d; margin-top: 5px;">
                        Semester: <?php echo htmlspecialchars($current_semester); ?>
                        | Total Students: <?php echo $total_students; ?>
                    </div>
                </div>
            </div>

            <!-- Enhanced Quick Stats -->
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_students'] ?? 0; ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-card info">
                    <div class="stat-number"><?php echo $stats['total_subjects'] ?? 0; ?></div>
                    <div class="stat-label">Subjects Teaching</div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-number"><?php echo $stats['at_risk_students'] ?? 0; ?></div>
                    <div class="stat-label">At-Risk Students</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-number"><?php echo $stats['students_with_interventions'] ?? 0; ?></div>
                    <div class="stat-label">Students Assisted</div>
                </div>
            </div>

            <!-- Risk Distribution Chart - FIXED -->
            <div class="content-section">
                <div class="section-header">
                    <h2>Risk Distribution Overview</h2>
                    <small style="color: #6c757d;">Based on average attendance across subjects</small>
                </div>
                <div class="risk-chart">
                    <div class="chart-item chart-critical">
                        <div class="chart-number"><?php echo $risk_distribution['Critical'] ?? 0; ?></div>
                        <div class="chart-label">Critical</div>
                        <small style="font-size: 0.7rem; color: #721c24;">&lt; 60%</small>
                    </div>
                    <div class="chart-item chart-high">
                        <div class="chart-number"><?php echo $risk_distribution['High Risk'] ?? 0; ?></div>
                        <div class="chart-label">High Risk</div>
                        <small style="font-size: 0.7rem; color: #856404;">60-69%</small>
                    </div>
                    <div class="chart-item chart-moderate">
                        <div class="chart-number"><?php echo $risk_distribution['At Risk'] ?? 0; ?></div>
                        <div class="chart-label">At Risk</div>
                        <small style="font-size: 0.7rem; color: #0c5460;">70-79%</small>
                    </div>
                    <div class="chart-item chart-good">
                        <div class="chart-number"><?php echo $risk_distribution['Good'] ?? 0; ?></div>
                        <div class="chart-label">Good</div>
                        <small style="font-size: 0.7rem; color: #155724;">≥ 80%</small>
                    </div>
                </div>
                <div style="text-align: center; margin-top: 10px; font-size: 0.9rem; color: #6c757d;">
                    Total: <?php echo array_sum($risk_distribution); ?> students | 
                    At-Risk: <?php echo ($risk_distribution['Critical'] ?? 0) + ($risk_distribution['High Risk'] ?? 0) + ($risk_distribution['At Risk'] ?? 0); ?>
                </div>
            </div>

            <!-- Filter Form -->
            <div class="content-section">
                <div class="section-header">
                    <h2>Report Filters</h2>
                    <div class="export-options">
                        <button type="button" class="btn btn-info" onclick="printReport()">Print Report</button>
                        <button type="button" class="btn btn-secondary" onclick="exportToPDF()">Export PDF</button>
                    </div>
                </div>
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label for="subject_id">Subject:</label>
                        <select name="subject_id" id="subject_id">
                            <option value="">All Subjects</option>
                            <?php 
                            if ($subjects_result) {
                                while ($subject = $subjects_result->fetch_assoc()): ?>
                                    <option value="<?php echo $subject['subject_id']; ?>" 
                                        <?php echo $subject_id == $subject['subject_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                <?php endwhile; 
                            } ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="student_id">Student:</label>
                        <select name="student_id" id="student_id">
                            <option value="">All Students</option>
                            <?php if ($students_result): ?>
                                <?php while ($student = $students_result->fetch_assoc()): ?>
                                    <option value="<?php echo $student['student_id']; ?>" 
                                        <?php echo $student_id == $student['student_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($student['name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="date_from">From Date:</label>
                        <input type="date" name="date_from" id="date_from" value="<?php echo $date_from; ?>">
                    </div>

                    <div class="form-group">
                        <label for="date_to">To Date:</label>
                        <input type="date" name="date_to" id="date_to" value="<?php echo $date_to; ?>">
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn">Generate Report</button>
                        <a href="reports.php" class="btn btn-secondary">Reset</a>
                    </div>
                </form>
            </div>

            <!-- Tab Navigation -->
            <div class="tab-navigation">
                <button class="tab-btn active" onclick="showTab('at-risk')">At-Risk Analysis</button>
                <button class="tab-btn" onclick="showTab('attendance')">Attendance Details</button>
                <button class="tab-btn" onclick="showTab('interventions')">Intervention History</button>
            </div>

            <!-- Enhanced Cross-Subject At-Risk Report -->
            <div id="at-risk" class="tab-content active">
                <div class="content-section">
                    <div class="section-header">
                        <h2>Cross-Subject At-Risk Analysis <span class="cross-subject-badge">CSSAP Intelligence</span></h2>
                        <div>
                            <span class="btn btn-danger">At-Risk: <?php echo is_object($at_risk_report) ? $at_risk_report->num_rows : 0; ?></span>
                            <button class="btn btn-warning" onclick="exportAtRiskCSV()">Export CSV</button>
                        </div>
                    </div>

                    <?php if (is_object($at_risk_report) && $at_risk_report->num_rows > 0): 
                        // Reset pointer for display
                        $at_risk_report->data_seek(0);
                    ?>
                        <div class="risk-breakdown">
                            <div class="risk-pill pill-critical">Critical: <?php echo $risk_distribution['Critical'] ?? 0; ?></div>
                            <div class="risk-pill pill-high">High Risk: <?php echo $risk_distribution['High Risk'] ?? 0; ?></div>
                            <div class="risk-pill pill-moderate">At Risk: <?php echo $risk_distribution['At Risk'] ?? 0; ?></div>
                            <div class="risk-pill pill-good">Good: <?php echo $risk_distribution['Good'] ?? 0; ?></div>
                        </div>

                        <table>
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Subjects</th>
                                    <th>Subject Count</th>
                                    <th>Lowest Attendance</th>
                                    <th>Average Attendance</th>
                                    <th>Subject Details</th>
                                    <th>Interventions</th>
                                    <th>Involved Lecturers</th>
                                    <th>Risk Level</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($student = $at_risk_report->fetch_assoc()): 
                                    $avg_attendance = $student['avg_attendance'] ?? 0;
                                    $lowest_attendance = $student['lowest_attendance'] ?? 0;
                                    $total_interventions = $student['total_interventions'] ?? 0;
                                    
                                    // Ensure 2 decimal places
                                    $avg_attendance_formatted = number_format((float)$avg_attendance, 2, '.', '');
                                    $lowest_attendance_formatted = number_format((float)$lowest_attendance, 2, '.', '');
                                    
                                    // Determine risk level (matching the risk distribution logic)
                                    if ($avg_attendance < 60) {
                                        $risk_level = 'Critical';
                                        $risk_class = 'risk-critical';
                                        $risk_color = '#dc3545';
                                    } elseif ($avg_attendance < 70) {
                                        $risk_level = 'High Risk';
                                        $risk_class = 'risk-warning';
                                        $risk_color = '#ffc107';
                                    } elseif ($avg_attendance < 80) {
                                        $risk_level = 'At Risk';
                                        $risk_class = 'risk-moderate';
                                        $risk_color = '#fd7e14';
                                    } else {
                                        $risk_level = 'Good';
                                        $risk_class = 'risk-good';
                                        $risk_color = '#28a745';
                                    }
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($student['student_name']); ?></strong>
                                            <?php if ($student['subject_count'] > 1): ?>
                                                <span class="multi-subject-indicator" title="Enrolled in <?php echo $student['subject_count']; ?> subjects">
                                                    <?php echo $student['subject_count']; ?> subs
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($student['subjects']); ?></td>
                                        <td><?php echo $student['subject_count']; ?></td>
                                        <td class="<?php echo $lowest_attendance < 60 ? 'attendance-critical' : 'attendance-warning'; ?>">
                                            <?php echo $lowest_attendance_formatted; ?>%
                                        </td>
                                        <td class="<?php echo $avg_attendance < 80 ? 'attendance-warning' : 'attendance-good'; ?>">
                                            <?php echo $avg_attendance_formatted; ?>%
                                        </td>
                                        <td>
                                            <div class="subject-details" title="<?php echo htmlspecialchars($student['subject_details']); ?>">
                                                <?php echo htmlspecialchars(substr($student['subject_details'], 0, 50)); ?>
                                                <?php if (strlen($student['subject_details']) > 50): ?>...<?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($total_interventions > 0): ?>
                                                <span class="attendance-warning" style="font-weight: bold;"><?php echo $total_interventions; ?></span>
                                            <?php else: ?>
                                                <span class="attendance-good">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($student['involved_lecturers'])): ?>
                                                <small><?php echo htmlspecialchars($student['involved_lecturers']); ?></small>
                                            <?php else: ?>
                                                <span style="color: #6c757d; font-style: italic;">None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="risk-indicator <?php echo $risk_class; ?>"></span>
                                            <strong style="color: <?php echo $risk_color; ?>;"><?php echo $risk_level; ?></strong>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button onclick="viewStudentDetails(<?php echo $student['student_id']; ?>)" 
                                                        class="action-btn action-view">View</button>
                                                <a href="interventions.php?student_id=<?php echo $student['student_id']; ?>" 
                                                   class="action-btn action-intervene">Intervene</a>
                                                <?php if ($risk_level == 'Critical' || $risk_level == 'High Risk'): ?>
                                                    <button onclick="alertLecturer('<?php echo htmlspecialchars($student['student_name']); ?>')" 
                                                            class="action-btn action-alert">Alert</button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-data">
                            <?php if (!empty($lecturer_subjects)): ?>
                                No at-risk students found for the selected criteria.
                                <div style="margin-top: 10px; font-size: 0.9rem; color: #6c757d;">
                                    Teaching <?php echo count($lecturer_subjects); ?> subject(s) with <?php echo $enrollment_count ?? 0; ?> enrollment(s)
                                </div>
                            <?php else: ?>
                                No subjects assigned to you. Please contact administrator.
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Detailed Attendance Report -->
            <div id="attendance" class="tab-content">
                <div class="content-section">
                    <div class="section-header">
                        <h2>Detailed Attendance Report</h2>
                        <span class="btn btn-info">Total: <?php echo is_object($attendance_report) ? $attendance_report->num_rows : 0; ?></span>
                    </div>

                    <?php if (is_object($attendance_report) && $attendance_report->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Subject</th>
                                    <th>Classes Attended</th>
                                    <th>Total Classes</th>
                                    <th>Attendance %</th>
                                    <th>Total Subjects</th>
                                    <th>Interventions</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($record = $attendance_report->fetch_assoc()): 
                                    $attendance_percentage = $record['attendance_percentage'] ?? 0;
                                    $total_subjects = $record['total_subjects_enrolled'] ?? 0;
                                    $attendance_percentage_formatted = number_format((float)$attendance_percentage, 2, '.', '');
                                    
                                    if ($attendance_percentage < 60) {
                                        $attendance_class = 'attendance-critical';
                                        $status = 'Critical';
                                    } elseif ($attendance_percentage < 80) {
                                        $attendance_class = 'attendance-warning';
                                        $status = 'At Risk';
                                    } else {
                                        $attendance_class = 'attendance-good';
                                        $status = 'Good';
                                    }
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($record['student_name']); ?></strong>
                                            <?php if ($total_subjects > 1): ?>
                                                <span class="multi-subject-indicator" title="Enrolled in <?php echo $total_subjects; ?> subjects"><?php echo $total_subjects; ?> subs</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($record['subject_name']); ?></td>
                                        <td><?php echo $record['attended_classes']; ?></td>
                                        <td><?php echo $record['total_classes']; ?></td>
                                        <td class="<?php echo $attendance_class; ?>">
                                            <?php echo $attendance_percentage_formatted; ?>%
                                        </td>
                                        <td><?php echo $total_subjects; ?></td>
                                        <td>
                                            <?php if ($record['total_interventions'] > 0): ?>
                                                <span class="attendance-warning"><?php echo $record['total_interventions']; ?></span>
                                            <?php else: ?>
                                                <span class="attendance-good">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($attendance_percentage < 80): ?>
                                                <span style="color: #dc3545; font-weight: bold;"><?php echo $status; ?></span>
                                            <?php else: ?>
                                                <span style="color: #28a745; font-weight: bold;"><?php echo $status; ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-data">
                            No attendance records found for the selected criteria.
                            <?php if (isset($lecturer_subjects) && !empty($lecturer_subjects)): ?>
                                <div style="margin-top: 10px; font-size: 0.9rem; color: #6c757d;">
                                    Try selecting a specific subject from the filter above.
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Intervention Report -->
            <div id="interventions" class="tab-content">
                <div class="content-section">
                    <div class="section-header">
                        <h2>Intervention History</h2>
                        <span class="btn btn-secondary">Total: <?php echo is_object($intervention_report) ? $intervention_report->num_rows : 0; ?></span>
                    </div>

                    <?php if (is_object($intervention_report) && $intervention_report->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Student</th>
                                    <th>Subject</th>
                                    <th>Overall Attendance</th>
                                    <th>Comments</th>
                                    <th>Recorded By</th>
                                    <th>Previous Interventions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($intervention = $intervention_report->fetch_assoc()): 
                                    $overall_attendance = $intervention['overall_attendance'] ?? 0;
                                    $attendance_class = $overall_attendance < 80 ? 'attendance-warning' : 'attendance-good';
                                    $overall_attendance_formatted = number_format((float)$overall_attendance, 2, '.', '');
                                ?>
                                    <tr>
                                        <td><?php echo date('M j, Y', strtotime($intervention['intervention_date'])); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($intervention['student_name']); ?></strong>
                                            <?php if ($intervention['previous_interventions'] > 0): ?>
                                                <span class="multi-subject-indicator" title="Multiple interventions"><?php echo $intervention['previous_interventions']; ?> prev</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($intervention['subject_name']); ?></td>
                                        <td class="<?php echo $attendance_class; ?>">
                                            <?php echo $overall_attendance_formatted; ?>%
                                        </td>
                                        <td class="intervention-comment" title="<?php echo htmlspecialchars($intervention['comments']); ?>">
                                            <?php echo htmlspecialchars(substr($intervention['comments'], 0, 50)); ?>
                                            <?php if (strlen($intervention['comments']) > 50): ?>...<?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($intervention['created_by_name'] == $_SESSION['lecturer_name']): ?>
                                                <span style="color: #28a745; font-weight: bold;">You</span>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($intervention['created_by_name']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($intervention['previous_interventions'] > 0): ?>
                                                <span class="attendance-warning"><?php echo $intervention['previous_interventions']; ?></span>
                                            <?php else: ?>
                                                <span class="attendance-good">0</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-data">No interventions found for the selected criteria.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Student Details Modal -->
    <div id="studentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Student Interventions - <span id="modalStudentName"></span></h3>
                <span class="close">&times;</span>
            </div>
            
            <div id="modalContent">
                <?php if ($view_student_id && $modal_student_data): ?>
                    <!-- Cross-Subject Attendance Summary -->
                    <div class="content-section">
                        <h4>Attendance Across All Subjects</h4>
                        <?php if ($modal_attendance->num_rows > 0): ?>
                            <table class="cross-subject-table">
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>Attended</th>
                                        <th>Total</th>
                                        <th>Percentage</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($subject = $modal_attendance->fetch_assoc()): 
                                        $attendance_class = '';
                                        if ($subject['attendance_percentage'] < 60) {
                                            $attendance_class = 'attendance-critical';
                                            $status = 'Critical';
                                        } elseif ($subject['attendance_percentage'] < 80) {
                                            $attendance_class = 'attendance-warning';
                                            $status = 'At Risk';
                                        } else {
                                            $attendance_class = 'attendance-good';
                                            $status = 'Good';
                                        }
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                            <td><?php echo $subject['attended_classes']; ?></td>
                                            <td><?php echo $subject['total_classes']; ?></td>
                                            <td class="<?php echo $attendance_class; ?>">
                                                <?php echo $subject['attendance_percentage']; ?>%
                                            </td>
                                            <td><?php echo $status; ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="no-data">No attendance records found.</div>
                        <?php endif; ?>
                    </div>

                    <!-- All Interventions -->
                    <div class="content-section">
                        <h4>All Interventions</h4>
                        <?php if ($modal_interventions->num_rows > 0): ?>
                            <div class="interventions-list">
                                <?php while ($intervention = $modal_interventions->fetch_assoc()): 
                                    $is_current_lecturer = ($intervention['created_by'] == $lecturer_id);
                                ?>
                                    <div class="intervention-item <?php echo $is_current_lecturer ? 'current-lecturer-intervention' : 'other-lecturer-intervention'; ?>">
                                        <div class="intervention-header">
                                            <span class="intervention-subject"><?php echo htmlspecialchars($intervention['subject_name']); ?></span>
                                            <span class="intervention-date"><?php echo date('M j, Y', strtotime($intervention['intervention_date'])); ?></span>
                                        </div>
                                        <div class="intervention-lecturer">
                                            By: <?php echo htmlspecialchars($intervention['lecturer_name'] ?? 'Unknown Lecturer'); ?>
                                            <?php if ($is_current_lecturer): ?>
                                                <strong>(You)</strong>
                                            <?php endif; ?>
                                        </div>
                                        <div class="intervention-comment">
                                            <?php echo htmlspecialchars($intervention['comments']); ?>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="no-data">No interventions found for this student.</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName).classList.add('active');
            
            // Activate selected tab button
            event.currentTarget.classList.add('active');
        }

        function printReport() {
            window.print();
        }

        function exportToPDF() {
            alert('PDF export feature would be implemented here. This would generate a comprehensive cross-subject report.');
            // In a real implementation, this would call a server-side script to generate PDF
        }

        function exportAtRiskCSV() {
            // Get current filters
            const params = new URLSearchParams(window.location.search);
            
            // Add export flag
            params.set('export', 'at_risk_csv');
            
            // Redirect to export script
            window.location.href = 'export_reports.php?' + params.toString();
        }

        function alertLecturer(studentName) {
            if (confirm(`Send urgent alert about ${studentName} to other lecturers?`)) {
                // In a real implementation, this would make an AJAX call
                alert(`Alert sent for ${studentName}. Other lecturers will be notified.`);
            }
        }

        // Modal functionality
        const modal = document.getElementById('studentModal');
        const closeBtn = document.querySelector('.close');
        
        function viewStudentDetails(studentId) {
            // Redirect to same page with view_student_id parameter
            const params = new URLSearchParams(window.location.search);
            params.set('view_student_id', studentId);
            window.location.href = `reports.php?${params.toString()}`;
        }

        // Auto-open modal if view_student_id is in URL
        <?php if ($view_student_id && $modal_student_data): ?>
            document.addEventListener('DOMContentLoaded', function() {
                document.getElementById('modalStudentName').textContent = '<?php echo htmlspecialchars($modal_student_data['name']); ?>';
                modal.style.display = 'block';
            });
        <?php endif; ?>

        // Close modal when clicking X
        closeBtn.onclick = function() {
            modal.style.display = 'none';
            // Remove view_student_id from URL
            const params = new URLSearchParams(window.location.search);
            params.delete('view_student_id');
            window.history.replaceState({}, document.title, `${window.location.pathname}?${params.toString()}`);
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target == modal) {
                modal.style.display = 'none';
                // Remove view_student_id from URL
                const params = new URLSearchParams(window.location.search);
                params.delete('view_student_id');
                window.history.replaceState({}, document.title, `${window.location.pathname}?${params.toString()}`);
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const subjectSelect = document.getElementById('subject_id');
            const studentSelect = document.getElementById('student_id');

            // Update students when subject changes
            subjectSelect.addEventListener('change', function() {
                const form = subjectSelect.closest('form');
                form.submit();
            });
        });
    </script>
</body>
</html>