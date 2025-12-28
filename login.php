<?php
// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

session_start();
session_regenerate_id(true); // Fix: Better security on login

/* === Database Configuration === */
$host = "127.0.0.1";
$db_user = "root";
$db_pass = "";
$db_name = "service_db";
$db_port = 3307;

$conn = new mysqli($host, $db_user, $db_pass, $db_name, $db_port);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/* === Collect form input === */
$username = isset($_POST['username']) ? trim($_POST['username']) : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';

if ($username === '' || $password === '') {
    echo "<script>alert('Please enter username and password'); window.location.href='login.html';</script>";
    exit();
}

/* === Helper function to verify credentials and fetch full user data === */
function check_table($conn, $table, $username, $password) {
    // Step 1: Verify password
    $pass_stmt = $conn->prepare("SELECT id, password FROM {$table} WHERE username = ?");
    if (!$pass_stmt) return ['status' => 'error', 'msg' => 'prepare_failed'];
    $pass_stmt->bind_param("s", $username);
    $pass_stmt->execute();
    $pass_res = $pass_stmt->get_result();

    if ($pass_res && $pass_res->num_rows > 0) {
        $pass_row = $pass_res->fetch_assoc();
        $stored_pass = $pass_row['password'];

        // Compare passwords (hashed or plain)
        if (password_verify($password, $stored_pass) || $password === $stored_pass) {
            $pass_stmt->close();

            // Step 2: Fetch full user data INCLUDING id
            if ($table == 'service_provider') {
                $user_stmt = $conn->prepare("
                    SELECT id, username, name, age, gender, contact, altcontact,
                           address, govid, experience, shifts, skill
                    FROM service_provider
                    WHERE username = ?
                ");
            } else {
                $user_stmt = $conn->prepare("
                    SELECT id, username, name, gender, contact, address
                    FROM service_seeker
                    WHERE username = ?
                ");
            }

            $user_stmt->bind_param("s", $username);
            $user_stmt->execute();
            $user_res = $user_stmt->get_result();
            $user_row = $user_res->fetch_assoc();
            $user_stmt->close();

            $role = ($table === 'service_seeker') ? 'seeker' : 'provider';
            return ['status' => 'ok', 'user' => $user_row, 'role' => $role];
        }

        $pass_stmt->close();
        return ['status' => 'badpass'];
    }

    $pass_stmt->close();
    return ['status' => 'nouser'];
}

/* === Try logging in as seeker first === */
$res = check_table($conn, 'service_seeker', $username, $password);
if ($res['status'] === 'ok') {
    $_SESSION['username'] = $username;
    $_SESSION['role'] = $res['role'];
    $_SESSION['user'] = $res['user']; // Now includes 'id'
    header("Location: home_page_service_seeker.php");
    exit();
} elseif ($res['status'] === 'badpass') {
    echo "<script>alert('Invalid password for service seeker'); window.location.href='login.html';</script>";
    exit();
}

/* === If not a seeker, check provider === */
$res = check_table($conn, 'service_provider', $username, $password);
if ($res['status'] === 'ok') {
    $_SESSION['username'] = $username;
    $_SESSION['role'] = $res['role'];
    $_SESSION['user'] = $res['user']; // Now includes 'id'
    header("Location: home_page_service_provider.php");
    exit();
} elseif ($res['status'] === 'badpass') {
    echo "<script>alert('Invalid password for service provider'); window.location.href='login.html';</script>";
    exit();
}

/* === No matching username === */
echo "<script>alert('Username not found'); window.location.href='login.html';</script>";
$conn->close();
exit();
?>