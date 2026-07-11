<?php
/**
 * _faction_homepage_engine.php
 * Page d'accueil immersive d'une faction Académie.
 * Sections : héro + slider, présentation, annonces, FAQ, CTA candidature/QCM.
 *
 * Variable attendue : $__ACADEMY_FACTION (ex: 'LSPD').
 */
if (!isset($__ACADEMY_FACTION) || !academy_faction_exists($__ACADEMY_FACTION)) {
    http_response_code(404);
    exit('Faction invalide');
}
academy_require_public();

$__fk    = $__ACADEMY_FACTION;
$__meta  = academy_faction_meta($__fk);
$__slug  = $__meta['slug'];
$pageTitle = $__meta['name'] . ' — Académie';

$db   = Database::getInstance()->getConnection();
$uid  = (int)$_SESSION['user_id'];

$cfg          = academy_homepage_config($__fk);
$accent       = !empty($cfg['accent_color']) ? $cfg['accent_color'] : $__meta['color'];
$images       = academy_homepage_images($__fk, true);
$faq          = academy_homepage_faq($__fk, true);
$announcements= academy_homepage_announcements($__fk, true);
$kinds        = academy_announcement_kinds();

$myCand = academy_user_latest_candidature($uid, $__fk);
$canTakeQcm = academy_user_can_take_qcm($uid, $__fk);
$candStatuses = academy_candidature_statuses();
$st = $myCand ? ($candStatuses[$myCand['status']] ?? null) : null;

// Helper : luminance simple pour overlay texte
function __ac_hex2rgb(string $hex): array {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
}
[$ar, $ag, $ab] = __ac_hex2rgb($accent);

include __DIR__ . '/header.php';
?>

