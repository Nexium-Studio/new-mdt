<?php
require_once __DIR__ . '/../../config/config.php';
academy_require_public();
$pageTitle = 'Académie — Services publics';
$db = Database::getInstance()->getConnection();
$uid = (int)($_SESSION['user_id'] ?? 0);

$factions = academy_factions();
$myCands = [];
$myQCMs  = [];
try {
    $s = $db->prepare('SELECT faction_key, status, created_at FROM academy_candidatures WHERE user_id = ? ORDER BY created_at DESC');
    $s->execute([$uid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!isset($myCands[$r['faction_key']])) $myCands[$r['faction_key']] = $r;
    }
    $s = $db->prepare('SELECT faction_key, MAX(percentage) AS best, COUNT(*) AS nb FROM academy_qcm_attempts WHERE user_id = ? GROUP BY faction_key');
    $s->execute([$uid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $myQCMs[$r['faction_key']] = $r;
    }
} catch (Exception $e) {}

include __DIR__ . '/includes/header.php';
?>

<style>
.acd-hero{position:relative;border-radius:22px;overflow:hidden;padding:2.4rem 2.2rem 2.2rem;margin-bottom:2.2rem;background:linear-gradient(135deg,rgba(139,92,246,.18),rgba(99,102,241,.06)),radial-gradient(ellipse at top right,rgba(168,85,247,.18),transparent 60%);border:1px solid rgba(139,92,246,.2)}
.acd-hero::before{content:"";position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,#a855f7,transparent)}
.acd-hero-flex{display:flex;align-items:flex-end;justify-content:space-between;gap:1.4rem;flex-wrap:wrap}
.acd-hero h1{font-family:'Syne',sans-serif;font-size:clamp(1.75rem,4vw,2.5rem);font-weight:800;color:#fff;margin:0 0 .4rem;line-height:1.05}
.acd-hero p{color:var(--muted-2);max-width:620px;font-size:.92rem;line-height:1.55;margin:0}
.acd-eyebrow{display:inline-flex;align-items:center;gap:.5rem;padding:.35rem .85rem;background:rgba(168,85,247,.15);border:1px solid rgba(168,85,247,.35);border-radius:50px;font-size:.7rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#c4b5fd;margin-bottom:.85rem}

.acd-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:1.05rem}
.acd-card{position:relative;background:linear-gradient(160deg,var(--bg-card) 0%,rgba(0,0,0,.18) 100%);border:1px solid var(--border);border-radius:18px;overflow:hidden;text-decoration:none;color:inherit;transition:transform .35s cubic-bezier(.4,0,.2,1),box-shadow .35s,border-color .35s;display:flex;flex-direction:column;cursor:pointer;isolation:isolate}
.acd-card::before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 50% -20%,var(--card-acc,#8b5cf6) 0%,transparent 60%);opacity:.12;transition:opacity .35s;pointer-events:none}
.acd-card::after{content:"";position:absolute;top:0;left:0;right:0;height:2px;background:var(--card-acc,#8b5cf6);transform:scaleX(0);transform-origin:left;transition:transform .4s ease}
.acd-card:hover{transform:translateY(-5px);border-color:var(--card-acc,#8b5cf6);box-shadow:0 25px 50px -20px rgba(0,0,0,.5),0 0 0 1px var(--card-acc,#8b5cf6)}
.acd-card:hover::before{opacity:.2}
.acd-card:hover::after{transform:scaleX(1)}

.acd-card-top{padding:1.5rem 1.5rem 1.1rem;display:flex;align-items:center;gap:1rem;position:relative;z-index:1}
.acd-ic{width:54px;height:54px;border-radius:14px;display:grid;place-items:center;color:#fff;font-size:1.4rem;background:var(--card-acc,#8b5cf6);box-shadow:0 8px 20px -6px rgba(0,0,0,.45);flex-shrink:0;transition:transform .35s}
.acd-card:hover .acd-ic{transform:rotate(-6deg) scale(1.05)}
.acd-name{font-family:'Syne',sans-serif;font-size:1.1rem;font-weight:700;color:#fff;line-height:1.15;margin-bottom:.15rem}
.acd-sub{font-size:.78rem;color:var(--muted-2);line-height:1.35}

.acd-card-body{padding:0 1.5rem 1.1rem;flex:1;display:flex;align-items:center;min-height:54px;gap:.4rem;flex-wrap:wrap;position:relative;z-index:1}
.acd-st{display:inline-flex;align-items:center;gap:.4rem;padding:.32rem .75rem;border-radius:50px;font-size:.72rem;font-weight:700}
.acd-st.muted{background:rgba(255,255,255,.05);color:var(--muted-2);border:1px dashed rgba(255,255,255,.12)}

.acd-card-foot{padding:.95rem 1.5rem;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:.5rem;background:rgba(0,0,0,.18);position:relative;z-index:1}
.acd-foot-cta{font-size:.82rem;font-weight:700;color:var(--card-acc,#8b5cf6);display:inline-flex;align-items:center;gap:.4rem;transition:gap .25s}
.acd-card:hover .acd-foot-cta{gap:.7rem}
.acd-foot-meta{font-size:.7rem;color:var(--muted-2);display:inline-flex;align-items:center;gap:.4rem}

@media(max-width:640px){
  .acd-hero{padding:1.6rem 1.4rem}
  .acd-grid{grid-template-columns:1fr}
}
</style>

<div class="acd-hero fade-in">
  <div class="acd-hero-flex">
    <div>
      <div class="acd-eyebrow"><i class="fa-solid fa-graduation-cap"></i> Académie Publique</div>
      <h1>Choisissez votre service</h1>
      <p>Découvrez chaque service public de Los Santos, déposez votre candidature et passez votre QCM pour rejoindre la faction qui vous correspond.</p>
    </div>
    <a href="<?php echo SITE_URL; ?>/pages/espace-perso.php" class="ep-btn ep-btn-outline">
      <i class="fa-solid fa-user"></i> Mes démarches
    </a>
  </div>
</div>

<div class="acd-grid">
<?php foreach ($factions as $fk => $m):
    $cand = $myCands[$fk] ?? null;
    $qcm  = $myQCMs[$fk]  ?? null;
    $st   = ($cand && isset(academy_candidature_statuses()[$cand['status']]))
              ? academy_candidature_statuses()[$cand['status']] : null;
?>
  <a href="<?php echo SITE_URL . '/pages/academy/' . htmlspecialchars($m['slug']) . '.php'; ?>"
     class="acd-card" style="--card-acc:<?php echo $m['color']; ?>">
    <div class="acd-card-top">
      <div class="acd-ic"><i class="fa-solid fa-<?php echo htmlspecialchars($m['icon']); ?>"></i></div>
      <div>
        <div class="acd-name"><?php echo htmlspecialchars($m['name']); ?></div>
        <div class="acd-sub"><?php echo htmlspecialchars($m['subtitle']); ?></div>
      </div>
    </div>
    <div class="acd-card-body">
      <?php if ($st): ?>
        <span class="acd-st" style="background:<?php echo $st['bg']; ?>;color:<?php echo $st['color']; ?>">
          <i class="fa-solid fa-<?php echo $st['icon']; ?>"></i> <?php echo $st['label']; ?>
        </span>
      <?php else: ?>
        <span class="acd-st muted"><i class="fa-solid fa-circle-dot"></i> Aucune candidature</span>
      <?php endif; ?>
      <?php if ($qcm): ?>
        <span class="acd-st" style="background:rgba(34,197,94,.12);color:#86efac">
          <i class="fa-solid fa-clipboard-check"></i> QCM : <?php echo (int)$qcm['best']; ?>%
        </span>
      <?php endif; ?>
    </div>
    <div class="acd-card-foot">
      <span class="acd-foot-cta">Découvrir le service <i class="fa-solid fa-arrow-right"></i></span>
      <span class="acd-foot-meta"><i class="fa-solid fa-circle-info"></i> Présentation &amp; candidature</span>
    </div>
  </a>
<?php endforeach; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
