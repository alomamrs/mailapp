<?php
ob_start(function($buf) {
    if (headers_sent()) return $buf;
    $clean = trim($buf);
    if ($clean !== '' && $clean[0] !== '{' && $clean[0] !== '[') {
        header('Content-Type: application/json');
        return json_encode(['error' => 'Server output error', 'detail' => substr($clean, 0, 300)]);
    }
    return $buf;
});
// Public API — no portal auth required.
// Only allows device_code and poll actions to add Microsoft accounts.

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_httponly', 1);
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth/Security.php';
require_once __DIR__ . '/auth/Auth.php';
require_once __DIR__ . '/auth/UserTokens.php';

header('Content-Type: application/json');

// Resolve via opaque invite code (?c=CODE) — no token or username in URL
$code = trim($_GET['c'] ?? ($_SESSION['invite_code'] ?? ''));
if (!$code) {
    http_response_code(403);
    echo json_encode(['error' => 'Missing invite code.']);
    exit;
}

$resolved = resolveInviteCode($code);
if (!$resolved) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid invite code.']);
    exit;
}

// Cache in session for subsequent poll calls (session shares same tab as invite.php)
$_SESSION['invite_code'] = $code;

// Portal user this account will be bound to — always from the code, never from URL
$portalUser = $resolved['portal_user'];
if ($portalUser === '__global__') {
    // Global invite code — bind to whoever is currently logged into the portal in this session
    $portalUser = $_SESSION['invite_portal_user'] ?? $_SESSION['portal_user'] ?? null;
    if (!$portalUser) {
        http_response_code(403);
        echo json_encode(['error' => 'Global invite requires an active portal session. Please log in first.']);
        exit;
    }
} else {
    $portalUser = $_SESSION['invite_portal_user'] ?? $resolved['portal_user'];
}

$action = $_GET['action'] ?? '';

try {
    if ($action === 'device_code') {
        $data = Auth::post(AUTH_URL . '/devicecode', [
            'client_id' => CLIENT_ID,
            'scope'     => SCOPE,
        ]);
        if (isset($data['error'])) {
            echo json_encode(['error' => $data['error_description'] ?? $data['error']]);
            exit;
        }
        $_SESSION['invite_device_code']     = $data['device_code'];
        $_SESSION['invite_device_code_exp'] = time() + ($data['expires_in'] ?? 900);
        session_write_close(); // flush session so poll can read it
        echo json_encode([
            'user_code'        => $data['user_code'],
            'verification_uri' => $data['verification_uri'],
            'expires_in'       => $data['expires_in'],
            'interval'         => $data['interval'] ?? 5,
        ]);
        exit;
    }

    if ($action === 'poll') {
        // Re-open session if it was closed after device_code action
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['invite_device_code'])) {
            echo json_encode(['status' => 'error', 'message' => 'No pending sign-in.']);
            exit;
        }
        if (time() > ($_SESSION['invite_device_code_exp'] ?? 0)) {
            unset($_SESSION['invite_device_code'], $_SESSION['invite_device_code_exp']);
            echo json_encode(['status' => 'expired', 'message' => 'Code expired. Please refresh.']);
            exit;
        }
        $data = Auth::post(AUTH_URL . '/token', [
            'grant_type'  => 'urn:ietf:params:oauth:grant-type:device_code',
            'client_id'   => CLIENT_ID,
            'device_code' => $_SESSION['invite_device_code'],
        ]);
        if (isset($data['access_token'])) {
            unset($_SESSION['invite_device_code'], $_SESSION['invite_device_code_exp']);
            // Fetch profile
            $ch = curl_init(GRAPH_URL . '/me?$select=id,displayName,mail,userPrincipalName');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$data['access_token']], CURLOPT_TIMEOUT=>10]);
            $profile = json_decode(curl_exec($ch), true) ?? [];
            curl_close($ch);

            $id    = $profile['id'] ?? uniqid('acc_');
            $email = $profile['mail'] ?? $profile['userPrincipalName'] ?? 'unknown@unknown';
            $name  = $profile['displayName'] ?? $email;

            // Save account via Auth
            $db = new PDO('sqlite:' . DB_FILE);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $colors = ['#e63022','#2563eb','#16a34a','#9333ea','#ea580c','#0891b2','#be185d','#ca8a04'];
            $count  = $db->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
            $existing = $db->prepare('SELECT color FROM accounts WHERE id=?');
            $existing->execute([$id]);
            $row   = $existing->fetch(PDO::FETCH_ASSOC);
            $color = $row ? $row['color'] : $colors[$count % count($colors)];

            $stmt = $db->prepare('
                INSERT INTO accounts (id,email,name,color,access_token,refresh_token,token_expires,last_seen,portal_user)
                VALUES (:id,:email,:name,:color,:at,:rt,:exp,:now,:pu)
                ON CONFLICT(id) DO UPDATE SET
                    name=excluded.name, email=excluded.email,
                    access_token=excluded.access_token,
                    refresh_token=COALESCE(excluded.refresh_token,refresh_token),
                    token_expires=excluded.token_expires,
                    last_seen=excluded.last_seen,
                    portal_user=COALESCE(portal_user, excluded.portal_user)
            ');
            // $portalUser already resolved from invite code at top of file
            $stmt->execute([
                ':id'    => $id, ':email' => $email, ':name'  => $name,
                ':color' => $color, ':at' => $data['access_token'],
                ':rt'    => $data['refresh_token'] ?? null,
                ':exp'   => time() + ($data['expires_in'] ?? 3600),
                ':now'   => time(),
                ':pu'    => $portalUser,
            ]);

            // Set the active account session key so the user sees the new account immediately
            $_SESSION['active_account_' . ($portalUser ?? '')] = $id;
            $_SESSION['active_account'] = $id; // legacy fallback

            echo json_encode(['status' => 'success', 'account' => compact('id','name','email','color')]);
            exit;
        }
        $error = $data['error'] ?? 'unknown';
        if ($error === 'authorization_pending') { echo json_encode(['status' => 'pending']); exit; }
        if ($error === 'authorization_declined') { echo json_encode(['status' => 'declined', 'message' => 'Login declined.']); exit; }
        if ($error === 'expired_token')          { echo json_encode(['status' => 'expired',  'message' => 'Code expired.']); exit; }
        echo json_encode(['status' => 'error', 'message' => $data['error_description'] ?? $error]);
        exit;
    }

    echo json_encode(['error' => 'Unknown action.']);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
