<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'seeker') {
    header("Location: login.html");
    exit();
}
$user = $_SESSION['user'];

// Set username for queries if not already in session (adjust based on your login script)
if (!isset($_SESSION['username'])) {
    $_SESSION['username'] = $user['username'] ?? $user['id']; // Fallback to ID if username not set
}

// Database connection (single for whole page)
$conn = new mysqli("127.0.0.1", "root", "", "service_db", 3307);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Simple CSRF token (basic fix)
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle profile update (POST)
if ($_POST && isset($_POST['update_profile']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $name = trim($_POST['name']);
    $contact = (int)$_POST['contact'];
    $address = trim($_POST['address']);
    $gender = strtolower(trim($_POST['gender']));

    if (empty($name) || $contact < 1000000000 || empty($address) || empty($gender)) {
        echo "<script>alert('Invalid input. Please check your details.');</script>";
    } else {
        $update_stmt = $conn->prepare("UPDATE service_seeker SET name=?, contact=?, address=?, gender=? WHERE username=?");
        $update_stmt->bind_param("sisss", $name, $contact, $address, $gender, $_SESSION['username']);
        if ($update_stmt->execute()) {
            $_SESSION['user'] = [
                'id' => $user['id'],
                'name' => $name, 
                'contact' => $contact, 
                'address' => $address, 
                'gender' => $gender
            ];
            echo "<script>alert('Profile updated successfully!'); loadNotifications();</script>";
        } else {
            echo "<script>alert('Error updating profile. Try again.');</script>";
        }
        $update_stmt->close();
    }
}

// Handle job post
if ($_POST && isset($_POST['post_job']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $skill = trim($_POST['skill']);
    $district = trim($_POST['district']);
    if (empty($skill) || empty($district)) {
    echo "<script>alert('Please select a valid skill and district.');</script>";
    }
   else {
        $seeker_id = $user['id']; // Now reliably available from session
        // Insert job request
        $job_stmt = $conn->prepare("INSERT INTO job_requests (seeker_id, skill, district, status)
VALUES (?, ?, ?, 'open')");
$job_stmt->bind_param("iss", $seeker_id, $skill, $district);
        if ($job_stmt->execute()) {
            $job_id = $conn->insert_id;

            // Find matching providers (skill-based; distance later)
            $providers_stmt = $conn->prepare("SELECT id, name FROM service_provider 
WHERE skill = ? AND address LIKE CONCAT(?, '%')");
$providers_stmt->bind_param("ss", $skill, $district);
            $providers_stmt->execute();
            $providers_result = $providers_stmt->get_result();
            $providers = $providers_result->fetch_all(MYSQLI_ASSOC);
            $providers_stmt->close();

            $provider_count = count($providers);
            if ($provider_count > 0) {
                // Create notifications
                $notif_stmt = $conn->prepare("INSERT INTO provider_notifications (provider_id, job_id, message) VALUES (?, ?, ?)");
                $message = "New job for {$skill}! District: {$district}. Seeker: " . htmlspecialchars($user['name']) . ". Job ID: {$job_id}.";

                foreach ($providers as $provider) {
                    $notif_stmt->bind_param("iis", $provider['id'], $job_id, $message);
                    $notif_stmt->execute();
                }
                $notif_stmt->close();

                echo "<script>alert('Job posted! Notified {$provider_count} providers.'); loadNotifications();</script>";
            } else {
                // Delete the job if no providers (optional cleanup)
                $conn->query("DELETE FROM job_requests WHERE id = $job_id");
                echo "<script>alert('No providers available for {$skill} in the selected range. Please try another skill or adjust distance (feature coming soon).'); loadNotifications();</script>";
            }
            $job_stmt->close();
        } else {
            echo "<script>alert('Error posting job. Try again.');</script>";
        }
    }
}

// Fetch seeker's notifications (posted jobs) - Updated to include assigned provider details
$seeker_id = $user['id'];

$sql = "SELECT jr.id, jr.skill, jr.district, jr.status, jr.created_at,
               sp.name AS provider_name, sp.contact AS provider_contact
        FROM job_requests jr
        LEFT JOIN service_provider sp ON jr.assigned_provider_id = sp.id
        WHERE jr.seeker_id = ?
        ORDER BY jr.created_at DESC
        LIMIT 25";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $seeker_id);
$stmt->execute();
$result = $stmt->get_result();

$seeker_notifs = [];
while ($row = $result->fetch_assoc()) {
    $seeker_notifs[] = $row;
}

$stmt->close();

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.html");
    exit();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Service Portal - Seeker</title>
  <style>
    :root{--header-bg: #a55cd6;--accent: #f1c40f;--header-height: 68px;}
    *{box-sizing:border-box}
    body {margin: 0;font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;background-color: #ffffff;color: #333;}
    header {display: flex;align-items: center;gap: 20px;padding: 12px 28px;background: var(--header-bg);color: white;position: fixed;top: 0;left: 0;right: 0;height: var(--header-height);z-index: 1000;box-shadow: 0 2px 6px rgba(0,0,0,0.12);}
    header h1 {margin: 0;font-size: 20px;color: var(--accent);flex-shrink: 0;letter-spacing: 0.6px;}
    nav {display: flex;gap: 20px;justify-content: center;flex: 1;align-items: center;}
    nav a {text-decoration: none;color: white;font-weight: 600;transition: color .18s, border-bottom .18s;padding: 6px 4px;cursor: pointer;border-bottom: 2px solid transparent;}
    nav a:hover {color: var(--accent);}
    nav a.active {color: var(--accent);border-bottom-color: var(--accent);}
    .icons {display: flex;gap: 12px;align-items: center;flex-shrink: 0;}
    .icons .icon {font-size: 22px;line-height: 1;cursor: pointer;padding: 6px;border-radius: 6px;display: inline-flex;align-items: center;justify-content: center;transition: transform .12s, color .12s;}
    .icons .profile-icon {color: #2ecc71;} .icons .notification-icon {color: var(--accent);} .icons .icon:hover {transform: scale(1.3);}
    main {padding-top: calc(var(--header-height) + 18px);} section {display: none;padding: 36px 20px 80px;text-align: center;min-height: calc(100vh - var(--header-height) - 18px);background: #f9f9f9;} section.active {display: block;}
    .form-container, .profile-card {max-width: 780px;margin: 18px auto;background: white;padding: 18px;border-radius: 10px;box-shadow: 0 6px 18px rgba(0,0,0,0.06);text-align: left;}
    .profile-field {margin: 12px 0;} .profile-field span {font-weight: 700;}
    .edit-icon {position: absolute;top: 12px;right: 12px;cursor: pointer;font-size: 16px;color: #2980b9;background: transparent;border-radius: 6px;padding: 6px;}
    .moving-text {max-width: 1500px;margin: 12px auto;background: var(--accent);padding: 8px 12px;border-radius: 8px;font-weight: 700;overflow: hidden;position: relative;display: flex;align-items: center;height: 45px;}
    .scrolling-text {white-space: nowrap;display: inline-block;will-change: transform;animation: scroll-left 12s linear infinite;}
    .moving-text:hover .scrolling-text {animation-play-state: paused;}
    .form-container label {font-weight: bold;display: block;margin-bottom: 6px;font-size: 16px;}
    .form-container select, .form-container input, .form-container textarea {width: 100%;padding: 10px;border-radius: 6px;border: 1px solid #ccc;margin-bottom: 20px;font-size: 15px;box-sizing: border-box;}
    .form-container button {background: #007BFF;color: white;padding: 6px 14px;border: none;border-radius: 5px;cursor: pointer;font-size: 16px;margin-right: 10px;}
    .form-container button:hover {background: #0056b3;}
    .edit-form {display: none; margin-top: 20px;} .edit-form.active {display: block;}
    .profile-field input, .profile-field select, .profile-field textarea {width: 100%;padding: 8px;border: 1px solid #ccc;border-radius: 4px;box-sizing: border-box;}
    .notification-item {padding: 10px; margin: 10px 0; border-left: 4px solid #007BFF; background: #f9f9f9; border-radius: 4px;}
    .notification-item.read {border-left-color: #ccc; background: #fff;}
    .notification-item.assigned {border-left-color: #28a745; background: #d4edda;}
    @keyframes scroll-left {0% {transform: translateX(100%);} 100% {transform: translateX(-100%);}}
    @media (max-width: 640px) {header {padding: 10px 14px; height: 64px;} nav {gap: 12px;} .moving-text {height: 38px; padding: 8px 10px;} .scrolling-text {font-size: 14px;}}
  </style>
</head>
<body>
  <header>
    <h1>SERVICE PORTAL</h1>
    <nav aria-label="Main navigation">
      <a data-target="home" onclick="openTab('home', this)" class="active">Home</a>
      <a data-target="about" onclick="openTab('about', this)">About Us</a>
      <a data-target="jobtype" onclick="openTab('jobtype', this)">Job Type</a>
      <a data-target="latest" onclick="openTab('latest', this)">Latest News</a>
    </nav>
    <div class="icons">
      <div class="icon profile-icon" title="Profile" onclick="openTab('profile')">👤</div>
      <div class="icon notification-icon" title="Notifications" onclick="openTab('notification')">🔔</div>
      <a href="?logout=1" title="Logout" style="color: white; text-decoration: none; font-size: 16px;">Logout</a>
    </div>
  </header>

  <main>
    <section id="home" class="active">
      <h2>Welcome to Our Service Seeker Portal, <?= htmlspecialchars($user['name']); ?>!</h2>
      <p>We provide professional services for construction, renovation, skilled workers, and now medical support.</p>
    </section>

    <section id="about">
      <h2>About Us</h2>
      <p>We are a team of professionals in construction, renovation, and service industries, connecting skilled labor with clients.</p>
    </section>

    <section id="profile">
      <h2>Profile</h2>
      <div class="profile-card">
        <button class="edit-icon" onclick="toggleEdit()" aria-label="Edit profile">✏</button>
        <div id="view-profile">
          <div class="profile-field"><span>Name:</span> <?= htmlspecialchars($user['name']); ?></div>
          <div class="profile-field"><span>Contact:</span> <?= htmlspecialchars($user['contact']); ?></div>
          <div class="profile-field"><span>Address:</span> <?= htmlspecialchars($user['address']); ?></div>
          <div class="profile-field"><span>Gender:</span> <?= htmlspecialchars(ucfirst($user['gender'])); ?></div>
        </div>
        <form id="edit-profile" class="edit-form" method="POST" action="">
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
          <input type="hidden" name="update_profile" value="1">
          <div class="profile-field">
            <label>Name:</label>
            <input type="text" name="name" value="<?= htmlspecialchars($user['name']); ?>" required>
          </div>
          <div class="profile-field">
            <label>Contact:</label>
            <input type="tel" name="contact" value="<?= htmlspecialchars($user['contact']); ?>" maxlength="10" required>
          </div>
          <div class="profile-field">
            <label>Address:</label>
            <textarea name="address" rows="3" required><?= htmlspecialchars($user['address']); ?></textarea>
          </div>
          <div class="profile-field">
            <label>Gender:</label>
            <select name="gender" required>
              <option value="male" <?= ($user['gender'] == 'male' ? 'selected' : ''); ?>>Male</option>
              <option value="female" <?= ($user['gender'] == 'female' ? 'selected' : ''); ?>>Female</option>
              <option value="other" <?= ($user['gender'] == 'other' ? 'selected' : ''); ?>>Other</option>
            </select>
          </div>
          <button type="submit">Save Changes</button>
          <button type="button" onclick="toggleEdit()">Cancel</button>
        </form>
      </div>
    </section>

    <section id="jobtype">
      <h2>Job Type</h2>
      <div class="form-container">
        <form method="POST" action="">
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
          <input type="hidden" name="post_job" value="1">
          <label for="skill">Select a Skill</label>
          <select id="skill" name="skill" required>
            <option value="">Choose Skill</option>
            <option value="painter">Painter</option>
            <option value="carpenter">Carpenter</option>
            <option value="electrician">Electrician</option>
            <option value="plumber">Plumber</option>
            <option value="mason">Mason</option>
            <option value="household">Household Helper</option>
          </select>
          <label for="district">Select Location</label>
          <select id="district" name="district" required>
              <option value="">Choose District</option>
              <option value="Kalaburagi">Kalaburagi</option>
              <option value="Yadgir">Yadgir</option>
              <option value="Bidar">Bidar</option>
              <option value="Raichur">Raichur</option>
          </select>
          <button type="submit">Post Job Request</button>
        </form>
      </div>
    </section>

    <section id="latest">
      <h2>Latest News</h2>
      <div class="moving-text" role="region" aria-live="polite">
        <div class="scrolling-text">
          🚨 The portal is now offering services for medical purposes also 🚨
        </div>
      </div>
    </section>

    <section id="notification">
      <h2>Notifications (Your Posted Jobs)</h2>
      <div class="form-container">
        <ul id="notif-list">
        </ul>
        <button type="button" onclick="loadNotifications()" style="background: #09fbf3ff; color: Black; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer;font-weight: bold; margin-top: 10px;">🔄 Refresh Notifications</button>
        <p><em>Notifications update after posting a job or accepting/rejecting. Click refresh to check for updates.</em></p>
        <?php if (empty($seeker_notifs)): ?>
          <li>Welcome! Post a job to get started. No jobs posted yet.</li>
        <?php else: ?>
          <?php foreach ($seeker_notifs as $notif): ?>
            <li class="notification-item <?= ($notif['status'] == 'assigned' ? 'assigned' : ''); ?>">
              Job ID: <?= htmlspecialchars($notif['id']); ?> - Posted for <?= htmlspecialchars(ucfirst($notif['skill'])); ?> (Location: <?= htmlspecialchars($notif['district']); ?>)
 - Status: <?= htmlspecialchars(ucfirst($notif['status'])); ?> - Posted on: <?= date('Y-m-d H:i', strtotime($notif['created_at'])); ?>
              <?php if ($notif['status'] == 'assigned' && !empty($notif['provider_name'])): ?>
                <br><strong style="color: #28a745;">✅ Assigned to <?= htmlspecialchars($notif['provider_name']); ?>! Contact them at: <?= htmlspecialchars($notif['provider_contact']); ?> to confirm details.</strong>
    <br></br>
                <button class="btn-confirm" style="background: #28a745;font-weight: bold;">Contract Confirmed</button>
                <button class="btn-reject"style="background: #e52929ff;font-weight: bold;" >Contract Rejected</button>

              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        <?php endif; ?>
        </ul>
        <p><em>Notifications update automatically after posting a job. Check back for provider responses.</em></p>
      </div>
    </section>
  </main>
<script>
  function openTab(tabId, clickedEl) {
    document.querySelectorAll('main section').forEach(s => s.classList.remove('active'));
    const section = document.getElementById(tabId);
    if (section) section.classList.add('active');

    document.querySelectorAll('nav a').forEach(a => a.classList.remove('active'));
    const navLink = document.querySelector(`nav a[data-target="${tabId}"]`);
    if (navLink) navLink.classList.add('active');

    if (history.pushState) {
      history.pushState(null, null, '#' + tabId);
    }
  }

  function toggleEdit() {
    const view = document.getElementById('view-profile');
    const edit = document.getElementById('edit-profile');
    const isEditing = view.style.display === 'none';
    view.style.display = isEditing ? 'block' : 'none';
    edit.style.display = isEditing ? 'none' : 'block';
  }

  function loadNotifications() {
    location.reload();
  }

  window.addEventListener('DOMContentLoaded', function() {
    const hash = window.location.hash.substring(1);
    const initialTab = hash || 'home';
    openTab(initialTab);
  });

  window.addEventListener('hashchange', function() {
    const hash = window.location.hash.substring(1);
    if (hash) openTab(hash);
  });

  // -----------------------------------------------
  // FINAL FIXED CONTRACT CONFIRM/REJECT SCRIPT
  // -----------------------------------------------
 document.addEventListener('click', function(e) {
  if (!e.target) return;

  const isConfirm = e.target.classList.contains('btn-confirm');
  const isReject  = e.target.classList.contains('btn-reject');

  if (!isConfirm && !isReject) return;

  const li = e.target.closest('li');
  if (!li) return;

  // Remove BOTH buttons immediately
  const confirmBtn = li.querySelector('.btn-confirm');
  const rejectBtn = li.querySelector('.btn-reject');
  if (confirmBtn) confirmBtn.remove();
  if (rejectBtn) rejectBtn.remove();

  // Create message element
  let msg = document.createElement("div");
  msg.style.marginTop = "8px";
  msg.style.fontWeight = "bold";

  if (isConfirm) {
    msg.style.color = "#28a745";
    msg.textContent = "The contract is confirmed.";
  }

  if (isReject) {
    msg.style.color = "#e52929";
    msg.textContent = "The contract has been rejected.";
  }

  li.appendChild(msg);
});
</script>

</body>
</html>