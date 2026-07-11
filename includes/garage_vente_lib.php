<?php
/**
 * Atelier / vente véhicule — calcul mods (garage_mods), facture pending, finalisation au paiement.
 */

if (!defined('GARAGE_VENTE_META_MARKER')) {
    define('GARAGE_VENTE_META_MARKER', "\n\n__MDT_GARAGE_META__\n");
}

/**
 * Catégories affichées (enum garage_vehicles + Autre).
 *
 * @return list<array{value:string,label:string}>
 */
function garage_vente_vehicle_categories(): array
{
    $labels = [
        'Moto', 'Sportives', 'Sportives Classiques', 'Muscle', 'Super', 'Off Road',
        'Sedan', 'Modèle', 'Vans', 'Utilitaire', 'Coupé', 'Compact', 'Autre',
    ];
    $out = [];
    foreach ($labels as $l) {
        $out[] = ['value' => $l, 'label' => $l];
    }
    return $out;
}

/**
 * @return array<string, array<string, list<array<string,mixed>>>>
 */
function garage_vente_load_mods_grouped(PDO $db): array
{
    $stmt = $db->query('SELECT id, category, subcategory, name, percentage FROM garage_mods ORDER BY category, subcategory, name');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $grouped = [];
    foreach ($rows as $r) {
        $cat = (string) $r['category'];
        $sub = (string) $r['subcategory'];
        if (!isset($grouped[$cat])) {
            $grouped[$cat] = [];
        }
        if (!isset($grouped[$cat][$sub])) {
            $grouped[$cat][$sub] = [];
        }
        $grouped[$cat][$sub][] = [
            'id'          => (int) $r['id'],
            'name'        => (string) $r['name'],
            'percentage'  => (float) $r['percentage'],
            'subcategory' => $sub,
        ];
    }
    return $grouped;
}

/**
 * @param list<int> $modIds
 * @return array{sum_pct:float, lines:list<array{id:int,name:string,percentage:float}>, invalid:bool}
 */
function garage_vente_resolve_mods(PDO $db, array $modIds): array
{
    $modIds = array_values(array_unique(array_filter(array_map('intval', $modIds))));
    if ($modIds === []) {
        return ['sum_pct' => 0.0, 'lines' => [], 'invalid' => false];
    }
    $placeholders = implode(',', array_fill(0, count($modIds), '?'));
    $stmt = $db->prepare("SELECT id, name, percentage FROM garage_mods WHERE id IN ($placeholders)");
    $stmt->execute($modIds);
    $found = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($found) !== count($modIds)) {
        return ['sum_pct' => 0.0, 'lines' => [], 'invalid' => true];
    }
    $sum = 0.0;
    $lines = [];
    foreach ($found as $r) {
        $pct = (float) $r['percentage'];
        $sum += $pct;
        $lines[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'percentage' => $pct];
    }
    return ['sum_pct' => $sum, 'lines' => $lines, 'invalid' => false];
}

/**
 * @return array|null Parsed meta or null
 */
