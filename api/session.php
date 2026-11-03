<?php
declare(strict_types=1);

// Keep both the cookie and its server-side data across browser/app restarts.
// An isolated directory prevents other PHP apps' shorter GC settings deleting it.
const ATLAS_SESSION_LIFETIME = 30 * 24 * 60 * 60;
function atlas_start_session(): void {
    $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'atlas-sessions-' . hash('sha256', __DIR__);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        http_response_code(503);
        exit('Die Anmeldung ist vorübergehend nicht verfügbar.');
    }
    ini_set('session.save_handler', 'files');
    session_save_path($directory);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) ATLAS_SESSION_LIFETIME);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    session_name('atlas_session');
    session_set_cookie_params([
        'lifetime' => ATLAS_SESSION_LIFETIME,
        'httponly' => true, 'secure' => true, 'samesite' => 'Lax', 'path' => '/',
    ]);
    if (!session_start()) {
        http_response_code(503);
        exit('Die Anmeldung ist vorübergehend nicht verfügbar.');
    }
    // Enforce expiry even if PHP's probabilistic garbage collection has not run.
    if (isset($_SESSION['user_id']) && (int) ($_SESSION['login_expires_at'] ?? 0) <= time()) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
}
