<?php
/**
 * Configurateur atelier — inclus dans vente.php (ateliers).
 *
 * @var array<string, array<string, list<array<string,mixed>>>> $gvModsGrouped
 * @var list<array{value:string,label:string}> $gvCategories
 * @var string $gvAccent couleur accent (#hex)
 * @var string $gvTitle titre section (optionnel)
 */
if (!isset($gvModsGrouped) || !isset($gvCategories)) {
    return;
}
$gvAccent = $gvAccent ?? '#8b5cf6';
$gvTitle = $gvTitle ?? 'Configuration véhicule & personnalisation';
$modsJson = json_encode($gvModsGrouped, JSON_UNESCAPED_UNICODE);
$catsJson = json_encode($gvCategories, JSON_UNESCAPED_UNICODE);
if ($modsJson === false) {
    $modsJson = '{}';
}
if ($catsJson === false) {
    $catsJson = '[]';
}
?>
<div class="gv-workshop" id="gvWorkshop" style="--gv-a: <?php echo htmlspecialchars($gvAccent); ?>">
    <input type="hidden" name="garage_mod_ids_json" id="gvModIdsJson" value="[]">
    <input type="hidden" name="garage_line_client" id="gvGarageLineHidden" value="0">

    <div class="gv-head">
        <h2 class="gv-title"><i class="fas fa-car-side"></i> <?php echo htmlspecialchars($gvTitle); ?></h2>
        <p class="gv-sub">Sélectionnez les options (boutons). Le montant <strong>customisation</strong> = % du prix de base (le prix de base n’est pas ajouté à la facture).</p>
    </div>

    <div class="gv-grid-top">
        <div class="gv-card">
            <div class="gv-card-label"><i class="fas fa-id-card"></i> Plaque</div>
            <input type="text" name="vehicle_plate" id="gvPlate" class="gv-input" placeholder="ex. ABC 123" autocomplete="off">
        </div>
        <div class="gv-card">
            <div class="gv-card-label"><i class="fas fa-tag"></i> Modèle</div>
            <input type="text" name="vehicle_model" id="gvModel" class="gv-input" placeholder="ex. Sultan RS" autocomplete="off">
        </div>
        <div class="gv-card gv-card-span">
            <div class="gv-card-label"><i class="fas fa-layer-group"></i> Catégorie</div>
            <input type="hidden" name="vehicle_category" id="gvCategoryValue" value="">
            <div class="gv-chip-grid" id="gvCategoryChips" role="group" aria-label="Catégorie"></div>
            <div class="gv-other-wrap" id="gvCategoryOtherWrap" hidden>
                <input type="text" name="vehicle_category_other" id="gvCategoryOther" class="gv-input gv-input-sm" placeholder="Précisez la catégorie…">
            </div>
        </div>
        <div class="gv-card">
            <div class="gv-card-label"><i class="fas fa-dollar-sign"></i> Prix de base ($)</div>
            <input type="number" name="base_price" id="gvBasePrice" class="gv-input" min="0" step="100" value="0" inputmode="decimal">
        </div>
    </div>

    <div class="gv-mod-sections" id="gvModSections"></div>

    <div class="gv-price-rail">
        <div class="gv-pr-node">
            <span class="gv-pr-lbl">Prix de base</span>
            <span class="gv-pr-val" id="gvDispBase">$0.00</span>
        </div>
        <div class="gv-pr-node">
            <span class="gv-pr-lbl">Total % mods</span>
            <span class="gv-pr-val" id="gvDispPct">+0.00%</span>
        </div>
        <div class="gv-pr-node gv-pr-node-total">
            <span class="gv-pr-lbl">Customisation</span>
            <span class="gv-pr-val" id="gvDispGarage">$0.00</span>
        </div>
    </div>
</div>

<style>
.gv-workshop {
    --gv-a: #8b5cf6;
    --gv-bg: rgba(15, 23, 42, 0.55);
    --gv-bdr: rgba(148, 163, 184, 0.18);
    --gv-txt: #e8edf5;
    --gv-muted: #94a3b8;
    background: var(--gv-bg);
    border: 1px solid var(--gv-bdr);
    border-radius: 14px;
    padding: 1.25rem 1.35rem 1.5rem;
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
}
.gv-workshop::before {
    content: '';
    position: absolute; top: 0; left: 0; right: 0; height: 3px;
    background: linear-gradient(90deg, var(--gv-a), transparent 70%);
    opacity: 0.85;
}
.gv-head { margin-bottom: 1.15rem; }
.gv-title {
    font-size: 1.05rem; font-weight: 800; color: var(--gv-txt);
    display: flex; align-items: center; gap: 0.5rem; margin: 0 0 0.35rem;
}
.gv-sub { font-size: 0.78rem; color: var(--gv-muted); margin: 0; line-height: 1.45; }

