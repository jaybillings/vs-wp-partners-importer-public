<?php
// Check for Importer
if (!class_exists('VSPI_Importer')) {
    error_log('[VSPI Plugin] Error: Importer not defined');
    echo "<h2>Error: Importer not present. Please reinstall plugin.</h2>";
    exit();
}
?>

<div class="vspi-admin-wrapper">
    <h1>Partners Importer</h1>
    <p>Fetches partner data from SimpleView over the past 48 hours or since the last import, whichever is longer.</p>

    <div class="panel loading-message"><span><i class="fa fa-spinner spin"></i> Running backend processes...</span></div>
    <div class="panel error-message"><span>Error: Another operation is already in progress. Press 'cancel' to stop this operation.</span></div>

    <div class="panel action-panel">
        <h2>
            Import Status
            <button type="button" id="vspi_refresh" title="Refresh status"><i class="fa fa-refresh"></i></button>
        </h2>
        <ul class="messageWrapper">
            <li><i class="fa fa-spinner spin status-spinner"></i> Importer is <span id="vspi_importer-status">...</span>.</li>
            <li class="js_processing-status count-message">
                <div class="inner-message">Processing <span class="js_current-count js_counter">?</span></div>
                <div class="inner-message">of<span class="js_total-count js_counter">?</span>.</div>
            </li>
            <li class="count-message">
                <div class="inner-message">Imported: <span class="js_imported js_counter add">?</span></div>
                <div class="inner-message">Deleted: <span class="js_deleted js_counter remove">?</span></div>
            </li>
            <li class="js_last-run-status"></li>
            <li class="js_final-count-msg">Currently <span class="js_final-count">?</span> listings are live.</li>
        </ul>
    </div>
    <div class="panel action-panel">
        <h2>Importer Actions</h2>
        <ul>
            <li class="js_action-resume"><button id="vspi_resume" class="js_action">Resume</button> the canceled process.</li>
            <li><button id="vspi_update" class="js_action">Import</button> listings from <span class="vspi_timespan">the last 48 hours</span>.</li>
            <li class="js_action-cancel"><button id="vspi_cancel">Stop</button> current operation.</li>
        </ul>
    </div>
    <?php if (array_key_exists('advanced', $_GET)) { ?>
    <div class="panel action-panel">
        <h2>Advanced Actions</h2>
        <ul>
            <li>
                <button id="vspi_update-single" class="js_action">Import Single</button> listing with SimpleView ID #
                <input id="vspi_import-id" title="Enter SimpleView ID number" type="text" />
            </li>
        </ul>
        <ul>
            <li><button id="vspi_update-all" class="js_action">Import All</button> <span>current listing data.</span></li>
            <li><button id="vspi_update-images" class="js_action">Import Images</button> <span>for all listings.</span></button></li>
        </ul>
        <ul>
            <li><button id="vspi_prune" class="js_action">Prune</button> <span>old data from the database.</span></li>
            <li><button id="vspi_purge" class="js_action">Purge All</button> <span>listings without re-importing.</span></li>
        </ul>
        <ul>
            <li><button id="vspi_reset" class="js_action">Reset All</button> <span>listings by deleting, then re-importing everything.</span></li>
        </ul>
    </div>
    <div class="panel action-panel">
        <h2>Cache Actions</h2>
        <ul>
            <li><button id="vspi_create-cache" class="js_action">Recreate Cache</button> <span>for subsequent importing.</span></li>
            <li><button id="vspi_purge-cache" class="js_action">Purge Cache</button> <span>for subsequent re-importing.</span></li>
        </ul>
    </div>
    <?php } ?>
    <div class="panel action-panel <?php if (!array_key_exists('advanced', $_GET)) echo "hidden"; ?>">
        <h2>AJAX Parameters</h2>
        <ul>
            <li><label for="vspi_import-start-date">Start date:</label> <input type="text" id="vspi_import-start-date" /></li>
        </ul>
    </div>
</div>
<script type="text/javascript">
    VSPI_ImporterClient.run(<?= VSPI_Importer::get_chunk_size(); ?>);
</script>
