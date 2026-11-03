<?php
declare(strict_types=1);

const MAX_PATH_LENGTH = 4096;
const DEFAULT_USERNAME = 'user';
const DEFAULT_PASSWORD = 'P@$$w0rd';
// The upload directory is the browser's root; files outside it are never listed or downloadable.
$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'upload';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
    http_response_code(500);
    exit('Unable to create upload directory.');
}
$root = realpath($uploadDir);
if ($root === false) {
    http_response_code(500);
    exit('Unable to determine upload directory.');
}
$uploadDir = $root;

$databasePath = __DIR__ . DIRECTORY_SEPARATOR . 'auth.sqlite';
$databaseExists = is_file($databasePath);
if (!extension_loaded('pdo_sqlite')) {
    error_log('The PDO SQLite extension is required for authentication.');
    http_response_code(500);
    exit('The PDO SQLite extension is required. Install/enable it and restart the server.');
}
try {
    $database = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5
    ]);
    $database->exec('PRAGMA busy_timeout = 5000');
    $database->exec('CREATE TABLE IF NOT EXISTS users (username TEXT PRIMARY KEY, password_hash TEXT NOT NULL)');
    if (!$databaseExists) {
        if (!chmod($databasePath, 0600)) {
            throw new RuntimeException('Unable to restrict permissions on the authentication database.');
        }
        $insertUser = $database->prepare('INSERT OR IGNORE INTO users (username, password_hash) VALUES (:username, :password_hash)');
        $insertUser->execute([
            ':username' => DEFAULT_USERNAME,
            ':password_hash' => password_hash(DEFAULT_PASSWORD, PASSWORD_DEFAULT)
        ]);
    }
} catch (Throwable $e) {
    error_log('Unable to initialize authentication database: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to initialize the authentication database.');
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax'
]);
if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
    http_response_code(500);
    exit('Unable to start the authentication session.');
}
if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function safeRelativePath(string $path): string
{
    $path = str_replace('\\', '/', $path);
    if (strlen($path) > MAX_PATH_LENGTH || str_contains($path, "\0"))
        throw new RuntimeException('Invalid path.');
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.')
            continue;
        if ($part === '..') {
            if (!$parts)
                throw new RuntimeException('Path is outside the server directory.');
            array_pop($parts);
        } else
            $parts[] = $part;
    }
    return implode('/', $parts);
}
function insideRoot(string $candidate, string $root): bool
{
    $candidate = rtrim(str_replace('\\', '/', $candidate), '/');
    $root = rtrim(str_replace('\\', '/', $root), '/');
    return $candidate === $root || str_starts_with($candidate, $root . '/');
}
function resolveExisting(string $relative, string $root): string
{
    $relative = safeRelativePath($relative);
    $candidate = $root . ($relative === '' ? '' : DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    $real = realpath($candidate);
    if ($real === false || !insideRoot($real, $root))
        throw new RuntimeException('File or folder not found.');
    return $real;
}
function humanSize(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = (float) $bytes;
    foreach ($units as $unit) {
        if ($size < 1024 || $unit === 'TB')
            return ($unit === 'B' ? (string) (int) $size : number_format($size, 1)) . ' ' . $unit;
        $size /= 1024;
    }
    return $bytes . ' B';
}

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        $loginError = 'Your sign-in request expired. Please try again.';
    } else {
        $username = is_string($_POST['username'] ?? null) ? $_POST['username'] : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        try {
            $findUser = $database->prepare('SELECT password_hash FROM users WHERE username = :username');
            $findUser->execute([':username' => $username]);
            $passwordHash = $findUser->fetchColumn();
        } catch (PDOException $e) {
            error_log('Unable to verify sign-in credentials: ' . $e->getMessage());
            http_response_code(500);
            exit('Unable to verify sign-in credentials.');
        }
        if (is_string($passwordHash) && password_verify($password, $passwordHash)) {
            if (!session_regenerate_id(true)) {
                http_response_code(500);
                exit('Unable to secure the authentication session.');
            }
            $_SESSION['authenticated'] = true;
            $_SESSION['username'] = $username;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header('Location: ./', true, 303);
            exit;
        }
        $loginError = 'Invalid username or password.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        http_response_code(403);
        exit('Invalid sign-out request.');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $cookie['path'],
            'domain' => $cookie['domain'],
            'secure' => $cookie['secure'],
            'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'] ?? 'Lax'
        ]);
    }
    session_destroy();
    header('Location: ./', true, 303);
    exit;
}

