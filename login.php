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
            $error = 'Invalid credentials provided. Access denied.';
        }
    } else {
        $error = 'Please provide both identity and security key.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' fill='none' stroke='%2358a6ff' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round' viewBox='0 0 24 24'><rect x='16' y='16' width='6' height='6' rx='1'/><rect x='2' y='16' width='6' height='6' rx='1'/><rect x='9' y='2' width='6' height='6' rx='1'/><path d='M5 16v-3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3'/><path d='M12 12V8'/></svg>">
    <title>Login - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #0d1117; /* Hardcoded to ensure dark mode regardless of variables missing */
            background-color: var(--background);
            background-image: 
                radial-gradient(circle at 15% 50%, rgba(88, 166, 255, 0.03) 0%, transparent 50%),
                radial-gradient(circle at 85% 30%, rgba(63, 185, 80, 0.02) 0%, transparent 50%);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            font-size: 14px;
        }
        
        .login-wrapper {
            width: 100%;
            max-width: 400px;
            padding: 2rem;
            position: relative;
            z-index: 1;
        }

        .login-card {
            background: #161b22;
            background: var(--surface);
            border: 1px solid #30363d;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 2.5rem 2rem;
            box-shadow: 0 8px 24px rgba(0,0,0,0.5);
            animation: slideUp 0.5s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .brand-header {
            text-align: center;
            margin-bottom: 2.5rem;
        }

        .logo-icon {
            color: #58a6ff;
            color: var(--primary);
            margin-bottom: 1rem;
            display: inline-block;
            background: rgba(88, 166, 255, 0.1);
            padding: 12px;
            border-radius: 8px;
            border: 1px solid rgba(88, 166, 255, 0.2);
        }

        .brand-title {
            color: #e6edf3;
            color: var(--text);
            font-size: 1.5rem;
            font-weight: 600;
            letter-spacing: -0.5px;
            margin-bottom: 0.25rem;
        }

        .brand-subtitle {
            color: #8b949e;
            color: var(--text-muted);
            font-size: 0.85rem;
            font-family: 'JetBrains Mono', monospace;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.8rem;
            font-weight: 500;
            color: #e6edf3;
            color: var(--text);
        }

        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 12px;
            color: #8b949e;
            color: var(--text-muted);
            pointer-events: none;
        }

        .form-control {
            width: 100%;
            background: #0d1117;
            background: var(--background);
            border: 1px solid #30363d;
            border: 1px solid var(--border);
            color: #e6edf3;
            color: var(--text);
            border-radius: 6px;
            padding: 10px 12px 10px 38px;
            font-size: 0.9rem;
            font-family: 'JetBrains Mono', monospace;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: #58a6ff;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(88, 166, 255, 0.1);
        }

        .form-control::placeholder {
            color: #484f58;
            font-family: 'Inter', sans-serif;
        }

        .btn-submit {
            width: 100%;
            background: #238636;
            color: #ffffff;
            border: 1px solid rgba(240, 246, 252, 0.1);
            padding: 10px;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }

        .btn-submit:hover {
            background: #2ea043;
            border-color: rgba(240, 246, 252, 0.2);
        }

        .alert-error {
            background: rgba(248, 81, 73, 0.1);
            border: 1px solid rgba(248, 81, 73, 0.4);
            color: #ff7b72;
            padding: 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .footer-text {
            text-align: center;
            margin-top: 2rem;
            color: #8b949e;
            color: var(--text-muted);
            font-size: 0.75rem;
            font-family: 'JetBrains Mono', monospace;
        }
    </style>
</head>
<body>

    <div class="login-wrapper">
        <div class="login-card">
            
            <div class="brand-header">
                <div class="logo-icon">
                    <i data-lucide="server" style="width: 32px; height: 32px;"></i>
                </div>
                <h1 class="brand-title"><?php echo APP_NAME; ?></h1>
                <div class="brand-subtitle">Network Operations Console</div>
            </div>

            <?php if ($error): ?>
                <div class="alert-error">
                    <i data-lucide="shield-alert" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="login" autocomplete="off">
                <div class="form-group">
                    <div class="form-label">
                        <label for="username">Identity</label>
                    </div>
                    <div class="input-group">
                        <i data-lucide="user" class="input-icon" style="width: 16px; height: 16px;"></i>
                        <input type="text" id="username" name="username" class="form-control" placeholder="admin" required autofocus autocomplete="off">
                    </div>
                </div>

                <div class="form-group">
                    <div class="form-label">
                        <label for="password">Authentication Key</label>
                    </div>
                    <div class="input-group">
                        <i data-lucide="key" class="input-icon" style="width: 16px; height: 16px;"></i>
                        <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required autocomplete="new-password">
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    Initialize Session
                    <i data-lucide="arrow-right" style="width: 16px; height: 16px;"></i>
                </button>
            </form>
            
        </div>
        
        <div class="footer-text">
            SYSTEM VERSION <?php echo APP_VERSION; ?> &bull; RESTRICTED ACCESS
        </div>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