<style>
:root{
  --acc:<?php echo $accent; ?>;
  --acc-rgb:<?php echo "$ar,$ag,$ab"; ?>;
}
.fhp-hero{position:relative;border-radius:24px;overflow:hidden;background:#06080f;min-height:480px;display:flex;align-items:flex-end;margin-bottom:2.5rem;box-shadow:0 30px 80px -30px rgba(0,0,0,.6),0 0 0 1px rgba(255,255,255,.05)}
.fhp-slider{position:absolute;inset:0;z-index:0}
.fhp-slide{position:absolute;inset:0;background-size:cover;background-position:center;opacity:0;transition:opacity 1.2s ease,transform 8s ease}
.fhp-slide.on{opacity:1;transform:scale(1.04)}
.fhp-overlay{position:absolute;inset:0;z-index:1;background:linear-gradient(180deg,rgba(3,7,15,0) 0%,rgba(3,7,15,.55) 55%,rgba(3,7,15,.95) 100%)}
.fhp-overlay::after{content:"";position:absolute;inset:0;background:radial-gradient(ellipse 70% 50% at 30% 100%,rgba(var(--acc-rgb),.25) 0%,transparent 60%)}
.fhp-hero-empty{background:linear-gradient(135deg,rgba(var(--acc-rgb),.18),rgba(var(--acc-rgb),.05)),radial-gradient(ellipse at top right,rgba(var(--acc-rgb),.15),transparent 60%)}
.fhp-hero-content{position:relative;z-index:2;padding:3rem 3rem 2.6rem;width:100%;display:flex;flex-direction:column;gap:1.1rem}
.fhp-back{align-self:flex-start;display:inline-flex;align-items:center;gap:.45rem;color:rgba(255,255,255,.75);font-size:.78rem;font-weight:600;padding:.4rem .85rem;border:1px solid rgba(255,255,255,.12);border-radius:50px;background:rgba(255,255,255,.04);backdrop-filter:blur(8px);transition:all .2s}
.fhp-back:hover{background:rgba(255,255,255,.08);color:#fff}
.fhp-faction-tag{display:inline-flex;align-items:center;gap:.55rem;padding:.4rem .9rem;background:rgba(var(--acc-rgb),.18);border:1px solid rgba(var(--acc-rgb),.4);color:#fff;border-radius:50px;font-size:.7rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;backdrop-filter:blur(8px);align-self:flex-start}
.fhp-title{font-family:'Syne',sans-serif;font-size:clamp(2rem,5vw,3.4rem);font-weight:800;color:#fff;line-height:1.05;text-shadow:0 4px 30px rgba(0,0,0,.6)}
.fhp-subtitle{font-family:'Syne',sans-serif;font-size:clamp(1rem,2vw,1.25rem);color:rgba(255,255,255,.85);font-weight:500;max-width:680px}
.fhp-motto{font-style:italic;font-size:.92rem;color:rgba(var(--acc-rgb),.95);font-weight:600;letter-spacing:.04em;display:inline-flex;align-items:center;gap:.5rem;margin-top:.3rem}
.fhp-motto::before,.fhp-motto::after{content:"";display:inline-block;width:24px;height:1px;background:rgba(var(--acc-rgb),.6)}
.fhp-cta{display:flex;gap:.7rem;flex-wrap:wrap;margin-top:1.2rem}
.fhp-btn{display:inline-flex;align-items:center;gap:.55rem;padding:.85rem 1.7rem;border-radius:11px;font-weight:700;font-size:.92rem;text-decoration:none;transition:all .25s cubic-bezier(.4,0,.2,1);border:1px solid transparent;cursor:pointer;font-family:'DM Sans',sans-serif}
.fhp-btn.primary{background:var(--acc);color:#fff;box-shadow:0 12px 30px -10px rgba(var(--acc-rgb),.7)}
.fhp-btn.primary:hover{transform:translateY(-2px);box-shadow:0 16px 40px -10px rgba(var(--acc-rgb),.85)}
.fhp-btn.secondary{background:rgba(255,255,255,.08);color:#fff;border-color:rgba(255,255,255,.18);backdrop-filter:blur(10px)}
.fhp-btn.secondary:hover{background:rgba(255,255,255,.14);border-color:rgba(255,255,255,.3);transform:translateY(-2px)}
.fhp-btn.muted{background:rgba(255,255,255,.04);color:rgba(255,255,255,.55);cursor:not-allowed;border-color:rgba(255,255,255,.08)}
.fhp-status{display:inline-flex;align-items:center;gap:.45rem;padding:.45rem 1rem;border-radius:50px;font-size:.78rem;font-weight:700;align-self:flex-start;backdrop-filter:blur(10px)}

/* Slider controls */
.fhp-dots{position:absolute;bottom:1.2rem;right:1.5rem;z-index:3;display:flex;gap:.45rem}
.fhp-dot{width:30px;height:4px;border-radius:2px;background:rgba(255,255,255,.25);border:0;padding:0;cursor:pointer;transition:all .3s}
.fhp-dot.on{background:var(--acc);width:48px}

/* Sections */
.fhp-section{margin-bottom:2.5rem}
.fhp-section-h{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.1rem;flex-wrap:wrap;gap:.75rem}
.fhp-section-title{font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:700;display:flex;align-items:center;gap:.6rem}
.fhp-section-title i{color:var(--acc)}
.fhp-section-sub{color:var(--muted-2);font-size:.85rem;margin-top:.2rem}

/* Présentation */
.fhp-pres{background:linear-gradient(135deg,rgba(var(--acc-rgb),.04),rgba(255,255,255,.02));border:1px solid rgba(255,255,255,.07);border-radius:18px;padding:2rem;line-height:1.75;font-size:.95rem;color:#cbd5e1;position:relative;overflow:hidden}
.fhp-pres::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,transparent,var(--acc),transparent)}
.fhp-pres p{margin-bottom:.8rem}
.fhp-pres strong{color:#fff}
.fhp-pres a{color:var(--acc);text-decoration:underline;text-underline-offset:3px}

/* Annonces */
.fhp-ann-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:.85rem}
.fhp-ann{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;padding:1.1rem;display:flex;gap:.85rem;align-items:flex-start;transition:all .2s;position:relative;overflow:hidden}
.fhp-ann:hover{transform:translateY(-3px);border-color:var(--border-2)}
.fhp-ann::before{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--ann-c,var(--acc))}
.fhp-ann-ic{width:38px;height:38px;border-radius:10px;background:var(--ann-bg);color:var(--ann-c);display:grid;place-items:center;flex-shrink:0;font-size:1rem}
.fhp-ann-body{flex:1;min-width:0}
.fhp-ann-tag{font-size:.62rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--ann-c);margin-bottom:.25rem}
.fhp-ann-title{font-weight:700;font-size:.9rem;color:#fff;line-height:1.35;margin-bottom:.3rem}
.fhp-ann-desc{font-size:.78rem;color:var(--muted-2);line-height:1.55}
.fhp-ann-link{display:inline-block;margin-top:.5rem;font-size:.75rem;color:var(--ann-c);font-weight:600}

/* FAQ */
.fhp-faq{display:flex;flex-direction:column;gap:.55rem}
.fhp-faq-item{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden;transition:border-color .2s}
.fhp-faq-item.open{border-color:rgba(var(--acc-rgb),.35)}
.fhp-faq-q{display:flex;align-items:center;justify-content:space-between;gap:.85rem;padding:1rem 1.25rem;cursor:pointer;font-weight:600;font-size:.92rem;color:#fff;user-select:none;background:transparent;border:0;width:100%;text-align:left;font-family:'DM Sans',sans-serif}
.fhp-faq-q:hover{background:rgba(255,255,255,.02)}
.fhp-faq-q .fhp-faq-num{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:8px;background:rgba(var(--acc-rgb),.12);color:var(--acc);font-size:.78rem;font-weight:800;font-family:'Syne',sans-serif;flex-shrink:0;margin-right:.65rem}
.fhp-faq-chev{color:var(--acc);transition:transform .3s;flex-shrink:0;margin-left:auto}
.fhp-faq-item.open .fhp-faq-chev{transform:rotate(180deg)}
.fhp-faq-a{max-height:0;overflow:hidden;transition:max-height .35s ease;font-size:.88rem;color:#cbd5e1;line-height:1.7}
.fhp-faq-item.open .fhp-faq-a{max-height:600px}
.fhp-faq-a-inner{padding:0 1.25rem 1.1rem 4.4rem}
.fhp-faq-a p{margin-bottom:.6rem}

/* CTA bottom */
.fhp-ctabar{margin-top:3rem;padding:2rem;background:linear-gradient(135deg,rgba(var(--acc-rgb),.12),rgba(var(--acc-rgb),.02));border:1px solid rgba(var(--acc-rgb),.25);border-radius:18px;text-align:center;position:relative;overflow:hidden}
.fhp-ctabar::before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 50% 0%,rgba(var(--acc-rgb),.18),transparent 70%);pointer-events:none}
.fhp-ctabar h3{font-family:'Syne',sans-serif;font-size:1.5rem;margin-bottom:.45rem;color:#fff}
.fhp-ctabar p{color:var(--muted-2);font-size:.92rem;margin-bottom:1.1rem;max-width:520px;margin-left:auto;margin-right:auto}
.fhp-ctabar .fhp-cta{justify-content:center}

/* Empty states */
.fhp-empty{text-align:center;padding:2.5rem 1rem;color:var(--muted-2);background:var(--bg-card);border:1px dashed var(--border);border-radius:14px}
.fhp-empty i{font-size:2rem;opacity:.35;margin-bottom:.6rem;display:block;color:var(--acc)}

@media(max-width:768px){
  .fhp-hero{min-height:380px}
  .fhp-hero-content{padding:1.6rem 1.4rem 1.4rem}
  .fhp-pres{padding:1.4rem}
  .fhp-faq-a-inner{padding-left:1.25rem}
}
</style>

<div class="fhp-hero <?php echo empty($images) ? 'fhp-hero-empty' : ''; ?>">
  <?php if (!empty($images)): ?>
  <div class="fhp-slider" id="fhpSlider">
    <?php foreach ($images as $i => $img): ?>
    <div class="fhp-slide <?php echo $i === 0 ? 'on' : ''; ?>" style="background-image:url('<?php echo htmlspecialchars(academy_image_src($img['image_path'])); ?>')"></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="fhp-overlay"></div>

  <div class="fhp-hero-content">
    <a href="<?php echo SITE_URL; ?>/pages/academy/dashboard.php" class="fhp-back"><i class="fa-solid fa-arrow-left"></i> Toutes les factions</a>
    <span class="fhp-faction-tag"><i class="fa-solid fa-<?php echo $__meta['icon']; ?>"></i> <?php echo htmlspecialchars($__meta['name']); ?></span>
    <h1 class="fhp-title"><?php echo htmlspecialchars($cfg['hero_title']); ?></h1>
    <?php if (!empty($cfg['hero_subtitle'])): ?>
    <p class="fhp-subtitle"><?php echo htmlspecialchars($cfg['hero_subtitle']); ?></p>
    <?php endif; ?>
    <?php if (!empty($cfg['motto'])): ?>
    <div class="fhp-motto"><?php echo htmlspecialchars($cfg['motto']); ?></div>
    <?php endif; ?>

    <?php if ($st): ?>
    <div class="fhp-status" style="background:<?php echo $st['bg']; ?>;color:<?php echo $st['color']; ?>;border:1px solid <?php echo $st['color']; ?>40">
      <i class="fa-solid fa-<?php echo $st['icon']; ?>"></i> Votre candidature : <?php echo $st['label']; ?>
    </div>
    <?php endif; ?>

    <div class="fhp-cta">
      <a href="<?php echo SITE_URL; ?>/pages/academy/candidature_<?php echo $__slug; ?>.php" class="fhp-btn primary">
        <i class="fa-solid fa-file-signature"></i> <?php echo htmlspecialchars($cfg['cta_primary_label']); ?>
      </a>
      <?php if ($canTakeQcm): ?>
      <a href="<?php echo SITE_URL; ?>/pages/academy/qcm_<?php echo $__slug; ?>.php" class="fhp-btn secondary">
        <i class="fa-solid fa-clipboard-question"></i> <?php echo htmlspecialchars($cfg['cta_secondary_label']); ?>
      </a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (count($images) > 1): ?>
  <div class="fhp-dots" id="fhpDots">
    <?php foreach ($images as $i => $img): ?>
    <button type="button" class="fhp-dot <?php echo $i === 0 ? 'on' : ''; ?>" data-idx="<?php echo $i; ?>" aria-label="Slide <?php echo $i+1; ?>"></button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- PRÉSENTATION -->
<?php if (!empty(trim(strip_tags($cfg['presentation_text'] ?? '')))): ?>
<section class="fhp-section">
  <div class="fhp-section-h">
    <div>
      <div class="fhp-section-title"><i class="fa-solid fa-circle-info"></i> Présentation</div>
      <div class="fhp-section-sub">Découvrez le service et ses missions.</div>
    </div>
  </div>
  <div class="fhp-pres"><?php echo $cfg['presentation_text']; ?></div>
</section>
<?php endif; ?>

<!-- ANNONCES -->
<?php if (!empty($announcements)): ?>
<section class="fhp-section">
  <div class="fhp-section-h">
    <div>
      <div class="fhp-section-title"><i class="fa-solid fa-bullhorn"></i> Annonces &amp; informations</div>
      <div class="fhp-section-sub">Restez informés des dernières actualités du service.</div>
    </div>
  </div>
  <div class="fhp-ann-grid">
    <?php foreach ($announcements as $a):
      $k = $kinds[$a['kind']] ?? $kinds['info'];
    ?>
    <div class="fhp-ann" style="--ann-c:<?php echo $k['color']; ?>;--ann-bg:<?php echo $k['bg']; ?>">
      <div class="fhp-ann-ic"><i class="fa-solid fa-<?php echo htmlspecialchars($a['icon'] ?: $k['icon']); ?>"></i></div>
      <div class="fhp-ann-body">
        <div class="fhp-ann-tag"><?php echo $k['label']; ?></div>
        <div class="fhp-ann-title"><?php echo htmlspecialchars($a['title']); ?></div>
        <?php if (!empty($a['description'])): ?>
        <div class="fhp-ann-desc"><?php echo nl2br(htmlspecialchars($a['description'])); ?></div>
        <?php endif; ?>
        <?php if (!empty($a['link_url'])): ?>
        <a class="fhp-ann-link" href="<?php echo htmlspecialchars($a['link_url']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($a['link_label'] ?: 'En savoir plus'); ?> <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:.65em;margin-left:.2rem"></i></a>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- FAQ -->
<?php if (!empty($faq)): ?>
<section class="fhp-section">
  <div class="fhp-section-h">
    <div>
      <div class="fhp-section-title"><i class="fa-solid fa-circle-question"></i> Questions fréquentes</div>
      <div class="fhp-section-sub">Tout ce que vous devez savoir avant de postuler.</div>
    </div>
  </div>
  <div class="fhp-faq" id="fhpFaq">
    <?php foreach ($faq as $i => $q): ?>
    <div class="fhp-faq-item <?php echo $i === 0 ? 'open' : ''; ?>">
      <button type="button" class="fhp-faq-q" onclick="this.parentElement.classList.toggle('open')">
        <span class="fhp-faq-num"><?php echo str_pad((string)($i+1), 2, '0', STR_PAD_LEFT); ?></span>
        <span style="flex:1"><?php echo htmlspecialchars($q['question']); ?></span>
        <i class="fa-solid fa-chevron-down fhp-faq-chev"></i>
      </button>
      <div class="fhp-faq-a"><div class="fhp-faq-a-inner"><?php echo $q['answer']; ?></div></div>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- CTA bas de page -->
<div class="fhp-ctabar">
  <h3>Prêt(e) à rejoindre <?php echo htmlspecialchars($__meta['name']); ?> ?</h3>
  <p>Déposez votre candidature dès maintenant. Notre équipe étudiera votre dossier et reviendra vers vous rapidement.</p>
  <div class="fhp-cta">
    <a href="<?php echo SITE_URL; ?>/pages/academy/candidature_<?php echo $__slug; ?>.php" class="fhp-btn primary">
      <i class="fa-solid fa-file-signature"></i> Déposer une candidature
    </a>
    <?php if ($canTakeQcm): ?>
    <a href="<?php echo SITE_URL; ?>/pages/academy/qcm_<?php echo $__slug; ?>.php" class="fhp-btn secondary">
      <i class="fa-solid fa-clipboard-question"></i> Passer le QCM
    </a>
    <?php elseif ($myCand): ?>
    <span class="fhp-btn muted" title="Le QCM se débloque lorsque votre candidature passe en statut « En examen »."><i class="fa-solid fa-lock"></i> QCM verrouillé</span>
    <?php endif; ?>
  </div>
</div>

<?php if (count($images) > 1): ?>
<script>
(function(){
  const slides = document.querySelectorAll('#fhpSlider .fhp-slide');
  const dots   = document.querySelectorAll('#fhpDots .fhp-dot');
  let cur = 0, timer = null;
  function go(i){
    slides[cur].classList.remove('on');
    if (dots[cur]) dots[cur].classList.remove('on');
    cur = (i + slides.length) % slides.length;
    slides[cur].classList.add('on');
    if (dots[cur]) dots[cur].classList.add('on');
  }
  function next(){ go(cur + 1); }
  function start(){ stop(); timer = setInterval(next, 5500); }
  function stop(){ if (timer) clearInterval(timer); timer = null; }
  dots.forEach(d => d.addEventListener('click', e => {
    go(parseInt(e.currentTarget.dataset.idx, 10) || 0);
    start();
  }));
  start();
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
