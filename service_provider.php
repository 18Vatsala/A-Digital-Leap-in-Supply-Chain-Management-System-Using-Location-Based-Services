<?php
$conn = new mysqli("localhost", "root", "", "service_db", 3307);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// Collect form fields
$name       = $_POST['name'];
$age        = $_POST['age'];
$gender     = $_POST['gender'];

$contact    = $_POST['contact'];
$altcontact = !empty($_POST['altcontact']) ? $_POST['altcontact'] : NULL;

$govid      = $_POST['govid'];
$address    = $_POST['address'];
$experience = $_POST['experience'];
$skill      = $_POST['skill'];
$shifts     = $_POST['shifts'];
$username   = $_POST['username'];
$password   = $_POST['newpassword'];
$confirm    = $_POST['confirmpassword'];


// ---------------- CONTACT VALIDATION ------------------
$contact = str_replace("+91", "", $contact);
$contact = preg_replace('/\D/', '', $contact);

if (strlen($contact) !== 10) {
    echo "<script>alert('Invalid Contact Number. Must be 10 digits.'); window.history.back();</script>";
    exit;
}

if (!empty($altcontact)) {
    $altcontact = str_replace("+91", "", $altcontact);
    $altcontact = preg_replace('/\D/', '', $altcontact);

    if (strlen($altcontact) !== 10) {
        echo "<script>alert('Invalid Alternate Contact Number. Must be 10 digits.'); window.history.back();</script>";
        exit;
    }
}


// ---------------- GOVERNMENT ID VALIDATION ------------------
$govid = strtoupper(str_replace(" ", "", $govid));

$aadhaar = '/^[0-9]{12}$/';
$pan     = '/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/';

if (!preg_match($aadhaar, $govid) && !preg_match($pan, $govid)) {
    echo "<script>alert('Invalid Government ID. Enter Aadhaar (12 digits) or PAN (ABCDE1234F).'); window.history.back();</script>";
    exit;
}


// ---------------- PASSWORD CHECK ------------------
if ($password !== $confirm) {
    echo "<script>alert('Passwords do not match!'); window.history.back();</script>";
    exit;
}


// ---------------- UNIQUE CHECK (username, govid) ------------------
$unique = ["username" => $username, "govid" => $govid];

foreach ($unique as $field => $value) {
    $check = $conn->prepare("SELECT id FROM service_provider WHERE $field=?");
    $check->bind_param("s", $value);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        echo "<script>alert('The $field \"$value\" is already in use.'); window.history.back();</script>";
        exit;
    }
    $check->close();
}


// ---------------- INSERT INTO DATABASE ------------------
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

$stmt = $conn->prepare("INSERT INTO service_provider 
(name, age, gender, contact, govid, altcontact, address, experience, skill, shifts, username, password)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

$stmt->bind_param(
"sisisisissss",
$name, $age, $gender, $contact, $govid, $altcontact,
$address, $experience, $skill, $shifts, $username, $hashedPassword
);


if ($stmt->execute()) {
    echo "<script>alert('Registration Successful!'); window.location='login.html';</script>";
} else {
    echo "<script>alert('Error: " . $stmt->error . "'); window.history.back();</script>";
}

$stmt->close();
$conn->close();
?>
