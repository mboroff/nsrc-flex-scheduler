<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Radio Activation';

$stationsByLocation = [];
foreach (get_stations() as $station) {
    $stationsByLocation[$station['location']] = $station;
}

// Alphabetical order, per the request: MunAV640, MunEndfed, Northbrook,
// Northfield, Skokie - each opens its own Node-RED page (same slug as
// the radio name). "All" is separate and always last - it opens the
// combined dashboard rather than one specific radio, so it isn't
// gated behind a reservation check.
$displayOrder = ['MunAV640', 'MunEndfed', 'Northbrook', 'Northfield', 'Skokie'];

require_once __DIR__ . '/includes/header.php';
?>
<main style="max-width:760px;">
    <h2>Radio Activation</h2>
    <p style="color:#55677d;font-size:0.9rem;margin-top:-8px;">
        Click a radio to open its control dashboard in a new tab. If the current hour is
        already reserved by someone else, it won't open - the reservation stays protected.
    </p>

    <div class="radio-rows">
        <?php foreach ($displayOrder as $location): ?>
            <?php $station = $stationsByLocation[$location] ?? null; ?>
            <div class="radio-row">
                <div class="radio-row-label">
                    <span class="station-location"><?php echo h($location); ?></span>
                    <span class="station-model"><?php echo $station ? h($station['model']) : ''; ?></span>
                </div>

                <div class="radio-row-button">
                    <button type="button" class="btn btn-small activate-btn" data-radio="<?php echo h($location); ?>">
                        Activate <?php echo h($location); ?>
                    </button>
                </div>

                <div class="radio-row-status" data-radio="<?php echo h($location); ?>">Checking...</div>
            </div>
        <?php endforeach; ?>

        <div class="radio-row">
            <div class="radio-row-label">
                <span class="station-location">All</span>
            </div>
            <div class="radio-row-button">
                <a class="btn btn-small" href="<?php echo h(node_red_url('radio-activation')); ?>" target="_blank" rel="noopener">
                    Open All Radios
                </a>
            </div>
            <div class="radio-row-status"></div>
        </div>
    </div>

    <div class="actions-row">
        <a class="btn btn-secondary" href="schedule.php">Back to Calendar</a>
        <a class="btn btn-secondary" href="logout.php">Log Out</a>
    </div>
</main>

<script>
(function () {
    var RADIO_URLS = <?php
        $urls = [];
        foreach ($displayOrder as $location) {
            $urls[$location] = node_red_url($location);
        }
        echo json_encode($urls);
    ?>;

    function checkAndUpdate(radio, statusEl, onResult) {
        return fetch('check_reservation.php?radio=' + encodeURIComponent(radio), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.reserved) {
                    statusEl.textContent = 'Radio is currently reserved by ' + data.reserved_by;
                } else {
                    statusEl.textContent = 'Available';
                }
                if (onResult) { onResult(data); }
            })
            .catch(function () {
                statusEl.textContent = 'Could not check status';
            });
    }

    // Show each radio's current status right away, and keep it fresh
    // while the page is open (someone else could reserve or release a
    // slot at any time).
    document.querySelectorAll('.radio-row-status[data-radio]').forEach(function (statusEl) {
        var radio = statusEl.getAttribute('data-radio');
        checkAndUpdate(radio, statusEl);
        setInterval(function () { checkAndUpdate(radio, statusEl); }, 15000);
    });

    document.querySelectorAll('.activate-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var radio = btn.getAttribute('data-radio');
            var statusEl = document.querySelector('.radio-row-status[data-radio="' + radio + '"]');
            var url = RADIO_URLS[radio];

            // Open the tab immediately, synchronously, inside this click
            // handler - browsers only allow window.open() without being
            // treated as a popup if it happens right inside a user
            // gesture like this. We fill in where it goes once the
            // reservation check (an async fetch) comes back - checked
            // again here even though the status field already shows it,
            // since time may have passed since the last periodic check.
            var newTab = window.open('about:blank', '_blank');

            checkAndUpdate(radio, statusEl, function (data) {
                if (data.reserved) {
                    if (newTab) { newTab.close(); }
                } else if (newTab) {
                    newTab.location.href = url;
                } else {
                    // Popup was blocked despite our best effort -
                    // fall back to navigating this same tab.
                    window.location.href = url;
                }
            });
        });
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
