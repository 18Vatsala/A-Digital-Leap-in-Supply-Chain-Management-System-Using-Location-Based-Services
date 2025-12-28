<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'provider') {
    header("Location: login.html");
    exit();
}
$user = $_SESSION['user'];
$provider_id = $user['id']; // ensure provider id is taken from the logged-in session
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
    $age = (int)$_POST['age'];
    $gender = strtolower(trim($_POST['gender']));
    $contact = (int)$_POST['contact'];
    $govid = trim($_POST['govid']);
    $altcontact = !empty($_POST['altcontact']) ? (int)$_POST['altcontact'] : null;
    $address = trim($_POST['address']);
    $experience = (int)$_POST['experience'];
    $shifts = trim($_POST['shifts']);

    if (empty($name) || $age < 18 || empty($gender) || $contact < 1000000000 || empty($govid) || empty($address)) {
        echo "<script>alert('Invalid input. Please check your details.');</script>";
    } else {
        $update_stmt = $conn->prepare("UPDATE service_provider SET name=?, age=?, gender=?, contact=?, govid=?, altcontact=?, address=?, experience=?, shifts=? WHERE username=?");
        $update_stmt->bind_param("sisssisssi", $name, $age, $gender, $contact, $govid, $altcontact, $address, $experience, $shifts, $user['username']);
        if ($update_stmt->execute()) {
            $_SESSION['user'] = [
                'id' => $user['id'],
                'name' => $name,
                'age' => $age,
                'gender' => $gender,
                'contact' => $contact,
                'govid' => $govid,
                'altcontact' => $altcontact,
                'address' => $address,
                'experience' => $experience,
                'shifts' => $shifts,
                'skill' => $user['skill'] // Unchanged, readonly
            ];
            echo "<script>alert('Profile updated successfully!'); loadNotifications();</script>";
        } else {
            echo "<script>alert('Error updating profile. Try again.');</script>";
        }
        $update_stmt->close();
    }
}

