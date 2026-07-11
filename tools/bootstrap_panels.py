# -*- coding: utf-8 -*-
"""Génère pages/panels/{slug}/ — exécuter : py -3 tools/bootstrap_panels.py"""
import os
import ast

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Même liste que config/panel_registry.php (extraite manuellement pour éviter parser PHP)
PANELS = [
    {"slug": "marshals", "key": "MARSHALS", "name": "Marshals", "faction": "MARSHALS", "color": "#475569", "subtitle": "US Marshals Service", "icon": "fas fa-shield-halved", "access": "faction"},
    {"slug": "superior-court-sa", "key": "SUPERIOR_COURT_SA", "name": "Superior court of S.A", "faction": "SUPERIOR_COURT_SA", "color": "#b45309", "subtitle": "Cour supérieure de San Andreas", "icon": "fas fa-gavel", "access": "faction"},
    {"slug": "lsfd", "key": "LSFD", "name": "LSFD", "faction": "LSFD", "color": "#dc2626", "subtitle": "Los Santos Fire Department", "icon": "fas fa-fire-flame-curved", "access": "faction"},
    {"slug": "irs-treasury", "key": "IRS_TREASURY", "name": "Dept of Treasury (IRS)", "faction": "IRS_TREASURY", "color": "#0f766e", "subtitle": "Department of the Treasury", "icon": "fas fa-landmark", "access": "faction"},
    {"slug": "yellow-jack", "key": "YELLOW_JACK", "name": "Yellow Jack", "faction": "YELLOW_JACK", "color": "#ca8a04", "subtitle": "Bar & Saloon", "icon": "fas fa-whiskey-glass", "access": "faction"},
    {"slug": "tropical", "key": "TROPICAL", "name": "Tropical", "faction": "TROPICAL", "color": "#14b8a6", "subtitle": "Bar tropical", "icon": "fas fa-umbrella-beach", "access": "faction"},
    {"slug": "unicorn", "key": "UNICORN", "name": "Unicorn", "faction": "UNICORN", "color": "#ec4899", "subtitle": "Nightclub", "icon": "fas fa-horse", "access": "faction"},
    {"slug": "black-wood-saloon", "key": "BLACK_WOOD_SALOON", "name": "Black Wood Saloon", "faction": "BLACK_WOOD_SALOON", "color": "#78350f", "subtitle": "Saloon", "icon": "fas fa-mug-hot", "access": "faction"},
    {"slug": "tequi-la-la", "key": "TEQUI_LA_LA", "name": "Tequi-la-la", "faction": "TEQUI_LA_LA", "color": "#f97316", "subtitle": "Nightclub", "icon": "fas fa-martini-glass-citrus", "access": "faction"},
    {"slug": "tuners-shop", "key": "TUNERS_SHOP", "name": "Tuner's Shop", "faction": "TUNERS_SHOP", "color": "#eab308", "subtitle": "Garage & tuning", "icon": "fas fa-screwdriver-wrench", "access": "faction"},
    {"slug": "premium-deluxe-motors", "key": "PREMIUM_DELUXE_MOTORS", "name": "Premium Deluxe Motorsport", "faction": "PREMIUM_DELUXE_MOTORS", "color": "#6366f1", "subtitle": "Concessionnaire", "icon": "fas fa-car-side", "access": "faction"},
    {"slug": "hayes-auto", "key": "HAYES_AUTO", "name": "Hayes", "faction": "HAYES_AUTO", "color": "#78716c", "subtitle": "Garage", "icon": "fas fa-wrench", "access": "faction"},
    {"slug": "harmony-repair", "key": "HARMONY_REPAIR", "name": "Harmony Repair", "faction": "HARMONY_REPAIR", "color": "#64748b", "subtitle": "Atelier mécanique", "icon": "fas fa-gears", "access": "faction"},
    {"slug": "luxury-auto", "key": "LUXURY_AUTO", "name": "Luxury Auto", "faction": "LUXURY_AUTO", "color": "#a855f7", "subtitle": "Concessionnaire premium", "icon": "fas fa-car-rear", "access": "faction"},
    {"slug": "ltd-little-seoul", "key": "LTD_LITTLE_SEOUL", "name": "LTD Little Seoul", "faction": "LTD_LITTLE_SEOUL", "color": "#38bdf8", "subtitle": "Supérette", "icon": "fas fa-basket-shopping", "access": "faction"},
    {"slug": "ltd-grrapeseed", "key": "LTD_GRRAPESEED", "name": "LTD Grapeseed", "faction": "LTD_GRRAPESEED", "color": "#0ea5e9", "subtitle": "Supérette", "icon": "fas fa-basket-shopping", "access": "faction"},
    {"slug": "ltd-strawberry", "key": "LTD_STRAWBERRY", "name": "LTD Strawberry", "faction": "LTD_STRAWBERRY", "color": "#0ea5e9", "subtitle": "Supérette", "icon": "fas fa-basket-shopping", "access": "faction"},
    {"slug": "ltd-blaine-county", "key": "LTD_BLAINE_COUNTY", "name": "LTD Blaine County", "faction": "LTD_BLAINE_COUNTY", "color": "#0ea5e9", "subtitle": "Supérette", "icon": "fas fa-basket-shopping", "access": "faction"},
    {"slug": "weazel-news", "key": "WEAZEL_NEWS", "name": "Weazel News", "faction": "WEAZEL_NEWS", "color": "#38bdf8", "subtitle": "Médias", "icon": "fas fa-newspaper", "access": "faction"},
    {"slug": "rosa-tattoo", "key": "ROSA_TATTOO", "name": "Rosa Tattoo", "faction": "ROSA_TATTOO", "color": "#be185d", "subtitle": "Salon de tatouage", "icon": "fas fa-pen-nib", "access": "faction"},
    {"slug": "vangelico", "key": "VANGELICO", "name": "Vangelico", "faction": "VANGELICO", "color": "#10b981", "subtitle": "Bijouterie", "icon": "fas fa-gem", "access": "faction"},
    {"slug": "boat-house", "key": "BOAT_HOUSE", "name": "Boat House", "faction": "BOAT_HOUSE", "color": "#0284c7", "subtitle": "Restaurant", "icon": "fas fa-ship", "access": "faction"},
    {"slug": "burger-shot", "key": "BURGER_SHOT", "name": "Burger Shot", "faction": "BURGER_SHOT", "color": "#f59e0b", "subtitle": "Fast-food", "icon": "fas fa-burger", "access": "faction"},
    {"slug": "hornys", "key": "HORNYS", "name": "Horny's", "faction": "HORNYS", "color": "#f43f5e", "subtitle": "Restaurant", "icon": "fas fa-drumstick-bite", "access": "faction"},
    {"slug": "diamond-casino", "key": "DIAMOND_CASINO", "name": "Diamond Casino", "faction": "DIAMOND_CASINO", "color": "#e11d48", "subtitle": "Casino & resort", "icon": "fas fa-dice", "access": "faction"},
    {"slug": "darknet", "key": "DARKNET", "name": "DarkNet", "faction": "DARKNET", "color": "#7c3aed", "subtitle": "Réseau — accès restreint", "icon": "fas fa-network-wired", "access": "darknet"},
]

