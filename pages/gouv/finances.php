<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = 'Finances des Entreprises';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

// Recalculer toutes les finances
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='recalculate_all') {
    try {
        $factions = $db->query("SELECT DISTINCT faction FROM company_finances")->fetchAll(\PDO::FETCH_COLUMN);
        $db->beginTransaction();
        foreach ($factions as $fk) {
            $ca  = (float)$db->prepare("SELECT COALESCE(SUM(final_amount),0) FROM company_invoices WHERE faction=? AND status='paid'")->execute([$fk]) ? $db->query("SELECT COALESCE(SUM(final_amount),0) FROM company_invoices WHERE faction='$fk' AND status='paid'")->fetchColumn() : 0;
            $ch  = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM company_expenses WHERE faction='$fk' AND is_tax_deductible=1")->fetchColumn();

            // Récupérer barème spécifique ou défaut
            $rate = $db->prepare("SELECT * FROM gouv_tax_rates WHERE faction=? OR faction='_DEFAULT_' ORDER BY CASE WHEN faction=? THEN 0 ELSE 1 END LIMIT 1");
            $rate->execute([$fk, $fk]);
            $r = $rate->fetch() ?: ['tranche_1_max'=>50000,'tranche_1_rate'=>0,'tranche_2_max'=>100000,'tranche_2_rate'=>10,'tranche_3_max'=>500000,'tranche_3_rate'=>15,'tranche_4_rate'=>20];

            // Vérifier exonération
            $exempt = $db->prepare("SELECT 1 FROM gouv_exemptions WHERE faction=? AND (expires_at IS NULL OR expires_at>=CURDATE())");
            $exempt->execute([$fk]); $isExempt = (bool)$exempt->fetchColumn();

            $b_brut = $ca - $ch;
            $impot = 0;
            if (!$isExempt && $b_brut > 0) {
                $t1 = min($b_brut, (float)$r['tranche_1_max']);
                $impot += $t1 * $r['tranche_1_rate'] / 100;
                if ($b_brut > $r['tranche_1_max']) {
                    $t2 = min($b_brut - $r['tranche_1_max'], (float)$r['tranche_2_max'] - (float)$r['tranche_1_max']);
                    $impot += $t2 * $r['tranche_2_rate'] / 100;
                }
                if ($b_brut > $r['tranche_2_max']) {
                    $t3 = min($b_brut - $r['tranche_2_max'], (float)$r['tranche_3_max'] - (float)$r['tranche_2_max']);
                    $impot += $t3 * $r['tranche_3_rate'] / 100;
                }
                if ($b_brut > $r['tranche_3_max']) {
                    $impot += ($b_brut - $r['tranche_3_max']) * $r['tranche_4_rate'] / 100;
                }
            }
            $b_net = $b_brut - $impot;
            $treso = $b_net * 0.30;

            $db->prepare("
                INSERT INTO company_finances (faction,ca_brut,charges,benefice_brut,impot_du,benefice_net,tresorerie)
                VALUES (?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE ca_brut=VALUES(ca_brut),charges=VALUES(charges),
                benefice_brut=VALUES(benefice_brut),impot_du=VALUES(impot_du),
                benefice_net=VALUES(benefice_net),tresorerie=VALUES(tresorerie)
            ")->execute([$fk, $ca, $ch, $b_brut, $impot, $b_net, $treso]);
        }
        $db->commit();
        $msg = 'Toutes les finances ont été recalculées.'; $msgType='ok';
    } catch(Exception $e) {
        if($db->inTransaction()) $db->rollBack();
        $msg = 'Erreur : '.$e->getMessage(); $msgType='err';
    }
}

// Charger toutes les finances avec infos faction
$finances = $db->query("
    SELECT cf.*,
           COALESCE(f.name, cf.faction) AS faction_name,
           COALESCE(f.color,'#3b82f6')  AS faction_color,
           COALESCE(f.icon,'building')  AS faction_icon,
           (SELECT 1 FROM gouv_exemptions ge
            WHERE ge.faction=cf.faction
              AND (ge.expires_at IS NULL OR ge.expires_at>=CURDATE()) LIMIT 1) AS is_exempt
    FROM company_finances cf
    LEFT JOIN factions f ON f.faction_key COLLATE utf8mb4_unicode_ci = cf.faction
    ORDER BY cf.ca_brut DESC
")->fetchAll();

// Totaux
$totCA = array_sum(array_column($finances,'ca_brut'));
$totCH = array_sum(array_column($finances,'charges'));
$totBB = array_sum(array_column($finances,'benefice_brut'));
$totIMP= array_sum(array_column($finances,'impot_du'));
$totBN = array_sum(array_column($finances,'benefice_net'));
$totTR = array_sum(array_column($finances,'tresorerie'));

include __DIR__ . '/includes/header.php';
?>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>">
    <i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo $msg; ?>
</div>
<?php endif; ?>

<!-- Actions -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.4rem;flex-wrap:wrap;gap:.75rem">
    <div style="font-size:.82rem;color:var(--txt-m)">
        <strong style="color:var(--txt)"><?php echo count($finances); ?></strong> entreprise(s) enregistrées
    </div>
    <form method="POST" onsubmit="return confirm('Recalculer les finances de TOUTES les entreprises via les factures et dépenses réelles ?')">
        <input type="hidden" name="action" value="recalculate_all">
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-calculator"></i> Recalculer toutes les finances
        </button>
    </form>
</div>

<!-- Totaux globaux -->
<div style="background:var(--bg-e);border:2px solid rgba(29,78,216,.3);border-radius:12px;padding:1.25rem 1.5rem;margin-bottom:1.4rem">
    <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m);margin-bottom:.9rem">
        <i class="fas fa-sigma" style="color:var(--gov);margin-right:.4rem"></i>Totaux consolidés — toutes entreprises
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem">
        <?php
        $tots = [
            ['CA Brut',      $totCA,  'txt-blue', 'fa-chart-line'],
            ['Charges',      $totCH,  'txt-red',  'fa-minus-circle'],
            ['Bén. Brut',    $totBB,  '',         'fa-equals'],
            ['Impôts Dus',   $totIMP, 'txt-gold',  'fa-scale-balanced'],
            ['Bén. Net',     $totBN,  'txt-green', 'fa-trophy'],
            ['Trésoreries',  $totTR,  '',          'fa-piggy-bank'],
        ];
        foreach($tots as [$lbl,$val,$cls,$ico]):?>
        <div style="text-align:center">
            <div style="font-size:.65rem;color:var(--txt-m);text-transform:uppercase;letter-spacing:.07em;margin-bottom:.25rem">
                <i class="fas <?php echo $ico; ?>" style="margin-right:.3rem;opacity:.7"></i><?php echo $lbl; ?>
            </div>
            <div class="mono <?php echo $cls; ?>" style="font-size:1.05rem;font-weight:700">
                $<?php echo number_format($val,2,'.',','); ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Table détaillée -->
<div class="tbl-wrap">
    <table class="tbl">
        <thead>
            <tr>
                <th>Entreprise</th>
                <th>CA Brut</th>
                <th>Charges</th>
                <th>Bén. Brut</th>
                <th>Impôts</th>
                <th>Bén. Net</th>
                <th>Trésorerie</th>
                <th>Taux eff.</th>
                <th>Statut fiscal</th>
                <th>Màj</th>
            </tr>
        </thead>
        <tbody>
        <?php if(empty($finances)): ?>
        <tr><td colspan="10" style="text-align:center;padding:3rem;color:var(--txt-m)">Aucune donnée financière</td></tr>
        <?php else: ?>
        <?php foreach($finances as $f):
            $tauxEff = $f['benefice_brut'] > 0 ? ($f['impot_du'] / $f['benefice_brut'] * 100) : 0;
            $col = $f['faction_color'];
        ?>
        <tr>
            <td>
                <div style="display:flex;align-items:center;gap:.6rem">
                    <div style="width:28px;height:28px;border-radius:7px;background:<?php echo $col; ?>22;color:<?php echo $col; ?>;display:flex;align-items:center;justify-content:center;font-size:.75rem;flex-shrink:0">
                        <i class="fas fa-<?php echo htmlspecialchars($f['faction_icon']); ?>"></i>
                    </div>
                    <div>
                        <div style="font-weight:700;font-size:.82rem"><?php echo htmlspecialchars($f['faction_name']); ?></div>
                        <div style="font-size:.68rem;color:var(--txt-m);font-family:monospace"><?php echo $f['faction']; ?></div>
                    </div>
                </div>
            </td>
            <td class="mono txt-blue">$<?php echo number_format($f['ca_brut'],2,'.',','); ?></td>
            <td class="mono txt-red">$<?php echo number_format($f['charges'],2,'.',','); ?></td>
            <td class="mono">$<?php echo number_format($f['benefice_brut'],2,'.',','); ?></td>
            <td class="mono txt-gold">$<?php echo number_format($f['impot_du'],2,'.',','); ?></td>
            <td class="mono txt-green">$<?php echo number_format($f['benefice_net'],2,'.',','); ?></td>
            <td class="mono" style="color:var(--txt-s)">$<?php echo number_format($f['tresorerie'],2,'.',','); ?></td>
            <td>
                <span class="badge <?php echo $tauxEff>15?'bg-red':($tauxEff>8?'bg-yellow':'bg-green'); ?>">
                    <?php echo number_format($tauxEff,1); ?>%
                </span>
            </td>
            <td>
                <?php if($f['is_exempt']): ?>
                <span class="badge bg-yellow"><i class="fas fa-shield-halved"></i> Exonérée</span>
                <?php elseif($f['impot_du']>0): ?>
                <span class="badge bg-red"><i class="fas fa-scale-balanced"></i> Imposée</span>
                <?php else: ?>
                <span class="badge bg-gray">—</span>
                <?php endif; ?>
            </td>
            <td style="font-size:.72rem;color:var(--txt-m)">
                <?php echo $f['last_updated'] ? date('d/m/y H:i', strtotime($f['last_updated'])) : '—'; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>