<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

session_start();

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: index');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $db = get_db_connection();
        $stmt = $db->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            header('Location: index');
            exit;
        } else {
            $error = 'Invalid username or password';
        }
    } else {
        $error = 'Please fill in all fields';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' fill='none' stroke='%236366f1' stroke-width='3' stroke-linecap='round' stroke-linejoin='round' viewBox='0 0 24 24'><rect x='16' y='16' width='6' height='6' rx='1'/><rect x='2' y='16' width='6' height='6' rx='1'/><rect x='9' y='2' width='6' height='6' rx='1'/><path d='M5 16v-3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3'/><path d='M12 12V8'/></svg>">
    <title>Login - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root {
            --glass-bg: rgba(15, 23, 42, 0.65);
            --glass-border: rgba(255, 255, 255, 0.08);
            --accent: #6366f1;
        }
        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #020617;
            background-image: 
                radial-gradient(at 0% 0%, hsla(253,16%,12%,1) 0, transparent 50%), 
                radial-gradient(at 50% 0%, hsla(225,39%,20%,1) 0, transparent 55%), 
                radial-gradient(at 100% 0%, hsla(339,49%,18%,1) 0, transparent 50%);
            overflow: hidden;
            font-family: 'Outfit', 'Inter', system-ui, -apple-system, sans-serif;
        }
        .bg-blobs {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            z-index: -1;
            filter: blur(100px);
            opacity: 0.45;
        }
        .blob {
            position: absolute;
            width: 500px;
            height: 500px;
            background: var(--accent);
            border-radius: 50%;
            animation: move 25s infinite alternate;
        }
        @keyframes move {
            from { transform: translate(-20%, -20%) scale(1); }
            to { transform: translate(110%, 110%) scale(1.3); }
        }
        .login-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            padding: 3rem 2.5rem;
            border-radius: 32px;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6);
            animation: slideUp 0.7s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
        }
        .login-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; height: 1px;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(40px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .logo-box {
            background: linear-gradient(135deg, var(--accent), #4f46e5);
            width: 72px; height: 72px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 20px;
            margin: 0 auto 1.5rem;
            box-shadow: 0 12px 20px -5px rgba(99, 102, 241, 0.5);
            transform: rotate(-3deg);
            transition: transform 0.3s ease;
        }
        .login-card:hover .logo-box { transform: rotate(0) scale(1.05); }

        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
        }
        .form-group label {
            display: block;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--text-muted);
            margin-bottom: 0.75rem;
            font-weight: 700;
            padding-left: 4px;
        }
        .field-container {
            display: flex;
            align-items: center;
            background: rgba(15, 23, 42, 0.4);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 0 18px;
            gap: 14px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: inset 0 2px 4px 0 rgba(0, 0, 0, 0.05);
        }
        .field-container i {
            color: rgba(255, 255, 255, 0.4);
            width: 18px;
            height: 18px;
            transition: color 0.3s ease;
            flex-shrink: 0;
        }
        .field-container:focus-within {
            outline: none;
            border-color: var(--accent);
            background: rgba(15, 23, 42, 0.7);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1), 0 10px 15px -3px rgba(0,0,0,0.2);
        }
        .field-container:focus-within i {
            color: var(--accent);
        }
        .modern-input {
            flex: 1;
            background: transparent;
            border: none;
            padding: 16px 0;
            color: white;
            font-size: 0.95rem;
            outline: none;
            box-sizing: border-box;
            min-width: 0;
        }
        .modern-input::placeholder { color: rgba(255, 255, 255, 0.2); }
        .login-btn {
            width: 100%;
            background: var(--accent);
            color: white;
            border: none;
            padding: 16px;
            border-radius: 14px;
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 10px;
            margin-top: 1.5rem;
            transition: all 0.3s ease;
            letter-spacing: 0.5px;
        }
        .login-btn:hover {
            background: #4f46e5;
            transform: translateY(-2px);
            box-shadow: 0 15px 25px -5px rgba(99, 102, 241, 0.4);
        }
        .error-msg {
            background: rgba(239, 68, 68, 0.15);
            color: #fca5a5;
            padding: 14px;
            border-radius: 12px;
            font-size: 0.8rem;
            margin-bottom: 1.5rem;
            text-align: center;
            border: 1px solid rgba(239, 68, 68, 0.3);
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }
    </style>
</head>
<body>
    <div class="bg-blobs">
        <div class="blob" style="top: -100px; left: -100px; background: #3730a3;"></div>
        <div class="blob" style="bottom: -150px; right: -150px; background: #9d174d; animation-delay: -7s;"></div>
    </div>

    <div class="login-card">
        <div class="logo-box">
            <i data-lucide="network" style="color: white; width: 36px; height: 36px;"></i>
        </div>
        
        <div style="text-align: center; margin-bottom: 2.5rem;">
            <h1 style="color: white; font-size: 1.85rem; margin-bottom: 0.5rem; font-weight: 800; letter-spacing: -0.5px;"><?php echo APP_NAME; ?></h1>
            <p style="color: var(--text-muted); font-size: 0.85rem; font-weight: 500;">Network Infrastructure Management</p>
        </div>

        <?php if ($error): ?>
            <div class="error-msg">
                <i data-lucide="alert-triangle" style="width: 16px; height: 16px;"></i>
                <span><?php echo $error; ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="form-group">
                <label>Access Identity</label>
                <div class="field-container">
                    <i data-lucide="user"></i>
                    <input type="text" name="username" class="modern-input" placeholder="Username" required autofocus autocomplete="off" readonly onfocus="this.removeAttribute('readonly');">
                </div>
            </div>

            <div class="form-group">
                <label>Security Key</label>
                <div class="field-container">
                    <i data-lucide="lock-keyhole"></i>
                    <input type="password" name="password" class="modern-input" placeholder="Password" required autocomplete="new-password" readonly onfocus="this.removeAttribute('readonly');">
                </div>
            </div>

            <button type="submit" class="login-btn">
                <span>Authorize Login</span>
                <i data-lucide="arrow-right-circle" style="width: 20px;"></i>
            </button>
        </form>

        <div style="margin-top: 2.5rem; text-align: center; font-size: 0.7rem; color: var(--text-muted); opacity: 0.8;">
            &copy; <?php echo date('Y'); ?> <b><?php echo APP_NAME; ?></b> &bull; Production Environment
        </div>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
