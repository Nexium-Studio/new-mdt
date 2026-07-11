<?php
/**
 * Dashboard Builder — couche overlay (lecture/seed + écriture).
 *
 * dashboard_overlay_apply() est appelée par mdt_panel_catalog_categories()
 * pour superposer ordre / catégorie / description issus de la DB par-dessus
 * le catalogue hardcodé, SANS jamais casser l'existant : si les tables sont
 * absentes ou en erreur, on retourne le tableau d'entrée inchangé.
 *
 * Les fonctions d'écriture (upsert / reorder) sont utilisées par les
 * endpoints api/dashboard/*.php (phases ultérieures).
 */

if (!function_exists('dashboard_overlay_apply')) {
    /**
     * Superpose l'overlay DB sur les catégories hardcodées.
     *
     * @param array $categories  ['Label' => [ ['key'=>..., ...], ... ]]
     * @return array même forme, ré-ordonnée / ré-catégorisée
     */
    function dashboard_overlay_apply(PDO $db, array $categories, bool $includeHidden = false): array
    {
        // 1. Charger les métadonnées de catégories. Table absente → pas d'overlay.
        try {
            $catRows = $db->query(
                "SELECT category_key, label, sort_order, is_hidden FROM dashboard_categories"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return $categories; // fallback intégral : comportement actuel
        }

        $catMeta = [];
        foreach ($catRows as $cr) {
            $catMeta[(string) $cr['category_key']] = [
                'label'      => (string) $cr['label'],
                'sort_order' => (int) $cr['sort_order'],
                'is_hidden'  => (int) $cr['is_hidden'],
            ];
        }

        // 2. Seed lazy idempotent : adopter les catégories hardcodées absentes.
        $toSeed = [];
        $pos = 0;
        foreach ($categories as $label => $_panels) {
            $label = (string) $label;
            if (!array_key_exists($label, $catMeta)) {
                $toSeed[] = [$label, $pos];
            }
            $pos++;
        }
        if (!empty($toSeed)) {
            try {
                $ins = $db->prepare(
                    "INSERT IGNORE INTO dashboard_categories
                       (category_key, label, sort_order, is_custom)
                     VALUES (?, ?, ?, 0)"
                );
                foreach ($toSeed as $s) {
                    $ins->execute([$s[0], $s[0], $s[1]]);
                    $catMeta[$s[0]] = ['label' => $s[0], 'sort_order' => $s[1], 'is_hidden' => 0];
                }
            } catch (Exception $e) {
                // seed best-effort : on continue avec ce qu'on a
            }
        }

        // 3. Charger l'overlay des panels.
        try {
            $panRows = $db->query(
                "SELECT panel_key, category_key, description, sort_order FROM dashboard_panel_overlay"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $panRows = [];
        }
        $panOverlay = [];
        foreach ($panRows as $pr) {
            $panOverlay[(string) $pr['panel_key']] = $pr;
        }

        // 4. Aplatir + appliquer l'overlay (re-catégorisation, description, ordre).
        $flat = [];
        $appear = 0;
        foreach ($categories as $label => $panels) {
            if (!is_array($panels)) {
                continue;
            }
            foreach ($panels as $p) {
                if (!isset($p['key'])) {
                    continue;
                }
                $key  = (string) $p['key'];
                $cat  = (string) $label;
                $sort = $appear; // défaut : ordre d'apparition (préserve l'existant)

                if (isset($panOverlay[$key])) {
                    $ov = $panOverlay[$key];
                    $ovCat = (string) ($ov['category_key'] ?? '');
                    if ($ovCat !== '' && isset($catMeta[$ovCat])) {
                        $cat = $ovCat; // re-catégorisation (via formulaire d'édition)
                    }
                    $ovDesc = trim((string) ($ov['description'] ?? ''));
                    if ($ovDesc !== '') {
                        $p['description'] = $ovDesc;
                    }
                    // sort_order explicite ; départage par apparition pour rester déterministe
                    $sort = ((int) $ov['sort_order'] * 100000) + $appear;
                }

                $p['_cat']  = $cat;
                $p['_sort'] = $sort;
                $flat[] = $p;
                $appear++;
            }
        }

        // 5. Re-grouper par catégorie.
        $regrouped = [];
        foreach ($flat as $p) {
            $regrouped[$p['_cat']][] = $p;
        }

        // 6. Trier les panels intra-catégorie par _sort.
        foreach ($regrouped as $cat => $panels) {
            usort($panels, static function ($a, $b) {
                return $a['_sort'] <=> $b['_sort'];
            });
            foreach ($panels as &$pp) {
                unset($pp['_cat'], $pp['_sort']);
            }
            unset($pp);
            $regrouped[$cat] = $panels;
        }

        // 7. Ordonner les catégories par sort_order (fallback : ordre d'origine).
        //    On trie sur les category_key, mais on indexe la sortie sur le LABEL
        //    éditable (sinon renommer une catégorie n'aurait aucun effet visible).
        $orderedKeys = array_keys($regrouped);
        usort($orderedKeys, static function ($a, $b) use ($catMeta) {
            $sa = $catMeta[$a]['sort_order'] ?? 9999;
            $sb = $catMeta[$b]['sort_order'] ?? 9999;
            if ($sa === $sb) {
                return strcmp((string) $a, (string) $b);
            }
            return $sa <=> $sb;
        });

        // 8. Reconstruire, indexé par label, en filtrant les masquées (sauf builder).
        $final = [];
        foreach ($orderedKeys as $catKey) {
            if (!$includeHidden && !empty($catMeta[$catKey]['is_hidden'])) {
                continue;
            }
            $display = $catMeta[$catKey]['label'] ?? $catKey;
            if ($display === '') {
                $display = $catKey;
            }
            // Collision de labels éventuelle : fusionner sous le même intitulé.
            if (isset($final[$display])) {
                $final[$display] = array_merge($final[$display], $regrouped[$catKey]);
            } else {
                $final[$display] = $regrouped[$catKey];
            }
        }

        return $final;
    }
}

// ─────────────────────────────────────────────────────────────────────
// Helpers d'écriture (utilisés par api/dashboard/*.php — phases 3+)
// ─────────────────────────────────────────────────────────────────────

if (!function_exists('dashboard_overlay_upsert_category')) {
    function dashboard_overlay_upsert_category(PDO $db, string $categoryKey, array $fields): void
    {
        $cols = ['label', 'description', 'color', 'icon', 'sort_order', 'is_custom', 'is_hidden'];
        $set  = [];
        $vals = [];
        foreach ($cols as $c) {
            if (array_key_exists($c, $fields)) {
                $set[]  = "`$c` = ?";
                $vals[] = $fields[$c];
            }
        }
        if (empty($set)) {
            return;
        }
        // INSERT … ON DUPLICATE KEY UPDATE, en s'appuyant sur l'unicité de category_key.
        $insCols = array_merge(['category_key'], array_keys(array_intersect_key($fields, array_flip($cols))));
        $place   = implode(',', array_fill(0, count($insCols), '?'));
        $insVals = array_merge([$categoryKey], array_values(array_intersect_key($fields, array_flip($cols))));
        $update  = implode(', ', $set);
        $st = $db->prepare(
            "INSERT INTO dashboard_categories (`" . implode('`,`', $insCols) . "`) VALUES ($place)
             ON DUPLICATE KEY UPDATE $update"
        );
        $st->execute(array_merge($insVals, $vals));
    }
}

if (!function_exists('dashboard_overlay_reorder_categories')) {
    /** @param string[] $orderedKeys */
    function dashboard_overlay_reorder_categories(PDO $db, array $orderedKeys): void
    {
        $db->beginTransaction();
        try {
            $st = $db->prepare("UPDATE dashboard_categories SET sort_order = ? WHERE category_key = ?");
            foreach (array_values($orderedKeys) as $i => $key) {
                $st->execute([$i, (string) $key]);
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('dashboard_overlay_set_panel')) {
    /**
     * Upsert ciblé de l'overlay d'un panel. Chaque paramètre à null = NON modifié
     * (on ne réécrit jamais une colonne qu'on ne touche pas — ex. ne pas remettre
     * sort_order à 0 en éditant juste le nom). categoryKey === '' = supprime l'override.
     */
    function dashboard_overlay_set_panel(PDO $db, string $panelKey, ?string $categoryKey, ?string $description, ?int $sortOrder): void
    {
        $db->prepare("INSERT IGNORE INTO dashboard_panel_overlay (panel_key) VALUES (?)")->execute([$panelKey]);
        if ($categoryKey !== null) {
            $db->prepare("UPDATE dashboard_panel_overlay SET category_key = ? WHERE panel_key = ?")
               ->execute([($categoryKey === '' ? null : $categoryKey), $panelKey]);
        }
        if ($description !== null) {
            $db->prepare("UPDATE dashboard_panel_overlay SET description = ? WHERE panel_key = ?")
               ->execute([(string) $description, $panelKey]);
        }
        if ($sortOrder !== null) {
            $db->prepare("UPDATE dashboard_panel_overlay SET sort_order = ? WHERE panel_key = ?")
               ->execute([(int) $sortOrder, $panelKey]);
        }
    }
}

if (!function_exists('dashboard_overlay_reorder_panels')) {
    /**
     * Réordonne les panels d'une catégorie.
     * @param string[] $orderedPanelKeys
     */
    function dashboard_overlay_reorder_panels(PDO $db, string $categoryKey, array $orderedPanelKeys): void
    {
        $db->beginTransaction();
        try {
            $st = $db->prepare(
                "INSERT INTO dashboard_panel_overlay (panel_key, category_key, sort_order)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE category_key = VALUES(category_key), sort_order = VALUES(sort_order)"
            );
            foreach (array_values($orderedPanelKeys) as $i => $key) {
                $st->execute([(string) $key, ($categoryKey === '' ? null : $categoryKey), $i]);
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
