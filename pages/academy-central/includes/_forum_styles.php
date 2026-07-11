<?php
/**
 * _forum_styles.php — feuille de style commune au forum.
 * À insérer après header.php, dans le <body>.
 */
if (defined('ACADEMY_FORUM_STYLES_LOADED')) return;
define('ACADEMY_FORUM_STYLES_LOADED', true);
?>
<style>
/* ─── Layout ─────────────────────────────────────────────────────────── */
.f-grid-cats{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px}
.f-faction-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:13px}

/* ─── Avatar ─────────────────────────────────────────────────────────── */
.f-ava{border-radius:9px;background:linear-gradient(135deg,#8b5cf6,#6366f1);display:grid;place-items:center;color:#fff;font-weight:700;font-family:'Syne',sans-serif;font-size:.9rem;flex-shrink:0;overflow:hidden}
.f-ava img{width:100%;height:100%;object-fit:cover;display:block}

/* ─── Tags ───────────────────────────────────────────────────────────── */
.ftag{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:50px;font-size:.7rem;font-weight:600;line-height:1.3;border:1px solid;white-space:nowrap}
.ftag-sm{padding:1px 8px;font-size:.65rem}
.ftag-pickable{cursor:pointer;transition:all .15s}
.ftag-pickable:hover{transform:translateY(-1px)}
.ftag-pickable.on{box-shadow:0 0 0 2px currentColor inset}

/* ─── Faction tile sur l'index ───────────────────────────────────────── */
.f-faction-tile{position:relative;background:var(--bg-card);border:1px solid var(--border);border-radius:14px;padding:18px;display:block;text-decoration:none;transition:all .2s;overflow:hidden}
.f-faction-tile::before{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--ftc,#8b5cf6)}
.f-faction-tile:hover{transform:translateY(-3px);border-color:var(--border-hover);box-shadow:0 12px 26px -14px rgba(0,0,0,.5)}
.f-faction-tile .ftt-head{display:flex;align-items:center;gap:11px;margin-bottom:12px}
.f-faction-tile .ftt-ic{width:42px;height:42px;border-radius:11px;display:grid;place-items:center;font-size:1.05rem;color:var(--ftc);background:var(--ftc15,rgba(139,92,246,.10));flex-shrink:0}
.f-faction-tile .ftt-name{font-family:'Syne',sans-serif;font-weight:700;font-size:1rem;color:#fff;flex:1}
.f-faction-tile .ftt-arrow{color:var(--text-secondary);transition:transform .15s}
.f-faction-tile:hover .ftt-arrow{transform:translateX(3px);color:#fff}
.f-faction-tile .ftt-stats{display:flex;gap:14px;font-size:.74rem;color:var(--text-secondary)}
.f-faction-tile .ftt-stats strong{color:#fff;font-family:'DM Mono',monospace;font-weight:700}
.f-faction-tile .ftt-foot{margin-top:11px;padding-top:11px;border-top:1px solid var(--border);font-size:.72rem;color:var(--text-secondary);display:flex;align-items:center;gap:6px}

/* ─── Catégorie row (dans une faction) ───────────────────────────────── */
.f-cat-card{background:var(--bg-card);border:1px solid var(--border);border-radius:13px;padding:16px 18px;display:flex;align-items:center;gap:14px;transition:all .15s;text-decoration:none;color:inherit}
.f-cat-card:hover{border-color:var(--border-hover);transform:translateY(-2px)}
.f-cat-card .fcc-ic{width:42px;height:42px;border-radius:11px;display:grid;place-items:center;font-size:1.05rem;flex-shrink:0;color:#fff}
.f-cat-card .fcc-info{flex:1;min-width:0}
.f-cat-card .fcc-name{font-family:'Syne',sans-serif;font-weight:700;font-size:.95rem;color:#fff;display:flex;align-items:center;gap:8px}
.f-cat-card .fcc-desc{font-size:.78rem;color:var(--text-secondary);margin-top:3px;line-height:1.4}
.f-cat-card .fcc-stats{display:flex;flex-direction:column;align-items:flex-end;gap:3px;font-size:.72rem;color:var(--text-secondary);text-align:right;flex-shrink:0;min-width:90px}
.f-cat-card .fcc-stats .num{font-family:'Syne',sans-serif;font-size:1rem;color:#fff;font-weight:700}

/* ─── Thread row ─────────────────────────────────────────────────────── */
.f-thread-row{display:grid;grid-template-columns:auto 1fr auto auto;align-items:center;gap:14px;padding:13px 16px;background:var(--bg-card);border:1px solid var(--border);border-radius:11px;text-decoration:none;color:inherit;transition:all .15s;margin-bottom:6px}
.f-thread-row:hover{border-color:var(--border-hover);background:var(--bg-elevated)}
.f-thread-row.pinned{border-color:rgba(245,158,11,.35);background:linear-gradient(90deg,rgba(245,158,11,.04),transparent 50%)}
.f-thread-row.locked{opacity:.65}
.f-thread-row .ftr-ic{width:36px;height:36px;border-radius:9px;background:rgba(139,92,246,.14);color:#c4b5fd;display:grid;place-items:center;font-size:.85rem;flex-shrink:0}
.f-thread-row.pinned .ftr-ic{background:rgba(245,158,11,.18);color:#fbbf24}
.f-thread-row .ftr-mid{min-width:0}
.f-thread-row .ftr-title{font-weight:700;font-size:.92rem;color:#fff;display:flex;align-items:center;gap:8px;flex-wrap:wrap;line-height:1.35;margin-bottom:3px}
.f-thread-row .ftr-meta{font-size:.74rem;color:var(--text-secondary);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.f-thread-row .ftr-meta .sep{opacity:.4}
.f-thread-row .ftr-stats{text-align:right;font-size:.72rem;color:var(--text-secondary);min-width:80px}
.f-thread-row .ftr-stats .num{display:block;color:#fff;font-family:'Syne',sans-serif;font-weight:700;font-size:.95rem;line-height:1}
.f-thread-row .ftr-last{display:flex;align-items:center;gap:9px;font-size:.74rem;color:var(--text-secondary);min-width:170px;flex-shrink:0}
.f-thread-row .ftr-last .last-info{flex:1;min-width:0}
.f-thread-row .ftr-last .last-author{color:#fff;font-weight:600;font-size:.78rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.f-thread-row .ftr-last .last-when{font-size:.7rem;color:var(--text-secondary);margin-top:1px}
@media(max-width:880px){
  .f-thread-row{grid-template-columns:auto 1fr auto}
  .f-thread-row .ftr-last{display:none}
}
@media(max-width:560px){
  .f-thread-row{grid-template-columns:1fr}
  .f-thread-row .ftr-ic,.f-thread-row .ftr-stats{display:none}
}

/* ─── Thread view (post stack) ───────────────────────────────────────── */
.f-thread-head{background:linear-gradient(135deg,var(--fac-c-15,rgba(139,92,246,.10)) 0%,transparent 60%);border:1px solid var(--border);border-radius:14px;padding:18px 20px;margin-bottom:18px}
.f-thread-head h2{font-family:'Syne',sans-serif;font-size:1.4rem;color:#fff;margin-bottom:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;line-height:1.25}
.f-thread-head .ft-meta{display:flex;align-items:center;gap:14px;flex-wrap:wrap;font-size:.78rem;color:var(--text-secondary)}
.f-thread-head .ft-meta strong{color:#fff;font-weight:600}
.f-thread-head .ft-author{display:inline-flex;align-items:center;gap:7px}

.f-post{display:grid;grid-template-columns:64px 1fr;gap:0;margin-bottom:14px;background:var(--bg-card);border:1px solid var(--border);border-radius:13px;overflow:hidden;scroll-margin-top:90px}
.f-post.opening{border-color:rgba(139,92,246,.30);background:linear-gradient(180deg,rgba(139,92,246,.06) 0%,var(--bg-card) 100%)}
.f-post.deleted{opacity:.6}
.f-post .fp-side{padding:18px 12px;background:rgba(0,0,0,.18);display:flex;flex-direction:column;align-items:center;gap:8px;border-right:1px solid var(--border)}
.f-post .fp-side .fp-author{font-family:'Syne',sans-serif;font-size:.72rem;color:#fff;font-weight:700;text-align:center;line-height:1.2;word-break:break-word;max-width:54px}
.f-post .fp-side .fp-rank{font-size:.6rem;color:var(--text-secondary);text-align:center;line-height:1.2;text-transform:uppercase;letter-spacing:.05em}
.f-post .fp-body{padding:16px 18px;min-width:0}
.f-post .fp-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:.74rem;color:var(--text-secondary);margin-bottom:10px;padding-bottom:9px;border-bottom:1px solid var(--border)}
.f-post .fp-head .fp-num{margin-left:auto;font-family:'DM Mono',monospace;color:var(--text-muted);font-size:.7rem}
.f-post .fp-quote-box{border-left:3px solid var(--purple);padding:8px 12px;margin:0 0 12px;background:rgba(139,92,246,.06);border-radius:0 8px 8px 0;font-size:.82rem;color:var(--text-secondary)}
.f-post .fp-quote-box .qa{font-weight:700;color:#c4b5fd;font-size:.74rem;margin-bottom:3px}
.f-post .fp-content{font-size:.92rem;line-height:1.7;color:#cbd5e1;word-wrap:break-word;overflow-wrap:break-word}
.f-post .fp-content p{margin:0 0 10px}
.f-post .fp-content h1,.f-post .fp-content h2,.f-post .fp-content h3{color:#fff;margin:14px 0 8px;font-family:'Syne',sans-serif}
.f-post .fp-content h1{font-size:1.3rem}
.f-post .fp-content h2{font-size:1.15rem}
.f-post .fp-content h3{font-size:1rem}
.f-post .fp-content blockquote{border-left:3px solid var(--purple);padding:6px 12px;margin:10px 0;color:var(--text-secondary);background:rgba(139,92,246,.05)}
.f-post .fp-content code{background:rgba(255,255,255,.07);padding:2px 6px;border-radius:4px;font-family:'DM Mono',monospace;font-size:.85em}
.f-post .fp-content pre{background:rgba(0,0,0,.3);padding:12px;border-radius:8px;border:1px solid var(--border);overflow-x:auto;margin:10px 0}
.f-post .fp-content ul,.f-post .fp-content ol{margin:6px 0 10px 24px}
.f-post .fp-content li{margin-bottom:3px}
.f-post .fp-content a{color:#c4b5fd;text-decoration:underline}
.f-post .fp-foot{margin-top:12px;padding-top:11px;border-top:1px solid var(--border);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.f-post .fp-edited{font-size:.68rem;color:var(--text-muted);font-style:italic;margin-left:auto}

.fmention{background:rgba(139,92,246,.14);color:#c4b5fd;padding:1px 7px;border-radius:5px;font-weight:600;font-size:.92em}

@media(max-width:600px){
  .f-post{grid-template-columns:1fr}
  .f-post .fp-side{flex-direction:row;justify-content:flex-start;padding:10px 14px;border-right:0;border-bottom:1px solid var(--border);gap:10px}
  .f-post .fp-side .f-ava{width:32px!important;height:32px!important}
  .f-post .fp-side .fp-author,.f-post .fp-side .fp-rank{max-width:none;text-align:left}
}

/* ─── Like / actions buttons ─────────────────────────────────────────── */
.fbtn-react{background:transparent;border:1px solid var(--border);color:var(--text-secondary);padding:5px 11px;border-radius:50px;cursor:pointer;font-size:.74rem;display:inline-flex;align-items:center;gap:5px;transition:all .15s;font-family:inherit}
.fbtn-react:hover{border-color:var(--border-hover);color:#fff}
.fbtn-react.like.on{background:rgba(236,72,153,.12);border-color:rgba(236,72,153,.4);color:#f9a8d4}
.fbtn-react i{font-size:.72rem}

/* ─── Reply box ──────────────────────────────────────────────────────── */
.f-reply-box{margin-top:16px;background:var(--bg-card);border:1px solid var(--border);border-radius:13px;overflow:hidden}
.f-reply-box-head{padding:13px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;font-family:'Syne',sans-serif;font-weight:700;font-size:.92rem;color:#fff}
.f-reply-box-body{padding:14px 16px}

/* ─── Quill skin ─────────────────────────────────────────────────────── */
.ql-toolbar.ql-snow,.ql-container.ql-snow{border-color:var(--border)!important}
.ql-toolbar.ql-snow{background:rgba(255,255,255,.02);border-radius:9px 9px 0 0}
.ql-container.ql-snow{border-radius:0 0 9px 9px;background:var(--bg-elevated);min-height:160px}
.ql-editor{color:var(--text-primary)!important;min-height:140px;font-family:'DM Sans',sans-serif;font-size:.92rem;line-height:1.6}
.ql-editor.ql-blank::before{color:var(--text-secondary)!important;font-style:normal!important;opacity:.6}
.ql-snow .ql-stroke{stroke:var(--text-secondary)}
.ql-snow .ql-fill{fill:var(--text-secondary)}
.ql-snow .ql-picker{color:var(--text-secondary)}
.ql-snow .ql-picker-options{background:var(--bg-elevated);border-color:var(--border)}
.ql-snow .ql-picker-label:hover,.ql-snow .ql-picker-item:hover{color:#fff}

/* ─── Pagination ─────────────────────────────────────────────────────── */
.fpagi{display:flex;align-items:center;justify-content:center;gap:6px;margin-top:16px;flex-wrap:wrap}
.fpagi a,.fpagi span{display:inline-grid;place-items:center;min-width:32px;height:32px;padding:0 9px;border-radius:8px;background:var(--bg-card);border:1px solid var(--border);color:var(--text-secondary);text-decoration:none;font-size:.82rem;font-weight:600;transition:all .15s}
.fpagi a:hover{color:#fff;border-color:var(--border-hover)}
.fpagi .on{background:var(--purple);color:#fff;border-color:var(--purple)}
.fpagi .ellipsis{background:transparent;border:0;color:var(--text-muted)}

/* ─── Notifications dropdown (topbar) ────────────────────────────────── */
.f-notif-pop{position:absolute;top:48px;right:0;width:360px;max-height:480px;overflow-y:auto;background:var(--bg-elevated);border:1px solid var(--border);border-radius:12px;box-shadow:0 18px 40px -12px rgba(0,0,0,.6);z-index:200;display:none}
.f-notif-pop.open{display:block}
.f-notif-pop .fnp-head{padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.f-notif-pop .fnp-head h4{font-family:'Syne',sans-serif;font-size:.88rem;font-weight:700;color:#fff}
.f-notif-pop .fnp-empty{padding:30px 18px;text-align:center;color:var(--text-secondary);font-size:.82rem}
.f-notif-pop .fnp-item{padding:11px 16px;border-bottom:1px solid var(--border);display:flex;gap:10px;text-decoration:none;color:inherit;transition:background .15s}
.f-notif-pop .fnp-item:hover{background:var(--bg-hover)}
.f-notif-pop .fnp-item.unread{background:rgba(139,92,246,.05);border-left:3px solid var(--purple)}
.f-notif-pop .fnp-item .fnp-ic{width:32px;height:32px;border-radius:8px;background:rgba(139,92,246,.14);color:#c4b5fd;display:grid;place-items:center;font-size:.78rem;flex-shrink:0}
.f-notif-pop .fnp-item .fnp-info{flex:1;min-width:0}
.f-notif-pop .fnp-item .fnp-text{font-size:.82rem;color:#cbd5e1;line-height:1.4;margin-bottom:3px}
.f-notif-pop .fnp-item .fnp-text strong{color:#fff;font-weight:600}
.f-notif-pop .fnp-item .fnp-when{font-size:.7rem;color:var(--text-muted)}
.f-notif-bell{position:relative}
.f-notif-bell .fnb-dot{position:absolute;top:5px;right:5px;background:#ef4444;color:#fff;font-size:.6rem;font-weight:700;min-width:16px;height:16px;border-radius:8px;display:grid;place-items:center;padding:0 4px;border:2px solid var(--bg-base);font-family:'DM Mono',monospace}
</style>
