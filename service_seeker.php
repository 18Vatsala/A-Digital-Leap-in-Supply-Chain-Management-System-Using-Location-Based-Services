<?php
// Database connection (with port 3307 since your MySQL runs there)
$conn = new mysqli("localhost", "root", "", "service_db", 3307);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Collect form data
$name     = $_POST['name'];
$contact  = $_POST['contact'];

// Remove +91 and any symbols
$contact = str_replace("+91", "", $contact);
$contact = preg_replace('/\D/', '', $contact);

// Validate final contact number (must be exactly 10 digits)
if (strlen($contact) !== 10) {
    echo "<script>alert('Invalid contact number. Please enter a valid 10-digit mobile number.'); window.history.back();</script>";
    exit;
}

$address  = $_POST['address'];
$gender   = $_POST['gender'];
$username = $_POST['username'];
$password = $_POST['newpassword'];
$confirm  = $_POST['confirmpassword'];

// Confirm password check
if ($password !== $confirm) {
    echo "<script>alert('Passwords do not match. Please try again.'); window.history.back();</script>";
    exit;
}

// Fields to check for uniqueness
$uniqueFields = [
    'username' => $username
];

foreach ($uniqueFields as $field => $value) {
    $check = $conn->prepare("SELECT id FROM service_seeker WHERE $field=?");
    $check->bind_param("s", $value);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        echo "<script>alert('⚠ The $field \"$value\" is already in use. Please choose another.'); window.history.back();</script>";
        $check->close();
        $conn->close();
        exit;
    }
    $check->close();
}

// Hash the password before storing
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

// Insert new record
$stmt = $conn->prepare("INSERT INTO service_seeker 
    (name, contact, address, gender, username, password, created_at) 
    VALUES (?, ?, ?, ?, ?, ?, NOW())");

$stmt->bind_param(
    "ssssss",
    $name,
    $contact,
    $address,
    $gender,
    $username,
    $hashedPassword
);

if ($stmt->execute()) {
    echo "<script>alert('✅ Registration successful!'); window.location='login.html';</script>";
} else {
    echo "<script>alert('❌ Error while registering.'); window.history.back();</script>";
}

$stmt->close();
$conn->close();
?>