DASHBOARD = r'''<?php
require_once dirname(__DIR__, 3) . '/config/config.php';
requireLogin();

$panelCfg = require __DIR__ . '/config.php';
require_once dirname(__DIR__, 3) . '/config/panel_access.php';
if (($panelCfg['access'] ?? 'faction') === 'darknet') {
    requireDarknetPanelAccess();
} else {
    requireFactionPanelAccess($panelCfg['faction']);
}

$pageTitle = 'Dashboard';
include __DIR__ . '/includes/header.php';
?>

<div class="mdt-panel-card">
    <h2>Bienvenue</h2>
    <p>
        Panel <strong><?php echo htmlspecialchars($panelCfg['name']); ?></strong> — structure de base.
        Les modules métier pourront être ajoutés progressivement.
    </p>
    <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="btn-mdt"><i class="fas fa-home"></i> Accueil MDT</a>
</div>

<?php include __DIR__ . '/includes/footer.php';
'''

HEADER_INC = r'''<?php
require_once dirname(__DIR__, 4) . '/pages/panel-base/render_header.php';
'''

FOOTER_INC = r'''<?php
require_once dirname(__DIR__, 4) . '/pages/panel-base/render_footer.php';
'''


def php_export(d):
    lines = ["<?php", "return ["]
    for k, v in d.items():
        if k == "nav":
            lines.append("    'nav' => [")
            for item in v:
                lines.append("        [")
                for ik, iv in item.items():
                    lines.append(f"            '{ik}' => {php_val(iv)},")
                lines.append("        ],")
            lines.append("    ],")
        else:
            lines.append(f"    '{k}' => {php_val(v)},")
    lines.append("];")
    return "\n".join(lines) + "\n"


def php_val(v):
    if isinstance(v, bool):
        return "true" if v else "false"
    if v is None:
        return "null"
    if isinstance(v, int):
        return str(v)
    return "'" + str(v).replace("\\", "\\\\").replace("'", "\\'") + "'"


def main():
    for p in PANELS:
        slug = p["slug"]
        base = os.path.join(ROOT, "pages", "panels", slug)
        os.makedirs(os.path.join(base, "includes"), exist_ok=True)

        cfg = {
            "slug": p["slug"],
            "key": p["key"],
            "name": p["name"],
            "subtitle": p["subtitle"],
            "faction": p["faction"],
            "color": p["color"],
            "icon": p["icon"],
            "access": p["access"],
            "nav": [
                {"file": "dashboard", "label": "Dashboard", "icon": "fas fa-chart-line", "section": None},
            ],
        }
        with open(os.path.join(base, "config.php"), "w", encoding="utf-8") as f:
            f.write(php_export(cfg))
        with open(os.path.join(base, "dashboard.php"), "w", encoding="utf-8", newline="\n") as f:
            f.write(DASHBOARD)
        with open(os.path.join(base, "includes", "header.php"), "w", encoding="utf-8", newline="\n") as f:
            f.write(HEADER_INC)
        with open(os.path.join(base, "includes", "footer.php"), "w", encoding="utf-8", newline="\n") as f:
            f.write(FOOTER_INC)
    print("OK:", len(PANELS), "panels")


if __name__ == "__main__":
    main()
