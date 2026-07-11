<?php
/**
 * _effectifs_engine.php — membres de la faction (grade RP + grade(s) académie).
 */
if (!isset($__ACADEMY_FACTION) || !academy_faction_exists($__ACADEMY_FACTION)) { http_response_code(404); exit('Faction invalide'); }
$__fk = $__ACADEMY_FACTION;
$__meta = academy_faction_meta($__fk);
$__slug = $__meta['slug'];
academy_require_access('effectifs_' . $__slug);

$pageTitle  = 'Effectifs — ' . $__meta['name'];
$breadcrumb = [['label' => $__meta['name']], ['label' => 'Effectifs']];
$db = Database::getInstance()->getConnection();

try {
    $s = $db->prepare("
        SELECT u.id, u.username, u.avatar, u.faction, u.faction_rank, u.faction_grade,
               GROUP_CONCAT(DISTINCT g.name  ORDER BY g.sort_order SEPARATOR '||') AS academy_grades,
               GROUP_CONCAT(DISTINCT g.color ORDER BY g.sort_order SEPARATOR '||') AS academy_colors
        FROM users u
        LEFT JOIN academy_user_grades aug ON aug.user_id = u.id
        LEFT JOIN academy_grades g ON g.id = aug.grade_id AND (g.faction_key = ? OR g.faction_key IS NULL)
        WHERE u.faction = ?
        GROUP BY u.id
        ORDER BY u.faction_rank IS NULL, u.faction_rank, u.username
    ");
    $s->execute([$__fk, $__fk]);
    $members = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $members = []; }

$withAcademy = 0;
foreach ($members as $m) if (!empty($m['academy_grades'])) $withAcademy++;
$total = count($members);

[$ar,$ag,$ab] = sscanf($__meta['color'], "#%02x%02x%02x");
$facCss = "--fac-c:{$__meta['color']};--fac-c-15:rgba($ar,$ag,$ab,.10);--fac-c-25:rgba($ar,$ag,$ab,.25);";

include __DIR__ . '/header.php';
?>

<style><?php echo $facCss; ?></style>

<div class="page-header">
  <div class="page-header-left">
    <div class="ph-eyebrow"><i class="fa-solid fa-<?php echo $__meta['icon']; ?>"></i> <?php echo htmlspecialchars($__meta['name']); ?></div>
    <h1><span class="ph-ic" style="background:var(--fac-c-15);color:var(--fac-c)"><i class="fa-solid fa-users"></i></span> Effectifs</h1>
    <p>Annuaire des membres de la faction avec leur grade RP et leurs grades académie.</p>
  </div>
</div>

<div class="fac-strip">
  <div class="fs-ic"><i class="fa-solid fa-<?php echo $__meta['icon']; ?>"></i></div>
  <div class="fs-info">
    <div class="fs-name"><?php echo htmlspecialchars($__meta['name']); ?></div>
    <div class="fs-sub"><?php echo htmlspecialchars($__meta['subtitle'] ?? ''); ?></div>
  </div>
</div>

<div class="stats-grid">
  <div class="stat-card stat-blue"><div class="stat-ic"><i class="fa-solid fa-users"></i></div><div class="stat-info">
    <div class="stat-label">Membres</div>
    <div class="stat-value"><?php echo $total; ?></div>
    <div class="stat-meta">total faction</div>
  </div></div>
  <div class="stat-card stat-cyan"><div class="stat-ic"><i class="fa-solid fa-graduation-cap"></i></div><div class="stat-info">
    <div class="stat-label">Avec grade académie</div>
    <div class="stat-value"><?php echo $withAcademy; ?></div>
    <div class="stat-meta"><?php echo $total > 0 ? round($withAcademy * 100 / $total) : 0; ?>% des effectifs</div>
  </div></div>
  <div class="stat-card stat-green"><div class="stat-ic"><i class="fa-solid fa-user-graduate"></i></div><div class="stat-info">
    <div class="stat-label">Sans grade académie</div>
    <div class="stat-value"><?php echo $total - $withAcademy; ?></div>
    <div class="stat-meta">à former</div>
  </div></div>
</div>

<div class="toolbar">
  <div class="grow search-input">
    <i class="fa-solid fa-search"></i>
    <input type="text" class="form-control" id="effSearch" placeholder="Rechercher un membre, grade…">
  </div>
  <div class="seg" id="effFilter">
    <button class="on" data-f="all">Tous</button>
    <button data-f="academy">Avec grade académie</button>
    <button data-f="none">Sans</button>
  </div>
</div>

<div class="card">
  <?php if (empty($members)): ?>
  <div class="empty-state">
    <i class="fa-solid fa-users-slash"></i>
    <div class="es-title">Aucun membre dans cette faction</div>
    <p>Les membres apparaîtront ici dès qu'ils seront affectés à <?php echo htmlspecialchars($__meta['name']); ?>.</p>
  </div>
  <?php else: ?>
  <div class="table-wrapper">
    <table>
      <thead><tr><th>Membre</th><th>Grade RP</th><th>Grade(s) académie</th></tr></thead>
      <tbody id="effTbody">
      <?php foreach ($members as $m):
        $names  = $m['academy_grades'] ? explode('||', $m['academy_grades']) : [];
        $colors = $m['academy_colors'] ? explode('||', $m['academy_colors']) : [];
        $hasAcademy = !empty($names);
        $haystack = strtolower(($m['username'] ?? '') . ' ' . ($m['faction_rank'] ?? '') . ' ' . implode(' ', $names));
        $initial  = strtoupper(mb_substr($m['username'] ?? 'U', 0, 1));
      ?>
      <tr data-search="<?php echo htmlspecialchars($haystack); ?>" data-state="<?php echo $hasAcademy ? 'academy' : 'none'; ?>">
        <td>
          <div class="usr">
            <div class="usr-ava" style="background:linear-gradient(135deg,var(--fac-c),#6366f1)">
              <?php if (!empty($m['avatar'])): ?>
                <img src="<?php echo htmlspecialchars($m['avatar']); ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:7px">
              <?php else: ?>
                <?php echo $initial; ?>
              <?php endif; ?>
            </div>
            <div>
              <div class="row-strong"><?php echo htmlspecialchars($m['username'] ?? '—'); ?></div>
              <div class="row-muted">#<?php echo (int)$m['id']; ?></div>
            </div>
          </div>
        </td>
        <td>
          <?php if (!empty($m['faction_rank'])): ?>
            <span class="badge" style="background:var(--fac-c-15);color:var(--fac-c)"><?php echo htmlspecialchars($m['faction_rank']); ?></span>
          <?php else: ?>
            <span class="row-muted">—</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if (!$hasAcademy): ?>
            <span class="row-muted">Aucun</span>
          <?php else:
            foreach ($names as $i => $n):
              $c = $colors[$i] ?? '#64748b';
          ?>
          <span class="assign-badge" style="--gr-c:<?php echo $c; ?>;--gr-bg:<?php echo $c; ?>22;margin-right:4px;margin-bottom:3px">
            <i class="fa-solid fa-graduation-cap"></i> <?php echo htmlspecialchars($n); ?>
          </span>
          <?php endforeach; endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<script>
const effS = document.getElementById('effSearch');
const effF = document.getElementById('effFilter');
const effT = document.getElementById('effTbody');
let effFilter = 'all';
function effApply(){
  if (!effT) return;
  const q = (effS?.value || '').trim().toLowerCase();
  effT.querySelectorAll('tr').forEach(tr => {
    const okQ = !q || tr.dataset.search.includes(q);
    const okF = effFilter === 'all' || tr.dataset.state === effFilter;
    tr.style.display = (okQ && okF) ? '' : 'none';
  });
}
if (effS) effS.addEventListener('input', effApply);
if (effF) effF.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
  effF.querySelectorAll('button').forEach(x => x.classList.remove('on'));
  b.classList.add('on');
  effFilter = b.dataset.f;
  effApply();
}));
</script>

<?php include __DIR__ . '/footer.php'; ?>
