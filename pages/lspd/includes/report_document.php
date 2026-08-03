<?php
/**
 * pages/lspd/includes/report_document.php
 * ─────────────────────────────────────────────────────────────────────────
 * Construit le document officiel HTML d'un rapport LSPD à partir d'une ligne
 * `reports`, en réutilisant EXACTEMENT le moteur et le style des certificats
 * PPA (config/GoogleDocsGenerator.php via mdt_ppa_render_document()).
 *
 * Le document est écrit sur disque (public/documents/) ; la fonction renvoie
 * son URL publique (à stocker dans reports.document_url), ou null en cas d'échec.
 *
 * Réutilisé par : création, édition, et génération à la demande d'un rapport.
 */
if (!function_exists('lspd_build_report_document')) {

    $__mdtRoot = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 3);
    require_once $__mdtRoot . '/includes/ppa.php';            // mdt_ppa_render_document(), mdt_ppa_abs_url()
    require_once $__mdtRoot . '/includes/mdt_display_name.php'; // mdt_rp_display_name()

    function lspd_build_report_document(PDO $db, array $report): ?string
    {
        $id = (int) ($report['id'] ?? 0);
        if ($id <= 0) return null;

        $h  = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $jd = static function ($v): array { $a = json_decode((string) ($v ?? ''), true); return is_array($a) ? $a : []; };

        $officers  = array_map('intval', $jd($report['officers'] ?? ($report['officers_involved'] ?? '')));
        $suspects  = array_map('intval', $jd($report['suspects'] ?? ''));
        $civilians = array_map('intval', $jd($report['civilians'] ?? ''));
        $witnesses = array_map('intval', $jd($report['witnesses'] ?? ''));
        $vehicles  = array_map('intval', $jd($report['vehicles'] ?? ''));
        $evidence  = $jd($report['evidence'] ?? '');
        $arrest    = $jd($report['arrest_details'] ?? '');

        // ── Résolution des noms (profiles) ──
        $profileNames = static function (array $ids) use ($db): array {
            $ids = array_values(array_filter($ids));
            if (empty($ids)) return [];
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare("SELECT id, firstname, lastname FROM profiles WHERE id IN ($ph)");
            $st->execute($ids);
            $map = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $map[(int) $r['id']] = trim($r['firstname'] . ' ' . $r['lastname']);
            $out = [];
            foreach ($ids as $i) $out[] = $map[$i] ?? ('#' . $i);
            return $out;
        };
        $suspectNames = $profileNames($suspects);
        $civilNames   = $profileNames($civilians);
        $witnessNames = $profileNames($witnesses);

        // ── Officiers (users + profile pour le nom RP) ──
        $officerNames = [];
        if (!empty($officers)) {
            try {
                $ph = implode(',', array_fill(0, count($officers), '?'));
                $st = $db->prepare("SELECT u.id,u.username,u.faction_grade,pr.firstname,pr.lastname
                                    FROM users u LEFT JOIN profiles pr ON pr.id=u.profile_id WHERE u.id IN ($ph)");
                $st->execute($officers);
                $map = [];
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $nm = mdt_rp_display_name($r['firstname'] ?? '', $r['lastname'] ?? '', $r['username'] ?? '');
                    $map[(int) $r['id']] = $nm . (!empty($r['faction_grade']) ? ' (' . $r['faction_grade'] . ')' : '');
                }
                foreach ($officers as $i) $officerNames[] = $map[$i] ?? ('#' . $i);
            } catch (Throwable $e) {}
        }

        // ── Véhicules ──
        $vehLines = [];
        if (!empty($vehicles)) {
            try {
                $ph = implode(',', array_fill(0, count($vehicles), '?'));
                $st = $db->prepare("SELECT v.id,v.plate,v.model,CONCAT(p.firstname,' ',p.lastname) AS owner
                                    FROM vehicles v LEFT JOIN profiles p ON p.id=v.owner_id WHERE v.id IN ($ph)");
                $st->execute($vehicles);
                $vm = [];
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $vm[(int) $r['id']] = trim($r['plate'] . ' — ' . $r['model'] . (!empty($r['owner']) ? ' (' . $r['owner'] . ')' : ''));
                }
                foreach ($vehicles as $i) $vehLines[] = $vm[$i] ?? ('#' . $i);
            } catch (Throwable $e) {}
        }

        // ── Saisies liées au rapport ──
        $saisies = [];
        try {
            $st = $db->prepare("SELECT categorie,description,quantite,valeur_estimee,lieu_saisie,numero_evidence,statut
                                FROM lspd_saisies WHERE report_id=? ORDER BY id ASC");
            $st->execute([$id]);
            $saisies = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

        // ── Suspect principal (en-tête) ──
        $mainSuspect = null;
        if (!empty($suspects)) {
            try {
                $st = $db->prepare("SELECT firstname,lastname,avatar,date_of_birth,phone,address FROM profiles WHERE id=?");
                $st->execute([$suspects[0]]);
                $mainSuspect = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {}
        }

        // ═══ Assemblage du contenu (classes doc-* du GoogleDocsGenerator) ═══
        $content = '';

        // Bloc individu concerné
        if ($mainSuspect) {
            $av    = mdt_ppa_abs_url($mainSuspect['avatar'] ?? '');
            $avImg = $av !== '' ? '<img src="' . $h($av) . '" alt="" referrerpolicy="no-referrer" style="width:78px;height:78px;border-radius:12px;object-fit:cover;border:2px solid #e5e7eb;flex-shrink:0">' : '';
            $dob   = !empty($mainSuspect['date_of_birth']) ? date('d/m/Y', strtotime((string) $mainSuspect['date_of_birth'])) : '—';
            $content .= '<div class="doc-section"><div class="doc-section-title">Individu concerné</div>'
                . '<div style="display:flex;gap:1.2rem;align-items:flex-start">' . $avImg
                . '<div class="doc-grid2" style="flex:1">'
                . '<div class="doc-field"><div class="doc-field-label">Nom</div><div class="doc-field-value">' . $h($mainSuspect['lastname'] ?: '—') . '</div></div>'
                . '<div class="doc-field"><div class="doc-field-label">Prénom</div><div class="doc-field-value">' . $h($mainSuspect['firstname'] ?: '—') . '</div></div>'
                . '<div class="doc-field"><div class="doc-field-label">Date de naissance</div><div class="doc-field-value">' . $h($dob) . '</div></div>'
                . '<div class="doc-field"><div class="doc-field-label">Téléphone</div><div class="doc-field-value">' . $h($mainSuspect['phone'] ?: '—') . '</div></div>'
                . '<div class="doc-field" style="grid-column:1/3"><div class="doc-field-label">Adresse</div><div class="doc-field-value">' . $h($mainSuspect['address'] ?: '—') . '</div></div>'
                . '</div></div></div>';
        }

        // Informations du rapport
        $incidentAt = !empty($report['incident_at']) ? date('d/m/Y H:i', strtotime((string) $report['incident_at'])) : '—';
        $content .= '<div class="doc-section"><div class="doc-section-title">Informations du rapport</div><div class="doc-grid2">'
            . '<div class="doc-field"><div class="doc-field-label">Type</div><div class="doc-field-value">' . $h($report['type'] ?: '—') . '</div></div>'
            . '<div class="doc-field"><div class="doc-field-label">Titre</div><div class="doc-field-value">' . $h($report['title'] ?: '—') . '</div></div>'
            . '<div class="doc-field"><div class="doc-field-label">Lieu de l\'incident</div><div class="doc-field-value">' . $h($report['location'] ?? '' ?: '—') . '</div></div>'
            . '<div class="doc-field"><div class="doc-field-label">Date de l\'incident</div><div class="doc-field-value">' . $h($incidentAt) . '</div></div>'
            . '<div class="doc-field"><div class="doc-field-label">Rédigé par</div><div class="doc-field-value">' . $h($report['auteur'] ?? '' ?: '—') . '</div></div>'
            . '</div></div>';

        // Personnes impliquées
        $listOr = static function (array $arr) use ($h) { return empty($arr) ? '—' : implode(', ', array_map($h, $arr)); };
        $content .= '<div class="doc-section"><div class="doc-section-title">Personnes impliquées</div><div class="doc-grid2">'
            . '<div class="doc-field"><div class="doc-field-label">Officiers impliqués</div><div class="doc-field-value">' . $listOr($officerNames) . '</div></div>'
            . '<div class="doc-field"><div class="doc-field-label">Suspects</div><div class="doc-field-value">' . $listOr($suspectNames) . '</div></div>'
            . '<div class="doc-field"><div class="doc-field-label">Civils impliqués</div><div class="doc-field-value">' . $listOr($civilNames) . '</div></div>'
            . '<div class="doc-field"><div class="doc-field-label">Témoins</div><div class="doc-field-value">' . $listOr($witnessNames) . '</div></div>'
            . '<div class="doc-field" style="grid-column:1/3"><div class="doc-field-label">Véhicules impliqués</div><div class="doc-field-value">' . $listOr($vehLines) . '</div></div>'
            . '</div></div>';

        // Objets saisis
        if (!empty($saisies)) {
            $catLbl = ['arme' => 'Arme', 'drogue' => 'Drogue', 'vehicule' => 'Véhicule', 'argent' => 'Argent', 'bijou' => 'Bijou', 'electronique' => 'Électronique', 'document' => 'Document', 'autre' => 'Autre'];
            $rows = '';
            foreach ($saisies as $sz) {
                $val = ((float) ($sz['valeur_estimee'] ?? 0)) > 0 ? '$' . number_format((float) $sz['valeur_estimee'], 0, '.', ',') : '—';
                $rows .= '<tr><td><div class="doc-med-name">' . $h($sz['description']) . '</div>'
                    . '<div class="doc-med-sub">' . $h($catLbl[$sz['categorie']] ?? $sz['categorie']) . ($sz['numero_evidence'] ? ' · ' . $h($sz['numero_evidence']) : '') . '</div></td>'
                    . '<td>' . $h($sz['quantite'] ?: '—') . '</td>'
                    . '<td>' . $h($sz['lieu_saisie'] ?: '—') . '</td>'
                    . '<td class="doc-med-instr">' . $val . '</td></tr>';
            }
            $content .= '<div class="doc-section"><div class="doc-section-title">Objets saisis</div>'
                . '<table class="doc-med-table"><thead><tr><th>Objet</th><th>Quantité</th><th>Lieu de saisie</th><th style="text-align:right">Valeur estimée</th></tr></thead>'
                . '<tbody>' . $rows . '</tbody></table></div>';
        }

        // Description complète (HTML riche du rédacteur)
        $descRich = trim((string) ($report['description_rich'] ?? ''));
        if ($descRich !== '') {
            $content .= '<div class="doc-section"><div class="doc-section-title">Description du rapport</div><div>' . $descRich . '</div></div>';
        }

        // Informations complémentaires générées (arrest_details)
        if (!empty($arrest)) {
            $af = [];
            $push = static function ($lbl, $val) use (&$af, $h) {
                $af[] = '<div class="doc-field"><div class="doc-field-label">' . $h($lbl) . '</div><div class="doc-field-value">' . $h(($val !== '' && $val !== null) ? $val : '—') . '</div></div>';
            };
            $secteur = (string) ($arrest['secteur'] ?? '');
            $push("Moyen d'information", $arrest['moyen'] ?? '');
            $push('Localisation exacte', $arrest['localisation'] ?? '');
            $push('Secteur', $secteur !== '' ? ('Secteur ' . $secteur) : '');
            $push("Comportement à l'arrestation", $arrest['comp_arrestation'] ?? '');
            $push('Comportement au poste', $arrest['comp_poste'] ?? '');
            $push("Heure d'entrée en cellule", $arrest['cell_in'] ?? '');
            $push('Heure de sortie de cellule', $arrest['cell_out'] ?? '');
            $push('Lecture des droits Miranda', $arrest['miranda'] ?? '');
            $push('Amende payée', $arrest['amende'] ?? '');
            $push('Intervention médicale', $arrest['medical'] ?? '');
            $content .= '<div class="doc-section"><div class="doc-section-title">Informations complémentaires</div><div class="doc-grid2">' . implode('', $af) . '</div>';
            if (!empty($arrest['faits'])) {
                $content .= '<div class="doc-field" style="margin-top:.9rem"><div class="doc-field-label">Faits</div><div class="doc-field-value" style="font-weight:400;white-space:pre-line">' . $h($arrest['faits']) . '</div></div>';
            }
            $content .= '</div>';
        }

        // Pièces jointes & images
        if (!empty($evidence)) {
            $imgs = ''; $links = '';
            foreach ($evidence as $url) {
                $u = mdt_ppa_abs_url((string) $url);
                if ($u === '') continue;
                if (preg_match('/\.(jpe?g|png|gif|webp)(\?.*)?$/i', $u)) {
                    $imgs .= '<img src="' . $h($u) . '" alt="" referrerpolicy="no-referrer" style="width:100%;border-radius:8px;border:1px solid #e5e7eb">';
                } else {
                    $links .= '<div style="font-size:.85rem;margin-bottom:.25rem"><a href="' . $h($u) . '" style="color:#1d4ed8">' . $h($u) . '</a></div>';
                }
            }
            if ($imgs !== '' || $links !== '') {
                $content .= '<div class="doc-section"><div class="doc-section-title">Pièces jointes & images</div>';
                if ($imgs !== '') $content .= '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.7rem;margin-bottom:.6rem">' . $imgs . '</div>';
                if ($links !== '') $content .= $links;
                $content .= '</div>';
            }
        }

        // ═══ Génération via le moteur PPA ═══
        $ref   = 'RPT-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
        $title = 'Rapport LSPD #' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
        $meta  = [
            'layout'    => 'clean',
            'brand'     => 'LSPD',
            'brand_sub' => 'Los Santos Police Department',
            'doc_label' => 'Rapport de Police',
            'reference' => $ref,
            'date'      => !empty($report['created_at']) ? date('d/m/Y', strtotime((string) $report['created_at'])) : date('d/m/Y'),
            'badge'     => (string) ($report['type'] ?? ''),
            'foot_note' => 'LSPD — Rapport officiel généré par le MDT · ' . $ref,
            'palette'   => ['c1' => '#1d4ed8', 'c2' => '#1e3a8a'],
        ];

        try {
            return mdt_ppa_render_document($db, 'LSPD', $title, $content, 'lspd_report', $meta);
        } catch (Throwable $e) {
            error_log('lspd_build_report_document: ' . $e->getMessage());
            return null;
        }
    }
}
