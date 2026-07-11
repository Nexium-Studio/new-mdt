<?php
/**
 * DarkNet — Encrypted Chat (polling).
 */
require_once __DIR__ . '/_bootstrap.php';

$pageTitle = 'Encrypted Chat';
$success = ''; $error = '';

// Assure l'existence des salons par défaut
try {
    $exists = (int) $db->query("SELECT COUNT(*) FROM darknet_chat_rooms")->fetchColumn();
    if ($exists === 0) {
        $db->exec("INSERT INTO darknet_chat_rooms (name, scope) VALUES ('#global-darknet', 'public'), ('#alerts', 'public'), ('#trades', 'public')");
    }
    // Salon faction automatique
    $st = $db->prepare("SELECT id FROM darknet_chat_rooms WHERE scope = 'faction' AND faction_key = ?");
    $st->execute([$dnFaction]);
    if (!$st->fetchColumn()) {
        $stF = $db->prepare("SELECT name FROM factions WHERE faction_key = ?");
        $stF->execute([$dnFaction]);
        $factionName = (string) $stF->fetchColumn() ?: $dnFaction;
        $db->prepare("INSERT INTO darknet_chat_rooms (name, scope, faction_key) VALUES (?, 'faction', ?)")
           ->execute(['#' . strtolower(str_replace(' ', '-', $factionName)), $dnFaction]);
    }
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!darknet_verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'Session expirée.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'send') {
                $roomId = (int) ($_POST['room_id'] ?? 0);
                $content = trim((string) ($_POST['content'] ?? ''));
                if ($roomId < 1 || $content === '') { throw new RuntimeException('Message invalide.'); }
                // Vérifier accès au salon
                $rs = $db->prepare("SELECT scope, faction_key FROM darknet_chat_rooms WHERE id = ?");
                $rs->execute([$roomId]);
                $room = $rs->fetch(PDO::FETCH_ASSOC);
                if (!$room) { throw new RuntimeException('Salon introuvable.'); }
                if ($room['scope'] === 'faction' && $room['faction_key'] !== $dnFaction && !$dnIsStaff) {
                    throw new RuntimeException('Salon privé à une autre faction.');
                }
                $db->prepare("INSERT INTO darknet_chat_messages (room_id, user_id, content) VALUES (?, ?, ?)")
                   ->execute([$roomId, $dnUserId, mb_substr($content, 0, 2000)]);
            } elseif ($action === 'create_room' && $dnIsStaff) {
                $name = trim((string) ($_POST['name'] ?? ''));
                if ($name === '') { throw new RuntimeException('Nom requis.'); }
                $db->prepare("INSERT INTO darknet_chat_rooms (name, scope, created_by) VALUES (?, 'public', ?)")
                   ->execute([$name, $dnUserId]);
                $success = 'Salon créé.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        if ($action === 'send') {
            // Pour éviter le repost on redirige
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
        if ($success !== '') {
            $_SESSION['darknet_csrf'] = bin2hex(random_bytes(16));
            $dnCsrf = $_SESSION['darknet_csrf'];
        }
    }
}

