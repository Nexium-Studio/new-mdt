<?php
/**
 * Tarification fourrière : < 24 h au prorata minute, ≥ 24 h au forfait journalier (jours entamés).
 * Durée basée sur le timestamp Unix (entrée → sortie / maintenant).
 */
function fourriere_seconds_elapsed(int $entreeTs, int $sortieTs): int
{
    return max(0, $sortieTs - $entreeTs);
}

/**
 * @param float $prixMinute Prix à la minute (> 0)
 * @param float $prixJour   Forfait par jour (> 0)
 */
function fourriere_compute_amount(float $prixMinute, float $prixJour, int $entreeTs, int $sortieTs): float
{
    $elapsed = fourriere_seconds_elapsed($entreeTs, $sortieTs);
    if ($elapsed <= 0) {
        return round(max(0.01, $prixMinute), 2);
    }

    $day = 86400;
    if ($elapsed < $day) {
        $minutes = max(1, (int) floor($elapsed / 60));

        return round($minutes * $prixMinute, 2);
    }

    $days = (int) ceil($elapsed / $day);

    return round($days * $prixJour, 2);
}
