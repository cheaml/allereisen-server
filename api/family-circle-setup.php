<?php
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo 'Die zusätzliche OneDrive-Familienfreigabe wurde entfernt. Website-Reiseberechtigungen und bestehende OneDrive-Rechte bleiben bestehen.';
