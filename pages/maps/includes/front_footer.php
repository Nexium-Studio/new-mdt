<?php
/**
 * pages/maps/includes/front_footer.php — ferme le shell front.
 * Optionnel : $mapsExtraJs (HTML <script> injecté avant </body>).
 */
if (!defined('MDT_MAPS_FRONT')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
    </main>
</div>
<?php if (!empty($mapsExtraJs)) { echo $mapsExtraJs; } ?>
</body>
</html>
