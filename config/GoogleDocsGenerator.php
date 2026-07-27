<?php
/**
 * GÉNÉRATEUR DE DOCUMENTS HTML POUR LE SAMS
 * Crée automatiquement des documents HTML partageables, au design professionnel
 * clair, inspiré des modèles officiels SAMS et adaptés au MDT.
 *
 * La couleur dominante provient de la configuration SAMS (sams_settings →
 * gradient_color_1, réglable dans Staff → Contenu → SAMS → Général). Le logo
 * provient de faction_logo_url($db,'SAMS').
 */

class GoogleDocsGenerator {

    /**
     * Génère un document HTML et retourne l'URL partageable.
     *
     * @param string      $title   Titre (balise <title> / contexte fichier).
     * @param string      $content Contenu HTML du corps (sections).
     * @param string      $type    Type de document (ordonnance, rapport, arret_travail…).
     * @param string|null $logoUrl URL du logo SAMS (ou null → icône de repli).
     * @param array       $meta    Métadonnées de mise en page (layout, reference, date, signataire…).
     * @return string|null URL du document généré ou null en cas d'erreur.
     */
    public function generateDocument($title, $content, $type = 'medical', $logoUrl = null, $meta = []) {
        try {
            $filename = $this->generateFilename($type);
            $filepath = $this->getDocumentPath($filename);
            $this->ensureDirectoryExists($filepath);

            $html = $this->getDocumentTemplate($title, $content, $type, $logoUrl, is_array($meta) ? $meta : []);

            if (file_put_contents($filepath, $html) === false) {
                throw new Exception("Impossible de créer le fichier");
            }
            return $this->getPublicUrl($filename);
        } catch (Exception $e) {
            error_log("Erreur génération document: " . $e->getMessage());
            return null;
        }
    }

    /** Génère un nom de fichier unique. */
    private function generateFilename($type) {
        return $type . '_' . date('Ymd_His') . '_' . uniqid() . '.html';
    }

    /** Retourne le chemin complet du fichier. */
    private function getDocumentPath($filename) {
        return __DIR__ . '/../public/documents/' . $filename;
    }

    /** Retourne l'URL publique du document. */
    private function getPublicUrl($filename) {
        return SITE_URL . '/public/documents/' . $filename;
    }

