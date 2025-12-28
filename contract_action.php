<?php
session_start();
include 'db.php';

$action      = $_POST['action'] ?? '';
$job_id      = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
$provider_id = isset($_POST['provider_id']) ? (int)$_POST['provider_id'] : 0;
$description = trim($_POST['description'] ?? '');

/*
|--------------------------------------------------------------------------
| BASIC VALIDATION
|--------------------------------------------------------------------------
*/
if ($job_id <= 0) {
    echo "INVALID_JOB";
    exit;
}

/*
|--------------------------------------------------------------------------
| 1️⃣ PROVIDER ACCEPTS JOB
|--------------------------------------------------------------------------
| Rule:
| - Job must be OPEN
| - assigned_provider_id must be NULL
*/
if ($action === 'provider_accept') {

    $stmt = $conn->prepare("
        UPDATE job_requests
        SET status = 'assigned',
            assigned_provider_id = ?
        WHERE id = ?
          AND status = 'open'
          AND assigned_provider_id IS NULL
    ");
    $stmt->bind_param("ii", $provider_id, $job_id);
    $stmt->execute();

    // If no rows updated → job already taken
    if ($stmt->affected_rows === 0) {
        echo "JOB_ALREADY_ASSIGNED";
        exit;
    }

    $stmt->close();

    echo "PROVIDER_ACCEPT_OK";
    exit;
}

/*
|--------------------------------------------------------------------------
| 2️⃣ PROVIDER REJECTS JOB
|--------------------------------------------------------------------------
| Rule:
| - Just mark provider notification as rejected
| - Job stays OPEN
*/
if ($action === 'provider_reject') {

    $stmt = $conn->prepare("
        UPDATE provider_notifications
        SET status = 'rejected'
        WHERE job_id = ? AND provider_id = ?
    ");
    $stmt->bind_param("ii", $job_id, $provider_id);
    $stmt->execute();
    $stmt->close();

    echo "PROVIDER_REJECT_OK";
    exit;
}

/*
|--------------------------------------------------------------------------
| 3️⃣ SEEKER CONFIRMS CONTRACT
|--------------------------------------------------------------------------
| Rule:
| - Job must already be ASSIGNED
| - Once confirmed → LOCK forever
*/
if ($action === 'seeker_confirm') {

    $stmt = $conn->prepare("
        UPDATE job_requests
        SET status = 'confirmed'
        WHERE id = ?
          AND status = 'assigned'
    ");
    $stmt->bind_param("i", $job_id);
    $stmt->execute();

    if ($stmt->affected_rows === 0) {
        echo "CONFIRM_FAILED";
        exit;
    }

    $stmt->close();

    echo "SEEKER_CONFIRM_OK";
    exit;
}

/*
|--------------------------------------------------------------------------
| 4️⃣ SEEKER REJECTS CONTRACT
|--------------------------------------------------------------------------
| Rule:
| - Reset job back to OPEN
| - assigned_provider_id cleared
*/
if ($action === 'seeker_reject') {

    $stmt = $conn->prepare("
        UPDATE job_requests
        SET status = 'open',
            assigned_provider_id = NULL
        WHERE id = ?
          AND status = 'assigned'
    ");
    $stmt->bind_param("i", $job_id);
    $stmt->execute();
    $stmt->close();

    echo "SEEKER_REJECT_OK";
    exit;
}

/*
|--------------------------------------------------------------------------
| FALLBACK
|--------------------------------------------------------------------------
*/
echo "INVALID_ACTION";
exit;
?>