// Endpoint AJAX : polling messages
if (isset($_GET['poll'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $roomId = (int) ($_GET['room'] ?? 0);
    $since  = (int) ($_GET['since'] ?? 0);
    try {
        // Vérifier accès
        $rs = $db->prepare("SELECT scope, faction_key FROM darknet_chat_rooms WHERE id = ?");
        $rs->execute([$roomId]);
        $room = $rs->fetch(PDO::FETCH_ASSOC);
        if (!$room || ($room['scope'] === 'faction' && $room['faction_key'] !== $dnFaction && !$dnIsStaff)) {
            echo json_encode(['messages' => [], 'last' => $since]);
            exit;
        }
        $st = $db->prepare("SELECT m.id, m.content, m.user_id, m.created_at, p.firstname, p.lastname, u.username
                            FROM darknet_chat_messages m
                            LEFT JOIN users u ON u.id = m.user_id
                            LEFT JOIN profiles p ON p.id = u.profile_id
                            WHERE m.room_id = ? AND m.id > ? ORDER BY m.id ASC LIMIT 80");
        $st->execute([$roomId, $since]);
        $msgs = $st->fetchAll(PDO::FETCH_ASSOC);
        $out = array_map(static function ($m) {
            $name = trim(($m['firstname'] ?? '') . ' ' . ($m['lastname'] ?? ''));
            if ($name === '') $name = $m['username'] ?? '—';
            return [
                'id' => (int) $m['id'],
                'user_id' => (int) $m['user_id'],
                'name' => $name,
                'content' => (string) $m['content'],
                'time' => date('H:i:s', strtotime($m['created_at'])),
            ];
        }, $msgs);
        $last = $msgs ? (int) end($msgs)['id'] : $since;
        echo json_encode(['messages' => $out, 'last' => $last]);
    } catch (Throwable $e) {
        echo json_encode(['messages' => [], 'last' => $since, 'error' => $e->getMessage()]);
    }
    exit;
}

// Salons accessibles
$rooms = $db->prepare("SELECT * FROM darknet_chat_rooms WHERE scope = 'public' OR (scope = 'faction' AND (faction_key = ? OR ?)) ORDER BY scope ASC, id ASC");
$rooms->execute([$dnFaction, $dnIsStaff ? 1 : 0]);
$rooms = $rooms->fetchAll(PDO::FETCH_ASSOC);

$currentRoomId = (int) ($_GET['r'] ?? 0);
if ($currentRoomId < 1 && $rooms) { $currentRoomId = (int) $rooms[0]['id']; }

$initialMsgs = [];
$lastId = 0;
if ($currentRoomId > 0) {
    $st = $db->prepare("SELECT m.id, m.content, m.user_id, m.created_at, p.firstname, p.lastname, u.username FROM darknet_chat_messages m LEFT JOIN users u ON u.id = m.user_id LEFT JOIN profiles p ON p.id = u.profile_id WHERE m.room_id = ? ORDER BY m.id DESC LIMIT 80");
    $st->execute([$currentRoomId]);
    $initialMsgs = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
    if ($initialMsgs) { $lastId = (int) end($initialMsgs)['id']; }
}

include __DIR__ . '/../includes/header.php';
?>
<style>
.chat-app{display:grid;grid-template-columns:260px 1fr;gap:20px;height:calc(100vh - 120px)}
@media(max-width:900px){.chat-app{grid-template-columns:1fr;height:auto}}

.chat-rooms{background:rgba(13,17,33,.85);border:1px solid rgba(148,163,184,.1);border-radius:12px;padding:14px 12px;overflow-y:auto}
.chat-rooms h3{font-family:'Share Tech Mono',monospace;font-size:.62rem;color:#94a3b8;letter-spacing:2px;text-transform:uppercase;margin-bottom:10px;padding:0 8px}
.chat-room{display:flex;align-items:center;gap:9px;padding:9px 12px;border-radius:8px;color:#94a3b8;text-decoration:none;font-size:.84rem;font-family:'JetBrains Mono',monospace;margin-bottom:3px;transition:all .15s}
.chat-room:hover{background:rgba(255,255,255,.04);color:#e2e8f0}
.chat-room.active{background:rgba(167,139,250,.15);color:#c4b5fd;border-left:3px solid #a78bfa;padding-left:9px}
.chat-room .sc{font-family:'Share Tech Mono',monospace;font-size:.55rem;padding:2px 5px;border-radius:3px;letter-spacing:1px;text-transform:uppercase;margin-left:auto}
.chat-room .sc.f{background:rgba(251,191,36,.18);color:#fbbf24}
.chat-room .sc.p{background:rgba(34,211,238,.15);color:#67e8f9}

.chat-main{background:rgba(13,17,33,.85);border:1px solid rgba(148,163,184,.1);border-radius:12px;display:flex;flex-direction:column;overflow:hidden}
.chat-head{padding:14px 20px;border-bottom:1px solid rgba(148,163,184,.1);display:flex;align-items:center;gap:12px;flex-shrink:0}
.chat-head .rname{font-family:'Syne',sans-serif;color:#fff;font-size:1rem;letter-spacing:1px}
.chat-head .stat{margin-left:auto;display:flex;align-items:center;gap:6px;font-family:'Share Tech Mono',monospace;font-size:.6rem;color:#10b981;letter-spacing:1.5px;text-transform:uppercase}
.chat-head .stat .d{width:8px;height:8px;background:#10b981;border-radius:50%;animation:pulse-d 1.5s infinite}
@keyframes pulse-d{0%,100%{opacity:1}50%{opacity:.4}}

.chat-body{flex:1;overflow-y:auto;padding:16px 22px;display:flex;flex-direction:column;gap:10px}
.chat-msg{display:flex;gap:10px;align-items:flex-start;max-width:80%}
.chat-msg.me{margin-left:auto;flex-direction:row-reverse}
.chat-msg .av{width:32px;height:32px;border-radius:9px;background:linear-gradient(135deg,#7c3aed,#22d3ee);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.85rem;flex-shrink:0}
.chat-msg.me .av{background:linear-gradient(135deg,#f472b6,#a78bfa)}
.chat-msg .bub{background:rgba(30,41,59,.7);border:1px solid rgba(148,163,184,.15);padding:8px 12px;border-radius:10px;border-top-left-radius:3px}
.chat-msg.me .bub{background:rgba(124,58,237,.2);border-color:rgba(167,139,250,.35);border-top-left-radius:10px;border-top-right-radius:3px}
.chat-msg .nm{font-family:'Share Tech Mono',monospace;font-size:.62rem;color:#a78bfa;letter-spacing:1px;margin-bottom:3px}
.chat-msg.me .nm{color:#f472b6;text-align:right}
.chat-msg .ct{color:#e2e8f0;font-size:.85rem;line-height:1.45;word-wrap:break-word;white-space:pre-wrap}
.chat-msg .tm{font-family:'Share Tech Mono',monospace;font-size:.58rem;color:#64748b;margin-top:3px;letter-spacing:1px}

.chat-form{padding:14px 18px;border-top:1px solid rgba(148,163,184,.1);display:flex;gap:10px;flex-shrink:0;background:rgba(3,5,15,.4)}
.chat-form input[type=text]{flex:1;background:rgba(2,4,12,.8);border:1px solid rgba(148,163,184,.2);color:#e2e8f0;padding:11px 14px;border-radius:10px;font-size:.88rem;font-family:'DM Sans',sans-serif}
.chat-form input[type=text]:focus{outline:none;border-color:#a78bfa;box-shadow:0 0 0 3px rgba(167,139,250,.15)}
.chat-form button{padding:11px 18px;background:linear-gradient(135deg,#7c3aed,#22d3ee);border:none;border-radius:10px;color:#fff;cursor:pointer;font-family:'Syne',sans-serif;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;font-size:.72rem}

.chat-admin{padding:10px 18px;border-top:1px solid rgba(148,163,184,.1);display:flex;gap:8px;background:rgba(3,5,15,.4)}
.chat-admin input{flex:1;background:rgba(2,4,12,.8);border:1px solid rgba(148,163,184,.2);color:#e2e8f0;padding:7px 11px;border-radius:7px;font-size:.78rem}
.chat-admin button{padding:7px 14px;background:rgba(167,139,250,.2);border:1px solid rgba(167,139,250,.4);border-radius:7px;color:#c4b5fd;cursor:pointer;font-family:'Share Tech Mono',monospace;font-size:.68rem;letter-spacing:1px;text-transform:uppercase}

.chat-alert{padding:8px 14px;margin:10px 18px;border-radius:8px;font-size:.78rem}
.chat-alert.ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#86efac}
.chat-alert.ko{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
</style>

<div class="chat-app">
    <aside class="chat-rooms">
        <h3>Salons</h3>
        <?php foreach ($rooms as $r):
            $active = (int) $r['id'] === $currentRoomId;
            $isFac = $r['scope'] === 'faction';
        ?>
            <a href="?r=<?= (int) $r['id']; ?>" class="chat-room <?= $active ? 'active' : ''; ?>">
                <i class="fa-solid fa-<?= $isFac ? 'lock' : 'hashtag'; ?>"></i>
                <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($r['name']); ?></span>
                <span class="sc <?= $isFac ? 'f' : 'p'; ?>"><?= htmlspecialchars($r['scope']); ?></span>
            </a>
        <?php endforeach; ?>
    </aside>

    <main class="chat-main">
        <?php $cr = array_values(array_filter($rooms, static fn($r) => (int) $r['id'] === $currentRoomId))[0] ?? null; ?>
        <div class="chat-head">
            <i class="fa-solid fa-<?= ($cr['scope'] ?? '') === 'faction' ? 'lock' : 'hashtag'; ?>" style="color:#a78bfa"></i>
            <div class="rname"><?= htmlspecialchars($cr['name'] ?? 'Aucun salon'); ?></div>
            <div class="stat"><span class="d"></span> live · polling 3s</div>
        </div>

        <?php if ($error): ?><div class="chat-alert ko"><?= htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="chat-alert ok"><?= htmlspecialchars($success); ?></div><?php endif; ?>

        <div class="chat-body" id="chatBody">
            <?php if (!$initialMsgs): ?>
                <div style="text-align:center;color:#64748b;padding:40px 0">Aucun message pour l'instant. Dis bonjour.</div>
            <?php else: foreach ($initialMsgs as $m):
                $name = trim(($m['firstname'] ?? '') . ' ' . ($m['lastname'] ?? ''));
                if ($name === '') $name = $m['username'] ?? '—';
                $me = (int) $m['user_id'] === $dnUserId;
                $init = strtoupper(mb_substr($name, 0, 1));
            ?>
                <div class="chat-msg <?= $me ? 'me' : ''; ?>">
                    <div class="av"><?= htmlspecialchars($init); ?></div>
                    <div class="bub">
                        <div class="nm"><?= htmlspecialchars($name); ?></div>
                        <div class="ct"><?= htmlspecialchars($m['content']); ?></div>
                        <div class="tm"><?= htmlspecialchars(date('H:i:s', strtotime($m['created_at']))); ?></div>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <?php if ($currentRoomId > 0): ?>
        <form method="post" class="chat-form" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
            <input type="hidden" name="action" value="send">
            <input type="hidden" name="room_id" value="<?= $currentRoomId; ?>">
            <input type="text" name="content" placeholder="Message crypté…" maxlength="2000" required autofocus>
            <button type="submit"><i class="fa-solid fa-paper-plane"></i></button>
        </form>
        <?php endif; ?>

        <?php if ($dnIsStaff): ?>
        <form method="post" class="chat-admin">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
            <input type="hidden" name="action" value="create_room">
            <input type="text" name="name" placeholder="#nouveau-salon" required>
            <button type="submit">[Staff] Créer salon</button>
        </form>
        <?php endif; ?>
    </main>
</div>

<script>
(function(){
    const roomId = <?= (int) $currentRoomId; ?>;
    const meId = <?= (int) $dnUserId; ?>;
    let lastId = <?= (int) $lastId; ?>;
    const body = document.getElementById('chatBody');
    if (!body || roomId < 1) return;
    body.scrollTop = body.scrollHeight;
    function esc(s){const d=document.createElement('div');d.textContent=s;return d.innerHTML;}
    async function poll(){
        try{
            const r = await fetch('?poll=1&room=' + roomId + '&since=' + lastId, {credentials:'same-origin'});
            const j = await r.json();
            if (j.messages && j.messages.length){
                const shouldScroll = (body.scrollTop + body.clientHeight >= body.scrollHeight - 60);
                j.messages.forEach(m => {
                    lastId = Math.max(lastId, m.id);
                    const me = m.user_id === meId;
                    const el = document.createElement('div');
                    el.className = 'chat-msg' + (me ? ' me' : '');
                    el.innerHTML = '<div class="av">' + esc((m.name||'?').charAt(0).toUpperCase()) + '</div>' +
                                   '<div class="bub"><div class="nm">' + esc(m.name) + '</div>' +
                                   '<div class="ct">' + esc(m.content) + '</div>' +
                                   '<div class="tm">' + esc(m.time) + '</div></div>';
                    body.appendChild(el);
                });
                if (shouldScroll) body.scrollTop = body.scrollHeight;
            }
        } catch(e){ console.warn('poll', e); }
    }
    setInterval(poll, 3000);
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