    /** Crée le dossier s'il n'existe pas. */
    private function ensureDirectoryExists($filepath) {
        $dir = dirname($filepath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new Exception("Impossible de créer le dossier documents");
            }
        }
    }

    /** Éclaircit (+) / assombrit (-) une couleur hex de $amt (0-255). */
    private function shiftHex($hex, $amt) {
        if (!preg_match('/^#?([0-9a-fA-F]{6})$/', trim((string)$hex), $m)) return $hex;
        $h = $m[1]; $out = '#';
        for ($i = 0; $i < 3; $i++) {
            $v = hexdec(substr($h, $i * 2, 2)) + $amt;
            $v = max(0, min(255, $v));
            $out .= str_pad(dechex($v), 2, '0', STR_PAD_LEFT);
        }
        return $out;
    }

    /** "#e11d48" => "225,29,72". */
    private function rgbHex($hex, $fallback = '225,29,72') {
        if (!preg_match('/^#?([0-9a-fA-F]{6})$/', trim((string)$hex), $m)) return $fallback;
        $h = $m[1];
        return hexdec(substr($h,0,2)) . ',' . hexdec(substr($h,2,2)) . ',' . hexdec(substr($h,4,2));
    }

    /**
     * Template HTML clair et professionnel des documents SAMS.
     * 3 variantes d'en-tête selon $meta['layout'] : 'clean' (ordonnance/défaut),
     * 'banner' (rapport, bannière colorée) et 'letter' (arrêt de travail).
     */
    private function getDocumentTemplate($title, $content, $type, $logoUrl = null, $meta = []) {
        // ── Palette (couleur SAMS configurée) ──
        $c1 = $meta['palette']['c1'] ?? '#e11d48';
        $c2 = $meta['palette']['c2'] ?? '#7c3aed';
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string)$c1)) $c1 = '#e11d48';
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string)$c2)) $c2 = '#7c3aed';
        $c1d   = $this->shiftHex($c1, -55);
        $c1dd  = $this->shiftHex($c1, -95);
        $rgb1  = $this->rgbHex($c1);

        // ── Métadonnées ──
        $labels = [
            'ordonnance'=>'Ordonnance','rapport'=>'Rapport Médical','arret_travail'=>'Arrêt de Travail',
            'certificat'=>'Certificat de Décès','naissance'=>'Certificat de Naissance','aptitude'=>"Certificat d'Aptitude",
            'aptitude_ppa'=>"Aptitude au Port d'Arme",'traumatisme'=>'Certificat de Traumatisme',
            'psy_suivi'=>'Suivi Psychologique','psy_eval'=>'Évaluation Psychologique','grossesse'=>'Suivi de Grossesse',
        ];
        $docLabel = $meta['doc_label'] ?? ($labels[$type] ?? 'Document Médical');
        $brand    = $meta['brand'] ?? 'SAMS';
        $brandSub = $meta['brand_sub'] ?? 'San Andreas Medical Service';
        $reference= (string)($meta['reference'] ?? '');
        $dateStr  = (string)($meta['date'] ?? date('d/m/Y'));
        $layout   = in_array($meta['layout'] ?? 'clean', ['clean','banner','letter'], true) ? $meta['layout'] : 'clean';
        $signName = (string)($meta['signatory_name'] ?? '');
        $signSub  = (string)($meta['signatory_sub'] ?? $brand);
        $footRightHtml = (string)($meta['footer_right_html'] ?? '');
        $footNote = (string)($meta['foot_note'] ?? ($brand.' — Document officiel généré par le MDT'.($reference!==''?' · '.$reference:'')));
        $safeTitle = htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8');

        // ── Logo (img si dispo, sinon icône ambulance) ──
        $emoji = '🚑';
        $logoUrl = is_string($logoUrl) ? trim($logoUrl) : '';
        if ($logoUrl !== '' && preg_match('#^https?://#i', $logoUrl)) {
            $fallback = htmlspecialchars('this.outerHTML=' . json_encode('<span style="font-size:1.5rem">'.$emoji.'</span>', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
            $logoHtml = '<img src="'.htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8').'" alt="'.htmlspecialchars($brand, ENT_QUOTES).'" referrerpolicy="no-referrer" onerror="'.$fallback.'">';
        } else {
            $logoHtml = '<span style="font-size:1.5rem">'.$emoji.'</span>';
        }

        $refLine   = $reference !== '' ? '<div class="doc-ref">'.htmlspecialchars($reference).'</div>' : '';
        $badgeHtml = !empty($meta['badge']) ? '<div><span class="doc-badge">'.htmlspecialchars((string)$meta['badge']).'</span></div>' : '';

        // ── En-tête (3 variantes) ──
        if ($layout === 'banner') {
            $subbarHtml = '';
            if (!empty($meta['subbar']) && is_array($meta['subbar'])) {
                $items = '';
                foreach ($meta['subbar'] as $it) {
                    $items .= '<span><b>'.htmlspecialchars((string)($it['label'] ?? '')).' :</b> '.htmlspecialchars((string)($it['value'] ?? '')).'</span>';
                }
                $subbarHtml = '<div class="doc-subbar">'.$items.'</div>';
            }
            $header = '<div class="doc-hd">'
                . '<div class="doc-brand"><div class="doc-logo">'.$logoHtml.'</div>'
                . '<div><div class="doc-brand-sub">'.htmlspecialchars($brand.' — '.$brandSub).'</div><div class="doc-title">'.htmlspecialchars($docLabel).'</div></div></div>'
                . '<div class="doc-hd-right"><div class="doc-refbox">'.$refLine.'<div class="doc-date">'.htmlspecialchars($dateStr).'</div></div>'.$badgeHtml.'</div>'
                . '</div>' . $subbarHtml;
        } elseif ($layout === 'letter') {
            $header = '<div class="doc-hd">'
                . '<div class="doc-brand"><div class="doc-logo">'.$logoHtml.'</div>'
                . '<div><div class="doc-title -letter">'.htmlspecialchars($docLabel).'</div>'
                . ($reference !== '' ? '<div class="doc-ref">Référence : '.htmlspecialchars($reference).'</div>' : '')
                . '</div></div>'
                . '</div>';
        } else { // clean
            $header = '<div class="doc-hd">'
                . '<div class="doc-brand"><div class="doc-logo">'.$logoHtml.'</div>'
                . '<div><div class="doc-brand-name">'.htmlspecialchars($brand).'</div><div class="doc-brand-sub">'.htmlspecialchars($brandSub).'</div></div></div>'
                . '<div class="doc-hd-right"><div class="doc-title">'.htmlspecialchars($docLabel).'</div>'.$refLine.'<div class="doc-date">Date : '.htmlspecialchars($dateStr).'</div>'.$badgeHtml.'</div>'
                . '</div>';
        }

        // ── Pied de page ──
        if ($signName === '' && $footRightHtml === '') {
            $footer = '<div class="doc-foot-note">'.htmlspecialchars($footNote).'</div>';
        } else {
            $footer = '<div class="doc-ft">'
                . '<div><div class="doc-sign-label">Signature du praticien</div><div class="doc-sign-line">'.htmlspecialchars($signName).'</div><div class="doc-sign-sub">'.htmlspecialchars($signSub).'</div></div>'
                . '<div style="text-align:right;align-self:flex-end">'.$footRightHtml.'</div>'
                . '</div>'
                . '<div class="doc-foot-note">'.htmlspecialchars($footNote).'</div>';
        }

        $css = <<<CSS
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter','Segoe UI',Arial,sans-serif;background:#eef1f5;color:#1f2937;padding:2rem 1rem;line-height:1.55}
.doc{max-width:820px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 10px 40px rgba(15,23,42,.14)}
.doc-hd{display:flex;justify-content:space-between;align-items:flex-start;gap:1.4rem;padding:1.9rem 2.2rem 1.4rem;border-bottom:2px solid {$c1}}
.doc-brand{display:flex;align-items:center;gap:1rem}
.doc-logo{width:56px;height:56px;border-radius:50%;border:2px solid {$c1};display:flex;align-items:center;justify-content:center;overflow:hidden;background:#fff;color:{$c1};flex-shrink:0}
.doc-logo img{width:100%;height:100%;object-fit:contain;padding:6px}
.doc-brand-name{font-weight:800;font-size:1.05rem;color:#111827}
.doc-brand-sub{font-size:.7rem;color:#6b7280;text-transform:uppercase;letter-spacing:.09em}
.doc-hd-right{text-align:right}
.doc-title{font-size:1.6rem;font-weight:800;color:{$c1};letter-spacing:.02em;text-transform:uppercase;line-height:1.12}
.doc-title.-letter{text-transform:none;color:#111827;font-size:1.4rem}
.doc-ref{font-weight:700;font-size:.82rem;color:#374151;margin-top:.3rem}
.doc-date{font-size:.74rem;color:#6b7280;margin-top:.15rem}
.doc-badge{display:inline-block;margin-top:.5rem;background:{$c1};color:#fff;font-size:.66rem;font-weight:700;padding:.24rem .7rem;border-radius:20px;text-transform:uppercase;letter-spacing:.04em}
.doc-banner .doc-hd{background:linear-gradient(135deg,{$c1dd},{$c1});border-bottom:none;color:#fff;align-items:center}
.doc-banner .doc-title,.doc-banner .doc-brand-name{color:#fff}
.doc-banner .doc-brand-sub{color:rgba(255,255,255,.85)}
.doc-banner .doc-logo{border-color:rgba(255,255,255,.65);background:rgba(255,255,255,.14);color:#fff}
.doc-banner .doc-ref{color:#fff}.doc-banner .doc-date{color:rgba(255,255,255,.85)}
.doc-refbox{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.25);border-radius:9px;padding:.5rem .8rem}
.doc-subbar{background:{$c1d};color:#fff;padding:.55rem 2.2rem;display:flex;gap:1.6rem;flex-wrap:wrap;font-size:.72rem}
.doc-subbar b{font-weight:700}
.doc-bd{padding:1.6rem 2.2rem 2rem}
.doc-section{margin-bottom:1.3rem}
.doc-section-title{font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:{$c1};background:rgba({$rgb1},.07);border-left:3px solid {$c1};padding:.42rem .7rem;border-radius:0 6px 6px 0;margin-bottom:.75rem}
.doc-grid2{display:grid;grid-template-columns:1fr 1fr;gap:1.4rem}
.doc-field{margin-bottom:.45rem}
.doc-field-label{font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af}
.doc-field-value{font-size:.92rem;color:#111827;font-weight:600}
.doc-bd p{margin-bottom:.6rem;color:#374151;font-size:.9rem}
.doc-bd h1{font-size:1.05rem;color:{$c1};margin-bottom:.55rem}
.doc-bd h2{font-size:.95rem;color:{$c1};margin:1rem 0 .5rem}
.doc-bd strong{color:#111827}
.doc-bd ol,.doc-bd ul{margin-left:1.2rem;margin-bottom:.6rem}
.doc-bd li{margin-bottom:.4rem;font-size:.9rem;color:#374151}
.doc-med-table{width:100%;border-collapse:collapse}
.doc-med-table th{text-align:left;font-size:.63rem;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;border-bottom:2px solid #e5e7eb;padding:.5rem .4rem}
.doc-med-table td{padding:.7rem .4rem;border-bottom:1px solid #f3f4f6;vertical-align:top;font-size:.86rem}
.doc-med-num{color:{$c1};font-weight:700}
.doc-med-name{font-weight:700;color:#111827}
.doc-med-sub{font-size:.72rem;color:#6b7280;margin-top:.15rem}
.doc-med-instr{text-align:right;color:{$c1};font-weight:600;white-space:nowrap}
.doc-vitals{display:grid;grid-template-columns:repeat(3,1fr);gap:.9rem}
.doc-vital{border-radius:10px;padding:1rem .8rem;text-align:center;border:1px solid}
.doc-vital .vl{font-size:.6rem;text-transform:uppercase;letter-spacing:.07em;font-weight:700;opacity:.85}
.doc-vital .vv{font-size:1.45rem;font-weight:800;margin-top:.3rem;line-height:1}
.doc-vital .vu{font-size:.6rem;opacity:.7;margin-top:.2rem}
.v-blue{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8}
.v-red{background:#fef2f2;border-color:#fecaca;color:#dc2626}
.v-amber{background:#fffbeb;border-color:#fde68a;color:#b45309}
.doc-letter{border:1px solid #e5e7eb;border-radius:10px;padding:1.3rem 1.5rem;font-size:.9rem;line-height:1.75;color:#374151;background:#fcfcfd}
.doc-letter p{margin-bottom:.7rem}
.hl{color:{$c1};font-weight:700}
.doc-ft{padding:1.4rem 2.2rem 1.6rem;border-top:1px solid #e5e7eb;display:flex;justify-content:space-between;gap:1.5rem;background:#f9fafb;flex-wrap:wrap}
.doc-sign-label{font-size:.62rem;text-transform:uppercase;letter-spacing:.07em;color:#9ca3af;margin-bottom:1.6rem}
.doc-sign-line{border-top:1px solid #9ca3af;padding-top:.3rem;font-weight:700;color:#111827;font-size:.82rem;min-width:170px}
.doc-sign-sub{font-size:.72rem;color:#6b7280}
.doc-foot-note{text-align:center;font-size:.66rem;color:#9ca3af;padding:.75rem 1rem;border-top:1px solid #eef1f5;background:#f9fafb}
.doc-print-btn{position:fixed;bottom:1.4rem;right:1.4rem;background:{$c1};color:#fff;border:none;border-radius:10px;padding:.75rem 1.15rem;font-weight:700;font-size:.85rem;cursor:pointer;box-shadow:0 6px 18px rgba({$rgb1},.4);font-family:inherit}
.doc-print-btn:hover{filter:brightness(1.06)}
@media print{body{background:#fff;padding:0}.doc{box-shadow:none;border-radius:0;max-width:100%}.doc-print-btn{display:none}}
@media(max-width:640px){.doc-grid2,.doc-vitals{grid-template-columns:1fr}.doc-hd{flex-direction:column}.doc-hd-right{text-align:left}}
CSS;

        return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$safeTitle}</title>
<style>
{$css}
</style>
</head>
<body>
<div class="doc doc-{$layout}">
{$header}
<div class="doc-bd">
{$content}
</div>
{$footer}
</div>
<button class="doc-print-btn" onclick="window.print()">🖨 Imprimer</button>
</body>
</html>
HTML;
    }
}

/**
 * Récupère la ligne « profile » d'un patient pour les documents SAMS.
 *
 * @return array<string,mixed> Ligne profiles (firstname/lastname) ou tableau vide.
 */
function sams_patient_row(PDO $db, $patientId): array {
    try {
        $stmt = $db->prepare("SELECT firstname,lastname FROM profiles WHERE id=?");
        $stmt->execute([(int) $patientId]);
        return $stmt->fetch() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Nom RP à afficher pour le médecin certifiant (user_id de session).
 */
function sams_doctor_name(PDO $db, $userId): string {
    try {
        $stmt = $db->prepare("SELECT pr.firstname,pr.lastname FROM users u LEFT JOIN profiles pr ON pr.id=u.profile_id WHERE u.id=?");
        $stmt->execute([(int) $userId]);
        $doctor = $stmt->fetch() ?: [];
        return mdt_rp_display_name($doctor['firstname'] ?? null, $doctor['lastname'] ?? null, '');
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Couleurs configurées du panel SAMS (Staff → Contenu → SAMS → Général).
 * Repli sur le rouge SAMS par défaut si la table est absente/non configurée.
 *
 * @return array{c1:string,c2:string}
 */
function sams_doc_palette(PDO $db): array {
    $c1 = '#e11d48'; $c2 = '#7c3aed';
    try {
        $rows = $db->query("SELECT setting_key,setting_value FROM sams_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!empty($rows['gradient_color_1'])) $c1 = (string) $rows['gradient_color_1'];
        if (!empty($rows['gradient_color_2'])) $c2 = (string) $rows['gradient_color_2'];
    } catch (Throwable $e) { /* table absente : défauts */ }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $c1)) $c1 = '#e11d48';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $c2)) $c2 = '#7c3aed';
    return ['c1' => $c1, 'c2' => $c2];
}

/**
 * Génère le document HTML d'un enregistrement SAMS et mémorise son URL publique.
 *
 * Centralise « générer → stocker l'URL » commun à toutes les pages de documents
 * du SAMS. Injecte automatiquement le logo et la couleur configurés. Toute erreur
 * est absorbée : l'échec de génération ne doit jamais empêcher la création de
 * l'enregistrement lui-même.
 *
 * @param string $table Table contenant la colonne google_docs_url (littéral, jamais une entrée utilisateur).
 * @param array  $meta  Métadonnées de mise en page (layout, reference, date, signataire…).
 */
function sams_generate_document(PDO $db, string $table, int $id, string $title, string $content, string $type = 'medical', array $meta = []): ?string {
    if (!class_exists('GoogleDocsGenerator')) {
        return null;
    }
    try {
        // Logo officiel SAMS (Staff → Contenu → MDT).
        $logoUrl = null;
        if (!function_exists('faction_logo_url')) {
            @require_once __DIR__ . '/../includes/faction_logo.php';
        }
        if (function_exists('faction_logo_url')) {
            $logoUrl = faction_logo_url($db, 'SAMS');
        }
        // Couleur configurée du SAMS.
        $meta['palette'] = sams_doc_palette($db);

        $generator = new GoogleDocsGenerator();
        $url = $generator->generateDocument($title, $content, $type, $logoUrl, $meta);
        if ($url) {
            // $table est toujours un littéral fourni par la page appelante, jamais une entrée utilisateur.
            $db->prepare("UPDATE `{$table}` SET google_docs_url=? WHERE id=?")->execute([$url, $id]);
        }
        return $url ?: null;
    } catch (Throwable $e) {
        error_log('sams_generate_document: ' . $e->getMessage());
        return null;
    }
}

/**
 * Petit fragment HTML de lien « Voir le document → », ajouté au message de succès.
 */
function sams_doc_link(?string $url): string {
    if (!$url) {
        return '';
    }
    return ' <a href="' . htmlspecialchars($url) . '" target="_blank" style="color:var(--red-l);text-decoration:underline">Voir le document →</a>';
}