$action = $_GET['api'] ?? '';
$authenticated = !empty($_SESSION['authenticated']);
$passwordMessage = '';
$passwordError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change-password') {
    if (!$authenticated) {
        http_response_code(401);
        exit('Authentication required.');
    }
    $submittedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        http_response_code(403);
        exit('Invalid password-change request. Reload the page and try again.');
    }

    $currentPassword = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
    $newPassword = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
    $confirmPassword = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
    $username = is_string($_SESSION['username'] ?? null) ? $_SESSION['username'] : DEFAULT_USERNAME;
    try {
        $findUser = $database->prepare('SELECT password_hash FROM users WHERE username = :username');
        $findUser->execute([':username' => $username]);
        $passwordHash = $findUser->fetchColumn();
        if (!is_string($passwordHash) || !password_verify($currentPassword, $passwordHash)) {
            $passwordError = 'Current password is incorrect.';
        } elseif (strlen($newPassword) < 8) {
            $passwordError = 'New password must be at least 8 characters.';
        } elseif (strlen($newPassword) > 72) {
            $passwordError = 'New password must be no more than 72 bytes.';
        } elseif (!hash_equals($newPassword, $confirmPassword)) {
            $passwordError = 'New password and confirmation do not match.';
        } elseif (!session_regenerate_id(true)) {
            http_response_code(500);
            exit('Unable to secure the authentication session.');
        } else {
            $updateUser = $database->prepare('UPDATE users SET password_hash = :password_hash WHERE username = :username');
            $updateUser->execute([
                ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                ':username' => $username
            ]);
            if ($updateUser->rowCount() !== 1) {
                throw new RuntimeException('The account password was not updated.');
            }
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $passwordMessage = 'Password changed successfully.';
        }
    } catch (Throwable $e) {
        error_log('Unable to change account password: ' . $e->getMessage());
        http_response_code(500);
        exit('Unable to change the account password.');
    }
}
if (!$authenticated && ($action !== '' || isset($_GET['download']))) {
    jsonResponse(['error' => 'Authentication required. Please sign in.'], 401);
}
if (!$authenticated) {
    $csrfToken = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $errorHtml = $loginError === '' ? '' : '<p class="error-message" role="alert">' . htmlspecialchars($loginError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    session_write_close();
    ?>
    <!doctype html>
    <html lang="en">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/png" href="favicon.png">
        <title>Sign in - PHP File Server</title>
        <link rel="stylesheet" href="style.css">
    </head>

    <body>
        <main class="container">
            <header class="topbar">
                <div>
                    <p class="eyebrow">LIGHTWEIGHT FILE SERVER</p>
                    <h1>Sign in</h1>
                    <p class="subtitle">Sign in to browse and manage files.</p>
                </div>
            </header>
            <section class="panel auth-panel">
                <h2>Account sign-in</h2>
                <?= $errorHtml ?>
                <form class="auth-form" method="post">
                    <input type="hidden" name="action" value="login">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <label for="username">Username</label><input id="username" name="username" type="text"
                        autocomplete="username" required autofocus>
                    <label for="password">Password</label><input id="password" name="password" type="password"
                        autocomplete="current-password" required>
                    <button class="button primary" type="submit">Sign in</button>
                </form>
            </section>
        </main>
    </body>

    </html>
    <?php
    exit;
}
if (($action !== '' || isset($_GET['download'])) && $action !== 'upload-progress') {
    session_write_close();
}
if ($action !== '') {
    if ($action === 'list') {
        try {
            $relative = safeRelativePath((string) ($_GET['path'] ?? ''));
            $folder = resolveExisting($relative, $root);
            if (!is_dir($folder))
                jsonResponse(['error' => 'That path is not a folder.'], 400);
            $entries = scandir($folder);
            if ($entries === false)
                jsonResponse(['error' => 'Unable to read this folder.'], 500);
            $items = [];
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..')
                    continue;
                $real = realpath($folder . DIRECTORY_SEPARATOR . $entry);
                if ($real === false || !insideRoot($real, $root))
                    continue;
                $isDir = is_dir($real);
                $size = $isDir ? null : (int) (filesize($real) ?: 0);
                $items[] = [
                    'name' => $entry,
                    'path' => ltrim(($relative === '' ? '' : $relative . '/') . $entry, '/'),
                    'type' => $isDir ? 'directory' : 'file',
                    'size' => $size,
                    'sizeText' => $isDir ? '—' : humanSize($size),
                    'modified' => date('Y-m-d H:i', (int) (filemtime($real) ?: time()))
                ];
            }
            usort($items, static function (array $a, array $b): int {
                if ($a['type'] !== $b['type'])
                    return $a['type'] === 'directory' ? -1 : 1;
                return strnatcasecmp($a['name'], $b['name']);
            });
            jsonResponse(['path' => $relative, 'items' => $items]);
        } catch (Throwable $e) {
            jsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    if ($action === 'upload') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
            jsonResponse(['error' => 'Use POST to upload files.'], 405);
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            jsonResponse(['error' => 'Could not create upload directory.'], 500);
        }
        if (empty($_FILES['files'])) {
            $limit = ini_get('post_max_size');
            jsonResponse(['error' => 'No file received. The request may exceed PHP post_max_size (' . $limit . '). Check php.ini and restart the server.'], 413);
        }
        $incoming = $_FILES['files'];
        $names = is_array($incoming['name']) ? $incoming['name'] : [$incoming['name']];
        $tmpNames = is_array($incoming['tmp_name']) ? $incoming['tmp_name'] : [$incoming['tmp_name']];
        $errors = is_array($incoming['error']) ? $incoming['error'] : [$incoming['error']];
        $sizes = is_array($incoming['size']) ? $incoming['size'] : [$incoming['size']];
        $results = [];
        foreach ($names as $i => $originalName) {
            $originalName = (string) $originalName;
            $errorCode = (int) ($errors[$i] ?? UPLOAD_ERR_NO_FILE);
            if ($errorCode !== UPLOAD_ERR_OK) {
                $messages = [
                    UPLOAD_ERR_INI_SIZE => 'File exceeds PHP upload_max_filesize (' . ini_get('upload_max_filesize') . ').',
                    UPLOAD_ERR_FORM_SIZE => 'File exceeds the form upload limit.',
                    UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
                    UPLOAD_ERR_NO_FILE => 'No file was received.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Server temporary folder is missing.',
                    UPLOAD_ERR_CANT_WRITE => 'Server could not write the file.',
                    UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.'
                ];
                $results[] = ['name' => $originalName, 'success' => false, 'message' => $messages[$errorCode] ?? 'Unknown upload error.'];
                continue;
            }
            $name = basename(str_replace('\\', '/', $originalName));
            $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
            if ($name === '' || $name === '.' || $name === '..') {
                $results[] = ['name' => $originalName, 'success' => false, 'message' => 'Invalid filename.'];
                continue;
            }
            if (in_array(strtolower($name), ['.htaccess', '.user.ini'], true)) {
                $results[] = ['name' => $originalName, 'success' => false, 'message' => 'Server configuration files are not allowed.'];
                continue;
            }
            if (preg_match('/\.(?:php[0-9]*|phtml|phtm?|phar|phps)$/i', $name) === 1) {
                $results[] = ['name' => $originalName, 'success' => false, 'message' => 'PHP files are not allowed.'];
                continue;
            }
            $tmpName = (string) ($tmpNames[$i] ?? '');
            if (!is_uploaded_file($tmpName)) {
                $results[] = ['name' => $originalName, 'success' => false, 'message' => 'PHP did not recognize this as a valid uploaded file.'];
                continue;
            }

            $destination = $uploadDir . DIRECTORY_SEPARATOR . $name;
            if (file_exists($destination)) {
                // Same name: compare SHA-256 checksums without loading large files into RAM.
                if (is_file($destination)) {
                    $existingHash = hash_file('sha256', $destination);
                    $incomingHash = hash_file('sha256', $tmpName);
                    if ($existingHash !== false && $incomingHash !== false && hash_equals($existingHash, $incomingHash)) {
                        // Identical content already exists: discard the incoming duplicate.
                        $results[] = ['name' => $originalName, 'success' => true, 'message' => 'Identical file already exists; duplicate discarded (' . $name . ').', 'duplicate' => true, 'savedAs' => $name, 'size' => (int) ($sizes[$i] ?? 0)];
                        continue;
                    }
                }

                // Same name but different content (or existing path is not a regular file):
                // choose a non-conflicting name such as report (1).pdf.
                $pathInfo = pathinfo($name);
                $base = $pathInfo['filename'] ?? $name;
                $extension = isset($pathInfo['extension']) && $pathInfo['extension'] !== '' ? '.' . $pathInfo['extension'] : '';
                $counter = 1;
                do {
                    $candidateName = $base . ' (' . $counter . ')' . $extension;
                    $destination = $uploadDir . DIRECTORY_SEPARATOR . $candidateName;
                    $counter++;
                } while (file_exists($destination));
                $name = $candidateName;
            }

            if (!move_uploaded_file($tmpName, $destination)) {
                $results[] = ['name' => $originalName, 'success' => false, 'message' => 'Could not save uploaded file.'];
                continue;
            }
            $results[] = ['name' => $originalName, 'success' => true, 'message' => 'Saved to upload/' . $name, 'savedAs' => $name, 'size' => (int) ($sizes[$i] ?? 0)];
        }
        $successCount = count(array_filter($results, static fn(array $result): bool => $result['success']));
        $status = $successCount === count($results) ? 200 : ($successCount > 0 ? 207 : 422);
        jsonResponse(['results' => $results], $status);
    }

    if ($action === 'upload-progress') {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET')
            jsonResponse(['error' => 'Use GET to read upload progress.'], 405);
        if (filter_var(ini_get('session.upload_progress.enabled'), FILTER_VALIDATE_BOOLEAN) !== true) {
            jsonResponse(['error' => 'PHP upload progress is disabled.'], 501);
        }
        $progressId = (string) ($_GET['id'] ?? '');
        if (preg_match('/\A[a-z0-9-]{1,64}\z/i', $progressId) !== 1) {
            jsonResponse(['error' => 'Invalid upload progress ID.'], 400);
        }
        if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
            jsonResponse(['error' => 'Unable to start upload progress session.'], 500);
        }
        $prefix = ini_get('session.upload_progress.prefix');
        $key = ($prefix === false ? '' : $prefix) . $progressId;
        $progress = $_SESSION[$key] ?? null;
        session_write_close();
        jsonResponse([
            'active' => is_array($progress),
            'bytesProcessed' => is_array($progress) ? (int) ($progress['bytes_processed'] ?? 0) : 0,
            'contentLength' => is_array($progress) ? (int) ($progress['content_length'] ?? 0) : 0,
            'done' => is_array($progress) && !empty($progress['done'])
        ]);
    }

    jsonResponse(['error' => 'Unknown API action.'], 404);
}
if (isset($_GET['download'])) {
    try {
        $file = resolveExisting((string) $_GET['download'], $root);
        if (!is_file($file) || !is_readable($file)) {
            http_response_code(404);
            exit('File not found.');
        }
        $name = basename($file);
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . (string) filesize($file));
        header('Content-Disposition: attachment; filename="' . addcslashes($name, "\"\\") . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($file);
        exit;
    } catch (Throwable $e) {
        http_response_code(400);
        exit('Invalid download path.');
    }
}
$uploadProgressName = (string) ini_get('session.upload_progress.name');
$csrfToken = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$passwordFeedback = $passwordError !== ''
    ? '<p class="error-message" role="alert">' . htmlspecialchars($passwordError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
    : ($passwordMessage !== ''
        ? '<p class="success-message" role="status">' . htmlspecialchars($passwordMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        : '');
session_write_close();
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>PHP File Server</title>
    <link rel="stylesheet" href="style.css">
    <script src="app.js" defer></script>
</head>

<body>
    <main class="container">
        <header class="topbar">
            <div>
                <p class="eyebrow">LIGHTWEIGHT FILE SERVER</p>
                <h1>File browser</h1>
                <p class="subtitle">Browse and download files, or upload multiple files at once.</p>
            </div>
            <div class="account-menu">
                <button id="account-menu-toggle" class="account-menu-toggle" type="button" aria-label="Account options"
                    aria-haspopup="true" aria-expanded="false" aria-controls="account-menu">
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="8" r="3.5"></circle>
                        <path d="M5.5 20c.5-3.5 2.8-5.5 6.5-5.5s6 2 6.5 5.5"></path>
                    </svg>
                </button>
                <div id="account-menu" class="account-menu-popover" hidden>
                    <button id="open-password-dialog" class="account-menu-item" type="button">Change password</button>
                    <form method="post">
                        <input type="hidden" name="action" value="logout">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <button class="account-menu-item sign-out-item" type="submit">Sign out</button>
                    </form>
                </div>
            </div>
        </header>
        <section class="panel upload-panel">
            <div class="section-heading">
                <div>
                    <h2>Upload files</h2>
                    <!-- <p>Files are saved in the <code>upload/</code> directory.</p> -->
                </div><span id="selection-count" class="muted">No files selected</span>
            </div>
            <div id="drop-zone" class="drop-zone">
                <div class="upload-icon" aria-hidden="true">↑</div><strong>Drop files here</strong><span>or</span><label
                    class="button primary" for="file-input">Choose files</label><input id="file-input" type="file"
                    multiple hidden><input id="upload-progress-field" type="hidden"
                    name="<?= htmlspecialchars($uploadProgressName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><small>New
                    selections are added to the queue.</small>
            </div>
            <div id="upload-list" class="upload-list" aria-live="polite"></div>
            <div class="upload-actions"><button id="upload-button" class="button primary" disabled>Upload queued
                    files</button><button id="clear-button" class="button secondary" disabled>Clear queue</button></div>
            <p id="upload-note" class="notice" role="status"></p>
        </section>
        <dialog id="password-dialog" class="password-dialog" <?= $passwordFeedback !== '' ? 'open' : '' ?>>
            <div class="dialog-heading">
                <div>
                    <h2>Change password</h2>
                    <p>Enter your current password and choose a new one.</p>
                </div>
                <button id="close-password-dialog" class="dialog-close" type="button" aria-label="Close dialog">&times;</button>
            </div>
            <?= $passwordFeedback ?>
            <form class="auth-form" method="post">
                <input type="hidden" name="action" value="change-password">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <label for="current-password">Current password</label>
                <input id="current-password" name="current_password" type="password" autocomplete="current-password" required>
                <label for="new-password">New password</label>
                <input id="new-password" name="new_password" type="password" autocomplete="new-password" minlength="8" maxlength="72" required>
                <label for="confirm-password">Confirm new password</label>
                <input id="confirm-password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" maxlength="72" required>
                <button class="button primary" type="submit">Change password</button>
            </form>
        </dialog>
        <section class="panel browser-panel">
            <div class="section-heading">
                <div>
                    <!-- <h2>Files and folders</h2> -->
                    <p>Root directory.</p>
                </div><button id="refresh-button" class="button secondary">Refresh</button>
            </div>
            <div class="breadcrumb-row"><span class="folder-symbol" aria-hidden="true">▣</span>
                <nav id="breadcrumbs" aria-label="Current folder"></nav>
            </div>
            <div id="file-error" class="error-message" hidden></div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Size</th>
                            <th>Modified</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="file-list">
                        <tr>
                            <td colspan="4" class="empty">Loading files…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <!-- <p class="footnote">Uploads always go to <code>upload/</code>. Identical files are skipped by SHA-256 checksum; different files with the same name are automatically renamed.</p> -->
        </section>
        <footer>Use on a trusted network only. Sign-in is required to browse, download, or upload files.</footer>
    </main>
</body>

</html>