.gv-grid-top {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 0.75rem;
    margin-bottom: 1.25rem;
}
.gv-card-span { grid-column: 1 / -1; }
.gv-card-label {
    font-size: 0.62rem; font-weight: 800; text-transform: uppercase;
    letter-spacing: 0.12em; color: var(--gv-muted); margin-bottom: 0.45rem;
    display: flex; align-items: center; gap: 0.35rem;
}
.gv-input {
    width: 100%;
    padding: 0.62rem 0.75rem;
    border-radius: 9px;
    border: 1px solid var(--gv-bdr);
    background: rgba(0,0,0,0.25);
    color: var(--gv-txt);
    font-size: 0.88rem;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.gv-input:focus {
    outline: none;
    border-color: color-mix(in srgb, var(--gv-a) 55%, var(--gv-bdr));
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--gv-a) 18%, transparent);
}
.gv-input-sm { font-size: 0.82rem; padding: 0.5rem 0.65rem; }

.gv-chip-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
}
.gv-cat-btn {
    appearance: none;
    border: 1px solid var(--gv-bdr);
    background: linear-gradient(180deg, rgba(255,255,255,0.04), rgba(0,0,0,0.12));
    color: var(--gv-txt);
    font-size: 0.72rem;
    font-weight: 700;
    padding: 0.45rem 0.65rem;
    border-radius: 8px;
    cursor: pointer;
    transition: transform 0.12s, border-color 0.15s, box-shadow 0.15s, background 0.15s;
    font-family: inherit;
}
.gv-cat-btn:hover {
    border-color: color-mix(in srgb, var(--gv-a) 45%, var(--gv-bdr));
}
.gv-cat-btn:active { transform: scale(0.97); }
.gv-cat-btn.is-on {
    border-color: var(--gv-a);
    background: linear-gradient(135deg, color-mix(in srgb, var(--gv-a) 28%, transparent), rgba(0,0,0,0.2));
    box-shadow: 0 0 0 1px color-mix(in srgb, var(--gv-a) 35%, transparent), inset 0 1px 0 rgba(255,255,255,0.06);
}

.gv-mod-sections { display: flex; flex-direction: column; gap: 1rem; }
.gv-mod-cat {
    border: 1px solid var(--gv-bdr);
    border-radius: 12px;
    overflow: hidden;
    background: rgba(0,0,0,0.15);
}
.gv-mod-cat-h {
    padding: 0.65rem 0.9rem;
    font-size: 0.72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.14em;
    color: color-mix(in srgb, var(--gv-a) 90%, #fff);
    border-bottom: 1px solid var(--gv-bdr);
    background: linear-gradient(90deg, color-mix(in srgb, var(--gv-a) 12%, transparent), transparent);
}
.gv-sub-block { padding: 0.75rem 0.85rem 0.9rem; border-bottom: 1px solid rgba(148,163,184,0.08); }
.gv-sub-block:last-child { border-bottom: none; }
.gv-sub-lbl {
    font-size: 0.65rem; font-weight: 700; color: var(--gv-muted);
    margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.08em;
}
.gv-mod-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}
.gv-mod-btn {
    appearance: none;
    cursor: pointer;
    font-family: inherit;
    font-size: 0.74rem;
    font-weight: 700;
    padding: 0.48rem 0.62rem;
    border-radius: 8px;
    border: 1px solid var(--gv-bdr);
    color: var(--gv-txt);
    background: linear-gradient(145deg, rgba(255,255,255,0.03), rgba(0,0,0,0.18));
    transition: transform 0.1s, border-color 0.15s, box-shadow 0.15s;
    text-align: left;
    max-width: 100%;
}
.gv-mod-btn small {
    display: block;
    font-size: 0.62rem;
    font-weight: 600;
    color: #86efac;
    margin-top: 0.15rem;
    opacity: 0.9;
}
.gv-mod-btn:hover { border-color: color-mix(in srgb, var(--gv-a) 40%, var(--gv-bdr)); }
.gv-mod-btn:active { transform: scale(0.98); }
.gv-mod-btn.is-on {
    border-color: var(--gv-a);
    background: linear-gradient(145deg, color-mix(in srgb, var(--gv-a) 22%, transparent), rgba(0,0,0,0.25));
    box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--gv-a) 25%, transparent);
}

.gv-price-rail {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.65rem;
    margin-top: 1.15rem;
    padding-top: 1rem;
    border-top: 1px solid var(--gv-bdr);
}
.gv-pr-node {
    background: rgba(0,0,0,0.2);
    border: 1px solid var(--gv-bdr);
    border-radius: 10px;
    padding: 0.65rem 0.75rem;
    text-align: center;
}
.gv-pr-node-total {
    border-color: color-mix(in srgb, var(--gv-a) 35%, var(--gv-bdr));
    background: linear-gradient(180deg, color-mix(in srgb, var(--gv-a) 10%, transparent), rgba(0,0,0,0.2));
}
.gv-pr-lbl {
    display: block;
    font-size: 0.58rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: var(--gv-muted);
    margin-bottom: 0.25rem;
}
.gv-pr-val {
    font-family: ui-monospace, 'DM Mono', monospace;
    font-size: 1.05rem;
    font-weight: 800;
    color: #4ade80;
}
.gv-pr-node-total .gv-pr-val { font-size: 1.2rem; }

