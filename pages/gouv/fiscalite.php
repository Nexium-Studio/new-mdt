<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = 'Fiscalité';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

// ── ACTIONS ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';

    // Modifier un barème
    if ($action === 'update_rate') {
        try {
            $faction = trim($_POST['faction']);
            $label   = trim($_POST['label'] ?? $faction);
            $db->prepare("
                INSERT INTO gouv_tax_rates (faction, label, tranche_1_max, tranche_1_rate, tranche_2_max, tranche_2_rate, tranche_3_max, tranche_3_rate, tranche_4_rate)
                VALUES (?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                  label=VALUES(label),
                  tranche_1_max=VALUES(tranche_1_max), tranche_1_rate=VALUES(tranche_1_rate),
                  tranche_2_max=VALUES(tranche_2_max), tranche_2_rate=VALUES(tranche_2_rate),
                  tranche_3_max=VALUES(tranche_3_max), tranche_3_rate=VALUES(tranche_3_rate),
                  tranche_4_rate=VALUES(tranche_4_rate)
            ")->execute([
                $faction, $label,
                floatval($_POST['t1_max']), floatval($_POST['t1_rate']),
                floatval($_POST['t2_max']), floatval($_POST['t2_rate']),
                floatval($_POST['t3_max']), floatval($_POST['t3_rate']),
                floatval($_POST['t4_rate']),
            ]);
            $msg='Barème mis à jour.'; $msgType='ok';
        } catch(Exception $e) { $msg='Erreur : '.$e->getMessage(); $msgType='err'; }
    }

    // Supprimer un barème spécifique (ne peut pas supprimer _DEFAULT_)
    if ($action === 'delete_rate') {
        $fk = trim($_POST['faction']);
        if ($fk !== '_DEFAULT_') {
            $db->prepare("DELETE FROM gouv_tax_rates WHERE faction=?")->execute([$fk]);
            $msg='Barème spécifique supprimé (revenu au barème général).'; $msgType='ok';
        }
    }

    // Ajouter une exonération
    if ($action === 'add_exemption') {
        try {
            $db->prepare("
                INSERT INTO gouv_exemptions (faction, reason, expires_at, created_by)
                VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE reason=VALUES(reason), expires_at=VALUES(expires_at)
            ")->execute([
                trim($_POST['faction']),
                trim($_POST['reason']),
                !empty($_POST['expires_at']) ? $_POST['expires_at'] : null,
                $_SESSION['user_id'],
            ]);
            $msg='Exonération enregistrée.'; $msgType='ok';
        } catch(Exception $e) { $msg='Erreur : '.$e->getMessage(); $msgType='err'; }
    }

    // Supprimer une exonération
    if ($action === 'delete_exemption') {
        $db->prepare("DELETE FROM gouv_exemptions WHERE id=?")->execute([intval($_POST['exemption_id'])]);
        $msg='Exonération supprimée.'; $msgType='ok';
    }
}

// ── DATA ──────────────────────────────────────────────────────────────────────
$rates = $db->query("SELECT * FROM gouv_tax_rates ORDER BY CASE WHEN faction='_DEFAULT_' THEN 0 ELSE 1 END, faction")->fetchAll();
$defaultRate = null;
$specificRates = [];
foreach ($rates as $r) {
    if ($r['faction'] === '_DEFAULT_') $defaultRate = $r;
    else $specificRates[] = $r;
}
if (!$defaultRate) $defaultRate = ['faction'=>'_DEFAULT_','label'=>'Barème général','tranche_1_max'=>50000,'tranche_1_rate'=>0,'tranche_2_max'=>100000,'tranche_2_rate'=>10,'tranche_3_max'=>500000,'tranche_3_rate'=>15,'tranche_4_rate'=>20];