function garage_vente_parse_meta_from_description(string $description): ?array
{
    $pos = strpos($description, GARAGE_VENTE_META_MARKER);
    if ($pos === false) {
        return null;
    }
    $json = trim(substr($description, $pos + strlen(GARAGE_VENTE_META_MARKER)));
    if ($json === '') {
        return null;
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

function garage_vente_strip_meta(string $description): string
{
    $pos = strpos($description, GARAGE_VENTE_META_MARKER);
    if ($pos === false) {
        return $description;
    }
    return rtrim(substr($description, 0, $pos));
}

/**
 * Finalise CA / stock / ventes quand une facture passe à « paid » (depuis facturation.php).
 *
 * @param array<string,mixed> $invoiceRow company_invoices row
 */
function garage_vente_finalize_paid_invoice(PDO $db, string $faction, array $invoiceRow): void
{
    $meta = garage_vente_parse_meta_from_description((string) ($invoiceRow['description'] ?? ''));
    $finalAmount = (float) ($invoiceRow['final_amount'] ?? 0);
    $employeeId = (int) ($invoiceRow['employee_id'] ?? 0);
    $customerName = trim((string) ($invoiceRow['customer_name'] ?? ''));
    $useBennys = is_array($meta) && !empty($meta['use_bennys_sales']);

    if ($meta && isset($meta['stock']) && is_array($meta['stock'])) {
        foreach ($meta['stock'] as $line) {
            $name = trim((string) ($line['name'] ?? ''));
            $qty = (int) ($line['qty'] ?? 0);
            if ($name === '' || $qty < 1) {
                continue;
            }
            $stmt = $db->prepare('SELECT stock_quantity FROM company_stock WHERE faction = ? AND item_name = ?');
            $stmt->execute([$faction, $name]);
            $q = $stmt->fetchColumn();
            if ($q !== false) {
                if ((int) $q < $qty) {
                    throw new RuntimeException('Stock insuffisant pour : ' . $name);
                }
                $db->prepare('UPDATE company_stock SET stock_quantity = stock_quantity - ? WHERE faction = ? AND item_name = ?')
                   ->execute([$qty, $faction, $name]);
            }
        }
    }

    $db->prepare('
        UPDATE company_employees
        SET individual_ca = individual_ca + ?,
            calculated_salary = (individual_ca + ?) * salary_percentage / 100
        WHERE user_id = ? AND faction = ?
    ')->execute([$finalAmount, $finalAmount, $employeeId, $faction]);

    $db->prepare('INSERT IGNORE INTO company_finances (faction, ca_brut) VALUES (?, 0)')->execute([$faction]);
    $db->prepare('UPDATE company_finances SET ca_brut = ca_brut + ? WHERE faction = ?')->execute([$finalAmount, $faction]);

    $week = date('Y-\WW');

    if ($useBennys) {
        $desc = garage_vente_strip_meta((string) ($invoiceRow['description'] ?? ''));
        $db->prepare('INSERT INTO bennys_sales (employee_id, amount, customer, description, week) VALUES (?,?,?,?,?)')
           ->execute([$employeeId, $finalAmount, $customerName, $desc, $week]);
    } else {
        $db->prepare('INSERT INTO company_sales (faction, employee_id, amount, customer, week) VALUES (?,?,?,?,?)')
           ->execute([$faction, $employeeId, $finalAmount, $customerName, $week]);
    }
}

/**
 * Traite POST action=sell_workshop — facture unique (atelier + panier stock optionnel), statut pending.
 *
 * @param array<string,mixed> $cfg faction, use_bennys_sales, POST
 * @return array{ok:bool,message:string,type:string}
 */
function garage_vente_process_workshop_sale(PDO $db, array $cfg): array
{
    $faction = (string) ($cfg['faction'] ?? '');
    $employeeId = (int) ($cfg['employee_id'] ?? 0);
    $useBennys = !empty($cfg['use_bennys_sales']);
    $post = $cfg['post'] ?? $_POST;

    try {
        $customerName = trim((string) ($post['customer_name'] ?? ''));
        if ($customerName === '') {
            throw new InvalidArgumentException('Nom du client requis.');
        }

        $plate = trim((string) ($post['vehicle_plate'] ?? ''));
        $model = trim((string) ($post['vehicle_model'] ?? ''));
        $category = trim((string) ($post['vehicle_category'] ?? ''));
        if ($category === 'Autre') {
            $otherCat = trim((string) ($post['vehicle_category_other'] ?? ''));
            if ($otherCat !== '') {
                $category = $otherCat;
            }
        }

        $cart = json_decode((string) ($post['cart_data'] ?? '[]'), true);
        if (!is_array($cart)) {
            $cart = [];
        }

        $stockSubtotal = 0.0;
        $stockLines = [];
        foreach ($cart as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            $price = (float) ($item['price'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 0);
            if ($name === '' || $qty < 1) {
                continue;
            }
            $stmt = $db->prepare('SELECT stock_quantity FROM company_stock WHERE faction = ? AND item_name = ?');
            $stmt->execute([$faction, $name]);
            $sq = $stmt->fetchColumn();
            if ($sq === false) {
                continue;
            }
            if ((int) $sq < $qty) {
                throw new InvalidArgumentException('Stock insuffisant pour : ' . $name);
            }
            $lineTot = $price * $qty;
            $stockSubtotal += $lineTot;
            $stockLines[] = ['name' => $name, 'qty' => $qty, 'unit_price' => $price];
        }

        $hasVehicle = ($plate !== '' && $model !== '' && $category !== '');
        $hasStock = $stockLines !== [];
        if (!$hasVehicle && !$hasStock) {
            throw new InvalidArgumentException('Renseignez le véhicule (plaque, modèle, catégorie) ou ajoutez des articles au panier.');
        }

        $basePrice = (float) ($post['base_price'] ?? 0);
        if ($basePrice < 0) {
            throw new InvalidArgumentException('Prix de base invalide.');
        }

        $modIds = json_decode((string) ($post['garage_mod_ids_json'] ?? '[]'), true);
        if (!is_array($modIds)) {
            $modIds = [];
        }
        if (!$hasVehicle) {
            $modIds = [];
        }
        $resolved = garage_vente_resolve_mods($db, $modIds);
        if ($resolved['invalid']) {
            throw new InvalidArgumentException('Liste de modifications invalide (IDs inconnus).');
        }

        $sumPct = $resolved['sum_pct'];
        // Ligne atelier = uniquement le montant des mods (% du prix de base), sans additionner le prix de base.
        $garageLine = $hasVehicle ? ($basePrice * ($sumPct / 100)) : 0.0;

        $subtotal = $garageLine + $stockSubtotal;

        $adjustmentType = (string) ($post['adjustment_type'] ?? 'none');
        $adjustmentValue = (float) ($post['adjustment_value'] ?? 0);
        $adjustment = 0.0;
        if ($adjustmentType === 'discount') {
            $adjustment = -($subtotal * $adjustmentValue / 100);
        } elseif ($adjustmentType === 'increase') {
            $adjustment = ($subtotal * $adjustmentValue / 100);
        }
        $finalAmount = max(0, $subtotal + $adjustment);
        if ($finalAmount <= 0) {
            throw new InvalidArgumentException('Le montant total doit être supérieur à 0.');
        }

        $desc = '';
        if ($hasVehicle) {
            $desc .= "=== Configuration atelier ===\n";
            $desc .= 'Plaque : ' . $plate . "\n";
            $desc .= 'Modèle : ' . $model . "\n";
            $desc .= 'Catégorie : ' . $category . "\n";
            $desc .= 'Prix de base véhicule : $' . number_format($basePrice, 2, '.', '') . "\n";
            $desc .= 'Total % modifications : +' . number_format($sumPct, 2, '.', '') . "%\n";
            if ($resolved['lines']) {
                $desc .= "Détail modifications :\n";
                foreach ($resolved['lines'] as $ln) {
                    $desc .= '  · ' . $ln['name'] . ' (+' . number_format($ln['percentage'], 2) . "%)\n";
                }
            }
            $desc .= 'Montant customisation (prix de base × total % mods) : $' . number_format($garageLine, 2, '.', '') . "\n";
        } else {
            $desc .= "=== Vente articles (stock) ===\n(Aucune configuration véhicule)\n";
        }

        if ($stockLines) {
            $desc .= "\n=== Articles (stock) ===\n";
            foreach ($stockLines as $sl) {
                $desc .= '  · ' . $sl['name'] . ' ×' . $sl['qty'] . ' @ $' . number_format($sl['unit_price'], 2) . "\n";
            }
            $desc .= 'Sous-total stock : $' . number_format($stockSubtotal, 2, '.', '') . "\n";
        }

        $desc .= "\n---\n";
        $desc .= 'Sous-total : $' . number_format($subtotal, 2, '.', '') . "\n";
        if ($adjustmentType !== 'none' && $adjustmentValue > 0) {
            $desc .= ($adjustmentType === 'discount' ? 'Réduction' : 'Supplément') . ' : ' . $adjustmentValue . '% ($' . number_format(abs($adjustment), 2) . ")\n";
        }
        $desc .= 'TOTAL : $' . number_format($finalAmount, 2, '.', '') . "\n";
        $desc .= "Statut : en attente de paiement\n";

        $meta = [
            'v'               => 1,
            'faction'       => $faction,
            'use_bennys_sales' => $useBennys,
            'stock'           => $stockLines,
            'garage'          => $hasVehicle ? [
                'plate'    => $plate,
                'model'    => $model,
                'category' => $category,
                'base_price' => $basePrice,
                'mod_ids'  => array_column($resolved['lines'], 'id'),
                'mods_pct' => $sumPct,
            ] : null,
            'amounts' => [
                'garage_line' => round($garageLine, 2),
                'stock_subtotal' => round($stockSubtotal, 2),
                'subtotal' => round($subtotal, 2),
                'discount_field' => round($adjustment, 2),
                'final' => round($finalAmount, 2),
            ],
            'adjustment' => ['type' => $adjustmentType, 'value' => $adjustmentValue],
        ];

        $desc .= GARAGE_VENTE_META_MARKER . json_encode($meta, JSON_UNESCAPED_UNICODE);

        $db->prepare('
            INSERT INTO company_invoices (faction, employee_id, customer_name, amount, discount, final_amount, description, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, \'pending\')
        ')->execute([
            $faction,
            $employeeId,
            $customerName,
            $subtotal,
            $adjustment,
            $finalAmount,
            $desc,
        ]);

        return [
            'ok'      => true,
            'message' => 'Facture #' . $db->lastInsertId() . ' créée — en attente de paiement ($' . number_format($finalAmount, 2) . ').',
            'type'    => 'success',
        ];
    } catch (Throwable $e) {
        return [
            'ok'      => false,
            'message' => $e->getMessage(),
            'type'    => 'error',
        ];
    }
}

/**
 * Appeler dans facturation.php après validation, avant UPDATE du statut,
 * ou intégrer dans une transaction avec l'UPDATE.
 */
function garage_vente_on_invoice_status_change(PDO $db, string $faction, int $invoiceId, string $newStatus): void
{
    $stmt = $db->prepare('SELECT * FROM company_invoices WHERE id = ? AND faction = ?');
    $stmt->execute([$invoiceId, $faction]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new InvalidArgumentException('Facture introuvable.');
    }
    $old = (string) ($row['status'] ?? '');
    if ($old === 'pending' && $newStatus === 'paid') {
        garage_vente_finalize_paid_invoice($db, $faction, $row);
    }
}