// Handle accept/reject job (POST) - Updated to assign provider and share contacts
if ($_POST && isset($_POST['action']) && isset($_POST['job_id']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $action = trim($_POST['action']);
    $job_id = (int)$_POST['job_id'];

    if ($action !== 'accept' && $action !== 'reject' || $job_id <= 0) {
        echo "<script>alert('Invalid action.'); loadNotifications();</script>";
    } else {
        // Verify the notification exists for this provider and job
        $verify_stmt = $conn->prepare("SELECT id FROM provider_notifications WHERE provider_id = ? AND job_id = ? AND status = 'unread'");
        $verify_stmt->bind_param("ii", $provider_id, $job_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        if ($verify_result->num_rows === 0) {
            echo "<script>alert('Invalid job or already handled.'); loadNotifications();</script>";
            $verify_stmt->close();
            exit();
        }
        $verify_stmt->close();

        $new_status = ($action === 'accept') ? 'accepted' : 'rejected';

        // Update notification status
        $update_notif = $conn->prepare("UPDATE provider_notifications SET status = ? WHERE provider_id = ? AND job_id = ?");
        $update_notif->bind_param("sii", $new_status, $provider_id, $job_id);
        $update_notif->execute();
        $update_notif->close();
        
        if ($action === 'accept') {

    // Assign job ONLY if still open
    $update = $conn->prepare("
        UPDATE job_requests
        SET status='assigned', assigned_provider_id=?
        WHERE id=?
          AND status='open'
          AND assigned_provider_id IS NULL
    ");
    $update->bind_param("ii", $provider_id, $job_id);
    $update->execute();

    // If no row updated → job already taken
    if ($update->affected_rows === 0) {
        echo "<script>
            alert('This job has already been assigned or confirmed.');
            loadNotifications();
        </script>";
        exit();
    }

    $update->close();

    echo "<script>
        alert('Job accepted successfully. Waiting for seeker confirmation.');
        loadNotifications();
    </script>";
}

 else {
            echo "<script>alert('Job rejected. The seeker will be notified to try other providers.'); loadNotifications();</script>";
        }
    }
}

// Fetch provider's notifications - Updated to include seeker contact for assigned jobs
$provider_notifs = [];
$notifs_stmt = $conn->prepare("
    SELECT 
        pn.id,
        pn.job_id,
        pn.message,
        pn.status,
        pn.created_at,

        jr.skill,
        jr.district,
        jr.status AS job_status,

        ss.name AS seeker_name,
        ss.contact AS seeker_contact

    FROM provider_notifications pn
    JOIN job_requests jr ON pn.job_id = jr.id
    JOIN service_seeker ss ON jr.seeker_id = ss.id
    WHERE pn.provider_id = ?
    ORDER BY pn.created_at DESC
");

$notifs_stmt->bind_param("i", $user['id']);
$notifs_stmt->execute();
$notifs_result = $notifs_stmt->get_result();
while ($row = $notifs_result->fetch_assoc()) {
    $provider_notifs[] = $row;
}
$notifs_stmt->close();

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
  <title>Service Portal - Provider</title>
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
    .form-container, .profile-card {max-width: 680px;margin: 18px auto;background: white;padding: 18px;border-radius: 10px;box-shadow: 0 6px 18px rgba(0,0,0,0.06);text-align: left;}
    .profile-field {margin: 12px 0; position: relative;} .profile-field span {font-weight: 700;}
    .edit-icon {position: absolute;top: 12px;right: 12px;cursor: pointer;font-size: 16px;color: #2980b9;background: transparent;border-radius: 6px;padding: 6px;}
    .moving-text {max-width: 1500px;margin: 12px auto;background: var(--accent);padding: 8px 12px;border-radius: 8px;font-weight: 700;overflow: hidden;position: relative;display: flex;align-items: center;height: 45px;}
    .scrolling-text {white-space: nowrap;display: inline-block;will-change: transform;animation: scroll-left 12s linear infinite;}
    .moving-text:hover .scrolling-text {animation-play-state: paused;}
    .form-container label {font-weight: bold;display: block;margin-bottom: 6px;font-size: 16px;}
    .form-container select, .form-container input, .form-container textarea {width: 100%;padding: 10px;border-radius: 6px;border: 1px solid #ccc;margin-bottom: 20px;font-size: 15px;box-sizing: border-box;}
    .form-container button {background: #007BFF;color: white;padding: 10px 20px;border: none;border-radius: 5px;cursor: pointer;font-size: 16px;margin-right: 10px;}
    .form-container button:hover {background: #0056b3;}
    .form-container button.accept {background: #28a745;} .form-container button.accept:hover {background: #218838;}
    .form-container button.reject {background: #dc3545;} .form-container button.reject:hover {background: #c82333;}
    .edit-form {display: none; margin-top: 20px;} .edit-form.active {display: block;}
    .profile-field input, .profile-field select, .profile-field textarea {width: 100%;padding: 8px;border: 1px solid #ccc;border-radius: 4px;box-sizing: border-box;}
    .profile-field .readonly {background-color: #f5f5f5;color: #666;font-weight: bold;}
    .notification-item {padding: 15px; margin: 10px 0; border-left: 4px solid #007BFF; background: #f9f9f9; border-radius: 4px;}
    .notification-item.read {border-left-color: #ccc; background: #fff;}
    .notification-item.rejected {border-left-color: #dc3545; opacity: 0.7;}
    .notification-item.accepted {border-left-color: #28a745; background: #d4edda;}
    .notification-actions {margin-top: 10px;}
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
      <h2>Welcome to Our Service Provider Portal, <?= htmlspecialchars($user['name']); ?>!</h2>
      <p>You are a skilled <?= htmlspecialchars(ucfirst($user['skill'])); ?>. Check notifications for new job requests in your area.</p>
    </section>

    <section id="about">
      <h2>About Us</h2>
      <p>We are professionals in construction, renovation, and related services, connecting skilled labor with clients.</p>
    </section>

    <section id="profile">
      <h2>Profile</h2>
      <div class="profile-card">
        <button class="edit-icon" onclick="toggleEdit()" aria-label="Edit profile">✏</button>
        <div id="view-profile">
          <div class="profile-field"><span>Name:</span> <?= htmlspecialchars($user['name']); ?></div>
          <div class="profile-field"><span>Age:</span> <?= htmlspecialchars($user['age']); ?></div>
          <div class="profile-field"><span>Gender:</span> <?= htmlspecialchars(ucfirst($user['gender'])); ?></div>
          <div class="profile-field"><span>Contact:</span> <?= htmlspecialchars($user['contact']); ?></div>
          <div class="profile-field"><span>Alternative Contact:</span> <?= htmlspecialchars($user['altcontact'] ?? 'N/A'); ?></div>
          <div class="profile-field"><span>Address:</span> <?= htmlspecialchars($user['address']); ?></div>
          <div class="profile-field"><span>Govt ID:</span> <?= htmlspecialchars($user['govid']); ?></div>
          <div class="profile-field"><span>Experience:</span> <?= htmlspecialchars($user['experience']); ?> years</div>
          <div class="profile-field"><span>Shift:</span> <?= htmlspecialchars(ucfirst($user['shifts'] ?? '')); ?></div>
          <div class="profile-field"><span>Skill:</span> <?= htmlspecialchars(ucfirst($user['skill'])); ?></div>
        </div>
        <form id="edit-profile" class="edit-form" method="POST" action="">
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
          <input type="hidden" name="update_profile" value="1">
          <div class="profile-field">
            <label>Name:</label>
            <input type="text" name="name" value="<?= htmlspecialchars($user['name']); ?>" required>
          </div>
          <div class="profile-field">
            <label>Age:</label>
            <input type="number" name="age" min="18" value="<?= htmlspecialchars($user['age']); ?>" required>
          </div>
          <div class="profile-field">
            <label>Gender:</label>
            <select name="gender" required>
              <option value="male" <?= ($user['gender'] == 'male' ? 'selected' : ''); ?>>Male</option>
              <option value="female" <?= ($user['gender'] == 'female' ? 'selected' : ''); ?>>Female</option>
              <option value="other" <?= ($user['gender'] == 'other' ? 'selected' : ''); ?>>Other</option>
            </select>
                      </div>
          <div class="profile-field">
            <label>Contact:</label>
            <input type="tel" name="contact" maxlength="10" value="<?= htmlspecialchars($user['contact']); ?>" required>
          </div>
          <div class="profile-field">
            <label>Alternative Contact:</label>
            <input type="tel" name="altcontact" maxlength="10" value="<?= htmlspecialchars($user['altcontact'] ?? ''); ?>">
          </div>
          <div class="profile-field">
            <label>Address:</label>
            <textarea name="address" rows="3" required><?= htmlspecialchars($user['address']); ?></textarea>
          </div>
          <div class="profile-field">
            <label>Govt ID:</label>
            <input type="text" name="govid" value="<?= htmlspecialchars($user['govid']); ?>" required>
          </div>
          <div class="profile-field">
            <label>Experience (years):</label>
            <input type="number" name="experience" min="0" value="<?= htmlspecialchars($user['experience']); ?>">
          </div>
          <div class="profile-field">
            <label>Shift:</label>
            <select name="shifts">
              <option value="day" <?= ($user['shifts'] == 'day' ? 'selected' : ''); ?>>Day</option>
              <option value="night" <?= ($user['shifts'] == 'night' ? 'selected' : ''); ?>>Night</option>
            </select>
          </div>
          <div class="profile-field">
            <label>Skill:</label>
            <input type="text" value="<?= htmlspecialchars(ucfirst($user['skill'])); ?>" readonly class="readonly">
          </div>
          <button type="submit">Save Changes</button>
          <button type="button" onclick="toggleEdit()">Cancel</button>
        </form>
      </div>
    </section>

    <section id="latest">
      <h2>Latest News</h2>
      <div class="moving-text" role="region" aria-live="polite">
        <div class="scrolling-text">
          🚨 The portal is now offering services for medical purposes also — Call +91-98765-43210 for urgent medical support 🚨
        </div>
      </div>
    </section>

    <section id="notification">
      <h2>Notifications (Job Requests)</h2>
      <div class="form-container">
        <ul id="notif-list">
                  </ul>
        <button type="button" onclick="loadNotifications()" style="background: #28a745; color: white; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; margin-top: 10px;">🔄 Refresh Notifications</button>
        <p><em>Notifications update after posting a job or accepting/rejecting. Click refresh to check for updates.</em></p>
          <?php if (empty($provider_notifs)): ?>
            <li>Welcome! As a <?= htmlspecialchars(ucfirst($user['skill'])); ?>, you're ready to accept jobs. No new requests yet—check back soon.</li>
          <?php else: ?>
            <?php foreach ($provider_notifs as $notif): ?>
              <li class="notification-item <?= htmlspecialchars($notif['status']); ?>">
                <strong><?= htmlspecialchars($notif['message']); ?></strong><br>
                <small>Skill: <?= htmlspecialchars(ucfirst($notif['skill'])); ?> | Seeker: <?= htmlspecialchars($notif['seeker_name']); ?> Location: <?= htmlspecialchars($notif['district']); ?>
 | Time: <?= date('Y-m-d H:i', strtotime($notif['created_at'])); ?></small>
                <?php if ($notif['status'] === 'accepted' && !empty($notif['seeker_contact'])): ?>
                  <div style="margin-top: 10px; padding: 8px; background: #e8f5e8; border-radius: 4px; border-left: 3px solid #28a745;">
                    <strong>✅ Job Accepted! Contact the seeker at: <?= htmlspecialchars($notif['seeker_contact']); ?> to confirm details and proceed.</strong>
                  </div>
                <?php endif; ?>
                <div class="notification-actions">
                  <?php if ($notif['status'] === 'unread' && $notif['job_status'] === 'open'): ?>
                    <form method="POST" style="display: inline; margin-right: 10px;">
                      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                      <input type="hidden" name="action" value="accept">
                      <input type="hidden" name="job_id" value="<?= $notif['job_id']; ?>">
                      <button type="submit" class="accept">Accept Job</button>
                    </form>
                    <form method="POST" style="display: inline;">
                      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                      <input type="hidden" name="action" value="reject">
                      <input type="hidden" name="job_id" value="<?= $notif['job_id']; ?>">
                      <button type="submit" class="reject">Reject Job</button>
                    </form>
                  <?php elseif ($notif['status'] === 'accepted'): ?>
                    <em style="color: green;">✅ You accepted this job. Contact details shared above.</em>
                  <?php elseif ($notif['status'] === 'rejected'): ?>
                    <em style="color: red;">❌ You rejected this job.</em>
                    <?php elseif ($notif['job_status'] === 'assigned'): ?>
    <em style="color:#ff9800; font-weight:bold;">
        ⏳ Job already assigned to another provider.
    </em>

<?php elseif ($notif['job_status'] === 'confirmed'): ?>
    <em style="color:#28a745; font-weight:bold;">
        🔒 Contract finalized by seeker.
    </em>
                  <?php else: ?>
                    <em>Handled (<?= htmlspecialchars(ucfirst($notif['status'])); ?>)</em>
                  <?php endif; ?>
                </div>

              </li>
            <?php endforeach; ?>
          <?php endif; ?>
        </ul>
        <p><em>Notifications update after accepting/rejecting. Only unread jobs show action buttons. For accepted jobs, contact details are shared securely.</em></p>
      </div>
    </section>
  </main>

  <script>
function openTab(tabId, clickedEl) {
  // Hide all sections
  document.querySelectorAll('main section').forEach(s => s.classList.remove('active'));
  const section = document.getElementById(tabId);
  if (section) section.classList.add('active');

  // Update navigation link highlighting
  document.querySelectorAll('nav a').forEach(a => a.classList.remove('active'));
  const navLink = document.querySelector(`nav a[data-target="${tabId}"]`);
  if (navLink) navLink.classList.add('active');

  // Update URL hash (so refresh keeps tab)
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

// Restore tab from URL on load
window.addEventListener('DOMContentLoaded', function() {
  const hash = window.location.hash.substring(1);
  const initialTab = hash || 'home';
  openTab(initialTab);
});

// Support browser back/forward navigation
window.addEventListener('hashchange', function() {
  const hash = window.location.hash.substring(1);
  if (hash) openTab(hash);
});
</script>

</body>
</html>