// Exonérations
$exemptions = $db->query("
    SELECT ge.*, COALESCE(f.name,ge.faction) AS faction_name,
           COALESCE(f.color,'#3b82f6') AS faction_color,
           u.username AS created_by_name
    FROM gouv_exemptions ge
    LEFT JOIN factions f ON f.faction_key COLLATE utf8mb4_unicode_ci = ge.faction
    LEFT JOIN users u ON u.id=ge.created_by
    ORDER BY ge.created_at DESC
")->fetchAll();

// État fiscal par entreprise (ce que chaque entreprise doit / a payé)
$fiscalStatus = $db->query("
    SELECT cf.faction,
           COALESCE(f.name,cf.faction) AS faction_name,
           COALESCE(f.color,'#3b82f6') AS faction_color,
           cf.ca_brut, cf.benefice_brut, cf.impot_du, cf.benefice_net,
           (SELECT 1 FROM gouv_exemptions ge
            WHERE ge.faction=cf.faction
              AND (ge.expires_at IS NULL OR ge.expires_at>=CURDATE()) LIMIT 1) AS is_exempt,
           (SELECT gtr.faction FROM gouv_tax_rates gtr WHERE gtr.faction=cf.faction LIMIT 1) AS has_custom_rate
    FROM company_finances cf
    LEFT JOIN factions f ON f.faction_key COLLATE utf8mb4_unicode_ci = cf.faction
    ORDER BY cf.impot_du DESC
")->fetchAll();

// Liste factions pour les selects
$allFactions = $db->query("SELECT faction_key, name FROM factions ORDER BY name")->fetchAll();

include __DIR__ . '/includes/header.php';

// Helper: afficher un barème
function displayRate($r) {
    return sprintf(
        '0→$%s: %s%% | $%s→$%s: %s%% | $%s→$%s: %s%% | >$%s: %s%%',
        number_format($r['tranche_1_max'],0,'.',','), $r['tranche_1_rate'],
        number_format($r['tranche_1_max'],0,'.',','), number_format($r['tranche_2_max'],0,'.',','), $r['tranche_2_rate'],
        number_format($r['tranche_2_max'],0,'.',','), number_format($r['tranche_3_max'],0,'.',','), $r['tranche_3_rate'],
        number_format($r['tranche_3_max'],0,'.',','), $r['tranche_4_rate']
    );
}
?>
<style>
.rate-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:12px;padding:1.25rem;margin-bottom:1rem}
.rate-card.default{border-color:rgba(251,191,36,.3);background:rgba(251,191,36,.04)}
.rate-tranche{display:flex;align-items:center;justify-content:space-between;padding:.55rem .85rem;border-radius:7px;background:var(--bg-ov);margin-bottom:.4rem;font-size:.8rem}
.rate-tranche:last-child{margin-bottom:0}
.tranche-range{color:var(--txt-s)}
.tranche-pct{font-weight:700;font-family:'IBM Plex Mono',monospace;font-size:.9rem}
.fiscal-row{display:flex;align-items:center;gap:1rem;padding:.85rem;border-bottom:1px solid rgba(255,255,255,.04)}
.fiscal-row:last-child{border-bottom:none}
</style>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>">
    <i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo $msg; ?>
</div>
<?php endif; ?>

<div class="grid2">
<!-- ── COLONNE GAUCHE : Barèmes ──────────────────────────────────── -->
<div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <div class="card-title" style="margin:0"><i class="fas fa-scale-balanced" style="color:var(--gov)"></i> Barèmes fiscaux</div>
        <button onclick="Gov.openModal('addRateModal')" class="btn btn-secondary btn-sm">
            <i class="fas fa-plus"></i> Barème spécifique
        </button>
    </div>

    <!-- Barème par défaut -->
    <div class="rate-card default">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.9rem">
            <div>
                <span class="badge bg-gold" style="margin-bottom:.3rem"><i class="fas fa-star"></i> Barème général</span>
                <div style="font-size:.72rem;color:var(--txt-m)">Appliqué à toutes les entreprises sans barème spécifique</div>
            </div>
            <button onclick="openRateEdit(<?php echo htmlspecialchars(json_encode($defaultRate),ENT_QUOTES); ?>)" class="btn btn-warning btn-sm">
                <i class="fas fa-pen"></i> Modifier
            </button>
        </div>
        <div class="rate-tranche">
            <span class="tranche-range"><i class="fas fa-circle-1" style="color:#4ade80;margin-right:.4rem"></i>0 → $<?php echo number_format($defaultRate['tranche_1_max'],0,'.',','); ?></span>
            <span class="tranche-pct" style="color:#4ade80"><?php echo $defaultRate['tranche_1_rate']; ?>%</span>
        </div>
        <div class="rate-tranche">
            <span class="tranche-range"><i class="fas fa-circle-2" style="color:#fcd34d;margin-right:.4rem"></i>$<?php echo number_format($defaultRate['tranche_1_max'],0,'.',','); ?> → $<?php echo number_format($defaultRate['tranche_2_max'],0,'.',','); ?></span>
            <span class="tranche-pct" style="color:#fcd34d"><?php echo $defaultRate['tranche_2_rate']; ?>%</span>
        </div>
        <div class="rate-tranche">
            <span class="tranche-range"><i class="fas fa-circle-3" style="color:#fb923c;margin-right:.4rem"></i>$<?php echo number_format($defaultRate['tranche_2_max'],0,'.',','); ?> → $<?php echo number_format($defaultRate['tranche_3_max'],0,'.',','); ?></span>
            <span class="tranche-pct" style="color:#fb923c"><?php echo $defaultRate['tranche_3_rate']; ?>%</span>
        </div>
        <div class="rate-tranche">
            <span class="tranche-range"><i class="fas fa-circle-4" style="color:#f87171;margin-right:.4rem"></i>> $<?php echo number_format($defaultRate['tranche_3_max'],0,'.',','); ?></span>
            <span class="tranche-pct" style="color:#f87171"><?php echo $defaultRate['tranche_4_rate']; ?>%</span>
        </div>
    </div>

    <!-- Barèmes spécifiques -->
    <?php foreach($specificRates as $sr): ?>
    <div class="rate-card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem">
            <div>
                <span class="badge bg-blue"><i class="fas fa-building"></i> <?php echo htmlspecialchars($sr['label'] ?: $sr['faction']); ?></span>
                <div style="font-size:.7rem;color:var(--txt-m);margin-top:.2rem;font-family:monospace"><?php echo $sr['faction']; ?></div>
            </div>
            <div style="display:flex;gap:.4rem">
                <button onclick="openRateEdit(<?php echo htmlspecialchars(json_encode($sr),ENT_QUOTES); ?>)" class="btn btn-secondary btn-sm"><i class="fas fa-pen"></i></button>
                <form method="POST" style="margin:0" onsubmit="return confirm('Supprimer ce barème spécifique ?')">
                    <input type="hidden" name="action" value="delete_rate">
                    <input type="hidden" name="faction" value="<?php echo $sr['faction']; ?>">
                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                </form>
            </div>
        </div>
        <div style="font-size:.72rem;color:var(--txt-m)"><?php echo displayRate($sr); ?></div>
    </div>
    <?php endforeach; ?>
    <?php if(empty($specificRates)): ?>
    <div style="text-align:center;padding:1.5rem;color:var(--txt-m);font-size:.82rem;background:var(--bg-ov);border-radius:9px">
        <i class="fas fa-info-circle" style="margin-right:.4rem"></i>Aucun barème spécifique — toutes les entreprises utilisent le barème général
    </div>
    <?php endif; ?>

    <!-- Exonérations -->
    <div style="margin-top:1.5rem;display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem">
        <div class="card-title" style="margin:0"><i class="fas fa-shield-halved" style="color:#fcd34d"></i> Exonérations actives</div>
        <button onclick="Gov.openModal('addExemptModal')" class="btn btn-warning btn-sm">
            <i class="fas fa-plus"></i> Exonérer
        </button>
    </div>
    <?php if(empty($exemptions)): ?>
    <div style="text-align:center;padding:1.5rem;color:var(--txt-m);font-size:.82rem;background:var(--bg-ov);border-radius:9px">
        Aucune exonération en cours
    </div>
    <?php else: ?>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Entreprise</th><th>Motif</th><th>Expire</th><th></th></tr></thead>
            <tbody>
            <?php foreach($exemptions as $ex):
                $expired = $ex['expires_at'] && $ex['expires_at'] < date('Y-m-d');
            ?>
            <tr>
                <td>
                    <span class="faction-chip" style="background:<?php echo $ex['faction_color']; ?>18;color:<?php echo $ex['faction_color']; ?>;border:1px solid <?php echo $ex['faction_color']; ?>33">
                        <?php echo htmlspecialchars($ex['faction_name']); ?>
                    </span>
                </td>
                <td style="font-size:.78rem;color:var(--txt-s)"><?php echo htmlspecialchars($ex['reason']??'—'); ?></td>
                <td>
                    <?php if($expired): ?>
                    <span class="badge bg-red">Expirée</span>
                    <?php elseif($ex['expires_at']): ?>
                    <span class="badge bg-yellow"><?php echo date('d/m/Y',strtotime($ex['expires_at'])); ?></span>
                    <?php else: ?>
                    <span class="badge bg-green">Permanente</span>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="POST" style="margin:0" onsubmit="return confirm('Supprimer l\'exonération ?')">
                        <input type="hidden" name="action" value="delete_exemption">
                        <input type="hidden" name="exemption_id" value="<?php echo $ex['id']; ?>">
                        <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ── COLONNE DROITE : État fiscal par entreprise ─────────────── -->
<div>
    <div class="card-title" style="margin-bottom:1rem"><i class="fas fa-file-invoice-dollar" style="color:var(--gov)"></i> État fiscal par entreprise</div>
    <div class="tbl-wrap">
        <div style="max-height:700px;overflow-y:auto">
        <?php foreach($fiscalStatus as $fs):
            $col = $fs['faction_color'];
            $tauxEff = $fs['benefice_brut'] > 0 ? round($fs['impot_du']/$fs['benefice_brut']*100,1) : 0;
        ?>
        <div class="fiscal-row">
            <div style="width:32px;height:32px;border-radius:8px;background:<?php echo $col; ?>22;color:<?php echo $col; ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.8rem">
                <i class="fas fa-building"></i>
            </div>
            <div style="flex:1;min-width:0">
                <div style="font-weight:700;font-size:.82rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                    <?php echo htmlspecialchars($fs['faction_name']); ?>
                    <?php if($fs['has_custom_rate']): ?><span class="badge bg-blue" style="margin-left:.3rem;font-size:.6rem">barème spécif.</span><?php endif; ?>
                </div>
                <div style="font-size:.7rem;color:var(--txt-m)">
                    Bén. brut: <strong>$<?php echo number_format($fs['benefice_brut'],0,'.',','); ?></strong>
                </div>
            </div>
            <div style="text-align:right;flex-shrink:0">
                <?php if($fs['is_exempt']): ?>
                <span class="badge bg-yellow">Exonérée</span>
                <?php else: ?>
                <div class="mono txt-gold" style="font-weight:700;font-size:.875rem">$<?php echo number_format($fs['impot_du'],0,'.',','); ?></div>
                <div style="font-size:.68rem;color:var(--txt-m)">taux eff. <?php echo $tauxEff; ?>%</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if(empty($fiscalStatus)): ?>
        <div style="text-align:center;padding:3rem;color:var(--txt-m)">Aucune donnée fiscale</div>
        <?php endif; ?>
        </div>
    </div>

    <!-- Total impôts à percevoir -->
    <?php $totalImpots = array_sum(array_column($fiscalStatus,'impot_du')); ?>
    <div style="background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.25);border-radius:10px;padding:1rem 1.25rem;margin-top:1rem;display:flex;justify-content:space-between;align-items:center">
        <div style="font-size:.78rem;font-weight:700;color:#fcd34d"><i class="fas fa-coins" style="margin-right:.5rem"></i>Total impôts à percevoir</div>
        <div class="mono txt-gold" style="font-size:1.35rem;font-weight:700">$<?php echo number_format($totalImpots,2,'.',','); ?></div>
    </div>
</div>
</div>

<!-- MODAL MODIFIER BARÈME -->
<div id="editRateModal" class="modal-ov hidden">
    <div class="modal">
        <div class="modal-hd">
            <h2><i class="fas fa-scale-balanced"></i> Modifier le barème</h2>
            <button class="modal-cl" onclick="Gov.closeModal('editRateModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-bd">
                <input type="hidden" name="action" value="update_rate">
                <input type="hidden" name="faction" id="er_faction">
                <div class="form-grid" style="margin-bottom:1rem">
                    <div class="form-group form-full">
                        <label class="form-label">Libellé du barème</label>
                        <input type="text" name="label" id="er_label" class="form-input" placeholder="Ex: Barème AMMUNATION">
                    </div>
                </div>
                <div style="background:var(--bg-ov);border-radius:9px;padding:1rem;margin-bottom:.75rem">
                    <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m);margin-bottom:.75rem">Tranche 1 (taux le plus bas)</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Plafond ($)</label>
                            <input type="number" name="t1_max" id="er_t1_max" class="form-input" step="1000" min="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Taux (%)</label>
                            <input type="number" name="t1_rate" id="er_t1_rate" class="form-input" step="0.5" min="0" max="100">
                        </div>
                    </div>
                </div>
                <div style="background:var(--bg-ov);border-radius:9px;padding:1rem;margin-bottom:.75rem">
                    <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m);margin-bottom:.75rem">Tranche 2</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Plafond ($)</label>
                            <input type="number" name="t2_max" id="er_t2_max" class="form-input" step="1000" min="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Taux (%)</label>
                            <input type="number" name="t2_rate" id="er_t2_rate" class="form-input" step="0.5" min="0" max="100">
                        </div>
                    </div>
                </div>
                <div style="background:var(--bg-ov);border-radius:9px;padding:1rem;margin-bottom:.75rem">
                    <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m);margin-bottom:.75rem">Tranche 3</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Plafond ($)</label>
                            <input type="number" name="t3_max" id="er_t3_max" class="form-input" step="1000" min="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Taux (%)</label>
                            <input type="number" name="t3_rate" id="er_t3_rate" class="form-input" step="0.5" min="0" max="100">
                        </div>
                    </div>
                </div>
                <div style="background:var(--bg-ov);border-radius:9px;padding:1rem">
                    <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m);margin-bottom:.75rem">Tranche 4 (taux marginal supérieur)</div>
                    <div class="form-group" style="max-width:200px">
                        <label class="form-label">Taux (%)</label>
                        <input type="number" name="t4_rate" id="er_t4_rate" class="form-input" step="0.5" min="0" max="100">
                    </div>
                </div>
            </div>
            <div class="modal-ft">
                <button type="button" class="btn btn-ghost" onclick="Gov.closeModal('editRateModal')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL NOUVEAU BARÈME SPÉCIFIQUE -->
