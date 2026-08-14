<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? h($pageTitle) . ' - ' : ''; ?>North Shore Radio Club Flex-Cadre</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?php echo @filemtime(__DIR__ . '/../css/style.css') ?: time(); ?>">
</head>
<body>
<header class="site-header">
    <div class="callsign-tag">DE NSRC</div>
    <h1>North Shore Radio Club Flex-Cadre</h1>
    <div class="tagline">Shared scheduling for Skokie &middot; Northfield &middot; Northbrook &middot; MunAV640 &middot; MunEndfed</div>
    <div class="radio-gallery">
        <?php foreach (get_stations() as $station): ?>
            <figure>
                <img src="images/<?php echo h(station_photo_filename($station['model'])); ?>"
                     alt="<?php echo h($station['model']); ?> radio at <?php echo h($station['location']); ?>">
                <figcaption>
                    <span class="station-location"><?php echo h($station['location']); ?></span>
                    <span class="station-model"><?php echo h($station['model']); ?></span>
                    <?php foreach ($station['antennas'] as $antenna): ?>
                        <span class="station-ant"><?php echo h($antenna); ?></span>
                    <?php endforeach; ?>
                </figcaption>
            </figure>
        <?php endforeach; ?>
    </div>
    <div class="band-sweep" aria-hidden="true"></div>
</header>
