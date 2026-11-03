<?php
declare(strict_types=1);

// Abmelden braucht keine Datenbank. So funktioniert es auch dann zuverlässig,
// wenn die Datenbank gerade nicht erreichbar ist.
require_once __DIR__ . '/session.php';
atlas_start_session();
$guestSession=($_SESSION['auth_method']??'')==='guest';
$_SESSION=[];
if (ini_get('session.use_cookies')) {
  $params=session_get_cookie_params();
  setcookie(session_name(),'',time()-42000,$params['path']??'/',$params['domain']??'',(bool)($params['secure']??true),(bool)($params['httponly']??true));
}
session_destroy();
header('Location: ../'.($guestSession?'gastzugang.html':'login.html'),true,303);
exit;