<div id="addRateModal" class="modal-ov hidden">
    <div class="modal modal-sm">
        <div class="modal-hd">
            <h2><i class="fas fa-plus-circle"></i> Barème spécifique</h2>
            <button class="modal-cl" onclick="Gov.closeModal('addRateModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-bd">
                <input type="hidden" name="action" value="update_rate">
                <div class="form-group" style="margin-bottom:1rem">
                    <label class="form-label">Entreprise *</label>
                    <select name="faction" class="form-select" required>
                        <option value="">Sélectionner…</option>
                        <?php foreach($allFactions as $af): ?>
                        <option value="<?php echo $af['faction_key']; ?>"><?php echo htmlspecialchars($af['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:1rem">
                    <label class="form-label">Libellé</label>
                    <input type="text" name="label" class="form-input" placeholder="Ex: Barème AMMUNATION">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                    <div class="form-group"><label class="form-label">T1 plafond</label><input type="number" name="t1_max" class="form-input" value="50000" step="1000"></div>
                    <div class="form-group"><label class="form-label">T1 taux %</label><input type="number" name="t1_rate" class="form-input" value="0" step="0.5"></div>
                    <div class="form-group"><label class="form-label">T2 plafond</label><input type="number" name="t2_max" class="form-input" value="100000" step="1000"></div>
                    <div class="form-group"><label class="form-label">T2 taux %</label><input type="number" name="t2_rate" class="form-input" value="10" step="0.5"></div>
                    <div class="form-group"><label class="form-label">T3 plafond</label><input type="number" name="t3_max" class="form-input" value="500000" step="1000"></div>
                    <div class="form-group"><label class="form-label">T3 taux %</label><input type="number" name="t3_rate" class="form-input" value="15" step="0.5"></div>
                    <div class="form-group form-full"><label class="form-label">T4 taux % (marginal supérieur)</label><input type="number" name="t4_rate" class="form-input" value="20" step="0.5"></div>
                </div>
            </div>
            <div class="modal-ft">
                <button type="button" class="btn btn-ghost" onclick="Gov.closeModal('addRateModal')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL EXONÉRATION -->
<div id="addExemptModal" class="modal-ov hidden">
    <div class="modal modal-sm">
        <div class="modal-hd">
            <h2><i class="fas fa-shield-halved"></i> Exonérer une entreprise</h2>
            <button class="modal-cl" onclick="Gov.closeModal('addExemptModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-bd">
                <input type="hidden" name="action" value="add_exemption">
                <div class="form-group" style="margin-bottom:1rem">
                    <label class="form-label">Entreprise *</label>
                    <select name="faction" class="form-select" required>
                        <option value="">Sélectionner…</option>
                        <?php foreach($allFactions as $af): ?>
                        <option value="<?php echo $af['faction_key']; ?>"><?php echo htmlspecialchars($af['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:1rem">
                    <label class="form-label">Motif</label>
                    <textarea name="reason" class="form-textarea" placeholder="Raison de l'exonération…"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Date d'expiration (vide = permanente)</label>
                    <input type="date" name="expires_at" class="form-input">
                </div>
            </div>
            <div class="modal-ft">
                <button type="button" class="btn btn-ghost" onclick="Gov.closeModal('addExemptModal')">Annuler</button>
                <button type="submit" class="btn btn-warning"><i class="fas fa-shield-halved"></i> Exonérer</button>
            </div>
        </form>
    </div>
</div>

<script>
function openRateEdit(r) {
    document.getElementById('er_faction').value   = r.faction;
    document.getElementById('er_label').value     = r.label || r.faction;
    document.getElementById('er_t1_max').value    = r.tranche_1_max;
    document.getElementById('er_t1_rate').value   = r.tranche_1_rate;
    document.getElementById('er_t2_max').value    = r.tranche_2_max;
    document.getElementById('er_t2_rate').value   = r.tranche_2_rate;
    document.getElementById('er_t3_max').value    = r.tranche_3_max;
    document.getElementById('er_t3_rate').value   = r.tranche_3_rate;
    document.getElementById('er_t4_rate').value   = r.tranche_4_rate;
    Gov.openModal('editRateModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>