@media (max-width: 640px) {
    .gv-price-rail { grid-template-columns: 1fr; }
}
</style>

<script>
(function () {
    const MODS = <?php echo $modsJson; ?>;
    const CATS = <?php echo $catsJson; ?>;

    const flatPct = {};
    Object.keys(MODS).forEach(function (c) {
        Object.keys(MODS[c]).forEach(function (sub) {
            MODS[c][sub].forEach(function (m) {
                flatPct[m.id] = parseFloat(m.percentage) || 0;
            });
        });
    });

    const selected = new Set();
    const elModSections = document.getElementById('gvModSections');
    const elModIdsJson = document.getElementById('gvModIdsJson');
    const elBase = document.getElementById('gvBasePrice');
    const elDispBase = document.getElementById('gvDispBase');
    const elDispPct = document.getElementById('gvDispPct');
    const elDispGarage = document.getElementById('gvDispGarage');
    const elCatVal = document.getElementById('gvCategoryValue');
    const elCatChips = document.getElementById('gvCategoryChips');
    const elOtherWrap = document.getElementById('gvCategoryOtherWrap');
    const elOther = document.getElementById('gvCategoryOther');

    function fmtMoney(n) {
        return '$' + (Math.round(n * 100) / 100).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function recalc() {
        let sumPct = 0;
        selected.forEach(function (id) {
            sumPct += flatPct[id] || 0;
        });
        const base = parseFloat(elBase.value) || 0;
        // Uniquement le coût des mods : % du prix de base (sans ajouter le prix de base).
        const garage = base * (sumPct / 100);
        elDispBase.textContent = fmtMoney(base);
        elDispPct.textContent = '+' + sumPct.toFixed(2) + '%';
        elDispGarage.textContent = fmtMoney(garage);
        const glh = document.getElementById('gvGarageLineHidden');
        if (glh) glh.value = String(Math.round(garage * 100) / 100);
        elModIdsJson.value = JSON.stringify(Array.from(selected));
        elModIdsJson.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function toggleMod(id, btn) {
        id = parseInt(id, 10);
        if (selected.has(id)) {
            selected.delete(id);
            btn.classList.remove('is-on');
        } else {
            selected.add(id);
            btn.classList.add('is-on');
        }
        recalc();
    }

    function buildMods() {
        elModSections.innerHTML = '';
        ['Performance', 'Aspect'].forEach(function (catKey) {
            if (!MODS[catKey]) return;
            const wrap = document.createElement('div');
            wrap.className = 'gv-mod-cat';
            const h = document.createElement('div');
            h.className = 'gv-mod-cat-h';
            h.textContent = catKey === 'Performance' ? '⚙ Performance' : '🎨 Apparence & options';
            wrap.appendChild(h);
            Object.keys(MODS[catKey]).forEach(function (sub) {
                const block = document.createElement('div');
                block.className = 'gv-sub-block';
                const lbl = document.createElement('div');
                lbl.className = 'gv-sub-lbl';
                lbl.textContent = sub;
                block.appendChild(lbl);
                const row = document.createElement('div');
                row.className = 'gv-mod-row';
                MODS[catKey][sub].forEach(function (m) {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'gv-mod-btn';
                    b.dataset.id = m.id;
                    b.innerHTML = m.name + '<small>+' + parseFloat(m.percentage).toFixed(2) + '%</small>';
                    b.addEventListener('click', function () { toggleMod(m.id, b); });
                    row.appendChild(b);
                });
                block.appendChild(row);
                wrap.appendChild(block);
            });
            elModSections.appendChild(wrap);
        });
    }

    function setCategory(val) {
        elCatVal.value = val;
        elCatChips.querySelectorAll('.gv-cat-btn').forEach(function (b) {
            b.classList.toggle('is-on', b.dataset.val === val);
        });
        const isOther = val === 'Autre';
        elOtherWrap.hidden = !isOther;
        if (isOther) elOther.focus();
    }

    CATS.forEach(function (c) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'gv-cat-btn';
        b.dataset.val = c.value;
        b.textContent = c.label;
        b.addEventListener('click', function () { setCategory(c.value); });
        elCatChips.appendChild(b);
    });

    elBase.addEventListener('input', recalc);
    elOther.addEventListener('input', function () {
        /* optional: live validation only */
    });

    buildMods();
    recalc();
})();
</script>
